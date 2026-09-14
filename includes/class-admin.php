<?php
/**
 * Settings screen.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Plugin settings under Settings → AI SEO Assist.
 */
class Admin {
	/**
	 * @var Options
	 */
	private $options;

	public function __construct( Options $options ) {
		$this->options = $options;
	}

	public function register(): void {
		add_action( 'admin_menu', array( $this, 'addPage' ) );
		add_action( 'admin_post_ai_seo_assist_save', array( $this, 'handleSave' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( AI_SEO_ASSIST_FILE ), array( $this, 'actionLinks' ) );
		add_filter( 'manage_posts_columns', array( $this, 'addColumn' ) );
		add_action( 'manage_posts_custom_column', array( $this, 'renderColumn' ), 10, 2 );
	}

	/**
	 * @param array<int, string> $links Plugin links.
	 * @return array<int, string>
	 */
	public function actionLinks( $links ) {
		if ( ! is_array( $links ) ) {
			$links = array();
		}
		$links[] = '<a href="' . esc_url( admin_url( 'options-general.php?page=ai-seo-assist' ) ) . '">設定</a>';
		return $links;
	}

	public function addPage(): void {
		add_options_page(
			'AI SEO Assist',
			'AI SEO Assist',
			'manage_options',
			'ai-seo-assist',
			array( $this, 'renderPage' )
		);
	}

	public function handleSave(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限修改這些設定。' ) );
		}
		check_admin_referer( 'ai_seo_assist_save' );

		$current = $this->options->all();
		$posted  = wp_unslash( $_POST );
		$apiKey  = isset( $posted['aiApiKey'] ) ? (string) $posted['aiApiKey'] : '';
		if ( $apiKey === '' ) {
			$apiKey = (string) ( $current['aiApiKey'] ?? '' );
		}

		$saved = $this->options->save(
			array(
				'reuseLineAi' => empty( $posted['reuseLineAi'] ) ? '' : '1',
				'aiEnabled'   => empty( $posted['aiEnabled'] ) ? '' : '1',
				'aiBaseUrl'   => esc_url_raw( (string) ( $posted['aiBaseUrl'] ?? '' ) ),
				'aiApiKey'    => $apiKey,
				'aiModel'     => sanitize_text_field( (string) ( $posted['aiModel'] ?? '' ) ),
				'localeHint'  => 'zh-TW',
				'extraPrompt' => sanitize_textarea_field( (string) ( $posted['extraPrompt'] ?? '' ) ),
			)
		);
		if ( $saved !== true ) {
			wp_die( esc_html( '儲存設定失敗，請再試一次。' ) );
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=ai-seo-assist&saved=1' ) );
		exit;
	}

	public function renderPage(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html( '你沒有權限查看這個頁面。' ) );
		}
		$settings = $this->options->all();
		$ai       = $this->options->resolvedAi();
		?>
		<div class="wrap">
			<h1>AI SEO Assist</h1>
			<p>這個外掛給 AI（Cursor MCP 或 OpenAI 相容 API）分析文章，並把合適的 SEO 寫進 Rank Math。</p>
			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>設定已儲存。</p></div>
			<?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'ai_seo_assist_save' ); ?>
				<input type="hidden" name="action" value="ai_seo_assist_save">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">AI 來源</th>
						<td>
							<label>
								<input type="checkbox" name="aiEnabled" value="1" <?php checked( (string) $settings['aiEnabled'] === '1' ); ?>>
								啟用 OpenAI 相容 API 自動產生 SEO
							</label>
							<br>
							<label>
								<input type="checkbox" name="reuseLineAi" value="1" <?php checked( (string) $settings['reuseLineAi'] === '1' ); ?>>
								若本外掛沒填金鑰，沿用「LINE 發文通知」的 AI 設定
							</label>
							<p class="description">沒有 API 時，仍可用 MCP 工具 <code>ai-seo/analyze-post</code> 與 <code>ai-seo/apply-seo</code>，由 Cursor 自己寫 SEO 再套用。</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiBaseUrl">API 網址</label></th>
						<td><input class="regular-text" type="url" id="aiBaseUrl" name="aiBaseUrl" value="<?php echo esc_attr( (string) $settings['aiBaseUrl'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="aiApiKey">API Key</label></th>
						<td>
							<input class="regular-text" type="password" id="aiApiKey" name="aiApiKey" value="" autocomplete="off" placeholder="<?php echo (string) $settings['aiApiKey'] !== '' ? '已儲存，留空則不更改' : ''; ?>">
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="aiModel">模型</label></th>
						<td><input class="regular-text" type="text" id="aiModel" name="aiModel" value="<?php echo esc_attr( (string) $settings['aiModel'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="extraPrompt">額外規則</label></th>
						<td>
							<textarea class="large-text" rows="4" id="extraPrompt" name="extraPrompt"><?php echo esc_textarea( (string) $settings['extraPrompt'] ); ?></textarea>
						</td>
					</tr>
				</table>
				<?php submit_button( '儲存設定' ); ?>
			</form>
			<h2>目前狀態</h2>
			<p><?php echo $ai['enabled'] ? esc_html( 'API 已就緒，模型：' . $ai['model'] ) : esc_html( '尚未就緒：後台按鈕會回傳 briefing，請用 Cursor MCP 補上 SEO。' ); ?></p>
			<h2>給 AI 的工具</h2>
			<ol>
				<li><code>ai-seo/list-posts</code> 找出缺口最多的文章</li>
				<li><code>ai-seo/analyze-post</code> 讀現況與問題</li>
				<li><code>ai-seo/suggest-seo</code> 若有 API 就產生建議，否則回 briefing</li>
				<li><code>ai-seo/apply-seo</code> 寫入 Rank Math 標題、摘要、焦點關鍵字、excerpt、封面 alt</li>
			</ol>
		</div>
		<?php
	}

	/**
	 * @param array<string, string> $columns
	 * @return array<string, string>
	 */
	public function addColumn( array $columns ): array {
		$columns['ai_seo_assist'] = 'SEO 缺口';
		return $columns;
	}

	public function renderColumn( string $column, int $postId ): void {
		if ( $column !== 'ai_seo_assist' ) {
			return;
		}
		$raw = get_post_meta( $postId, '_ai_seo_assist_last_report', true );
		if ( ! is_string( $raw ) || $raw === '' ) {
			echo '<span class="description">尚未分析</span>';
			return;
		}
		$issues = json_decode( $raw, true );
		if ( ! is_array( $issues ) ) {
			echo '<span class="description">報告損壞</span>';
			return;
		}
		$count = count( $issues );
		if ( $count === 0 ) {
			echo '<span style="color:#008a20">0</span>';
			return;
		}
		echo '<span style="color:#d63638">' . esc_html( (string) $count ) . '</span>';
	}
}
