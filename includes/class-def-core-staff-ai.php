<?php
/**
 * Class DEF_Core_Staff_AI
 *
 * Staff AI frontend endpoint handler.
 *
 * @package def-core
 * @since 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles the /staff-ai endpoint rendering.
 */
final class DEF_Core_Staff_AI {
	/**
	 * The endpoint slug.
	 */
	const ENDPOINT_SLUG = 'staff-ai';

	/**
	 * Initialize the Staff AI endpoint.
	 */
	public static function init(): void {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_endpoint' ) );
		add_filter( 'query_vars', array( __CLASS__, 'add_query_vars' ) );
	}

	/**
	 * Add rewrite rules for /staff-ai endpoint.
	 */
	public static function add_rewrite_rules(): void {
		add_rewrite_rule(
			'^' . self::ENDPOINT_SLUG . '/?$',
			'index.php?' . self::ENDPOINT_SLUG . '=1',
			'top'
		);
	}

	/**
	 * Add query vars.
	 *
	 * @param array $vars Existing query vars.
	 * @return array Modified query vars.
	 */
	public static function add_query_vars( array $vars ): array {
		$vars[] = self::ENDPOINT_SLUG;
		return $vars;
	}

	/**
	 * Handle the /staff-ai endpoint request.
	 */
	public static function handle_endpoint(): void {
		if ( ! get_query_var( self::ENDPOINT_SLUG ) ) {
			return;
		}

		// Authentication gate: redirect to login if not authenticated.
		if ( ! is_user_logged_in() ) {
			$redirect_url = home_url( '/' . self::ENDPOINT_SLUG );
			wp_safe_redirect( wp_login_url( $redirect_url ) );
			exit;
		}

		// Capability gate: check for def_staff_access OR def_management_access.
		if ( ! self::user_has_staff_ai_access() ) {
			self::render_access_denied();
			exit;
		}

		// Render the Staff AI shell.
		self::render_shell();
		exit;
	}

	/**
	 * Check if current user has Staff AI access.
	 *
	 * @return bool True if user has def_staff_access OR def_management_access.
	 */
	public static function user_has_staff_ai_access(): bool {
		$user = wp_get_current_user();
		if ( ! $user || ! $user->exists() ) {
			return false;
		}

		return $user->has_cap( 'def_staff_access' ) || $user->has_cap( 'def_management_access' );
	}

	/**
	 * Render the access denied page.
	 */
	private static function render_access_denied(): void {
		http_response_code( 403 );
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Access Denied', 'def-core' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		body {
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f0f0f1;
			color: #3c434a;
			display: flex;
			align-items: center;
			justify-content: center;
			min-height: 100vh;
			padding: 20px;
		}
		.access-denied {
			background: #fff;
			border: 1px solid #c3c4c7;
			border-radius: 4px;
			padding: 40px;
			max-width: 400px;
			text-align: center;
			box-shadow: 0 1px 3px rgba(0,0,0,.04);
		}
		.access-denied h1 {
			font-size: 1.5em;
			margin-bottom: 16px;
			color: #1d2327;
		}
		.access-denied p {
			color: #50575e;
			line-height: 1.6;
		}
	</style>
</head>
<body>
	<div class="access-denied">
		<h1><?php echo esc_html__( 'Access Denied', 'def-core' ); ?></h1>
		<p><?php echo esc_html__( 'You do not have permission to access Staff AI.', 'def-core' ); ?></p>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Render the Staff AI shell.
	 */
	private static function render_shell(): void {
		$user    = wp_get_current_user();
		$channel = 'staff_ai';

		// Determine assistant type based on capability.
		$assistant_type = $user->has_cap( 'def_management_access' )
			? 'management'
			: 'staff';
		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<title><?php echo esc_html__( 'Staff AI', 'def-core' ); ?> - <?php bloginfo( 'name' ); ?></title>
	<style>
		* { margin: 0; padding: 0; box-sizing: border-box; }
		html, body {
			height: 100%;
			font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
			background: #f9fafb;
			color: #1f2937;
		}
		#staff-ai-app {
			display: flex;
			flex-direction: column;
			height: 100vh;
		}
		.staff-ai-header {
			background: #fff;
			border-bottom: 1px solid #e5e7eb;
			padding: 12px 20px;
			display: flex;
			align-items: center;
			justify-content: space-between;
		}
		.staff-ai-header h1 {
			font-size: 1.125rem;
			font-weight: 600;
			color: #111827;
		}
		.staff-ai-main {
			flex: 1;
			display: flex;
			align-items: center;
			justify-content: center;
			padding: 20px;
		}
		.staff-ai-placeholder {
			text-align: center;
			color: #6b7280;
		}
		.staff-ai-footer {
			background: #fff;
			border-top: 1px solid #e5e7eb;
			padding: 8px 20px;
			text-align: center;
			font-size: 0.75rem;
			color: #9ca3af;
		}
	</style>
</head>
<body>
	<div id="staff-ai-app"
		data-channel="<?php echo esc_attr( $channel ); ?>"
		data-user-id="<?php echo esc_attr( (string) $user->ID ); ?>"
		data-assistant-type="<?php echo esc_attr( $assistant_type ); ?>">
		<header class="staff-ai-header">
			<h1>
				<?php
				if ( 'management' === $assistant_type ) {
					echo esc_html__( 'Management Knowledge Assistant', 'def-core' );
				} else {
					echo esc_html__( 'Staff Knowledge Assistant', 'def-core' );
				}
				?>
			</h1>
		</header>
		<main class="staff-ai-main">
			<div class="staff-ai-placeholder">
				<?php echo esc_html__( 'Chat UI loading...', 'def-core' ); ?>
			</div>
		</main>
		<footer class="staff-ai-footer">
			<?php echo esc_html__( 'Powered by DEF', 'def-core' ); ?>
		</footer>
	</div>
</body>
</html>
		<?php
	}

	/**
	 * Flush rewrite rules on activation.
	 */
	public static function on_activate(): void {
		self::add_rewrite_rules();
		flush_rewrite_rules();
	}
}
