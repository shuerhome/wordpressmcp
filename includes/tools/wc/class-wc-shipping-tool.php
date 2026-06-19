<?php
/**
 * wc_shipping 工具:WooCommerce 配送区域与方式(阶段 4)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce 配送区域工具。
 */
class WC_Shipping_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_shipping';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return 'WooCommerce 配送:list_zones=列出配送区域; get_zone=取区域详情(地区+配送方式); create_zone=建区域; add_method=给区域加配送方式(flat_rate/free_shipping/local_pickup); delete_zone=删区域(需确认)。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list_zones', 'get_zone', 'create_zone', 'add_method', 'delete_zone' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'delete_zone' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'        => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'zone_id'       => array(
						'type'        => 'integer',
						'description' => 'get_zone/add_method/delete_zone 的区域 ID(0=「其余地区」)。',
					),
					'name'          => array(
						'type'        => 'string',
						'description' => 'create_zone 的区域名称。',
					),
					'method_type'   => array(
						'type'        => 'string',
						'description' => 'add_method 的方式类型:flat_rate / free_shipping / local_pickup。',
					),
					'confirm_token' => array(
						'type' => 'string',
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
		if ( ! class_exists( '\WC_Shipping_Zones' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无 WooCommerce 管理权限(manage_woocommerce)。' );
		}

		switch ( $action ) {
			case 'list_zones':
				return $this->list_zones();
			case 'get_zone':
				return $this->get_zone( $args );
			case 'create_zone':
				return $this->create_zone( $args );
			case 'add_method':
				return $this->add_method( $args );
			case 'delete_zone':
				return $this->delete_zone( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出配送区域(含「其余地区」)。
	 *
	 * @return array
	 */
	private function list_zones() {
		$zones = \WC_Shipping_Zones::get_zones();
		$out   = array();
		foreach ( $zones as $zone ) {
			$out[] = array(
				'id'           => (int) $zone['zone_id'],
				'name'         => $zone['zone_name'],
				'order'        => (int) $zone['zone_order'],
				'regions'      => $zone['formatted_zone_location'],
				'method_count' => count( $zone['shipping_methods'] ),
			);
		}

		// 「世界其余地区」区域(ID 0)。
		$rest    = new \WC_Shipping_Zone( 0 );
		$out[]   = array(
			'id'           => 0,
			'name'         => $rest->get_zone_name(),
			'order'        => 0,
			'regions'      => '世界其余地区',
			'method_count' => count( $rest->get_shipping_methods() ),
		);

		return array( 'zones' => $out );
	}

	/**
	 * 取区域详情。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function get_zone( $args ) {
		$zone_id = (int) ( isset( $args['zone_id'] ) ? $args['zone_id'] : 0 );
		$zone    = new \WC_Shipping_Zone( $zone_id );

		$methods = array();
		foreach ( $zone->get_shipping_methods() as $instance_id => $method ) {
			$methods[] = array(
				'instance_id' => (int) $instance_id,
				'id'          => $method->id,
				'title'       => $method->get_title(),
				'enabled'     => ( 'yes' === $method->enabled ),
			);
		}

		$locations = array();
		foreach ( $zone->get_zone_locations() as $loc ) {
			$locations[] = array(
				'type' => $loc->type,
				'code' => $loc->code,
			);
		}

		return array(
			'id'        => $zone_id,
			'name'      => $zone->get_zone_name(),
			'locations' => $locations,
			'methods'   => $methods,
		);
	}

	/**
	 * 创建配送区域。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_zone( $args ) {
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create_zone 需要 name。' );
		}

		$blocked = $this->guard( 'create_zone', $args, '创建配送区域「' . $name . '」' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$zone = new \WC_Shipping_Zone();
		$zone->set_zone_name( $name );
		$id = $zone->save();

		return array( 'created' => true, 'zone_id' => (int) $id, 'name' => $name );
	}

	/**
	 * 给区域添加配送方式。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function add_method( $args ) {
		$zone_id = (int) ( isset( $args['zone_id'] ) ? $args['zone_id'] : 0 );
		$type    = isset( $args['method_type'] ) ? sanitize_key( $args['method_type'] ) : '';
		if ( '' === $type ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'add_method 需要 method_type(flat_rate/free_shipping/local_pickup)。' );
		}

		$blocked = $this->guard( 'add_method', $args, sprintf( '给区域 #%d 添加配送方式 %s', $zone_id, $type ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$zone        = new \WC_Shipping_Zone( $zone_id );
		$instance_id = $zone->add_shipping_method( $type );
		if ( ! $instance_id ) {
			return new \WP_Error( 'wp_mcp_method_failed', '添加配送方式失败(类型可能无效)。' );
		}
		$zone->save();

		return array( 'added' => true, 'zone_id' => $zone_id, 'instance_id' => (int) $instance_id );
	}

	/**
	 * 删除配送区域。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_zone( $args ) {
		$zone_id = (int) ( isset( $args['zone_id'] ) ? $args['zone_id'] : 0 );
		if ( ! $zone_id ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'delete_zone 需要有效 zone_id(不能删「其余地区」)。' );
		}

		$blocked = $this->guard( 'delete_zone', $args, sprintf( '删除配送区域 #%d', $zone_id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = \WC_Shipping_Zones::delete_zone( $zone_id );

		return array( 'deleted' => (bool) $result, 'zone_id' => $zone_id );
	}
}
