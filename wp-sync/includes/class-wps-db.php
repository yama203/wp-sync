<?php
/**
 * データベースの書き出しと取り込み。
 *
 * 取り込みは、まず wpstmp_ で始まる仮のテーブルに入れ、最後に RENAME TABLE で
 * 一度に入れ替える。途中で失敗しても今のサイトはそのまま動く。
 * 入れ替える前のテーブルは wpsbak_ で始まる名前で 1 回分だけ残し、元に戻せるようにする。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_DB {

	const TMP = 'wpstmp_';
	const BAK = 'wpsbak_';
	const OLD = 'wpsold_';

	/** 1 つの INSERT 文の大きさの目安（バイト）。 */
	const INSERT_BYTES = 1048576;

	private static function dbh() {
		global $wpdb;
		return $wpdb->dbh;
	}

	/**
	 * $wpdb->query() は大きな文で文字コードの検査に時間がかかり、% も書き換えるので、mysqli を直接使う。
	 */
	private static function query( $sql, $mode = MYSQLI_STORE_RESULT ) {
		$result = mysqli_query( self::dbh(), $sql, $mode );
		if ( false === $result ) {
			throw new Exception( 'SQL エラー: ' . mysqli_error( self::dbh() ) . '（' . mb_substr( $sql, 0, 150 ) . '）' );
		}
		return $result;
	}

	private static function rows( $sql ) {
		$result = self::query( $sql );
		$rows   = array();
		while ( $row = mysqli_fetch_row( $result ) ) {
			$rows[] = $row;
		}
		mysqli_free_result( $result );
		return $rows;
	}

	private static function column( $sql ) {
		return array_column( self::rows( $sql ), 0 );
	}

	private static function ident( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	private static function quote( $value ) {
		if ( null === $value ) {
			return 'NULL';
		}
		$value = (string) $value;
		// UTF-8 として正しくない値（バイナリ）は 16 進で渡して、文字コードの変換で壊れないようにする。
		if ( '' !== $value && ! preg_match( '//u', $value ) ) {
			return '0x' . bin2hex( $value );
		}
		return "'" . mysqli_real_escape_string( self::dbh(), $value ) . "'";
	}

	private static function like( $prefix ) {
		return "'" . mysqli_real_escape_string( self::dbh(), addcslashes( $prefix, '_%\\' ) ) . "%'";
	}

	private static function session() {
		// 0 の ID や 0000-00-00 の日付をそのまま入れられるよう、厳格モードを外す。
		self::query( "SET SESSION sql_mode = 'NO_AUTO_VALUE_ON_ZERO'" );
		self::query( 'SET SESSION foreign_key_checks = 0' );
	}

	private static function is_own_table( $name ) {
		foreach ( array( self::TMP, self::BAK, self::OLD ) as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 同期するテーブル（このサイトの接頭辞で始まる、ビューでないもの）。
	 *
	 * @return array<int,array{name:string,rows:int,bytes:int}>
	 */
	public static function tables() {
		global $wpdb;
		$result = self::query( 'SHOW TABLE STATUS LIKE ' . self::like( $wpdb->base_prefix ) );
		$tables = array();
		while ( $row = mysqli_fetch_assoc( $result ) ) {
			if ( null === $row['Engine'] || self::is_own_table( $row['Name'] ) ) {
				continue;
			}
			$tables[] = array(
				'name'  => $row['Name'],
				'rows'  => (int) $row['Rows'],
				'bytes' => (int) $row['Data_length'],
			);
		}
		mysqli_free_result( $result );
		return $tables;
	}

	private static function assert_table( $name ) {
		if ( ! in_array( $name, wp_list_pluck( self::tables(), 'name' ), true ) ) {
			throw new Exception( 'テーブルが見つかりません: ' . $name );
		}
	}

	private static function primary_key( $table ) {
		$keys = array();
		foreach ( self::rows( 'SHOW KEYS FROM ' . self::ident( $table ) . " WHERE Key_name = 'PRIMARY'" ) as $row ) {
			$keys[ (int) $row[3] ] = $row[4]; // Seq_in_index => Column_name
		}
		ksort( $keys );
		return array_values( $keys );
	}

	/**
	 * テーブルの行を、決まった大きさまで読み出す。
	 *
	 * 主キーが 1 列なら「前回の最後の値より大きいもの」で続きを読む（大きなテーブルでも遅くならない）。
	 * そうでなければ LIMIT の位置で読む。
	 */
	public static function export_chunk( $args ) {
		$table     = (string) $args['table'];
		$cursor    = isset( $args['cursor'] ) ? $args['cursor'] : null;
		$max_bytes = max( 65536, (int) ( isset( $args['max_bytes'] ) ? $args['max_bytes'] : 2097152 ) );
		self::assert_table( $table );

		$out = array();
		if ( ! $cursor ) {
			$create        = self::rows( 'SHOW CREATE TABLE ' . self::ident( $table ) );
			$out['create'] = $create[0][1];
			$pk            = self::primary_key( $table );
			$cursor        = array(
				'pk'     => $pk,
				'last'   => null,
				'offset' => 0,
			);
		}

		$cols  = self::column( 'SHOW COLUMNS FROM ' . self::ident( $table ) );
		$keyed = 1 === count( $cursor['pk'] );
		$index = $keyed ? array_search( $cursor['pk'][0], $cols, true ) : false;
		$order = $cursor['pk'] ? ' ORDER BY ' . implode( ', ', array_map( array( __CLASS__, 'ident' ), $cursor['pk'] ) ) : '';

		$rows  = array();
		$bytes = 0;
		$done  = false;
		$batch = 500;

		while ( true ) {
			if ( $keyed && false !== $index ) {
				$where = null === $cursor['last'] ? '' : ' WHERE ' . self::ident( $cursor['pk'][0] ) . ' > ' . self::quote( $cursor['last'] );
				$sql   = 'SELECT * FROM ' . self::ident( $table ) . $where . $order . ' LIMIT ' . $batch;
			} else {
				$sql = 'SELECT * FROM ' . self::ident( $table ) . $order . ' LIMIT ' . (int) $cursor['offset'] . ', ' . $batch;
			}

			// 1 行ずつ受け取り、大きさの上限で止める（大きな行が続いてもメモリを使いすぎない）。
			$result  = self::query( $sql, MYSQLI_USE_RESULT );
			$count   = 0;
			$stopped = false;
			while ( $row = mysqli_fetch_row( $result ) ) {
				$rows[] = $row;
				++$count;
				foreach ( $row as $value ) {
					$bytes += null === $value ? 4 : strlen( $value );
				}
				if ( $keyed && false !== $index ) {
					$cursor['last'] = $row[ $index ];
				}
				if ( $bytes >= $max_bytes ) {
					$stopped = true;
					break;
				}
			}
			mysqli_free_result( $result );
			$cursor['offset'] += $count;

			if ( $stopped ) {
				break;
			}
			if ( $count < $batch ) {
				$done = true;
				break;
			}
		}

		$out['cols']   = $cols;
		$out['rows']   = $rows;
		$out['cursor'] = $cursor;
		$out['done']   = $done;
		return $out;
	}

	/**
	 * 取り込みの前に、前回の仮テーブルが残っていれば消す。
	 */
	public static function import_begin() {
		foreach ( self::column( 'SHOW TABLES LIKE ' . self::like( self::TMP ) ) as $table ) {
			self::query( 'DROP TABLE IF EXISTS ' . self::ident( $table ) );
		}
		return true;
	}

	private static function assert_dest_name( $table ) {
		global $wpdb;
		if ( 0 !== strpos( $table, $wpdb->base_prefix ) || ! preg_match( '/^[A-Za-z0-9_$]+$/', $table ) || strlen( self::BAK . $table ) > 64 ) {
			throw new Exception( '取り込めないテーブル名です: ' . $table );
		}
	}

	public static function import_chunk( $args ) {
		$table = (string) $args['table'];
		self::assert_dest_name( $table );
		$tmp = self::TMP . $table;
		self::session();

		if ( ! empty( $args['create'] ) ) {
			self::query( 'DROP TABLE IF EXISTS ' . self::ident( $tmp ) );
			$create = preg_replace( '/^CREATE TABLE `(?:[^`]|``)+`/', 'CREATE TABLE ' . self::ident( $tmp ), $args['create'], 1 );
			try {
				self::query( $create );
			} catch ( Exception $e ) {
				// MySQL 8 と MariaDB の間で、相手にない照合順序を使っているとき。
				$create = preg_replace( '/utf8mb4_(?:0900|uca1400)_\w+/', 'utf8mb4_unicode_520_ci', $create );
				self::query( $create );
			}
		}

		$rows = isset( $args['rows'] ) ? $args['rows'] : array();
		if ( ! $rows ) {
			return array( 'inserted' => 0 );
		}

		$replacer = new WPS_Replacer( isset( $args['pairs'] ) ? $args['pairs'] : array() );
		$head     = 'INSERT INTO ' . self::ident( $tmp ) . ' (' . implode( ', ', array_map( array( __CLASS__, 'ident' ), $args['cols'] ) ) . ') VALUES ';
		$values   = array();
		$size     = 0;
		foreach ( $rows as $row ) {
			$quoted = array();
			foreach ( $row as $value ) {
				$quoted[] = self::quote( $replacer->value( $value ) );
			}
			$tuple    = '(' . implode( ',', $quoted ) . ')';
			$values[] = $tuple;
			$size    += strlen( $tuple );
			if ( $size >= self::INSERT_BYTES ) {
				self::query( $head . implode( ',', $values ) );
				$values = array();
				$size   = 0;
			}
		}
		if ( $values ) {
			self::query( $head . implode( ',', $values ) );
		}
		return array( 'inserted' => count( $rows ) );
	}

	/**
	 * 仮テーブルを本物と入れ替える。
	 *
	 * @param array $args tables: 取り込んだテーブル名（このサイトの名前）、src_prefix: 送り元の接頭辞、src_home: 送り元の URL、
	 *                    keep_active_plugins: 有効化しているプラグインの設定（active_plugins）を取り込み先のものにするか。
	 */
	public static function import_finalize( $args ) {
		global $wpdb;
		$tables = array_values( (array) $args['tables'] );
		$src    = (string) $args['src_prefix'];
		$dst    = $wpdb->base_prefix;
		self::session();

		$tmp_tables = self::column( 'SHOW TABLES LIKE ' . self::like( self::TMP ) );
		foreach ( $tables as $table ) {
			self::assert_dest_name( $table );
			if ( ! in_array( self::TMP . $table, $tmp_tables, true ) ) {
				throw new Exception( '取り込み途中のテーブルが見つかりません: ' . $table );
			}
		}

		$multisite   = is_multisite();
		$main        = $multisite ? (int) get_main_site_id() : 1;
		$options     = self::main_options();
		$tmp_options = self::TMP . $options;
		$tmp_meta    = self::TMP . $dst . 'usermeta';
		$existing    = self::column( 'SHOW TABLES LIKE ' . self::like( $dst ) );
		$keep_active = ! empty( $args['keep_active_plugins'] );

		// 接頭辞が違うとき、接頭辞の付いたオプション名とユーザーメタのキーを直す（権限が消えないように）。
		// マルチサイトでは、サイトごとの options（wp_2_options など）の wp_2_user_roles も直す。
		if ( $src !== $dst ) {
			foreach ( $tables as $table ) {
				if ( preg_match( '/^' . preg_quote( $dst, '/' ) . '(\d+_)?options$/', $table, $m ) ) {
					$blog = isset( $m[1] ) ? $m[1] : '';
					self::query( 'UPDATE ' . self::ident( self::TMP . $table ) . ' SET option_name = ' . self::quote( $dst . $blog . 'user_roles' ) . ' WHERE option_name = ' . self::quote( $src . $blog . 'user_roles' ) );
				}
			}
			if ( in_array( $dst . 'usermeta', $tables, true ) ) {
				self::query( 'UPDATE ' . self::ident( $tmp_meta ) . ' SET meta_key = CONCAT(' . self::quote( $dst ) . ', SUBSTRING(meta_key, ' . ( strlen( $src ) + 1 ) . ')) WHERE meta_key LIKE ' . self::like( $src ) );
			}
		}

		if ( in_array( $options, $tables, true ) ) {
			// このプラグインの設定（接続キー・接続先・許可）と、サイトの URL（home・siteurl）は取り込み先のものを残す。
			// URL は置換でも合わせているが、WordPress をフォルダに置いているかどうかが違うと置換では合わないことがある。
			// 送り元の接続キーなどは、取り込み先に同じ行がなくても持ち込まない。
			self::keep_options( $options, $existing, array_merge( WPS_Settings::KEEP_OPTIONS, array( 'home', 'siteurl' ), $keep_active ? array( 'active_plugins' ) : array() ), array_merge( WPS_Settings::KEEP_OPTIONS, array( 'wps_backup' ) ) );

			// 取り込んだ後もこのプラグインが有効なままになるようにする（マルチサイトでは下でネットワークで有効にする）。
			if ( ! $multisite ) {
				$active  = self::column( 'SELECT option_value FROM ' . self::ident( $tmp_options ) . " WHERE option_name = 'active_plugins'" );
				$plugins = $active ? @unserialize( $active[0], array( 'allowed_classes' => false ) ) : array(); // phpcs:ignore
				$plugins = is_array( $plugins ) ? $plugins : array();
				if ( ! in_array( WPS_BASENAME, $plugins, true ) ) {
					$plugins[] = WPS_BASENAME;
					if ( $active ) {
						self::query( 'UPDATE ' . self::ident( $tmp_options ) . ' SET option_value = ' . self::quote( serialize( array_values( $plugins ) ) ) . " WHERE option_name = 'active_plugins'" );
					} else {
						self::query( 'INSERT INTO ' . self::ident( $tmp_options ) . " (option_name, option_value, autoload) VALUES ('active_plugins', " . self::quote( serialize( $plugins ) ) . ", 'yes')" );
					}
				}
			}
		}

		$orphans = array();
		if ( $multisite ) {
			$orphans = self::finalize_network( $args, $tables, $existing, $main, $keep_active );
		}

		// 前回のバックアップは消して、今回の入れ替え前を新しいバックアップにする。
		foreach ( self::column( 'SHOW TABLES LIKE ' . self::like( self::BAK ) ) as $table ) {
			self::query( 'DROP TABLE IF EXISTS ' . self::ident( $table ) );
		}

		$renames = array();
		$backed  = array();
		$created = array();
		// 送り元にないサイトのテーブルは、入れ替えた後に残っていると同じ ID でサイトを作ったときに混ざるので、バックアップに回す。
		foreach ( $orphans as $table ) {
			$renames[] = self::ident( $table ) . ' TO ' . self::ident( self::BAK . $table );
			$backed[]  = $table;
		}
		foreach ( $tables as $table ) {
			if ( in_array( $table, $existing, true ) ) {
				$renames[] = self::ident( $table ) . ' TO ' . self::ident( self::BAK . $table );
				$backed[]  = $table;
			} else {
				$created[] = $table;
			}
			$renames[] = self::ident( self::TMP . $table ) . ' TO ' . self::ident( $table );
		}
		self::query( 'RENAME TABLE ' . implode( ', ', $renames ) );

		if ( in_array( $options, $tables, true ) ) {
			$info = array(
				'time'    => time(),
				'from'    => isset( $args['src_home'] ) ? (string) $args['src_home'] : '',
				'tables'  => $backed,
				'created' => $created,
			);
			self::query( 'DELETE FROM ' . self::ident( $options ) . " WHERE option_name = 'wps_backup'" );
			self::query( 'INSERT INTO ' . self::ident( $options ) . " (option_name, option_value, autoload) VALUES ('wps_backup', " . self::quote( serialize( $info ) ) . ", 'no')" );
		}

		wp_cache_flush();
		return array(
			'tables'  => count( $tables ),
			'created' => $created,
		);
	}

	/** WP Sync の設定を置く options テーブル（マルチサイトではメインのサイトのもの）。 */
	private static function main_options() {
		global $wpdb;
		$main = is_multisite() ? (int) get_main_site_id() : 1;
		return $wpdb->base_prefix . ( 1 === $main ? '' : $main . '_' ) . 'options';
	}

	/**
	 * 仮テーブルの options のうち $names の行を、今のテーブルのものに差し替える。
	 * $drop の行は、今のテーブルに同じ行がなくても仮テーブルから消す。
	 */
	private static function keep_options( $table, $existing, $names, $drop = array() ) {
		$tmp  = self::TMP . $table;
		$keep = array();
		if ( in_array( $table, $existing, true ) ) {
			$keep = self::rows( 'SELECT option_name, option_value, autoload FROM ' . self::ident( $table ) . ' WHERE option_name IN (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $names ) ) . ')' );
		}
		$drop = array_merge( $drop, array_column( $keep, 0 ) );
		if ( $drop ) {
			self::query( 'DELETE FROM ' . self::ident( $tmp ) . ' WHERE option_name IN (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $drop ) ) . ')' );
		}
		foreach ( $keep as $row ) {
			self::query( 'INSERT INTO ' . self::ident( $tmp ) . ' (option_name, option_value, autoload) VALUES (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $row ) ) . ')' );
		}
	}

	/**
	 * マルチサイトの取り込みで、ネットワークのドメインや各サイトの URL を取り込み先に合わせる。
	 *
	 * @return string[] 送り元にないサイトのテーブル（入れ替えのときにバックアップに回す）。
	 */
	private static function finalize_network( $args, $tables, $existing, $main, $keep_active ) {
		global $wpdb;
		$dst   = $wpdb->base_prefix;
		$sites = isset( $args['sites'] ) ? (array) $args['sites'] : array();
		$ids   = array_map( 'intval', array_column( $sites, 0 ) );

		// サイトのドメインとパス（wp_blogs）。送り元の各サイトが、取り込み先でどこになるかは同期を始めるときに決めてある。
		$blogs = $dst . 'blogs';
		if ( in_array( $blogs, $tables, true ) ) {
			foreach ( $sites as $site ) {
				self::query( 'UPDATE ' . self::ident( self::TMP . $blogs ) . ' SET domain = ' . self::quote( $site[1] ) . ', path = ' . self::quote( $site[2] ) . ' WHERE blog_id = ' . (int) $site[0] );
			}
		}

		// ネットワークのドメインとパス（wp_site）は、wp-config.php の DOMAIN_CURRENT_SITE と合っていないといけないので取り込み先のものを残す。
		$site = $dst . 'site';
		if ( in_array( $site, $tables, true ) && in_array( $site, $existing, true ) ) {
			foreach ( self::rows( 'SELECT id, domain, path FROM ' . self::ident( $site ) ) as $row ) {
				self::query( 'UPDATE ' . self::ident( self::TMP . $site ) . ' SET domain = ' . self::quote( $row[1] ) . ', path = ' . self::quote( $row[2] ) . ' WHERE id = ' . (int) $row[0] );
			}
		}

		$meta = $dst . 'sitemeta';
		if ( in_array( $meta, $tables, true ) ) {
			$keys = array_merge( array( 'siteurl' ), $keep_active ? array( 'active_sitewide_plugins' ) : array() );
			if ( in_array( $meta, $existing, true ) ) {
				foreach ( self::rows( 'SELECT site_id, meta_key, meta_value FROM ' . self::ident( $meta ) . ' WHERE meta_key IN (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $keys ) ) . ')' ) as $row ) {
					self::query( 'DELETE FROM ' . self::ident( self::TMP . $meta ) . ' WHERE site_id = ' . (int) $row[0] . ' AND meta_key = ' . self::quote( $row[1] ) );
					self::query( 'INSERT INTO ' . self::ident( self::TMP . $meta ) . ' (site_id, meta_key, meta_value) VALUES (' . (int) $row[0] . ', ' . self::quote( $row[1] ) . ', ' . self::quote( $row[2] ) . ')' );
				}
			}

			// 取り込んだ後もこのプラグインがネットワークで有効なままになるようにする。
			foreach ( self::rows( 'SELECT meta_id, meta_value FROM ' . self::ident( self::TMP . $meta ) . " WHERE meta_key = 'active_sitewide_plugins'" ) as $row ) {
				$plugins = @unserialize( (string) $row[1], array( 'allowed_classes' => false ) ); // phpcs:ignore
				$plugins = is_array( $plugins ) ? $plugins : array();
				if ( ! isset( $plugins[ WPS_BASENAME ] ) ) {
					$plugins[ WPS_BASENAME ] = time();
					self::query( 'UPDATE ' . self::ident( self::TMP . $meta ) . ' SET meta_value = ' . self::quote( serialize( $plugins ) ) . ' WHERE meta_id = ' . (int) $row[0] );
				}
			}
		}

		// メイン以外のサイトも、取り込み先の同じサイトに入れるときは URL（と有効化しているプラグインの設定）を取り込み先のものにする。
		// 取り込み先で別のサイトが同じ ID を使っていたときは、置換した送り元の値のまま。
		foreach ( $sites as $site ) {
			$id    = (int) $site[0];
			$table = $dst . ( 1 === $id ? '' : $id . '_' ) . 'options';
			if ( $id !== $main && in_array( $table, $tables, true ) ) {
				if ( ! empty( $site[3] ) ) {
					self::keep_options( $table, $existing, array_merge( array( 'home', 'siteurl' ), $keep_active ? array( 'active_plugins' ) : array() ) );
				}
			}
		}

		// 送り元にないサイト（取り込み先にだけあるサイト）のテーブル。
		$orphans = array();
		foreach ( $existing as $table ) {
			if ( ! in_array( $table, $tables, true ) && preg_match( '/^' . preg_quote( $dst, '/' ) . '(\d+)_/', $table, $m ) && ! in_array( (int) $m[1], $ids, true ) && 1 !== (int) $m[1] ) {
				$orphans[] = $table;
			}
		}
		return $orphans;
	}

	/**
	 * @return array{time:int,from:string,tables:int}|null
	 */
	public static function backup_info() {
		$tables = self::column( 'SHOW TABLES LIKE ' . self::like( self::BAK ) );
		if ( ! $tables ) {
			return null;
		}
		$info = WPS_Settings::get( 'wps_backup' );
		return array(
			'time'   => is_array( $info ) && isset( $info['time'] ) ? (int) $info['time'] : 0,
			'from'   => is_array( $info ) && isset( $info['from'] ) ? (string) $info['from'] : '',
			'tables' => count( $tables ),
		);
	}

	/**
	 * 直前の取り込みの前の状態に戻す。
	 */
	public static function restore() {
		global $wpdb;
		$backups = self::column( 'SHOW TABLES LIKE ' . self::like( self::BAK ) );
		if ( ! $backups ) {
			throw new Exception( '戻せるバックアップがありません。' );
		}
		$info     = WPS_Settings::get( 'wps_backup' );
		$created  = is_array( $info ) && ! empty( $info['created'] ) ? (array) $info['created'] : array();
		$existing = self::column( 'SHOW TABLES LIKE ' . self::like( $wpdb->base_prefix ) );
		self::session();

		$renames  = array();
		$restored = array();
		foreach ( $backups as $backup ) {
			$table = substr( $backup, strlen( self::BAK ) );
			self::assert_dest_name( $table );
			if ( in_array( $table, $existing, true ) ) {
				self::query( 'DROP TABLE IF EXISTS ' . self::ident( self::OLD . $table ) );
				$renames[] = self::ident( $table ) . ' TO ' . self::ident( self::OLD . $table );
			}
			$renames[]  = self::ident( $backup ) . ' TO ' . self::ident( $table );
			$restored[] = $table;
		}
		self::query( 'RENAME TABLE ' . implode( ', ', $renames ) );

		foreach ( $restored as $table ) {
			self::query( 'DROP TABLE IF EXISTS ' . self::ident( self::OLD . $table ) );
		}
		// 取り込みで新しくできたテーブルは、取り込む前にはなかったので消す。
		foreach ( $created as $table ) {
			if ( ! in_array( $table, $restored, true ) && in_array( $table, $existing, true ) ) {
				self::assert_dest_name( $table );
				self::query( 'DROP TABLE IF EXISTS ' . self::ident( $table ) );
			}
		}
		self::query( 'DELETE FROM ' . self::ident( self::main_options() ) . " WHERE option_name = 'wps_backup'" );
		wp_cache_flush();
		return array( 'tables' => count( $restored ) );
	}
}
