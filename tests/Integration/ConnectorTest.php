<?php
/**
 * Does the connector send what the feeds say, once, with consent, and without leaking a sign-up?
 *
 * HTTP is faked with pre_http_request, so nothing here reaches Klaviyo.
 *
 * @package Shakewell\KlaviyoConnector
 */

namespace Shakewell\KlaviyoConnector\Tests;

use Shakewell\KlaviyoConnector\Connector;
use Shakewell\KlaviyoConnector\Plugin;
use WP_Error;
use WP_UnitTestCase;

final class ConnectorTest extends WP_UnitTestCase {

	private const KEY = 'pk_test_secret';

	private array $responses = array();
	private array $calls     = array();
	private array $logs      = array();
	private array $sleeps    = array();
	private array $feed;
	private array $entry;
	private array $form = array( 'id' => 30 );

	public function set_up(): void {
		parent::set_up();
		$GLOBALS['shakewell_gf_meta'] = array();
		add_filter(
			'pre_http_request',
			function ( $preempt, $args, $url ) {
				$this->calls[] = array(
					'url'     => $url,
					'headers' => $args['headers'],
					'body'    => json_decode( $args['body'], true ),
				);
				return array_shift( $this->responses ) ?? $this->reply( 200 );
			},
			10,
			3
		);
		$this->feed  = array(
			'form_id'       => 30,
			'lists'         => array( 'LIST1', 'LIST2' ),
			'email_consent' => '8',
			'sms'           => array(
				'list'    => 'SMS1',
				'consent' => '9',
			),
			'profile'       => array(
				'email'         => '3',
				'first_name'    => '1.3',
				'last_name'     => '1.6',
				'phone_number'  => '4',
				'location.city' => '6',
			),
			'properties'    => array( 'Plan' => '7' ),
			'phone_country_code' => '44',
			'source'        => 'Newsletter sign-up',
			'event'         => array(
				'name'       => 'Signed up',
				'properties' => array( 'Plan' => '7' ),
			),
		);
		$this->entry = array(
			'id'      => 501,
			'form_id' => 30,
			'3'       => 'sam.private@example.com',
			'1.3'     => 'Sam',
			'1.6'     => 'Private',
			'4'       => '07911 123456',
			'6'       => 'Leeds',
			'7'       => 'Pro',
			'8'       => 'Yes',
			'9'       => '',
		);
	}

	public function tear_down(): void {
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY' );
		parent::tear_down();
	}

	private function reply( int $code, string $body = '{}', array $headers = array() ): array {
		return array(
			'headers'  => $headers,
			'body'     => $body,
			'response' => array(
				'code'    => $code,
				'message' => '',
			),
			'cookies'  => array(),
			'filename' => null,
		);
	}

	private function ctx(): array {
		return array(
			'key'   => self::KEY,
			'sleep' => function ( $seconds ) {
				$this->sleeps[] = $seconds;
			},
			'log'   => function ( $message ) {
				$this->logs[] = $message;
			},
		);
	}

	private function run_with( array $feeds, array $entry, array $responses = array() ): void {
		$this->calls     = array();
		$this->logs      = array();
		$this->sleeps    = array();
		$this->responses = $responses;
		$GLOBALS['shakewell_gf_meta'] = array();
		add_filter( 'shakewell_klaviyo_connector_feeds', fn() => $feeds );
		Connector::process_entry( $entry, $this->form, $this->ctx() );
		remove_all_filters( 'shakewell_klaviyo_connector_feeds' );
	}

	private function subscriptions(): array {
		return array_values( array_filter( $this->calls, fn( $c ) => str_contains( $c['url'], 'profile-subscription-bulk-create-jobs' ) ) );
	}

	private function mentions( string $needle ): int {
		return count( array_filter( $this->calls, fn( $c ) => str_contains( wp_json_encode( $c['body'] ), $needle ) ) );
	}

	public function test_maps_profile_and_sends_the_right_request(): void {
		$this->run_with( array( $this->feed ), $this->entry );
		$attrs = $this->calls[0]['body']['data']['attributes'];
		$this->assertSame( 'https://a.klaviyo.com/api/profile-import/', $this->calls[0]['url'] );
		$this->assertSame( 'sam.private@example.com', $attrs['email'] );
		$this->assertSame( 'Sam', $attrs['first_name'] );
		$this->assertSame( 'Private', $attrs['last_name'] );
		$this->assertSame( '+447911123456', $attrs['phone_number'] );
		$this->assertSame( array( 'city' => 'Leeds' ), $attrs['location'] );
		$this->assertSame( array( 'Plan' => 'Pro' ), $attrs['properties'] );
		$this->assertSame( 'Klaviyo-API-Key ' . self::KEY, $this->calls[0]['headers']['Authorization'] );
		$this->assertSame( '2026-04-15', $this->calls[0]['headers']['revision'] );
		$this->assertSame( 'application/vnd.api+json', $this->calls[0]['headers']['Content-Type'] );
	}

	public function test_sends_only_mapped_fields(): void {
		$this->entry['99'] = 'unmapped secret';
		$this->run_with( array( $this->feed ), $this->entry );
		$this->assertSame( 0, $this->mentions( 'unmapped secret' ) );
	}

	public function test_subscribes_each_list_with_consent_and_skips_sms_without_it(): void {
		$this->run_with( array( $this->feed ), $this->entry );
		$sub = $this->subscriptions();
		$this->assertCount( 2, $sub );
		$this->assertSame( 'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/', $sub[0]['url'] );
		$this->assertSame( 'LIST1', $sub[0]['body']['data']['relationships']['list']['data']['id'] );
		$this->assertSame( 'LIST2', $sub[1]['body']['data']['relationships']['list']['data']['id'] );
		$this->assertSame( 'SUBSCRIBED', $sub[0]['body']['data']['attributes']['profiles']['data'][0]['attributes']['subscriptions']['email']['marketing']['consent'] );
		$this->assertSame( 'Newsletter sign-up', $sub[0]['body']['data']['attributes']['custom_source'] );
		$this->assertSame( 0, $this->mentions( 'SMS1' ) );
		$last = end( $this->calls );
		$this->assertSame( 'https://a.klaviyo.com/api/events/', $last['url'] );
		$this->assertSame( 'Signed up', $last['body']['data']['attributes']['metric']['data']['attributes']['name'] );
	}

	public function test_subscribes_sms_when_its_consent_is_ticked(): void {
		$this->run_with( array( $this->feed ), array( '9' => 'Yes' ) + $this->entry );
		$this->assertSame( 1, $this->mentions( 'SMS1' ) );
	}

	public function test_without_email_consent_nothing_is_sent(): void {
		$this->run_with( array( $this->feed ), array( '8' => '' ) + $this->entry );
		$this->assertSame( array(), $this->calls );
		$this->assertCount( 1, $this->logs );
		$this->assertStringContainsString( 'no consent', $this->logs[0] );
		$this->assertSame( 'sent', $GLOBALS['shakewell_gf_meta'][501]['shakewell_klaviyo_connector_feed_0'] );
	}

	public function test_sms_only_consent_sends_the_sms_subscription_but_no_email_one(): void {
		$this->run_with( array( $this->feed ), array(
			'8' => '',
			'9' => 'Yes',
		) + $this->entry );
		$this->assertSame( 0, $this->mentions( 'LIST1' ) );
		$this->assertSame( 1, $this->mentions( 'SMS1' ) );
	}

	public function test_feed_without_consent_field_subscribes_always(): void {
		unset( $this->feed['email_consent'], $this->feed['sms'] );
		$this->run_with( array( $this->feed ), array( '8' => '' ) + $this->entry );
		$this->assertCount( 2, $this->subscriptions() );
	}

	public function test_unmapped_form_spam_invalid_email_and_no_feeds_send_nothing(): void {
		$this->run_with( array( array( 'form_id' => 99 ) + $this->feed ), $this->entry );
		$this->assertSame( array(), $this->calls );
		$this->run_with( array( $this->feed ), array( 'status' => 'spam' ) + $this->entry );
		$this->assertSame( array(), $this->calls );
		$this->run_with( array( $this->feed ), array( '3' => 'not-an-email' ) + $this->entry );
		$this->assertSame( array(), $this->calls );
		$this->assertCount( 1, $this->logs );
		$this->run_with( array(), $this->entry );
		$this->assertSame( array(), $this->calls );
	}

	public function test_rejected_phone_is_dropped_and_the_signup_kept(): void {
		$bad = $this->reply( 400, '{"errors":[{"code":"invalid","detail":"x","source":{"pointer":"/data/attributes/phone_number"}}]}' );
		$this->run_with( array( $this->feed ), array( '9' => 'Yes' ) + $this->entry, array( $bad ) );
		$this->assertArrayNotHasKey( 'phone_number', $this->calls[1]['body']['data']['attributes'] );
		$this->assertSame( 'sam.private@example.com', $this->calls[1]['body']['data']['attributes']['email'] );
		$this->assertSame( 0, $this->mentions( 'SMS1' ), 'no SMS subscription without a usable number' );
	}

	public function test_429_honours_retry_after_and_5xx_backs_off(): void {
		$this->run_with( array( $this->feed ), $this->entry, array( $this->reply( 429, '', array( 'retry-after' => '3' ) ), $this->reply( 503 ) ) );
		$this->assertSame( array( 3, 2 ), $this->sleeps );
		$this->assertStringEndsWith( '/profile-import/', $this->calls[2]['url'] );
		$this->assertGreaterThan( 3, count( $this->calls ) );
	}

	public function test_gives_up_after_three_attempts_and_marks_failed(): void {
		$err = new WP_Error( 'http_request_failed', 'down' );
		$this->run_with( array( $this->feed ), $this->entry, array( $err, $err, $err ) );
		$this->assertCount( 3, $this->calls );
		$this->assertSame( array( 1, 2 ), $this->sleeps );
		$this->assertSame( 'failed', $GLOBALS['shakewell_gf_meta'][501]['shakewell_klaviyo_connector_feed_0'] );
	}

	public function test_client_error_is_not_retried(): void {
		$this->run_with( array( $this->feed ), $this->entry, array( $this->reply( 401 ) ) );
		$this->assertCount( 1, $this->calls );
	}

	public function test_same_entry_never_sends_twice_and_a_failed_one_is_replayed(): void {
		$feeds = array( $this->feed );
		$this->run_with( $feeds, $this->entry );
		$sent = count( $this->calls );
		add_filter( 'shakewell_klaviyo_connector_feeds', fn() => $feeds );
		$this->calls = array();
		Connector::process_entry( $this->entry, $this->form, $this->ctx() );
		$this->assertSame( array(), $this->calls );
		$GLOBALS['shakewell_gf_meta'][501]['shakewell_klaviyo_connector_feed_0'] = 'failed';
		Connector::process_entry( $this->entry, $this->form, $this->ctx() );
		$this->assertCount( $sent, $this->calls );
		remove_all_filters( 'shakewell_klaviyo_connector_feeds' );
	}

	public function test_failure_log_has_no_personal_data_and_no_key(): void {
		$body = '{"errors":[{"code":"invalid","detail":"sam.private@example.com is bad","source":{"pointer":"/data/attributes/email"}}]}';
		$this->run_with( array( $this->feed ), $this->entry, array( $this->reply( 400, $body ) ) );
		$text = implode( "\n", $this->logs );
		$this->assertStringContainsString( 'status=400', $text );
		$this->assertStringContainsString( 'entry=501', $text );
		$this->assertDoesNotMatchRegularExpression( '/sam|private|example\.com|07911|Leeds|pk_test_secret/i', $text );
	}

	public function test_phone_formats(): void {
		$cases = array(
			array( '07911 123456', '+447911123456' ),
			array( '+44 7911 123456', '+447911123456' ),
			array( '911123456', '+44911123456' ),
			array( '00447911123456', '+447911123456' ),
			array( '+1 (212) 555-0100', '+12125550100' ),
			array( '123', '' ),
		);
		foreach ( $cases as list( $in, $out ) ) {
			$this->assertSame( $out, Connector::phone( $in, '44' ), $in );
		}
		$this->assertSame( '+61412345678', Connector::phone( '0412 345 678', '61' ) );
	}

	public function test_local_numbers_need_a_country_code_but_international_ones_do_not(): void {
		$this->assertSame( '', Connector::phone( '07911 123456' ) );
		$this->assertSame( '', Connector::phone( '911123456' ) );
		$this->assertSame( '+447911123456', Connector::phone( '+44 7911 123456' ) );
		$this->assertSame( '+447911123456', Connector::phone( '0044 7911 123456' ) );
	}

	public function test_off_by_default_without_a_key_and_on_with_both(): void {
		$this->assertNull( Plugin::context() );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY=k' );
		$this->assertNull( Plugin::context(), 'key alone is not enough' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED=1' );
		$this->assertNull( Plugin::context(), 'flag alone is not enough' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY=k' );
		$this->assertSame( 'k', Plugin::context()['key'] );
	}

	public function test_unprefixed_names_are_ignored(): void {
		putenv( 'KLAVIYO_CONNECTOR_ENABLED=1' );
		putenv( 'KLAVIYO_PRIVATE_API_KEY=other' );
		$this->assertNull( Plugin::context() );
		putenv( 'KLAVIYO_CONNECTOR_ENABLED' );
		putenv( 'KLAVIYO_PRIVATE_API_KEY' );
	}

	public function test_hook_does_nothing_while_off(): void {
		add_filter( 'shakewell_klaviyo_connector_feeds', fn() => array( $this->feed ) );
		Plugin::on_submission( $this->entry, $this->form );
		$this->assertSame( array(), $this->calls );
		$this->assertSame( array(), $GLOBALS['shakewell_gf_meta'] );
	}

	public function test_hook_sends_with_the_key_from_the_environment(): void {
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED=1' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY=' . self::KEY );
		add_filter( 'shakewell_klaviyo_connector_feeds', fn() => array( $this->feed ) );
		$this->assertNotFalse( has_action( 'gform_after_submission', array( Plugin::class, 'on_submission' ) ) );
		Plugin::on_submission( $this->entry, $this->form );
		$this->assertSame( 'Klaviyo-API-Key ' . self::KEY, $this->calls[0]['headers']['Authorization'] );
		$this->assertCount( 2, $this->subscriptions() );
	}

	public function test_unexpected_exception_is_logged_by_class_only_and_never_breaks_the_form(): void {
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED=1' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY=' . self::KEY );
		add_filter(
			'shakewell_klaviyo_connector_feeds',
			function () {
				throw new \RuntimeException( 'boom ' . self::KEY . ' sam.private@example.com' );
			}
		);
		$file = wp_tempnam( 'klaviyo-log' );
		$prev = ini_set( 'error_log', $file ); // phpcs:ignore WordPress.PHP.IniSet
		Plugin::on_submission( $this->entry, $this->form );
		ini_set( 'error_log', (string) $prev ); // phpcs:ignore WordPress.PHP.IniSet
		$logged = (string) file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		unlink( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->assertSame( array(), $this->calls );
		$this->assertStringContainsString( 'unexpected RuntimeException', $logged );
		$this->assertStringNotContainsString( 'boom', $logged );
		$this->assertStringNotContainsString( self::KEY, $logged );
		$this->assertStringNotContainsString( 'sam.private', $logged );
	}

	public function test_key_is_never_stored_in_options(): void {
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED=1' );
		putenv( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY=' . self::KEY );
		add_filter( 'shakewell_klaviyo_connector_feeds', fn() => array( $this->feed ) );
		Plugin::on_submission( $this->entry, $this->form );
		global $wpdb;
		$this->assertSame( '0', (string) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s", '%' . self::KEY . '%' ) ) );
	}

	public function test_notice_shows_to_admins_when_gravity_forms_is_missing(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Plugin::missing_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'needs the Gravity Forms plugin', $html );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		ob_start();
		Plugin::missing_notice();
		$this->assertSame( '', ob_get_clean() );
	}
}
