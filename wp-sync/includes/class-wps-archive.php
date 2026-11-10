<?php
/**
 * DB のファイルへの書き出し（エクスポート）と、ファイルからの取り込み（インポート）。
 *
 * 書き出すのは、そのまま mysql でも読み込める SQL を gzip したもの（.sql.gz）。先頭のコメントに書き出したサイトの情報
 * （URL・パス・接頭辞・マルチサイトのサイト）を入れておき、取り込むときに、このサイトの URL・パス・接頭辞へ自動で置き換える。
 * ほかの道具で書き出した SQL（mysqldump・phpMyAdmin など）も、options テーブルの home・siteurl を読んで置き換えて取り込める
 * （マルチサイトは WP Sync で書き出したものだけ）。
 *
 * 取り込みの中身は同期と同じ（仮のテーブルに入れて、最後に一度に入れ替える。入れ替える前の DB を 1 回分残す）。
 * どちらも、何回かに分けて進める（1 回の要求が長くならないように）。途中の状態は uploads/wp-sync/ に置く。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Archive {

	/** 1 回の step() で作業する時間の目安（秒）。 */
	const STEP_SECONDS = 8;

	/** 書き出しで、1 つの INSERT 文の大きさの目安（バイト）。 */
	const INSERT_BYTES = 1048576;

	/** ファイルの先頭に入れる、書き出したサイトの情報の印。 */
	const META_MARK = '-- WPS-META: ';

	const STAGE_LABELS = array(
		'dump'     => 'DB を書き出しています',
		'unpack'   => 'ファイルを展開しています',
		'scan'     => 'ファイルを調べています',
		'confirm'  => '取り込む前の確認',
		'load'     => 'DB を取り込んでいます',
		'finalize' => 'DB を入れ替えています',
		'done'     => '完了しました',
	);

	private $data;

	private function __construct( array $data ) {
		$this->data = $data;
	}

	/* ---------- 状態の保存 ---------- */

	private static function path( $id, $kind ) {
		if ( ! preg_match( '/^[A-Za-z0-9]{20}$/', (string) $id ) ) {
			throw new Exception( 'ID が正しくありません。' );
		}
		$names = array(
			'state'  => 'archive-%s.php',
			'export' => 'export-%s.sql.gz',
			'upload' => 'upload-%s.part',
			'sql'    => 'import-%s.sql',
		);
		return WPS_Job::dir() . '/' . sprintf( $names[ $kind ], $id );
	}

	public static function load( $id ) {
		$file = self::path( $id, 'state' );
		if ( ! is_file( $file ) ) {
			throw new Exception( '作業の状態が見つかりません。最初からやり直してください。' );
		}
		$raw  = (string) file_get_contents( $file ); // phpcs:ignore
		$data = @unserialize( substr( $raw, strpos( $raw, "\n" ) + 1 ), array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( ! is_array( $data ) ) {
			throw new Exception( '作業の状態を読めませんでした。最初からやり直してください。' );
		}
		return new self( $data );
	}

	private function save() {
		// ウェブから直接開かれても中身が出ないよう、先頭で exit する PHP として保存する。
		file_put_contents( self::path( $this->data['id'], 'state' ), "<?php exit; ?>\n" . serialize( $this->data ) ); // phpcs:ignore
	}

	/**
	 * 1 日より前の作業のファイルを消す。
	 */
	private static function cleanup() {
		foreach ( array( 'archive-*.php', 'export-*.sql.gz', 'upload-*.part', 'import-*.sql' ) as $pattern ) {
			foreach ( (array) glob( WPS_Job::dir() . '/' . $pattern ) as $file ) {
				if ( filemtime( $file ) < time() - DAY_IN_SECONDS ) {
					unlink( $file ); // phpcs:ignore
				}
			}
		}
	}

	private static function new_id() {
		return wp_generate_password( 20, false, false );
	}

	/* ---------- エクスポート ---------- */

	/**
	 * 書き出しを始める。
	 *
	 * @param array $pairs 書き出すときに置き換える組（[前, 後] の配列）。
	 */
	public static function start_export( array $pairs ) {
		WPS_Settings::assert_main_site();
		self::cleanup();
		$info   = WPS_Server::info();
		$tables = wp_list_pluck( $info['tables'], 'name' );
		$extra  = self::clean_pairs( $pairs );

		$job = new self(
			array(
				'id'      => self::new_id(),
				'kind'    => 'export',
				'stages'  => array( 'dump', 'done' ),
				'stage'   => 0,
				'tables'  => $tables,
				'table'   => 0,
				'cursor'  => null,
				'rows'    => 0,
				'total'   => array_sum( wp_list_pluck( $info['tables'], 'bytes' ) ),
				'done'    => 0,
				'pairs'   => array_map(
					function ( $pair ) {
						return array( $pair[0], $pair[1], '' );
					},
					$extra
				),
				'name'    => self::file_name( $info['home'] ),
				'log'     => array(),
			)
		);

		$meta = self::meta( $info );
		$head = '-- WP Sync ' . WPS_VERSION . " による DB のエクスポート\n"
			. '-- サイト: ' . $info['home'] . "\n"
			. '-- 日時: ' . wp_date( 'Y-m-d H:i:s T' ) . "\n"
			. ( $extra ? '-- 置き換えた組: ' . count( $extra ) . " 件\n" : '' )
			. self::META_MARK . base64_encode( wp_json_encode( $meta ) ) . "\n\n"
			. "SET NAMES utf8mb4;\nSET foreign_key_checks = 0;\nSET sql_mode = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
		self::gz_write( self::path( $job->data['id'], 'export' ), $head, 'wb' );

		$job->log( sprintf( 'エクスポートを始めました（%d 個のテーブル）。', count( $tables ) ) );
		if ( $extra ) {
			$job->log( sprintf( '書き出すときに %d 組を置き換えます。', count( $extra ) ) );
		}
		$job->save();
		return $job->status();
	}

	/**
	 * 取り込むときに要る、書き出したサイトの情報。
	 */
	private static function meta( array $info ) {
		$keys = array( 'version', 'name', 'home', 'siteurl', 'abspath', 'content_url', 'content_dir', 'uploads_dir', 'uploads_url', 'prefix', 'multisite', 'network', 'wp' );
		$meta = array_intersect_key( $info, array_flip( $keys ) );
		$meta['time'] = time();
		return $meta;
	}

	private static function file_name( $home ) {
		$host = preg_replace( '/[^A-Za-z0-9.-]+/', '-', (string) wp_parse_url( $home, PHP_URL_HOST ) );
		$port = wp_parse_url( $home, PHP_URL_PORT );
		return ( $host ? $host : 'site' ) . ( $port ? '-' . $port : '' ) . '-' . wp_date( 'Ymd-His' ) . '.sql.gz';
	}

	private static function gz_write( $file, $text, $mode = 'ab' ) {
		// 書き足すたびに gzip の塊が増えるが、続けて並んだ gzip は gzip・zcat・mysql の取り込みでも 1 つのファイルとして読める。
		$gz = gzopen( $file, $mode . '6' );
		if ( ! $gz ) {
			throw new Exception( 'エクスポートのファイルに書き込めませんでした。' );
		}
		gzwrite( $gz, $text );
		gzclose( $gz );
	}

	private function step_dump() {
		$d     = &$this->data;
		$table = $d['tables'][ $d['table'] ];
		$chunk = WPS_DB::export_chunk(
			array(
				'table'     => $table,
				'cursor'    => $d['cursor'],
				'max_bytes' => 2097152,
			)
		);

		$out = '';
		if ( isset( $chunk['create'] ) ) {
			$out .= '-- ' . $table . "\nDROP TABLE IF EXISTS " . WPS_DB::ident( $table ) . ";\n" . $chunk['create'] . ";\n\n";
		}

		// 接続キーなど WP Sync の設定は書き出さない（ファイルを人に渡したときに、キーが漏れないように）。
		$skip  = array_merge( WPS_Settings::KEEP_OPTIONS, array( 'wps_backup' ) );
		$name  = preg_match( '/options$/', $table ) ? array_search( 'option_name', $chunk['cols'], true ) : false;
		$rep   = new WPS_Replacer( $d['pairs'] );
		$head  = 'INSERT INTO ' . WPS_DB::ident( $table ) . ' (' . implode( ', ', array_map( array( 'WPS_DB', 'ident' ), $chunk['cols'] ) ) . ') VALUES ';
		$tuple = array();
		$size  = 0;
		foreach ( $chunk['rows'] as $row ) {
			if ( false !== $name && in_array( $row[ $name ], $skip, true ) ) {
				continue;
			}
			$values = array();
			foreach ( $row as $value ) {
				$values[] = WPS_DB::quote( $rep->value( $value ) );
				$d['done'] += null === $value ? 0 : strlen( $value );
			}
			$tuple[] = '(' . implode( ',', $values ) . ')';
			$size   += strlen( end( $tuple ) );
			if ( $size >= self::INSERT_BYTES ) {
				$out  .= $head . implode( ",\n", $tuple ) . ";\n";
				$tuple = array();
				$size  = 0;
			}
		}
		if ( $tuple ) {
			$out .= $head . implode( ",\n", $tuple ) . ";\n";
		}
		if ( '' !== $out ) {
			self::gz_write( self::path( $d['id'], 'export' ), $out );
		}

		$d['rows']  += count( $chunk['rows'] );
		$d['cursor'] = $chunk['cursor'];
		if ( $chunk['done'] ) {
			$d['cursor'] = null;
			++$d['table'];
			if ( $d['table'] >= count( $d['tables'] ) ) {
				self::gz_write( self::path( $d['id'], 'export' ), "\nSET foreign_key_checks = 1;\n" );
				$this->log( sprintf( '書き出し終えました（%d 個のテーブル、%d 行、%s）。', count( $d['tables'] ), $d['rows'], size_format( filesize( self::path( $d['id'], 'export' ) ), 1 ) ) );
				++$d['stage'];
			}
		}
	}

	/**
	 * 書き出したファイルを渡す。
	 */
	public static function download( $id ) {
		$job = self::load( $id );
		if ( 'export' !== $job->data['kind'] || 'done' !== $job->stage() ) {
			throw new Exception( 'まだ書き出し終えていません。' );
		}
		$file = self::path( $id, 'export' );
		if ( ! is_file( $file ) ) {
			throw new Exception( 'エクスポートのファイルが見つかりません。もう一度エクスポートしてください。' );
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		nocache_headers();
		header( 'Content-Type: application/gzip' );
		header( 'Content-Disposition: attachment; filename="' . $job->data['name'] . '"' );
		header( 'Content-Length: ' . filesize( $file ) );
		readfile( $file ); // phpcs:ignore
		exit;
	}

	/* ---------- インポート ---------- */

	/**
	 * アップロードを分けて受け取る。最初の分では ID を作って返す。
	 *
	 * @param string $id     2 回目からの ID（最初は空）。
	 * @param int    $offset この分がファイルのどこからか。
	 * @param string $tmp    PHP がアップロードを置いたファイル。
	 */
	public static function upload( $id, $offset, $tmp ) {
		WPS_Settings::assert_main_site();
		$offset = (int) $offset;
		if ( 0 === $offset ) {
			self::cleanup();
			$id = self::new_id();
		}
		$file = self::path( $id, 'upload' );
		clearstatcache( true, $file );
		$size = is_file( $file ) ? filesize( $file ) : 0;
		if ( $size !== $offset ) {
			throw new Exception( 'アップロードの続きが合いません。最初からやり直してください。' );
		}
		$in  = fopen( $tmp, 'rb' ); // phpcs:ignore
		$out = fopen( $file, 0 === $offset ? 'wb' : 'ab' ); // phpcs:ignore
		if ( ! $in || ! $out ) {
			throw new Exception( 'アップロードしたファイルを保存できませんでした。' );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in ); // phpcs:ignore
		fclose( $out ); // phpcs:ignore
		clearstatcache( true, $file );
		return array(
			'id'   => $id,
			'size' => filesize( $file ),
		);
	}

	/**
	 * アップロードし終えたファイルの取り込みを始める（まず展開して中を調べ、確認で止まる）。
	 */
	public static function start_import( $id, $name ) {
		WPS_Settings::assert_main_site();
		$upload = self::path( $id, 'upload' );
		if ( ! is_file( $upload ) ) {
			throw new Exception( 'アップロードしたファイルが見つかりません。' );
		}
		$handle = fopen( $upload, 'rb' ); // phpcs:ignore
		$magic  = fread( $handle, 2 ); // phpcs:ignore
		fclose( $handle ); // phpcs:ignore
		$gz = "\x1f\x8b" === $magic;
		if ( ! $gz ) {
			rename( $upload, self::path( $id, 'sql' ) ); // phpcs:ignore
		}

		$job = new self(
			array(
				'id'       => $id,
				'kind'     => 'import',
				'file'     => mb_substr( preg_replace( '/[^\pL\pN._ ()-]+/u', '', wp_basename( (string) $name ) ), 0, 100 ),
				'stages'   => array_merge( $gz ? array( 'unpack' ) : array(), array( 'scan', 'confirm', 'load', 'finalize', 'done' ) ),
				'stage'    => 0,
				'offset'   => 0,
				'size'     => $gz ? filesize( $upload ) : filesize( self::path( $id, 'sql' ) ),
				'meta'     => null,
				'guess'    => array(),
				'creates'  => array(),
				'tables'   => array(),
				'skipped'  => array(),
				'cols'     => array(),
				'current'  => null,
				'rows'     => 0,
				'pairs'    => array(),
				'sites'    => array(),
				'keep'     => true,
				'log'      => array(),
			)
		);
		$job->log( sprintf( '「%s」（%s）を受け取りました。', $job->data['file'], size_format( $job->data['size'], 1 ) ) );
		$job->save();
		return $job->status();
	}

	/**
	 * gzip を少しずつ展開する。gzseek は先頭から読み直すが、1 回の展開の量が多いので回数は少ない。
	 */
	private function step_unpack( $deadline ) {
		$d  = &$this->data;
		$gz = gzopen( self::path( $d['id'], 'upload' ), 'rb' );
		if ( ! $gz ) {
			throw new Exception( 'ファイルを展開できませんでした。' );
		}
		if ( $d['offset'] ) {
			gzseek( $gz, $d['offset'] );
		}
		$out = fopen( self::path( $d['id'], 'sql' ), $d['offset'] ? 'ab' : 'wb' ); // phpcs:ignore
		while ( ! gzeof( $gz ) && microtime( true ) < $deadline ) {
			$data = gzread( $gz, 1048576 );
			if ( false === $data ) {
				throw new Exception( 'ファイルを展開できませんでした（壊れているかもしれません）。' );
			}
			fwrite( $out, $data ); // phpcs:ignore
			$d['offset'] += strlen( $data );
		}
		$eof = gzeof( $gz );
		gzclose( $gz );
		fclose( $out ); // phpcs:ignore
		if ( $eof ) {
			unlink( self::path( $d['id'], 'upload' ) ); // phpcs:ignore
			$d['size']   = filesize( self::path( $d['id'], 'sql' ) );
			$d['offset'] = 0;
			$this->log( sprintf( '展開しました（%s）。', size_format( $d['size'], 1 ) ) );
			++$d['stage'];
		}
	}

	/**
	 * 中を調べる。WP Sync のファイルなら先頭の情報を読むだけ。ほかのファイルは全体を読み、接頭辞と home・siteurl を探す。
	 */
	private function step_scan( $deadline ) {
		$d      = &$this->data;
		$handle = fopen( self::path( $d['id'], 'sql' ), 'rb' ); // phpcs:ignore
		fseek( $handle, $d['offset'] );
		$finished = false;
		while ( microtime( true ) < $deadline ) {
			$sql = self::read_statement( $handle, $meta );
			if ( null !== $meta ) {
				$decoded = json_decode( (string) base64_decode( $meta ), true );
				if ( is_array( $decoded ) && isset( $decoded['home'], $decoded['prefix'] ) ) {
					$d['meta'] = $decoded;
					$finished  = true;
					break;
				}
			}
			if ( null === $sql ) {
				$finished = true;
				break;
			}
			$create = self::parse_create( $sql );
			if ( $create ) {
				$d['creates'][] = $create[0];
				continue;
			}
			$insert = self::parse_insert_head( $sql );
			// options テーブルの home・siteurl を探す（どのテーブルが本物の options かは、最後に接頭辞を決めてから選ぶ）。
			if ( $insert && preg_match( '/options$/', $insert[0] ) ) {
				$cols = $insert[1];
				if ( ! $cols ) {
					$cols = isset( $d['cols'][ $insert[0] ] ) ? $d['cols'][ $insert[0] ] : array( 'option_id', 'option_name', 'option_value', 'autoload' );
				}
				$name  = array_search( 'option_name', $cols, true );
				$value = array_search( 'option_value', $cols, true );
				if ( false !== $name && false !== $value ) {
					foreach ( self::parse_rows( $sql, $insert[2] ) as $row ) {
						if ( isset( $row[ $name ] ) && in_array( $row[ $name ], array( 'home', 'siteurl' ), true ) ) {
							$d['guess'][ $insert[0] ][ $row[ $name ] ] = $row[ $value ];
						}
					}
				}
			}
		}
		$d['offset'] = ftell( $handle );
		fclose( $handle ); // phpcs:ignore
		if ( ! $finished ) {
			return;
		}

		if ( ! $d['meta'] ) {
			$d['meta'] = $this->guess_meta();
			$this->log( 'WP Sync で書き出したファイルではないので、options テーブルから URL を読み取りました。' );
		} else {
			$this->log( sprintf( 'WP Sync %s で書き出したファイルです（%s、%s）。', $d['meta']['version'], $d['meta']['home'], isset( $d['meta']['time'] ) ? wp_date( 'Y年n月j日 H:i', $d['meta']['time'] ) : '日時不明' ) );
		}
		$this->check_meta( $d['meta'] );
		$d['offset'] = 0;
		++$d['stage'];
	}

	/**
	 * WP Sync で書き出していないファイルの、書き出したサイトの情報を推し量る。
	 */
	private function guess_meta() {
		$d      = $this->data;
		$prefix = null;
		// CREATE TABLE のうち、{接頭辞}options と {接頭辞}posts がそろっているものを WordPress のテーブルとみなす。
		foreach ( $d['creates'] as $table ) {
			if ( preg_match( '/^(.*)options$/', $table, $m ) && in_array( $m[1] . 'posts', $d['creates'], true ) ) {
				if ( null === $prefix || strlen( $m[1] ) < strlen( $prefix ) ) {
					$prefix = $m[1];
				}
			}
		}
		if ( null === $prefix ) {
			throw new Exception( 'WordPress の DB のファイルではないようです（options と posts のテーブルが見つかりません）。' );
		}
		if ( in_array( $prefix . 'blogs', $d['creates'], true ) ) {
			throw new Exception( 'マルチサイトの DB は、WP Sync でエクスポートしたファイルだけ取り込めます。' );
		}
		$found = isset( $d['guess'][ $prefix . 'options' ] ) ? $d['guess'][ $prefix . 'options' ] : array();
		if ( empty( $found['home'] ) && empty( $found['siteurl'] ) ) {
			throw new Exception( 'ファイルの options テーブルに home・siteurl が見つかりません。' );
		}
		$home    = untrailingslashit( ! empty( $found['home'] ) ? $found['home'] : $found['siteurl'] );
		$siteurl = untrailingslashit( ! empty( $found['siteurl'] ) ? $found['siteurl'] : $home );
		return array(
			'version'   => '',
			'name'      => '',
			'home'      => $home,
			'siteurl'   => $siteurl,
			'prefix'    => $prefix,
			'multisite' => false,
			'network'   => null,
			'guessed'   => true,
		);
	}

	private function check_meta( array $meta ) {
		$local = WPS_Server::info();
		if ( (bool) $meta['multisite'] !== (bool) $local['multisite'] ) {
			throw new Exception( $local['multisite'] ? 'このサイトはマルチサイトですが、ファイルはシングルサイトの DB です。シングルサイトとマルチサイトの間では取り込めません。' : 'このサイトはシングルサイトですが、ファイルはマルチサイトの DB です。シングルサイトとマルチサイトの間では取り込めません。' );
		}
		if ( $local['multisite'] && (int) $meta['network']['main'] !== (int) $local['network']['main'] ) {
			throw new Exception( 'メインのサイトの ID がファイルとこのサイトで違うので取り込めません。' );
		}
	}

	/**
	 * 確認の画面で見せる、ファイルの中身と置換の組。
	 */
	private function preview() {
		$meta  = $this->data['meta'];
		$local = WPS_Server::info();
		$src   = array_merge( array( 'content_url' => '', 'abspath' => '' ), $meta );
		$pairs = array( array( $src['home'], $local['home'] ) );
		if ( ! empty( $meta['guessed'] ) ) {
			if ( $src['siteurl'] !== $src['home'] ) {
				$pairs[] = array( $src['siteurl'], $local['siteurl'] );
			}
		} else {
			$pairs = WPS_Job::preview_pairs( $src, $local );
		}
		return array(
			'file'      => $this->data['file'],
			'name'      => isset( $meta['name'] ) ? $meta['name'] : '',
			'home'      => $meta['home'],
			'version'   => $meta['version'],
			'time'      => isset( $meta['time'] ) ? wp_date( 'Y年n月j日 H:i', $meta['time'] ) : '',
			'prefix'    => $meta['prefix'],
			'sites'     => ! empty( $meta['network'] ) ? count( $meta['network']['sites'] ) : 0,
			'guessed'   => ! empty( $meta['guessed'] ),
			'tables'    => ! empty( $meta['guessed'] ) ? count( $this->data['creates'] ) : null,
			'local'     => $local['home'],
			'pairs'     => array_values(
				array_filter(
					$pairs,
					function ( $pair ) {
						return $pair[0] !== $pair[1];
					}
				)
			),
		);
	}

	/**
	 * 確認したので取り込む。
	 *
	 * @param array $extra       足した置換の組（[前, 後] の配列）。
	 * @param bool  $keep_active プラグインの有効・無効をこのサイトのものにするか。
	 */
	public static function confirm( $id, array $extra, $keep_active ) {
		$job = self::load( $id );
		$d   = &$job->data;
		if ( 'import' !== $d['kind'] || 'confirm' !== $job->stage() ) {
			throw new Exception( 'この取り込みは確認の段階ではありません。' );
		}
		$local = WPS_Server::info();
		$src   = $d['meta'];
		$extra = self::clean_pairs( $extra );
		if ( ! empty( $src['guessed'] ) ) {
			$d['pairs'] = WPS_Replacer::build_pairs( $src, array_merge( $local, array( 'content_url' => '' ) ), $extra );
			$d['sites'] = array();
		} else {
			$d['pairs'] = WPS_Replacer::build_pairs( $src, $local, $extra, WPS_Job::site_pairs( $src, $local ) );
			$d['sites'] = array_map(
				function ( $site ) {
					return array( $site['id'], $site['domain'], $site['path'], $site['same'] );
				},
				WPS_Job::map_sites( $src, $local )
			);
		}
		$d['keep'] = (bool) $keep_active;
		WPS_DB::import_begin();
		$job->log( sprintf( '取り込みを始めました（%s → %s）。', $src['home'], $local['home'] ) );
		if ( $extra ) {
			$job->log( sprintf( '足した置換: %d 組。', count( $extra ) ) );
		}
		$job->log( $d['keep'] ? 'プラグインの有効・無効はこのサイトのままにします。' : 'プラグインの有効・無効をファイルに合わせます。' );
		++$d['stage'];
		$job->save();
		return $job->status();
	}

	/**
	 * SQL を 1 文ずつ読み、テーブルを作る文と行を入れる文だけを、このサイトの名前と置換で仮のテーブルに入れる。
	 * ファイルの文をそのまま流すことはしない（SET・DROP・LOCK などは読み飛ばす）。
	 */
	private function step_load( $deadline ) {
		global $wpdb;
		$d      = &$this->data;
		$handle = fopen( self::path( $d['id'], 'sql' ), 'rb' ); // phpcs:ignore
		fseek( $handle, $d['offset'] );
		$src = $d['meta']['prefix'];
		$dst = $wpdb->base_prefix;
		$end = false;
		while ( microtime( true ) < $deadline ) {
			$sql = self::read_statement( $handle, $meta );
			if ( null === $sql ) {
				$end = true;
				break;
			}
			$create = self::parse_create( $sql );
			if ( $create ) {
				list( $name, $statement ) = $create;
				$d['current']             = null;
				if ( 0 !== strpos( $name, $src ) || self::is_work_table( $name ) ) {
					$d['skipped'][] = $name;
				} else {
					$table = $dst . substr( $name, strlen( $src ) );
					WPS_DB::import_chunk(
						array(
							'table'  => $table,
							'create' => $statement,
						)
					);
					$d['tables'][]       = $table;
					$d['current']        = $name;
					$d['cols'][ $table ] = null;
				}
			} else {
				$insert = self::parse_insert_head( $sql );
				if ( $insert && ! in_array( $insert[0], $d['skipped'], true ) ) {
					list( $name, $cols, $pos ) = $insert;
					$table                     = $dst . substr( $name, strlen( $src ) );
					if ( 0 !== strpos( $name, $src ) || ! in_array( $table, $d['tables'], true ) ) {
						throw new Exception( 'テーブルを作る文より前に、行を入れる文があります: ' . $name );
					}
					if ( ! $cols ) {
						$cols = $d['cols'][ $table ];
						if ( ! $cols ) {
							$cols                = self::table_columns( WPS_DB::TMP . $table );
							$d['cols'][ $table ] = $cols;
						}
					}
					$rows = self::parse_rows( $sql, $pos );
					WPS_DB::import_chunk(
						array(
							'table' => $table,
							'cols'  => $cols,
							'rows'  => $rows,
							'pairs' => $d['pairs'],
						)
					);
					$d['rows'] += count( $rows );
				}
			}
			// 1 文ごとに残す（止まったときに、入れた文をもう一度入れないように）。
			$d['offset'] = ftell( $handle );
			$this->save();
		}
		$d['offset'] = ftell( $handle );
		fclose( $handle ); // phpcs:ignore
		if ( $end ) {
			if ( ! $d['tables'] ) {
				throw new Exception( '取り込めるテーブルがありませんでした。' );
			}
			$this->log( sprintf( '%d 個のテーブル（%d 行）を読み込みました。', count( $d['tables'] ), $d['rows'] ) );
			$work  = array_filter( $d['skipped'], array( __CLASS__, 'is_work_table' ) );
			$other = array_diff( $d['skipped'], $work );
			if ( $work ) {
				$this->log( sprintf( 'WP Sync の作業用のテーブル（%s など）%d 個は取り込みませんでした。', reset( $work ), count( $work ) ) );
			}
			if ( $other ) {
				$this->log( sprintf( '接頭辞（%s）が違うテーブル %d 個は取り込みませんでした: %s', $src, count( $other ), implode( ', ', array_slice( $other, 0, 10 ) ) . ( count( $other ) > 10 ? ' ほか' : '' ) ) );
			}
			++$d['stage'];
		}
	}

	private function step_finalize() {
		$d = &$this->data;
		WPS_DB::import_finalize(
			array(
				'tables'              => $d['tables'],
				'src_prefix'          => $d['meta']['prefix'],
				'src_home'            => $d['meta']['home'],
				'keep_active_plugins' => $d['keep'],
				'sites'               => $d['sites'],
			)
		);
		@unlink( self::path( $d['id'], 'sql' ) ); // phpcs:ignore
		$this->log( 'DB を入れ替えました。入れ替える前の DB はバックアップとして残しています。' );
		++$d['stage'];
	}

	private static function is_work_table( $name ) {
		foreach ( array( WPS_DB::TMP, WPS_DB::BAK, WPS_DB::OLD ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	private static function table_columns( $table ) {
		global $wpdb;
		return $wpdb->get_col( 'SHOW COLUMNS FROM ' . WPS_DB::ident( $table ) ); // phpcs:ignore
	}

	/* ---------- SQL の読み取り ---------- */

	/**
	 * 次の文を読む。文は、行の終わりが ; の行までとする（mysqldump も WP Sync も、文字列の中の改行は \n と書くので、
	 * 文字列の途中で行が終わることはない）。コメントと空の行は読み飛ばし、WP Sync の情報の行があれば $meta に入れる。
	 *
	 * @return string|null 文（最後の ; は除く）。ファイルの終わりなら null。
	 */
	private static function read_statement( $handle, &$meta ) {
		$meta = null;
		$buf  = '';
		while ( false !== ( $line = fgets( $handle ) ) ) {
			if ( '' === $buf ) {
				$trim = ltrim( $line );
				if ( '' === trim( $trim ) || 0 === strpos( $trim, '#' ) || ( 0 === strpos( $trim, '--' ) && ( '' === trim( substr( $trim, 2 ) ) || ctype_space( $trim[2] ) ) ) ) {
					if ( 0 === strpos( $trim, self::META_MARK ) ) {
						$meta = trim( substr( $trim, strlen( self::META_MARK ) ) );
						return '';
					}
					continue;
				}
			}
			$buf .= $line;
			$end  = rtrim( $line );
			if ( '' !== $end && ';' === substr( $end, -1 ) ) {
				return substr( rtrim( $buf ), 0, -1 );
			}
		}
		return '' === trim( $buf ) ? null : $buf;
	}

	/**
	 * @return array{0:string,1:string}|null [テーブル名, CREATE TABLE `名前` ... の形にそろえた文]
	 */
	private static function parse_create( $sql ) {
		if ( ! preg_match( '/^\s*CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)/i', $sql, $m ) ) {
			return null;
		}
		$name = '`' === $m[1][0] ? str_replace( '``', '`', substr( $m[1], 1, -1 ) ) : $m[1];
		return array( $name, 'CREATE TABLE ' . WPS_DB::ident( $name ) . substr( $sql, strlen( $m[0] ) ) );
	}

	/**
	 * @return array{0:string,1:string[]|null,2:int}|null [テーブル名, 列（書いていなければ null）, VALUES の後ろの位置]
	 */
	private static function parse_insert_head( $sql ) {
		if ( ! preg_match( '/^\s*(?:INSERT|REPLACE)\s+(?:(?:LOW_PRIORITY|DELAYED|HIGH_PRIORITY|IGNORE)\s+)*INTO\s+(`(?:[^`]|``)+`|[A-Za-z0-9_$]+)\s*(?:\(([^)]*)\))?\s*VALUES\s*/i', $sql, $m ) ) {
			return null;
		}
		$name = '`' === $m[1][0] ? str_replace( '``', '`', substr( $m[1], 1, -1 ) ) : $m[1];
		$cols = null;
		if ( isset( $m[2] ) && '' !== trim( $m[2] ) ) {
			$cols = array_map(
				function ( $col ) {
					return trim( trim( $col ), '`' );
				},
				explode( ',', $m[2] )
			);
		}
		return array( $name, $cols, strlen( $m[0] ) );
	}

	/**
	 * VALUES (...),(...) を読み、行の配列にする。値は文字列か null（数も文字列のまま。入れるときに MySQL が直す）。
	 */
	private static function parse_rows( $sql, $pos ) {
		$rows = array();
		$len  = strlen( $sql );
		while ( true ) {
			$pos += strspn( $sql, " \t\r\n", $pos );
			if ( $pos >= $len || '(' !== $sql[ $pos ] ) {
				break;
			}
			++$pos;
			$row = array();
			while ( true ) {
				$pos  += strspn( $sql, " \t\r\n", $pos );
				$row[] = self::parse_value( $sql, $pos );
				$pos  += strspn( $sql, " \t\r\n", $pos );
				$c     = $pos < $len ? $sql[ $pos ] : '';
				++$pos;
				if ( ',' === $c ) {
					continue;
				}
				if ( ')' === $c ) {
					break;
				}
				throw new Exception( 'SQL の値を読めませんでした（' . mb_substr( substr( $sql, max( 0, $pos - 40 ), 80 ), 0, 80 ) . '）。' );
			}
			$rows[] = $row;
			$pos   += strspn( $sql, " \t\r\n", $pos );
			if ( $pos < $len && ',' === $sql[ $pos ] ) {
				++$pos;
				continue;
			}
			break;
		}
		return $rows;
	}

	private static function parse_value( $sql, &$pos ) {
		$c = $sql[ $pos ];
		if ( "'" === $c || '"' === $c ) {
			return self::parse_string( $sql, $pos );
		}
		// _binary 'x'・_utf8mb4 'x' など（文字コードの前置き）。
		if ( '_' === $c && preg_match( '/\G_[A-Za-z0-9]+\s*(?=[\'"])/', $sql, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			return self::parse_string( $sql, $pos );
		}
		// X'4142'・x'4142'・B'0101'。
		if ( preg_match( '/\G([xXbB])\'([0-9A-Fa-f]*)\'/', $sql, $m, 0, $pos ) ) {
			$pos += strlen( $m[0] );
			return 'x' === strtolower( $m[1] ) ? (string) hex2bin( $m[2] ) : self::bits( $m[2] );
		}
		$n    = strcspn( $sql, ",) \t\r\n", $pos );
		$word = substr( $sql, $pos, $n );
		$pos += $n;
		if ( 0 === strcasecmp( $word, 'NULL' ) ) {
			return null;
		}
		if ( preg_match( '/^0x([0-9A-Fa-f]*)$/', $word, $m ) ) {
			return (string) hex2bin( strlen( $m[1] ) % 2 ? '0' . $m[1] : $m[1] );
		}
		return $word;
	}

	private static function bits( $bits ) {
		$bits = str_pad( $bits, (int) ceil( strlen( $bits ) / 8 ) * 8, '0', STR_PAD_LEFT );
		$out  = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			$out .= chr( bindec( $byte ) );
		}
		return $out;
	}

	/**
	 * '...' を読む。\ のエスケープ（\n・\' など）と、'' の重ね書きを戻す。
	 */
	private static function parse_string( $sql, &$pos ) {
		static $escapes = array(
			'0' => "\0",
			'n' => "\n",
			'r' => "\r",
			't' => "\t",
			'b' => "\x08",
			'Z' => "\x1a",
			'%' => '\\%',
			'_' => '\\_',
		);
		$quote = $sql[ $pos ];
		$len   = strlen( $sql );
		$out   = '';
		++$pos;
		while ( true ) {
			$n    = strcspn( $sql, $quote . '\\', $pos );
			$out .= substr( $sql, $pos, $n );
			$pos += $n;
			if ( $pos >= $len ) {
				throw new Exception( 'SQL の文字列が閉じていません。' );
			}
			if ( '\\' === $sql[ $pos ] ) {
				$next = $pos + 1 < $len ? $sql[ $pos + 1 ] : '';
				$out .= isset( $escapes[ $next ] ) ? $escapes[ $next ] : $next;
				$pos += 2;
				continue;
			}
			if ( $pos + 1 < $len && $quote === $sql[ $pos + 1 ] ) {
				$out .= $quote;
				$pos += 2;
				continue;
			}
			++$pos;
			return $out;
		}
	}

	/* ---------- 進める・状態 ---------- */

	public static function step_by_id( $id ) {
		return self::load( $id )->step();
	}

	public function step() {
		WPS_Settings::assert_main_site();
		$deadline = microtime( true ) + self::STEP_SECONDS;
		do {
			$stage = $this->stage();
			if ( in_array( $stage, array( 'done', 'confirm' ), true ) ) {
				break;
			}
			// 止まったときは、最後に残した状態から再開する（止まった回の分は残さない。load は 1 文ごとに残している）。
			switch ( $stage ) {
				case 'dump':
					$this->step_dump();
					break;
				case 'unpack':
					$this->step_unpack( $deadline );
					break;
				case 'scan':
					$this->step_scan( $deadline );
					break;
				case 'load':
					$this->step_load( $deadline );
					break;
				case 'finalize':
					$this->step_finalize();
					break;
			}
			$this->save();
		} while ( microtime( true ) < $deadline && 'finalize' !== $this->stage() );
		return $this->status();
	}

	private function stage() {
		return $this->data['stages'][ $this->data['stage'] ];
	}

	private function log( $message ) {
		$this->data['log'][] = $message;
	}

	public function status() {
		$d     = $this->data;
		$stage = $this->stage();

		$percent = 0;
		$detail  = '';
		if ( 'dump' === $stage ) {
			$percent = $d['total'] ? $d['done'] / $d['total'] : 0;
			$detail  = sprintf( '%s（%d / %d 個目）', $d['tables'][ $d['table'] ], $d['table'] + 1, count( $d['tables'] ) );
		} elseif ( in_array( $stage, array( 'unpack', 'scan', 'load' ), true ) ) {
			$percent = $d['size'] ? $d['offset'] / $d['size'] : 0;
			$detail  = sprintf( '%s / %s', size_format( $d['offset'], 1 ), size_format( $d['size'], 1 ) );
			if ( 'unpack' === $stage ) {
				$detail = size_format( $d['offset'], 1 ) . ' 展開しました';
				$percent = 0;
			}
		} elseif ( 'done' === $stage ) {
			$percent = 1;
		}

		$log = $d['log'];
		// 返した分のログは次から返さない。
		$this->data['log'] = array();
		if ( 'done' === $stage && 'import' === $d['kind'] ) {
			// 取り込み終えたら状態は要らない（エクスポートは、ダウンロードのために 1 日残す）。
			@unlink( self::path( $d['id'], 'state' ) ); // phpcs:ignore
		} else {
			$this->save();
		}

		$out = array(
			'id'      => $d['id'],
			'kind'    => $d['kind'],
			'stage'   => $stage,
			'label'   => self::STAGE_LABELS[ $stage ],
			'detail'  => $detail,
			'percent' => (int) floor( min( 1, $percent ) * 100 ),
			'step'    => $d['stage'] + 1,
			'steps'   => count( $d['stages'] ),
			'done'    => 'done' === $stage,
			'waiting' => 'confirm' === $stage,
			'log'     => $log,
		);
		if ( 'confirm' === $stage ) {
			$out['preview'] = $this->preview();
		}
		if ( 'done' === $stage && 'import' === $d['kind'] ) {
			// このサイトのユーザーはファイルのものになる。
			$out['relogin'] = true;
		}
		if ( 'done' === $stage && 'export' === $d['kind'] ) {
			$out['file'] = $d['name'];
			$out['size'] = size_format( filesize( self::path( $d['id'], 'export' ) ), 1 );
		}
		return $out;
	}

	private static function clean_pairs( array $pairs ) {
		$out = array();
		foreach ( $pairs as $pair ) {
			if ( is_array( $pair ) && isset( $pair[0], $pair[1] ) && '' !== trim( (string) $pair[0] ) ) {
				$out[] = array( (string) $pair[0], (string) $pair[1] );
			}
		}
		return $out;
	}
}
