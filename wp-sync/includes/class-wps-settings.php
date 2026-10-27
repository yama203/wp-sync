<?php
/**
 * 設定の保存先。
 *
 * DB を取り込むときは KEEP_OPTIONS の行を取り込み先のものに戻すので、
 * 接続キーや接続先は同期で上書きされない。
 * マルチサイトでは、ネットワークに 1 つの設定としてメインのサイトのオプションに置く。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Settings {

	const KEY_PREFIX = 'wps1:';

	/** DB を取り込むときに、取り込み先のものを残すオプション。 */
	const KEEP_OPTIONS = array( 'wps_secret', 'wps_allow_pull', 'wps_allow_push', 'wps_remote' );

	/** 画面を開き、同期できる権限。 */
	public static function capability() {
		return is_multisite() ? 'manage_network_options' : 'manage_options';
	}

	/**
	 * マルチサイトでは、同期はメインのサイトから行う（サブサイトの URL で呼ばれたら断る）。
	 */
	public static function assert_main_site() {
		if ( is_multisite() && ! is_main_site() ) {
			throw new Exception( 'マルチサイトでは、メインのサイト（' . untrailingslashit( get_home_url( get_main_site_id() ) ) . '）から同期してください。' );
		}
	}

	public static function get( $name, $default = false ) {
		return is_multisite() ? get_blog_option( get_main_site_id(), $name, $default ) : get_option( $name, $default );
	}

	private static function set( $name, $value ) {
		if ( is_multisite() ) {
			switch_to_blog( get_main_site_id() );
			update_option( $name, $value, false );
			restore_current_blog();
			return;
		}
		update_option( $name, $value, false );
	}

	private static function remove( $name ) {
		return is_multisite() ? delete_blog_option( get_main_site_id(), $name ) : delete_option( $name );
	}

	/** このサイトの URL（マルチサイトではメインのサイトの URL）。 */
	public static function home() {
		return untrailingslashit( self::get( 'home' ) );
	}

	/**
	 * このサイトの秘密のキー。なければ作る。
	 */
	public static function secret() {
		$secret = self::get( 'wps_secret' );
		if ( ! is_string( $secret ) || strlen( $secret ) < 32 ) {
			$secret = self::regenerate_secret();
		}
		return $secret;
	}

	public static function regenerate_secret() {
		$secret = wp_generate_password( 48, false, false );
		self::set( 'wps_secret', $secret );
		return $secret;
	}

	/**
	 * 接続先に貼り付けてもらう文字列（URL と秘密のキーを 1 つにまとめたもの）。
	 */
	public static function connection_key() {
		$json = wp_json_encode(
			array(
				'u' => self::home(),
				'k' => self::secret(),
			)
		);
		return self::KEY_PREFIX . rtrim( strtr( base64_encode( $json ), '+/', '-_' ), '=' );
	}

	/**
	 * @return array{url:string,secret:string}
	 */
	public static function parse_connection_key( $key ) {
		$key = trim( (string) $key );
		if ( 0 !== strpos( $key, self::KEY_PREFIX ) ) {
			throw new Exception( '接続キーの形式が正しくありません（wps1: で始まる文字列を貼り付けてください）。' );
		}
		$json = base64_decode( strtr( substr( $key, strlen( self::KEY_PREFIX ) ), '-_', '+/' ) );
		$data = json_decode( (string) $json, true );
		if ( empty( $data['u'] ) || empty( $data['k'] ) ) {
			throw new Exception( '接続キーを読み取れませんでした。途中で切れていないか確認してください。' );
		}
		return array(
			'url'    => untrailingslashit( $data['u'] ),
			'secret' => $data['k'],
		);
	}

	/** 接続先がこのサイトから pull するのを許すか。 */
	public static function allow_pull() {
		return '1' === self::get( 'wps_allow_pull', '1' );
	}

	/** 接続先がこのサイトへ push するのを許すか（DB とメディアを上書きされる）。 */
	public static function allow_push() {
		return '1' === self::get( 'wps_allow_push', '0' );
	}

	public static function save_permissions( $pull, $push ) {
		self::set( 'wps_allow_pull', $pull ? '1' : '0' );
		self::set( 'wps_allow_push', $push ? '1' : '0' );
	}

	/**
	 * @return array{url:string,secret:string,home:string}|null
	 */
	public static function remote() {
		$remote = self::get( 'wps_remote' );
		return ( is_array( $remote ) && ! empty( $remote['url'] ) && ! empty( $remote['secret'] ) ) ? $remote : null;
	}

	public static function save_remote( $remote ) {
		if ( null === $remote ) {
			self::remove( 'wps_remote' );
			return;
		}
		self::set( 'wps_remote', $remote );
	}
}
