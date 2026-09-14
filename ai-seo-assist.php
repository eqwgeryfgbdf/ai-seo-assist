<?php
/**
 * Plugin Name: AI SEO Assist
 * Description: Lets AI (MCP / OpenAI-compatible API) analyze a post and fill Rank Math SEO title, description, focus keywords, excerpt and image alt.
 * Version: 1.0.0
 * Requires at least: 6.8
 * Requires PHP: 8.0
 * Author: Roy
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: ai-seo-assist
 *
 * @package AiSeoAssist
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'AI_SEO_ASSIST_VERSION', '1.0.0' );
define( 'AI_SEO_ASSIST_FILE', __FILE__ );
define( 'AI_SEO_ASSIST_DIR', plugin_dir_path( __FILE__ ) );

require_once AI_SEO_ASSIST_DIR . 'includes/class-options.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-analyzer.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-suggester.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-applier.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-abilities.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-admin.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-metabox.php';
require_once AI_SEO_ASSIST_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( 'AiSeoAssist\\Plugin', 'activate' ) );

add_action(
	'plugins_loaded',
	static function () {
		\AiSeoAssist\Plugin::instance()->boot();
	}
);
