<?php
/**
 * Class Digital_Employee_WP_Bridge_Tools
 *
 * Tools for the a3 AI Session Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Digital_Employee_WP_Bridge_Tools
 *
 * Tools for the a3 AI Session Bridge plugin.
 *
 * @package digital-employee-wp-bridge
 * @since 0.1.0
 * @version 0.1.0
 */
final class Digital_Employee_WP_Bridge_Tools {

	/**
	 * Issue a context token.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function rest_issue_context_token(): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response( array( 'error' => 'unauthorized' ), 401 );
		}
		$claims = array(
			'sub'          => (string) $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'first_name'   => $user->user_firstname,
			'email'        => $user->user_email,
			'roles'        => array_values( (array) $user->roles ),
			'iss'          => get_site_url(),
			'aud'          => DE_WP_BRIDGE_AUDIENCE,
		);
		$jwt    = Digital_Employee_WP_Bridge_JWT::issue_token( $claims, 300 ); // 5 minutes.
		return new \WP_REST_Response(
			array(
				'token' => $jwt,
				'exp'   => time() + 300,
			),
			200
		);
	}

	/**
	 * Get the JWKS.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function rest_get_jwks(): \WP_REST_Response {
		return new \WP_REST_Response( Digital_Employee_WP_Bridge_JWT::get_jwks(), 200 );
	}

	/**
	 * Get the bearer token from the request.
	 *
	 * @return string|null The bearer token or null if not found.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	private static function get_bearer_token(): ?string {
		$auth = '';
		if ( isset( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		} elseif ( isset( $_SERVER['Authorization'] ) ) {
			$auth = sanitize_text_field( wp_unslash( $_SERVER['Authorization'] ) );
		}
		if ( $auth && stripos( $auth, 'bearer ' ) === 0 ) {
			return trim( substr( $auth, 7 ) );
		}
		return null;
	}

	/**
	 * Parse WooCommerce session cookie from REST API request headers.
	 * This ensures REST API uses the same session as the browser for guest users.
	 *
	 * @param \WP_REST_Request $request The REST request object.
	 * @return void
	 * @since 0.2.0
	 */
	private static function parse_wc_session_cookie_from_request( \WP_REST_Request $request ): void {
		// Get Cookie header from request.
		$cookie_header = $request->get_header( 'Cookie' );
		if ( empty( $cookie_header ) ) {
			// Fallback to $_SERVER if header not available.
			$cookie_header = isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '';
		}

		if ( empty( $cookie_header ) ) {
			return;
		}

		// Parse cookie header.
		$cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;
		$cookies     = array();
		$pairs       = explode( '; ', $cookie_header );

		foreach ( $pairs as $pair ) {
			$parts = explode( '=', $pair, 2 );
			if ( count( $parts ) === 2 ) {
				$name             = trim( $parts[0] );
				$value            = urldecode( trim( $parts[1] ) );
				$cookies[ $name ] = $value;
			}
		}

		// If WooCommerce session cookie found, populate $_COOKIE so WooCommerce can use it.
		if ( isset( $cookies[ $cookie_name ] ) ) {
			$_COOKIE[ $cookie_name ] = $cookies[ $cookie_name ];
		}
	}

	/**
	 * Verify the bearer token and get the user.
	 *
	 * @return \WP_User|null The user object or null if not found.
	 * @since 0.1.0
	 * @version 0.2.0 - Made public for addon use
	 */
	public static function verify_and_get_user(): ?\WP_User {
		$jwt = self::get_bearer_token();
		if ( ! $jwt ) {
			return null;
		}
		$payload = Digital_Employee_WP_Bridge_JWT::verify_token( $jwt );
		if ( ! is_array( $payload ) ) {
			return null;
		}
		$user_id = isset( $payload['sub'] ) ? absint( $payload['sub'] ) : 0;
		if ( ! $user_id ) {
			return null;
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! ( $user instanceof \WP_User ) ) {
			return null;
		}
		wp_set_current_user( $user->ID );
		$current = wp_get_current_user();
		return ( $current instanceof \WP_User && $current->exists() ) ? $current : null;
	}

	/**
	 * Permission callback for tool routes.
	 * Verifies JWT and sets current user context.
	 *
	 * @return bool True if user is authenticated, false otherwise.
	 * @since 0.1.0
	 * @version 0.2.0
	 */
	public static function permission_check(): bool {
		$user = self::verify_and_get_user();
		return ( $user instanceof \WP_User && $user->exists() );
	}

	/**
	 * Permission callback for add-to-cart route.
	 * Allows guests if WooCommerce guest checkout is enabled.
	 *
	 * @return bool True if allowed (authenticated user OR guest checkout enabled).
	 * @since 0.2.0
	 * @version 0.2.0
	 */
	public static function permission_check_add_to_cart(): bool {
		// Check if user is authenticated.
		$user = self::verify_and_get_user();
		if ( $user instanceof \WP_User && $user->exists() ) {
			return true;
		}

		// Check if WooCommerce allows guest checkout.
		if ( function_exists( 'get_option' ) ) {
			$guest_checkout_enabled = get_option( 'woocommerce_enable_guest_checkout', 'no' );
			if ( 'yes' === $guest_checkout_enabled ) {
				return true;
			}
		}

		// Default: deny access.
		return false;
	}

	/**
	 * Get the user's profile.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function me(): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}

		$data = Digital_Employee_WP_Bridge_Cache::get_or_set(
			'me',
			$user->ID,
			604800, // 7 days - user profile rarely changes (should be cached for a week).
			function () use ( $user ) {
				return array(
					'id'           => (int) $user->ID,
					'username'     => $user->user_login,
					'display_name' => $user->display_name,
					'first_name'   => $user->first_name,
					'last_name'    => $user->last_name,
					'email'        => $user->user_email,
					'roles'        => array_values( (array) $user->roles ),
				);
			}
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get the user's orders.
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_orders( \WP_REST_Request $req ): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not active',
				),
				400
			);
		}

		$limit  = intval( $req['limit'] ?? -1 );
		$status = sanitize_text_field( $req['status'] ?? '' );

		// Build cache key with params.
		$cache_key = "orders_limit{$limit}";
		if ( $status ) {
			$cache_key .= "_status{$status}";
		}

		$data = Digital_Employee_WP_Bridge_Cache::get_or_set(
			$cache_key,
			$user->ID,
			604800, // 7 days - orders are more dynamic (should be cached for a week).
			function () use ( $user, $limit, $status ) {
				$args = array(
					'customer_id' => (int) $user->ID,
					'limit'       => $limit,
					'orderby'     => 'date',
					'order'       => 'DESC',
					'return'      => 'ids',
				);
				if ( $status ) {
					$args['status'] = $status;
				}
				$order_ids = wc_get_orders( $args );
				$out       = array();
				foreach ( $order_ids as $oid ) {
					$o = wc_get_order( $oid );
					if ( ! $o ) {
						continue;
					}
					// Get product names from order items.
					$product_names = array();
					foreach ( $o->get_items() as $item ) {
						$product_names[] = $item->get_name();
					}
					$out[] = array(
						'id'       => (int) $o->get_id(),
						'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
						'status'   => $o->get_status(),
						'total'    => (string) $o->get_total(),
						'currency' => $o->get_currency(),
						'products' => $product_names,
					);
				}
				return array(
					'total_orders' => count( $out ),
					'orders'       => $out,
				);
			}
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get the user's order detail.
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_order_detail( \WP_REST_Request $req ): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not active',
				),
				400
			);
		}
		$order_id = intval( $req['order_id'] ?? 0 );
		if ( $order_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Invalid order_id',
				),
				400
			);
		}

		$data = Digital_Employee_WP_Bridge_Cache::get_or_set(
			"order_detail_{$order_id}",
			$user->ID,
			604800, // 7 days - order details change less frequently (should be cached for a week).
			function () use ( $user, $order_id ) {
				$o = wc_get_order( $order_id );
				if ( ! $o ) {
					return array(
						'error'   => true,
						'message' => 'Order not found',
						'_status' => 404,
					);
				}
				if ( intval( $o->get_customer_id() ) !== intval( $user->ID ) ) {
					return array(
						'error'   => true,
						'message' => 'Forbidden',
						'_status' => 403,
					);
				}
				$items = array();
				foreach ( $o->get_items() as $item ) {
					$items[] = array(
						'id'       => (int) $item->get_id(),
						'name'     => $item->get_name(),
						'quantity' => (int) $item->get_quantity(),
						'total'    => (string) $item->get_total(),
					);
				}
				return array(
					'id'       => (int) $o->get_id(),
					'date'     => $o->get_date_created() ? $o->get_date_created()->date( 'c' ) : null,
					'status'   => $o->get_status(),
					'total'    => (string) $o->get_total(),
					'currency' => $o->get_currency(),
					'items'    => $items,
					'billing'  => array(
						'first_name' => $o->get_billing_first_name(),
						'last_name'  => $o->get_billing_last_name(),
						'email'      => $o->get_billing_email(),
						'country'    => $o->get_billing_country(),
					),
				);
			}
		);

		// Handle error responses from cache.
		if ( isset( $data['error'] ) && isset( $data['_status'] ) ) {
			$status = $data['_status'];
			unset( $data['_status'] );
			return new \WP_REST_Response( $data, $status );
		}

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get the user's subscriptions.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_subscriptions(): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce Subscriptions not active',
				),
				400
			);
		}

		$data = Digital_Employee_WP_Bridge_Cache::get_or_set(
			'subscriptions',
			$user->ID,
			604800, // 7 days - subscriptions change less frequently (should be cached for a week).
			function () use ( $user ) {
				$subs = wcs_get_users_subscriptions( (int) $user->ID );
				$out  = array();
				// Statuses that count as "paid".
				$paid_statuses = array( 'completed', 'processing', 'refunded' );

				foreach ( $subs as $sub ) {
					/**
					 * Subscription object.
					 *
					 * @var WC_Subscription $sub
					 */
					$next        = $sub->get_time( 'next_payment' );
					$total_spent = 0.0;

					// Get product names from subscription items.
					$product_names = array();
					foreach ( $sub->get_items() as $item ) {
						$product_names[] = $item->get_name();
					}

					// Get parent order info.
					$parent_order_data = null;
					$parent_order_id   = $sub->get_parent_id();
					if ( $parent_order_id ) {
						$parent_order = wc_get_order( $parent_order_id );
						if ( $parent_order ) {
							$parent_status     = $parent_order->get_status();
							$parent_total      = (float) $parent_order->get_total();
							$parent_order_data = array(
								'id'     => (int) $parent_order_id,
								'status' => $parent_status,
								'date'   => $parent_order->get_date_created() ? $parent_order->get_date_created()->date( 'c' ) : null,
								'total'  => (string) $parent_total,
							);
							// Add to total spent if paid.
							if ( in_array( $parent_status, $paid_statuses, true ) ) {
								$total_spent += $parent_total;
							}
						}
					}

					// Get all renewal orders.
					$renewal_orders_data = array();
					$renewal_order_ids   = $sub->get_related_orders( 'ids', 'renewal' );
					if ( ! empty( $renewal_order_ids ) ) {
						foreach ( $renewal_order_ids as $renewal_id ) {
							$renewal_order = wc_get_order( $renewal_id );
							if ( $renewal_order ) {
								$renewal_status        = $renewal_order->get_status();
								$renewal_total         = (float) $renewal_order->get_total();
								$renewal_orders_data[] = array(
									'id'     => (int) $renewal_id,
									'status' => $renewal_status,
									'date'   => $renewal_order->get_date_created() ? $renewal_order->get_date_created()->date( 'c' ) : null,
									'total'  => (string) $renewal_total,
								);
								// Add to total spent if paid..
								if ( in_array( $renewal_status, $paid_statuses, true ) ) {
									$total_spent += $renewal_total;
								}
							}
						}
					}

					$out[] = array(
						'id'             => (int) $sub->get_id(),
						'status'         => $sub->get_status(),
						'start_date'     => $sub->get_date( 'date_created' ),
						'next_payment'   => $next ? gmdate( 'c', $next ) : null,
						'end_date'       => $sub->get_date( 'end' ) ? $sub->get_date( 'end' ) : null,
						'total'          => (string) $sub->get_total(),
						'currency'       => $sub->get_currency(),
						'products'       => $product_names,
						'parent_order'   => $parent_order_data,
						'renewal_orders' => $renewal_orders_data,
						'renewal_count'  => count( $renewal_orders_data ),
						'total_spent'    => number_format( $total_spent, 2, '.', '' ),
					);
				}
				return array(
					'total_subscriptions' => count( $out ),
					'subscriptions'       => $out,
				);
			}
		);

		return new \WP_REST_Response( $data, 200 );
	}

	/**
	 * Get all active licenses for the logged-in user.
	 * Returns list of licenses with product name, variation name, number of licenses, and type.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_get_licenses(): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}

		// Check if WOO_Authorization_Data class exists.
		if ( ! class_exists( 'WOO_Authorization_Data' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'License manager not available',
				),
				400
			);
		}

		return Digital_Employee_WP_Bridge_Cache::get_or_set(
			'licenses_all',
			$user->ID,
			604800, // 7 days - licenses change less frequently (should be cached for a week).
			function () use ( $user ) {
				global $wpdb;

				// Get all active authorizations for this user.
				$authorizations = WOO_Authorization_Data::get_results(
					"user_id={$user->ID} AND recycle=0 AND plugin_or_theme='plugin'",
					'authorization_id ASC'
				);

				if ( ! is_array( $authorizations ) || count( $authorizations ) === 0 ) {
					return new \WP_REST_Response(
						array(
							'success'  => true,
							'licenses' => array(),
							'total'    => 0,
						),
						200
					);
				}

				$licenses = array();
				foreach ( $authorizations as $auth ) {
					// Get product name.
					$product      = wc_get_product( $auth->product_id );
					$product_name = $product ? $product->get_name() : 'Unknown Product';
					if ( ( 1 === (int) $auth->special || ! $product || ! $product->is_visible() ) && function_exists( 'get_a3_plugin_name' ) ) {
						$product_name = get_a3_plugin_name( $auth->plugin );
					}

					// Get variation name if applicable.
					$variation_name = '';
					if ( $auth->variation_id > 0 ) {
						$variation      = wc_get_product( $auth->variation_id );
						$variation_name = $variation ? $variation->get_name() : '';
					}

					// Determine license type.
					$license_type       = 'lifetime subscription';
					$is_club_membership = false;
					if ( 'subscription' === $auth->product_type ) {
						$license_type = 'annual subscription';

						$is_club_membership = get_post_meta( $auth->product_id, '_enable_club_membership', true ) === '1';
						if ( $is_club_membership ) {
							$license_type = 'monthly subscription';
						}
					}

					$number_licenses = (int) $auth->number_licenses;
					$connected_sites = array();
					if ( $is_club_membership ) {
						global $wpdb;
						$membership_data = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}user_membership WHERE user_id=%d", $user->ID ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

						if ( $membership_data ) {
							$number_licenses = $membership_data->number_sites;
						}

						$membership_sites = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}user_sites WHERE user_login=%s AND is_available=%d ", $user->user_login, 1 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
						if ( $membership_sites ) {
							foreach ( $membership_sites as $membership_site ) {
								$connected_sites[] = array(
									'connected-site' => $membership_site->domain_address,
									'connected-date' => $membership_site->date_loggedin,
									'type'           => 'subscription',
									'is-club-member' => 1,
								);
							}
						}
					} else {
						$license_sites = WOO_Licenses_Data::get_results( 'authorization_id=' . $auth->authorization_id );
						if ( $license_sites ) {
							foreach ( $license_sites as $license_site ) {
								$connected_sites[] = array(
									'connected-site' => $license_site->domain_installed,
									'connected-date' => $license_site->date_activated,
									'type'           => 'subscription' === $auth->product_type ? 'subscription' : 'lifetime',
									'is-club-member' => 0,
								);
							}
						}
					}

					// Check if expired.
					$is_expired = false;
					if ( 'subscription' === $auth->product_type && '1970-01-01 00:00:00' !== $auth->date_expired && $auth->date_expired < gmdate( 'Y-m-d H:i:s' ) ) {
						$is_expired = true;
					}

					$licenses[] = array(
						'product_name'       => $product_name,
						'variation_name'     => $variation_name,
						'number_licenses'    => $number_licenses,
						'license_type'       => $license_type,
						'is_expired'         => $is_expired,
						'date_purchased'     => $auth->date_purchased,
						'date_expired'       => 'subscription' === $auth->product_type ? $auth->date_expired : '',
						'connected_sites'    => $connected_sites,
						'total_sites'        => count( $connected_sites ),
						'available_licenses' => max( 0, $number_licenses - count( $connected_sites ) ),
					);
				}

				return new \WP_REST_Response(
					array(
						'success'        => true,
						'licenses'       => $licenses,
						'total_licenses' => count( $licenses ),
					),
					200
				);
			}
		);
	}

	/**
	 * Get user support tickets from bbPress.
	 * Returns list of topics (tickets) created by the logged-in user.
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function bbp_get_user_tickets( \WP_REST_Request $req ): \WP_REST_Response {
		$user = wp_get_current_user();
		if ( ! $user || 0 === $user->ID ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Unauthorized',
				),
				401
			);
		}

		// Check if bbPress is active.
		if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'bbPress not available',
				),
				400
			);
		}

		$limit  = intval( $req['limit'] ?? -1 );
		$status = sanitize_text_field( (string) ( $req['status'] ?? 'any' ) );

		// Build cache key.
		$cache_key = 'bbp_tickets_' . $limit . '_' . $status;

		return Digital_Employee_WP_Bridge_Cache::get_or_set(
			$cache_key,
			$user->ID,
			86400, // 1 day - tickets change less frequently (should be cached for a day).
			function () use ( $user, $limit, $status ) {
				// Build query args.
				$args = array(
					'post_type'      => bbp_get_topic_post_type(),
					'author'         => $user->ID,
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'post_status'    => 'any' === $status ? array( 'publish', 'closed', 'private' ) : $status,
				);

				// Get topics.
				$query = new \WP_Query( $args );

				if ( ! $query->have_posts() ) {
					return new \WP_REST_Response(
						array(
							'success' => true,
							'tickets' => array(),
							'total'   => 0,
						),
						200
					);
				}

				$tickets = array();
				while ( $query->have_posts() ) {
					$query->the_post();
					$topic_id = get_the_ID();

					// Get forum info.
					$forum_id   = get_post_meta( $topic_id, '_bbp_forum_id', true );
					$forum_name = $forum_id ? get_the_title( $forum_id ) : '';

					// Get last activity.
					$last_active = get_post_meta( $topic_id, '_bbp_last_active_time', true );

					// Get topic status.
					$topic_status = get_post_status( $topic_id );
					$is_closed    = 'closed' === $topic_status || get_post_meta( $topic_id, '_bbp_status', true ) === 'closed';

					// Get issue (from a3-bbpress-support-forum plugin).
					$issue_id   = get_post_meta( $topic_id, 'post_topic_issue_' . $topic_id, true );
					$issue_name = '';
					if ( $issue_id ) {
						$issue_term = get_term( $issue_id, 'topic_issue' );
						if ( $issue_term && ! is_wp_error( $issue_term ) ) {
							$issue_name = $issue_term->name;
						}
					}

					// Get state (from a3-bbpress-support-forum plugin).
					$state_id   = get_post_meta( $topic_id, 'post_topic_state_' . $topic_id, true );
					$state_name = '';
					if ( $state_id ) {
						$state_term = get_term( $state_id, 'topic_state' );
						if ( $state_term && ! is_wp_error( $state_term ) ) {
							$state_name = $state_term->name;
						}
					}

					$tickets[ $topic_id ] = array(
						'id'            => (int) $topic_id,
						'title'         => get_the_title(),
						'content'       => get_the_content(),
						'status'        => $is_closed ? 'closed' : 'open',
						'forum_name'    => $forum_name,
						'issue'         => $issue_name,
						'state'         => $state_name,
						'date_created'  => get_the_date( 'Y-m-d H:i:s' ),
						'last_activity' => $last_active ? $last_active : get_the_date( 'Y-m-d H:i:s' ),
						'url'           => get_permalink( $topic_id ),
					);

					// Get all replies for this topic.
					$replies = array();
					if ( function_exists( 'bbp_get_reply_post_type' ) ) {
						$reply_query = new \WP_Query(
							array(
								'post_type'      => bbp_get_reply_post_type(),
								'post_parent'    => $topic_id,
								'posts_per_page' => -1,
								'orderby'        => 'date',
								'order'          => 'ASC',
								'post_status'    => array( 'publish', 'private' ),
							)
						);

						if ( $reply_query->have_posts() ) {
							while ( $reply_query->have_posts() ) {
								$reply_query->the_post();
								$reply_id        = get_the_ID();
								$reply_author_id = get_post_field( 'post_author', $reply_id );
								$reply_author    = get_userdata( $reply_author_id );
								$reply_content   = get_post_field( 'post_content', $reply_id );
								$reply_date      = get_post_field( 'post_date', $reply_id );

								$replies[] = array(
									'id'          => (int) $reply_id,
									'content'     => $reply_content,
									'author_id'   => (int) $reply_author_id,
									'author_name' => $reply_author ? $reply_author->display_name : '',
									'date'        => $reply_date ? gmdate( 'Y-m-d H:i:s', strtotime( $reply_date ) ) : '',
									'status'      => get_post_status( $reply_id ),
								);
							}
							wp_reset_postdata();
						}
					}

					$tickets[ $topic_id ]['reply_count'] = count( $replies );
					$tickets[ $topic_id ]['replies']     = $replies;
				}

				wp_reset_postdata();

				return new \WP_REST_Response(
					array(
						'success'       => true,
						'tickets'       => $tickets,
						'total_tickets' => count( $tickets ),
					),
					200
				);
			}
		);
	}

	/**
	 * Get all published products with their variations.
	 * This is a public endpoint (no user context needed) for LLM product selection.
	 * Results are cached for 1 hour.
	 *
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.1.0
	 */
	public static function wc_get_products_list(): \WP_REST_Response {
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not available',
				),
				400
			);
		}

		return Digital_Employee_WP_Bridge_Cache::get_or_set(
			'products_list',
			0, // User ID 0 for public/shared cache.
			3600, // 1 hour - products change less frequently (should be cached for an hour).
			function () {
				// Get all published products.
				$args = array(
					'status'  => 'publish',
					'limit'   => -1,
					'orderby' => 'title',
					'order'   => 'ASC',
				);

				$products_data = array();
				$products      = wc_get_products( $args );

				foreach ( $products as $product ) {
					$product_info = array(
						'id'   => $product->get_id(),
						'name' => $product->get_name(),
						'type' => $product->get_type(),
					);

					// If variable product, get variations.
					if ( $product->is_type( 'variable' ) ) {
						$variations_data = array();
						$variations      = $product->get_available_variations();

						foreach ( $variations as $variation_data ) {
							$variation = wc_get_product( $variation_data['variation_id'] );
							if ( ! $variation ) {
								continue;
							}

							// Get variation attributes as readable string.
							$attributes      = $variation->get_variation_attributes();
							$attribute_names = array();
							foreach ( $attributes as $attr_name => $attr_value ) {
								$attribute_names[] = $attr_value;
							}

							$variations_data[] = array(
								'id'         => $variation_data['variation_id'],
								'name'       => $variation->get_name(),
								'attributes' => implode( ', ', $attribute_names ),
								'price'      => $variation->get_price(),
							);
						}

						$product_info['variations'] = $variations_data;
					} else {
						$product_info['price'] = $product->get_price();
					}

					$products_data[] = $product_info;
				}

				return new \WP_REST_Response(
					array(
						'success'        => true,
						'products'       => $products_data,
						'total_products' => count( $products_data ),
					),
					200
				);
			}
		);
	}

	/**
	 * Add product to cart.
	 * Accepts product_id (required) and optional variation_id.
	 * If product is variable and no variation_id provided, uses first available variation.
	 * Supports both authenticated users and guest checkout (if enabled in WooCommerce).
	 *
	 * @param \WP_REST_Request $req The request object.
	 * @return \WP_REST_Response The response object.
	 * @since 0.1.0
	 * @version 0.2.0
	 */
	public static function wc_add_to_cart( \WP_REST_Request $req ): \WP_REST_Response {
		if ( ! function_exists( 'WC' ) || ! class_exists( 'WC_Cart' ) ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'WooCommerce not available',
				),
				400
			);
		}

		// Get current user (may be guest).
		$user     = wp_get_current_user();
		$is_guest = ( ! $user || 0 === $user->ID );

		// If guest, check if guest checkout is allowed.
		if ( $is_guest ) {
			$guest_checkout_enabled = get_option( 'woocommerce_enable_guest_checkout', 'no' );
			if ( 'yes' !== $guest_checkout_enabled ) {
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => 'Guest checkout is disabled. Please log in to add items to cart.',
						'code'    => 'guest_checkout_disabled',
					),
					403
				);
			}
		}

		$product_id   = intval( $req['product_id'] ?? 0 );
		$variation_id = intval( $req['variation_id'] ?? 0 );
		$quantity     = intval( $req['quantity'] ?? 1 );

		// Validate product_id.
		if ( $product_id <= 0 ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'product_id is required',
				),
				400
			);
		}

		// Verify product exists and is purchasable.
		$product = wc_get_product( $product_id );
		if ( ! $product ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Product not found',
				),
				404
			);
		}
		if ( ! $product->is_purchasable() ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Product is not purchasable',
				),
				400
			);
		}

		// Handle variable products.
		if ( $product->is_type( 'variable' ) ) {
			$variations = $product->get_available_variations();

			if ( empty( $variations ) ) {
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => 'No available variations for this product',
					),
					400
				);
			}

			// Validate variation_id if provided.
			if ( $variation_id > 0 ) {
				$valid_variation_ids = array_column( $variations, 'variation_id' );
				if ( ! in_array( $variation_id, $valid_variation_ids, true ) ) {
					return new \WP_REST_Response(
						array(
							'error'   => true,
							'message' => 'Invalid variation_id for this product',
						),
						400
					);
				}
			} else {
				// Use first available variation if not specified.
				$variation_id = (int) $variations[0]['variation_id'];
			}
		}

		// Add to cart.
		try {
			$current_user_id = get_current_user_id();

			// Initialize WooCommerce session if not already initialized (required for REST API context).
			if ( ! isset( WC()->session ) || '' === WC()->session || is_null( WC()->session ) ) {
				WC()->session = new \WC_Session_Handler();
				WC()->session->init();
			}

			// Set customer ID in session for logged-in user.
			if ( $current_user_id > 0 ) {
				WC()->session->set( 'customer_id', $current_user_id );
			} else {
				self::parse_wc_session_cookie_from_request( $req );
			}

			// Set customer session cookie if needed.
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}

			// Load cart from session (this will use cookie if present, or create new session).
			if ( ! did_action( 'woocommerce_load_cart_from_session' ) ) {
				wc_load_cart();
			} else {
				// If cart was already loaded, ensure we reload it to get latest session data.
				WC()->cart->get_cart_from_session();
			}

			// Load user's existing cart from persistent storage (user meta) for logged-in users.
			// This ensures we ADD to existing cart instead of replacing it.
			if ( $current_user_id > 0 ) {
				$saved_cart = get_user_meta( $current_user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), true );
				if ( ! empty( $saved_cart['cart'] ) && is_array( $saved_cart['cart'] ) ) {
					// Merge saved cart into session.
					WC()->session->set( 'cart', $saved_cart['cart'] );
					// Reload cart from session.
					WC()->cart->get_cart_from_session();
				}
			}

			$cart_item_key = WC()->cart->add_to_cart(
				$product_id,
				$quantity,
				$variation_id
			);

			// Save cart back to persistent storage for logged-in user.
			if ( $current_user_id > 0 && $cart_item_key ) {
				$cart_to_save = array( 'cart' => WC()->session->get( 'cart', array() ) );
				update_user_meta( $current_user_id, '_woocommerce_persistent_cart_' . get_current_blog_id(), $cart_to_save );
			}

			// For guest users, ensure session data is saved to database.
			if ( 0 === $current_user_id && $cart_item_key && WC()->session ) {
				// Trigger session save to persist cart data.
				WC()->session->save_data();
			}

			if ( ! $cart_item_key ) {
				return new \WP_REST_Response(
					array(
						'error'   => true,
						'message' => 'Failed to add product to cart',
					),
					400
				);
			}

			// Invalidate user's cart cache.
			if ( $current_user_id > 0 ) {
				Digital_Employee_WP_Bridge_Cache::invalidate_user( $current_user_id, 'cart_' );
			} else {
				// For guests, invalidate by session customer ID.
				$customer_id = WC()->session ? WC()->session->get_customer_id() : '';
				if ( $customer_id ) {
					Digital_Employee_WP_Bridge_Cache::invalidate_user( (int) $customer_id, 'cart_' );
				}
			}

			// Get updated cart info.
			$cart         = WC()->cart;
			$product_info = array(
				'id'   => (int) $product->get_id(),
				'name' => $product->get_name(),
			);

			// Add variation info if applicable.
			if ( $variation_id > 0 ) {
				$variation = wc_get_product( $variation_id );
				if ( $variation ) {
					$product_info['variation_id']   = (int) $variation_id;
					$product_info['variation_name'] = $variation->get_name();
					$attributes                     = $variation->get_variation_attributes();
					$product_info['attributes']     = $attributes;
				}
			}

			// Prepare response data.
			$response_data = array(
				'success'       => true,
				'message'       => 'Product added to cart',
				'cart_item_key' => $cart_item_key,
				'product'       => $product_info,
				'cart'          => array(
					'count'    => $cart->get_cart_contents_count(),
					'subtotal' => (string) $cart->get_subtotal(),
					'total'    => (string) $cart->get_total( 'edit' ),
					'currency' => get_woocommerce_currency(),
					'cart_url' => wc_get_cart_url(),
				),
			);

			// For guest users, return session cookie info so browser can sync.
			if ( 0 === $current_user_id && WC()->session ) {
				$cookie_name = 'wp_woocommerce_session_' . COOKIEHASH;

				// Try to get cookie value from $_COOKIE first (if it was sent in request).
				$cookie_value = isset( $_COOKIE[ $cookie_name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $cookie_name ] ) ) : '';

				// If not in $_COOKIE, build it from session handler (same way WooCommerce does).
				if ( empty( $cookie_value ) ) {
					$session_handler = WC()->session;
					$customer_id     = $session_handler->get_customer_id();

					if ( $customer_id ) {
						// Use reflection to access protected properties (session expiration/expiring).
						$reflection = new \ReflectionClass( $session_handler );

						$expiration_prop = $reflection->getProperty( '_session_expiration' );
						$expiration_prop->setAccessible( true );
						$session_expiration = $expiration_prop->getValue( $session_handler );

						$expiring_prop = $reflection->getProperty( '_session_expiring' );
						$expiring_prop->setAccessible( true );
						$session_expiring = $expiring_prop->getValue( $session_handler );

						// Build cookie hash (same method as WooCommerce).
						$hash_method = $reflection->getMethod( 'hash' );
						$hash_method->setAccessible( true );
						$cookie_hash = $hash_method->invoke( $session_handler, $customer_id . '|' . (string) $session_expiration );

						// Build cookie value (format: customer_id|expiration|expiring|hash).
						$cookie_value = $customer_id . '|' . (string) $session_expiration . '|' . (string) $session_expiring . '|' . $cookie_hash;
					}
				}

				// Return cookie info so browser can sync.
				if ( ! empty( $cookie_value ) ) {
					$response_data['session_cookie'] = array(
						'name'   => $cookie_name,
						'value'  => $cookie_value,
						'path'   => COOKIEPATH,
						'domain' => COOKIE_DOMAIN,
					);
				}
			}

			return new \WP_REST_Response( $response_data, 200 );
		} catch ( \Exception $e ) {
			return new \WP_REST_Response(
				array(
					'error'   => true,
					'message' => 'Error adding to cart: ' . $e->getMessage(),
				),
				500
			);
		}
	}
}
