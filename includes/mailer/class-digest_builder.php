<?php
/**
 * Digest content builder.
 *
 * @package WeSubscribeToPosts
 */

namespace WSTP\Mailer;

use WSTP\DB\Event_Log_Repository;
use WP_Post;

defined( 'ABSPATH' ) || exit;

/**
 * Builds digest payload for rendering/sending.
 */
final class Digest_Builder {
	/**
	 * Event repository.
	 *
	 * @var Event_Log_Repository
	 */
	private Event_Log_Repository $event_repository;

	/**
	 * Total posts matched in latest collection.
	 *
	 * @var int
	 */
	private int $last_collection_total = 0;

	/**
	 * Constructor.
	 *
	 * @param Event_Log_Repository $event_repository Event repository.
	 */
	public function __construct( Event_Log_Repository $event_repository ) {
		$this->event_repository = $event_repository;
	}

	/**
	 * Build digest payload from subscriber and frequency.
	 *
	 * @param array<string,mixed> $subscriber Subscriber row.
	 * @param string              $frequency Frequency key.
	 * @param bool                $is_preview Whether this is preview mode.
	 * @return array<string,mixed>
	 */
	public function build_payload( array $subscriber, string $frequency, bool $is_preview = false ): array {
		$posts = $this->collect_posts( $subscriber, $frequency, $is_preview );
		$available_total = $this->last_collection_total > 0 ? $this->last_collection_total : count( $posts );
		$truncated_count = max( 0, $available_total - count( $posts ) );

		return array(
			'subject'            => $this->build_subject( $frequency, $is_preview, count( $posts ) ),
			'posts'              => array_map( array( $this, 'map_post_to_email_data' ), $posts ),
			'posts_total'        => $available_total,
			'posts_truncated_by' => $truncated_count,
		);
	}

	/**
	 * Query posts for this digest.
	 *
	 * @param array<string,mixed> $subscriber Subscriber row.
	 * @param string              $frequency Frequency key.
	 * @param bool                $is_preview Preview mode.
	 * @return array<int,WP_Post>
	 */
	public function collect_posts( array $subscriber, string $frequency, bool $is_preview = false ): array {
		$this->last_collection_total = 0;

		if ( $is_preview ) {
			$query = new \WP_Query(
				array(
					'post_type'      => 'post',
					'post_status'    => 'publish',
					'posts_per_page' => 3,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$this->last_collection_total = is_array( $query->posts ) ? count( $query->posts ) : 0;
			return is_array( $query->posts ) ? $query->posts : array();
		}

		$after_date = $this->resolve_after_date( $subscriber, $frequency );
		$max_posts  = $this->resolve_max_posts( $frequency );
		$query      = new \WP_Query(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'posts_per_page' => $max_posts > 0 ? $max_posts : -1,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'date_query'     => array(
					array(
						'after'     => $after_date,
						'inclusive' => false,
					),
				),
			)
		);

		$this->last_collection_total = isset( $query->found_posts ) ? (int) $query->found_posts : 0;
		return is_array( $query->posts ) ? $query->posts : array();
	}

	/**
	 * Determine lower date boundary.
	 *
	 * @param array<string,mixed> $subscriber Subscriber row.
	 * @param string              $frequency Frequency key.
	 * @return string
	 */
	private function resolve_after_date( array $subscriber, string $frequency ): string {
		$subscriber_id = isset( $subscriber['id'] ) ? (int) $subscriber['id'] : 0;
		if ( $subscriber_id > 0 ) {
			$frequency_last_sent_at = $this->event_repository->get_last_success_sent_at( $subscriber_id, $frequency );
			if ( '' !== $frequency_last_sent_at ) {
				return $frequency_last_sent_at;
			}
		}

		$modifier = match ( $frequency ) {
			'weekly' => '-7 days',
			'monthly' => '-1 month',
			default => '-1 day',
		};

		$base_timestamp = current_time( 'timestamp' );
		$after_ts       = strtotime( $modifier, $base_timestamp );
		if ( false === $after_ts ) {
			$after_ts = $base_timestamp;
		}

		$window_start = wp_date( 'Y-m-d H:i:s', $after_ts );

		if ( ! empty( $subscriber['confirmed_at'] ) ) {
			$confirmed_at = (string) $subscriber['confirmed_at'];
			return strtotime( $confirmed_at ) > strtotime( $window_start ) ? $confirmed_at : $window_start;
		}

		if ( ! empty( $subscriber['created_at'] ) ) {
			$created_at = (string) $subscriber['created_at'];
			return strtotime( $created_at ) > strtotime( $window_start ) ? $created_at : $window_start;
		}

		return $window_start;
	}

	/**
	 * Resolve max posts limit by frequency.
	 *
	 * @param string $frequency Frequency key.
	 * @return int
	 */
	private function resolve_max_posts( string $frequency ): int {
		$settings = $this->get_general_settings();
		$key      = match ( $frequency ) {
			'weekly' => 'max_posts_weekly',
			'monthly' => 'max_posts_monthly',
			default => 'max_posts_daily',
		};
		$value    = isset( $settings[ $key ] ) ? (int) $settings[ $key ] : 0;

		if ( $value < 0 ) {
			return 0;
		}

		return $value;
	}

	/**
	 * Build email subject.
	 *
	 * @param string $frequency Frequency key.
	 * @param bool   $is_preview Preview mode.
	 * @param int    $post_count Number of posts in digest.
	 * @return string
	 */
	private function build_subject( string $frequency, bool $is_preview, int $post_count ): string {
		$settings = $this->get_general_settings();

		if ( $is_preview ) {
			$template = isset( $settings['subject_preview'] ) ? (string) $settings['subject_preview'] : __( '[Preview] Latest post updates - {site_name}', 'we-subscribe-to-posts' );
			return $this->replace_subject_placeholders( $template, $frequency, $post_count );
		}

		$template = match ( $frequency ) {
			'weekly' => isset( $settings['subject_weekly'] ) ? (string) $settings['subject_weekly'] : __( 'Your weekly post updates - {site_name}', 'we-subscribe-to-posts' ),
			'monthly' => isset( $settings['subject_monthly'] ) ? (string) $settings['subject_monthly'] : __( 'Your monthly post updates - {site_name}', 'we-subscribe-to-posts' ),
			default => isset( $settings['subject_daily'] ) ? (string) $settings['subject_daily'] : __( 'Your latest post updates - {site_name}', 'we-subscribe-to-posts' ),
		};

		return $this->replace_subject_placeholders( $template, $frequency, $post_count );
	}

	/**
	 * Replace supported placeholders in subject templates.
	 *
	 * @param string $template Subject template.
	 * @param string $frequency Frequency key.
	 * @param int    $post_count Number of posts in digest.
	 * @return string
	 */
	private function replace_subject_placeholders( string $template, string $frequency, int $post_count ): string {
		$site_name = wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = strtr(
			$template,
			array(
				'{site_name}' => $site_name,
				'{frequency}' => $frequency,
				'{count}'     => (string) $post_count,
			)
		);
		$subject   = trim( wp_strip_all_tags( $subject ) );

		return '' !== $subject ? $subject : __( 'Your latest post updates', 'we-subscribe-to-posts' );
	}

	/**
	 * Read general plugin settings array.
	 *
	 * @return array<string,mixed>
	 */
	private function get_general_settings(): array {
		$stored = get_option( 'wstp_settings', array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		return wp_parse_args(
			$stored,
			array(
				'subject_daily'   => __( 'Your latest post updates - {site_name}', 'we-subscribe-to-posts' ),
				'subject_weekly'  => __( 'Your weekly post updates - {site_name}', 'we-subscribe-to-posts' ),
				'subject_monthly' => __( 'Your monthly post updates - {site_name}', 'we-subscribe-to-posts' ),
				'subject_preview' => __( '[Preview] Latest post updates - {site_name}', 'we-subscribe-to-posts' ),
				'max_posts_daily' => 0,
				'max_posts_weekly' => 0,
				'max_posts_monthly' => 0,
			)
		);
	}

	/**
	 * Convert post object to template data.
	 *
	 * @param WP_Post $post Post entity.
	 * @return array<string,string|int>
	 */
	private function map_post_to_email_data( WP_Post $post ): array {
		$featured_image_url = get_the_post_thumbnail_url( $post, 'large' );
		$excerpt            = has_excerpt( $post ) ? get_the_excerpt( $post ) : wp_trim_words( wp_strip_all_tags( (string) $post->post_content ), 42 );

		return array(
			'id'                => (int) $post->ID,
			'title'             => get_the_title( $post ),
			'permalink'         => get_permalink( $post ),
			'featured_image_id' => (int) get_post_thumbnail_id( $post ),
			'featured_image_url'=> $featured_image_url ? $featured_image_url : '',
			'excerpt'           => $excerpt,
		);
	}
}
