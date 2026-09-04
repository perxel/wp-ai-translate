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

		add_action( 'wp_ajax_pxat_test_api_key', array( $this, 'ajax_test_api_key' ) );
		add_action( 'wp_ajax_pxat_test_model', array( $this, 'ajax_test_model' ) );
		add_action( 'wp_ajax_pxat_process', array( Progress::class, 'ajax_process' ) );
		add_action( 'wp_ajax_pxat_retry', array( Progress::class, 'ajax_retry' ) );
		add_action( 'wp_ajax_pxat_status', array( Progress::class, 'ajax_status' ) );
		add_action( 'wp_ajax_pxat_resume', array( Progress::class, 'ajax_resume' ) );
	}

	/*
	---------------------------------------------------------------------
	 * Menu
	 * ------------------------------------------------------------------- */

	public function menu() {
		// One entry under Tools; the other screens are reached from the kit's
		// in-page nav, so they are registered off the menu but still render.
		add_management_page(
			PXAT_NAME,
			__( 'AI Translate', 'perxel-ai-translate' ),
			'manage_options',
			self::PAGE_DASHBOARD,
			array( $this, 'render_dashboard' )
		);

		add_submenu_page( null, __( 'History', 'perxel-ai-translate' ) . ' - ' . PXAT_NAME, '', 'manage_options', self::PAGE_HISTORY, array( $this, 'render_history' ) );
		add_submenu_page( null, __( 'ID lookup', 'perxel-ai-translate' ) . ' - ' . PXAT_NAME, '', 'manage_options', self::PAGE_ID_LOOKUP, array( $this, 'render_id_lookup' ) );
		add_submenu_page( null, __( 'Settings', 'perxel-ai-translate' ) . ' - ' . PXAT_NAME, '', 'manage_options', self::PAGE_SETTINGS, array( $this, 'render_settings' ) );

		// Confirm + run screens - off the menu, reached mid-task via redirect
		// from the bulk action / admin bar / re-run, but still render on visit.
		add_submenu_page( null, __( 'Translation', 'perxel-ai-translate' ) . ' - ' . PXAT_NAME, '', 'manage_options', self::PAGE_CONFIRM, array( Confirm::class, 'render' ) );
		add_submenu_page( null, __( 'Translation run', 'perxel-ai-translate' ), '', 'manage_options', self::PAGE_PROGRESS, array( Progress::class, 'render' ) );

		$titles = array(
			self::PAGE_DASHBOARD => __( 'Dashboard', 'perxel-ai-translate' ),
			self::PAGE_CONFIRM   => __( 'Translation', 'perxel-ai-translate' ),
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
			History::data(),
			array( 'wrap_class' => 'pxat pxat-wide' )
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
			'settings'      => Settings::all(),
			'model'         => Settings::model(),
			'environment'   => self::environment(),
			'compatibility' => self::compatibility(),
			'benchmark'     => self::homepage_benchmark(),
			'updated'       => isset( $_GET['updated'] ),
			'was_reset'     => isset( $_GET['reset'] ),
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

		// Cancelling ends the run; History is where ended runs live (the run
		// screen would read the all-skipped run as "Complete").
		wp_safe_redirect(
			add_query_arg( array( 'page' => self::PAGE_HISTORY ), admin_url( 'admin.php' ) )
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

		$limit     = isset( $result['limit'] ) && null !== $result['limit'] ? (float) $result['limit'] : 0.0;
		$usage     = isset( $result['usage'] ) ? (float) $result['usage'] : 0.0;
		$remaining = isset( $result['limit_remaining'] ) && null !== $result['limit_remaining']
			? (float) $result['limit_remaining']
			: max( 0.0, $limit - $usage );

		// A pass means "this key works" - remember it, mark it verified, and keep
		// the credit figures so the Settings row can show them without re-testing.
		Settings::update(
			array(
				'api_key'           => $api_key,
				'api_key_verified'  => true,
				'api_key_limit'     => $limit,
				'api_key_remaining' => $limit > 0 ? $remaining : 0.0,
			)
		);

		$message = $limit > 0
			? sprintf(
				/* translators: 1: USD credit left, 2: USD credit limit, e.g. "$37.66 left of $50.00". */
				__( 'Verified · %1$s left of %2$s', 'perxel-ai-translate' ),
				Format::money_usd( $remaining ),
				Format::money_usd( $limit )
			)
			: __( 'Verified', 'perxel-ai-translate' );

		wp_send_json_success(
			array(
				'message' => $message,
				'tone'    => Settings::api_key_tone(),
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
			'wpml_tested'   => '4.9.7',
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

	/**
	 * The plugins this plugin is built and tested against. Every entry is shown
	 * on the Settings screen whether or not it is installed - a check just means
	 * it is active here. Each carries the version we last verified a run against
	 * ('tested'); 'version' is whatever is live on this site. WPML is the hard
	 * dependency and lives in the Environment block, not this list.
	 *
	 * @return array[] Each: [ name, tested (string), active (bool), version (string), note ].
	 */
	public static function compatibility() {
		$acf_active = class_exists( 'ACF' ) || function_exists( 'get_field' );
		$rm_active  = defined( 'RANK_MATH_VERSION' );
		$rmp_active = defined( 'RANK_MATH_PRO_VERSION' );
		$vc_active  = defined( 'WPB_VC_VERSION' );

		return array(
			array(
				'name'    => __( 'Advanced Custom Fields / Secure Custom Fields', 'perxel-ai-translate' ),
				'tested'  => '6.9.5',
				'active'  => $acf_active,
				'version' => defined( 'ACF_VERSION' ) ? ACF_VERSION : '',
				'note'    => __( 'Text, textarea and WYSIWYG fields are translated; other field types are copied to the translation as-is.', 'perxel-ai-translate' ),
			),
			array(
				'name'    => __( 'Rank Math SEO', 'perxel-ai-translate' ),
				'tested'  => '1.0.277.2',
				'active'  => $rm_active,
				'version' => $rm_active ? RANK_MATH_VERSION : '',
				'note'    => __( 'SEO title, description, focus keyword and Facebook / X social meta.', 'perxel-ai-translate' ),
			),
			array(
				'name'    => __( 'Rank Math SEO PRO', 'perxel-ai-translate' ),
				'tested'  => '3.0.82',
				'active'  => $rmp_active,
				'version' => $rmp_active ? RANK_MATH_PRO_VERSION : '',
				'note'    => __( 'Runs alongside the Pro add-on; the same Rank Math meta fields are translated.', 'perxel-ai-translate' ),
			),
			array(
				'name'    => __( 'WPBakery Page Builder', 'perxel-ai-translate' ),
				'tested'  => '9.0.1',
				'active'  => $vc_active,
				'version' => $vc_active ? WPB_VC_VERSION : '',
				'note'    => __( 'Builder shortcodes such as [vc_row] are kept intact; only the readable text inside them is translated.', 'perxel-ai-translate' ),
			),
		);
	}

	/**
	 * A fixed cost benchmark for the Settings screen: what it costs to translate
	 * the site's front page once at the current model's verified rates. Same
	 * input text for every model, so switching models moves only the price.
	 *
	 * Local math only - no API call. Returns null when there is nothing to
	 * price against (no verified model) or no front-page content to sample.
	 *
	 * @return array|null [ words, prompt_tokens, completion_tokens, cost_per_lang ].
	 */
	public static function homepage_benchmark() {
		$model = Settings::model();

		if ( ! Settings::model_verified() || ! Settings::has_api_key() || (float) $model['input'] <= 0 ) {
			return null;
		}

		$post = self::front_page_post();
		if ( ! $post ) {
			return null;
		}

		$payload = array(
			'post_title'   => (string) $post->post_title,
			'post_content' => (string) $post->post_content,
		);

		$system_chars = mb_strlen( OpenRouter::build_system_prompt( Settings::get( 'prompt' ), 'en', 'xx' ) );
		$estimate     = OpenRouter::estimate_job_tokens( $payload, $system_chars );

		$cost = OpenRouter::estimate_cost(
			$estimate['prompt_tokens'],
			$estimate['completion_tokens'],
			$model['input'],
			$model['output']
		);

		return array(
			'words'             => Format::tokens_to_words( $estimate['prompt_tokens'] + $estimate['completion_tokens'] ),
			'prompt_tokens'     => $estimate['prompt_tokens'],
			'completion_tokens' => $estimate['completion_tokens'],
			'cost_per_lang'     => $cost,
		);
	}

	/**
	 * The post behind the site's front page: the static front page if one is
	 * set, otherwise the most recent published post. Null when neither exists.
	 *
	 * @return \WP_Post|null
	 */
	protected static function front_page_post() {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$front_id = (int) get_option( 'page_on_front' );
			$post     = $front_id ? get_post( $front_id ) : null;
			if ( $post instanceof \WP_Post ) {
				return $post;
			}
		}

		$recent = get_posts(
			array(
				'numberposts'      => 1,
				'post_status'      => 'publish',
				'suppress_filters' => true,
			)
		);

		return $recent ? $recent[0] : null;
	}
}
