<?php
/**
 * wc_inventory 工具:库存查询与设置。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce 库存工具。
 */
class WC_Inventory_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_inventory';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '库存:get=查单个商品/变体库存; set_stock=设置某商品库存数量/状态; bulk_set=批量设置多个商品库存; low_stock=列出低于阈值的商品。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'get', 'set_stock', 'bulk_set', 'low_stock' );
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
					'id'            => array(
						'type'        => 'integer',
						'description' => 'get/set_stock 的商品或变体 ID',
					),
					'quantity'      => array(
						'type'        => 'integer',
						'description' => 'set_stock 的库存数量',
					),
					'stock_status'  => array(
						'type'        => 'string',
						'description' => 'set_stock 的库存状态:instock/outofstock/onbackorder',
					),
					'manage_stock'  => array(
						'type'        => 'boolean',
						'description' => 'set_stock 是否启用库存管理',
					),
					'items'         => array(
						'type'        => 'array',
						'description' => 'bulk_set:[{id, quantity}] 列表',
						'items'       => array(
							'type'       => 'object',
							'properties' => array(
								'id'       => array( 'type' => 'integer' ),
								'quantity' => array( 'type' => 'integer' ),
							),
						),
					),
					'threshold'     => array(
						'type'        => 'integer',
						'description' => 'low_stock 阈值,默认取 WC 设置',
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
		if ( ! function_exists( 'wc_get_product' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}

		switch ( $action ) {
			case 'get':
				return $this->get_stock( $args );
			case 'set_stock':
				return $this->set_stock( $args );
			case 'bulk_set':
				return $this->bulk_set( $args );
			case 'low_stock':
				return $this->low_stock( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 查库存。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_stock( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到商品 ID:' . $id );
		}
		return $this->stock_info( $product );
	}

	/**
	 * 设置单个商品库存。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_stock( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到商品 ID:' . $id );
		}

		$summary = sprintf(
			'设置商品 #%d 库存:数量=%s 状态=%s',
			$id,
			isset( $args['quantity'] ) ? $args['quantity'] : '(不变)',
			isset( $args['stock_status'] ) ? $args['stock_status'] : '(不变)'
		);
		$blocked = $this->guard( 'set_stock', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$this->apply_stock( $product, $args );
		$product->save();

		return array( 'updated' => true, 'stock' => $this->stock_info( $product ) );
	}

	/**
	 * 批量设置库存。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function bulk_set( $args ) {
		$items = isset( $args['items'] ) && is_array( $args['items'] ) ? $args['items'] : array();
		if ( empty( $items ) ) {
			return new \WP_Error( 'wp_mcp_no_items', '缺少 items' );
		}

		$summary = sprintf( '批量设置 %d 个商品的库存', count( $items ) );
		$blocked = $this->guard( 'bulk_set', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$results = array();
		foreach ( $items as $item ) {
			$id      = isset( $item['id'] ) ? (int) $item['id'] : 0;
			$product = $id ? wc_get_product( $id ) : null;
			if ( ! $product ) {
				$results[] = array( 'id' => $id, 'ok' => false, 'error' => 'not_found' );
				continue;
			}
			$this->apply_stock( $product, array( 'quantity' => isset( $item['quantity'] ) ? (int) $item['quantity'] : 0, 'manage_stock' => true ) );
			$product->save();
			$results[] = array( 'id' => $id, 'ok' => true, 'quantity' => $product->get_stock_quantity() );
		}

		return array( 'results' => $results );
	}

	/**
	 * 低库存列表。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function low_stock( $args ) {
		$threshold = isset( $args['threshold'] )
			? (int) $args['threshold']
			: (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );

		$results = wc_get_products(
			array(
				'limit'        => 100,
				'stock_status' => '', // 全部。
				'manage_stock' => true,
				'return'       => 'objects',
			)
		);

		$out = array();
		foreach ( $results as $product ) {
			$qty = $product->get_stock_quantity();
			if ( null !== $qty && $qty <= $threshold ) {
				$out[] = array(
					'id'             => $product->get_id(),
					'name'           => $product->get_name(),
					'sku'            => $product->get_sku(),
					'stock_quantity' => $qty,
				);
			}
		}

		return array( 'threshold' => $threshold, 'low_stock' => $out, 'count' => count( $out ) );
	}

	/**
	 * 把库存入参写入商品对象。
	 *
	 * @param \WC_Product $product 商品。
	 * @param array       $args    入参。
	 */
	private function apply_stock( $product, $args ) {
		if ( isset( $args['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $args['manage_stock'] );
		}
		if ( isset( $args['quantity'] ) ) {
			$product->set_manage_stock( true );
			$product->set_stock_quantity( (int) $args['quantity'] );
		}
		if ( isset( $args['stock_status'] ) ) {
			$product->set_stock_status( sanitize_key( $args['stock_status'] ) );
		}
	}

	/**
	 * 库存信息。
	 *
	 * @param \WC_Product $product 商品。
	 * @return array
	 */
	private function stock_info( $product ) {
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'manage_stock'   => $product->get_manage_stock(),
			'stock_quantity' => $product->get_stock_quantity(),
			'stock_status'   => $product->get_stock_status(),
		);
	}
}
