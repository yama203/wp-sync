<?php
/**
 * 同期の進め方。
 *
 * 画面（または WP-CLI）から step() を何度も呼び、1 回ごとに少しずつ進める。
 * 途中の状態は uploads/wp-sync/ のファイルに置く（pull で DB を入れ替えても消えないように）。
 *
 * 順番はテーマ・プラグイン → メディア → DB。pull では最後に DB を入れ替えるとこのサイトのユーザーも入れ替わり、
 * ログインし直すことになるので、DB の入れ替えを必ず最後にする。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Job {

	/** 1 回の step() で作業する時間の目安（秒）。 */
	const STEP_SECONDS = 8;

	const STAGE_LABELS = array(
		'code_scan'    => 'テーマ・プラグインのファイルを比べています',
		'code_copy'    => 'テーマ・プラグインを送っています',
		'code_commit'  => 'テーマ・プラグインを入れ替えています',
		'media_scan'   => 'メディアの一覧を比べています',
		'media_copy'   => 'メディアを送っています',
		'media_delete' => '送り元にないメディアを消しています',
		'db_begin'     => 'DB の取り込みを準備しています',
		'db_tables'    => 'DB を送っています',
		'db_finalize'  => 'DB を入れ替えています',
		'done'         => '完了しました',
	);

	private $data;

	private function __construct( array $data ) {
		$this->data = $data;
	}

	private static function dir() {
		$dir = WPS_Files::base() . '/' . WPS_Files::WORK_DIR;
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
			file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore
		}
		return $dir;
	}

	private static function file( $id ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', (string) $id ) ) {
			throw new Exception( '同期の ID が正しくありません。' );
		}
		return self::dir() . '/job-' . $id . '.php';
	}

	public static function load( $id ) {
		$file = self::file( $id );
		if ( ! is_file( $file ) ) {
			throw new Exception( '同期の状態が見つかりません。最初からやり直してください。' );
		}
		$raw  = (string) file_get_contents( $file ); // phpcs:ignore
		$data = @unserialize( substr( $raw, strpos( $raw, "\n" ) + 1 ), array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( ! is_array( $data ) ) {
			throw new Exception( '同期の状態を読めませんでした。最初からやり直してください。' );
		}
		return new self( $data );
	}

	private function save() {
		// ウェブから直接開かれても中身が出ないよう、先頭で exit する PHP として保存する。
		file_put_contents( self::file( $this->data['id'] ), "<?php exit; ?>\n" . serialize( $this->data ) ); // phpcs:ignore
	}

	private static function cleanup() {
		foreach ( (array) glob( self::dir() . '/job-*.php' ) as $file ) {
			if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
				unlink( $file ); // phpcs:ignore
			}
		}
	}

	/**
	 * 置換の組を、画面に見せる分（主な組）だけ作る。
	 */
	public static function preview_pairs( array $src, array $dst ) {
		$pairs = array( array( $src['home'], $dst['home'] ) );
		if ( $src['siteurl'] !== $src['home'] ) {
			$pairs[] = array( $src['siteurl'], $dst['siteurl'] );
		}
		foreach ( self::site_pairs( $src, $dst ) as $pair ) {
			$pairs[] = $pair;
		}
		$pairs[] = array( $src['content_url'], $dst['content_url'] );
		$pairs[] = array( $src['abspath'], $dst['abspath'] );
		return array_values(
			array_filter(
				$pairs,
				function ( $pair ) {
					return $pair[0] !== $pair[1];
				}
			)
		);
	}

	/**
	 * マルチサイトで、送り元の各サイトが送り先でどのドメイン・パス・URL になるか。
	 *
	 * ふつうは、ネットワークの中での場所（パスかサブドメイン）を送り先のネットワークに付け替えて作る
	 * （https://example.com/door/ → http://localhost:10898/door/、https://door.example.com → http://door.localhost:10898）。
	 * 送り先に同じ ID で同じ場所のサイトがあるか、どちらかが別のドメインを当てているサイトなら、送り先のそのサイトに合わせる。
	 *
	 * ネットワークの型（SUBDOMAIN_INSTALL）が両方で違っていてもよい。サブディレクトリ型のネットワークでも、
	 * サイトに sub.ネットワークのドメイン を当てていればサブドメインの場所として扱う（本番はサブディレクトリ型で
	 * kinoko.example.com を当て、ローカルはサブドメイン型で kinoko.localhost:10856、のような組み合わせ）。
	 *
	 * @return array<int,array{id:int,src_home:string,src_siteurl:string,domain:string,path:string,home:string,siteurl:string,same:bool}>
	 */
	public static function map_sites( array $src, array $dst ) {
		if ( empty( $src['network'] ) || empty( $dst['network'] ) ) {
			return array();
		}
		$sn     = $src['network'];
		$dn     = $dst['network'];
		$scheme = wp_parse_url( $dst['home'], PHP_URL_SCHEME ) ?: 'http';
		$by_id  = array();
		foreach ( $dn['sites'] as $site ) {
			$by_id[ (int) $site['id'] ] = $site;
		}

		$out = array();
		foreach ( $sn['sites'] as $site ) {
			$id    = (int) $site['id'];
			$place = self::site_place( $site, $sn );
			$to    = null;
			if ( isset( $by_id[ $id ] ) ) {
				$there = self::site_place( $by_id[ $id ], $dn );
				if ( null === $place || null === $there || $place === $there ) {
					$to = $by_id[ $id ];
				}
			}
			if ( $to ) {
				$domain  = $to['domain'];
				$path    = $to['path'];
				$home    = $to['home'];
				$siteurl = $to['siteurl'];
			} else {
				list( $domain, $path ) = self::place_address( $place, $site, $dn );
				$home    = $scheme . '://' . $domain . untrailingslashit( $path );
				$siteurl = 0 === strpos( $site['siteurl'], $site['home'] ) ? $home . substr( $site['siteurl'], strlen( $site['home'] ) ) : $home;
			}
			$out[] = array(
				'id'          => $id,
				'src_home'    => $site['home'],
				'src_siteurl' => $site['siteurl'],
				'domain'      => $domain,
				'path'        => $path,
				'home'        => $home,
				'siteurl'     => $siteurl,
				// 送り先の同じサイトに合わせたか（そのときは送り先の home・siteurl を残す）。
				'same'        => null !== $to,
			);
		}
		return $out;
	}

	/**
	 * ネットワークの中でのサイトの場所。メインと同じ場所は ''、パスは 'dir:door/'、サブドメインは 'sub:door'。
	 * ネットワークと関係のないドメインを当てているサイトは null。
	 */
	private static function site_place( array $site, array $network ) {
		$suffix = '.' . $network['domain'];
		if ( $site['domain'] === $network['domain'] ) {
			if ( 0 !== strpos( $site['path'], $network['path'] ) ) {
				return null;
			}
			$rel = (string) substr( $site['path'], strlen( $network['path'] ) );
			return '' === $rel ? '' : 'dir:' . $rel;
		}
		if ( substr( $site['domain'], -strlen( $suffix ) ) === $suffix && $site['path'] === $network['path'] ) {
			return 'sub:' . substr( $site['domain'], 0, -strlen( $suffix ) );
		}
		return null;
	}

	/**
	 * 場所を、送り先のネットワークのドメインとパスにする。
	 *
	 * @return array{0:string,1:string}
	 */
	private static function place_address( $place, array $site, array $network ) {
		if ( null === $place ) {
			return array( $site['domain'], $site['path'] );
		}
		if ( '' === $place ) {
			return array( $network['domain'], $network['path'] );
		}
		$name = substr( $place, 4 );
		if ( 0 === strpos( $place, 'dir:' ) ) {
			// パスの場所。サブドメイン型のネットワークへはサブドメインにする（door/ → door.ドメイン）。
			return $network['subdomain']
				? array( str_replace( '/', '-', trim( $name, '/' ) ) . '.' . $network['domain'], $network['path'] )
				: array( $network['domain'], $network['path'] . $name );
		}
		// サブドメインの場所。サブディレクトリ型のネットワークでも、サイトにサブドメインを当てた形のまま送る。
		return array( $name . '.' . $network['domain'], $network['path'] );
	}

	/**
	 * メイン以外のサイトの URL の組（メインのサイトの URL の置換だけでは合わないものを拾う）。
	 */
	private static function site_pairs( array $src, array $dst ) {
		$pairs = array();
		foreach ( self::map_sites( $src, $dst ) as $site ) {
			if ( $site['id'] === (int) $src['network']['main'] ) {
				continue;
			}
			$pairs[] = array( $site['src_home'], $site['home'] );
			if ( $site['src_siteurl'] !== $site['src_home'] ) {
				$pairs[] = array( $site['src_siteurl'], $site['siteurl'] );
			}
		}
		return $pairs;
	}

	/**
	 * @param array $opts direction: pull|push, db: bool, media: bool, media_delete: bool, pairs: 足す置換 [[from, to], ...],
	 *                    keep_active_plugins: 有効化しているプラグインの設定を送らない（送り先のものを残す）、
	 *                    themes / plugins: 送るテーマ・プラグインの名前（フォルダ名）の配列
	 */
	public static function start( array $opts ) {
		$direction = isset( $opts['direction'] ) && 'push' === $opts['direction'] ? 'push' : 'pull';
		$do_db     = ! empty( $opts['db'] );
		$do_media  = ! empty( $opts['media'] );
		$themes    = array_values( array_unique( array_filter( array_map( 'strval', isset( $opts['themes'] ) ? (array) $opts['themes'] : array() ) ) ) );
		$plugins   = array_values( array_unique( array_filter( array_map( 'strval', isset( $opts['plugins'] ) ? (array) $opts['plugins'] : array() ) ) ) );
		if ( ! $do_db && ! $do_media && ! $themes && ! $plugins ) {
			throw new Exception( '送るものを選んでください。' );
		}

		WPS_Settings::assert_main_site();
		$remote = WPS_Endpoint::remote();
		$rinfo  = $remote->call( 'info' );
		$linfo  = WPS_Server::info();

		if ( (int) $rinfo['api'] !== WPS_API_VERSION ) {
			throw new Exception( sprintf( '接続先の WP Sync のバージョン（%s）がこのサイト（%s）と合いません。両方を同じバージョンにしてください。', $rinfo['version'], WPS_VERSION ) );
		}
		if ( (bool) $rinfo['multisite'] !== (bool) $linfo['multisite'] ) {
			throw new Exception( 'シングルサイトとマルチサイトの間では同期できません（' . ( $linfo['multisite'] ? 'このサイトがマルチサイト、接続先がシングルサイト' : 'このサイトがシングルサイト、接続先がマルチサイト' ) . 'です）。' );
		}
		if ( $linfo['multisite'] ) {
			if ( $rinfo['network']['main'] !== $linfo['network']['main'] ) {
				throw new Exception( 'メインのサイトの ID が両方で違うので同期できません。' );
			}
		}
		if ( 'pull' === $direction && ! $rinfo['allow_pull'] ) {
			throw new Exception( '接続先で pull が許可されていません。接続先の WP Sync の「このサイトの設定」で許可してください。' );
		}
		if ( 'push' === $direction && ! $rinfo['allow_push'] ) {
			throw new Exception( '接続先で push が許可されていません。接続先の WP Sync の「このサイトの設定」で「このサイトへ push するのを許可する」をオンにしてください。' );
		}

		$src = 'pull' === $direction ? $rinfo : $linfo;
		$dst = 'pull' === $direction ? $linfo : $rinfo;

		$extra = array();
		foreach ( isset( $opts['pairs'] ) ? (array) $opts['pairs'] : array() as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== trim( (string) $pair[0] ) ) {
				$extra[] = array( (string) $pair[0], (string) $pair[1] );
			}
		}

		$tables = array();
		$rows   = 0;
		foreach ( $src['tables'] as $table ) {
			$tables[] = array(
				'src'  => $table['name'],
				'dst'  => $dst['prefix'] . substr( $table['name'], strlen( $src['prefix'] ) ),
				'rows' => $table['rows'],
			);
			$rows    += $table['rows'];
		}
		if ( $do_db && ! in_array( $src['prefix'] . 'options', wp_list_pluck( $src['tables'], 'name' ), true ) ) {
			throw new Exception( '送り元に options テーブルが見つかりません。' );
		}

		// 送り元にあるテーマ・プラグインだけを送る。
		$code = array();
		foreach ( array( 'themes' => $themes, 'plugins' => $plugins ) as $root => $slugs ) {
			$names = array();
			foreach ( $src['code'][ $root ] as $item ) {
				$names[ $item['slug'] ] = $item['name'];
			}
			foreach ( $slugs as $slug ) {
				WPS_Files::check_slug( $slug );
				if ( ! isset( $names[ $slug ] ) ) {
					throw new Exception( sprintf( '送り元に%s「%s」がありません。', 'themes' === $root ? 'テーマ' : 'プラグイン', $slug ) );
				}
				$code[] = array( $root, $slug, $names[ $slug ] );
			}
		}

		$stages = array();
		if ( $code ) {
			$stages[] = 'code_scan';
			$stages[] = 'code_copy';
			$stages[] = 'code_commit';
		}
		if ( $do_media ) {
			$stages[] = 'media_scan';
			$stages[] = 'media_copy';
			if ( ! empty( $opts['media_delete'] ) ) {
				$stages[] = 'media_delete';
			}
		}
		if ( $do_db ) {
			$stages[] = 'db_begin';
			$stages[] = 'db_tables';
			$stages[] = 'db_finalize';
		}
		$stages[] = 'done';

		self::cleanup();
		$job = new self(
			array(
				'id'          => wp_generate_password( 20, false, false ),
				'direction'   => $direction,
				'started'     => time(),
				'src_home'    => $src['home'],
				'keep_active' => ! empty( $opts['keep_active_plugins'] ),
				'dst_home'    => $dst['home'],
				'src_prefix'  => $src['prefix'],
				'pairs'       => WPS_Replacer::build_pairs( $src, $dst, $extra, self::site_pairs( $src, $dst ) ),
				'sites'       => array_map(
					function ( $site ) {
						return array( $site['id'], $site['domain'], $site['path'], $site['same'] );
					},
					self::map_sites( $src, $dst )
				),
				// 接続先の post_max_size に収まる大きさで送る。
				'chunk'       => (int) max( 262144, min( WPS_Files::CHUNK_BYTES, ( (int) $rinfo['post_max'] ?: WPS_Files::CHUNK_BYTES * 4 ) / 3 ) ),
				'stages'      => $stages,
				'stage'       => 0,
				'tables'      => $tables,
				'table'       => 0,
				'cursor'      => null,
				'rows_total'  => $rows,
				'rows_done'   => 0,
				'queue'       => array(),
				'qi'          => 0,
				'qoffset'     => 0,
				'bytes_total' => 0,
				'bytes_done'  => 0,
				'deletes'     => array(),
				'di'          => 0,
				'code'        => $code,
				'ci'          => 0,
				'changed'     => array(),
				'log'         => array(),
				'errors'      => array(),
			)
		);
		$job->log( sprintf( '%s を開始しました（%s → %s）。', strtoupper( $direction ), $src['home'], $dst['home'] ) );
		$job->save();
		return $job->status();
	}

	public static function step_by_id( $id ) {
		$job = self::load( $id );
		return $job->step();
	}

	public function step() {
		$deadline = microtime( true ) + self::STEP_SECONDS;
		$remote   = WPS_Endpoint::remote();
		$local    = WPS_Endpoint::local();
		$pull     = 'pull' === $this->data['direction'];
		$src      = $pull ? $remote : $local;
		$dst      = $pull ? $local : $remote;

		do {
			$stage = $this->stage();
			if ( 'done' === $stage ) {
				break;
			}
			try {
				$this->run( $stage, $src, $dst );
			} catch ( Throwable $e ) {
				$this->on_error( $stage );
				throw $e;
			}
			$this->save();
		} while ( microtime( true ) < $deadline && 'db_finalize' !== $this->stage() );

		if ( 'done' === $this->stage() ) {
			@unlink( self::file( $this->data['id'] ) ); // phpcs:ignore
		}
		return $this->status();
	}

	/**
	 * 止まったときに、続きから再開できる状態にして残す（止まった回の分は保存していないので、その前の状態から再開する）。
	 *
	 * DB のテーブルの途中で止まったときは、そのテーブルを最初から送り直す。要求が届いた後に応答を待てずに止まったときは、
	 * その分の行が入っているかどうか分からないため（最初のチャンクで一時テーブルを作り直すので、2 回入らない）。
	 */
	private function on_error( $stage ) {
		$saved = self::load( $this->data['id'] )->data;
		if ( 'db_tables' === $stage && null !== $saved['cursor'] ) {
			$saved['cursor']    = null;
			$saved['rows_done'] = isset( $saved['table_rows_start'] ) ? $saved['table_rows_start'] : $saved['rows_done'];
		}
		$this->data = $saved;
		$this->save();
	}

	private function stage() {
		return $this->data['stages'][ $this->data['stage'] ];
	}

	private function next_stage() {
		++$this->data['stage'];
	}

	private function log( $message ) {
		$this->data['log'][] = $message;
	}

	private function run( $stage, WPS_Endpoint $src, WPS_Endpoint $dst ) {
		$d = &$this->data;

		switch ( $stage ) {
			case 'media_scan':
				$from    = $src->call( 'files_list' );
				$to      = $dst->call( 'files_list' );
				$from    = $from['files'];
				$to      = $to['files'];
				$queue   = array();
				$total   = 0;
				$deletes = array();
				foreach ( $from as $rel => $meta ) {
					if ( ! isset( $to[ $rel ] ) || $to[ $rel ][0] !== $meta[0] || $to[ $rel ][1] !== $meta[1] ) {
						$queue[] = array( (string) $rel, $meta[0], $meta[1], 'uploads' );
						$total  += $meta[0];
					}
				}
				foreach ( $to as $rel => $meta ) {
					if ( ! isset( $from[ $rel ] ) ) {
						$deletes[] = (string) $rel;
					}
				}
				$d['queue']       = $queue;
				$d['qi']          = 0;
				$d['qoffset']     = 0;
				$d['bytes_total'] = $total;
				$d['bytes_done']  = 0;
				$d['deletes']     = $deletes;
				$this->log( sprintf( 'メディア: 送り元 %d 件、送り先 %d 件。送るもの %d 件（%s）。', count( $from ), count( $to ), count( $queue ), size_format( $total, 1 ) ) );
				if ( in_array( 'media_delete', $d['stages'], true ) ) {
					$this->log( sprintf( 'メディア: 送り元にないもの %d 件を消します。', count( $deletes ) ) );
				}
				$this->next_stage();
				return;

			case 'code_scan':
				list( $root, $slug, $name ) = $d['code'][ $d['ci'] ];
				$label                      = ( 'themes' === $root ? 'テーマ' : 'プラグイン' ) . '「' . $name . '」';
				$from                       = $src->call( 'files_list', array( 'root' => $root, 'sub' => $slug ) );
				$to                         = $dst->call( 'files_list', array( 'root' => $root, 'sub' => $slug ) );
				$from                       = $from['files'];
				$to                         = $to['files'];
				$send                       = 0;
				$bytes                      = 0;
				foreach ( $from as $rel => $meta ) {
					if ( ! isset( $to[ $rel ] ) || $to[ $rel ][0] !== $meta[0] || $to[ $rel ][1] !== $meta[1] ) {
						$d['queue'][]       = array( (string) $rel, $meta[0], $meta[1], $root );
						$d['bytes_total']  += $meta[0];
						$bytes             += $meta[0];
						++$send;
					}
				}
				$deletes = array_values( array_map( 'strval', array_keys( array_diff_key( $to, $from ) ) ) );
				if ( $send || $deletes ) {
					$dst->call( 'code_begin', array( 'root' => $root, 'slug' => $slug ) );
					$d['changed'][] = array( $root, $slug, $deletes, $label );
					$this->log( sprintf( '%s: %d 件（%s）を送り、%d 件を消します。', $label, $send, size_format( $bytes, 1 ), count( $deletes ) ) );
				} else {
					$this->log( $label . ': 変わっていません。' );
				}
				++$d['ci'];
				if ( $d['ci'] >= count( $d['code'] ) ) {
					$this->next_stage();
				}
				return;

			case 'code_copy':
			case 'media_copy':
				$this->copy_files( $src, $dst );
				if ( $d['qi'] >= count( $d['queue'] ) ) {
					$this->log( 'code_copy' === $stage ? 'テーマ・プラグインを送り終えました。' : 'メディアを送り終えました。' );
					$this->next_stage();
				}
				return;

			case 'code_commit':
				$item = array_shift( $d['changed'] );
				if ( $item ) {
					list( $root, $slug, $deletes, $label ) = $item;
					$dst->call(
						'code_commit',
						array(
							'root'    => $root,
							'slug'    => $slug,
							'deletes' => $deletes,
						)
					);
					$this->log( $label . 'を入れ替えました。' );
				}
				if ( ! $d['changed'] ) {
					$this->next_stage();
				}
				return;

			case 'media_delete':
				$batch = array_slice( $d['deletes'], $d['di'], 500 );
				if ( $batch ) {
					$dst->call(
						'files_delete',
						array(
							'root'  => 'uploads',
							'items' => $batch,
						)
					);
					$d['di'] += count( $batch );
				}
				if ( $d['di'] >= count( $d['deletes'] ) ) {
					$this->next_stage();
				}
				return;

			case 'db_begin':
				$dst->call( 'import_begin' );
				$this->log( sprintf( 'DB: %d 個のテーブルを送ります。', count( $d['tables'] ) ) );
				if ( ! empty( $d['keep_active'] ) ) {
					$this->log( 'DB: 有効化しているプラグインの設定は送らず、送り先のものを残します。' );
				}
				$this->next_stage();
				return;

			case 'db_tables':
				$table = $d['tables'][ $d['table'] ];
				if ( null === $d['cursor'] ) {
					$d['table_rows_start'] = $d['rows_done'];
				}
				$chunk = $src->call(
					'export_chunk',
					array(
						'table'     => $table['src'],
						'cursor'    => $d['cursor'],
						'max_bytes' => $d['chunk'],
					)
				);
				$dst->call(
					'import_chunk',
					array(
						'table'  => $table['dst'],
						'create' => isset( $chunk['create'] ) ? $chunk['create'] : null,
						'cols'   => $chunk['cols'],
						'rows'   => $chunk['rows'],
						'pairs'  => $d['pairs'],
					)
				);
				$d['rows_done'] += count( $chunk['rows'] );
				$d['cursor']     = $chunk['cursor'];
				if ( $chunk['done'] ) {
					$this->log( sprintf( 'DB: %s（%d 行）', $table['src'], $chunk['cursor']['offset'] ) );
					$d['cursor'] = null;
					++$d['table'];
					if ( $d['table'] >= count( $d['tables'] ) ) {
						$this->next_stage();
					}
				}
				return;

			case 'db_finalize':
				$dst->call(
					'import_finalize',
					array(
						'tables'     => wp_list_pluck( $d['tables'], 'dst' ),
						'src_prefix' => $d['src_prefix'],
						'src_home'   => $d['src_home'],
						'keep_active_plugins' => ! empty( $d['keep_active'] ),
						'sites'      => isset( $d['sites'] ) ? $d['sites'] : array(),
					)
				);
				$this->log( 'DB を入れ替えました。入れ替える前の DB はバックアップとして残しています。' );
				$this->next_stage();
				return;
		}
	}

	/**
	 * 順番待ちのファイルを、決まった大きさの分だけ送る。1 回に送るのは同じ場所（root）のファイルだけ。
	 */
	private function copy_files( WPS_Endpoint $src, WPS_Endpoint $dst ) {
		$d      = &$this->data;
		$budget = $d['chunk'];
		$items  = array();
		$metas  = array();
		$root   = null;
		while ( $d['qi'] < count( $d['queue'] ) && $budget > 0 ) {
			list( $rel, $size, $mtime, $item_root ) = $d['queue'][ $d['qi'] ];
			if ( null !== $root && $item_root !== $root ) {
				break;
			}
			$root    = $item_root;
			$length  = (int) min( $size - $d['qoffset'], $budget );
			$items[] = array( $rel, $d['qoffset'], $length );
			$metas[] = array( $size, $mtime );
			$budget -= max( 1, $length );
			if ( $d['qoffset'] + $length >= $size ) {
				++$d['qi'];
				$d['qoffset'] = 0;
			} else {
				$d['qoffset'] += $length;
			}
		}
		if ( ! $items ) {
			return;
		}
		$got  = $src->call(
			'files_get',
			array(
				'root'  => $root,
				'items' => $items,
			)
		);
		$puts = array();
		foreach ( $items as $i => $item ) {
			$chunk = isset( $got['data'][ $i ] ) ? $got['data'][ $i ] : null;
			if ( null === $chunk || strlen( $chunk ) !== $item[2] ) {
				// 比べた後に消えたり変わったりしたファイル。
				$d['errors'][] = $item[0];
				$this->log( '読めなかったので飛ばしました: ' . $item[0] );
				continue;
			}
			$puts[] = array( $item[0], $item[1], $metas[ $i ][0], $metas[ $i ][1], $chunk );
		}
		if ( $puts ) {
			$put              = $dst->call(
				'files_put',
				array(
					'root'  => $root,
					'items' => $puts,
				)
			);
			$d['bytes_done'] += $put['bytes'];
		}
	}

	public function status() {
		$d     = $this->data;
		$stage = $this->stage();

		$percent = 0;
		if ( 'media_copy' === $stage || 'code_copy' === $stage ) {
			$percent = $d['bytes_total'] ? $d['bytes_done'] / $d['bytes_total'] : 1;
		} elseif ( 'db_tables' === $stage ) {
			$percent = $d['rows_total'] ? $d['rows_done'] / $d['rows_total'] : $d['table'] / max( 1, count( $d['tables'] ) );
		} elseif ( 'done' === $stage ) {
			$percent = 1;
		}

		$detail = '';
		if ( 'code_scan' === $stage && isset( $d['code'][ $d['ci'] ] ) ) {
			$detail = sprintf( '%s（%d / %d 個目）', $d['code'][ $d['ci'] ][2], $d['ci'] + 1, count( $d['code'] ) );
		} elseif ( 'media_copy' === $stage || 'code_copy' === $stage ) {
			$detail = sprintf( '%d / %d 件（%s / %s）', $d['qi'], count( $d['queue'] ), size_format( $d['bytes_done'], 1 ), size_format( $d['bytes_total'], 1 ) );
		} elseif ( 'db_tables' === $stage && isset( $d['tables'][ $d['table'] ] ) ) {
			$detail = sprintf( '%s（%d / %d 個目）', $d['tables'][ $d['table'] ]['src'], $d['table'] + 1, count( $d['tables'] ) );
		}

		$log = $d['log'];
		// 返した分のログは次から返さない。
		$this->data['log'] = array();
		$this->save_if_running();

		return array(
			'id'        => $d['id'],
			'direction' => $d['direction'],
			'stage'     => $stage,
			'label'     => self::STAGE_LABELS[ $stage ],
			'detail'    => $detail,
			'percent'   => (int) floor( min( 1, $percent ) * 100 ),
			'step'      => $d['stage'] + 1,
			'steps'     => count( $d['stages'] ),
			'done'      => 'done' === $stage,
			'log'       => $log,
			'skipped'   => count( $d['errors'] ),
			// pull で DB を入れ替えたら、このサイトのユーザーは送り元のものになる。
			'relogin'   => 'done' === $stage && 'pull' === $d['direction'] && in_array( 'db_finalize', $d['stages'], true ),
		);
	}

	private function save_if_running() {
		if ( 'done' !== $this->stage() ) {
			$this->save();
		}
	}
}
