<?php
/**
 * 接続先からの要求を受ける窓口（/?wps-api=1）。
 *
 * /wp-json/ を使わないのは、Xserver の「国外IPアクセス制限」などで REST API が止められていても動くようにするため。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Auth_Exception extends Exception {
}

class WPS_Server {

	/**
	 * 受け付ける操作と、必要な許可。
	 * pull: このサイトから読み出す / push: このサイトに書き込む / any: 許可の設定によらない / either: どちらかが許可されていればよい
	 */
	const ACTIONS = array(
		'info'            => 'any',
		'export_chunk'    => 'pull',
		'files_list'      => 'either',
		'files_get'       => 'pull',
		'import_begin'    => 'push',
		'import_chunk'    => 'push',
		'import_finalize' => 'push',
		'files_put'       => 'push',
		'files_delete'    => 'push',
		'code_begin'      => 'push',
		'code_commit'     => 'push',
		'restore'         => 'push',
	);

	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_handle' ), 1 );
	}

	public static function maybe_handle() {
		if ( ! isset( $_GET['wps-api'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}

		nocache_headers();
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		// 警告などが本文に混ざると署名が合わなくなるので、画面には出さず、出たものは捨てる。
		@ini_set( 'display_errors', '0' ); // phpcs:ignore
		ob_start();

		$status = 200;
		try {
			if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? $_SERVER['REQUEST_METHOD'] : '' ) ) {
				throw new WPS_Auth_Exception( 'POST で送ってください。' );
			}
			$body = (string) file_get_contents( 'php://input' );
			$time = isset( $_SERVER['HTTP_X_WPS_TIME'] ) ? (string) $_SERVER['HTTP_X_WPS_TIME'] : '';
			$sig  = isset( $_SERVER['HTTP_X_WPS_SIGNATURE'] ) ? (string) $_SERVER['HTTP_X_WPS_SIGNATURE'] : '';
			if ( ! WPS_Transport::verify( $time, $sig, $body, WPS_Settings::secret() ) ) {
				throw new WPS_Auth_Exception( '接続キーが合わないか、時刻が大きくずれています。接続キーを貼り直してください。' );
			}
			$request = WPS_Transport::unpack( $body );
			$action  = isset( $request['action'] ) ? (string) $request['action'] : '';
			$args    = isset( $request['args'] ) && is_array( $request['args'] ) ? $request['args'] : array();

			$result = array(
				'ok'   => true,
				'data' => self::dispatch( $action, $args, true ),
			);
		} catch ( WPS_Auth_Exception $e ) {
			$status = 403;
			$result = array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		} catch ( Throwable $e ) {
			$result = array(
				'ok'    => false,
				'error' => $e->getMessage(),
			);
		}

		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$out = WPS_Transport::pack( $result );
		$now = (string) time();
		status_header( $status );
		header( 'Content-Type: application/octet-stream' );
		// 途中で圧縮・変換しないでもらう（本文はもともと gzip。サーバーが gzip をかぶせると、受け取る側で二重に解かれて署名が合わなくなる。0.3.2）
		header( 'Cache-Control: no-store, no-transform' );
		header( 'X-WPS-Time: ' . $now );
		header( 'X-WPS-Signature: ' . WPS_Transport::sign( $now, $out, WPS_Settings::secret() ) );
		header( 'Content-Length: ' . strlen( $out ) );
		echo $out; // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * @param bool $remote 接続先から呼ばれたか（このサイトの画面から呼ぶときは許可の設定を見ない）。
	 */
	public static function dispatch( $action, $args, $remote ) {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			throw new Exception( '知らない操作です: ' . $action );
		}
		WPS_Settings::assert_main_site();
		if ( $remote ) {
			$need = self::ACTIONS[ $action ];
			$ok   = 'any' === $need
				|| ( 'pull' === $need && WPS_Settings::allow_pull() )
				|| ( 'push' === $need && WPS_Settings::allow_push() )
				|| ( 'either' === $need && ( WPS_Settings::allow_pull() || WPS_Settings::allow_push() ) );
			if ( ! $ok ) {
				throw new Exception( 'pull' === $need
					? 'このサイトからの pull は許可されていません（WP Sync の設定で許可してください）。'
					: 'このサイトへの push は許可されていません（WP Sync の設定で許可してください）。' );
			}
			if ( function_exists( 'set_time_limit' ) ) {
				@set_time_limit( 300 ); // phpcs:ignore
			}
			ignore_user_abort( true );
			wp_raise_memory_limit( 'admin' );
		}

		switch ( $action ) {
			case 'info':
				return self::info();
			case 'export_chunk':
				return WPS_DB::export_chunk( $args );
			case 'import_begin':
				return WPS_DB::import_begin();
			case 'import_chunk':
				return WPS_DB::import_chunk( $args );
			case 'import_finalize':
				return WPS_DB::import_finalize( $args );
			case 'restore':
				return WPS_DB::restore();
			case 'files_list':
				return WPS_Files::list_files( $args );
			case 'files_get':
				return WPS_Files::get( $args );
			case 'files_put':
				return WPS_Files::put( $args );
			case 'files_delete':
				return WPS_Files::delete( $args );
			case 'code_begin':
				return WPS_Files::code_begin( $args );
			case 'code_commit':
				return WPS_Files::code_commit( $args );
		}
	}

	public static function info() {
		global $wpdb;
		$uploads = wp_upload_dir( null, false );
		return array(
			'api'         => WPS_API_VERSION,
			'version'     => WPS_VERSION,
			'name'        => get_option( 'blogname' ),
			// フィルターを通さない DB の値を使う（置換するのは DB の中身なので）。
			'home'        => untrailingslashit( get_option( 'home' ) ),
			'siteurl'     => untrailingslashit( get_option( 'siteurl' ) ),
			'abspath'     => untrailingslashit( wp_normalize_path( ABSPATH ) ),
			'content_url' => untrailingslashit( WP_CONTENT_URL ),
			'content_dir' => untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ),
			'uploads_dir' => WPS_Files::base(),
			'uploads_url' => untrailingslashit( $uploads['baseurl'] ),
			'prefix'      => $wpdb->base_prefix,
			'multisite'   => is_multisite(),
			'network'     => self::network(),
			'tables'      => WPS_DB::tables(),
			'code'        => WPS_Files::code_items(),
			'allow_pull'  => WPS_Settings::allow_pull(),
			'allow_push'  => WPS_Settings::allow_push(),
			'backup'      => WPS_DB::backup_info(),
			'wp'          => get_bloginfo( 'version' ),
			'php'         => PHP_VERSION,
			'db'          => $wpdb->db_server_info(),
			'post_max'    => wp_convert_hr_to_bytes( (string) ini_get( 'post_max_size' ) ),
		);
	}

	/**
	 * マルチサイトのネットワークとサイトの一覧（シングルサイトでは null）。
	 *
	 * @return array{subdomain:bool,domain:string,path:string,main:int,sites:array}|null
	 */
	private static function network() {
		if ( ! is_multisite() ) {
			return null;
		}
		$network = get_network();
		$sites   = array();
		foreach ( get_sites( array( 'number' => 0, 'network_id' => $network->id ) ) as $site ) {
			$id      = (int) $site->blog_id;
			$sites[] = array(
				'id'      => $id,
				'domain'  => $site->domain,
				'path'    => $site->path,
				'name'    => get_blog_option( $id, 'blogname' ),
				'home'    => untrailingslashit( get_blog_option( $id, 'home' ) ),
				'siteurl' => untrailingslashit( get_blog_option( $id, 'siteurl' ) ),
			);
		}
		return array(
			'subdomain' => is_subdomain_install(),
			'domain'    => $network->domain,
			'path'      => $network->path,
			'main'      => (int) get_main_site_id(),
			'sites'     => $sites,
		);
	}
}
