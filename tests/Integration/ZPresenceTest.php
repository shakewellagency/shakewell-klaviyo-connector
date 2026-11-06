<?php
/**
 * Runs last (file name): it declares stub classes that stay for the rest of the process.
 *
 * @package Shakewell\KlaviyoConnector
 */

namespace Shakewell\KlaviyoConnector\Tests;

use Shakewell\KlaviyoConnector\Plugin;
use WP_UnitTestCase;

// phpcs:disable Generic.Files.OneObjectStructurePerFile, Generic.Classes.DuplicateClassName
class GFKlaviyoFeedAddOn {} // phpcs:ignore
final class ZPresenceTest extends WP_UnitTestCase {

	public function test_silent_while_the_paid_addon_is_loaded_and_no_notice_with_gravity_forms(): void {
		class_alias( GFKlaviyoFeedAddOn::class, 'GFKlaviyoFeedAddOn' );
		class_alias( GFKlaviyoFeedAddOn::class, 'GFForms' );

		$sent = false;
		add_filter(
			'pre_http_request',
			function () use ( &$sent ) {
				$sent = true;
				return new \WP_Error( 'blocked' );
			}
		);
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED=1' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY=k' );
		add_filter( 'shakewell_klaviyo_connector_feeds', fn() => array( array( 'form_id' => 30, 'lists' => array( 'L' ), 'profile' => array( 'email' => '3' ) ) ) );
		Plugin::on_submission( array( 'id' => 1, '3' => 'a@example.com' ), array( 'id' => 30 ) );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY' );
		$this->assertFalse( $sent );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::missing_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
