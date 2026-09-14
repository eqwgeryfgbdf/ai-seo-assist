<?php
/**
 * Plugin bootstrap.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Wires options, analysis, MCP abilities, and admin UI.
 */
class Plugin {
	/**
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * @var Options
	 */
	private $options;

	/**
	 * @return Plugin
	 */
	public static function instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public static function activate(): void {
		$plugin = self::instance();
		$plugin->options->load();
		if ( get_option( Options::OPTION_KEY, null ) === null ) {
			add_option( Options::OPTION_KEY, Options::defaults(), '', false );
		}
	}

	private function __construct() {
		$this->options = new Options();
	}

	public function boot(): void {
		$this->options->load();

		$analyzer  = new Analyzer();
		$suggester = new Suggester( $this->options );
		$applier   = new Applier();

		( new Abilities( $analyzer, $suggester, $applier ) )->register();
		( new Admin( $this->options ) )->register();
		( new Metabox( $analyzer, $suggester, $applier ) )->register();
	}

	public function options(): Options {
		return $this->options;
	}
}
