<?php
/**
 * Ask an OpenAI-compatible model for an SEO pack.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Turns an analysis report into Rank Math-ready suggestions.
 */
class Suggester {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	/**
	 * @param array<string, mixed> $analysis Analyzer output.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function suggest( array $analysis ) {
		$ai = $this->options->resolvedAi();
		if ( $ai['enabled'] !== true ) {
			return array(
				'source'             => 'external-ai',
				'needsExternalModel' => true,
				'message'            => '尚未設定可用的 AI API。請由呼叫端（Cursor / MCP）依 briefing 產出 SEO 後再用 apply-seo 寫入。',
				'briefing'           => $this->briefing( $analysis ),
			);
		}

		$endpoint = $this->endpoint( $ai['baseUrl'] );
		$body     = array(
			'model'           => $ai['model'],
			'temperature'     => 0.2,
			'response_format' => array( 'type' => 'json_object' ),
			'messages'        => array(
				array(
					'role'    => 'system',
					'content' => $this->systemPrompt(),
				),
				array(
					'role'    => 'user',
					'content' => wp_json_encode( $this->briefing( $analysis ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
				),
			),
		);
		$encoded = wp_json_encode( $body );
		if ( ! is_string( $encoded ) || $encoded === '' ) {
			return new \WP_Error( 'ai_encode', '無法編碼 AI 請求內容。' );
		}

		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout' => 60,
				'headers' => array(
					'Content-Type'  => 'application/json',
					'Authorization' => 'Bearer ' . $ai['apiKey'],
				),
				'body'    => $encoded,
			)
		);
		if ( is_wp_error( $response ) ) {
			return new \WP_Error( 'ai_http', '無法連線到 AI API：' . $response->get_error_message() );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		if ( $status < 200 || $status >= 300 ) {
			$decoded = json_decode( $raw, true );
			$detail  = is_array( $decoded ) ? (string) ( $decoded['error']['message'] ?? $raw ) : $raw;
			return new \WP_Error( 'ai_api', 'AI API 回傳 HTTP ' . $status . '：' . $detail );
		}

		$decoded = json_decode( $raw, true );
		if ( ! is_array( $decoded ) ) {
			return new \WP_Error( 'ai_parse', 'AI API 回應不是有效 JSON。' );
		}
		$text = trim( (string) ( $decoded['choices'][0]['message']['content'] ?? '' ) );
		if ( $text === '' ) {
			return new \WP_Error( 'ai_empty', 'AI API 沒有回傳內容。' );
		}
		$text = preg_replace( '/^```(?:json)?\s*/u', '', $text ) ?? $text;
		$text = preg_replace( '/\s*```$/u', '', $text ) ?? $text;

		$pack = json_decode( $text, true );
		if ( ! is_array( $pack ) ) {
			return new \WP_Error( 'ai_json', 'AI 沒有回傳可解析的 SEO JSON。' );
		}

		$normalized = $this->normalizePack( $pack );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$normalized['source']             = 'model';
		$normalized['needsExternalModel'] = false;
		$normalized['model']              = $ai['model'];
		return $normalized;
	}

	/**
	 * Compact briefing for the model or an external agent.
	 *
	 * @param array<string, mixed> $analysis Analyzer output.
	 * @return array<string, mixed>
	 */
	public function briefing( array $analysis ): array {
		return array(
			'locale'            => 'zh-TW',
			'playbook'          => array(
				'seoTitle'       => '獨立清楚，約 20–40 字，可用 %sep% %sitename%',
				'description'    => '70–160 字，說明文章能解答什麼，不要只寫資料截止時間',
				'focusKeywords'  => '逗號分隔；主關鍵字具體，不要只填「台股」這種過寬詞',
				'internalLinks'  => '2–4 個真正相關的站內連結',
				'tags'           => '3–6 個會持續使用的標籤',
				'peopleFirst'    => '不要為了分數堆關鍵字；股市文必須標示不構成投資建議',
			),
			'id'                => $analysis['id'] ?? 0,
			'title'             => $analysis['title'] ?? '',
			'permalink'         => $analysis['permalink'] ?? '',
			'currentSeoTitle'   => $analysis['seoTitle'] ?? '',
			'currentDescription'=> $analysis['description'] ?? '',
			'currentFocus'      => $analysis['focusKeywords'] ?? '',
			'opening'           => $analysis['opening'] ?? '',
			'plainExcerpt'      => $analysis['plainExcerpt'] ?? '',
			'headings'          => $analysis['headings'] ?? array(),
			'internalLinks'     => $analysis['internalLinks'] ?? array(),
			'tags'              => $analysis['tags'] ?? array(),
			'featuredImageAlt'  => $analysis['featuredImageAlt'] ?? '',
			'relatedCandidates' => $analysis['relatedCandidates'] ?? array(),
			'issues'            => $analysis['issues'] ?? array(),
		);
	}

	/**
	 * @param array<string, mixed> $pack Raw model JSON.
	 * @return array<string, mixed>|\WP_Error
	 */
	public function normalizePack( array $pack ) {
		$seoTitle = trim( (string) ( $pack['seoTitle'] ?? $pack['seo_title'] ?? '' ) );
		$description = trim( (string) ( $pack['description'] ?? '' ) );
		$focus = trim( (string) ( $pack['focusKeywords'] ?? $pack['focus_keywords'] ?? '' ) );
		$excerpt = trim( (string) ( $pack['excerpt'] ?? '' ) );
		$imageAlt = trim( (string) ( $pack['imageAlt'] ?? $pack['image_alt'] ?? '' ) );
		$notes = $pack['notes'] ?? array();
		if ( ! is_array( $notes ) ) {
			$notes = array( (string) $notes );
		}

		$relatedLinks = array();
		$rawLinks     = $pack['relatedLinks'] ?? $pack['related_links'] ?? array();
		if ( is_array( $rawLinks ) ) {
			foreach ( $rawLinks as $link ) {
				if ( ! is_array( $link ) ) {
					continue;
				}
				$url   = esc_url_raw( (string) ( $link['url'] ?? '' ) );
				$label = sanitize_text_field( (string) ( $link['label'] ?? '' ) );
				if ( $url === '' || $label === '' ) {
					continue;
				}
				$relatedLinks[] = array(
					'url'   => $url,
					'label' => $label,
				);
			}
		}

		if ( $seoTitle === '' || $description === '' || $focus === '' ) {
			return new \WP_Error( 'ai_incomplete_pack', 'AI 回傳缺少 seoTitle、description 或 focusKeywords。' );
		}

		return array(
			'seoTitle'       => $seoTitle,
			'description'    => $description,
			'focusKeywords'  => $focus,
			'excerpt'        => $excerpt,
			'imageAlt'       => $imageAlt,
			'relatedLinks'   => $relatedLinks,
			'notes'          => array_values( array_map( 'strval', $notes ) ),
		);
	}

	private function endpoint( string $baseUrl ): string {
		$baseUrl = untrailingslashit( $baseUrl );
		if ( preg_match( '#/chat/completions$#', $baseUrl ) ) {
			return $baseUrl;
		}
		if ( preg_match( '#/v1$#', $baseUrl ) ) {
			return $baseUrl . '/chat/completions';
		}
		return $baseUrl . '/v1/chat/completions';
	}

	private function systemPrompt(): string {
		$extra = trim( (string) $this->options->get( 'extraPrompt', '' ) );
		$prompt = <<<'TXT'
你是這個臺灣繁體中文部落格的 SEO 編輯。根據分析報告產出一份可直接寫入 Rank Math 的 JSON。

只輸出 JSON，鍵名必須是：
seoTitle, description, focusKeywords, excerpt, imageAlt, relatedLinks, notes

規則：
1. 語言使用臺灣繁體中文，html 語系是 zh-TW。
2. seoTitle 獨立清楚，約 20–40 字，可含 %sep% %sitename%。
3. description 70–160 字，說明文章能解答什麼，有點擊誘因；不要只寫資料截止時間。
4. focusKeywords 用逗號分隔；第一個是主關鍵字，必須具體（例如「台股盤勢 2026年9月1日」而不是「台股」）。
5. 不要為了外掛分數重複塞關鍵字。
6. relatedLinks 從 relatedCandidates 選 2–4 個真正相關的，格式 [{"url":"...","label":"..."}]。
7. 股市內容必須把「個人研究紀錄，不構成投資建議」放進 excerpt 或 notes。
8. Google 看重原創、有幫助、可信任的內容，而不是分數。
TXT;
		if ( $extra !== '' ) {
			$prompt .= "\n\n額外規則：\n" . $extra;
		}
		return $prompt;
	}
}
