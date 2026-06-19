<?php
/**
 * 能力探测:识别站点环境,决定暴露哪些工具。
 *
 * @package WPMCP
 */

namespace WPMCP\Capability;

defined( 'ABSPATH' ) || exit;

/**
 * 探测 WordPress / WooCommerce / 主题 / 关键插件。
 */
class Detector {

	/**
	 * 返回完整的能力快照。
	 *
	 * @return array
	 */
	public static function snapshot() {
		return array(
			'wordpress'   => self::wordpress(),
			'woocommerce' => self::woocommerce(),
			'theme'       => self::theme(),
			'plugins'     => self::notable_plugins(),
		);
	}

	/**
	 * WordPress 基本信息。
	 *
	 * @return array
	 */
	public static function wordpress() {
		return array(
			'version'    => get_bloginfo( 'version' ),
			'php'        => PHP_VERSION,
			'multisite'  => is_multisite(),
			'language'   => get_locale(),
			'timezone'   => wp_timezone_string(),
			'is_ssl'     => is_ssl(),
		);
	}

	/**
	 * WooCommerce 信息(未安装则 active=false)。
	 *
	 * @return array
	 */
	public static function woocommerce() {
		$active = class_exists( 'WooCommerce' );
		$data   = array( 'active' => $active );

		if ( $active ) {
			$data['version'] = defined( 'WC_VERSION' ) ? WC_VERSION : null;
			$data['hpos']    = self::hpos_enabled();
		}

		return $data;
	}

	/**
	 * 是否启用 HPOS(高性能订单存储)。
	 *
	 * @return bool|null
	 */
	private static function hpos_enabled() {
		$class = '\Automattic\WooCommerce\Utilities\OrderUtil';
		if ( class_exists( $class ) && method_exists( $class, 'custom_orders_table_usage_is_enabled' ) ) {
			return (bool) $class::custom_orders_table_usage_is_enabled();
		}
		return null;
	}

	/**
	 * 当前主题信息,含是否 FSE 块主题。
	 *
	 * @return array
	 */
	public static function theme() {
		$theme       = wp_get_theme();
		$is_block    = function_exists( 'wp_is_block_theme' ) ? wp_is_block_theme() : false;

		return array(
			'name'          => $theme->get( 'Name' ),
			'stylesheet'    => get_stylesheet(),
			'version'       => $theme->get( 'Version' ),
			'is_block_theme' => (bool) $is_block,
			'type'          => $is_block ? 'fse' : 'classic',
		);
	}

	/**
	 * 探测若干关键插件是否启用(用于桥接工具)。
	 *
	 * @return array
	 */
	public static function notable_plugins() {
		return array(
			'elementor' => defined( 'ELEMENTOR_VERSION' ),
			'acf'       => class_exists( 'ACF' ),
			'cartflows' => defined( 'CARTFLOWS_VER' ),
		);
	}

	/**
	 * 返回当前应启用的工具组键名列表。
	 *
	 * @return string[]
	 */
	public static function enabled_tool_groups() {
		$groups = array( 'wp' );
		if ( class_exists( 'WooCommerce' ) ) {
			$groups[] = 'wc';
		}
		return $groups;
	}
}
