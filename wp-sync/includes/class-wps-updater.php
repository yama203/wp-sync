<?php
/**
 * GitHub の Release から、このプラグイン自身を更新する。
 *
 * プラグインのヘッダーに Update URI（github.com）を書いているので、WordPress は wordpress.org にこのプラグインの
 * 更新を問い合わせず、代わりに update_plugins_github.com のフィルターを呼ぶ（WordPress 5.8 から）。
 * ここで公開リポジトリの最新の Release を読み、添付の wp-sync-{版}.zip を更新のパッケージとして渡す。
 *
 * 最新の版は、Release に添付した wp-sync.json（releases/latest/download/ の URL）から読む。
 * GitHub の API は、認証なしだと IP ごとに 1 時間 60 回までで、共用サーバーでは同じ IP のほかの利用者と
 * 回数を分け合うため、すぐに上限に当たって更新が出なくなる。wp-sync.json がないときだけ API を使う。
 * あとは WordPress の通常の更新（管理画面の「更新」・WP-CLI・Site Hub）でそのまま更新できる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Updater {

	const REPO      = 'yama203/wp-sync';
	const CACHE     = 'wps_release_latest';
	const CACHE_TTL = 3 * HOUR_IN_SECONDS;

	public static function init() {
		// 開発中のソースを直接マウントしている環境では、更新で消えないよう止める。
		if ( defined( 'WPS_DISABLE_UPDATER' ) && WPS_DISABLE_UPDATER ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		// 「更新」の画面で「もう一度確認する」を押したときは、Release も確認し直す。
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );
		// WordPress の更新情報が消されたとき（Site Hub の「今すぐ取得」などで確認し直すとき）は、Release の覚えも消す。
		add_action( 'delete_site_transient_update_plugins', array( __CLASS__, 'clear' ) );
	}

	/**
	 * 最新の Release。
	 *
	 * @return array{version:string,package:string,url:string,name:string,body:string,published_at:string}|array{error:string}
	 */
	public static function latest( $force = false ) {
		$cached = get_site_transient( self::CACHE );
		if ( ! $force && is_array( $cached ) ) {
			return $cached;
		}

		$result = self::from_manifest();
		if ( ! empty( $result['error'] ) ) {
			$api = self::from_api();
			if ( empty( $api['error'] ) ) {
				$result = $api;
			} else {
				$result['error'] .= ' / ' . $api['error'];
			}
		}

		// 失敗したときは短めに覚えておく（何度も問い合わせないように）。
		set_site_transient( self::CACHE, $result, empty( $result['error'] ) ? self::CACHE_TTL : 15 * MINUTE_IN_SECONDS );
		return $result;
	}

	public static function clear() {
		delete_site_transient( self::CACHE );
	}

	private static function get( $url, $accept ) {
		return wp_remote_get(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => $accept,
					'User-Agent' => 'WP-Sync/' . WPS_VERSION,
				),
			)
		);
	}

	private static function error( $response ) {
		return array( 'error' => is_wp_error( $response ) ? $response->get_error_message() : 'GitHub の応答が HTTP ' . wp_remote_retrieve_response_code( $response ) . ' でした。' );
	}

	/**
	 * Release に添付した wp-sync.json から読む（API の回数の上限がない）。
	 */
	private static function from_manifest() {
		$response = self::get( 'https://github.com/' . self::REPO . '/releases/latest/download/wp-sync.json', 'application/json' );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::error( $response );
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $data ) || empty( $data['version'] ) || empty( $data['package'] ) || ! preg_match( '/^\d[0-9A-Za-z.\-]*$/', (string) $data['version'] )
			|| 0 !== strpos( (string) $data['package'], 'https://github.com/' . self::REPO . '/releases/download/' ) ) {
			return array( 'error' => 'wp-sync.json の中身が正しくありません。' );
		}
		return array(
			'version'      => (string) $data['version'],
			'package'      => (string) $data['package'],
			'url'          => isset( $data['url'] ) ? (string) $data['url'] : 'https://github.com/' . self::REPO . '/releases',
			'name'         => isset( $data['name'] ) ? (string) $data['name'] : 'WP Sync ' . $data['version'],
			'body'         => isset( $data['body'] ) ? (string) $data['body'] : '',
			'published_at' => isset( $data['published_at'] ) ? (string) $data['published_at'] : '',
		);
	}

	/**
	 * GitHub の API から読む（wp-sync.json を付けていない古い Release のため）。
	 */
	private static function from_api() {
		$response = self::get( 'https://api.github.com/repos/' . self::REPO . '/releases/latest', 'application/vnd.github+json' );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return self::error( $response );
		}
		$release = json_decode( wp_remote_retrieve_body( $response ), true );
		foreach ( (array) ( isset( $release['assets'] ) ? $release['assets'] : array() ) as $asset ) {
			if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/^wp-sync-(\d[0-9A-Za-z.\-]*)\.zip$/', $asset['name'], $m ) ) {
				return array(
					'version'      => $m[1],
					'package'      => $asset['browser_download_url'],
					'url'          => (string) $release['html_url'],
					'name'         => (string) $release['name'],
					'body'         => (string) $release['body'],
					'published_at' => (string) $release['published_at'],
				);
			}
		}
		return array( 'error' => '最新の Release に wp-sync-{版}.zip がありません。' );
	}

	/**
	 * WordPress が更新を確認するときに呼ばれる。
	 *
	 * @param array|false $update      ほかのフィルターが返したもの。
	 * @param array       $plugin_data プラグインのヘッダー。
	 * @param string      $plugin_file プラグインのファイル（wp-sync/wp-sync.php）。
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( WPS_BASENAME !== $plugin_file ) {
			return $update;
		}
		$latest = self::latest();
		if ( empty( $latest['version'] ) ) {
			return $update;
		}
		// 新しくなければ「更新なし」として返す（WordPress がバージョンを比べる）。
		return array(
			'id'           => 'github.com/' . self::REPO,
			'slug'         => 'wp-sync',
			'version'      => $latest['version'],
			'url'          => $latest['url'],
			'package'      => $latest['package'],
			'requires_php' => '7.4',
		);
	}

	/**
	 * プラグイン一覧の「詳細を表示」で、Release の内容を出す。
	 */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || ! isset( $args->slug ) || 'wp-sync' !== $args->slug ) {
			return $result;
		}
		$latest = self::latest();
		if ( empty( $latest['version'] ) ) {
			return $result;
		}
		return (object) array(
			'name'          => 'WP Sync',
			'slug'          => 'wp-sync',
			'version'       => $latest['version'],
			'author'        => 'yama',
			'homepage'      => 'https://github.com/' . self::REPO,
			'last_updated'  => $latest['published_at'],
			'download_link' => $latest['package'],
			'sections'      => array(
				'changelog' => '<h4>' . esc_html( $latest['name'] ) . '</h4><pre style="white-space:pre-wrap">' . esc_html( $latest['body'] ) . '</pre>',
			),
		);
	}

	public static function maybe_force_check() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			self::clear();
		}
	}
}
