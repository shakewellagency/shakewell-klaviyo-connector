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
	 * One API call, one attempt: the visitor is waiting. A failed entry is retried later from cron.
	 * Returns error codes and field pointers only: the detail text can contain the submitted address.
	 *
	 * @param array  $ctx     Context from Plugin::context().
	 * @param string $path    Path under /api/.
	 * @param array  $payload JSON:API body.
	 * @return array{ok:bool,code:int,errors:string[]}
	 */
	public static function request( array $ctx, string $path, array $payload ): array {
		$left = isset( $ctx['deadline'] ) ? $ctx['deadline'] - microtime( true ) : 5;
		if ( $left < 1 ) {
			// Under a second of the visitor's budget is left: do not call, so the total stays within 5 seconds. Cron retries.
			return array(
				'ok'     => false,
				'code'   => 0,
				'errors' => array(),
			);
		}
		$response = wp_remote_request(
			'https://a.klaviyo.com/api/' . $path,
			array(
				'method'  => 'POST',
				'timeout' => min( 5, $left ),
				'headers' => array(
					'Authorization' => 'Klaviyo-API-Key ' . $ctx['key'],
					'Content-Type'  => 'application/vnd.api+json',
					'Accept'        => 'application/vnd.api+json',
					'revision'      => self::REVISION,
				),
				'body'    => wp_json_encode( $payload ),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		$body     = is_wp_error( $response ) ? '' : (string) wp_remote_retrieve_body( $response );
		$errors   = array();
		$decoded  = json_decode( $body, true );
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
	 * E.164 phone. A leading + or 00 is international. Otherwise the number is national and needs the
	 * feed's country code (without one it is unusable): a leading 0 is replaced by it, and digits that
	 * do not already start with it get it prefixed. Empty string if unusable.
	 *
	 * @param string $raw          Phone as typed.
	 * @param string $country_code Digits only, no plus.
	 */
	public static function phone( string $raw, string $country_code = '' ): string {
		$country_code = (string) preg_replace( '/\D/', '', $country_code ); // "+61" and "61" are the same.
		$digits       = (string) preg_replace( '/\D/', '', $raw );
		if ( str_starts_with( trim( $raw ), '+' ) ) {
			return strlen( $digits ) >= 8 && strlen( $digits ) <= 15 ? '+' . $digits : '';
		}
		if ( str_starts_with( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( '' === $country_code ) {
			return '';
		} elseif ( str_starts_with( $digits, '0' ) ) {
			$digits = $country_code . substr( $digits, 1 );
		} elseif ( ! str_starts_with( $digits, $country_code ) ) {
			// ponytail: a national number that happens to begin with the country code is read as international.
			$digits = $country_code . $digits;
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
	 * @param array  $done  Step labels already delivered for this entry; steps that succeed are added, so a replay repeats only what failed.
	 * @param string $feed_key Key of the feed in the feeds list; makes the event's unique_id.
	 */
	public static function run_feed( array $ctx, array $feed, array $entry, string $tag, array &$done = array(), string $feed_key = '0' ): bool {
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
		foreach ( array_filter( $consent_fields, fn( $f ) => null !== $f ) as $field ) {
			// A checkbox group is stored as 3.1, 3.2 ...; plain 3 is always empty and would silently drop consent.
			if ( ! str_contains( (string) $field, '.' ) && array_filter( array_keys( $entry ), fn( $k ) => str_starts_with( (string) $k, "$field." ) ) ) {
				$log( "$tag skipped: consent field $field is a checkbox group, map an input id such as $field.1" );
				return true; // A setup error does not fix itself on retry.
			}
		}
		$consented = fn( $field ) => null === $field || '' !== self::value( $entry, (string) $field );
		$email_ok  = $consented( $feed['email_consent'] ?? null );
		$sms_ok    = $consented( $feed['sms']['consent'] ?? null );
		$source    = $feed['source'] ?? null;
		$steps     = array();
		if ( $email_ok ) {
			foreach ( $feed['lists'] ?? array() as $list ) {
				$steps[ "list=$list" ] = self::subscribe_payload( (string) $list, $attrs, false, $source );
			}
		}
		if ( ! empty( $feed['sms']['list'] ) && ! empty( $attrs['phone_number'] ) && $sms_ok ) {
			$steps[ 'sms=' . $feed['sms']['list'] ] = self::subscribe_payload( (string) $feed['sms']['list'], $attrs, true, $source );
		}
		if ( ! $steps && array_filter( $consent_fields, fn( $f ) => null !== $f ) ) {
			$log( "$tag skipped: no consent" );
			return true;
		}
		// Each channel's identifier travels only with that channel's consent.
		if ( ! $email_ok ) {
			unset( $attrs['email'] );
		}
		if ( ! $sms_ok ) {
			unset( $attrs['phone_number'] );
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
			$log( "$tag phone rejected by Klaviyo; sent without it" );
			if ( empty( $attrs['email'] ) ) {
				return true; // Nothing else may be sent, and a retry would be rejected the same way.
			}
			$result = $import( $attrs );
		}
		if ( ! $result['ok'] ) {
			$log( "$tag step=profile status={$result['code']} errors=" . implode( ',', $result['errors'] ) );
			return false;
		}

		$ok = true;
		// The visitor is waiting: stop at the first sign Klaviyo is slow or down, or when the time budget is
		// spent, and leave the remaining steps to cron.
		$late      = static fn(): bool => isset( $ctx['deadline'] ) && microtime( true ) >= $ctx['deadline'];
		$transient = static fn( array $r ): bool => 0 === $r['code'] || 429 === $r['code'] || $r['code'] >= 500;
		$stopped   = false;
		foreach ( array_diff_key( $steps, array_flip( $done ) ) as $label => $payload ) {
			if ( $late() ) {
				$stopped = true;
				break;
			}
			$result = self::request( $ctx, 'profile-subscription-bulk-create-jobs/', $payload );
			if ( $result['ok'] ) {
				$done[] = $label;
				continue;
			}
			$ok = false;
			$log( "$tag step=subscribe $label status={$result['code']} errors=" . implode( ',', $result['errors'] ) );
			if ( $transient( $result ) ) {
				$stopped = true;
				break;
			}
		}

		$identity = isset( $attrs['email'] ) ? array( 'email' => $attrs['email'] ) : array_intersect_key( $attrs, array( 'phone_number' => 1 ) );
		if ( ! empty( $feed['event']['name'] ) && ! in_array( 'event', $done, true ) && $identity ) {
			if ( $stopped || $late() ) {
				$stopped = true;
			} else {
				$props = self::profile_attributes( array( 'properties' => $feed['event']['properties'] ?? array() ), $entry )['properties'] ?? array();
				$event = array(
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
							'attributes' => $identity,
						),
					),
					// Klaviyo ignores a repeat of the same unique_id, so a retry after a lost response cannot double-count.
					'unique_id'  => 'gf-' . (int) ( $entry['form_id'] ?? 0 ) . "-{$entry['id']}-$feed_key",
				);
				$created_at = ! empty( $entry['date_created'] ) ? strtotime( $entry['date_created'] . ' UTC' ) : false;
				if ( $created_at ) {
					$event['time'] = gmdate( 'c', $created_at );
				}
				$result = self::request(
					$ctx,
					'events/',
					array(
						'data' => array(
							'type'       => 'event',
							'attributes' => $event,
						),
					)
				);
				if ( ! $result['ok'] ) {
					$ok = false;
					$log( "$tag step=event status={$result['code']} errors=" . implode( ',', $result['errors'] ) );
				} else {
					$done[] = 'event';
				}
			}
		}
		if ( $stopped ) {
			$ok = false;
			$log( "$tag stopped early; remaining steps left to cron" );
		}
		return $ok;
	}

	/**
	 * All feeds for the entry's form. Entry meta makes it safe to call twice for the same entry: a sent
	 * feed is skipped, and a failed one repeats only the steps that did not succeed.
	 * True when every feed succeeded.
	 *
	 * @param array $entry Gravity Forms entry.
	 * @param array $form  Gravity Forms form.
	 * @param array $ctx   Context.
	 */
	public static function process_entry( array $entry, array $form, array $ctx ): bool {
		if ( 'spam' === ( $entry['status'] ?? 'active' ) || empty( $entry['id'] ) ) {
			return true;
		}
		$all_ok = true;
		foreach ( self::feeds() as $i => $feed ) {
			if ( (int) ( $feed['form_id'] ?? 0 ) !== (int) $form['id'] ) {
				continue;
			}
			$meta = "shakewell_klaviyo_connector_feed_$i";
			if ( 'sent' === gform_get_meta( $entry['id'], $meta ) ) {
				continue;
			}
			$done = array_filter( explode( ',', (string) gform_get_meta( $entry['id'], $meta . '_done' ) ) );
			$ok   = self::run_feed( $ctx, $feed, $entry, "form={$form['id']} entry={$entry['id']}", $done, (string) $i );
			gform_update_meta( $entry['id'], $meta . '_done', implode( ',', $done ) );
			gform_update_meta( $entry['id'], $meta, $ok ? 'sent' : 'failed' );
			$all_ok = $all_ok && $ok;
		}
		return $all_ok;
	}
}
