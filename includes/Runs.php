<?php

namespace Perxel\AITranslate;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Repository for translation runs, their per-post items and their log lines.
 * The only place that touches the pxat_* tables. Items are returned as flat
 * associative arrays with the JSON `payload` merged in (fields / before /
 * preview), so callers see one shape.
 *
 * Concurrency: claim_ids() flips rows to 'translating' in one atomic UPDATE, so
 * parallel batched workers can never take the same item; with_write_lock() wraps
 * the WordPress-write phase in a MySQL named lock so two workers never create a
 * destination post or resolve taxonomy at the same moment.
 */
class Runs {

	// Keys stored inside the item `payload` JSON column, exposed flat on the
	// hydrated item. Everything else is a real column.
	const PAYLOAD_KEYS = array( 'fields', 'before', 'preview' );

	const WRITE_LOCK            = 'pxat_ai_translate_write';
	const STALE_SECONDS         = 90;
	const STALE_SECONDS_BATCHED = 300;

	// Grace an explicit Resume gives an in-flight row before it requeues it: long
	// enough not to steal a row a parallel worker only just claimed, short enough
	// that a genuinely stuck run recovers on the first click.
	const RESUME_GRACE_SECONDS = 30;

	/*
	---------------------------------------------------------------------
	 * Runs
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $data source_lang, dest_lang, data_mode, custom_types (array),
	 *              batched (bool), and the model snapshot: model, model_label,
	 *              input_rate, output_rate, max_output.
	 * @return int New run id.
	 */
	public static function create( array $data ) {
		global $wpdb;

		$user = wp_get_current_user();
		$now  = current_time( 'mysql' );

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Db::runs(),
			array(
				'created_at'      => $now,
				'updated_at'      => $now,
				'created_by'      => $user ? (int) $user->ID : 0,
				'created_by_name' => $user && $user->exists() ? $user->display_name : '',
				'model'           => (string) ( $data['model'] ?? '' ),
				'model_label'     => (string) ( $data['model_label'] ?? '' ),
				'input_rate'      => (float) ( $data['input_rate'] ?? 0 ),
				'output_rate'     => (float) ( $data['output_rate'] ?? 0 ),
				'max_output'      => (int) ( $data['max_output'] ?? 0 ),
				'source_lang'     => (string) ( $data['source_lang'] ?? '' ),
				'dest_lang'       => (string) ( $data['dest_lang'] ?? '' ),
				'data_mode'       => 'custom' === ( $data['data_mode'] ?? 'full' ) ? 'custom' : 'full',
				'custom_types'    => implode( ',', (array) ( $data['custom_types'] ?? array() ) ),
				'batched'         => ! empty( $data['batched'] ) ? 1 : 0,
				'status'          => 'running',
				'active_seconds'  => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%s', '%f', '%f', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%f' )
		);

		return (int) $wpdb->insert_id;
	}

	/**
	 * @param int   $run_id Run id.
	 * @param array $items  Each: source_post_id, dest_post_id, post_type, action, status, error_message, fields, before.
	 */
	public static function add_items( $run_id, array $items ) {
		global $wpdb;

		$now = current_time( 'mysql' );

		foreach ( $items as $item ) {
			$payload = array(
				'fields'  => $item['fields'] ?? array(),
				'before'  => $item['before'] ?? array(),
				'preview' => array(),
			);

			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				Db::items(),
				array(
					'run_id'         => $run_id,
					'status'         => (string) ( $item['status'] ?? 'pending' ),
					'source_post_id' => (int) ( $item['source_post_id'] ?? 0 ),
					'dest_post_id'   => (int) ( $item['dest_post_id'] ?? 0 ),
					'post_type'      => (string) ( $item['post_type'] ?? '' ),
					'action'         => 'update' === ( $item['action'] ?? 'create' ) ? 'update' : 'create',
					'error_message'  => isset( $item['error_message'] ) ? (string) $item['error_message'] : null,
					'results'        => wp_json_encode( array() ),
					'payload'        => wp_json_encode( $payload, JSON_UNESCAPED_UNICODE ),
					'created_at'     => $now,
					'updated_at'     => $now,
				),
				array( '%d', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
		}
	}

	/**
	 * @param array $row Raw run row (ARRAY_A).
	 * @return array Typed run row, custom_types as an array.
	 */
	protected static function hydrate_run( array $row ) {
		$row['id']             = (int) $row['id'];
		$row['batched']        = (bool) $row['batched'];
		$row['active_seconds'] = (float) $row['active_seconds'];
		$row['input_rate']     = (float) ( $row['input_rate'] ?? 0 );
		$row['output_rate']    = (float) ( $row['output_rate'] ?? 0 );
		$row['max_output']     = (int) ( $row['max_output'] ?? 0 );
		$row['custom_types']   = '' !== $row['custom_types'] ? explode( ',', $row['custom_types'] ) : array();
		return $row;
	}

	/**
	 * @param int $run_id Run id.
	 * @return array|null Typed run row.
	 */
	public static function get( $run_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM ' . Db::runs() . ' WHERE id = %d', $run_id ),
			ARRAY_A
		);

		return $row ? self::hydrate_run( $row ) : null;
	}

	/**
	 * @param int $limit  Max rows.
	 * @param int $offset Offset.
	 * @return array Typed run rows, newest first.
	 */
	public static function list_runs( $limit = 50, $offset = 0 ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . Db::runs() . ' ORDER BY id DESC LIMIT %d OFFSET %d',
				$limit,
				$offset
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate_run' ), $rows ? $rows : array() );
	}

	public static function count_runs() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Db::runs() );
	}

	public static function set_status( $run_id, $status ) {
		global $wpdb;
		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Db::runs(),
			array(
				'status'     => $status,
				'updated_at' => current_time( 'mysql' ),
			),
			array( 'id' => $run_id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Flip a run to 'done' once no item is left pending or translating.
	 *
	 * @param int $run_id Run id.
	 * @return bool Whether the run is now finished.
	 */
	public static function maybe_finish( $run_id ) {
		$counts = self::counts( $run_id );
		$done   = $counts['total'] > 0 && 0 === $counts['pending'] && 0 === $counts['translating'];

		if ( $done ) {
			$run = self::get( $run_id );
			if ( $run && 'done' !== $run['status'] ) {
				self::set_status( $run_id, 'done' );
			}
		}

		return $done;
	}

	public static function delete( $run_id ) {
		global $wpdb;

		$wpdb->delete( Db::logs(), array( 'run_id' => $run_id ), array( '%d' ) );  // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( Db::items(), array( 'run_id' => $run_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (bool) $wpdb->delete( Db::runs(), array( 'id' => $run_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	public static function add_active_seconds( $run_id, $seconds ) {
		global $wpdb;

		if ( $seconds <= 0 ) {
			return;
		}

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE ' . Db::runs() . ' SET active_seconds = active_seconds + %f, updated_at = %s WHERE id = %d',
				$seconds,
				current_time( 'mysql' ),
				$run_id
			)
		);
	}

	public static function duration_seconds( $run_id ) {
		$run = self::get( $run_id );
		return $run ? (float) $run['active_seconds'] : 0.0;
	}

	/*
	---------------------------------------------------------------------
	 * Items
	 * ------------------------------------------------------------------- */

	/**
	 * @param array $row Raw DB row (ARRAY_A).
	 * @return array Flat item: columns + fields/before/preview + results.
	 */
	protected static function hydrate( array $row ) {
		$payload = json_decode( (string) $row['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();
		$results = json_decode( (string) $row['results'], true );

		$item = $row;
		unset( $item['payload'] );

		$item['id']                = (int) $row['id'];
		$item['run_id']            = (int) $row['run_id'];
		$item['source_post_id']    = (int) $row['source_post_id'];
		$item['dest_post_id']      = (int) $row['dest_post_id'];
		$item['prompt_tokens']     = (int) $row['prompt_tokens'];
		$item['completion_tokens'] = (int) $row['completion_tokens'];
		$item['cost_usd']          = (float) $row['cost_usd'];
		$item['has_warning']       = (bool) $row['has_warning'];
		$item['has_apply_error']   = (bool) $row['has_apply_error'];
		$item['results']           = is_array( $results ) ? $results : array();
		$item['fields']            = $payload['fields'] ?? array();
		$item['before']            = $payload['before'] ?? array();
		$item['preview']           = $payload['preview'] ?? array();

		return $item;
	}

	/**
	 * @param int $run_id Run id.
	 * @return array Hydrated items in id order.
	 */
	public static function items( $run_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM ' . Db::items() . ' WHERE run_id = %d ORDER BY id ASC', $run_id ),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/**
	 * @param int $item_id Item id.
	 * @return array|null Hydrated item.
	 */
	public static function item( $item_id ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT * FROM ' . Db::items() . ' WHERE id = %d', $item_id ),
			ARRAY_A
		);

		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Apply a set of changes to one item. Column keys go to columns; the keys in
	 * PAYLOAD_KEYS are merged into the JSON payload; `results` is re-encoded.
	 *
	 * @param int   $item_id Item id.
	 * @param array $changes Field => value.
	 * @return array|null Hydrated item after the write.
	 */
	public static function update_item( $item_id, array $changes ) {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare( 'SELECT payload FROM ' . Db::items() . ' WHERE id = %d', $item_id ),
			ARRAY_A
		);
		if ( ! $row ) {
			return null;
		}

		$payload = json_decode( (string) $row['payload'], true );
		$payload = is_array( $payload ) ? $payload : array();

		$data    = array();
		$formats = array();

		foreach ( $changes as $key => $value ) {
			if ( in_array( $key, self::PAYLOAD_KEYS, true ) ) {
				$payload[ $key ] = $value;
				continue;
			}
			if ( 'results' === $key ) {
				$data['results'] = wp_json_encode( $value, JSON_UNESCAPED_UNICODE );
				$formats[]       = '%s';
				continue;
			}
			$data[ $key ] = $value;
			$formats[]    = self::column_format( $key );
		}

		$data['payload']    = wp_json_encode( $payload, JSON_UNESCAPED_UNICODE );
		$formats[]          = '%s';
		$data['updated_at'] = current_time( 'mysql' );
		$formats[]          = '%s';

		$wpdb->update( Db::items(), $data, array( 'id' => $item_id ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return self::item( $item_id );
	}

	/**
	 * @param array $changes_by_id item_id => changes array.
	 * @return array Hydrated items that were changed.
	 */
	public static function update_items( array $changes_by_id ) {
		$out = array();
		foreach ( $changes_by_id as $item_id => $changes ) {
			$updated = self::update_item( (int) $item_id, $changes );
			if ( $updated ) {
				$out[] = $updated;
			}
		}
		return $out;
	}

	protected static function column_format( $key ) {
		$ints   = array( 'run_id', 'source_post_id', 'dest_post_id', 'prompt_tokens', 'completion_tokens', 'has_warning', 'has_apply_error' );
		$floats = array( 'cost_usd' );

		if ( in_array( $key, $ints, true ) ) {
			return '%d';
		}
		if ( in_array( $key, $floats, true ) ) {
			return '%f';
		}
		return '%s';
	}

	/*
	---------------------------------------------------------------------
	 * Claiming / stale reclaim
	 * ------------------------------------------------------------------- */

	protected static function stale_seconds( $run_id ) {
		$run = self::get( $run_id );
		return ( $run && $run['batched'] ) ? self::STALE_SECONDS_BATCHED : self::STALE_SECONDS;
	}

	/**
	 * Requeue items left 'translating' by a request that never finished.
	 *
	 * @param int      $run_id        Run id.
	 * @param int|null $grace_seconds Age a row must have before it is requeued;
	 *                                null uses the per-run stale window.
	 * @return int Rows requeued.
	 */
	public static function reclaim_stale( $run_id, $grace_seconds = null ) {
		global $wpdb;

		$grace     = null === $grace_seconds ? self::stale_seconds( $run_id ) : max( 0, (int) $grace_seconds );
		$threshold = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - $grace ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- site-local stored datetimes.

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'UPDATE ' . Db::items() . " SET status = 'pending', worker = '', claimed_at = NULL, error_message = %s, updated_at = %s
				 WHERE run_id = %d AND status = 'translating' AND ( claimed_at IS NULL OR claimed_at < %s )",
				'Requeued after an interrupted request.',
				current_time( 'mysql' ),
				$run_id,
				$threshold
			)
		);

		return (int) $wpdb->rows_affected;
	}

	/**
	 * How many 'translating' rows have been sitting long enough to count as
	 * orphaned - a request claimed them and died. These are recoverable.
	 *
	 * @param int $run_id Run id.
	 * @return int
	 */
	public static function count_orphaned( $run_id ) {
		global $wpdb;

		$threshold = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::stale_seconds( $run_id ) ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested -- site-local stored datetimes.

		return (int) $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT COUNT(*) FROM ' . Db::items() . " WHERE run_id = %d AND status = 'translating' AND ( claimed_at IS NULL OR claimed_at < %s )",
				$run_id,
				$threshold
			)
		);
	}

	/**
	 * The single source of truth for "where is this run and what should happen
	 * next". Every screen and endpoint reads the phase from here rather than
	 * re-deriving it from raw counts.
	 *
	 * The phase:
	 *  - complete: total > 0 and nothing pending or translating.
	 *  - blocked:  nothing claimable, but in-flight rows are all orphaned
	 *              (a dead request) - needs a reclaim to move again.
	 *  - running:  work is claimable or genuinely in flight.
	 *  - idle:     no items at all (a freshly created, empty run).
	 *
	 * @param int $run_id Run id.
	 * @return array phase, claimable, in_flight, orphaned, counts.
	 */
	public static function state( $run_id ) {
		$counts      = self::counts( $run_id );
		$total       = $counts['total'];
		$pending     = $counts['pending'];
		$translating = $counts['translating'];
		$orphaned    = $translating > 0 ? self::count_orphaned( $run_id ) : 0;

		if ( 0 === $total ) {
			$phase = 'idle';
		} elseif ( 0 === $pending && 0 === $translating ) {
			$phase = 'complete';
		} elseif ( 0 === $pending && $translating > 0 && $translating === $orphaned ) {
			$phase = 'blocked';
		} else {
			$phase = 'running';
		}

		return array(
			'phase'     => $phase,
			'claimable' => $pending,
			'in_flight' => $translating,
			'orphaned'  => $orphaned,
			'counts'    => $counts,
		);
	}

	/**
	 * The next pending item ids, oldest first, after a stale reclaim.
	 *
	 * @param int $run_id Run id.
	 * @param int $limit  Max ids.
	 * @return int[]
	 */
	public static function peek_pending_ids( $run_id, $limit ) {
		global $wpdb;

		self::reclaim_stale( $run_id );

		$ids = $wpdb->get_col( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT id FROM ' . Db::items() . " WHERE run_id = %d AND status = 'pending' ORDER BY id ASC LIMIT %d",
				$run_id,
				$limit
			)
		);

		return array_map( 'intval', $ids ? $ids : array() );
	}

	/**
	 * Atomically claim specific pending item ids for one worker. Only the rows
	 * this call actually flipped from 'pending' are returned, so two workers
	 * racing for the same ids never both get them.
	 *
	 * @param int    $run_id  Run id.
	 * @param string $worker  Per-request worker id.
	 * @param int[]  $item_ids Candidate ids.
	 * @return array Hydrated claimed items.
	 */
	public static function claim_ids( $run_id, $worker, array $item_ids ) {
		global $wpdb;

		$item_ids = array_values( array_filter( array_map( 'intval', $item_ids ) ) );
		if ( ! $item_ids ) {
			return array();
		}

		$now          = current_time( 'mysql' );
		$placeholders = implode( ',', array_fill( 0, count( $item_ids ), '%d' ) );

		$params = array_merge( array( $worker, $now, $now, $run_id ), $item_ids );

		$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->prepare(
				'UPDATE ' . Db::items() . " SET status = 'translating', worker = %s, claimed_at = %s, updated_at = %s
				 WHERE run_id = %d AND status = 'pending' AND id IN ({$placeholders})",
				$params
			)
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT * FROM ' . Db::items() . " WHERE run_id = %d AND worker = %s AND status = 'translating' ORDER BY id ASC",
				$run_id,
				$worker
			),
			ARRAY_A
		);

		return array_map( array( __CLASS__, 'hydrate' ), $rows ? $rows : array() );
	}

	/*
	---------------------------------------------------------------------
	 * Write lock
	 * ------------------------------------------------------------------- */

	/**
	 * Run $fn while holding a MySQL named lock, so the WordPress-write phase
	 * (destination post creation, WPML taxonomy resolution) can never overlap
	 * another worker's. $fn still runs if the lock can't be acquired.
	 *
	 * @param callable $callback Work to serialise.
	 * @return mixed The callback's return value.
	 */
	public static function with_write_lock( callable $callback ) {
		global $wpdb;

		$wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', self::WRITE_LOCK, 15 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		try {
			return $callback();
		} finally {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::WRITE_LOCK ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/*
	---------------------------------------------------------------------
	 * Counts
	 * ------------------------------------------------------------------- */

	/**
	 * @param int $run_id Run id.
	 * @return array total, pending, translating, done, error, skipped, warnings,
	 *               apply_errors, cost_usd, prompt_tokens, completion_tokens.
	 */
	public static function counts( $run_id ) {
		global $wpdb;

		$counts = array(
			'total'             => 0,
			'pending'           => 0,
			'translating'       => 0,
			'done'              => 0,
			'error'             => 0,
			'skipped'           => 0,
			'warnings'          => 0,
			'apply_errors'      => 0,
			'cost_usd'          => 0.0,
			'prompt_tokens'     => 0,
			'completion_tokens' => 0,
		);

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT status, COUNT(*) AS c, SUM(cost_usd) AS cost, SUM(prompt_tokens) AS pt,
				 SUM(completion_tokens) AS ct, SUM(has_warning) AS warn, SUM(has_apply_error) AS err
				 FROM ' . Db::items() . ' WHERE run_id = %d GROUP BY status',
				$run_id
			),
			ARRAY_A
		);

		foreach ( $rows ? $rows : array() as $row ) {
			$c                = (int) $row['c'];
			$counts['total'] += $c;
			if ( isset( $counts[ $row['status'] ] ) ) {
				$counts[ $row['status'] ] = $c;
			}
			$counts['cost_usd']          += (float) $row['cost'];
			$counts['prompt_tokens']     += (int) $row['pt'];
			$counts['completion_tokens'] += (int) $row['ct'];
			$counts['warnings']          += (int) $row['warn'];
			$counts['apply_errors']      += (int) $row['err'];
		}

		return $counts;
	}

	/**
	 * All-time totals across every run - the Dashboard's "at a glance".
	 *
	 * @return array runs, posts_done, warnings, apply_errors, cost_usd, tokens.
	 */
	public static function totals() {
		global $wpdb;

		$row = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT
				SUM(CASE WHEN status = \'done\' THEN 1 ELSE 0 END) AS posts_done,
				SUM(has_warning) AS warnings,
				SUM(has_apply_error) AS apply_errors,
				SUM(cost_usd) AS cost_usd,
				SUM(prompt_tokens + completion_tokens) AS tokens
			 FROM ' . Db::items(),
			ARRAY_A
		);

		return array(
			'runs'         => self::count_runs(),
			'posts_done'   => (int) ( $row['posts_done'] ?? 0 ),
			'warnings'     => (int) ( $row['warnings'] ?? 0 ),
			'apply_errors' => (int) ( $row['apply_errors'] ?? 0 ),
			'cost_usd'     => (float) ( $row['cost_usd'] ?? 0 ),
			'tokens'       => (int) ( $row['tokens'] ?? 0 ),
		);
	}

	/**
	 * Is any run still unfinished (has pending / translating items)?
	 *
	 * @return int|null The most recent such run's id, or null.
	 */
	public static function active_run_id() {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			'SELECT r.id FROM ' . Db::runs() . ' r
			 WHERE EXISTS (
				SELECT 1 FROM ' . Db::items() . " i
				WHERE i.run_id = r.id AND i.status IN ('pending','translating')
			 )
			 ORDER BY r.id DESC LIMIT 1"
		);

		return $id ? (int) $id : null;
	}

	/**
	 * The most recent unfinished run that contains this source post, if any -
	 * so the admin bar can offer "Resume" instead of starting a duplicate.
	 *
	 * @param int $source_post_id Source post id.
	 * @return int|null
	 */
	public static function active_run_id_for_source( $source_post_id ) {
		global $wpdb;

		$id = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT run_id FROM ' . Db::items() . " WHERE source_post_id = %d AND status IN ('pending','translating') ORDER BY run_id DESC LIMIT 1",
				(int) $source_post_id
			)
		);

		return $id ? (int) $id : null;
	}

	/*
	---------------------------------------------------------------------
	 * Log
	 * ------------------------------------------------------------------- */

	protected static function current_user_label() {
		$user = wp_get_current_user();
		return $user && $user->exists() ? $user->display_name : '';
	}

	public static function log( $run_id, $item_id, $message ) {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			Db::logs(),
			array(
				'run_id'    => $run_id,
				'item_id'   => (int) $item_id,
				'logged_at' => current_time( 'mysql' ),
				'logged_by' => self::current_user_label(),
				'message'   => $message,
			),
			array( '%d', '%d', '%s', '%s', '%s' )
		);
	}

	public static function log_bulk( $run_id, array $item_ids, $message ) {
		foreach ( $item_ids as $item_id ) {
			self::log( $run_id, $item_id, $message );
		}
	}

	/**
	 * @param int $run_id Run id.
	 * @return array Rows: logged_at, message.
	 */
	public static function log_lines( $run_id ) {
		global $wpdb;

		$rows = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				'SELECT logged_at, message FROM ' . Db::logs() . ' WHERE run_id = %d ORDER BY id ASC',
				$run_id
			),
			ARRAY_A
		);

		return $rows ? $rows : array();
	}
}
