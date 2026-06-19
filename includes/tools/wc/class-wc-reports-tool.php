<?php
/**
 * wc_reports 工具:销售与热销报表(阶段 1,基于订单聚合)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce 报表工具。
 *
 * 阶段 1 通过在日期区间内聚合订单计算,不依赖 WC Analytics 表。
 * 大型商店区间过大时可能较慢,请用日期参数限定范围。
 */
class WC_Reports_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_reports';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '销售报表:sales=区间内销售额/订单数/客单价; top_products=区间内热销商品 TOP N。默认统计 completed+processing 状态。用 date_after/date_before 限定区间(默认近 7 天)。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'sales', 'top_products' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'      => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'date_after'  => array(
						'type'        => 'string',
						'description' => '起始日期 YYYY-MM-DD,默认 7 天前',
					),
					'date_before' => array(
						'type'        => 'string',
						'description' => '截止日期 YYYY-MM-DD,默认今天',
					),
					'statuses'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => '纳入统计的订单状态,默认 [completed, processing]',
					),
					'limit'       => array(
						'type'        => 'integer',
						'description' => 'top_products 返回数量,默认 10',
						'default'     => 10,
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
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}

		$orders = $this->fetch_orders( $args );

		switch ( $action ) {
			case 'sales':
				return $this->sales( $orders, $args );
			case 'top_products':
				return $this->top_products( $orders, $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 取区间内订单。
	 *
	 * @param array $args 入参。
	 * @return \WC_Order[]
	 */
	private function fetch_orders( $args ) {
		$after  = ! empty( $args['date_after'] ) ? gmdate( 'Y-m-d', strtotime( $args['date_after'] ) ) : gmdate( 'Y-m-d', strtotime( '-7 days' ) );
		$before = ! empty( $args['date_before'] ) ? gmdate( 'Y-m-d', strtotime( $args['date_before'] ) ) : gmdate( 'Y-m-d' );

		$statuses = ! empty( $args['statuses'] ) && is_array( $args['statuses'] )
			? array_map( 'sanitize_text_field', $args['statuses'] )
			: array( 'completed', 'processing' );

		return wc_get_orders(
			array(
				'limit'        => -1,
				'status'       => $statuses,
				'date_created' => $after . '...' . $before,
				'return'       => 'objects',
			)
		);
	}

	/**
	 * 销售汇总。
	 *
	 * @param \WC_Order[] $orders 订单。
	 * @param array       $args   入参。
	 * @return array
	 */
	private function sales( $orders, $args ) {
		$total = 0.0;
		$count = 0;
		$items = 0;
		$currency = get_woocommerce_currency();

		foreach ( $orders as $order ) {
			$total += (float) $order->get_total();
			$items += (int) $order->get_item_count();
			$count++;
		}

		return array(
			'period'      => $this->period_label( $args ),
			'currency'    => $currency,
			'order_count' => $count,
			'gross_sales' => round( $total, 2 ),
			'items_sold'  => $items,
			'avg_order'   => $count ? round( $total / $count, 2 ) : 0,
		);
	}

	/**
	 * 热销商品。
	 *
	 * @param \WC_Order[] $orders 订单。
	 * @param array       $args   入参。
	 * @return array
	 */
	private function top_products( $orders, $args ) {
		$limit = max( 1, (int) ( isset( $args['limit'] ) ? $args['limit'] : 10 ) );
		$agg   = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				$pid = $item->get_product_id();
				if ( ! isset( $agg[ $pid ] ) ) {
					$agg[ $pid ] = array(
						'product_id' => $pid,
						'name'       => $item->get_name(),
						'quantity'   => 0,
						'revenue'    => 0.0,
					);
				}
				$agg[ $pid ]['quantity'] += (int) $item->get_quantity();
				$agg[ $pid ]['revenue']  += (float) $item->get_total();
			}
		}

		usort(
			$agg,
			function ( $a, $b ) {
				return $b['quantity'] <=> $a['quantity'];
			}
		);

		foreach ( $agg as &$row ) {
			$row['revenue'] = round( $row['revenue'], 2 );
		}
		unset( $row );

		return array(
			'period'   => $this->period_label( $args ),
			'currency' => get_woocommerce_currency(),
			'products' => array_slice( $agg, 0, $limit ),
		);
	}

	/**
	 * 区间标签。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function period_label( $args ) {
		return array(
			'after'  => ! empty( $args['date_after'] ) ? gmdate( 'Y-m-d', strtotime( $args['date_after'] ) ) : gmdate( 'Y-m-d', strtotime( '-7 days' ) ),
			'before' => ! empty( $args['date_before'] ) ? gmdate( 'Y-m-d', strtotime( $args['date_before'] ) ) : gmdate( 'Y-m-d' ),
		);
	}
}
