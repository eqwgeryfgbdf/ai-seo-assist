<?php
/**
 * Remove plugin settings and stored suggestions.
 *
 * @package AiSeoAssist
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'ai_seo_assist' );

$suggestionDeleted = delete_post_meta_by_key( '_ai_seo_assist_suggestion' );
if ( false === $suggestionDeleted ) {
	error_log( 'AI SEO Assist: uninstall could not delete suggestion meta.' );
}

$reportDeleted = delete_post_meta_by_key( '_ai_seo_assist_last_report' );
if ( false === $reportDeleted ) {
	error_log( 'AI SEO Assist: uninstall could not delete report meta.' );
}
