<?php
/**
 * Plugin Name:       Shakewell Connector for Klaviyo and Gravity Forms
 * Plugin URI:        https://github.com/shakewellagency/shakewell-klaviyo-connector
 * Description:       Adds people who submit selected Gravity Forms forms to a Klaviyo list, with their consent. Off by default.
 * Version:           0.1.1
 * Requires at least: 6.5
 * Requires PHP:      8.1
 * Author:            Shakewell
 * Author URI:        https://shakewell.agency
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       shakewell-klaviyo-connector
 *
 * @package Shakewell\KlaviyoConnector
 */

namespace Shakewell\KlaviyoConnector;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.1';
const FILE    = __FILE__;

require_once __DIR__ . '/src/Plugin.php';

add_action( 'plugins_loaded', array( Plugin::class, 'boot' ) );
