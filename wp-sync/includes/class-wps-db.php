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

		$options     = $dst . 'options';
		$tmp_options = self::TMP . $options;
		$tmp_meta    = self::TMP . $dst . 'usermeta';
		$existing    = self::column( 'SHOW TABLES LIKE ' . self::like( $dst ) );

		// 接頭辞が違うとき、接頭辞の付いたオプション名とユーザーメタのキーを直す（権限が消えないように）。
		if ( $src !== $dst ) {
			if ( in_array( $options, $tables, true ) ) {
				self::query( 'UPDATE ' . self::ident( $tmp_options ) . ' SET option_name = ' . self::quote( $dst . 'user_roles' ) . ' WHERE option_name = ' . self::quote( $src . 'user_roles' ) );
			}
			if ( in_array( $dst . 'usermeta', $tables, true ) ) {
				self::query( 'UPDATE ' . self::ident( $tmp_meta ) . ' SET meta_key = CONCAT(' . self::quote( $dst ) . ', SUBSTRING(meta_key, ' . ( strlen( $src ) + 1 ) . ')) WHERE meta_key LIKE ' . self::like( $src ) );
			}
		}

		if ( in_array( $options, $tables, true ) ) {
			// このプラグインの設定（接続キー・接続先・許可）と、サイトの URL（home・siteurl）は取り込み先のものを残す。
			// URL は置換でも合わせているが、WordPress をフォルダに置いているかどうかが違うと置換では合わないことがある。
			$names = implode( ',', array_map( array( __CLASS__, 'quote' ), array_merge( WPS_Settings::KEEP_OPTIONS, array( 'home', 'siteurl' ), empty( $args['keep_active_plugins'] ) ? array() : array( 'active_plugins' ) ) ) );
			$keep  = array();
			if ( in_array( $options, $existing, true ) ) {
				$keep = self::rows( 'SELECT option_name, option_value, autoload FROM ' . self::ident( $options ) . ' WHERE option_name IN (' . $names . ')' );
			}
			// 送り元の接続キーなどは、取り込み先に同じ行がなくても持ち込まない。home・siteurl は残せるときだけ差し替える。
			$drop = array_merge( WPS_Settings::KEEP_OPTIONS, array( 'wps_backup' ), array_column( $keep, 0 ) );
			self::query( 'DELETE FROM ' . self::ident( $tmp_options ) . ' WHERE option_name IN (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $drop ) ) . ')' );
			foreach ( $keep as $row ) {
				self::query( 'INSERT INTO ' . self::ident( $tmp_options ) . ' (option_name, option_value, autoload) VALUES (' . implode( ',', array_map( array( __CLASS__, 'quote' ), $row ) ) . ')' );
			}

			// 取り込んだ後もこのプラグインが有効なままになるようにする。
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

		// 前回のバックアップは消して、今回の入れ替え前を新しいバックアップにする。
		foreach ( self::column( 'SHOW TABLES LIKE ' . self::like( self::BAK ) ) as $table ) {
			self::query( 'DROP TABLE IF EXISTS ' . self::ident( $table ) );
		}

		$renames = array();
		$backed  = array();
		$created = array();
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

	/**
	 * @return array{time:int,from:string,tables:int}|null
	 */
	public static function backup_info() {
		$tables = self::column( 'SHOW TABLES LIKE ' . self::like( self::BAK ) );
		if ( ! $tables ) {
			return null;
		}
		$info = get_option( 'wps_backup' );
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
		$info     = get_option( 'wps_backup' );
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
		self::query( 'DELETE FROM ' . self::ident( $wpdb->base_prefix . 'options' ) . " WHERE option_name = 'wps_backup'" );
		wp_cache_flush();
		return array( 'tables' => count( $restored ) );
	}
}
