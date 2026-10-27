<?php
/**
 * ファイルの一覧・読み出し・書き込み。
 *
 * 扱う場所（root）は 3 つ。パスはすべて、その場所からの相対パスで扱い、外に出るパスは扱わない。
 * - uploads … メディア。PHP などの実行できるファイルは扱わない
 * - themes  … テーマ。1 つのテーマ（フォルダ）ごとに扱う
 * - plugins … プラグイン。1 つのプラグイン（フォルダか、1 つのファイル）ごとに扱う
 *
 * テーマとプラグインは、違うファイルだけを wp-content/wps-stage/ にいったん置き、
 * 全部届いてから一度に入れ替える（code_commit）。送っている途中で古いファイルと新しいファイルが混ざらない。
 * 入れ替えで上書き・削除したファイルは wp-content/wps-old/ に 1 回分だけ残す。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Files {

	/** 1 回のやり取りで送るファイルの大きさの目安（バイト）。大きなファイルはこの大きさに分けて送る。 */
	const CHUNK_BYTES = 2097152;

	/** このプラグインの作業用フォルダ（uploads の中）。同期の対象にしない。 */
	const WORK_DIR = 'wp-sync';

	const PART_SUFFIX = '.wpspart';

	const ROOTS = array( 'uploads', 'themes', 'plugins' );

	/** テーマ・プラグインで送らないフォルダ。 */
	const CODE_SKIP_DIRS = array( '.git', '.svn', 'node_modules' );

	public static function base() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}

	private static function root( $args ) {
		$root = isset( $args['root'] ) ? (string) $args['root'] : 'uploads';
		if ( ! in_array( $root, self::ROOTS, true ) ) {
			throw new Exception( '扱えない場所です: ' . $root );
		}
		return $root;
	}

	/**
	 * @param string $area live: 本物の場所 / stage: 入れ替え前に置く場所 / old: 入れ替えで外したファイルを残す場所
	 */
	private static function dir( $root, $area = 'live' ) {
		if ( 'live' !== $area ) {
			return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) . '/wps-' . $area . '/' . $root;
		}
		switch ( $root ) {
			case 'themes':
				return untrailingslashit( wp_normalize_path( get_theme_root() ) );
			case 'plugins':
				return untrailingslashit( wp_normalize_path( WP_PLUGIN_DIR ) );
		}
		return self::base();
	}

	/**
	 * 同期しないファイルか。
	 */
	public static function skipped( $rel, $root = 'uploads' ) {
		$rel  = (string) $rel;
		$name = strtolower( basename( $rel ) );
		if ( substr( $rel, -strlen( self::PART_SUFFIX ) ) === self::PART_SUFFIX || '.ds_store' === $name ) {
			return true;
		}
		if ( 'uploads' !== $root ) {
			$parts = explode( '/', $rel );
			// WP Sync 自身は送らない（動いているプラグインを書き換えないように。版は両側でそろえる）。
			if ( 'plugins' === $root && 'wp-sync' === $parts[0] ) {
				return true;
			}
			return (bool) array_intersect( $parts, self::CODE_SKIP_DIRS );
		}
		if ( self::WORK_DIR === $rel || 0 === strpos( $rel, self::WORK_DIR . '/' ) ) {
			return true;
		}
		if ( in_array( $name, array( '.htaccess', '.user.ini', 'php.ini', 'web.config' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|exe)$/i', $name );
	}

	/**
	 * テーマ・プラグインの名前（フォルダ名か、1 つのファイルのプラグインのファイル名）か確かめる。
	 */
	public static function check_slug( $slug ) {
		$slug = (string) $slug;
		if ( ! preg_match( '/^[A-Za-z0-9][A-Za-z0-9._-]*$/', $slug ) || false !== strpos( $slug, '..' ) ) {
			throw new Exception( '扱えない名前です: ' . $slug );
		}
		return $slug;
	}

	private static function check_rel( $rel, $root ) {
		$rel = (string) $rel;
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) || '/' === $rel[0]
			|| in_array( '..', explode( '/', $rel ), true ) || in_array( '.', explode( '/', $rel ), true ) || self::skipped( $rel, $root ) ) {
			throw new Exception( '扱えないファイルのパスです: ' . $rel );
		}
		if ( 'uploads' !== $root ) {
			// テーマ・プラグインは、その中のファイルだけ（themes/index.php などは扱わない）。
			$slug = strtok( $rel, '/' );
			self::check_slug( $slug );
			if ( 'themes' === $root && $slug === $rel ) {
				throw new Exception( '扱えないファイルのパスです: ' . $rel );
			}
			if ( 'plugins' === $root && $slug === $rel && '.php' !== substr( $rel, -4 ) ) {
				throw new Exception( '扱えないファイルのパスです: ' . $rel );
			}
		}
		return $rel;
	}

	private static function path( $rel, $root, $area = 'live' ) {
		return self::dir( $root, $area ) . '/' . self::check_rel( $rel, $root );
	}

	/**
	 * @param array $args root: 場所、sub: テーマ・プラグインの名前（テーマ・プラグインでは必須）
	 * @return array{files:array<string,array{0:int,1:int}>} 相対パス => [大きさ, 更新日時]
	 */
	public static function list_files( $args = array() ) {
		$root  = self::root( $args );
		$base  = self::dir( $root );
		$start = $base;
		if ( 'uploads' !== $root ) {
			$start = $base . '/' . self::check_slug( isset( $args['sub'] ) ? $args['sub'] : '' );
		}

		$files = array();
		$len   = strlen( $base ) + 1;
		if ( is_file( $start ) ) {
			$rel = substr( $start, $len );
			if ( ! self::skipped( $rel, $root ) ) {
				$files[ $rel ] = array( (int) filesize( $start ), (int) filemtime( $start ) );
			}
		} elseif ( is_dir( $start ) ) {
			$skip     = 'uploads' === $root ? array() : self::CODE_SKIP_DIRS;
			$iterator = new RecursiveIteratorIterator(
				new RecursiveCallbackFilterIterator(
					new RecursiveDirectoryIterator( $start, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
					function ( $file ) use ( $skip ) {
						// node_modules などは、中に入らずに飛ばす（数が多いので）。
						return ! ( $file->isDir() && in_array( $file->getFilename(), $skip, true ) );
					}
				),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$rel = substr( wp_normalize_path( $file->getPathname() ), $len );
				if ( self::skipped( $rel, $root ) ) {
					continue;
				}
				$files[ $rel ] = array( (int) $file->getSize(), (int) $file->getMTime() );
			}
		}
		return array( 'files' => $files );
	}

	/**
	 * @param array $args root、items: [[相対パス, 位置, 長さ], ...]
	 * @return array{data:array<int,string|null>} items と同じ順の中身（読めなかったものは null）
	 */
	public static function get( $args ) {
		$root = self::root( $args );
		$data = array();
		foreach ( (array) $args['items'] as $item ) {
			list( $rel, $offset, $length ) = $item;
			$path                          = self::path( $rel, $root );
			$handle                        = is_file( $path ) ? fopen( $path, 'rb' ) : false; // phpcs:ignore
			if ( ! $handle ) {
				$data[] = null;
				continue;
			}
			fseek( $handle, (int) $offset );
			$chunk = (int) $length > 0 ? fread( $handle, (int) $length ) : ''; // phpcs:ignore
			fclose( $handle ); // phpcs:ignore
			$data[] = false === $chunk ? null : $chunk;
		}
		return array( 'data' => $data );
	}

	/**
	 * 書き込む。分けて届くファイルは .wpspart に足していき、最後の分が届いたら名前を変える。
	 * テーマ・プラグインは、本物の場所ではなく wps-stage に書く（code_commit で入れ替える）。
	 *
	 * @param array $args root、items: [[相対パス, 位置, 全体の大きさ, 更新日時, 中身], ...]
	 */
	public static function put( $args ) {
		$root    = self::root( $args );
		$area    = 'uploads' === $root ? 'live' : 'stage';
		$written = 0;
		foreach ( (array) $args['items'] as $item ) {
			list( $rel, $offset, $size, $mtime, $chunk ) = $item;
			$path                                        = self::path( $rel, $root, $area );
			$part                                        = $path . self::PART_SUFFIX;
			$offset                                      = (int) $offset;

			if ( 0 === $offset ) {
				if ( ! wp_mkdir_p( dirname( $path ) ) ) {
					throw new Exception( 'フォルダを作れませんでした: ' . dirname( $rel ) );
				}
				$handle = fopen( $part, 'wb' ); // phpcs:ignore
			} else {
				clearstatcache( true, $part );
				if ( ! is_file( $part ) || filesize( $part ) !== $offset ) {
					throw new Exception( '分けて送ったファイルの続きが合いません: ' . $rel );
				}
				$handle = fopen( $part, 'ab' ); // phpcs:ignore
			}
			if ( ! $handle ) {
				throw new Exception( 'ファイルを書き込めませんでした: ' . $rel );
			}
			fwrite( $handle, (string) $chunk ); // phpcs:ignore
			fclose( $handle ); // phpcs:ignore
			$written += strlen( (string) $chunk );

			if ( $offset + strlen( (string) $chunk ) >= (int) $size ) {
				if ( ! rename( $part, $path ) ) { // phpcs:ignore
					throw new Exception( 'ファイルを置き換えられませんでした: ' . $rel );
				}
				// 次の比較で「変わっていない」と分かるよう、更新日時を送り元に合わせる。
				touch( $path, (int) $mtime ); // phpcs:ignore
			}
		}
		return array( 'bytes' => $written );
	}

	/**
	 * メディアを消す。
	 *
	 * @param array $args items: 相対パスの配列
	 */
	public static function delete( $args ) {
		$root = self::root( $args );
		if ( 'uploads' !== $root ) {
			throw new Exception( 'テーマ・プラグインのファイルは code_commit で消します。' );
		}
		$deleted = 0;
		foreach ( (array) $args['items'] as $rel ) {
			$path = self::path( $rel, $root );
			if ( is_file( $path ) && unlink( $path ) ) { // phpcs:ignore
				++$deleted;
			}
		}
		return array( 'deleted' => $deleted );
	}

	/**
	 * テーマ・プラグイン 1 つ分の送り込みを始める。前回の wps-stage と wps-old の分を消す。
	 *
	 * @param array $args root: themes|plugins、slug: 名前
	 */
	public static function code_begin( $args ) {
		$root = self::code_root( $args );
		$slug = self::check_slug( $args['slug'] );
		foreach ( array( 'stage', 'old' ) as $area ) {
			self::remove( self::dir( $root, $area ) . '/' . $slug );
		}
		return true;
	}

	/**
	 * wps-stage に置いたファイルを本物の場所に移し、送り元にないファイルを消す。
	 * 上書き・削除するファイルは wps-old に移しておく。
	 *
	 * @param array $args root、slug、deletes: 消すファイルの相対パス
	 */
	public static function code_commit( $args ) {
		$root  = self::code_root( $args );
		$slug  = self::check_slug( $args['slug'] );
		$stage = self::dir( $root, 'stage' );
		$moved = 0;

		$staged = array();
		$start  = $stage . '/' . $slug;
		if ( is_file( $start ) ) {
			$staged[] = $slug;
		} elseif ( is_dir( $start ) ) {
			$len = strlen( $stage ) + 1;
			foreach ( new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $start, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ) ) as $file ) {
				if ( $file->isFile() ) {
					$staged[] = substr( wp_normalize_path( $file->getPathname() ), $len );
				}
			}
		}

		foreach ( $staged as $rel ) {
			$from = self::path( $rel, $root, 'stage' );
			$to   = self::path( $rel, $root );
			if ( 0 !== strpos( $rel, $slug . '/' ) && $rel !== $slug ) {
				throw new Exception( '扱えないファイルのパスです: ' . $rel );
			}
			self::keep_old( $rel, $root );
			if ( ! wp_mkdir_p( dirname( $to ) ) || ! rename( $from, $to ) ) { // phpcs:ignore
				throw new Exception( 'ファイルを置き換えられませんでした: ' . $rel );
			}
			self::invalidate( $to );
			++$moved;
		}

		$deleted = 0;
		foreach ( isset( $args['deletes'] ) ? (array) $args['deletes'] : array() as $rel ) {
			if ( 0 !== strpos( (string) $rel, $slug . '/' ) ) {
				continue;
			}
			$path = self::path( $rel, $root );
			if ( is_file( $path ) ) {
				self::keep_old( $rel, $root );
				self::invalidate( $path );
				++$deleted;
			}
		}

		self::remove( $start );
		return array(
			'moved'   => $moved,
			'deleted' => $deleted,
		);
	}

	private static function code_root( $args ) {
		$root = self::root( $args );
		if ( 'uploads' === $root ) {
			throw new Exception( 'テーマかプラグインを指定してください。' );
		}
		return $root;
	}

	/** 本物の場所にあるファイルを wps-old に移す（なければ何もしない）。 */
	private static function keep_old( $rel, $root ) {
		$live = self::path( $rel, $root );
		if ( ! is_file( $live ) ) {
			return;
		}
		$old = self::path( $rel, $root, 'old' );
		if ( ! wp_mkdir_p( dirname( $old ) ) || ! rename( $live, $old ) ) { // phpcs:ignore
			throw new Exception( '前のファイルを退避できませんでした: ' . $rel );
		}
	}

	private static function invalidate( $path ) {
		if ( function_exists( 'opcache_invalidate' ) && '.php' === substr( $path, -4 ) ) {
			@opcache_invalidate( $path, true ); // phpcs:ignore
		}
	}

	/**
	 * wps-stage / wps-old の中のファイルかフォルダを消す。
	 */
	private static function remove( $path ) {
		$content = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		if ( 0 !== strpos( $path, $content . '/wps-stage/' ) && 0 !== strpos( $path, $content . '/wps-old/' ) ) {
			throw new Exception( '消せない場所です: ' . $path );
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path ); // phpcs:ignore
			return;
		}
		if ( ! is_dir( $path ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			if ( $file->isDir() && ! $file->isLink() ) {
				rmdir( $file->getPathname() ); // phpcs:ignore
			} else {
				unlink( $file->getPathname() ); // phpcs:ignore
			}
		}
		rmdir( $path ); // phpcs:ignore
	}

	/**
	 * 同期できるテーマ・プラグインの一覧（画面で選ぶため）。
	 *
	 * @return array{themes:array,plugins:array}
	 */
	public static function code_items() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		$themes = array();
		foreach ( wp_get_themes() as $slug => $theme ) {
			$themes[] = array(
				'slug'    => (string) $slug,
				'name'    => $theme->get( 'Name' ),
				'version' => $theme->get( 'Version' ),
				'active'  => get_stylesheet() === $slug || get_template() === $slug,
			);
		}
		$plugins = array();
		foreach ( get_plugins() as $file => $data ) {
			$slug = false === strpos( $file, '/' ) ? $file : dirname( $file );
			if ( 'wp-sync' === $slug ) {
				continue;
			}
			$plugins[] = array(
				'slug'    => $slug,
				'name'    => $data['Name'],
				'version' => $data['Version'],
				'active'  => is_plugin_active( $file ),
			);
		}
		return array(
			'themes'  => $themes,
			'plugins' => $plugins,
		);
	}
}
