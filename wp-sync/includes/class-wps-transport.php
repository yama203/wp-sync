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

/**
 * やり直せるかもしれない通信の失敗。
 */
class WPS_Retry_Exception extends Exception {

	/** @var bool 要求が接続先に届いていない（どの操作でもやり直してよい）。 */
	public $unsent;

	public function __construct( $message, $unsent ) {
		parent::__construct( $message );
		$this->unsent = $unsent;
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

	/** 何度も送ってよい操作（同じ要求をもう一度送っても結果が変わらない）。 */
	const REPEATABLE = array( 'info', 'export_chunk', 'files_list', 'files_get', 'files_put', 'files_delete' );

	/** つながらなかったときに待つ秒数。この回数だけやり直す。 */
	const RETRY_WAITS = array( 3, 10, 20, 30 );

	/**
	 * 接続先の操作を呼ぶ。つながらなかったときは、少し待ってやり直す。
	 *
	 * メディアが多いと要求が何千回にもなり、サーバー（Xserver など）が一時的に接続を受け付けないことがあるため（sanagochi.jp で、
	 * 5256 件中 68 件目で「cURL error 28: Connection timed out after 10003 milliseconds」。0.4.3）。
	 * 要求が届く前に失敗したとき（接続できない・混んでいる）はどの操作もやり直す。届いた後の失敗（応答待ちの時間切れなど）は、
	 * DB の取り込みのように 2 回入ると困る操作ではやり直さない。
	 */
	public function call( $action, $args = array() ) {
		$waits = self::RETRY_WAITS;
		while ( true ) {
			try {
				return $this->send( $action, $args );
			} catch ( WPS_Retry_Exception $e ) {
				if ( ! $waits || ( ! $e->unsent && ! in_array( $action, self::REPEATABLE, true ) ) ) {
					throw new Exception( $e->getMessage() );
				}
				sleep( array_shift( $waits ) );
			}
		}
	}

	private function send( $action, $args ) {
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
					// 本文はもともと gzip なので、サーバーにさらに圧縮しないよう頼む。サーバー（Apache の mod_deflate など）が gzip をかぶせると、
					// WordPress の通信（cURL）が外側を解いたあと中身の gzip まで解いてしまい、署名が合わなくなる（veritas-solve.com で発生。0.3.2）
					'Accept-Encoding' => 'identity',
					'X-WPS-Time'      => $time,
					'X-WPS-Signature' => WPS_Transport::sign( $time, $body, $this->secret ),
				),
				'body'        => $body,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $response->get_error_message();
			// 名前が引けない・接続できない・接続の時間切れ・TLS の接続に失敗は、要求が届いていない。
			$unsent = (bool) preg_match( '/cURL error (6|7|35):|Connection timed out|Failed to connect|Could not resolve|Connection refused/i', $message );
			throw new WPS_Retry_Exception( '接続先につながりませんでした: ' . $message, $unsent );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( in_array( $code, array( 429, 502, 503, 504 ), true ) && ! wp_remote_retrieve_header( $response, 'x-wps-signature' ) ) {
			// 混んでいる・前のサーバーが受け付けなかった（WP Sync まで届いていない）。
			throw new WPS_Retry_Exception( sprintf( '接続先が混んでいて応答しませんでした（HTTP %d）。', $code ), 429 === $code || 503 === $code );
		}
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
