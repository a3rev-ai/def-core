<?php
/**
 * Class DEF_Core_Tool_BbPress_Tickets
 *
 * Built-in bbPress tickets tool. Conditionally registers when bbPress is active.
 * Returns the authenticated user's bbPress topics with replies.
 *
 * Absorbed from the standalone def-bbpress addon plugin.
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
 * Class DEF_Core_Tool_BbPress_Tickets
 *
 * @package def-core
 * @since 1.8.0
 * @version 1.8.0
 */
class DEF_Core_Tool_BbPress_Tickets extends DEF_Core_Tool_Base {

	/**
	 * Initialize the tool.
	 *
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	protected function init(): void {
		$this->name    = __( 'bbPress Tickets', 'def-core' );
		$this->route   = '/tools/bbp/tickets';
		$this->methods = array( 'GET' );
		$this->module  = 'bbpress';

		// Invalidate cache when topics change.
		add_action( 'bbp_new_topic', array( $this, 'on_ticket_changed' ), 10, 1 );
		add_action( 'bbp_edit_topic', array( $this, 'on_ticket_changed' ), 10, 1 );
		add_action( 'bbp_closed_topic', array( $this, 'on_ticket_changed' ), 10, 1 );
		add_action( 'bbp_opened_topic', array( $this, 'on_ticket_changed' ), 10, 1 );
	}

	/**
	 * Invalidate cache when a bbPress topic changes.
	 *
	 * @param int $topic_id The topic ID.
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	public function on_ticket_changed( int $topic_id ): void {
		$topic = get_post( $topic_id );
		if ( $topic && $topic->post_author > 0 ) {
			DEF_Core_Cache::invalidate_user( (int) $topic->post_author, 'bbp_tickets_' );
		}
	}

	/**
	 * Only register if bbPress is active.
	 *
	 * @return bool
	 * @since 1.8.0
	 * @version 1.8.0
	 */
	protected function should_register(): bool {
		if ( ! function_exists( 'bbpress' ) && ! class_exists( 'bbPress' ) ) {
			return false;
		}
		if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Handle the request — return the user's bbPress topics with replies.
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

		if ( ! function_exists( 'bbp_get_topic_post_type' ) ) {
			return $this->error_response( 'bbPress not available', 400 );
		}

		$limit  = intval( $request->get_param( 'limit' ) ?? -1 );
		$status = sanitize_text_field( (string) ( $request->get_param( 'status' ) ?? 'any' ) );

		$cache_key = 'bbp_tickets_' . $limit . '_' . $status;

		return DEF_Core_Cache::get_or_set(
			$cache_key,
			$user->ID,
			86400,
			function () use ( $user, $limit, $status ) {
				$args = array(
					'post_type'      => bbp_get_topic_post_type(),
					'author'         => $user->ID,
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'post_status'    => 'any' === $status ? array( 'publish', 'closed', 'private' ) : $status,
				);

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

					$forum_id   = get_post_meta( $topic_id, '_bbp_forum_id', true );
					$forum_name = $forum_id ? get_the_title( $forum_id ) : '';

					$last_active = get_post_meta( $topic_id, '_bbp_last_active_time', true );

					$topic_status = get_post_status( $topic_id );
					$is_closed    = 'closed' === $topic_status || get_post_meta( $topic_id, '_bbp_status', true ) === 'closed';

					// Support a3-bbpress-support-forum plugin issue/state taxonomy.
					$issue_id   = get_post_meta( $topic_id, 'post_topic_issue_' . $topic_id, true );
					$issue_name = '';
					if ( $issue_id ) {
						$issue_term = get_term( $issue_id, 'topic_issue' );
						if ( $issue_term && ! is_wp_error( $issue_term ) ) {
							$issue_name = $issue_term->name;
						}
					}

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

					// Get replies for this topic.
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

								$replies[] = array(
									'id'          => (int) $reply_id,
									'content'     => get_post_field( 'post_content', $reply_id ),
									'author_id'   => (int) $reply_author_id,
									'author_name' => $reply_author ? $reply_author->display_name : '',
									'date'        => gmdate( 'Y-m-d H:i:s', strtotime( get_post_field( 'post_date', $reply_id ) ) ),
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
}
