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

	/**
	 * DB を取り込むときに、取り込み先のものを残すオプション。DB を読み出すときも、送り元のこれらの行は送らない
	 * （古い版の相手は wps_remotes を残す設定に入っていないので、送ると相手の DB にこのサイトの接続先が入ってしまう）。
	 */
	const KEEP_OPTIONS = array( 'wps_secret', 'wps_allow_pull', 'wps_allow_push', 'wps_remote', 'wps_remotes', 'wps_remote_active' );

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
	 * 保存している接続先（0.6.0 から複数。名前を付けて切り替える）。
	 *
	 * 0.5 までの 1 件だけの接続先（wps_remote）は、初めて読むときに 1 件目として引き継ぐ。
	 * wps_remote には、今使っている接続先を同じ形で入れておく（古い版に戻しても、使っていた接続先でつながるように）。
	 *
	 * @return array<string,array{id:string,name:string,url:string,secret:string,home:string}> ID => 接続先（保存した順）
	 */
	public static function remotes() {
		$remotes = self::get( 'wps_remotes' );
		if ( ! is_array( $remotes ) ) {
			$remotes = array();
			$old     = self::get( 'wps_remote' );
			if ( is_array( $old ) && ! empty( $old['url'] ) && ! empty( $old['secret'] ) ) {
				$id             = self::new_remote_id();
				$remotes[ $id ] = self::remote_entry( $id, '', $old['url'], $old['secret'], isset( $old['home'] ) ? $old['home'] : $old['url'] );
				self::set( 'wps_remotes', $remotes );
				self::set( 'wps_remote_active', $id );
			}
		}
		return array_filter(
			$remotes,
			function ( $remote ) {
				return is_array( $remote ) && ! empty( $remote['url'] ) && ! empty( $remote['secret'] );
			}
		);
	}

	private static function new_remote_id() {
		return strtolower( wp_generate_password( 8, false, false ) );
	}

	private static function remote_entry( $id, $name, $url, $secret, $home ) {
		$home = untrailingslashit( (string) $home );
		return array(
			'id'     => $id,
			// 名前がなければ相手のホスト名にする（あとで「本番」などに変えられる）。
			'name'   => '' !== trim( (string) $name ) ? trim( (string) $name ) : (string) wp_parse_url( $home, PHP_URL_HOST ) . ( wp_parse_url( $home, PHP_URL_PORT ) ? ':' . wp_parse_url( $home, PHP_URL_PORT ) : '' ),
			'url'    => untrailingslashit( (string) $url ),
			'secret' => (string) $secret,
			'home'   => $home,
		);
	}

	/**
	 * 接続先を 1 件返す。
	 *
	 * @param string|null $id ID か名前。null なら今使っている接続先。
	 * @return array{id:string,name:string,url:string,secret:string,home:string}|null
	 */
	public static function remote( $id = null ) {
		$remotes = self::remotes();
		if ( null === $id || '' === $id ) {
			$active = (string) self::get( 'wps_remote_active', '' );
			if ( isset( $remotes[ $active ] ) ) {
				return $remotes[ $active ];
			}
			return $remotes ? reset( $remotes ) : null;
		}
		return self::find_remote( $id );
	}

	/**
	 * ID か名前で接続先を探す（名前は大文字・小文字を区別しない）。
	 */
	public static function find_remote( $key ) {
		$remotes = self::remotes();
		$key     = (string) $key;
		if ( isset( $remotes[ $key ] ) ) {
			return $remotes[ $key ];
		}
		foreach ( $remotes as $remote ) {
			if ( 0 === strcasecmp( $remote['name'], $key ) ) {
				return $remote;
			}
		}
		return null;
	}

	/**
	 * 接続先を足す（同じ名前か、同じ相手（home）があれば置き換える）。足した接続先を使うようにする。
	 *
	 * @return array 保存した接続先。
	 */
	public static function add_remote( $name, $url, $secret, $home ) {
		$remotes = self::remotes();
		$id      = null;
		foreach ( $remotes as $remote ) {
			if ( ( '' !== trim( (string) $name ) && 0 === strcasecmp( $remote['name'], trim( (string) $name ) ) ) || untrailingslashit( (string) $home ) === $remote['home'] ) {
				$id   = $remote['id'];
				$name = '' !== trim( (string) $name ) ? $name : $remote['name'];
				break;
			}
		}
		$id             = $id ? $id : self::new_remote_id();
		$remotes[ $id ] = self::remote_entry( $id, $name, $url, $secret, $home );
		self::set( 'wps_remotes', $remotes );
		self::use_remote( $id );
		return $remotes[ $id ];
	}

	/**
	 * 使う接続先を切り替える。
	 */
	public static function use_remote( $id ) {
		$remote = self::find_remote( $id );
		if ( ! $remote ) {
			throw new Exception( '接続先が見つかりません: ' . $id );
		}
		self::set( 'wps_remote_active', $remote['id'] );
		self::set(
			'wps_remote',
			array(
				'url'    => $remote['url'],
				'secret' => $remote['secret'],
				'home'   => $remote['home'],
			)
		);
		return $remote;
	}

	public static function rename_remote( $id, $name ) {
		$remotes = self::remotes();
		$remote  = self::find_remote( $id );
		$name    = trim( (string) $name );
		if ( ! $remote ) {
			throw new Exception( '接続先が見つかりません: ' . $id );
		}
		if ( '' === $name ) {
			throw new Exception( '名前を入れてください。' );
		}
		foreach ( $remotes as $other ) {
			if ( $other['id'] !== $remote['id'] && 0 === strcasecmp( $other['name'], $name ) ) {
				throw new Exception( '同じ名前の接続先があります: ' . $name );
			}
		}
		$remotes[ $remote['id'] ]['name'] = $name;
		self::set( 'wps_remotes', $remotes );
		return $remotes[ $remote['id'] ];
	}

	/**
	 * 接続先を消す。使っていた接続先を消したら、残りの最初のものを使う。
	 */
	public static function delete_remote( $id ) {
		$remotes = self::remotes();
		$remote  = self::find_remote( $id );
		if ( ! $remote ) {
			throw new Exception( '接続先が見つかりません: ' . $id );
		}
		$active = self::remote();
		unset( $remotes[ $remote['id'] ] );
		self::set( 'wps_remotes', $remotes );
		if ( $active && $active['id'] === $remote['id'] ) {
			if ( $remotes ) {
				self::use_remote( key( $remotes ) );
			} else {
				self::remove( 'wps_remote_active' );
				self::remove( 'wps_remote' );
			}
		}
		return $remote;
	}
}
