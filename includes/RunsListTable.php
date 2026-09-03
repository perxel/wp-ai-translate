<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Translation-run history, native WP admin table chrome (checkboxes, bulk
 * actions, pagination — all core admin JS).
 */
class RunsListTable extends \WP_List_Table {

	const PER_PAGE = 20;

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'run',
				'plural'   => 'runs',
				'ajax'     => false,
			)
		);
	}

	public function get_columns() {
		return array(
			'cb'       => '<input type="checkbox" />',
			'run'      => __( 'Run', 'perxel-ai-translate' ),
			'started'  => __( 'Started', 'perxel-ai-translate' ),
			'run_time' => __( 'Run time', 'perxel-ai-translate' ),
			'langs'    => __( 'Languages', 'perxel-ai-translate' ),
			'model'    => __( 'Model', 'perxel-ai-translate' ),
			'posts'    => __( 'Posts', 'perxel-ai-translate' ),
			'done'     => __( 'Done', 'perxel-ai-translate' ),
			'issues'   => __( 'Issues', 'perxel-ai-translate' ),
			'mode'     => __( 'Scope', 'perxel-ai-translate' ),
			'tokens'   => __( 'Volume', 'perxel-ai-translate' ),
			'cost'     => __( 'Cost', 'perxel-ai-translate' ),
			'by'       => __( 'By', 'perxel-ai-translate' ),
		);
	}

	public function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'perxel-ai-translate' ) );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="run_ids[]" value="%d" />', (int) $item['id'] );
	}

	protected function column_run( $item ) {
		$url = add_query_arg(
			array(
				'page'   => Admin::PAGE_PROGRESS,
				'run_id' => $item['id'],
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'action' => 'pxat_delete_run',
					'run_id' => $item['id'],
				),
				admin_url( 'admin-post.php' )
			),
			'pxat_delete_run_' . $item['id']
		);

		$actions = array(
			'open'   => '<a href="' . esc_url( $url ) . '">' . esc_html__( 'Open', 'perxel-ai-translate' ) . '</a>',
			'delete' => '<a href="' . esc_url( $delete_url ) . '" data-pxui-confirm="' . esc_attr__( 'Delete this run? This cannot be undone.', 'perxel-ai-translate' ) . '">' . esc_html__( 'Delete', 'perxel-ai-translate' ) . '</a>',
		);

		return '<a href="' . esc_url( $url ) . '"><strong>#' . (int) $item['id'] . '</strong></a>' . $this->row_actions( $actions );
	}

	protected function column_default( $item, $column_name ) {
		return isset( $item[ $column_name ] ) ? $item[ $column_name ] : '';
	}

	public function prepare_items() {
		$this->_column_headers = array( $this->get_columns(), array(), array() );

		$page      = max( 1, (int) $this->get_pagenum() );
		$total     = Runs::count_runs();
		$languages = Wpml::get_active_languages();

		$items = array();

		foreach ( Runs::list_runs( self::PER_PAGE, ( $page - 1 ) * self::PER_PAGE ) as $run ) {
			$counts = Runs::counts( $run['id'] );

			$issues = array();
			if ( $counts['error'] > 0 ) {
				$errors_phrase = sprintf(
					/* translators: %s: number of errors. */
					_n( '%s error', '%s errors', $counts['error'], 'perxel-ai-translate' ),
					number_format_i18n( $counts['error'] )
				);
				$issues[] = '<span class="pxat-inline-error">' . esc_html( $errors_phrase ) . '</span>';
			}
			if ( $counts['warnings'] > 0 ) {
				$warnings_phrase = sprintf(
					/* translators: %s: number of warnings. */
					_n( '%s warning', '%s warnings', $counts['warnings'], 'perxel-ai-translate' ),
					number_format_i18n( $counts['warnings'] )
				);
				$issues[] = '<span class="pxat-inline-warning">' . esc_html( $warnings_phrase ) . '</span>';
			}

			$scope = 'custom' === $run['data_mode']
				? sprintf(
					/* translators: %s: comma-separated data type labels. */
					__( 'Custom (%s)', 'perxel-ai-translate' ),
					implode( ', ', array_map( array( 'Perxel\AITranslate\Translator', 'type_label' ), $run['custom_types'] ) )
				)
				: __( 'Full', 'perxel-ai-translate' );
			if ( $run['batched'] ) {
				$scope .= ' · ' . __( 'batched', 'perxel-ai-translate' );
			}

			$items[] = array(
				'id'       => $run['id'],
				'started'  => Format::time_ago( $run['created_at'] ),
				'run_time' => Format::duration( $run['active_seconds'] ),
				'langs'    => esc_html( Wpml::language_label( $languages, $run['source_lang'] ) . ' → ' . Wpml::language_label( $languages, $run['dest_lang'] ) ),
				'model'    => esc_html( OpenRouter::get_model( $run['model'] )['label'] ),
				'posts'    => number_format_i18n( $counts['total'] ),
				'done'     => number_format_i18n( $counts['done'] ) . ' / ' . number_format_i18n( $counts['total'] ),
				'issues'   => $issues ? implode( ' ', $issues ) : '&mdash;',
				'mode'     => esc_html( $scope ),
				'tokens'   => esc_html( Format::unit_label( $counts['prompt_tokens'] + $counts['completion_tokens'] ) ),
				'cost'     => esc_html( Format::cost( $counts['cost_usd'] ) ),
				'by'       => esc_html( $run['created_by_name'] ),
			);
		}

		$this->items = $items;
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => self::PER_PAGE,
			)
		);
	}
}
