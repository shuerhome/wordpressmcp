<?php
/**
 * Plugin Name:       WP MCP — WordPress/WooCommerce MCP Server
 * Plugin URI:        https://github.com/shuerhome/wordpressmcp
 * Description:        把本站变成一个 MCP 服务器,让 Claude 通过站点 URL 精细控制 WordPress / WooCommerce。
 * Version:           0.1.1
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            shuerhome
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-mcp
 *
 * @package WPMCP
 */

defined( 'ABSPATH' ) || exit;

define( 'WP_MCP_VERSION', '0.1.1' );
define( 'WP_MCP_FILE', __FILE__ );
define( 'WP_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_MCP_REST_NAMESPACE', 'mcp/v1' );

require_once WP_MCP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( '\WPMCP\Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( '\WPMCP\Plugin', 'on_deactivation' ) );

/**
 * 启动插件。
 */
function wp_mcp() {
	return \WPMCP\Plugin::instance();
}

wp_mcp();
