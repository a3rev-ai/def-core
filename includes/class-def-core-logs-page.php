<?php
/**
 * Class DEF_Core_Logs_Page
 *
 * Connection Logs viewer in wp-admin. Displays structured log entries
 * from the def_core_logs table with filtering, pagination, and actions.
 *
 * @package digital-employees
 * @since   1.7.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Logs_Page {

	/** @var int Entries per page. */
	private const PER_PAGE = 50;

	/**
	 * Initialize — register the submenu page and AJAX handlers.
	 */
	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'add_submenu_page' ) );
		add_action( 'wp_ajax_def_core_clear_logs', array( __CLASS__, 'ajax_clear_logs' ) );
		add_action( 'wp_ajax_def_core_download_logs_csv', array( __CLASS__, 'ajax_download_csv' ) );
	}

	/**
	 * Register the Logs submenu page under Digital Employees.
	 */
	public static function add_submenu_page(): void {
		add_submenu_page(
			'def-core',
			__( 'Connection Logs', 'digital-employees' ),
			__( 'Logs', 'digital-employees' ),
			'manage_options',
			'def-core-logs',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Render the logs page.
	 */
	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'digital-employees' ) );
		}

		global $wpdb;
		$table = DEF_Core_Logger::get_table_name();

		// Check if table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var(
			$wpdb->prepare( 'SHOW TABLES LIKE %s', $table )
		);

		if ( ! $table_exists ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Connection Logs', 'digital-employees' ) . '</h1>';
			echo '<p>' . esc_html__( 'Log table not found. Please deactivate and reactivate the plugin.', 'digital-employees' ) . '</p></div>';
			return;
		}

		// Read filters.
		$filter_level  = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
		$filter_source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$filter_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$paged         = isset( $_GET['paged'] ) ? max( 1, (int) $_GET['paged'] ) : 1;

		// Build WHERE clauses.
		$where  = array( '1=1' );
		$values = array();

		if ( $filter_level && in_array( $filter_level, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			$where[]  = 'level = %s';
			$values[] = $filter_level;
		}

		if ( $filter_source && in_array( $filter_source, array( 'sync', 'auth', 'connection', 'tools' ), true ) ) {
			$where[]  = 'source = %s';
			$values[] = $filter_source;
		}

		if ( $filter_search ) {
			$where[]  = '(message LIKE %s OR context LIKE %s OR request_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $filter_search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// Count total matching rows.
		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", ...$values )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
		}

		$total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
		$paged       = min( $paged, $total_pages );
		$offset      = ( $paged - 1 ) * self::PER_PAGE;

		// Fetch rows.
		$limit_sql = $wpdb->prepare( 'ORDER BY id DESC LIMIT %d OFFSET %d', self::PER_PAGE, $offset );

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} {$limit_sql}", ...$values )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} {$limit_sql}" );
		}

		// Enqueue admin styles.
		wp_enqueue_style( 'def-core-admin' );

		$page_url = admin_url( 'admin.php?page=def-core-logs' );
		$nonce    = wp_create_nonce( 'def_core_logs_action' );

		?>
		<div class="wrap def-core-wrap" style="max-width: 1200px;">
			<h1><?php esc_html_e( 'Connection Logs', 'digital-employees' ); ?></h1>

			<!-- Filters -->
			<form method="get" style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px; flex-wrap: wrap;">
				<input type="hidden" name="page" value="def-core-logs" />

				<select name="level">
					<option value=""><?php esc_html_e( 'All Levels', 'digital-employees' ); ?></option>
					<?php foreach ( array( 'debug', 'info', 'warning', 'error' ) as $lvl ) : ?>
						<option value="<?php echo esc_attr( $lvl ); ?>" <?php selected( $filter_level, $lvl ); ?>>
							<?php echo esc_html( ucfirst( $lvl ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<select name="source">
					<option value=""><?php esc_html_e( 'All Sources', 'digital-employees' ); ?></option>
					<?php foreach ( array( 'sync', 'auth', 'connection', 'tools' ) as $src ) : ?>
						<option value="<?php echo esc_attr( $src ); ?>" <?php selected( $filter_source, $src ); ?>>
							<?php echo esc_html( ucfirst( $src ) ); ?>
						</option>
					<?php endforeach; ?>
				</select>

				<input type="text" name="s" value="<?php echo esc_attr( $filter_search ); ?>"
					placeholder="<?php esc_attr_e( 'Search message, context, or request ID...', 'digital-employees' ); ?>"
					style="min-width: 280px;" />

				<button type="submit" class="button"><?php esc_html_e( 'Filter', 'digital-employees' ); ?></button>

				<?php if ( $filter_level || $filter_source || $filter_search ) : ?>
					<a href="<?php echo esc_url( $page_url ); ?>" class="button"><?php esc_html_e( 'Clear Filters', 'digital-employees' ); ?></a>
				<?php endif; ?>

				<span style="margin-left: auto; color: #646970;">
					<?php
					printf(
						/* translators: %s: total number of log entries */
						esc_html__( '%s entries', 'digital-employees' ),
						'<strong>' . number_format_i18n( $total ) . '</strong>'
					);
					?>
				</span>
			</form>

			<!-- Actions -->
			<div style="display: flex; gap: 8px; margin-bottom: 16px;">
				<button type="button" class="button" id="def-logs-download-csv">
					<?php esc_html_e( 'Download CSV', 'digital-employees' ); ?>
				</button>
				<button type="button" class="button" id="def-logs-clear-all" style="color: #b32d2e;">
					<?php esc_html_e( 'Clear All Logs', 'digital-employees' ); ?>
				</button>
			</div>

			<!-- Log Table -->
			<table class="widefat striped" style="table-layout: fixed;">
				<thead>
					<tr>
						<th style="width: 150px;"><?php esc_html_e( 'Timestamp', 'digital-employees' ); ?></th>
						<th style="width: 70px;"><?php esc_html_e( 'Level', 'digital-employees' ); ?></th>
						<th style="width: 80px;"><?php esc_html_e( 'Source', 'digital-employees' ); ?></th>
						<th><?php esc_html_e( 'Message', 'digital-employees' ); ?></th>
						<th style="width: 80px;"><?php esc_html_e( 'Req ID', 'digital-employees' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr>
							<td colspan="5" style="text-align: center; padding: 20px; color: #646970;">
								<?php esc_html_e( 'No log entries found.', 'digital-employees' ); ?>
							</td>
						</tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<?php
							$level_colors = array(
								'debug'   => '#646970',
								'info'    => '#2271b1',
								'warning' => '#dba617',
								'error'   => '#b32d2e',
							);
							$color = $level_colors[ $row->level ] ?? '#646970';

							// Parse context JSON for display.
							$context_display = '';
							if ( $row->context ) {
								$ctx = json_decode( $row->context, true );
								if ( is_array( $ctx ) ) {
									$parts = array();
									foreach ( $ctx as $k => $v ) {
										if ( 'sql' === $k ) {
											// Show truncated SQL.
											$v = mb_substr( (string) $v, 0, 120 ) . ( mb_strlen( (string) $v ) > 120 ? '...' : '' );
										}
										if ( is_bool( $v ) ) {
											$v = $v ? 'true' : 'false';
										}
										if ( is_array( $v ) ) {
											$v = wp_json_encode( $v );
										}
										$parts[] = '<span style="color:#646970;">' . esc_html( $k ) . '=</span>' . esc_html( (string) $v );
									}
									$context_display = implode( ' &nbsp;', $parts );
								} else {
									$context_display = esc_html( mb_substr( $row->context, 0, 200 ) );
								}
							}
							?>
							<tr>
								<td style="font-size: 12px; color: #646970; white-space: nowrap;">
									<?php echo esc_html( $row->timestamp ); ?>
								</td>
								<td>
									<span style="color: <?php echo esc_attr( $color ); ?>; font-weight: 600; font-size: 12px; text-transform: uppercase;">
										<?php echo esc_html( $row->level ); ?>
									</span>
								</td>
								<td style="font-size: 12px;">
									<?php echo esc_html( $row->source ); ?>
								</td>
								<td style="font-size: 13px; word-break: break-word;">
									<strong><?php echo esc_html( $row->message ); ?></strong>
									<?php if ( $context_display ) : ?>
										<div style="margin-top: 4px; font-size: 12px; line-height: 1.5; color: #50575e;">
											<?php echo $context_display; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per-field above. ?>
										</div>
									<?php endif; ?>
								</td>
								<td style="font-size: 11px; font-family: monospace; color: #646970;">
									<?php echo esc_html( $row->request_id ? substr( $row->request_id, 0, 8 ) : '—' ); ?>
								</td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>

			<!-- Pagination -->
			<?php if ( $total_pages > 1 ) : ?>
				<div class="tablenav" style="margin-top: 12px;">
					<div class="tablenav-pages">
						<span class="displaying-num">
							<?php
							printf(
								/* translators: %s: total entries */
								esc_html__( '%s items', 'digital-employees' ),
								number_format_i18n( $total )
							);
							?>
						</span>
						<span class="pagination-links">
							<?php
							$base_args = array( 'page' => 'def-core-logs' );
							if ( $filter_level ) {
								$base_args['level'] = $filter_level;
							}
							if ( $filter_source ) {
								$base_args['source'] = $filter_source;
							}
							if ( $filter_search ) {
								$base_args['s'] = $filter_search;
							}

							if ( $paged > 1 ) {
								$base_args['paged'] = $paged - 1;
								printf(
									'<a class="prev-page button" href="%s">&lsaquo;</a>',
									esc_url( add_query_arg( $base_args, admin_url( 'admin.php' ) ) )
								);
							} else {
								echo '<span class="button disabled">&lsaquo;</span>';
							}

							printf(
								' <span class="paging-input">%d / %d</span> ',
								$paged,
								$total_pages
							);

							if ( $paged < $total_pages ) {
								$base_args['paged'] = $paged + 1;
								printf(
									'<a class="next-page button" href="%s">&rsaquo;</a>',
									esc_url( add_query_arg( $base_args, admin_url( 'admin.php' ) ) )
								);
							} else {
								echo '<span class="button disabled">&rsaquo;</span>';
							}
							?>
						</span>
					</div>
				</div>
			<?php endif; ?>
		</div>

		<script>
		(function() {
			'use strict';

			// Clear all logs.
			var clearBtn = document.getElementById('def-logs-clear-all');
			if (clearBtn) {
				clearBtn.addEventListener('click', function() {
					if (!confirm('<?php echo esc_js( __( 'Delete all log entries? This cannot be undone.', 'digital-employees' ) ); ?>')) {
						return;
					}
					var data = new FormData();
					data.append('action', 'def_core_clear_logs');
					data.append('_wpnonce', '<?php echo esc_js( $nonce ); ?>');

					fetch(ajaxurl, { method: 'POST', body: data })
						.then(function(r) { return r.json(); })
						.then(function(r) {
							if (r.success) {
								location.href = '<?php echo esc_js( $page_url ); ?>';
							} else {
								alert(r.data || 'Error clearing logs.');
							}
						});
				});
			}

			// Download CSV.
			var csvBtn = document.getElementById('def-logs-download-csv');
			if (csvBtn) {
				csvBtn.addEventListener('click', function() {
					var params = new URLSearchParams(location.search);
					params.set('action', 'def_core_download_logs_csv');
					params.set('_wpnonce', '<?php echo esc_js( $nonce ); ?>');
					window.location.href = ajaxurl + '?' + params.toString();
				});
			}
		})();
		</script>
		<?php
	}

	/**
	 * AJAX: Clear all logs.
	 */
	public static function ajax_clear_logs(): void {
		check_ajax_referer( 'def_core_logs_action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Permission denied.' );
		}

		global $wpdb;
		$table = DEF_Core_Logger::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "TRUNCATE TABLE {$table}" );

		wp_send_json_success( array( 'cleared' => true ) );
	}

	/**
	 * AJAX: Download logs as CSV.
	 */
	public static function ajax_download_csv(): void {
		check_ajax_referer( 'def_core_logs_action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Permission denied.' );
		}

		global $wpdb;
		$table = DEF_Core_Logger::get_table_name();

		// Apply same filters as page view.
		$where  = array( '1=1' );
		$values = array();

		$filter_level  = isset( $_GET['level'] ) ? sanitize_text_field( wp_unslash( $_GET['level'] ) ) : '';
		$filter_source = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		$filter_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';

		if ( $filter_level && in_array( $filter_level, array( 'debug', 'info', 'warning', 'error' ), true ) ) {
			$where[]  = 'level = %s';
			$values[] = $filter_level;
		}

		if ( $filter_source && in_array( $filter_source, array( 'sync', 'auth', 'connection', 'tools' ), true ) ) {
			$where[]  = 'source = %s';
			$values[] = $filter_source;
		}

		if ( $filter_search ) {
			$where[]  = '(message LIKE %s OR context LIKE %s OR request_id LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $filter_search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT 10000", ...$values )
			);
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 10000" );
		}

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="def-core-logs-' . gmdate( 'Y-m-d-His' ) . '.csv"' );

		$out = fopen( 'php://output', 'w' );
		fputcsv( $out, array( 'Timestamp', 'Level', 'Source', 'Message', 'Context', 'Request ID' ) );

		foreach ( $rows as $row ) {
			fputcsv( $out, array(
				$row->timestamp,
				$row->level,
				$row->source,
				$row->message,
				$row->context,
				$row->request_id,
			) );
		}

		fclose( $out );
		exit;
	}
}
