<?php
/**
 * 插件引导:加载依赖、注册钩子。
 *
 * @package WPMCP
 */

namespace WPMCP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Mcp\Transport;
use WPMCP\Mcp\Registry;
use WPMCP\Admin\Settings;
use WPMCP\Update\Updater;

/**
 * 主引导类(单例)。
 */
final class Plugin {

	/**
	 * 单例。
	 *
	 * @var Plugin|null
	 */
	private static $instance = null;

	/**
	 * 工具注册表。
	 *
	 * @var Registry
	 */
	public $registry;

	/**
	 * 获取单例。
	 *
	 * @return Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * 构造:加载文件、挂钩子。
	 */
	private function __construct() {
		$this->load_files();
		$this->registry = new Registry();

		// 注册 MCP 端点(REST 路由)。
		add_action( 'rest_api_init', array( new Transport( $this->registry ), 'register_routes' ) );

		// 私有更新(GitHub Releases)。
		$repo = defined( 'WP_MCP_UPDATE_REPO' ) ? WP_MCP_UPDATE_REPO : 'shuerhome/wordpressmcp';
		( new Updater( $repo, WP_MCP_FILE, WP_MCP_VERSION ) )->register();

		// 后台设置页。
		if ( is_admin() ) {
			$settings = new Settings();
			add_action( 'admin_menu', array( $settings, 'register_menu' ) );
			add_action( 'admin_init', array( $settings, 'handle_actions' ) );
		}
	}

	/**
	 * 按依赖顺序加载文件(抽象基类先于其子类)。
	 */
	private function load_files() {
		$files = array(
			'includes/capability/class-detector.php',
			'includes/safety/class-audit.php',
			'includes/safety/class-pii.php',
			'includes/safety/class-guard.php',
			'includes/safety/class-backup.php',
			'includes/mcp/class-auth.php',
			'includes/mcp/class-server.php',
			'includes/mcp/class-transport.php',
			'includes/update/class-updater.php',
			'includes/tools/abstract-tool.php',
			'includes/tools/wp/class-wp-site-tool.php',
			'includes/tools/wp/class-wp-content-tool.php',
			'includes/tools/wp/class-wp-media-tool.php',
			'includes/tools/wp/class-wp-blocks-tool.php',
			'includes/tools/wp/class-wp-elementor-tool.php',
			'includes/tools/wp/class-wp-design-tool.php',
			'includes/tools/wp/class-wp-theme-tool.php',
			'includes/tools/wp/class-wp-taxonomy-tool.php',
			'includes/tools/wp/class-wp-comments-tool.php',
			'includes/tools/wp/class-wp-users-tool.php',
			'includes/tools/wp/class-wp-settings-tool.php',
			'includes/tools/wp/class-wp-plugins-tool.php',
			'includes/tools/wp/class-wp-menus-tool.php',
			'includes/tools/wp/class-wp-widgets-tool.php',
			'includes/tools/wp/class-wp-system-tool.php',
			'includes/tools/wc/class-wc-products-tool.php',
			'includes/tools/wc/class-wc-orders-tool.php',
			'includes/tools/wc/class-wc-reports-tool.php',
			'includes/tools/wc/class-wc-inventory-tool.php',
			'includes/tools/wc/class-wc-customers-tool.php',
			'includes/tools/wc/class-wc-coupons-tool.php',
			'includes/tools/wc/class-wc-settings-tool.php',
			'includes/tools/wc/class-wc-shipping-tool.php',
			'includes/tools/wc/class-wc-webhooks-tool.php',
			'includes/mcp/class-registry.php',
			'includes/admin/class-settings.php',
		);

		foreach ( $files as $file ) {
			require_once WP_MCP_DIR . $file;
		}
	}

	/**
	 * 激活:写入默认选项、把当前管理员绑为 MCP 用户。
	 */
	public static function on_activation() {
		add_option( 'wp_mcp_enabled', 1 );
		add_option( 'wp_mcp_require_confirm', 1 );
		add_option( 'wp_mcp_mask_pii', 1 );
		add_option( 'wp_mcp_audit', 1 );
		add_option( 'wp_mcp_ip_allowlist', '' );

		// 默认绑定到当前(激活者)用户,前提是其为管理员。
		if ( ! get_option( 'wp_mcp_bound_user' ) ) {
			$user = wp_get_current_user();
			if ( $user && $user->exists() && user_can( $user, 'manage_options' ) ) {
				add_option( 'wp_mcp_bound_user', $user->ID );
			}
		}
	}

	/**
	 * 停用:无需清理(保留令牌与设置)。
	 */
	public static function on_deactivation() {}
}
