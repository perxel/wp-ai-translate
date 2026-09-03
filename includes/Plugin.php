<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Boot: wire the admin surface and entry points, keep the schema current.
 * Instantiated once from the main file after WPML is confirmed active.
 */
class Plugin {

	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Admin
	 */
	private $admin;

	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function admin() {
		return $this->admin;
	}

	public function boot() {
		// Runs for every admin request including admin-ajax; cheap option read.
		add_action(
			'init',
			static function () {
				if ( is_admin() ) {
					Db::maybe_upgrade();
				}
			}
		);

		$this->admin = new Admin();
		$this->admin->register();

		( new BulkAction() )->register();
		( new AdminBar() )->register();
	}

	/**
	 * Activation: create the custom tables.
	 */
	public static function activate() {
		Db::install();
	}
}
