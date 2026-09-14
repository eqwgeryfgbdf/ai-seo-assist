<?php
/**
 * Post editor metabox.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Analyze / suggest / apply from the post edit screen.
 */
class Metabox {
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
		add_action( 'add_meta_boxes', array( $this, 'addBox' ) );
		add_action( 'admin_post_ai_seo_assist_analyze', array( $this, 'handleAnalyze' ) );
		add_action( 'admin_post_ai_seo_assist_suggest', array( $this, 'handleSuggest' ) );
		add_action( 'admin_post_ai_seo_assist_apply', array( $this, 'handleApply' ) );
	}

	public function addBox(): void {
		add_meta_box(
			'ai-seo-assist',
			'AI SEO Assist',
			array( $this, 'render' ),
			array( 'post', 'page' ),
			'side',
			'high'
		);
	}

	public function render( \WP_Post $post ): void {
		$reportRaw = get_post_meta( $post->ID, '_ai_seo_assist_last_report', true );
		$suggestRaw = get_post_meta( $post->ID, '_ai_seo_assist_suggestion', true );
		$issues     = is_string( $reportRaw ) && $reportRaw !== '' ? json_decode( $reportRaw, true ) : null;
		$suggestion = is_string( $suggestRaw ) && $suggestRaw !== '' ? json_decode( $suggestRaw, true ) : null;
		?>
		<?php if ( isset( $_GET['ai_seo_assist'] ) ) : ?>
			<p class="description">
				<?php
				$flag = sanitize_key( (string) wp_unslash( $_GET['ai_seo_assist'] ) );
				$map  = array(
					'analyzed'  => '分析完成。',
					'suggested' => '已產生 SEO 建議。',
					'applied'   => '已寫入 Rank Math。',
				);
				echo esc_html( $map[ $flag ] ?? '完成。' );
				?>
			</p>
		<?php endif; ?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( $this->actionUrl( 'ai_seo_assist_suggest', $post->ID ) ); ?>">用 AI 產生建議</a>
		</p>
		<?php if ( is_array( $issues ) ) : ?>
			<p><strong>缺口（<?php echo esc_html( (string) count( $issues ) ); ?>）</strong></p>
			<ul style="margin:0 0 8px 1.2em;">
				<?php foreach ( $issues as $issue ) : ?>
					<?php if ( ! is_array( $issue ) ) : ?>
						<?php continue; ?>
					<?php endif; ?>
					<li><?php echo esc_html( (string) ( $issue['message'] ?? '' ) ); ?></li>
				<?php endforeach; ?>
			</ul>
		<?php endif; ?>
		<?php if ( is_array( $suggestion ) && empty( $suggestion['needsExternalModel'] ) ) : ?>
			<p><strong>上次建議</strong></p>
			<p class="description"><?php echo esc_html( mb_substr( (string) ( $suggestion['seoTitle'] ?? '' ), 0, 80 ) ); ?></p>
			<p>
				<a class="button" href="<?php echo esc_url( $this->actionUrl( 'ai_seo_assist_apply', $post->ID ) ); ?>">套用到 Rank Math</a>
			</p>
		<?php elseif ( is_array( $suggestion ) && ! empty( $suggestion['needsExternalModel'] ) ) : ?>
			<p class="description">尚未設定 AI API。請在 Cursor 用 <code>ai-seo/analyze-post</code> 分析後，再以 <code>ai-seo/apply-seo</code> 寫入。</p>
		<?php endif; ?>
		<?php
	}

	public function handleAnalyze(): void {
		$postId = $this->requirePost();
		$report = $this->analyzer->analyze( $postId );
		if ( is_wp_error( $report ) ) {
			wp_die( esc_html( $report->get_error_message() ) );
		}
		update_post_meta( $postId, '_ai_seo_assist_last_report', wp_json_encode( $report['issues'], JSON_UNESCAPED_UNICODE ) );
		$this->redirect( $postId, 'analyzed' );
	}

	public function handleSuggest(): void {
		$postId   = $this->requirePost();
		$analysis = $this->analyzer->analyze( $postId );
		if ( is_wp_error( $analysis ) ) {
			wp_die( esc_html( $analysis->get_error_message() ) );
		}
		update_post_meta( $postId, '_ai_seo_assist_last_report', wp_json_encode( $analysis['issues'], JSON_UNESCAPED_UNICODE ) );
		$suggestion = $this->suggester->suggest( $analysis );
		if ( is_wp_error( $suggestion ) ) {
			wp_die( esc_html( $suggestion->get_error_message() ) );
		}
		update_post_meta( $postId, '_ai_seo_assist_suggestion', wp_json_encode( $suggestion, JSON_UNESCAPED_UNICODE ) );
		$this->redirect( $postId, 'suggested' );
	}

	public function handleApply(): void {
		$postId     = $this->requirePost();
		$suggestRaw = get_post_meta( $postId, '_ai_seo_assist_suggestion', true );
		if ( ! is_string( $suggestRaw ) || $suggestRaw === '' ) {
			wp_die( esc_html( '還沒有可套用的建議，請先產生 SEO。' ) );
		}
		$decoded = json_decode( $suggestRaw, true );
		if ( ! is_array( $decoded ) ) {
			wp_die( esc_html( '儲存的建議不是有效 JSON。' ) );
		}
		$pack = $this->suggester->normalizePack( $decoded );
		if ( is_wp_error( $pack ) ) {
			wp_die( esc_html( $pack->get_error_message() ) );
		}
		$result = $this->applier->apply( $postId, $pack, false );
		if ( is_wp_error( $result ) ) {
			wp_die( esc_html( $result->get_error_message() ) );
		}
		$this->redirect( $postId, 'applied' );
	}

	private function requirePost(): int {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html( '你沒有權限執行這個動作。' ) );
		}
		$postId = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		check_admin_referer( 'ai_seo_assist_post_' . $postId );
		if ( $postId <= 0 || ! current_user_can( 'edit_post', $postId ) ) {
			wp_die( esc_html( '無效的文章，或你沒有編輯權限。' ) );
		}
		return $postId;
	}

	private function actionUrl( string $action, int $postId ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . rawurlencode( $action ) . '&post_id=' . $postId ),
			'ai_seo_assist_post_' . $postId
		);
	}

	private function redirect( int $postId, string $flag ): void {
		$url = get_edit_post_link( $postId, 'raw' );
		if ( ! is_string( $url ) || $url === '' ) {
			wp_die( esc_html( '無法回到文章編輯頁。' ) );
		}
		wp_safe_redirect( add_query_arg( 'ai_seo_assist', $flag, $url ) );
		exit;
	}
}
