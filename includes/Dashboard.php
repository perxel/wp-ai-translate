<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dashboard: the landing screen. Shows setup state, any unfinished run, and
 * all-time totals. Posts are picked for translation from the post-list bulk
 * action or a post's "Translate this page" bar item - there is no picker here.
 */
class Dashboard {

	/**
	 * @return array View variables.
	 */
	public static function data() {
		$languages    = Wpml::get_active_languages();
		$has_api_key  = Settings::has_api_key();
		$enough_langs = count( $languages ) >= 2;

		return array(
			'state'         => ( $has_api_key && $enough_langs ) ? 'ready' : 'needs_setup',
			'has_api_key'   => $has_api_key,
			'enough_langs'  => $enough_langs,
			'settings_url'  => admin_url( 'admin.php?page=' . Admin::PAGE_SETTINGS ),
			'post_types'    => PostTypes::labelled(),
			'active_run_id' => Runs::active_run_id(),
			'totals'        => Runs::totals(),
			'recent'        => Runs::list_runs( 5 ),
			'languages'     => $languages,
			'default_lang'  => Wpml::get_default_language(),
		);
	}
}
