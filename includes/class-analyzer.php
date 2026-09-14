<?php
/**
 * Inspect a post against this site's SEO playbook.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns current SEO fields, content signals, and actionable issues.
 */
class Analyzer {
	/**
	 * Analyze one post.
	 *
	 * @return array<string, mixed>|\WP_Error
	 */
	public function analyze( int $postId ) {
		if ( $postId <= 0 ) {
			return new \WP_Error( 'invalid_id', '文章 ID 必須是正整數。' );
		}
		$post = get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return new \WP_Error( 'not_found', '找不到這篇文章。' );
		}
		if ( ! in_array( $post->post_type, array( 'post', 'page' ), true ) ) {
			return new \WP_Error( 'unsupported_type', '只支援文章與頁面。' );
		}

		$html          = (string) $post->post_content;
		$plain         = $this->plainText( $html );
		$opening       = $this->openingParagraph( $plain );
		$seoTitle      = (string) get_post_meta( $postId, 'rank_math_title', true );
		$description   = (string) get_post_meta( $postId, 'rank_math_description', true );
		$focus         = (string) get_post_meta( $postId, 'rank_math_focus_keyword', true );
		$primaryKeyword = $this->primaryKeyword( $focus );
		$headings      = $this->headings( $html );
		$internalLinks = $this->internalLinks( $html );
		$tags          = wp_get_post_terms( $postId, 'post_tag', array( 'fields' => 'names' ) );
		if ( is_wp_error( $tags ) ) {
			return $tags;
		}
		$thumbId = (int) get_post_thumbnail_id( $postId );
		$alt     = $thumbId > 0 ? (string) get_post_meta( $thumbId, '_wp_attachment_image_alt', true ) : '';

		$issues = $this->collectIssues(
			$post,
			$plain,
			$opening,
			$seoTitle,
			$description,
			$focus,
			$primaryKeyword,
			$headings,
			$internalLinks,
			is_array( $tags ) ? $tags : array(),
			$thumbId,
			$alt
		);

		$permalink = get_permalink( $post );
		if ( ! is_string( $permalink ) ) {
			$permalink = '';
		}

		return array(
			'id'              => $postId,
			'title'           => get_the_title( $post ),
			'slug'            => (string) $post->post_name,
			'status'          => (string) $post->post_status,
			'permalink'       => $permalink,
			'locale'          => get_locale(),
			'htmlLang'        => get_bloginfo( 'language' ),
			'seoTitle'        => $seoTitle,
			'description'     => $description,
			'focusKeywords'   => $focus,
			'primaryKeyword'  => $primaryKeyword,
			'excerpt'         => (string) $post->post_excerpt,
			'opening'         => $opening,
			'plainExcerpt'    => mb_substr( $plain, 0, 1800 ),
			'charCount'       => mb_strlen( $plain ),
			'headings'        => $headings,
			'internalLinks'   => $internalLinks,
			'internalLinkCount' => count( $internalLinks ),
			'tags'            => array_values( $tags ),
			'tagCount'        => count( $tags ),
			'featuredImageId' => $thumbId,
			'featuredImageAlt'=> $alt,
			'relatedCandidates' => $this->relatedCandidates( $post ),
			'issueCount'      => count( $issues ),
			'issues'          => $issues,
			'needsWork'       => $issues !== array(),
		);
	}

	/**
	 * List posts ordered by how much SEO work they still need.
	 *
	 * @return array<int, array<string, mixed>>|\WP_Error
	 */
	public function listNeedingWork( string $status, int $limit ) {
		$limit = max( 1, min( 50, $limit ) );
		$query = new \WP_Query(
			array(
				'post_type'           => array( 'post', 'page' ),
				'post_status'         => $status === 'any' ? array( 'publish', 'draft', 'pending', 'future' ) : $status,
				'posts_per_page'      => $limit,
				'orderby'             => 'modified',
				'order'               => 'DESC',
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
			)
		);

		$rows = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$report = $this->analyze( (int) $post->ID );
			if ( is_wp_error( $report ) ) {
				error_log( 'AI SEO Assist: skipped post ' . $post->ID . ' during list: ' . $report->get_error_message() );
				continue;
			}
			$rows[] = array(
				'id'         => $report['id'],
				'title'      => $report['title'],
				'status'     => $report['status'],
				'permalink'  => $report['permalink'],
				'issueCount' => $report['issueCount'],
				'needsWork'  => $report['needsWork'],
				'issues'     => $report['issues'],
			);
		}
		wp_reset_postdata();

		usort(
			$rows,
			static function ( array $left, array $right ): int {
				return (int) $right['issueCount'] <=> (int) $left['issueCount'];
			}
		);

		return $rows;
	}

	/**
	 * @param array<int, string>                                                     $tags
	 * @param array<int, array{tag:string,text:string}>                               $headings
	 * @param array<int, array{url:string,label:string}>                              $internalLinks
	 * @return array<int, array{code:string,severity:string,message:string}>
	 */
	private function collectIssues(
		\WP_Post $post,
		string $plain,
		string $opening,
		string $seoTitle,
		string $description,
		string $focus,
		string $primaryKeyword,
		array $headings,
		array $internalLinks,
		array $tags,
		int $thumbId,
		string $alt
	): array {
		$issues = array();

		if ( trim( $seoTitle ) === '' ) {
			$issues[] = $this->issue( 'missing_seo_title', 'warning', '尚未填寫 Rank Math SEO 標題。' );
		} elseif ( mb_strlen( $this->stripTokens( $seoTitle ) ) > 42 ) {
			$issues[] = $this->issue( 'long_seo_title', 'warning', 'SEO 標題偏長，搜尋結果很可能被截斷。' );
		}

		$descLength = mb_strlen( $description );
		if ( trim( $description ) === '' ) {
			$issues[] = $this->issue( 'missing_description', 'error', '尚未填寫 meta description。' );
		} elseif ( preg_match( '/資料截止/u', $description ) ) {
			$issues[] = $this->issue( 'weak_description', 'error', 'meta description 幾乎只有資料截止時間，沒有告訴搜尋者文章能解答什麼。' );
		} elseif ( $descLength < 70 ) {
			$issues[] = $this->issue( 'short_description', 'warning', 'meta description 偏短（目前 ' . $descLength . ' 字），建議寫 70–160 字。' );
		} elseif ( $descLength > 170 ) {
			$issues[] = $this->issue( 'long_description', 'warning', 'meta description 超過約 160 字，可能被截斷。' );
		}

		if ( trim( $focus ) === '' ) {
			$issues[] = $this->issue( 'missing_focus', 'error', '尚未填寫焦點關鍵字。' );
		} elseif ( in_array( $primaryKeyword, array( '台股', '臺股', '股市', '投資' ), true ) ) {
			$issues[] = $this->issue( 'broad_focus', 'warning', '主關鍵字過於寬泛（例如只填「台股」），建議加上日期或具體主題。' );
		}

		if ( $primaryKeyword !== '' ) {
			$titleHaystack = $seoTitle . ' ' . get_the_title( $post );
			if ( mb_stripos( $titleHaystack, $primaryKeyword ) === false ) {
				$issues[] = $this->issue( 'keyword_missing_title', 'warning', '主關鍵字沒有出現在標題或 SEO 標題。' );
			}
			if ( mb_stripos( $opening, $primaryKeyword ) === false ) {
				$issues[] = $this->issue( 'keyword_missing_opening', 'warning', '主關鍵字沒有自然出現在開頭第一段。' );
			}
			$headingBlob = '';
			foreach ( $headings as $heading ) {
				$headingBlob .= $heading['text'] . ' ';
			}
			if ( $headingBlob !== '' && mb_stripos( $headingBlob, $primaryKeyword ) === false ) {
				$issues[] = $this->issue( 'keyword_missing_heading', 'warning', '主關鍵字沒有出現在任何 H2/H3。' );
			}
		}

		$h2Count = 0;
		foreach ( $headings as $heading ) {
			if ( $heading['tag'] === 'h2' ) {
				++$h2Count;
			}
		}
		if ( $h2Count === 0 ) {
			$issues[] = $this->issue( 'missing_h2', 'warning', '正文沒有 H2，層級不夠清楚。' );
		}

		$linkCount = count( $internalLinks );
		if ( $linkCount < 2 ) {
			$issues[] = $this->issue( 'few_internal_links', 'error', '內部連結只有 ' . $linkCount . ' 個，建議補 2–4 個真正相關的連結。' );
		}

		if ( $thumbId <= 0 ) {
			$issues[] = $this->issue( 'missing_featured', 'warning', '尚未設定封面圖片。' );
		} elseif ( trim( $alt ) === '' ) {
			$issues[] = $this->issue( 'missing_alt', 'warning', '封面圖片沒有 alt 描述。' );
		}

		$tagCount = count( $tags );
		if ( $tagCount > 6 ) {
			$issues[] = $this->issue( 'too_many_tags', 'warning', '標籤有 ' . $tagCount . ' 個，建議保留 3–6 個會持續使用的標籤。' );
		}

		if ( trim( (string) $post->post_excerpt ) === '' ) {
			$issues[] = $this->issue( 'missing_excerpt', 'notice', '尚未填寫文章摘要（excerpt）。' );
		}

		if ( preg_match( '/<h1\b/i', (string) $post->post_content ) ) {
			$issues[] = $this->issue( 'duplicate_h1', 'warning', '正文裡還有 H1，主題已經會輸出標題 H1，容易重複。' );
		}

		if ( get_locale() !== 'zh_TW' ) {
			$issues[] = $this->issue( 'locale_not_zh_tw', 'notice', '網站語言目前是 ' . get_locale() . '，內容若是臺灣繁體中文應使用 zh_TW。' );
		}

		$isStock = has_category( 'stock-views', $post ) || has_category( '股市觀點', $post );
		if ( $isStock && ! preg_match( '/不構成投資建議/u', $plain ) ) {
			$issues[] = $this->issue( 'missing_disclaimer', 'warning', '股市文章沒有標示「不構成投資建議」。' );
		}

		return $issues;
	}

	/**
	 * @return array{code:string,severity:string,message:string}
	 */
	private function issue( string $code, string $severity, string $message ): array {
		return array(
			'code'     => $code,
			'severity' => $severity,
			'message'  => $message,
		);
	}

	private function plainText( string $html ): string {
		$html = preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', '', $html ) ?? $html;
		$html = preg_replace( '#<!--.*?-->#s', '', $html ) ?? $html;
		$text = wp_strip_all_tags( $html, false );
		$text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$text = preg_replace( "/[ \t]+/u", ' ', $text ) ?? $text;
		$text = preg_replace( "/\n{3,}/u", "\n\n", $text ) ?? $text;
		return trim( $text );
	}

	private function openingParagraph( string $plain ): string {
		$parts = preg_split( "/\n{2,}/u", $plain ) ?: array( $plain );
		foreach ( $parts as $part ) {
			$part = trim( $part );
			if ( $part === '' ) {
				continue;
			}
			if ( preg_match( '/^資料截止/u', $part ) ) {
				continue;
			}
			return mb_substr( $part, 0, 280 );
		}
		return mb_substr( $plain, 0, 280 );
	}

	private function primaryKeyword( string $focus ): string {
		$parts = array_map( 'trim', explode( ',', $focus ) );
		foreach ( $parts as $part ) {
			if ( $part !== '' ) {
				return $part;
			}
		}
		return '';
	}

	/**
	 * @return array<int, array{tag:string,text:string}>
	 */
	private function headings( string $html ): array {
		$found = array();
		if ( preg_match_all( '/<(h[2-3])[^>]*>(.*?)<\/\1>/is', $html, $matches, PREG_SET_ORDER ) ) {
			foreach ( $matches as $match ) {
				$text = trim( wp_strip_all_tags( $match[2] ) );
				if ( $text === '' ) {
					continue;
				}
				$found[] = array(
					'tag'  => strtolower( $match[1] ),
					'text' => $text,
				);
			}
		}
		return $found;
	}

	/**
	 * @return array<int, array{url:string,label:string}>
	 */
	private function internalLinks( string $html ): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$found = array();
		if ( ! preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $matches, PREG_SET_ORDER ) ) {
			return $found;
		}
		$seen = array();
		foreach ( $matches as $match ) {
			$url = html_entity_decode( $match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
			if ( strpos( $url, '#' ) === 0 ) {
				continue;
			}
			$absolute = $url;
			if ( strpos( $url, '/' ) === 0 ) {
				$absolute = home_url( $url );
			}
			$linkHost = (string) wp_parse_url( $absolute, PHP_URL_HOST );
			if ( $linkHost === '' || strcasecmp( $linkHost, $host ) !== 0 ) {
				continue;
			}
			$label = trim( wp_strip_all_tags( $match[2] ) );
			$key   = $absolute . '|' . $label;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$found[]      = array(
				'url'   => $absolute,
				'label' => $label,
			);
		}
		return $found;
	}

	/**
	 * Nearby posts the AI can use as internal links.
	 *
	 * @return array<int, array{id:int,title:string,url:string,type:string}>
	 */
	private function relatedCandidates( \WP_Post $post ): array {
		$candidates = array();
		$seen       = array( (int) $post->ID => true );

		$hubPages = array( 'jetson-local-ai', 'taiwan-stock-watch', 'lyrics-guide', 'about' );
		foreach ( $hubPages as $slug ) {
			$page = get_page_by_path( $slug );
			if ( ! $page instanceof \WP_Post ) {
				continue;
			}
			$url = get_permalink( $page );
			if ( ! is_string( $url ) || $url === '' ) {
				continue;
			}
			$seen[ (int) $page->ID ] = true;
			$candidates[]            = array(
				'id'    => (int) $page->ID,
				'title' => get_the_title( $page ),
				'url'   => $url,
				'type'  => 'hub',
			);
		}

		$query = new \WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 6,
				'post__not_in'        => array_keys( $seen ),
				'ignore_sticky_posts' => true,
				'no_found_rows'       => true,
				'orderby'             => 'date',
				'order'               => 'DESC',
				'category__in'        => wp_get_post_categories( $post->ID ),
			)
		);
		foreach ( $query->posts as $related ) {
			if ( ! $related instanceof \WP_Post ) {
				continue;
			}
			$url = get_permalink( $related );
			if ( ! is_string( $url ) || $url === '' ) {
				continue;
			}
			$candidates[] = array(
				'id'    => (int) $related->ID,
				'title' => get_the_title( $related ),
				'url'   => $url,
				'type'  => 'sibling',
			);
		}
		wp_reset_postdata();

		return array_slice( $candidates, 0, 8 );
	}

	private function stripTokens( string $title ): string {
		$title = str_replace( array( '%sep%', '%sitename%', '%title%' ), ' ', $title );
		return trim( preg_replace( '/\s+/u', ' ', $title ) ?? $title );
	}
}
