<?php
/**
 * Write Rank Math fields and optional related-reading links.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies an SEO pack to a post.
 */
class Applier {
	/**
	 * @param array<string, mixed> $pack Normalized suggestion.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function apply( int $postId, array $pack, bool $dryRun ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'invalid_id', '文章 ID 必須是正整數。' );
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', '找不到這篇文章。' );
		}
		if ( ! current_user_can( 'edit_post', $postId ) ) {
			return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
		}

		$changes = array();
		$seoTitle = isset( $pack['seoTitle'] ) ? trim( (string) $pack['seoTitle'] ) : '';
		$description = isset( $pack['description'] ) ? trim( (string) $pack['description'] ) : '';
		$focus = isset( $pack['focusKeywords'] ) ? trim( (string) $pack['focusKeywords'] ) : '';
		$excerpt = isset( $pack['excerpt'] ) ? trim( (string) $pack['excerpt'] ) : '';
		$imageAlt = isset( $pack['imageAlt'] ) ? trim( (string) $pack['imageAlt'] ) : '';
		$relatedLinks = isset( $pack['relatedLinks'] ) && is_array( $pack['relatedLinks'] ) ? $pack['relatedLinks'] : array();

		if ( $seoTitle !== '' ) {
			$changes['rank_math_title'] = $seoTitle;
		}
		if ( $description !== '' ) {
			$changes['rank_math_description'] = $description;
		}
		if ( $focus !== '' ) {
			$changes['rank_math_focus_keyword'] = $focus;
		}
		if ( $excerpt !== '' && $excerpt !== (string) $post->post_excerpt ) {
			$changes['post_excerpt'] = $excerpt;
		}
		if ( $imageAlt !== '' ) {
			$thumbId = (int) get_post_thumbnail_id( $postId );
			if ( $thumbId > 0 ) {
				$changes['image_alt'] = array(
					'attachmentId' => $thumbId,
					'alt'          => $imageAlt,
				);
			}
		}

		$relatedHtml = $this->relatedReadingHtml( $relatedLinks );
		$content     = (string) $post->post_content;
		if ( $relatedHtml !== '' && strpos( $content, 'seo-related-reading' ) === false ) {
			$changes['related_reading'] = $relatedHtml;
		}

		if ( $changes === array() ) {
			return new \WP_Error( 'empty_pack', '沒有可套用的 SEO 欄位。' );
		}

		if ( $dryRun ) {
			return array(
				'id'      => $postId,
				'dryRun'  => true,
				'changes' => $changes,
			);
		}

		if ( isset( $changes['rank_math_title'] ) ) {
			update_post_meta( $postId, 'rank_math_title', $changes['rank_math_title'] );
		}
		if ( isset( $changes['rank_math_description'] ) ) {
			update_post_meta( $postId, 'rank_math_description', $changes['rank_math_description'] );
		}
		if ( isset( $changes['rank_math_focus_keyword'] ) ) {
			update_post_meta( $postId, 'rank_math_focus_keyword', $changes['rank_math_focus_keyword'] );
		}
		if ( isset( $changes['image_alt'] ) && is_array( $changes['image_alt'] ) ) {
			update_post_meta(
				(int) $changes['image_alt']['attachmentId'],
				'_wp_attachment_image_alt',
				(string) $changes['image_alt']['alt']
			);
		}

		$postUpdate = array( 'ID' => $postId );
		if ( isset( $changes['post_excerpt'] ) ) {
			$postUpdate['post_excerpt'] = $changes['post_excerpt'];
		}
		if ( isset( $changes['related_reading'] ) ) {
			$postUpdate['post_content'] = rtrim( $content ) . "\n" . $changes['related_reading'] . "\n";
		}
		if ( count( $postUpdate ) > 1 ) {
			$result = wp_update_post( wp_slash( $postUpdate ), true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		$stored = update_post_meta( $postId, '_ai_seo_assist_suggestion', wp_json_encode( $pack, JSON_UNESCAPED_UNICODE ) );
		if ( $stored === false && get_post_meta( $postId, '_ai_seo_assist_suggestion', true ) === '' ) {
			error_log( 'AI SEO Assist: failed to store suggestion meta for post ' . $postId );
		}

		return array(
			'id'      => $postId,
			'dryRun'  => false,
			'changes' => array_keys( $changes ),
			'permalink' => get_permalink( $postId ),
		);
	}

	/**
	 * @param array<int, mixed> $relatedLinks
	 */
	private function relatedReadingHtml( array $relatedLinks ): string {
		$parts = array();
		foreach ( $relatedLinks as $link ) {
			if ( ! is_array( $link ) ) {
				continue;
			}
			$url   = esc_url( (string) ( $link['url'] ?? '' ) );
			$label = esc_html( (string) ( $link['label'] ?? '' ) );
			if ( $url === '' || $label === '' ) {
				continue;
			}
			$parts[] = '<a href="' . $url . '">' . $label . '</a>';
			if ( count( $parts ) >= 4 ) {
				break;
			}
		}
		if ( count( $parts ) < 2 ) {
			return '';
		}
		return '<p class="seo-related-reading">延伸閱讀：' . implode( '、', $parts ) . '。</p>';
	}
}
