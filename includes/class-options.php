<?php
/**
 * Plugin settings.
 *
 * @package AiSeoAssist
 */

namespace AiSeoAssist;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads and writes the ai_seo_assist option.
 */
class Options {
	public const OPTION_KEY = 'ai_seo_assist';

	/**
	 * @var array<string, mixed>
	 */
	private $settings = array();

	/**
	 * Default settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function defaults(): array {
		return array(
			'reuseLineAi' => '1',
			'aiEnabled'   => '1',
			'aiBaseUrl'   => 'https://api.openai.com/v1',
			'aiApiKey'    => '',
			'aiModel'     => 'gpt-4o-mini',
			'localeHint'  => 'zh-TW',
			'extraPrompt' => '',
		);
	}

	/**
	 * Load settings from the database.
	 */
	public function load(): void {
		$stored = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $stored ) ) {
			error_log( 'AI SEO Assist: stored settings are not an array; using defaults.' );
			$stored = array();
		}
		$this->settings = array_merge( self::defaults(), $stored );
	}

	/**
	 * @return array<string, mixed>
	 */
	public function all(): array {
		if ( $this->settings === array() ) {
			$this->load();
		}
		return $this->settings;
	}

	/**
	 * @param mixed $default Fallback.
	 * @return mixed
	 */
	public function get( string $key, $default = null ) {
		$settings = $this->all();
		if ( array_key_exists( $key, $settings ) ) {
			return $settings[ $key ];
		}
		return $default;
	}

	/**
	 * @param array<string, mixed> $settings Sanitized settings.
	 */
	public function save( array $settings ): bool {
		$this->settings = array_merge( self::defaults(), $settings );
		$updated        = update_option( self::OPTION_KEY, $this->settings, false );
		if ( false === $updated && get_option( self::OPTION_KEY ) !== $this->settings ) {
			error_log( 'AI SEO Assist: failed to save settings.' );
			return false;
		}
		return true;
	}

	/**
	 * Resolved chat API credentials, optionally borrowed from LINE Post Notify.
	 *
	 * @return array{enabled:bool,baseUrl:string,apiKey:string,model:string}
	 */
	public function resolvedAi(): array {
		$settings = $this->all();
		$enabled  = (string) ( $settings['aiEnabled'] ?? '' ) === '1';
		$baseUrl  = trim( (string) ( $settings['aiBaseUrl'] ?? '' ) );
		$apiKey   = trim( (string) ( $settings['aiApiKey'] ?? '' ) );
		$model    = trim( (string) ( $settings['aiModel'] ?? '' ) );

		if ( (string) ( $settings['reuseLineAi'] ?? '' ) === '1' && function_exists( 'linePostNotifyGetSettings' ) ) {
			$line = linePostNotifyGetSettings();
			if ( ! is_array( $line ) ) {
				error_log( 'AI SEO Assist: LINE settings were not an array; ignored.' );
			} else {
				if ( $apiKey === '' ) {
					$apiKey = trim( (string) ( $line['aiApiKey'] ?? '' ) );
				}
				if ( $baseUrl === '' || $baseUrl === 'https://api.openai.com/v1' ) {
					$lineBase = trim( (string) ( $line['aiBaseUrl'] ?? '' ) );
					if ( $lineBase !== '' ) {
						$baseUrl = $lineBase;
					}
				}
				if ( $model === '' || $model === 'gpt-4o-mini' ) {
					$lineModel = trim( (string) ( $line['aiModel'] ?? '' ) );
					if ( $lineModel !== '' ) {
						$model = $lineModel;
					}
				}
				if ( (string) ( $line['aiEnabled'] ?? '' ) === '1' ) {
					$enabled = true;
				}
			}
		}

		return array(
			'enabled' => $enabled && $apiKey !== '' && $baseUrl !== '' && $model !== '',
			'baseUrl' => $baseUrl,
			'apiKey'  => $apiKey,
			'model'   => $model,
		);
	}
}
