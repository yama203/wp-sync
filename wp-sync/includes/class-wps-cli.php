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
	 * 接続先を足す（同じ名前か同じ相手があれば置き換える）。足した接続先を使うようにする。
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : 接続先の接続キー（wps1: で始まる文字列）。
	 *
	 * [--name=<name>]
	 * : 接続先の名前（例: 本番、ステージング）。省くと相手のホスト名。
	 *
	 * [--connect-url=<url>]
	 * : 接続キーの URL とは別の URL でつなぐとき（--url は WP-CLI 自身が使うので別の名前にしている）。
	 *
	 * ## EXAMPLES
	 *
	 *     wp sync connect wps1:... --name=ステージング
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
		$remote = WPS_Settings::add_remote( isset( $assoc['name'] ) ? $assoc['name'] : '', $url, $parsed['secret'], $info['home'] );
		WP_CLI::success( sprintf( '接続先「%s」として %s（%s）につなぎました。pull: %s / push: %s', $remote['name'], $info['name'], $info['home'], $info['allow_pull'] ? '可' : '不可', $info['allow_push'] ? '可' : '不可' ) );
	}

	/**
	 * 保存している接続先を一覧する。* が今使っている接続先。
	 *
	 * @subcommand remotes
	 */
	public function remotes() {
		$active = WPS_Settings::remote();
		$rows   = array();
		foreach ( WPS_Settings::remotes() as $remote ) {
			$rows[] = array(
				'使用中' => $active && $active['id'] === $remote['id'] ? '*' : '',
				'名前'   => $remote['name'],
				'URL'    => $remote['home'],
				'接続 URL' => $remote['url'],
			);
		}
		if ( ! $rows ) {
			WP_CLI::line( '接続先はまだありません（wp sync connect <key> --name=<名前>）。' );
			return;
		}
		WP_CLI\Utils\format_items( 'table', $rows, array( '使用中', '名前', 'URL', '接続 URL' ) );
	}

	/**
	 * 使う接続先を切り替える（画面の「接続先」の選択と同じ）。
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : 接続先の名前。
	 */
	public function use( $args ) {
		try {
			$remote = WPS_Settings::use_remote( $args[0] );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( '接続先「%s」（%s）を使います。', $remote['name'], $remote['home'] ) );
	}

	/**
	 * 接続先の名前を変える。
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : 今の名前。
	 *
	 * <new-name>
	 * : 新しい名前。
	 */
	public function rename( $args ) {
		try {
			$remote = WPS_Settings::rename_remote( $args[0], $args[1] );
		} catch ( Exception $e ) {
			WP_CLI::error( $e->getMessage() );
		}
		WP_CLI::success( sprintf( '名前を「%s」にしました。', $remote['name'] ) );
	}

	/**
	 * 接続先を消す。
	 *
	 * ## OPTIONS
	 *
	 * [<name>]
	 * : 消す接続先の名前。省くと今使っている接続先。
	 *
	 * [--yes]
	 * : 確認しない。
	 */
	public function disconnect( $args, $assoc ) {
		$remote = WPS_Settings::remote( isset( $args[0] ) ? $args[0] : null );
		if ( ! $remote ) {
			WP_CLI::error( isset( $args[0] ) ? '接続先が見つかりません: ' . $args[0] : '接続先が設定されていません。' );
		}
		WP_CLI::confirm( sprintf( '接続先「%s」（%s）を消します。よろしいですか？', $remote['name'], $remote['home'] ), $assoc );
		WPS_Settings::delete_remote( $remote['id'] );
		WP_CLI::success( sprintf( '接続先「%s」を消しました。', $remote['name'] ) );
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
	 * [--themes=<names>]
	 * : 送るテーマのフォルダ名（カンマ区切り）。all ですべて。
	 *
	 * [--plugins=<names>]
	 * : 送るプラグインのフォルダ名（カンマ区切り）。all ですべて。
	 *
	 * [--[no-]keep-active-plugins]
	 * : 有効化しているプラグインの設定を送らず、送り先のものを残すか（初期値は残す。--no-keep-active-plugins で送る）。
	 *
	 * [--replace=<pair>]
	 * : 置換を足す。「前=>後」の形で書く（例: --replace='https://cdn.example.com=>http://localhost:10837'）。
	 *
	 * [--remote=<name>]
	 * : この回だけ使う接続先の名前（省くと今使っている接続先。wp sync remotes で一覧）。
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
	 * [--themes=<names>]
	 * : 送るテーマのフォルダ名（カンマ区切り）。all ですべて。
	 *
	 * [--plugins=<names>]
	 * : 送るプラグインのフォルダ名（カンマ区切り）。all ですべて。
	 *
	 * [--[no-]keep-active-plugins]
	 * : 有効化しているプラグインの設定を送らず、送り先のものを残すか（初期値は残す。--no-keep-active-plugins で送る）。
	 *
	 * [--replace=<pair>]
	 * : 置換を足す。
	 *
	 * [--remote=<name>]
	 * : この回だけ使う接続先の名前（省くと今使っている接続先）。
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
	 * [--remote[=<name>]]
	 * : 接続先の DB を戻す。名前を付けると、その接続先の DB を戻す（付けなければ今使っている接続先）。
	 *
	 * [--yes]
	 * : 確認しない。
	 */
	public function restore( $args, $assoc ) {
		$remote = ! empty( $assoc['remote'] );
		$preset = null;
		if ( $remote ) {
			$preset = WPS_Settings::remote( true === $assoc['remote'] ? null : $assoc['remote'] );
			if ( ! $preset ) {
				WP_CLI::error( true === $assoc['remote'] ? '接続先が設定されていません。' : '接続先が見つかりません: ' . $assoc['remote'] );
			}
		}
		WP_CLI::confirm( ( $remote ? '接続先「' . $preset['name'] . '」（' . $preset['home'] . '）' : 'このサイト' ) . 'の DB を戻します。よろしいですか？', $assoc );
		try {
			$result = $remote ? WPS_Endpoint::remote( $preset['id'] )->call( 'restore' ) : WPS_Server::dispatch( 'restore', array(), false );
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

		$remote = WPS_Settings::remote( isset( $assoc['remote'] ) ? $assoc['remote'] : null );
		if ( ! $remote ) {
			WP_CLI::error( isset( $assoc['remote'] ) ? '接続先が見つかりません: ' . $assoc['remote'] . '（wp sync remotes で一覧）' : '接続先が設定されていません（wp sync connect <key> --name=<名前>）。' );
		}
		WP_CLI::line( sprintf( '接続先「%s」（%s）と %s します。', $remote['name'], $remote['home'], $direction ) );
		$target = 'pull' === $direction ? 'このサイト' : '接続先「' . $remote['name'] . '」（' . $remote['home'] . '）';
		WP_CLI::confirm( $target . 'の' . implode( '・', array_filter( array( $db ? 'DB' : '', $media ? 'メディア' : '', empty( $assoc['themes'] ) ? '' : 'テーマ（' . $assoc['themes'] . '）', empty( $assoc['plugins'] ) ? '' : 'プラグイン（' . $assoc['plugins'] . '）' ) ) ) . 'を上書きします。よろしいですか？', $assoc );

		try {
			$status = WPS_Job::start(
				array(
					'direction'    => $direction,
					'remote'       => $remote['id'],
					'db'           => $db,
					'media'        => $media,
					'media_delete' => ! empty( $assoc['delete-media'] ),
					'pairs'        => $pairs,
					'keep_active_plugins' => WP_CLI\Utils\get_flag_value( $assoc, 'keep-active-plugins', true ),
					'themes'       => $this->code_names( $assoc, 'themes', $direction, $remote['id'] ),
					'plugins'      => $this->code_names( $assoc, 'plugins', $direction, $remote['id'] ),
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

	/**
	 * --themes / --plugins の値を名前の配列にする。all なら送り元にあるものすべて。
	 */
	private function code_names( $assoc, $root, $direction, $remote ) {
		if ( empty( $assoc[ $root ] ) ) {
			return array();
		}
		if ( 'all' === $assoc[ $root ] ) {
			$info = 'pull' === $direction ? WPS_Endpoint::remote( $remote )->call( 'info' ) : WPS_Server::info();
			return wp_list_pluck( $info['code'][ $root ], 'slug' );
		}
		return array_values( array_filter( array_map( 'trim', explode( ',', $assoc[ $root ] ) ) ) );
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
