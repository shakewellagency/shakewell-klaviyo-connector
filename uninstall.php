<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\KlaviyoConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// No options are stored. Sent/failed flags live in Gravity Forms entry meta and go with the entries; only pending retries are ours to remove.
wp_unschedule_hook( 'shakewell_klaviyo_connector_retry' );
