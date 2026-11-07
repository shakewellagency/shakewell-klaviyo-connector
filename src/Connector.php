<?php
/**
 * Sends Gravity Forms entries to Klaviyo according to the configured feeds.
 *
 * Per feed, on gform_after_submission: upsert the profile (idempotent on email), subscribe it to each
 * list with consent, optionally record an event. Each entry is marked sent or failed in entry meta, so a
 * repeated hook sends nothing and a failed entry can be replayed with Connector::process_entry().
 *
 * @package Shakewell\KlaviyoConnector
 */

namespace Shakewell\KlaviyoConnector;

defined( 'ABSPATH' ) || exit;

/**
 * Feed processing and the Klaviyo API calls.
 */
final class Connector {

	public const REVISION = '2026-04-15';

	/**
	 * Feeds: one entry per form. See readme.txt for the format.
	 */
	public static function feeds(): array {
		return (array) apply_filters( 'shakewell_klaviyo_connector_feeds', array() );
	}

	/**
	 * One API call, up to 3 attempts on transport errors, 429 and 5xx.
	 * Returns error codes and field pointers only: the detail text can contain the submitted address.
	 *
	 * @param array  $ctx     Context from Plugin::context().
	 * @param string $path    Path under /api/.
	 * @param array  $payload JSON:API body.
	 * @return array{ok:bool,code:int,errors:string[]}
	 */
	public static function request( array $ctx, string $path, array $payload ): array {
		$args = array(
			'method'  => 'POST',
			'timeout' => 10,
			'headers' => array(
				'Authorization' => 'Klaviyo-API-Key ' . $ctx['key'],
				'Content-Type'  => 'application/vnd.api+json',
				'Accept'        => 'application/vnd.api+json',
				'revision'      => self::REVISION,
			),
			'body'    => wp_json_encode( $payload ),
		);
		$code = 0;
		$body = '';
		for ( $try = 1; $try <= 3; $try++ ) {
			$response = wp_remote_request( 'https://a.klaviyo.com/api/' . $path, $args );
			$code     = 0;
			$body     = '';
			$wait     = null;
			if ( ! is_wp_error( $response ) ) {
				$code        = (int) wp_remote_retrieve_response_code( $response );
				$body        = (string) wp_remote_retrieve_body( $response );
				$retry_after = wp_remote_retrieve_header( $response, 'retry-after' );
				$wait        = is_numeric( $retry_after ) ? (int) $retry_after : null;
			}
			if ( 0 !== $code && 429 !== $code && $code < 500 ) {
				break;
			}
			if ( $try < 3 ) {
				( $ctx['sleep'] )( min( 5, $wait ?? 2 ** ( $try - 1 ) ) );
			}
		}
		$errors  = array();
		$decoded = json_decode( $body, true );
		foreach ( is_array( $decoded ) ? (array) ( $decoded['errors'] ?? array() ) : array() as $error ) {
			$errors[] = ( $error['code'] ?? '?' ) . '@' . ( $error['source']['pointer'] ?? '' );
		}
		return array(
			'ok'     => $code >= 200 && $code < 300,
			'code'   => $code,
			'errors' => $errors,
		);
	}

	/**
	 * E.164 phone. A leading + or 00 is international. A leading 0 is a national trunk prefix: it is
	 * replaced by the feed's country code, and without one the number is unusable. Any other digits are
	 * taken to include their country code already. Empty string if unusable.
	 *
	 * @param string $raw          Phone as typed.
	 * @param string $country_code Digits only, no plus.
	 */
	public static function phone( string $raw, string $country_code = '' ): string {
		$digits = (string) preg_replace( '/\D/', '', $raw );
		if ( str_starts_with( trim( $raw ), '+' ) ) {
			return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? '+' . $digits : '';
		}
		if ( str_starts_with( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( str_starts_with( $digits, '0' ) ) {
			if ( '' === $country_code ) {
				return '';
			}
			$digits = $country_code . substr( $digits, 1 );
		}
		return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? '+' . $digits : '';
	}

	/**
	 * Trimmed entry value for a field id such as '3' or '1.3'.
	 *
	 * @param array  $entry    Gravity Forms entry.
	 * @param string $field_id Field or input id.
	 */
	public static function value( array $entry, string $field_id ): string {
		$value = $entry[ $field_id ] ?? '';
		return is_scalar( $value ) ? trim( (string) $value ) : '';
	}

	/**
	 * Profile attributes and custom properties, from mapped fields only.
	 *
	 * @param array $feed  Feed.
	 * @param array $entry Gravity Forms entry.
	 */
	public static function profile_attributes( array $feed, array $entry ): array {
		$attrs = array();
		foreach ( $feed['profile'] ?? array() as $name => $field_id ) {
			$value = self::value( $entry, (string) $field_id );
			if ( 'phone_number' === $name ) {
				$value = self::phone( $value, (string) ( $feed['phone_country_code'] ?? '' ) );
			}
			if ( '' === $value ) {
				continue;
			}
			if ( str_starts_with( $name, 'location.' ) ) {
				$attrs['location'][ substr( $name, 9 ) ] = $value;
			} else {
				$attrs[ $name ] = $value;
			}
		}
		foreach ( $feed['properties'] ?? array() as $name => $field_id ) {
			$value = self::value( $entry, (string) $field_id );
			if ( '' !== $value ) {
				$attrs['properties'][ $name ] = $value;
			}
		}
		return $attrs;
	}

	/**
	 * Bulk subscribe job body for one list, by email or SMS, with consent SUBSCRIBED.
	 *
	 * @param string      $list_id Klaviyo list id.
	 * @param array       $profile Profile attributes.
	 * @param bool        $sms     True to subscribe the phone number to SMS instead of the email to email.
	 * @param string|null $source  Optional custom_source.
	 */
	public static function subscribe_payload( string $list_id, array $profile, bool $sms, ?string $source ): array {
		$channel = array( 'marketing' => array( 'consent' => 'SUBSCRIBED' ) );
		$person  = $sms
			? array(
				'phone_number'  => $profile['phone_number'],
				'subscriptions' => array( 'sms' => $channel ),
			)
			: array(
				'email'         => $profile['email'],
				'subscriptions' => array( 'email' => $channel ),
			);
		$attrs   = array(
			'profiles' => array(
				'data' => array(
					array(
						'type'       => 'profile',
						'attributes' => $person,
					),
				),
			),
		);
		if ( $source ) {
			$attrs['custom_source'] = $source;
		}
		return array(
			'data' => array(
				'type'          => 'profile-subscription-bulk-create-job',
				'attributes'    => $attrs,
				'relationships' => array(
					'list' => array(
						'data' => array(
							'type' => 'list',
							'id'   => $list_id,
						),
					),
				),
			),
		);
	}

	/**
	 * Runs one feed for one entry. True when every step succeeded or there was nothing to send.
	 *
	 * @param array  $ctx   Context.
	 * @param array  $feed  Feed.
	 * @param array  $entry Gravity Forms entry.
	 * @param string $tag   Log prefix (form and entry ids).
	 */
	public static function run_feed( array $ctx, array $feed, array $entry, string $tag ): bool {
		$log   = $ctx['log'];
		$attrs = self::profile_attributes( $feed, $entry );
		$phone = (string) ( $feed['profile']['phone_number'] ?? '' );
		if ( '' !== $phone && '' !== self::value( $entry, $phone ) && empty( $attrs['phone_number'] ) ) {
			// Constant text only: never the number itself.
			$log( "$tag phone not usable; local numbers need phone_country_code in the feed" );
		}
		if ( ! filter_var( $attrs['email'] ?? '', FILTER_VALIDATE_EMAIL ) ) {
			$log( "$tag skipped: no valid email" );
			return true; // Nothing to send is not a failure to retry.
		}

		// A mapped consent field must be ticked; without consent nothing at all is sent.
		$consent_fields = array( $feed['email_consent'] ?? null, $feed['sms']['consent'] ?? null );
		$consented      = fn( $field ) => null === $field || '' !== self::value( $entry, (string) $field );
		$source         = $feed['source'] ?? null;
		$steps          = array();
		if ( $consented( $feed['email_consent'] ?? null ) ) {
			foreach ( $feed['lists'] ?? array() as $list ) {
				$steps[ "list=$list" ] = self::subscribe_payload( (string) $list, $attrs, false, $source );
			}
		}
		if ( ! empty( $feed['sms']['list'] ) && ! empty( $attrs['phone_number'] ) && $consented( $feed['sms']['consent'] ?? null ) ) {
			$steps[ 'sms=' . $feed['sms']['list'] ] = self::subscribe_payload( (string) $feed['sms']['list'], $attrs, true, $source );
		}
		if ( ! $steps && array_filter( $consent_fields, fn( $f ) => null !== $f ) ) {
			$log( "$tag skipped: no consent" );
			return true;
		}

		$import = fn( array $a ) => self::request(
			$ctx,
			'profile-import/',
			array(
				'data' => array(
					'type'       => 'profile',
					'attributes' => $a,
				),
			)
		);
		$result = $import( $attrs );
		if ( ! $result['ok'] && 400 === $result['code'] && isset( $attrs['phone_number'] ) && preg_grep( '/phone_number/', $result['errors'] ) ) {
			unset( $attrs['phone_number'] ); // A rejected number must not lose the sign-up.
			unset( $steps[ 'sms=' . ( $feed['sms']['list'] ?? '' ) ] );
			$result = $import( $attrs );
		}
		if ( ! $result['ok'] ) {
			$log( "$tag step=profile status={$result['code']} errors=" . implode( ',', $result['errors'] ) );
			return false;
		}

		$ok = true;
		foreach ( $steps as $label => $payload ) {
			$result = self::request( $ctx, 'profile-subscription-bulk-create-jobs/', $payload );
			if ( ! $result['ok'] ) {
				$ok = false;
				$log( "$tag step=subscribe $label status={$result['code']} errors=" . implode( ',', $result['errors'] ) );
			}
		}

		if ( ! empty( $feed['event']['name'] ) ) {
			$props  = self::profile_attributes( array( 'properties' => $feed['event']['properties'] ?? array() ), $entry )['properties'] ?? array();
			$result = self::request(
				$ctx,
				'events/',
				array(
					'data' => array(
						'type'       => 'event',
						'attributes' => array(
							'properties' => (object) $props,
							'metric'     => array(
								'data' => array(
									'type'       => 'metric',
									'attributes' => array( 'name' => $feed['event']['name'] ),
								),
							),
							'profile'    => array(
								'data' => array(
									'type'       => 'profile',
									'attributes' => array( 'email' => $attrs['email'] ),
								),
							),
						),
					),
				)
			);
			if ( ! $result['ok'] ) {
				$ok = false;
				$log( "$tag step=event status={$result['code']} errors=" . implode( ',', $result['errors'] ) );
			}
		}
		return $ok;
	}

	/**
	 * All feeds for the entry's form. Entry meta makes it safe to call twice for the same entry.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 * @param array $ctx   Context.
	 */
	public static function process_entry( array $entry, array $form, array $ctx ): void {
		if ( 'spam' === ( $entry['status'] ?? 'active' ) || empty( $entry['id'] ) ) {
			return;
		}
		foreach ( self::feeds() as $i => $feed ) {
			if ( (int) ( $feed['form_id'] ?? 0 ) !== (int) $form['id'] ) {
				continue;
			}
			$meta = "shakewell_klaviyo_connector_feed_$i";
			if ( 'sent' === gform_get_meta( $entry['id'], $meta ) ) {
				continue;
			}
			$ok = self::run_feed( $ctx, $feed, $entry, "form={$form['id']} entry={$entry['id']}" );
			gform_update_meta( $entry['id'], $meta, $ok ? 'sent' : 'failed' );
		}
	}
}
