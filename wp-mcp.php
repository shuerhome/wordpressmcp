<?php
/**
 * Plugin Name:       WP MCP — WordPress/WooCommerce MCP Server
 * Plugin URI:        https://github.com/shuerhome/wordpressmcp
 * Description:        把本站变成一个 MCP 服务器,让 Claude 通过站点 URL 精细控制 WordPress / WooCommerce。
 * Version:           0.2.1
 * Requires at least: 5.9
 * Requires PHP:      7.4
 * Author:            shuerhome
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-mcp
 *
 * @package WPMCP
 */

defined( 'ABSPATH' ) || exit;

/*
 * 防御:避免同一插件被装成两份(不同目录)而触发致命错误、整站白屏。
 *
 * 常见诱因:从 GitHub 下载的压缩包解压后目录名是 `wordpressmcp-main` / `wordpressmcp-0.2.0`,
 * 而安装说明又建议把目录改名为 `wp-mcp`;手动安装叠加内置自动更新器时,很容易出现「旧目录
 * 没删、新目录又装了一份」的情况。两份都会被 WordPress 当作独立插件加载,于是 `wp_mcp()`
 * 函数与各类被重复声明,PHP 抛出「Cannot redeclare …」致命错误,导致前台与后台一起白屏、
 * 连「插件」页都进不去,只能用 FTP/文件管理器手动删目录才能恢复。
 *
 * 这里检测到已有一份在运行就安全退出(WordPress 会照常使用先加载的那一份),并在后台给出
 * 可操作的提示,把「致命错误」降级为「一条通知」。
 */
if ( defined( 'WP_MCP_VERSION' ) ) {
	if ( ! function_exists( 'wp_mcp_duplicate_admin_notice' ) ) {
		/**
		 * 后台提示:存在重复安装的 WP MCP,请只保留一份。
		 */
		function wp_mcp_duplicate_admin_notice() {
			if ( ! current_user_can( 'activate_plugins' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p><strong>WP MCP:</strong> ';
			echo esc_html__( '检测到本插件被安装了多份(多个目录)。已自动只启用其中一份以避免致命错误,请到「插件」页停用并删除多余的副本,只保留一个。', 'wp-mcp' );
			echo '</p></div>';
		}
		add_action( 'admin_notices', 'wp_mcp_duplicate_admin_notice' );
		add_action( 'network_admin_notices', 'wp_mcp_duplicate_admin_notice' );
	}
	return;
}

define( 'WP_MCP_VERSION', '0.2.1' );
define( 'WP_MCP_FILE', __FILE__ );
define( 'WP_MCP_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_URL', plugin_dir_url( __FILE__ ) );
define( 'WP_MCP_REST_NAMESPACE', 'mcp/v1' );

require_once WP_MCP_DIR . 'includes/class-plugin.php';

register_activation_hook( __FILE__, array( '\WPMCP\Plugin', 'on_activation' ) );
register_deactivation_hook( __FILE__, array( '\WPMCP\Plugin', 'on_deactivation' ) );

if ( ! function_exists( 'wp_mcp' ) ) {
	/**
	 * 启动插件。
	 */
	function wp_mcp() {
		return \WPMCP\Plugin::instance();
	}
}

wp_mcp();
