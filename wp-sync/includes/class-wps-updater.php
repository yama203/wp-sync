<?php
/**
 * GitHub の Release から、このプラグイン自身を更新する。
 *
 * プラグインのヘッダーに Update URI（github.com）を書いているので、WordPress は wordpress.org にこのプラグインの
 * 更新を問い合わせず、代わりに update_plugins_github.com のフィルターを呼ぶ（WordPress 5.8 から）。
 * ここで公開リポジトリの最新の Release を読み、添付の wp-sync-{版}.zip を更新のパッケージとして渡す。
 * あとは WordPress の通常の更新（管理画面の「更新」・WP-CLI・Site Hub）でそのまま更新できる。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Updater {

	const REPO      = 'yama203/wp-sync';
	const CACHE     = 'wps_release_latest';
	const CACHE_TTL = 6 * HOUR_IN_SECONDS;

	public static function init() {
		// 開発中のソースを直接マウントしている環境では、更新で消えないよう止める。
		if ( defined( 'WPS_DISABLE_UPDATER' ) && WPS_DISABLE_UPDATER ) {
			return;
		}
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 10, 3 );
		// 「更新」の画面で「もう一度確認する」を押したときは、Release も確認し直す。
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_force_check' ) );
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

		$response = wp_remote_get(
			'https://api.github.com/repos/' . self::REPO . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array(
					'Accept'     => 'application/vnd.github+json',
					'User-Agent' => 'WP-Sync/' . WPS_VERSION,
				),
			)
		);
		$code    = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$release = 200 === $code ? json_decode( wp_remote_retrieve_body( $response ), true ) : null;

		$result = array( 'error' => is_wp_error( $response ) ? $response->get_error_message() : 'GitHub の応答が HTTP ' . $code . ' でした。' );
		if ( is_array( $release ) ) {
			foreach ( (array) ( isset( $release['assets'] ) ? $release['assets'] : array() ) as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && preg_match( '/^wp-sync-(\d[0-9A-Za-z.\-]*)\.zip$/', $asset['name'], $m ) ) {
					$result = array(
						'version'      => $m[1],
						'package'      => $asset['browser_download_url'],
						'url'          => (string) $release['html_url'],
						'name'         => (string) $release['name'],
						'body'         => (string) $release['body'],
						'published_at' => (string) $release['published_at'],
					);
					break;
				}
			}
			if ( empty( $result['version'] ) ) {
				$result = array( 'error' => '最新の Release に wp-sync-{版}.zip がありません。' );
			}
		}

		// 失敗したときは短めに覚えておく（何度も問い合わせないように）。
		set_site_transient( self::CACHE, $result, empty( $result['error'] ) ? self::CACHE_TTL : HOUR_IN_SECONDS );
		return $result;
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
			delete_site_transient( self::CACHE );
		}
	}
}
