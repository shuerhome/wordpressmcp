<?php
/**
 * wp_site 工具:站点信息、能力探测、健康检查。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Capability\Detector;

/**
 * 站点级只读/维护工具。
 */
class WP_Site_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_site';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '站点级信息与维护:获取站点基本信息(info)、能力探测(capabilities)、健康检查(health)、清理缓存(flush_cache)。所有数据均为只读,flush_cache 仅清理缓存不改内容。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'info', 'capabilities', 'health', 'flush_cache' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action' => array(
						'type'        => 'string',
						'enum'        => $this->get_actions(),
						'description' => 'info=站点信息; capabilities=能力探测; health=健康检查; flush_cache=清缓存',
					),
				),
				$this->common_properties()
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( $action, $args ) {
		switch ( $action ) {
			case 'info':
				return $this->info();
			case 'capabilities':
				return Detector::snapshot();
			case 'health':
				return $this->health();
			case 'flush_cache':
				return $this->flush_cache( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 站点基本信息。
	 *
	 * @return array
	 */
	private function info() {
		$wp = Detector::wordpress();

		return array(
			'name'        => get_bloginfo( 'name' ),
			'description' => get_bloginfo( 'description' ),
			'url'         => home_url(),
			'admin_url'   => admin_url(),
			'wordpress'   => $wp,
			'theme'       => Detector::theme(),
			'woocommerce' => Detector::woocommerce(),
			'plugin'      => array(
				'name'    => 'WP MCP',
				'version' => WP_MCP_VERSION,
			),
		);
	}

	/**
	 * 基础健康检查。
	 *
	 * @return array
	 */
	private function health() {
		$checks = array();

		$checks[] = array(
			'name'   => 'php_version',
			'status' => version_compare( PHP_VERSION, '7.4', '>=' ) ? 'ok' : 'warning',
			'value'  => PHP_VERSION,
		);

		$checks[] = array(
			'name'   => 'https',
			'status' => is_ssl() ? 'ok' : 'warning',
			'value'  => is_ssl() ? '已启用' : '未启用(生产环境建议启用)',
		);

		$checks[] = array(
			'name'   => 'wp_version',
			'status' => version_compare( get_bloginfo( 'version' ), '5.9', '>=' ) ? 'ok' : 'warning',
			'value'  => get_bloginfo( 'version' ),
		);

		if ( class_exists( 'WooCommerce' ) ) {
			$checks[] = array(
				'name'   => 'woocommerce',
				'status' => 'ok',
				'value'  => defined( 'WC_VERSION' ) ? WC_VERSION : 'active',
			);
		}

		$overall = 'ok';
		foreach ( $checks as $c ) {
			if ( 'ok' !== $c['status'] ) {
				$overall = 'warning';
			}
		}

		return array(
			'overall' => $overall,
			'checks'  => $checks,
		);
	}

	/**
	 * 清理缓存。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function flush_cache( $args ) {
		if ( $this->is_dry_run( $args ) ) {
			return array( 'dry_run' => true, 'would' => '清理对象缓存与已知缓存插件缓存' );
		}

		wp_cache_flush();

		// 兼容常见缓存插件。
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'w3tc_flush_all' ) ) {
			w3tc_flush_all();
		}
		if ( function_exists( 'wp_cache_clear_cache' ) ) {
			wp_cache_clear_cache();
		}

		return array( 'flushed' => true );
	}
}
