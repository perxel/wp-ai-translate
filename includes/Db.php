<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom-table schema for translation runs. Replaces the old per-batch JSON
 * files under uploads/. Three tables: one row per run, one per post in a run,
 * one per log line.
 *
 * Direct $wpdb is deliberate and standard for a plugin-owned table; every query
 * here and in Runs goes through $wpdb->prepare(). No caching layer - a live run
 * needs fresh reads on every poll.
 */
class Db {

	const SCHEMA_VERSION = 2;
	const VERSION_OPTION = 'pxat_db_version';

	public static function runs() {
		global $wpdb;
		return $wpdb->prefix . 'pxat_runs';
	}

	public static function items() {
		global $wpdb;
		return $wpdb->prefix . 'pxat_run_items';
	}

	public static function logs() {
		global $wpdb;
		return $wpdb->prefix . 'pxat_run_log';
	}

	/**
	 * Create/upgrade the tables. Safe to call repeatedly (dbDelta diff-applies).
	 */
	public static function install() {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$runs            = self::runs();
		$items           = self::items();
		$logs            = self::logs();

		dbDelta(
			"CREATE TABLE {$runs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				created_by BIGINT UNSIGNED NOT NULL DEFAULT 0,
				created_by_name VARCHAR(191) NOT NULL DEFAULT '',
				model VARCHAR(191) NOT NULL DEFAULT '',
				model_label VARCHAR(191) NOT NULL DEFAULT '',
				input_rate DECIMAL(14,6) NOT NULL DEFAULT 0,
				output_rate DECIMAL(14,6) NOT NULL DEFAULT 0,
				max_output INT UNSIGNED NOT NULL DEFAULT 0,
				source_lang VARCHAR(20) NOT NULL DEFAULT '',
				dest_lang VARCHAR(20) NOT NULL DEFAULT '',
				data_mode VARCHAR(20) NOT NULL DEFAULT 'full',
				custom_types VARCHAR(255) NOT NULL DEFAULT '',
				batched TINYINT(1) NOT NULL DEFAULT 0,
				status VARCHAR(20) NOT NULL DEFAULT 'running',
				active_seconds DOUBLE NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY created_at (created_at)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$items} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id BIGINT UNSIGNED NOT NULL,
				status VARCHAR(20) NOT NULL DEFAULT 'pending',
				source_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				dest_post_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				post_type VARCHAR(50) NOT NULL DEFAULT '',
				action VARCHAR(10) NOT NULL DEFAULT 'create',
				error_message TEXT NULL,
				prompt_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				completion_tokens INT UNSIGNED NOT NULL DEFAULT 0,
				cost_usd DECIMAL(14,6) NOT NULL DEFAULT 0,
				has_warning TINYINT(1) NOT NULL DEFAULT 0,
				has_apply_error TINYINT(1) NOT NULL DEFAULT 0,
				results LONGTEXT NULL,
				payload LONGTEXT NULL,
				worker VARCHAR(40) NOT NULL DEFAULT '',
				claimed_at DATETIME NULL,
				created_at DATETIME NOT NULL,
				updated_at DATETIME NOT NULL,
				PRIMARY KEY  (id),
				KEY run_status (run_id, status),
				KEY run_id (run_id)
			) {$charset_collate};"
		);

		dbDelta(
			"CREATE TABLE {$logs} (
				id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
				run_id BIGINT UNSIGNED NOT NULL,
				item_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
				logged_at DATETIME NOT NULL,
				logged_by VARCHAR(191) NOT NULL DEFAULT '',
				message TEXT NOT NULL,
				PRIMARY KEY  (id),
				KEY run_id (run_id)
			) {$charset_collate};"
		);

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/**
	 * Run install() when the stored schema version is behind. Cheap option read;
	 * called on admin_init.
	 */
	public static function maybe_upgrade() {
		if ( (int) get_option( self::VERSION_OPTION, 0 ) < self::SCHEMA_VERSION ) {
			self::install();
		}
	}

	/**
	 * Drop every table and the version option. Called from uninstall.php.
	 */
	public static function uninstall() {
		global $wpdb;

		foreach ( array( self::logs(), self::items(), self::runs() ) as $table ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a plugin constant, not user input.
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		}

		delete_option( self::VERSION_OPTION );
	}
}
