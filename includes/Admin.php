<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin surface: one top-level "AI Translate" menu, every screen rendered inside
 * the shared Perxel UI layout (vendor/perxel-ui). Owns menu registration, asset
 * loading, the shared layout args, and the plain form / AJAX handlers. The
 * heavier per-screen logic lives in Dashboard / Confirm / Progress / History /
 * IdLookup.
 */
class Admin {

	const MENU           = 'pxat';
	const PAGE_DASHBOARD = 'pxat';
	const PAGE_HISTORY   = 'pxat-history';
	const PAGE_ID_LOOKUP = 'pxat-id-lookup';
	const PAGE_SETTINGS  = 'pxat-settings';
	const PAGE_CONFIRM   = 'pxat-confirm';
	const PAGE_PROGRESS  = 'pxat-progress';
	const PAGE_UI        = 'pxat-ui';

	const NONCE = 'pxat_admin';

	public function register() {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );

		add_action( 'admin_post_pxat_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_pxat_reset_settings', array( $this, 'handle_reset_settings' ) );
		add_action( 'admin_post_pxat_create_run', array( Confirm::class, 'handle_submit' ) );
		add_action( 'admin_post_pxat_delete_run', array( $this, 'handle_delete_run' ) );
		add_action( 'admin_post_pxat_cancel_run', array( $this, 'handle_cancel_run' ) );
		add_action( 'admin_post_pxat_rerun', array( Progress::class, 'handle_rerun' ) );
		add_action( 'admin_post_pxat_cart_remove', array( $this, 'handle_cart_remove' ) );
		add_action( 'admin_post_pxat_cart_clear', array( $this, 'handle_cart_clear' ) );

		add_action( 'wp_ajax_pxat_test_api_key', array( $this, 'ajax_test_api_key' ) );
		add_action( 'wp_ajax_pxat_test_model', array( $this, 'ajax_test_model' ) );
		add_action( 'wp_ajax_pxat_process', array( Progress::class, 'ajax_process' ) );
		add_action( 'wp_ajax_pxat_retry', array( Progress::class, 'ajax_retry' ) );
		add_action( 'wp_ajax_pxat_status', array( Progress::class, 'ajax_status' ) );
	}

	/*
	---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------- */

	public function menu() {
		$cart_count = Cart::count();
		$menu_title = __( 'AI Translate', 'perxel-ai-translate' );
		if ( $cart_count > 0 ) {
			$menu_title .= ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $cart_count ) ) . '</span>';
		}

		add_menu_page(
			PXAT_NAME,
			$menu_title,
			'manage_options',
			self::PAGE_DASHBOARD,
			array( $this, 'render_dashboard' ),
			'dashicons-translation',
			76
		);

		$cart_label = __( 'Translation cart', 'perxel-ai-translate' );
		if ( $cart_count > 0 ) {
			$cart_label .= ' <span class="awaiting-mod">' . esc_html( number_format_i18n( $cart_count ) ) . '</span>';
		}

		$submenus = array(
			self::PAGE_DASHBOARD => array( __( 'Dashboard', 'perxel-ai-translate' ), array( $this, 'render_dashboard' ), __( 'Dashboard', 'perxel-ai-translate' ) ),
			self::PAGE_CONFIRM   => array( $cart_label, array( Confirm::class, 'render' ), __( 'Translation cart', 'perxel-ai-translate' ) ),
			self::PAGE_HISTORY   => array( __( 'History', 'perxel-ai-translate' ), array( $this, 'render_history' ), __( 'History', 'perxel-ai-translate' ) ),
			self::PAGE_ID_LOOKUP => array( __( 'ID lookup', 'perxel-ai-translate' ), array( $this, 'render_id_lookup' ), __( 'ID lookup', 'perxel-ai-translate' ) ),
			self::PAGE_SETTINGS  => array( __( 'Settings', 'perxel-ai-translate' ), array( $this, 'render_settings' ), __( 'Settings', 'perxel-ai-translate' ) ),
		);

		foreach ( $submenus as $slug => $entry ) {
			add_submenu_page( self::MENU, $entry[2] . ' - ' . PXAT_NAME, $entry[0], 'manage_options', $slug, $entry[1] );
		}

		// Flow screen - reachable mid-task, kept off the menu.
		add_submenu_page( null, __( 'Translation run', 'perxel-ai-translate' ), '', 'manage_options', self::PAGE_PROGRESS, array( Progress::class, 'render' ) );

		$titles = array(
			self::PAGE_DASHBOARD => __( 'Dashboard', 'perxel-ai-translate' ),
			self::PAGE_CONFIRM   => __( 'Translation cart', 'perxel-ai-translate' ),
			self::PAGE_HISTORY   => __( 'History', 'perxel-ai-translate' ),
			self::PAGE_ID_LOOKUP => __( 'ID lookup', 'perxel-ai-translate' ),
			self::PAGE_SETTINGS  => __( 'Settings', 'perxel-ai-translate' ),
			self::PAGE_PROGRESS  => __( 'Translation run', 'perxel-ai-translate' ),
		);

		if ( self::can_see_showcase() ) {
			add_submenu_page( null, 'Perxel UI', '', 'manage_options', self::PAGE_UI, array( $this, 'render_ui' ) );
			$titles[ self::PAGE_UI ] = 'Perxel UI';
		}

		if ( class_exists( 'Perxel_UI_Layout' ) ) {
			\Perxel_UI_Layout::set_page_titles( $titles, PXAT_NAME );
		}
	}

	/**
	 * Whether the current user may see the bundled UI-kit showcase - the
	 * maintainer only, and only in a build that still ships showcase/.
	 *
	 * @return bool
	 */
	public static function can_see_showcase() {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( 'Perxel_UI_Showcase' ) ) {
			return false;
		}
		$user = wp_get_current_user();
		return $user && ( 'phucbm' === $user->user_login || 'phucbm.dev@gmail.com' === strtolower( (string) $user->user_email ) );
	}

	/*
	---------------------------------------------------------------------
	 * Assets
	 * ------------------------------------------------------------------- */

	/**
	 * @param string $hook Current admin page hook.
	 */
	public function assets( $hook ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only screen switch.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		$ours = array(
			self::PAGE_DASHBOARD,
			self::PAGE_HISTORY,
			self::PAGE_ID_LOOKUP,
			self::PAGE_SETTINGS,
			self::PAGE_CONFIRM,
			self::PAGE_PROGRESS,
			self::PAGE_UI,
		);

		if ( ! in_array( $page, $ours, true ) ) {
			return;
		}

		if ( class_exists( 'Perxel_UI' ) ) {
			\Perxel_UI::enqueue();
		}

		$css = PXAT_DIR . '/assets/css/admin.css';
		wp_enqueue_style( 'pxat-admin', PXAT_URL . '/assets/css/admin.css', array( 'perxel-ui' ), file_exists( $css ) ? (string) filemtime( $css ) : PXAT_VERSION );

		$dep = array( 'perxel-ui', 'wp-i18n' );

		wp_register_script( 'pxat-format', PXAT_URL . '/assets/js/format.js', array( 'wp-i18n' ), $this->asset_ver( 'assets/js/format.js' ), true );

		switch ( $page ) {
			case self::PAGE_SETTINGS:
				$this->script( 'pxat-settings', 'assets/js/settings.js', $dep );
				wp_localize_script(
					'pxat-settings',
					'PXAT_Settings',
					array(
						'ajaxUrl' => admin_url( 'admin-ajax.php' ),
						'nonce'   => wp_create_nonce( self::NONCE ),
					)
				);
				break;

			case self::PAGE_ID_LOOKUP:
				$this->script( 'pxat-id-lookup', 'assets/js/id-lookup.js', $dep );
				break;

			case self::PAGE_CONFIRM:
				$this->script( 'pxat-confirm', 'assets/js/confirm.js', $dep );
				break;

			case self::PAGE_PROGRESS:
				wp_enqueue_script( 'pxat-format' );
				$this->script( 'pxat-progress', 'assets/js/progress.js', array_merge( $dep, array( 'pxat-format' ) ) );
				Progress::localize();
				break;
		}
	}

	protected function asset_ver( $rel ) {
		$abs = PXAT_DIR . '/' . $rel;
		return file_exists( $abs ) ? (string) filemtime( $abs ) : PXAT_VERSION;
	}

	protected function script( $handle, $rel, $deps ) {
		wp_enqueue_script( $handle, PXAT_URL . '/' . $rel, $deps, $this->asset_ver( $rel ), true );
		wp_set_script_translations( $handle, 'perxel-ai-translate', PXAT_DIR . '/languages' );
	}

	/*
	---------------------------------------------------------------------
	 * Layout
	 * ------------------------------------------------------------------- */

	protected function plugin_header() {
		static $header = null;
		if ( null === $header ) {
			$header = get_file_data(
				PXAT_FILE,
				array(
					'name'       => 'Plugin Name',
					'plugin_uri' => 'Plugin URI',
					'author'     => 'Author',
					'author_uri' => 'Author URI',
				),
				'plugin'
			);
		}
		return $header;
	}

	/**
	 * @param string $current Active sidebar slug.
	 * @param string $title   Page title.
	 * @param array  $extra   Extra layout args (e.g. actions).
	 * @return array
	 */
	public function layout_args( $current, $title, array $extra = array() ) {
		$header = $this->plugin_header();

		$pages = array(
			self::PAGE_DASHBOARD => __( 'Dashboard', 'perxel-ai-translate' ),
			self::PAGE_CONFIRM   => __( 'Translation cart', 'perxel-ai-translate' ),
			self::PAGE_HISTORY   => __( 'History', 'perxel-ai-translate' ),
			self::PAGE_ID_LOOKUP => __( 'ID lookup', 'perxel-ai-translate' ),
			self::PAGE_SETTINGS  => __( 'Settings', 'perxel-ai-translate' ),
		);

		if ( self::can_see_showcase() ) {
			$pages[ self::PAGE_UI ] = 'Perxel UI';
		}

		return array_merge(
			array(
				'title'       => $title,
				'plugin'      => PXAT_NAME,
				'version'     => PXAT_VERSION,
				'base'        => 'admin.php',
				'wrap_class'  => 'pxat',
				'current'     => $current,
				'menu'        => array( '' => $pages ),
				'links'       => array( __( 'Docs', 'perxel-ai-translate' ) => $header['plugin_uri'] ),
				'author'      => array(
					'name' => $header['author'],
					'url'  => $header['author_uri'],
				),
				'text_domain' => 'perxel-ai-translate',
			),
			$extra
		);
	}

	public function ui_ready() {
		return class_exists( 'Perxel_UI' ) && class_exists( 'Perxel_UI_Layout' );
	}

	/**
	 * Open the shared layout, include a view, close it. Falls back to a plain
	 * wrap if the kit failed to load.
	 *
	 * @param string $current Active sidebar slug.
	 * @param string $title   Page title.
	 * @param string $view    View file name under includes/views/.
	 * @param array  $vars    Variables extracted into the view scope.
	 * @param array  $extra   Extra layout args.
	 */
	public function screen( $current, $title, $view, array $vars = array(), array $extra = array() ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$path = PXAT_DIR . '/includes/views/' . $view . '.php';

		if ( ! $this->ui_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html( PXAT_NAME ) . '</h1>';
			echo '<div class="notice notice-error"><p>' . esc_html__( 'The shared Perxel UI library could not be loaded.', 'perxel-ai-translate' ) . '</p></div></div>';
			return;
		}

		\Perxel_UI_Layout::open( $this->layout_args( $current, $title, $extra ) );
		// phpcs:ignore WordPressVIPMinimum.Files.IncludingFile.UsingVariable -- fixed view path.
		( static function ( $__path, $__vars ) {
			// phpcs:ignore WordPress.PHP.DontExtract.extract_extract -- controlled view vars.
			extract( $__vars );
			include $__path;
		} )( $path, $vars );
		\Perxel_UI_Layout::close();
	}

	/*
	---------------------------------------------------------------------
	 * Screen render callbacks
	 * ------------------------------------------------------------------- */

	public function render_dashboard() {
		$this->screen(
			self::PAGE_DASHBOARD,
			__( 'Dashboard', 'perxel-ai-translate' ),
			'dashboard',
			Dashboard::data()
		);
	}

	public function render_history() {
		$this->screen(
			self::PAGE_HISTORY,
			__( 'History', 'perxel-ai-translate' ),
			'history',
			History::data()
		);
	}

	public function render_id_lookup() {
		$this->screen(
			self::PAGE_ID_LOOKUP,
			__( 'ID lookup', 'perxel-ai-translate' ),
			'id-lookup',
			IdLookup::data()
		);
	}

	public function render_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display-only flash flags set by our own redirect.
		$vars = array(
			'settings'    => Settings::all(),
			'model'       => Settings::model(),
			'environment' => self::environment(),
			'updated'     => isset( $_GET['updated'] ),
			'was_reset'   => isset( $_GET['reset'] ),
		);
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$save = get_submit_button(
			__( 'Save settings', 'perxel-ai-translate' ),
			'primary',
			'pxat-save',
			false,
			array( 'form' => 'pxat-settings-form' )
		);

		$this->screen(
			self::PAGE_SETTINGS,
			__( 'Settings', 'perxel-ai-translate' ),
			'settings',
			$vars,
			array( 'actions' => $save )
		);
	}

	public function render_ui() {
		if ( ! self::can_see_showcase() || ! $this->ui_ready() ) {
			return;
		}
		\Perxel_UI_Layout::open( $this->layout_args( self::PAGE_UI, 'Perxel UI' ) );
		\Perxel_UI_Showcase::body();
		\Perxel_UI_Layout::close();
	}

	/*
	---------------------------------------------------------------------
	 * Handlers
	 * ------------------------------------------------------------------- */

	public function handle_save_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_save_settings' );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce checked above; sanitised in Settings::sanitize().
		$raw = wp_unslash( $_POST );
		Settings::update( Settings::sanitize( is_array( $raw ) ? $raw : array() ) );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'    => self::PAGE_SETTINGS,
					'updated' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_reset_settings() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_reset_settings' );

		Settings::reset();

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'  => self::PAGE_SETTINGS,
					'reset' => '1',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function handle_cart_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_cart_remove' );

		$post_id = isset( $_REQUEST['post_id'] ) ? absint( wp_unslash( $_REQUEST['post_id'] ) ) : 0;
		if ( $post_id ) {
			Cart::remove( array( $post_id ) );
		}

		wp_safe_redirect( Cart::url() );
		exit;
	}

	public function handle_cart_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}
		check_admin_referer( 'pxat_cart_clear' );

		Cart::clear();

		wp_safe_redirect( Cart::url() );
		exit;
	}

	public function handle_delete_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
		check_admin_referer( 'pxat_delete_run_' . $run_id );

		if ( $run_id ) {
			Runs::delete( $run_id );
		}

		wp_safe_redirect( add_query_arg( array( 'page' => self::PAGE_HISTORY ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public function handle_cancel_run() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'perxel-ai-translate' ) );
		}

		$run_id = isset( $_REQUEST['run_id'] ) ? absint( wp_unslash( $_REQUEST['run_id'] ) ) : 0;
		check_admin_referer( 'pxat_cancel_run_' . $run_id );

		$run = $run_id ? Runs::get( $run_id ) : null;
		if ( $run ) {
			global $wpdb;
			$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					'UPDATE ' . Db::items() . " SET status = 'skipped', error_message = %s, updated_at = %s
					 WHERE run_id = %d AND status IN ('pending','translating')",
					__( 'Run cancelled.', 'perxel-ai-translate' ),
					current_time( 'mysql' ),
					$run_id
				)
			);
			Runs::set_status( $run_id, 'stopped' );
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => self::PAGE_PROGRESS,
					'run_id' => $run_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function ajax_test_api_key() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'perxel-ai-translate' ) ), 403 );
		}

		$api_key = isset( $_POST['api_key'] ) ? sanitize_text_field( wp_unslash( $_POST['api_key'] ) ) : '';
		$result  = OpenRouter::test_api_key( $api_key );

		if ( is_wp_error( $result ) ) {
			// The stored key is only "verified" as long as it stays the tested one.
			if ( Settings::api_key() === $api_key ) {
				Settings::update( array( 'api_key_verified' => false ) );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// A pass means "this key works" - remember it and mark it verified.
		Settings::update(
			array(
				'api_key'          => $api_key,
				'api_key_verified' => true,
			)
		);

		wp_send_json_success(
			array(
				'message' => __( 'API key is valid.', 'perxel-ai-translate' ),
				'label'   => $result['label'] ?? '',
				'usage'   => $result['usage'] ?? null,
				'limit'   => $result['limit'] ?? null,
			)
		);
	}

	public function ajax_test_model() {
		check_ajax_referer( self::NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You are not allowed to do this.', 'perxel-ai-translate' ) ), 403 );
		}

		$model_id = isset( $_POST['model_id'] ) ? sanitize_text_field( wp_unslash( $_POST['model_id'] ) ) : '';
		$result   = OpenRouter::test_model( $model_id );

		if ( is_wp_error( $result ) ) {
			if ( Settings::model()['id'] === $model_id ) {
				Settings::update( array( 'model_verified' => false ) );
			}
			wp_send_json_error( array( 'message' => $result->get_error_message() ) );
		}

		// Remember the model and its live pricing / limits.
		Settings::update(
			array(
				'model_id'         => $result['id'],
				'model_verified'   => true,
				'model_label'      => $result['label'],
				'model_input'      => $result['input'],
				'model_output'     => $result['output'],
				'model_context'    => $result['context'],
				'model_max_output' => $result['max_output'],
			)
		);

		wp_send_json_success(
			array(
				'id'         => $result['id'],
				'label'      => $result['label'],
				'input'      => $result['input'],
				'output'     => $result['output'],
				'context'    => $result['context'],
				'max_output' => $result['max_output'],
				'summary'    => sprintf(
					/* translators: 1: model name, 2: input price, 3: output price, 4: context length. */
					__( '%1$s - $%2$s in / $%3$s out per 1M tokens · %4$s context', 'perxel-ai-translate' ),
					$result['label'],
					rtrim( rtrim( number_format( $result['input'], 4 ), '0' ), '.' ),
					rtrim( rtrim( number_format( $result['output'], 4 ), '0' ), '.' ),
					$result['context'] ? number_format_i18n( $result['context'] ) . ' tokens' : __( 'unknown', 'perxel-ai-translate' )
				),
			)
		);
	}

	/**
	 * Read-only environment snapshot for the Settings screen's Environment
	 * section - the equivalents of "can this plugin do its job".
	 *
	 * @return array
	 */
	public static function environment() {
		$languages = Wpml::get_active_languages();
		$settings  = Settings::all();

		return array(
			'wpml_active'   => defined( 'ICL_SITEPRESS_VERSION' ),
			'wpml_version'  => defined( 'ICL_SITEPRESS_VERSION' ) ? ICL_SITEPRESS_VERSION : '',
			'lang_count'    => count( $languages ),
			'default_lang'  => (string) Wpml::get_default_language(),
			'api_key_set'   => Settings::has_api_key(),
			'api_key_ok'    => ! empty( $settings['api_key_verified'] ),
			'model_id'      => Settings::model()['id'],
			'model_ok'      => Settings::model_verified(),
			'php_version'   => PHP_VERSION,
			'max_execution' => (int) ini_get( 'max_execution_time' ),
			'acf'           => class_exists( 'ACF' ) || function_exists( 'get_field' ),
			'rankmath'      => defined( 'RANK_MATH_VERSION' ),
		);
	}
}
