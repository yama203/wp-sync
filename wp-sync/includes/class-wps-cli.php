<?php
/**
 * WP-CLI のコマンド（wp sync ...）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_CLI {

	/**
	 * このサイトの接続キーを表示する。
	 *
	 * @subcommand key
	 */
	public function key() {
		WP_CLI::line( WPS_Settings::connection_key() );
	}

	/**
	 * 接続先を設定する。
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : 接続先の接続キー（wps1: で始まる文字列）。
	 *
	 * [--connect-url=<url>]
	 * : 接続キーの URL とは別の URL でつなぐとき（--url は WP-CLI 自身が使うので別の名前にしている）。
	 */
	public function connect( $args, $assoc ) {
		try {
			$parsed   = WPS_Settings::parse_connection_key( $args[0] );
			$url      = isset( $assoc['connect-url'] ) ? untrailingslashit( $assoc['connect-url'] ) : $parsed['url'];
			$endpoint = new WPS_Remote_Endpoint( $url, $parsed['secret'] );
			$info     = $endpoint->call( 'info' );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WPS_Settings::save_remote(
			array(
				'url'    => $url,
				'secret' => $parsed['secret'],
				'home'   => $info['home'],
			)
		);
		WP_CLI::success( sprintf( '%s（%s）につなぎました。pull: %s / push: %s', $info['name'], $info['home'], $info['allow_pull'] ? '可' : '不可', $info['allow_push'] ? '可' : '不可' ) );
	}

	/**
	 * pull / push を許可するかを設定する。
	 *
	 * ## OPTIONS
	 *
	 * [--pull=<yes|no>]
	 * : 相手がこのサイトから pull するのを許可するか。
	 *
	 * [--push=<yes|no>]
	 * : 相手がこのサイトへ push するのを許可するか。
	 */
	public function allow( $args, $assoc ) {
		$pull = isset( $assoc['pull'] ) ? 'yes' === $assoc['pull'] : WPS_Settings::allow_pull();
		$push = isset( $assoc['push'] ) ? 'yes' === $assoc['push'] : WPS_Settings::allow_push();
		WPS_Settings::save_permissions( $pull, $push );
		WP_CLI::success( sprintf( 'pull: %s / push: %s', $pull ? '許可' : '不許可', $push ? '許可' : '不許可' ) );
	}

	/**
	 * 接続先からこのサイトへ取り込む。
	 *
	 * ## OPTIONS
	 *
	 * [--[no-]db]
	 * : DB を取り込むか（--no-db で取り込まない）。
	 *
	 * [--[no-]media]
	 * : メディアを取り込むか（--no-media で取り込まない）。
	 *
	 * [--delete-media]
	 * : 送り元にないメディアを消す。
	 *
	 * [--[no-]keep-active-plugins]
	 * : 有効化しているプラグインの設定を送らず、送り先のものを残すか（初期値は残す。--no-keep-active-plugins で送る）。
	 *
	 * [--replace=<pair>]
	 * : 置換を足す。「前=>後」の形で書く（例: --replace='https://cdn.example.com=>http://localhost:10837'）。
	 *
	 * [--yes]
	 * : 確認しない。
	 */
	public function pull( $args, $assoc ) {
		$this->run( 'pull', $assoc );
	}

	/**
	 * このサイトから接続先へ送る。
	 *
	 * ## OPTIONS
	 *
	 * [--[no-]db]
	 * : DB を送るか（--no-db で送らない）。
	 *
	 * [--[no-]media]
	 * : メディアを送るか（--no-media で送らない）。
	 *
	 * [--delete-media]
	 * : 送り元にないメディアを消す。
	 *
	 * [--[no-]keep-active-plugins]
	 * : 有効化しているプラグインの設定を送らず、送り先のものを残すか（初期値は残す。--no-keep-active-plugins で送る）。
	 *
	 * [--replace=<pair>]
	 * : 置換を足す。
	 *
	 * [--yes]
	 * : 確認しない。
	 */
	public function push( $args, $assoc ) {
		$this->run( 'push', $assoc );
	}

	/**
	 * 直前に DB を取り込む前の状態に戻す。
	 *
	 * ## OPTIONS
	 *
	 * [--remote]
	 * : 接続先の DB を戻す。
	 *
	 * [--yes]
	 * : 確認しない。
	 */
	public function restore( $args, $assoc ) {
		$remote = ! empty( $assoc['remote'] );
		WP_CLI::confirm( ( $remote ? '接続先' : 'このサイト' ) . 'の DB を戻します。よろしいですか？', $assoc );
		try {
			$result = $remote ? WPS_Endpoint::remote()->call( 'restore' ) : WPS_DB::restore();
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( $result['tables'] . ' 個のテーブルを戻しました。' );
	}

	private function run( $direction, $assoc ) {
		$pairs = array();
		if ( isset( $assoc['replace'] ) ) {
			foreach ( (array) $assoc['replace'] as $replace ) {
				$parts = explode( '=>', $replace, 2 );
				if ( 2 === count( $parts ) ) {
					$pairs[] = $parts;
				}
			}
		}
		$db    = WP_CLI\Utils\get_flag_value( $assoc, 'db', true );
		$media = WP_CLI\Utils\get_flag_value( $assoc, 'media', true );

		$remote = WPS_Settings::remote();
		if ( ! $remote ) {
			WP_CLI::error( '接続先が設定されていません（wp sync connect <key>）。' );
		}
		$target = 'pull' === $direction ? 'このサイト' : '接続先（' . $remote['home'] . '）';
		WP_CLI::confirm( $target . 'の' . implode( 'と', array_filter( array( $db ? 'DB' : '', $media ? 'メディア' : '' ) ) ) . 'を上書きします。よろしいですか？', $assoc );

		try {
			$status = WPS_Job::start(
				array(
					'direction'    => $direction,
					'db'           => $db,
					'media'        => $media,
					'media_delete' => ! empty( $assoc['delete-media'] ),
					'pairs'        => $pairs,
					'keep_active_plugins' => WP_CLI\Utils\get_flag_value( $assoc, 'keep-active-plugins', true ),
				)
			);
			$this->show( $status );
			while ( ! $status['done'] ) {
				$status = WPS_Job::step_by_id( $status['id'] );
				$this->show( $status );
			}
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( '完了しました。' );
	}

	private function show( $status ) {
		foreach ( $status['log'] as $line ) {
			WP_CLI::line( $line );
		}
		if ( ! $status['done'] && $status['detail'] ) {
			WP_CLI::line( sprintf( '  %s %d%% %s', $status['label'], $status['percent'], $status['detail'] ) );
		}
	}
}
