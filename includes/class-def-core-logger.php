<?php
/**
 * Class DEF_Core_Logger
 *
 * Structured logging for the Digital Employee Framework - Core plugin.
 * Stores log entries in a dedicated database table with level filtering,
 * automatic rotation, and fail-open design (errors never break the plugin).
 *
 * @package digital-employees
 * @since   2.2.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DEF_Core_Logger {

	// Log levels.
	const LEVEL_DEBUG   = 'debug';
	const LEVEL_INFO    = 'info';
	const LEVEL_WARNING = 'warning';
	const LEVEL_ERROR   = 'error';

	// Log sources.
	const SOURCE_SYNC       = 'sync';
	const SOURCE_AUTH       = 'auth';
	const SOURCE_CONNECTION = 'connection';
	const SOURCE_TOOLS      = 'tools';

	/**
	 * Maximum message length (chars).
	 *
	 * @var int
	 */
	private const MAX_MESSAGE_LENGTH = 500;

	/**
	 * Maximum context JSON length (bytes).
	 *
	 * @var int
	 */
	private const MAX_CONTEXT_LENGTH = 10240;

	/**
	 * Maximum SQL string length in context (chars).
	 *
	 * @var int
	 */
	private const MAX_SQL_LENGTH = 2000;

	/**
	 * Default maximum log entries before rotation.
	 *
	 * @var int
	 */
	private const DEFAULT_MAX_ENTRIES = 50000;

	/**
	 * Default retention period in days.
	 *
	 * @var int
	 */
	private const DEFAULT_RETENTION_DAYS = 30;

	/**
	 * Write a log entry.
	 *
	 * Fail-open: all exceptions are caught and forwarded to error_log().
	 *
	 * @param string $level   One of the LEVEL_* constants.
	 * @param string $source  One of the SOURCE_* constants (or free-form).
	 * @param string $message Human-readable log message.
	 * @param array  $context Optional structured context data.
	 */
	/**
	 * Recursion guard — prevents infinite loops if error_log() fallback
	 * somehow triggers another logger call through WordPress hooks.
	 *
	 * @var bool
	 */
	private static $in_logger = false;

	public static function log( string $level, string $source, string $message, array $context = [] ): void {
		// Recursion guard (Staff-AI suggestion).
		if ( self::$in_logger ) {
			return;
		}
		self::$in_logger = true;

		try {
			// Check minimum log level (before any encoding work).
			if ( self::level_priority( $level ) < self::level_priority( self::get_min_level() ) ) {
				self::$in_logger = false;
				return;
			}

			global $wpdb;

			// Truncate message.
			if ( mb_strlen( $message ) > self::MAX_MESSAGE_LENGTH ) {
				$message = mb_substr( $message, 0, self::MAX_MESSAGE_LENGTH - 3 ) . '...';
			}

			// Extract request_id from context into dedicated column.
			$request_id = '';
			if ( isset( $context['request_id'] ) ) {
				$request_id = substr( (string) $context['request_id'], 0, 36 );
				unset( $context['request_id'] );
			}

			// Truncate large string values in context BEFORE encoding
			// to ensure the final JSON is always valid (ChatGPT + Grok blocker).
			if ( ! empty( $context ) ) {
				foreach ( $context as $key => &$value ) {
					if ( ! is_string( $value ) ) {
						continue;
					}
					// SQL gets its own higher limit.
					$limit = ( 'sql' === $key ) ? self::MAX_SQL_LENGTH : 512;
					if ( strlen( $value ) > $limit ) {
						$value = substr( $value, 0, $limit ) . '...[truncated]';
					}
				}
				unset( $value );
			}

			// Encode context to JSON.
			$context_json = null;
			if ( ! empty( $context ) ) {
				$context_json = wp_json_encode( $context );
				if ( false === $context_json ) {
					// Unserializable data (objects, resources) — safe fallback.
					$context_json = wp_json_encode( array( '_error' => 'json_encode_failed' ) );
				} elseif ( strlen( $context_json ) > self::MAX_CONTEXT_LENGTH ) {
					// Still over limit after field truncation — re-encode with a note.
					$context_json = wp_json_encode( array(
						'_error'  => 'context_too_large',
						'_length' => strlen( $context_json ),
					) );
				}
			}

			$result = $wpdb->insert(
				self::get_table_name(),
				array(
					'timestamp'  => current_time( 'mysql', true ),
					'level'      => $level,
					'source'     => substr( $source, 0, 20 ),
					'message'    => $message,
					'context'    => $context_json,
					'request_id' => $request_id ?: null,
				),
				array( '%s', '%s', '%s', '%s', '%s', '%s' )
			);

			// wpdb::insert() returns false on failure, not an exception
			// (ChatGPT blocker #3). Explicitly check and fallback.
			if ( false === $result ) {
				// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
				error_log( sprintf(
					'[DEF_Core_Logger] DB insert failed: %s | Original: [%s][%s] %s',
					$wpdb->last_error,
					$level,
					$source,
					$message
				) );
			}

			// Emergency fallback rotation: 1-in-100 probabilistic check.
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
			if ( 1 === mt_rand( 1, 100 ) ) {
				$max_entries = (int) apply_filters( 'def_core_log_max_entries', self::DEFAULT_MAX_ENTRIES );
				$count       = (int) $wpdb->get_var(
					$wpdb->prepare(
						'SELECT COUNT(*) FROM %i',
						self::get_table_name()
					)
				);
				if ( $count > $max_entries ) {
					self::cleanup();
				}
			}
		} catch ( \Throwable $e ) {
			// Fail-open: never let logging break the plugin.
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( sprintf(
				'[DEF_Core_Logger] Failed to write log: %s | Original: [%s][%s] %s',
				$e->getMessage(),
				$level,
				$source,
				$message
			) );
		} finally {
			self::$in_logger = false;
		}
	}

	/**
	 * Log a debug message.
	 *
	 * @param string $source  Log source.
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function debug( string $source, string $message, array $context = [] ): void {
		self::log( self::LEVEL_DEBUG, $source, $message, $context );
	}

	/**
	 * Log an info message.
	 *
	 * @param string $source  Log source.
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function info( string $source, string $message, array $context = [] ): void {
		self::log( self::LEVEL_INFO, $source, $message, $context );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $source  Log source.
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function warning( string $source, string $message, array $context = [] ): void {
		self::log( self::LEVEL_WARNING, $source, $message, $context );
	}

	/**
	 * Log an error message.
	 *
	 * @param string $source  Log source.
	 * @param string $message Log message.
	 * @param array  $context Optional context data.
	 */
	public static function error( string $source, string $message, array $context = [] ): void {
		self::log( self::LEVEL_ERROR, $source, $message, $context );
	}

	/**
	 * Create or upgrade the log table using dbDelta().
	 */
	public static function create_table(): void {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			timestamp datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			level varchar(10) NOT NULL,
			source varchar(20) NOT NULL,
			message varchar(500) NOT NULL,
			context longtext NULL,
			request_id varchar(36) NULL,
			PRIMARY KEY  (id),
			KEY idx_timestamp (timestamp),
			KEY idx_level (level),
			KEY idx_source (source),
			KEY idx_request_id (request_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Delete old entries and trim to 80% of max if over limit.
	 */
	public static function cleanup(): void {
		global $wpdb;

		$table_name     = self::get_table_name();
		$retention_days = (int) apply_filters( 'def_core_log_retention_days', self::DEFAULT_RETENTION_DAYS );
		$max_entries    = (int) apply_filters( 'def_core_log_max_entries', self::DEFAULT_MAX_ENTRIES );

		// Delete entries older than retention period.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM %i WHERE timestamp < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$table_name,
				$retention_days
			)
		);

		// Trim to 80% of max if still over limit.
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( 'SELECT COUNT(*) FROM %i', $table_name )
		);

		if ( $count > $max_entries ) {
			$keep = (int) ( $max_entries * 0.8 );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE id NOT IN (SELECT id FROM (SELECT id FROM %i ORDER BY id DESC LIMIT %d) AS keep_ids)",
					$table_name,
					$table_name,
					$keep
				)
			);
		}
	}

	/**
	 * Schedule daily cleanup via wp-cron.
	 */
	public static function schedule_cleanup(): void {
		if ( ! wp_next_scheduled( 'def_core_log_cleanup' ) ) {
			wp_schedule_event( time(), 'daily', 'def_core_log_cleanup' );
		}
		add_action( 'def_core_log_cleanup', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Unschedule cleanup cron event (for plugin deactivation).
	 */
	public static function unschedule_cleanup(): void {
		$timestamp = wp_next_scheduled( 'def_core_log_cleanup' );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, 'def_core_log_cleanup' );
		}
	}

	/**
	 * Get the full table name with prefix.
	 *
	 * @return string Table name.
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . 'def_core_logs';
	}

	/**
	 * Get the configured minimum log level string.
	 *
	 * @return string Level string (debug, info, warning, error).
	 */
	public static function get_min_level(): string {
		$level = get_option( 'def_core_log_level', 'info' );
		$valid = array( self::LEVEL_DEBUG, self::LEVEL_INFO, self::LEVEL_WARNING, self::LEVEL_ERROR );
		return in_array( $level, $valid, true ) ? $level : 'info';
	}

	/**
	 * Get numeric priority for a log level.
	 *
	 * @param string $level The log level string.
	 * @return int Priority (0=debug, 1=info, 2=warning, 3=error).
	 */
	private static function level_priority( string $level ): int {
		$map = array(
			self::LEVEL_DEBUG   => 0,
			self::LEVEL_INFO    => 1,
			self::LEVEL_WARNING => 2,
			self::LEVEL_ERROR   => 3,
		);
		return $map[ $level ] ?? 1;
	}
}
