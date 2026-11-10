<?php
/**
 * 管理画面（ツール → WP Sync。マルチサイトではネットワーク管理の 設定 → WP Sync）。
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WPS_Admin {

	const SLUG = 'wp-sync';

	public static function init() {
		add_action( is_multisite() ? 'network_admin_menu' : 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wps_permissions', array( __CLASS__, 'save_permissions' ) );
		add_action( 'admin_post_wps_regenerate', array( __CLASS__, 'regenerate' ) );
		add_action( 'admin_post_wps_disconnect', array( __CLASS__, 'disconnect' ) );
		foreach ( array( 'connect', 'info', 'start', 'step', 'restore', 'export', 'upload', 'import', 'confirm', 'file_step' ) as $action ) {
			add_action( 'wp_ajax_wps_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
		add_action( 'admin_post_wps_download', array( __CLASS__, 'download' ) );
		add_filter( ( is_multisite() ? 'network_admin_' : '' ) . 'plugin_action_links_' . WPS_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		$hook = is_multisite()
			? add_submenu_page( 'settings.php', 'WP Sync', 'WP Sync', WPS_Settings::capability(), self::SLUG, array( __CLASS__, 'render' ) )
			: add_management_page( 'WP Sync', 'WP Sync', WPS_Settings::capability(), self::SLUG, array( __CLASS__, 'render' ) );
		add_action( 'admin_print_scripts-' . $hook, array( __CLASS__, 'assets' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">設定</a>' );
		return $links;
	}

	private static function url( $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG ), $args ), is_multisite() ? network_admin_url( 'settings.php' ) : admin_url( 'tools.php' ) );
	}

	public static function assets() {
		wp_enqueue_style( 'wps-admin', WPS_URL . 'assets/admin.css', array(), WPS_VERSION . '.' . filemtime( WPS_DIR . 'assets/admin.css' ) );
		wp_enqueue_script( 'wps-admin', WPS_URL . 'assets/admin.js', array(), WPS_VERSION . '.' . filemtime( WPS_DIR . 'assets/admin.js' ), true );
		wp_enqueue_script( 'wps-archive', WPS_URL . 'assets/archive.js', array( 'wps-admin' ), WPS_VERSION . '.' . filemtime( WPS_DIR . 'assets/archive.js' ), true );
		wp_localize_script(
			'wps-admin',
			'wpsAdmin',
			array(
				'ajax'      => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wps_ajax' ),
				'connected' => (bool) WPS_Settings::remote(),
				'login'     => wp_login_url( self::url() ),
				'version'   => WPS_VERSION,
				// アップロードを分けて送る大きさ（このサイトの upload_max_filesize・post_max_size に収まるように）。
				'chunk'     => (int) max( 262144, min( 4194304, wp_max_upload_size() * 0.8 ) ),
				'download'  => wp_nonce_url( admin_url( 'admin-post.php?action=wps_download' ), 'wps_download' ),
			)
		);
	}

	private static function check_post( $action ) {
		if ( ! current_user_can( WPS_Settings::capability() ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( $action );
	}

	public static function save_permissions() {
		self::check_post( 'wps_permissions' );
		WPS_Settings::save_permissions( ! empty( $_POST['allow_pull'] ), ! empty( $_POST['allow_push'] ) );
		wp_safe_redirect( self::url( array( 'wps_notice' => 'saved', 'tab' => 'settings' ) ) );
		exit;
	}

	public static function regenerate() {
		self::check_post( 'wps_regenerate' );
		WPS_Settings::regenerate_secret();
		wp_safe_redirect( self::url( array( 'wps_notice' => 'regenerated', 'tab' => 'settings' ) ) );
		exit;
	}

	public static function disconnect() {
		self::check_post( 'wps_disconnect' );
		WPS_Settings::save_remote( null );
		wp_safe_redirect( self::url( array( 'wps_notice' => 'disconnected' ) ) );
		exit;
	}

	private static function ajax_guard() {
		if ( ! current_user_can( WPS_Settings::capability() ) ) {
			wp_send_json_error( array( 'message' => '権限がありません。' ), 403 );
		}
		check_ajax_referer( 'wps_ajax' );
		@set_time_limit( 300 ); // phpcs:ignore
		wp_raise_memory_limit( 'admin' );
	}

	private static function ajax_run( $callback ) {
		self::ajax_guard();
		try {
			WPS_Settings::assert_main_site();
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
				if ( WPS_Settings::home() === $parsed['url'] && WPS_Settings::secret() === $parsed['secret'] ) {
					throw new Exception( 'これはこのサイト自身の接続キーです。接続先のサイトの WP Sync の「このサイトの設定」にある接続キーを貼り付けてください。' );
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
				'network'    => self::network_payload( $remote ),
				'allow_pull' => $remote['allow_pull'],
				'allow_push' => $remote['allow_push'],
				'size'       => $size( $remote ),
				'backup'     => $backup( $remote ),
				'code'       => $remote['code'],
			),
			'local'  => array(
				'network' => self::network_payload( $local ),
				'name'   => $local['name'],
				'home'   => $local['home'],
				'prefix' => $local['prefix'],
				'size'   => $size( $local ),
				'backup' => $backup( $local ),
				'code'   => $local['code'],
			),
			'pairs'  => array(
				'pull' => WPS_Job::preview_pairs( $remote, $local ),
				'push' => WPS_Job::preview_pairs( $local, $remote ),
			),
		);
	}

	private static function network_payload( $info ) {
		if ( empty( $info['network'] ) ) {
			return null;
		}
		return array(
			'subdomain' => (bool) $info['network']['subdomain'],
			'sites'     => count( $info['network']['sites'] ),
		);
	}

	public static function ajax_start() {
		self::ajax_run(
			function () {
				$pairs = json_decode( (string) self::post( 'pairs', '[]' ), true );
				$code  = json_decode( (string) self::post( 'code', '{}' ), true );
				return WPS_Job::start(
					array(
						'direction'    => self::post( 'direction' ),
						'db'           => '1' === self::post( 'db' ),
						'media'        => '1' === self::post( 'media' ),
						'media_delete' => '1' === self::post( 'media_delete' ),
						'pairs'        => is_array( $pairs ) ? $pairs : array(),
						'keep_active_plugins' => '1' === self::post( 'keep_active_plugins' ),
						'themes'       => isset( $code['themes'] ) ? (array) $code['themes'] : array(),
						'plugins'      => isset( $code['plugins'] ) ? (array) $code['plugins'] : array(),
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

	/* ---------- エクスポート・インポート ---------- */

	private static function post_pairs() {
		$pairs = json_decode( (string) self::post( 'pairs', '[]' ), true );
		return is_array( $pairs ) ? $pairs : array();
	}

	public static function ajax_export() {
		self::ajax_run(
			function () {
				return WPS_Archive::start_export( self::post_pairs() );
			}
		);
	}

	public static function ajax_upload() {
		self::ajax_run(
			function () {
				// phpcs:ignore WordPress.Security.NonceVerification -- ajax_guard で確かめている。
				$file = isset( $_FILES['chunk'] ) ? $_FILES['chunk'] : null;
				if ( ! $file || UPLOAD_ERR_OK !== $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
					throw new Exception( 'アップロードを受け取れませんでした（エラー ' . ( $file ? (int) $file['error'] : '-' ) . '）。' );
				}
				return WPS_Archive::upload( (string) self::post( 'id' ), (int) self::post( 'offset' ), $file['tmp_name'] );
			}
		);
	}

	public static function ajax_import() {
		self::ajax_run(
			function () {
				return WPS_Archive::start_import( (string) self::post( 'id' ), (string) self::post( 'name' ) );
			}
		);
	}

	public static function ajax_confirm() {
		self::ajax_run(
			function () {
				return WPS_Archive::confirm( (string) self::post( 'id' ), self::post_pairs(), '1' === self::post( 'keep_active_plugins' ) );
			}
		);
	}

	public static function ajax_file_step() {
		self::ajax_run(
			function () {
				return WPS_Archive::step_by_id( (string) self::post( 'id' ) );
			}
		);
	}

	public static function download() {
		if ( ! current_user_can( WPS_Settings::capability() ) ) {
			wp_die( '権限がありません。' );
		}
		check_admin_referer( 'wps_download' );
		try {
			WPS_Archive::download( isset( $_GET['id'] ) ? (string) wp_unslash( $_GET['id'] ) : '' ); // phpcs:ignore
		} catch ( Exception $e ) {
			wp_die( esc_html( $e->getMessage() ) );
		}
	}

	private static function render_file_panel() {
		$backup = WPS_DB::backup_info();
		?>
		<section class="wps-card">
			<h2 class="wps-card-title">エクスポート</h2>
			<p>このサイトの DB を、ダウンロードできるファイル（<code>.sql.gz</code>）に書き出します。そのまま mysql や phpMyAdmin でも読み込めます。<?php echo is_multisite() ? 'マルチサイトでは、ネットワークのすべてのサイトの DB を書き出します。' : ''; ?></p>
			<p class="description">WP Sync のインポートで読み込むと、取り込む先の URL・パス・接頭辞に自動で置き換えます。接続キーなど WP Sync の設定は書き出しません。</p>
			<details class="wps-details wps-pairs">
				<summary>書き出すときに置き換える <span class="wps-count" id="wps-export-pairs-count"></span></summary>
				<div class="wps-details-body">
					<p class="description">書き出すファイルの中だけを置き換えます（このサイトの DB は変わりません）。ふつうは空のままで大丈夫です。</p>
					<table class="wps-table">
						<thead><tr><th>置き換える前</th><th class="wps-arrow-col"></th><th>置き換えた後</th><th class="wps-tag-col"></th></tr></thead>
						<tbody id="wps-export-pairs"></tbody>
					</table>
					<button type="button" class="button button-small" data-add-pair="wps-export-pairs"><span class="dashicons dashicons-plus-alt2"></span> 置換を足す</button>
				</div>
			</details>
			<div class="wps-actions">
				<button type="button" class="button button-primary button-large" id="wps-export-start"><span class="dashicons dashicons-download"></span> エクスポートする</button>
			</div>
			<div class="wps-file-progress" id="wps-export-progress" hidden></div>
		</section>

		<section class="wps-card">
			<h2 class="wps-card-title">インポート</h2>
			<p>書き出したファイル（<code>.sql.gz</code> か <code>.sql</code>）を、このサイトの DB に取り込みます。ファイルの中の URL・パス・接頭辞は、このサイトのものに自動で置き換えます。</p>
			<p class="description">WP Sync でエクスポートしたファイルのほか、mysqldump や phpMyAdmin のファイルも読み込めます（options テーブルの URL から置き換えます。マルチサイトは WP Sync のファイルだけ）。</p>
			<div class="wps-file-pick">
				<input type="file" id="wps-import-file" accept=".sql,.gz,application/gzip,application/sql">
				<button type="button" class="button button-large" id="wps-import-upload" disabled>ファイルを読み込む</button>
			</div>
			<div class="wps-file-progress" id="wps-import-progress" hidden></div>

			<div class="wps-confirm" id="wps-import-confirm" hidden>
				<h3>取り込む前の確認</h3>
				<dl class="wps-facts" id="wps-import-facts"></dl>
				<div class="wps-options">
					<div class="wps-option wps-option-choice">
						<span class="wps-option-label"><strong>プラグインの有効・無効</strong></span>
						<label>
							<input type="radio" name="wps-import-active" id="wps-import-keep-active" value="keep" checked>
							<span><strong>このサイトのままにする</strong><span class="wps-option-desc">このサイトで有効なプラグインをそのまま残します。</span></span>
						</label>
						<label>
							<input type="radio" name="wps-import-active" value="copy">
							<span><strong>ファイルに合わせる</strong><span class="wps-option-desc">ファイルの DB で有効だったプラグインを有効にします。このサイトにないプラグインは有効になりません。</span></span>
						</label>
					</div>
				</div>
				<details class="wps-details wps-pairs" open>
					<summary>URL とパスの置換 <span class="wps-count" id="wps-import-pairs-count"></span></summary>
					<div class="wps-details-body">
						<p class="description">serialize された値や、ブロックの JSON の中の URL も壊さずに置き換えます。ほかに置き換えたいものがあれば足してください。</p>
						<table class="wps-table">
							<thead><tr><th>置き換える前</th><th class="wps-arrow-col"></th><th>置き換えた後</th><th class="wps-tag-col"></th></tr></thead>
							<tbody id="wps-import-pairs-auto"></tbody>
							<tbody id="wps-import-pairs"></tbody>
						</table>
						<button type="button" class="button button-small" data-add-pair="wps-import-pairs"><span class="dashicons dashicons-plus-alt2"></span> 置換を足す</button>
					</div>
				</details>
				<div class="wps-run">
					<p class="wps-summary">このサイト（<?php echo esc_html( WPS_Settings::home() ); ?>）の DB を上書きします。終わったら、ファイルの DB のユーザーでログインし直すことになります。</p>
					<div class="wps-run-buttons">
						<button type="button" class="button button-large" id="wps-import-cancel">やめる</button>
						<button type="button" class="button button-primary button-hero" id="wps-import-start">インポートする</button>
					</div>
				</div>
			</div>
		</section>

		<section class="wps-card wps-card-quiet">
			<h2 class="wps-card-title">元に戻す</h2>
			<p class="description">インポートや pull で DB を取り込むと、取り込む前の DB を 1 回分だけ残します。</p>
			<div class="wps-backup-list">
				<div class="wps-backup">
					<span class="wps-backup-site">このサイト</span>
					<span class="wps-backup-info"><?php echo $backup ? esc_html( ( $backup['time'] ? wp_date( 'Y年n月j日 H:i', $backup['time'] ) : '日時不明' ) . ' に取り込む前の DB' . ( $backup['from'] ? '（' . $backup['from'] . ' から取り込み）' : '' ) ) : 'バックアップはありません'; ?></span>
					<button type="button" class="button" id="wps-file-restore" <?php disabled( ! $backup ); ?>>元に戻す</button>
				</div>
			</div>
		</section>
		<?php
	}

	public static function render() {
		$notice  = isset( $_GET['wps_notice'] ) ? sanitize_key( $_GET['wps_notice'] ) : ''; // phpcs:ignore
		$tab     = isset( $_GET['tab'] ) && in_array( $_GET['tab'], array( 'settings', 'file' ), true ) ? sanitize_key( $_GET['tab'] ) : 'sync'; // phpcs:ignore
		$notices = array(
			'saved'        => '許可の設定を保存しました。',
			'regenerated'  => '接続キーを作り直しました。接続先に新しいキーを貼り直してください。',
			'disconnected' => '接続を解除しました。',
		);
		$remote = WPS_Settings::remote();
		$post   = esc_url( admin_url( 'admin-post.php' ) );
		?>
		<div class="wrap wps">
			<div class="wps-header">
				<h1>WP Sync <span class="wps-version"><?php echo esc_html( WPS_VERSION ); ?></span></h1>
				<p class="wps-lead">ローカルと本番の間で、データベース・メディア・テーマ・プラグインを同期します。DB はファイルでエクスポート・インポートもできます。</p>
			</div>

			<?php if ( isset( $notices[ $notice ] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php echo esc_html( $notices[ $notice ] ); ?></p></div>
			<?php endif; ?>
			<?php if ( is_multisite() ) : ?>
				<p class="wps-network-note"><span class="dashicons dashicons-networking"></span> マルチサイトでは、ネットワークのすべてのサイトをまとめて同期します。接続先もマルチサイトにしてください。</p>
			<?php endif; ?>

			<nav class="nav-tab-wrapper wps-tabs">
				<a href="<?php echo esc_url( self::url() ); ?>" class="nav-tab <?php echo 'sync' === $tab ? 'nav-tab-active' : ''; ?>" data-tab="sync">同期</a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'file' ) ) ); ?>" class="nav-tab <?php echo 'file' === $tab ? 'nav-tab-active' : ''; ?>" data-tab="file">エクスポート・インポート</a>
				<a href="<?php echo esc_url( self::url( array( 'tab' => 'settings' ) ) ); ?>" class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" data-tab="settings">このサイトの設定</a>
			</nav>

			<div class="wps-panel" data-panel="sync" <?php echo 'sync' === $tab ? '' : 'hidden'; ?>>

				<?php if ( ! $remote ) : ?>
					<section class="wps-card" id="wps-connect">
						<h2 class="wps-card-title">接続先につなぐ</h2>
						<ol class="wps-steps">
							<li>接続先のサイトに WP Sync を入れ、「ツール → WP Sync → このサイトの設定」（マルチサイトでは「ネットワーク管理 → 設定 → WP Sync」）で接続キーをコピーします。</li>
							<li>下に貼り付けて「接続する」を押します。</li>
						</ol>
						<textarea id="wps-key" class="large-text code wps-key" rows="3" placeholder="wps1:..."></textarea>
						<details class="wps-details">
							<summary>接続する URL を変える（任意）</summary>
							<div class="wps-details-body">
								<p class="description">接続キーに入っている URL とは別の URL でつなぐときだけ入れます（例：Docker の中から別のローカル環境につなぐときの <code>http://host.docker.internal:10899</code>）。</p>
								<input type="url" id="wps-url" class="regular-text code" placeholder="https://example.com">
							</div>
						</details>
						<div class="wps-actions">
							<button type="button" class="button button-primary button-large" id="wps-connect-button">接続する</button>
						</div>
					</section>
				<?php else : ?>
					<section class="wps-card">
						<div class="wps-direction" role="radiogroup" aria-label="同期の向き">
							<label class="wps-seg">
								<input type="radio" name="wps-direction" value="pull" checked>
								<span><strong>Pull</strong>接続先から取り込む</span>
							</label>
							<label class="wps-seg">
								<input type="radio" name="wps-direction" value="push">
								<span><strong>Push</strong>接続先へ送る</span>
							</label>
						</div>

						<div class="wps-sites" id="wps-sites">
							<div class="wps-site" id="wps-site-local">
								<span class="wps-role"></span>
								<span class="wps-site-kind">このサイト</span>
								<strong class="wps-site-name"><?php echo esc_html( WPS_Settings::get( 'blogname' ) ); ?></strong>
								<span class="wps-site-url"><?php echo esc_html( WPS_Settings::home() ); ?></span>
								<span class="wps-site-meta"></span>
							</div>
							<div class="wps-flow" aria-hidden="true"><span class="dashicons dashicons-arrow-left-alt"></span></div>
							<div class="wps-site" id="wps-site-remote">
								<span class="wps-role"></span>
								<span class="wps-site-kind">接続先</span>
								<strong class="wps-site-name">読み込み中…</strong>
								<span class="wps-site-url"><?php echo esc_html( $remote['home'] ); ?></span>
								<span class="wps-site-meta"></span>
							</div>
						</div>
						<div id="wps-remote-warning"></div>
					</section>

					<section class="wps-card">
						<h2 class="wps-card-title">送るもの</h2>
						<div class="wps-options">
							<label class="wps-option">
								<input type="checkbox" id="wps-db" checked>
								<span><strong>データベース</strong><span class="wps-option-desc">投稿・固定ページ・設定・ユーザーなど。URL とパスは送り先に合わせて置き換えます。</span></span>
							</label>
							<div class="wps-option wps-option-sub wps-option-choice" id="wps-active-choice">
								<span class="wps-option-label"><strong>プラグインの有効・無効</strong></span>
								<label>
									<input type="radio" name="wps-active" id="wps-keep-active" value="keep" checked>
									<span><strong>送り先のままにする</strong><span class="wps-option-desc">送り先で有効なプラグインをそのまま残します。</span></span>
								</label>
								<label>
									<input type="radio" name="wps-active" id="wps-copy-active" value="copy">
									<span><strong>送り元に合わせる</strong><span class="wps-option-desc">送り元で有効なプラグインを、送り先でも有効にします（はじめての pull など）。送り先にないプラグインは有効になりません。</span></span>
								</label>
							</div>
							<label class="wps-option">
								<input type="checkbox" id="wps-media" checked>
								<span><strong>メディア</strong><span class="wps-option-desc">uploads フォルダのうち、大きさか更新日時が違うファイルだけを送ります。</span></span>
							</label>
							<label class="wps-option wps-option-sub">
								<input type="checkbox" id="wps-media-delete">
								<span><strong>送り元にないメディアを送り先から消す</strong></span>
							</label>
							<?php foreach ( array( 'themes' => array( 'テーマ', 'テーマのフォルダの中身。Git で管理しているテーマは、上書きしてよいか気を付けてください。' ), 'plugins' => array( 'プラグイン', 'プラグインのファイル。送り先で有効にするかどうかは変えません（WP Sync 自身は送りません）。' ) ) as $root => $text ) : ?>
								<label class="wps-option">
									<input type="checkbox" id="wps-<?php echo esc_attr( $root ); ?>">
									<span><strong><?php echo esc_html( $text[0] ); ?></strong><span class="wps-option-desc"><?php echo esc_html( $text[1] ); ?>違うファイルだけを送り、送り元にないファイルは消します。<code>.git</code>・<code>node_modules</code> は送りません。</span></span>
								</label>
								<div class="wps-code" id="wps-<?php echo esc_attr( $root ); ?>-list" hidden>
									<div class="wps-code-head">
										<span class="wps-code-count"></span>
										<span class="wps-code-tools">
											<button type="button" class="button-link" data-select="all">すべて選ぶ</button>
											<button type="button" class="button-link" data-select="none">すべて外す</button>
										</span>
									</div>
									<ul class="wps-code-items"></ul>
								</div>
							<?php endforeach; ?>
						</div>

						<details class="wps-details wps-pairs">
							<summary>URL とパスの置換 <span class="wps-count" id="wps-pairs-count"></span></summary>
							<div class="wps-details-body">
								<p class="description">serialize された値や、ブロックの JSON の中の URL も壊さずに置き換えます。ほかに置き換えたいものがあれば足してください。</p>
								<table class="wps-table">
									<thead><tr><th>置き換える前</th><th class="wps-arrow-col"></th><th>置き換えた後</th><th class="wps-tag-col"></th></tr></thead>
									<tbody id="wps-pairs-auto"></tbody>
									<tbody id="wps-pairs-extra"></tbody>
								</table>
								<button type="button" class="button button-small" id="wps-add-pair"><span class="dashicons dashicons-plus-alt2"></span> 置換を足す</button>
							</div>
						</details>

						<div class="wps-run">
							<p class="wps-summary" id="wps-summary"></p>
							<button type="button" class="button button-primary button-hero" id="wps-start">Pull を始める</button>
						</div>
					</section>

					<section class="wps-card" id="wps-progress" hidden>
						<div class="wps-progress-head">
							<strong id="wps-progress-label"></strong>
							<span id="wps-progress-percent" class="wps-percent"></span>
						</div>
						<div class="wps-bar"><div class="wps-bar-fill" id="wps-bar-fill"></div></div>
						<p id="wps-progress-detail" class="wps-progress-detail"></p>
						<div id="wps-result"></div>
						<details class="wps-details" open>
							<summary>記録</summary>
							<ol id="wps-log" class="wps-log"></ol>
						</details>
					</section>

					<section class="wps-card" id="wps-backups">
						<h2 class="wps-card-title">元に戻す</h2>
						<p class="description">DB を取り込むと、取り込む前の DB を 1 回分だけ残します。次に取り込むと、その前のものに置き換わります（メディアは戻りません）。</p>
						<div class="wps-backup-list">
							<div class="wps-backup">
								<span class="wps-backup-site">このサイト</span>
								<span class="wps-backup-info" id="wps-backup-local">—</span>
								<button type="button" class="button" data-restore="local" disabled>元に戻す</button>
							</div>
							<div class="wps-backup">
								<span class="wps-backup-site">接続先</span>
								<span class="wps-backup-info" id="wps-backup-remote">—</span>
								<button type="button" class="button" data-restore="remote" disabled>元に戻す</button>
							</div>
						</div>
					</section>
				<?php endif; ?>
			</div>

			<div class="wps-panel" data-panel="file" <?php echo 'file' === $tab ? '' : 'hidden'; ?>>
				<?php self::render_file_panel(); ?>
			</div>

			<div class="wps-panel" data-panel="settings" <?php echo 'settings' === $tab ? '' : 'hidden'; ?>>
				<section class="wps-card">
					<h2 class="wps-card-title">接続キー</h2>
					<p>相手のサイトの WP Sync にこのキーを貼り付けると、このサイトにつながります。</p>
					<div class="wps-key-row">
						<textarea class="large-text code wps-key" rows="3" readonly id="wps-own-key"><?php echo esc_textarea( WPS_Settings::connection_key() ); ?></textarea>
						<button type="button" class="button" id="wps-copy-key"><span class="dashicons dashicons-admin-page"></span> コピー</button>
					</div>
					<p class="wps-caution"><span class="dashicons dashicons-lock"></span> このキーを知っている人は、下で許可した操作ができます。人に見せないでください。</p>
				</section>

				<section class="wps-card">
					<h2 class="wps-card-title">相手に許可する操作</h2>
					<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
						<?php wp_nonce_field( 'wps_permissions' ); ?>
						<input type="hidden" name="action" value="wps_permissions">
						<div class="wps-options">
							<label class="wps-option">
								<input type="checkbox" name="allow_pull" value="1" <?php checked( WPS_Settings::allow_pull() ); ?>>
								<span><strong>このサイトから pull するのを許可する</strong><span class="wps-option-desc">相手がこのサイトの DB・メディア・テーマ・プラグインを読み出せます。</span></span>
							</label>
							<label class="wps-option">
								<input type="checkbox" name="allow_push" value="1" <?php checked( WPS_Settings::allow_push() ); ?>>
								<span><strong>このサイトへ push するのを許可する</strong><span class="wps-option-desc">相手がこのサイトの DB・メディア・テーマ・プラグインを上書きできます（プラグインの PHP も書き込めます）。本番では、push するときだけオンにするのが安全です。</span></span>
							</label>
						</div>
						<div class="wps-actions"><button type="submit" class="button button-primary">保存</button></div>
					</form>
				</section>

				<section class="wps-card wps-card-quiet">
					<h2 class="wps-card-title">接続の管理</h2>
					<?php if ( $remote ) : ?>
						<div class="wps-manage-row">
							<span>接続先: <strong><?php echo esc_html( $remote['home'] ); ?></strong><?php echo $remote['url'] !== $remote['home'] ? '（接続 URL: ' . esc_html( $remote['url'] ) . '）' : ''; ?></span>
							<form method="post" action="<?php echo $post; // phpcs:ignore ?>">
								<?php wp_nonce_field( 'wps_disconnect' ); ?>
								<input type="hidden" name="action" value="wps_disconnect">
								<button type="submit" class="button">接続を解除する</button>
							</form>
						</div>
					<?php endif; ?>
					<div class="wps-manage-row">
						<span>接続キーを作り直すと、今のキーでつないでいる相手はつながらなくなります。</span>
						<form method="post" action="<?php echo $post; // phpcs:ignore ?>" onsubmit="return confirm('接続キーを作り直すと、今のキーではつなげなくなります。よろしいですか？');">
							<?php wp_nonce_field( 'wps_regenerate' ); ?>
							<input type="hidden" name="action" value="wps_regenerate">
							<button type="submit" class="button button-link-delete wps-danger">接続キーを作り直す</button>
						</form>
					</div>
				</section>
			</div>
		</div>
		<?php
	}
}
