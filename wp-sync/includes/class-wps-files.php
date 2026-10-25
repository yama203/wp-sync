<?php
/**
 * メディア（uploads フォルダ）の一覧・読み出し・書き込み。
 *
 * パスはすべて uploads からの相対パスで扱い、外に出るパスや PHP などの実行できるファイルは扱わない。
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

	public static function base() {
		$uploads = wp_upload_dir( null, false );
		return untrailingslashit( wp_normalize_path( $uploads['basedir'] ) );
	}

	/**
	 * 同期しないファイルか。
	 */
	public static function skipped( $rel ) {
		$rel = (string) $rel;
		if ( self::WORK_DIR === $rel || 0 === strpos( $rel, self::WORK_DIR . '/' ) ) {
			return true;
		}
		if ( substr( $rel, -strlen( self::PART_SUFFIX ) ) === self::PART_SUFFIX ) {
			return true;
		}
		$name = strtolower( basename( $rel ) );
		if ( in_array( $name, array( '.htaccess', '.user.ini', 'php.ini', 'web.config', '.ds_store' ), true ) ) {
			return true;
		}
		return (bool) preg_match( '/\.(php\d*|phtml|phar|pht|phps|cgi|pl|py|sh|exe)$/i', $name );
	}

	private static function path( $rel ) {
		$rel = (string) $rel;
		if ( '' === $rel || false !== strpos( $rel, "\0" ) || false !== strpos( $rel, '\\' ) || '/' === $rel[0]
			|| in_array( '..', explode( '/', $rel ), true ) || self::skipped( $rel ) ) {
			throw new Exception( '扱えないファイルのパスです: ' . $rel );
		}
		return self::base() . '/' . $rel;
	}

	/**
	 * @return array{files:array<string,array{0:int,1:int}>} 相対パス => [大きさ, 更新日時]
	 */
	public static function list_files() {
		$base  = self::base();
		$files = array();
		if ( is_dir( $base ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS ),
				RecursiveIteratorIterator::LEAVES_ONLY,
				RecursiveIteratorIterator::CATCH_GET_CHILD
			);
			$len = strlen( $base ) + 1;
			foreach ( $iterator as $file ) {
				if ( ! $file->isFile() ) {
					continue;
				}
				$rel = substr( wp_normalize_path( $file->getPathname() ), $len );
				if ( self::skipped( $rel ) ) {
					continue;
				}
				$files[ $rel ] = array( (int) $file->getSize(), (int) $file->getMTime() );
			}
		}
		return array( 'files' => $files );
	}

	/**
	 * @param array $args items: [[相対パス, 位置, 長さ], ...]
	 * @return array{data:array<int,string|null>} items と同じ順の中身（読めなかったものは null）
	 */
	public static function get( $args ) {
		$data = array();
		foreach ( (array) $args['items'] as $item ) {
			list( $rel, $offset, $length ) = $item;
			$path                          = self::path( $rel );
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
	 *
	 * @param array $args items: [[相対パス, 位置, 全体の大きさ, 更新日時, 中身], ...]
	 */
	public static function put( $args ) {
		$written = 0;
		foreach ( (array) $args['items'] as $item ) {
			list( $rel, $offset, $size, $mtime, $chunk ) = $item;
			$path                                        = self::path( $rel );
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
	 * @param array $args items: 相対パスの配列
	 */
	public static function delete( $args ) {
		$deleted = 0;
		foreach ( (array) $args['items'] as $rel ) {
			$path = self::path( $rel );
			if ( is_file( $path ) && unlink( $path ) ) { // phpcs:ignore
				++$deleted;
			}
		}
		return array( 'deleted' => $deleted );
	}
}
