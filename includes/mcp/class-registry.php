<?php
/**
 * 工具/资源/提示词注册表。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Tools\WP\WP_Site_Tool;
use WPMCP\Tools\WP\WP_Content_Tool;
use WPMCP\Tools\WP\WP_Media_Tool;
use WPMCP\Tools\WP\WP_Blocks_Tool;
use WPMCP\Tools\WP\WP_Elementor_Tool;
use WPMCP\Tools\WP\WP_Design_Tool;
use WPMCP\Tools\WP\WP_Theme_Tool;
use WPMCP\Tools\WP\WP_Taxonomy_Tool;
use WPMCP\Tools\WP\WP_Comments_Tool;
use WPMCP\Tools\WP\WP_Users_Tool;
use WPMCP\Tools\WP\WP_Settings_Tool;
use WPMCP\Tools\WP\WP_Plugins_Tool;
use WPMCP\Tools\WP\WP_Menus_Tool;
use WPMCP\Tools\WP\WP_Widgets_Tool;
use WPMCP\Tools\WP\WP_System_Tool;
use WPMCP\Tools\WC\WC_Products_Tool;
use WPMCP\Tools\WC\WC_Orders_Tool;
use WPMCP\Tools\WC\WC_Reports_Tool;
use WPMCP\Tools\WC\WC_Inventory_Tool;
use WPMCP\Tools\WC\WC_Customers_Tool;
use WPMCP\Tools\WC\WC_Coupons_Tool;
use WPMCP\Tools\WC\WC_Settings_Tool;
use WPMCP\Tools\WC\WC_Shipping_Tool;
use WPMCP\Tools\WC\WC_Webhooks_Tool;

/**
 * 维护可用工具集合,经能力探测过滤后对外暴露。
 */
class Registry {

	/**
	 * 已注册工具:name => Abstract_Tool。
	 *
	 * @var Abstract_Tool[]
	 */
	private $tools = array();

	/**
	 * 构造:注册阶段 0 工具。
	 */
	public function __construct() {
		// WordPress 工具(始终可用)。
		$this->register( new WP_Site_Tool() );
		$this->register( new WP_Content_Tool() );
		$this->register( new WP_Media_Tool() );
		$this->register( new WP_Blocks_Tool() );
		$this->register( new WP_Design_Tool() );
		$this->register( new WP_Theme_Tool() );
		$this->register( new WP_Taxonomy_Tool() );
		$this->register( new WP_Comments_Tool() );
		$this->register( new WP_Users_Tool() );
		$this->register( new WP_Settings_Tool() );
		$this->register( new WP_Plugins_Tool() );
		$this->register( new WP_Menus_Tool() );
		$this->register( new WP_Widgets_Tool() );
		$this->register( new WP_System_Tool() );

		// Elementor 工具(仅在 Elementor 启用时暴露)。
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			$this->register( new WP_Elementor_Tool() );
		}

		// WooCommerce 工具(仅在 WC 启用时暴露)。
		if ( class_exists( 'WooCommerce' ) ) {
			$this->register( new WC_Products_Tool() );
			$this->register( new WC_Orders_Tool() );
			$this->register( new WC_Reports_Tool() );
			$this->register( new WC_Inventory_Tool() );
			$this->register( new WC_Customers_Tool() );
			$this->register( new WC_Coupons_Tool() );
			$this->register( new WC_Settings_Tool() );
			$this->register( new WC_Shipping_Tool() );
			$this->register( new WC_Webhooks_Tool() );
		}

		/**
		 * 允许其他模块注册工具。
		 *
		 * @param Registry $registry 注册表实例。
		 */
		do_action( 'wp_mcp_register_tools', $this );
	}

	/**
	 * 注册一个工具。
	 *
	 * @param Abstract_Tool $tool 工具。
	 */
	public function register( Abstract_Tool $tool ) {
		$this->tools[ $tool->get_name() ] = $tool;
	}

	/**
	 * 获取一个工具。
	 *
	 * @param string $name 工具名。
	 * @return Abstract_Tool|null
	 */
	public function get( $name ) {
		return isset( $this->tools[ $name ] ) ? $this->tools[ $name ] : null;
	}

	/**
	 * 生成 MCP tools/list 结构。
	 *
	 * @return array
	 */
	public function list_for_mcp() {
		$out = array();
		foreach ( $this->tools as $tool ) {
			$out[] = array(
				'name'        => $tool->get_name(),
				'description' => $tool->get_description(),
				'inputSchema' => $tool->get_input_schema(),
			);
		}
		return $out;
	}
}
