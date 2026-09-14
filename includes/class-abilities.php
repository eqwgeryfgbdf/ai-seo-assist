<?php
/**
 * WordPress Abilities / MCP tools.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers analyze / suggest / apply abilities for AI agents.
 */
class Abilities {
	/**
	 * @var Analyzer
	 */
	private $analyzer;

	/**
	 * @var Suggester
	 */
	private $suggester;

	/**
	 * @var Applier
	 */
	private $applier;

	public function __construct( Analyzer $analyzer, Suggester $suggester, Applier $applier ) {
		$this->analyzer  = $analyzer;
		$this->suggester = $suggester;
		$this->applier   = $applier;
	}

	public function register(): void {
		add_action( 'wp_abilities_api_categories_init', array( $this, 'registerCategory' ) );
		add_action( 'wp_abilities_api_init', array( $this, 'registerAbilities' ) );
	}

	public function registerCategory(): void {
		if ( ! function_exists( 'wp_register_ability_category' ) ) {
			return;
		}
		wp_register_ability_category(
			'ai-seo',
			array(
				'label'       => 'AI SEO',
				'description' => 'Analyze a post against this site\'s SEO playbook and apply Rank Math fields.',
			)
		);
	}

	public function registerAbilities(): void {
		if ( ! function_exists( 'wp_register_ability' ) ) {
			error_log( 'AI SEO Assist: wp_register_ability is unavailable; MCP tools were not registered.' );
			return;
		}

		$mcpMeta = array(
			'mcp'          => array( 'public' => true ),
			'show_in_rest' => true,
		);

		wp_register_ability(
			'ai-seo/analyze-post',
			array(
				'label'               => 'Analyze post SEO',
				'description'         => 'Inspect Rank Math fields, headings, internal links, tags and image alt, then list issues against the zh-TW people-first SEO playbook.',
				'category'            => 'ai-seo',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Post or page ID',
						),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
						return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
					}
					$report = $this->analyzer->analyze( $postId );
					if ( ! is_wp_error( $report ) ) {
						update_post_meta( $postId, '_ai_seo_assist_last_report', wp_json_encode( $report['issues'], JSON_UNESCAPED_UNICODE ) );
					}
					return $report;
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'ai-seo/list-posts',
			array(
				'label'               => 'List posts needing SEO work',
				'description'         => 'List recent posts/pages sorted by SEO issue count. Use this to decide which article to fix next.',
				'category'            => 'ai-seo',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'status' => array(
							'type'        => 'string',
							'description' => 'publish, draft, pending, future, or any',
							'default'     => 'publish',
						),
						'limit'  => array(
							'type'        => 'integer',
							'description' => 'How many posts to scan (max 50)',
							'default'     => 10,
						),
					),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$status = sanitize_key( (string) ( $input['status'] ?? 'publish' ) );
					if ( ! in_array( $status, array( 'publish', 'draft', 'pending', 'future', 'any' ), true ) ) {
						return new \WP_Error( 'invalid_status', '不支援的文章狀態。' );
					}
					$limit = (int) ( $input['limit'] ?? 10 );
					return $this->analyzer->listNeedingWork( $status, $limit );
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'ai-seo/suggest-seo',
			array(
				'label'               => 'Suggest SEO pack',
				'description'         => 'If an OpenAI-compatible API is configured, generate Rank Math title/description/keywords. Otherwise return a briefing for the calling AI to fill.',
				'category'            => 'ai-seo',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id' => array(
							'type'        => 'integer',
							'description' => 'Post or page ID',
						),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					if ( $postId > 0 && ! current_user_can( 'edit_post', $postId ) ) {
						return new \WP_Error( 'forbidden', '你沒有編輯這篇文章的權限。' );
					}
					$analysis = $this->analyzer->analyze( $postId );
					if ( is_wp_error( $analysis ) ) {
						return $analysis;
					}
					$suggestion = $this->suggester->suggest( $analysis );
					if ( is_wp_error( $suggestion ) ) {
						return $suggestion;
					}
					$suggestion['id'] = $postId;
					update_post_meta( $postId, '_ai_seo_assist_suggestion', wp_json_encode( $suggestion, JSON_UNESCAPED_UNICODE ) );
					return $suggestion;
				},
				'meta'                => $mcpMeta,
			)
		);

		wp_register_ability(
			'ai-seo/apply-seo',
			array(
				'label'               => 'Apply SEO pack',
				'description'         => 'Write Rank Math SEO title, meta description, focus keywords, excerpt, featured-image alt, and optional related-reading links.',
				'category'            => 'ai-seo',
				'input_schema'        => array(
					'type'       => 'object',
					'properties' => array(
						'id'             => array( 'type' => 'integer', 'description' => 'Post or page ID' ),
						'seoTitle'       => array( 'type' => 'string', 'description' => 'Rank Math title, may include %sep% %sitename%' ),
						'description'    => array( 'type' => 'string', 'description' => 'Meta description, 70-160 Chinese characters' ),
						'focusKeywords'  => array( 'type' => 'string', 'description' => 'Comma-separated focus keywords; first is primary' ),
						'excerpt'        => array( 'type' => 'string', 'description' => 'WordPress excerpt' ),
						'imageAlt'       => array( 'type' => 'string', 'description' => 'Featured image alt text' ),
						'relatedLinks'   => array(
							'type'  => 'array',
							'items' => array(
								'type'       => 'object',
								'properties' => array(
									'url'   => array( 'type' => 'string' ),
									'label' => array( 'type' => 'string' ),
								),
							),
						),
						'dryRun'         => array( 'type' => 'boolean', 'default' => false ),
					),
					'required'   => array( 'id' ),
				),
				'permission_callback' => static function () {
					return current_user_can( 'edit_posts' );
				},
				'execute_callback'    => function ( $input ) {
					$postId = (int) ( $input['id'] ?? 0 );
					$dryRun = ! empty( $input['dryRun'] );
					$pack   = $this->suggester->normalizePack( is_array( $input ) ? $input : array() );
					if ( is_wp_error( $pack ) ) {
						$stored = get_post_meta( $postId, '_ai_seo_assist_suggestion', true );
						if ( is_string( $stored ) && $stored !== '' ) {
							$decoded = json_decode( $stored, true );
							if ( is_array( $decoded ) ) {
								$pack = $this->suggester->normalizePack( $decoded );
							}
						}
					}
					if ( is_wp_error( $pack ) ) {
						return $pack;
					}
					return $this->applier->apply( $postId, $pack, $dryRun );
				},
				'meta'                => $mcpMeta,
			)
		);
	}
}
