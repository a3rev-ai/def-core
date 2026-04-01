<?php
/**
 * Class DEF_Core_Tool_WC_Subscriptions
 *
 * Built-in WooCommerce Subscriptions tool. Conditionally registers when
 * WooCommerce and WooCommerce Subscriptions are active.
 * Returns the authenticated user's subscriptions with renewal history.
 *
 * Absorbed from the standalone def-wc-subscriptions addon plugin.
 *
 * @package def-core
 * @since 1.8.0
 * @version 1.8.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class DEF_Core_Tool_WC_Subscriptions
 *
 * @package def-core
 * @since 1.8.0
 * @version 1.8.0
 */
class DEF_Core_Tool_WC_Subscriptions extends DEF_Core_Tool_Base {

	/**
	 * Initialize the tool.
	 *
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	protected function init(): void {
		$this->name    = __( 'WooCommerce Subscriptions', 'def-core' );
		$this->route   = '/tools/wc/subscriptions';
		$this->methods = array( 'GET' );
		$this->module  = 'woocommerce-subscriptions';

		// Invalidate cache on subscription lifecycle events.
		add_action( 'woocommerce_checkout_subscription_created', array( $this, 'on_subscription_changed' ), 10, 1 );
		add_action( 'woocommerce_subscription_status_updated', array( $this, 'on_subscription_changed' ), 10, 1 );
		add_action( 'woocommerce_subscription_renewal_payment_complete', array( $this, 'on_subscription_changed' ), 10, 1 );
		add_action( 'woocommerce_subscription_payment_failed', array( $this, 'on_subscription_changed' ), 10, 1 );
		add_action( 'woocommerce_subscription_date_updated', array( $this, 'on_subscription_changed' ), 10, 1 );
	}

	/**
	 * Invalidate cache when a subscription changes state.
	 *
	 * @param \WC_Subscription $subscription The subscription object.
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	public function on_subscription_changed( $subscription ): void {
		$user_id = $subscription->get_user_id();
		if ( $user_id > 0 ) {
			DEF_Core_Cache::invalidate_user( (int) $user_id, 'subscriptions' );
		}
	}

	/**
	 * Only register if WooCommerce and WooCommerce Subscriptions are active.
	 *
	 * @return bool
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	protected function should_register(): bool {
		if ( ! class_exists( 'WooCommerce' ) && ! function_exists( 'WC' ) ) {
			return false;
		}
		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return false;
		}
		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Handle the request — return the user's subscriptions with renewal history.
	 *
	 * @param \WP_REST_Request $request The request object.
	 * @return \WP_REST_Response
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	public function handle_request( \WP_REST_Request $request ): \WP_REST_Response {
		$user = $this->get_current_user();
		if ( ! $user ) {
			return $this->error_response( 'Unauthorized', 401 );
		}

		if ( ! function_exists( 'wcs_get_users_subscriptions' ) ) {
			return $this->error_response( 'WooCommerce Subscriptions not active', 400 );
		}

		return DEF_Core_Cache::get_or_set(
			'subscriptions',
			$user->ID,
			604800,
			function () use ( $user ) {
				$subs = wcs_get_users_subscriptions( (int) $user->ID );
				$out  = array();
				$paid_statuses = array( 'completed', 'processing' );

				foreach ( $subs as $sub ) {
					/**
					 * @var \WC_Subscription $sub
					 */
					$next        = $sub->get_time( 'next_payment' );
					$total_spent = 0.0;

					$product_names = array();
					foreach ( $sub->get_items() as $item ) {
						$product_names[] = $item->get_name();
					}

					// Parent order.
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
							if ( in_array( $parent_status, $paid_statuses, true ) ) {
								$total_spent += $parent_total;
							}
						}
					}

					// Renewal orders.
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

				return new \WP_REST_Response(
					array(
						'success'             => true,
						'total_subscriptions' => count( $out ),
						'subscriptions'       => $out,
					),
					200
				);
			}
		);
	}
}
