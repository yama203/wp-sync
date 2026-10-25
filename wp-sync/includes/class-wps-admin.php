<?php
/**
 * 管理画面（ツール → WP Sync）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Admin {

	const SLUG = 'wp-sync';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wps_permissions', array( __CLASS__, 'save_permissions' ) );
		add_action( 'admin_post_wps_regenerate', array( __CLASS__, 'regenerate' ) );
		add_action( 'admin_post_wps_disconnect', array( __CLASS__, 'disconnect' ) );
		foreach ( array( 'connect', 'info', 'start', 'step', 'restore' ) as $action ) {
			add_action( 'wp_ajax_wps_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . WPS_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		$hook = add_management_page( 'WP Sync', 'WP Sync', 'manage_options', self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">設定</a>' );
		return $links;
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), admin_url( 'tools.php' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'wps-admin', WPS_URL . 'assets/admin.css', array(), WPS_VERSION );
		wp_enqueue_script( 'wps-admin', WPS_URL . 'assets/admin.js', array(), WPS_VERSION, true );
		wp_localize_script(
			'wps-admin',
			'wpsAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wps_ajax' ),
				'connected' => (bool) WPS_Settings::remote(),
				'login'     => wp_login_url( self::url() ),
			)
		);
	}

	private static function check_post( $action ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( $action );
	}

	public static function save_permissions() {
		self::check_post( 'wps_permissions' );
		WPS_Settings::save_permissions( ! empty( $_POST['allow_pull'] ), ! empty( $_POST['allow_push'] ) );
		wp_safe_redirect( self::url( array( 'wps_notice' => 'saved' ) ) );
		exit;
	}

	public static function regenerate() {
		self::check_post( 'wps_regenerate' );
		WPS_Settings::regenerate_secret();
		wp_safe_redirect( self::url( array( 'wps_notice' => 'regenerated' ) ) );
		exit;
	}

	public static function disconnect() {
		self::check_post( 'wps_disconnect' );
		WPS_Settings::save_remote( null );
		wp_safe_redirect( self::url( array( 'wps_notice' => 'disconnected' ) ) );
		exit;
	}

	private static function ajax_guard() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		check_ajax_referer( 'wps_ajax' );
		@set_time_limit( 300 ); // phpcs:ignore
		wp_raise_memory_limit( 'admin' );
	}

	private static function ajax_run( $callback ) {
		self::ajax_guard();
		try {
			wp_send_json_success( call_user_func( $callback ) );
		} catch ( Throwable $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	private static function post( $key, $default = '' ) {
		return isset( $_POST[ $key ] ) ? wp_unslash( $_POST[ $key ] ) : $default; // phpcs:ignore
	}

	public static function ajax_connect() {
		self::ajax_run(
			function () {
				$parsed = WPS_Settings::parse_connection_key( self::post( 'key' ) );
				$url    = trim( (string) self::post( 'url' ) );
				$url    = '' !== $url ? untrailingslashit( esc_url_raw( $url ) ) : $parsed['url'];
				if ( untrailingslashit( get_option( 'home' ) ) === $parsed['url'] && WPS_Settings::secret() === $parsed['secret'] ) {
					throw new Exception( 'これはこのサイト自身の接続キーです。接続先のサイトの「ツール → WP Sync」にある接続キーを貼り付けてください。' );
				}
				$endpoint = new WPS_Remote_Endpoint( $url, $parsed['secret'] );
				$info     = $endpoint->call( 'info' );
				WPS_Settings::save_remote(
					array(
						'url'    => $url,
						'secret' => $parsed['secret'],
						'home'   => $info['home'],
					)
				);
				return self::info_payload( $info );
			}
		);
	}

	public static function ajax_info() {
		self::ajax_run(
			function () {
				return self::info_payload( WPS_Endpoint::remote()->call( 'info' ) );
			}
		);
	}

	private static function info_payload( $remote ) {
		$local = WPS_Server::info();
		$size  = function ( $info ) {
			return array(
				'tables' => count( $info['tables'] ),
				'bytes'  => size_format( array_sum( wp_list_pluck( $info['tables'], 'bytes' ) ), 1 ),
			);
		};
		$backup = function ( $info ) {
			if ( empty( $info['backup'] ) ) {
				return null;
			}
			return array(
				'time'   => $info['backup']['time'] ? wp_date( 'Y年n月j日 H:i', $info['backup']['time'] ) : '',
				'from'   => $info['backup']['from'],
				'tables' => $info['backup']['tables'],
			);
		};
		$settings = WPS_Settings::remote();
		return array(
			'remote' => array(
				'url'        => $settings ? $settings['url'] : '',
				'home'       => $remote['home'],
				'name'       => $remote['name'],
				'version'    => $remote['version'],
				'compatible' => (int) $remote['api'] === WPS_API_VERSION,
				'wp'         => $remote['wp'],
				'php'        => $remote['php'],
				'db'         => $remote['db'],
				'prefix'     => $remote['prefix'],
				'multisite'  => $remote['multisite'],
				'allow_pull' => $remote['allow_pull'],
				'allow_push' => $remote['allow_push'],
				'size'       => $size( $remote ),
				'backup'     => $backup( $remote ),
			),
			'local'  => array(
				'home'   => $local['home'],
				'prefix' => $local['prefix'],
				'size'   => $size( $local ),
				'backup' => $backup( $local ),
			),
			'pairs'  => array(
				'pull' => WPS_Job::preview_pairs( $remote, $local ),
				'push' => WPS_Job::preview_pairs( $local, $remote ),
			),
		);
	}

	public static function ajax_start() {
		self::ajax_run(
			function () {
				$pairs = json_decode( (string) self::post( 'pairs', '[]' ), true );
				return WPS_Job::start(
					array(
						'direction'    => self::post( 'direction' ),
						'db'           => '1' === self::post( 'db' ),
						'media'        => '1' === self::post( 'media' ),
						'media_delete' => '1' === self::post( 'media_delete' ),
						'pairs'        => is_array( $pairs ) ? $pairs : array(),
						'keep_active_plugins' => '1' === self::post( 'keep_active_plugins' ),
					)
				);
			}
		);
	}

	public static function ajax_step() {
		self::ajax_run(
			function () {
				return WPS_Job::step_by_id( (string) self::post( 'id' ) );
			}
		);
	}

	public static function ajax_restore() {
		self::ajax_run(
			function () {
				if ( 'remote' === self::post( 'target' ) ) {
					return WPS_Endpoint::remote()->call( 'restore' );
				}
				return WPS_DB::restore();
			}
		);
	}

	public static function render() {
		$notice  = isset( $_GET['wps_notice'] ) ? sanitize_key( $_GET['wps_notice'] ) : ''; // phpcs:ignore
		$notices = array(
			'saved'        => '許可の設定を保存しました。',
			'regenerated'  => '接続キーを作り直しました。接続先に新しいキーを貼り直してください。',
			'disconnected' => '接続を解除しました。',
		);
		$remote = WPS_Settings::remote();
		?>
		<div class="wrap wps">
			<h1>WP Sync</h1>
			<?php if ( isset( $notices[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<div class="notice notice-warning"><p>マルチサイトにはまだ対応していません。</p></div>
			<?php endif; ?>

			<div class="wps-card">
				<h2>同期</h2>
				<div id="wps-connect" <?php echo $remote ? 'hidden' : ''; ?>>
					<p>接続先のサイトの「ツール → WP Sync」にある接続キーを貼り付けてください。</p>
					<p><textarea id="wps-key" class="large-text code" rows="3" placeholder="wps1:..."></textarea></p>
					<details>
						<summary>接続する URL を変える（任意）</summary>
						<p class="description">接続キーに入っている URL とは別の URL でつなぐときだけ入れます（例：Docker の中から別のローカル環境につなぐときの <code>http://host.docker.internal:10899</code>）。</p>
						<p><input type="url" id="wps-url" class="regular-text code" placeholder="https://example.com"></p>
					</details>
					<p><button type="button" class="button button-primary" id="wps-connect-button">接続する</button></p>
				</div>

				<div id="wps-remote" <?php echo $remote ? '' : 'hidden'; ?>>
					<p class="wps-remote-head">
						接続先: <strong id="wps-remote-home"><?php echo $remote ? esc_html( $remote['home'] ) : ''; ?></strong>
						<span id="wps-remote-meta" class="description"></span>
					</p>
					<div id="wps-remote-warning"></div>

					<fieldset class="wps-direction">
						<label><input type="radio" name="wps-direction" value="pull" checked> <strong>Pull</strong>（接続先 → このサイト）</label>
						<label><input type="radio" name="wps-direction" value="push"> <strong>Push</strong>（このサイト → 接続先）</label>
					</fieldset>

					<fieldset class="wps-what">
						<label><input type="checkbox" id="wps-db" checked> データベース</label>
						<label class="wps-indent"><input type="checkbox" id="wps-keep-active" checked> 有効化しているプラグインの設定は送らない（送り先で有効なプラグインをそのまま残す）</label>
						<label><input type="checkbox" id="wps-media" checked> メディア（uploads）</label>
						<label class="wps-indent"><input type="checkbox" id="wps-media-delete"> 送り元にないメディアを送り先から消す</label>
					</fieldset>

					<div class="wps-pairs">
						<h3>置換</h3>
						<p class="description">送り先に合わせて、DB の中の URL とパスを置き換えます（serialize された値も壊さずに直します）。</p>
						<table class="widefat striped">
							<thead><tr><th>置き換える前</th><th></th><th>置き換えた後</th><th></th></tr></thead>
							<tbody id="wps-pairs-auto"></tbody>
							<tbody id="wps-pairs-extra"></tbody>
						</table>
						<p><button type="button" class="button" id="wps-add-pair">置換を足す</button></p>
					</div>

					<p class="wps-start">
						<button type="button" class="button button-primary button-hero" id="wps-start">Pull を始める</button>
					</p>

					<div id="wps-progress" hidden>
						<p class="wps-progress-label"><strong id="wps-progress-label"></strong> <span id="wps-progress-detail" class="description"></span></p>
						<div class="wps-bar"><div class="wps-bar-fill" id="wps-bar-fill"></div></div>
						<ol id="wps-log" class="wps-log"></ol>
					</div>

					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wps-disconnect">
						<?php wp_nonce_field( 'wps_disconnect' ); ?>
						<input type="hidden" name="action" value="wps_disconnect">
						<button type="submit" class="button-link">接続を解除する</button>
					</form>
				</div>
			</div>

			<div class="wps-card" id="wps-backups" <?php echo $remote ? '' : 'hidden'; ?>>
				<h2>元に戻す</h2>
				<p class="description">DB を取り込むと、取り込む前の DB を 1 回分だけ残しています。次に取り込むと、その前のものに置き換わります（メディアは戻りません）。</p>
				<table class="widefat striped">
					<tbody>
						<tr>
							<th>このサイト</th>
							<td id="wps-backup-local">—</td>
							<td><button type="button" class="button" data-restore="local" disabled>元に戻す</button></td>
						</tr>
						<tr>
							<th>接続先</th>
							<td id="wps-backup-remote">—</td>
							<td><button type="button" class="button" data-restore="remote" disabled>元に戻す</button></td>
						</tr>
					</tbody>
				</table>
			</div>

			<div class="wps-card">
				<h2>このサイトの接続キー</h2>
				<p>このサイトとつなぐときは、相手のサイトの WP Sync にこのキーを貼り付けます。<strong>このキーを知っている人は、下で許可した操作ができます。</strong>人に見せないでください。</p>
				<p><textarea class="large-text code" rows="3" readonly id="wps-own-key"><?php echo esc_textarea( WPS_Settings::connection_key() ); ?></textarea></p>
				<p>
					<button type="button" class="button" id="wps-copy-key">コピー</button>
				</p>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( 'wps_permissions' ); ?>
					<input type="hidden" name="action" value="wps_permissions">
					<fieldset>
						<label><input type="checkbox" name="allow_pull" value="1" <?php checked( WPS_Settings::allow_pull() ); ?>> 相手がこのサイトから pull するのを許可する（DB とメディアを読み出せる）</label><br>
						<label><input type="checkbox" name="allow_push" value="1" <?php checked( WPS_Settings::allow_push() ); ?>> 相手がこのサイトへ push するのを許可する（DB とメディアを上書きできる）</label>
					</fieldset>
					<p><button type="submit" class="button">保存</button></p>
				</form>

				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('接続キーを作り直すと、今のキーではつなげなくなります。よろしいですか？');">
					<?php wp_nonce_field( 'wps_regenerate' ); ?>
					<input type="hidden" name="action" value="wps_regenerate">
					<button type="submit" class="button-link button-link-delete">接続キーを作り直す</button>
				</form>
			</div>
		</div>
		<?php
	}
}
