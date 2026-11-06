=== Shakewell Connector for Klaviyo and Gravity Forms ===
Contributors: shakewell
Tags: klaviyo, gravity forms, email marketing, newsletter, consent
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Adds people who submit selected Gravity Forms forms to a Klaviyo list, with their consent. Off by default.

== Description ==

When a visitor submits a form you have chosen, the plugin creates or updates their profile in Klaviyo and subscribes them to the lists you name. It does the job of the paid Gravity Forms Klaviyo add-on, with no feed screen: you describe each form in code, so the mapping lives in version control.

* Off until you turn it on. Nothing is sent without an API key and the enabled switch.
* Consent first. If you map a consent checkbox and it is not ticked, nothing is sent for that person.
* Sends only the fields you map.
* The Klaviyo private API key is read from a PHP constant or an environment variable. It is never stored in the database and never shown in any screen.
* Retries temporary failures (network errors, rate limits, server errors) up to three times. Each entry is marked sent or failed, so the same submission is never sent twice.
* Logs carry the form id, entry id, step and HTTP status only. Never an email address, a name, a phone number, the API key or Klaviyo's response text.
* Stays silent while the paid Klaviyo add-on for Gravity Forms is active, so nobody is sent twice.

Requires the Gravity Forms plugin, which is a paid plugin from a third party and is not included. If it is missing, an admin notice says so and the connector does nothing.

= External services =

This plugin sends data to Klaviyo (https://www.klaviyo.com), the email marketing service whose account you connect.

* What is sent: for each submission of a form you have mapped, only the fields you mapped: any of email address, first name, last name, phone number, location fields and custom properties, plus the Klaviyo list id, the consent status "subscribed", an optional source label and an optional event name. Your private API key is sent in the Authorization header.
* When: once per submission, right after the form is submitted, and only if the connector is enabled, a key is set and the person ticked the consent checkbox you mapped (if any). Failed requests are retried up to three times.
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

The same names work as environment variables. The older names `KLAVIYO_PRIVATE_API_KEY` and `KLAVIYO_CONNECTOR_ENABLED` are also accepted, as constants or environment variables. The enabled switch is on for `1` or `true` and off for anything else.

4. Add a feed per form with the `shakewell_klaviyo_connector_feeds` filter, in a theme, a must-use plugin or your own plugin:

`add_filter( 'shakewell_klaviyo_connector_feeds', function ( $feeds ) {`
`    $feeds[] = array(`
`        'form_id'       => 30,                  // Gravity Forms form id`
`        'lists'         => array( 'AbC123' ),   // Klaviyo list ids for email marketing`
`        'email_consent' => '8.1',               // consent checkbox input id; omit to always subscribe`
`        'profile'       => array(               // Klaviyo attribute => Gravity Forms field id`
`            'email'         => '3',`
`            'first_name'    => '1.3',           // the first-name part of a Name field`
`            'last_name'     => '1.6',`
`            'phone_number'  => '4',`
`            'location.city' => '6',`
`        ),`
`        'properties'    => array( 'Venue' => '7' ),  // custom profile properties (optional)`
`        'source'        => 'Venue enquiry',          // custom_source label (optional)`
`        'sms'           => array( 'list' => 'XyZ789', 'consent' => '9.1' ),  // optional SMS list`
`        'event'         => array( 'name' => 'Enquiry', 'properties' => array( 'Venue' => '7' ) ),  // optional`
`        'phone_country_code' => '61',           // optional, default 61 (Australia)`
`    );`
`    return $feeds;`
`} );`

Field ids are the ones in the Gravity Forms entry: a plain number such as `3`, or `1.3` for one input of a compound field. A checkbox field stores its choices as inputs, so map the consent checkbox as `8.1`, not `8`.

== Consent ==

If a feed maps `email_consent`, the person must have ticked that checkbox (any non-empty value) or nothing is sent for them: no profile, no subscription, no event. The same holds for the SMS list and its own `consent` field. A feed with no consent field subscribes everyone who submits the form, so use one wherever the law of your audience asks for opt-in. Collecting and recording the consent, and the wording next to the checkbox, are your responsibility.

== Frequently Asked Questions ==

= Does it need Gravity Forms? =

Yes. Gravity Forms is a separate paid plugin. Without it you see an admin notice and nothing is sent.

= Where do I put the API key? =

In wp-config.php or the server environment, as shown under Installation. There is deliberately no settings screen for it.

= Why was nobody added to Klaviyo? =

Check that the enabled switch and the key are set, that the form id matches a feed, that the email field is a valid address, that the consent box was ticked, and the PHP error log. Failed entries are marked failed in the entry's meta and are retried by calling `Shakewell\KlaviyoConnector\Connector::process_entry()` for the entry.

= Can I use it with the Gravity Forms Klaviyo add-on? =

Not at the same time. While the add-on is active this plugin sends nothing. Deactivate the add-on to switch over.

= Where do I report a security problem? =

Please do not open a public issue. Follow SECURITY.md in the plugin's repository.

== Changelog ==

= 0.1.0 =
* First release. Per-form feeds, consent handling, retries, de-duplication per entry, key from the server configuration, logs without personal data.
