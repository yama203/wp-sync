<?php
/**
 * サイト間の通信。
 *
 * 本文は serialize して gzip した生のバイト列で送る（バイナリの値もそのまま運べる。
 * SQL らしい文字列が見えないので、サーバーの WAF にも止められにくい）。
 * 要求と応答には、送り先の秘密のキーで HMAC の署名を付ける。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Transport {

	/** 署名の時刻のずれをどこまで許すか（秒）。 */
	const MAX_SKEW = 300;

	public static function pack( $value ) {
		return gzencode( serialize( $value ), 6 );
	}

	public static function unpack( $body ) {
		$raw = @gzdecode( $body ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		if ( false === $raw ) {
			throw new Exception( 'データを展開できませんでした。' );
		}
		$value = @unserialize( $raw, array( 'allowed_classes' => false ) ); // phpcs:ignore
		if ( false === $value && 'b:0;' !== $raw ) {
			throw new Exception( 'データを読み取れませんでした。' );
		}
		return $value;
	}

	public static function sign( $time, $body, $secret ) {
		return hash_hmac( 'sha256', $time . "\n" . hash( 'sha256', $body ), $secret );
	}

	public static function verify( $time, $signature, $body, $secret ) {
		if ( ! ctype_digit( (string) $time ) || abs( time() - (int) $time ) > self::MAX_SKEW ) {
			return false;
		}
		return is_string( $signature ) && hash_equals( self::sign( $time, $body, $secret ), $signature );
	}
}

/**
 * 同期の相手。このサイト自身（関数を直接呼ぶ）と接続先（HTTP で呼ぶ）を同じ形で扱う。
 */
abstract class WPS_Endpoint {

	abstract public function call( $action, $args = array() );

	abstract public function is_remote();

	public static function local() {
		return new WPS_Local_Endpoint();
	}

	public static function remote() {
		$remote = WPS_Settings::remote();
		if ( ! $remote ) {
			throw new Exception( '接続先が設定されていません。' );
		}
		return new WPS_Remote_Endpoint( $remote['url'], $remote['secret'] );
	}
}

class WPS_Local_Endpoint extends WPS_Endpoint {

	public function call( $action, $args = array() ) {
		return WPS_Server::dispatch( $action, $args, false );
	}

	public function is_remote() {
		return false;
	}
}

class WPS_Remote_Endpoint extends WPS_Endpoint {

	private $url;
	private $secret;

	public function __construct( $url, $secret ) {
		$this->url    = untrailingslashit( $url );
		$this->secret = $secret;
	}

	public function is_remote() {
		return true;
	}

	public function call( $action, $args = array() ) {
		$body = WPS_Transport::pack(
			array(
				'action' => $action,
				'args'   => $args,
			)
		);
		$time = (string) time();

		$response = wp_remote_post(
			$this->url . '/?wps-api=1',
			array(
				'timeout'     => 180,
				// POST が転送されると GET に変わって中身が消えるので、転送は追わずに知らせる。
				'redirection' => 0,
				'headers'     => array(
					'Content-Type'    => 'application/octet-stream',
					'X-WPS-Time'      => $time,
					'X-WPS-Signature' => WPS_Transport::sign( $time, $body, $this->secret ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			throw new Exception( '接続先につながりませんでした: ' . $response->get_error_message() );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 300 && $code < 400 ) {
			throw new Exception( '接続先の URL が転送されています（' . wp_remote_retrieve_header( $response, 'location' ) . '）。転送先の URL を接続 URL に設定してください。' );
		}

		$raw = wp_remote_retrieve_body( $response );
		$sig = wp_remote_retrieve_header( $response, 'x-wps-signature' );
		$rt  = wp_remote_retrieve_header( $response, 'x-wps-time' );
		if ( ! $sig ) {
			$snippet = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
			throw new Exception( sprintf( '接続先から WP Sync の応答がありませんでした（HTTP %d）。プラグインが有効か、URL が正しいか確認してください。%s', $code, $snippet ? '応答: ' . mb_substr( $snippet, 0, 200 ) : '' ) );
		}
		if ( ! WPS_Transport::verify( $rt, $sig, $raw, $this->secret ) ) {
			throw new Exception( '接続先の応答の署名が合いません。接続キーを貼り直してください。' );
		}

		$result = WPS_Transport::unpack( $raw );
		if ( empty( $result['ok'] ) ) {
			throw new Exception( '接続先でエラー: ' . ( isset( $result['error'] ) ? $result['error'] : '不明なエラー' ) );
		}
		return $result['data'];
	}
}
