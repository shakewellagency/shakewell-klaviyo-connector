<?php
/**
 * Removes everything the plugin stored when it is deleted from the Plugins screen.
 *
 * @package Shakewell\KlaviyoConnector
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Nothing to delete: the plugin stores no options. Sent/failed flags live in Gravity Forms entry meta and go with the entries.
