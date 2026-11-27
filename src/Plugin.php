<?php
/**
 * Plugin bootstrap: settings, the Gravity Forms hook and the missing-dependency notice.
 *
 * @package Shakewell\KlaviyoConnector
 */

namespace Shakewell\KlaviyoConnector;

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/Connector.php';

/**
 * Wires the plugin into WordPress.
 */
final class Plugin {

	/**
	 * Registers hooks. Called on plugins_loaded.
	 */
	public static function boot(): void {
		add_action( 'gform_after_submission', array( self::class, 'on_submission' ), 20, 2 );
		add_action( 'shakewell_klaviyo_connector_retry', array( self::class, 'retry' ), 10, 3 );
		add_action( 'admin_notices', array( self::class, 'missing_notice' ) );
	}

	/**
	 * Seconds to wait before each cron retry of a failed entry; the visitor never waits on Klaviyo.
	 */
	public const RETRY_DELAYS = array( 60, 900, 3600 );

	/**
	 * Reads a setting from a PHP constant or an environment variable, never the database.
	 *
	 * @param string $name API_KEY or ENABLED.
	 */
	public static function setting( string $name ): string {
		$candidate = 'SHAKEWELL_KLAVIYO_CONNECTOR_' . $name;
		if ( defined( $candidate ) ) {
			return trim( (string) constant( $candidate ) );
		}
		$value = getenv( $candidate );
		return false === $value ? '' : trim( $value );
	}

	/**
	 * Working context, or null while the connector is off (the default) or has no key.
	 *
	 * @return array{key:string,log:callable}|null
	 */
	public static function context(): ?array {
		$key = self::setting( 'API_KEY' );
		if ( ! in_array( self::setting( 'ENABLED' ), array( '1', 'true' ), true ) || '' === $key ) {
			return null;
		}
		return array(
			'key' => $key,
			'log' => static function ( string $message ): void {
				// Deliberate: the only log sink, and messages never carry personal data or the key.
				error_log( '[shakewell-klaviyo-connector] ' . $message ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			},
		);
	}

	/**
	 * Gravity Forms hook. Silent while the paid Klaviyo add-on is loaded, so the two never send twice.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 */
	public static function on_submission( $entry, $form ): void {
		$ctx = self::context();
		if ( null === $ctx || class_exists( 'GFKlaviyoFeedAddOn', false ) || ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}
		try {
			if ( ! Connector::process_entry( $entry, $form, $ctx ) ) {
				wp_schedule_single_event( time() + self::RETRY_DELAYS[0], 'shakewell_klaviyo_connector_retry', array( (int) $entry['id'], (int) $form['id'], 1 ) );
			}
		} catch ( \Throwable $e ) { // Never break the visitor's form submission; log the class only.
			( $ctx['log'] )( 'form=' . ( $form['id'] ?? '?' ) . ' unexpected ' . get_class( $e ) );
		}
	}

	/**
	 * Cron: replays a failed entry, then schedules the next attempt while any are left.
	 *
	 * @param int $entry_id Entry id.
	 * @param int $form_id  Form id.
	 * @param int $attempt  Retries done so far.
	 */
	public static function retry( $entry_id, $form_id, $attempt ): void {
		$ctx   = self::context();
		$entry = class_exists( 'GFAPI', false ) ? \GFAPI::get_entry( (int) $entry_id ) : null;
		$form  = class_exists( 'GFAPI', false ) ? \GFAPI::get_form( (int) $form_id ) : null;
		if ( null === $ctx || ! is_array( $entry ) || ! is_array( $form ) ) {
			return;
		}
		try {
			$ok = Connector::process_entry( $entry, $form, $ctx );
		} catch ( \Throwable $e ) {
			( $ctx['log'] )( "form=$form_id unexpected " . get_class( $e ) );
			return;
		}
		if ( ! $ok && isset( self::RETRY_DELAYS[ $attempt ] ) ) {
			wp_schedule_single_event( time() + self::RETRY_DELAYS[ $attempt ], 'shakewell_klaviyo_connector_retry', array( (int) $entry_id, (int) $form_id, (int) $attempt + 1 ) );
		}
	}

	/**
	 * Tells administrators Gravity Forms is missing.
	 */
	public static function missing_notice(): void {
		if ( class_exists( 'GFForms', false ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-warning is-dismissible"><p>%s</p></div>',
			esc_html__( 'Shakewell Connector for Klaviyo and Gravity Forms needs the Gravity Forms plugin to be installed and active. Until then it does nothing.', 'shakewell-klaviyo-connector' )
		);
	}
}
