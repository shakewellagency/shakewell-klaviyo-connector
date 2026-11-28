=== Shakewell Connector for Klaviyo and Gravity Forms ===
Contributors: shakewellagency
Tags: klaviyo, gravity forms, email marketing, newsletter, consent
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.3
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds people who submit selected Gravity Forms forms to a Klaviyo list, with their consent. Off by default.

== Description ==

When a visitor submits a form you have chosen, the plugin creates or updates their profile in Klaviyo and subscribes them to the lists you name. It does the job of the paid Gravity Forms Klaviyo add-on, with no feed screen: you describe each form in code, so the mapping lives in version control.

* Off until you turn it on. Nothing is sent without an API key and the enabled switch.
* Consent first, per channel. Without the email consent box the email address is not sent, and without the SMS consent box the phone number is not sent: not on the profile, not in a subscription, not in the event. If neither box allows anything to be sent, nothing is.
* Sends only the fields you map.
* The Klaviyo private API key is read from a PHP constant or an environment variable. It is never stored in the database and never shown in any screen.
* The visitor waits on Klaviyo for 5 seconds at most in total: each call is tried once, and no call starts when less than a second of that time is left, the first slow, rate-limited or failing response stops the rest, and whatever was not sent is left to WP-Cron. A failed entry is retried from WP-Cron after 1 minute, 15 minutes and 1 hour, repeating only the steps that failed. Each entry is marked sent or failed, so the same step is never sent twice.
* Logs carry the form id, entry id, step and HTTP status only. Never an email address, a name, a phone number, the API key or Klaviyo's response text.
* Stays silent while the paid Klaviyo add-on for Gravity Forms is active, so nobody is sent twice.

Requires the Gravity Forms plugin, which is a paid plugin from a third party and is not included. If it is missing, an admin notice says so and the connector does nothing.

= External services =

This plugin sends data to Klaviyo (https://www.klaviyo.com), the email marketing service whose account you connect.

* What is sent: for each submission of a form you have mapped, only the fields you mapped: any of email address, first name, last name, phone number, location fields and custom properties, plus the Klaviyo list id, the consent status "subscribed", an optional source label and an optional event name. Your private API key is sent in the Authorization header.
* When: once per submission, right after the form is submitted, and only if the connector is enabled, a key is set and the person ticked the consent checkbox you mapped (if any). Failed requests are retried later from WP-Cron, up to three times.
* Where: https://a.klaviyo.com/api/ (profile import, subscription jobs and events).
* Klaviyo terms of service: https://www.klaviyo.com/legal
* Klaviyo privacy policy: https://www.klaviyo.com/legal/privacy-notice

The plugin makes no other outside requests, has no tracking and does not call home.

== Installation ==

1. Install and activate Gravity Forms, then this plugin.
2. In Klaviyo create a private API key with the scopes profiles:write, subscriptions:write, lists:write and events:write, and note the id of each list.
3. Set the key and turn the connector on, in wp-config.php or as server environment variables (never in the database):

`define( 'SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY', 'pk_...' );`
`define( 'SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED', true );`

The same names work as environment variables. The enabled switch is on for exactly `1` or `true` and off for anything else, including `TRUE`, `yes` and `on`.

4. Add a feed per form with the `shakewell_klaviyo_connector_feeds` filter, in a theme, a must-use plugin or your own plugin:

`add_filter( 'shakewell_klaviyo_connector_feeds', function ( $feeds ) {`
`    $feeds[] = array(`
`        'form_id'       => 1,                   // Gravity Forms form id`
`        'lists'         => array( 'AbC123' ),   // Klaviyo list ids for email marketing`
`        'email_consent' => '8.1',               // consent checkbox input id; omit to always subscribe`
`        'profile'       => array(               // Klaviyo attribute => Gravity Forms field id`
`            'email'         => '3',`
`            'first_name'    => '1.3',           // the first-name part of a Name field`
`            'last_name'     => '1.6',`
`            'phone_number'  => '4',`
`            'location.city' => '6',`
`        ),`
`        'properties'    => array( 'Plan' => '7' ),   // custom profile properties (optional)`
`        'source'        => 'Newsletter sign-up',    // custom_source label (optional)`
`        'sms'           => array( 'list' => 'XyZ789', 'consent' => '9.1' ),  // optional SMS list`
`        'event'         => array( 'name' => 'Signed up', 'properties' => array( 'Plan' => '7' ) ),  // optional`
`        'phone_country_code' => '44',           // optional; needed to read national numbers such as 07911 123456 or 7911 123456 (no default)`
`    );`
`    return $feeds;`
`} );`

Field ids are the ones in the Gravity Forms entry: a plain number such as `3`, or `1.3` for one input of a compound field. A checkbox field stores its choices as inputs, so map the consent checkbox as `8.1`, not `8`. A consent field mapped to a checkbox group id is logged and nothing is sent.

== Consent ==

Consent is checked per channel. If a feed maps `email_consent`, the person must have ticked that checkbox (any non-empty value) or their email address is not sent anywhere: no profile, no email subscription, no event. The same holds for the phone number and the SMS list with the `sms` `consent` field. When only one box is ticked, the profile and the event carry just that channel's identifier (so an SMS-only sign-up has a phone number and no email address). If neither allows anything to be sent, nothing is sent. A feed with no consent field subscribes everyone who submits the form, so use one wherever the law of your audience asks for opt-in. The email field must be mapped and filled on every connected form: a submission with an empty or invalid email address is skipped, even when the SMS box is ticked. Collecting and recording the consent, and the wording next to the checkbox, are your responsibility.

== Frequently Asked Questions ==

= Does it need Gravity Forms? =

Yes. Gravity Forms is a separate paid plugin. Without it you see an admin notice and nothing is sent.

= Where do I put the API key? =

In wp-config.php or the server environment, as shown under Installation. There is deliberately no settings screen for it.

= Why was nobody added to Klaviyo? =

Check that the enabled switch and the key are set, that the form id matches a feed, that the email field is a valid address, that the consent box was ticked, and the PHP error log. Failed entries are marked failed in the entry's meta and retried automatically from WP-Cron. To replay one by hand, call `Shakewell\KlaviyoConnector\Connector::process_entry()` for the entry: it repeats only the steps that failed.

= Can I use it with the Gravity Forms Klaviyo add-on? =

Not at the same time. While the add-on is active this plugin sends nothing. Deactivate the add-on to switch over.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Upgrade Notice ==

= 0.1.3 =
Consent is now applied per channel: an unticked email box keeps the email address out of Klaviyo, and an unticked SMS box keeps the phone number out. Deactivating the plugin removes pending retries.

= 0.1.2 =
National phone numbers now need phone_country_code in the feed, with or without the leading 0. Failed submissions are retried from WP-Cron instead of while the visitor waits.

= 0.1.1 =
Breaking: rename KLAVIYO_PRIVATE_API_KEY and KLAVIYO_CONNECTOR_ENABLED to SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY and _ENABLED, or the connector stays off. Set phone_country_code in each feed, or local phone numbers are skipped.

== Changelog ==

= 0.1.3 =
* Consent is applied per channel. With the email box unticked and SMS ticked, the email address no longer goes to Klaviyo (profile or event). With email ticked and SMS unticked, the phone number no longer goes on the profile.
* A visitor waits at most 5 seconds in total. The first transport error, 429 or 5xx stops the remaining steps, and anything left is retried from WP-Cron.
* The sign-up event carries a unique_id and the entry's time, so a retry after a lost response is not counted twice.
* phone_country_code accepts +61 as well as 61. A phone number rejected by Klaviyo is logged (never the number).
* Deactivating or uninstalling the plugin removes pending retries.
* CI fails on a Plugin Check error and .gitattributes normalises line endings.

= 0.1.2 =
* Phone numbers without a leading 0 or + (such as 412345678) get the feed's country code instead of being read as international. Without phone_country_code they are skipped.
* Replaying a failed entry repeats only the steps that failed, so the event is no longer sent twice.
* One attempt per call with a 5 second limit, so a Klaviyo outage no longer holds up the form for 30 seconds. Failed entries are retried from WP-Cron.
* A consent field mapped to a checkbox group (3 instead of 3.1) is logged, and nothing is sent.

= 0.1.1 =
* Breaking: the setting names KLAVIYO_PRIVATE_API_KEY and KLAVIYO_CONNECTOR_ENABLED are replaced by SHAKEWELL_KLAVIYO_CONNECTOR_API_KEY and SHAKEWELL_KLAVIYO_CONNECTOR_ENABLED. The built-in default country code is gone: set phone_country_code per feed. Numbers without a leading + or 00 or 0 are now read as already including their country code, and a skipped phone is logged.
* Site-neutral release: no built-in country code for local phone numbers (set `phone_country_code` per feed), the admin notice is dismissible, and only the `SHAKEWELL_KLAVIYO_CONNECTOR_` setting names are read.

= 0.1.0 =
* First release. Per-form feeds, consent handling, retries, de-duplication per entry, key from the server configuration, logs without personal data.
