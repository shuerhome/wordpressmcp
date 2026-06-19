<?php
/**
 * wc_orders 工具:订单只读访问(阶段 1),默认 PII 脱敏。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\PII;

/**
 * WooCommerce 订单读取工具。
 */
class WC_Orders_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_orders';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '管理 WooCommerce 订单:list=筛选列出; get=取完整订单; set_status=改状态; note=加订单备注; refund=退款(全/部分,需确认,涉及资金); update=改客户备注等。客户 PII 默认脱敏(reveal_pii=true 解锁)。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'set_status', 'note', 'refund', 'update' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'refund' );
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
					'id'          => array(
						'type'        => 'integer',
						'description' => 'get 时的订单 ID',
					),
					'status'      => array(
						'type'        => 'string',
						'description' => 'list 状态:pending/processing/on-hold/completed/cancelled/refunded/failed/any',
						'default'     => 'any',
					),
					'customer'    => array(
						'type'        => 'integer',
						'description' => 'list 按客户用户 ID 过滤',
					),
					'date_after'  => array(
						'type'        => 'string',
						'description' => 'list 起始日期 YYYY-MM-DD',
					),
					'date_before' => array(
						'type'        => 'string',
						'description' => 'list 截止日期 YYYY-MM-DD',
					),
					'search'      => array(
						'type'        => 'string',
						'description' => 'list 关键词搜索',
					),
					'per_page'    => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'        => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'reveal_pii'  => array(
						'type'        => 'boolean',
						'description' => '解锁客户 PII(默认脱敏)',
						'default'     => false,
					),
					'status_to'   => array(
						'type'        => 'string',
						'description' => 'set_status 目标状态:pending/processing/on-hold/completed/cancelled/refunded/failed',
					),
					'note'        => array(
						'type'        => 'string',
						'description' => 'note 备注内容',
					),
					'customer_note' => array(
						'type'        => 'boolean',
						'description' => 'note 是否客户可见(默认 false=内部备注);update 时作为客户备注文本请用 note 字段',
						'default'     => false,
					),
					'refund_amount' => array(
						'type'        => 'string',
						'description' => 'refund 退款金额(留空=全额)',
					),
					'refund_reason' => array(
						'type'        => 'string',
						'description' => 'refund 退款原因',
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
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_orders( $args );
			case 'get':
				return $this->get_order( $args );
			case 'set_status':
				return $this->set_status( $args );
			case 'note':
				return $this->add_note( $args );
			case 'refund':
				return $this->refund( $args );
			case 'update':
				return $this->update_order( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 改订单状态。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_status( $args ) {
		$id    = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到订单 ID:' . $id );
		}
		$to = isset( $args['status_to'] ) ? sanitize_text_field( $args['status_to'] ) : '';
		if ( '' === $to ) {
			return new \WP_Error( 'wp_mcp_bad_status', '缺少 status_to' );
		}

		$blocked = $this->guard( 'set_status', $args, sprintf( '订单 #%d 状态改为 %s', $id, $to ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$order->update_status( $to, isset( $args['note'] ) ? $args['note'] : '', true );
		return array( 'updated' => true, 'id' => $id, 'status' => $order->get_status() );
	}

	/**
	 * 加订单备注。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function add_note( $args ) {
		$id    = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到订单 ID:' . $id );
		}
		$note = isset( $args['note'] ) ? (string) $args['note'] : '';
		if ( '' === $note ) {
			return new \WP_Error( 'wp_mcp_bad_note', '缺少 note 内容' );
		}

		$blocked = $this->guard( 'note', $args, sprintf( '为订单 #%d 添加%s备注', $id, ! empty( $args['customer_note'] ) ? '客户可见' : '内部' ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$note_id = $order->add_order_note( $note, ! empty( $args['customer_note'] ) ? 1 : 0, false );
		return array( 'added' => true, 'note_id' => $note_id );
	}

	/**
	 * 退款(全额或部分)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function refund( $args ) {
		$id    = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到订单 ID:' . $id );
		}

		$amount = isset( $args['refund_amount'] ) && '' !== $args['refund_amount']
			? (string) $args['refund_amount']
			: (string) $order->get_remaining_refund_amount();

		$summary = sprintf( '对订单 #%d 退款 %s %s(原因:%s)', $id, $amount, $order->get_currency(), isset( $args['refund_reason'] ) ? $args['refund_reason'] : '无' );
		$blocked = $this->guard( 'refund', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$refund = wc_create_refund(
			array(
				'order_id' => $id,
				'amount'   => $amount,
				'reason'   => isset( $args['refund_reason'] ) ? sanitize_text_field( $args['refund_reason'] ) : '',
			)
		);

		if ( is_wp_error( $refund ) ) {
			return $refund;
		}

		return array(
			'refunded'  => true,
			'order_id'  => $id,
			'refund_id' => $refund->get_id(),
			'amount'    => $amount,
		);
	}

	/**
	 * 更新订单(当前支持客户备注)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_order( $args ) {
		$id    = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到订单 ID:' . $id );
		}

		$blocked = $this->guard( 'update', $args, '更新订单 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( isset( $args['note'] ) ) {
			$order->set_customer_note( (string) $args['note'] );
		}
		$order->save();

		return array( 'updated' => true, 'id' => $id );
	}

	/**
	 * 列出订单。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_orders( $args ) {
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$mask     = PII::should_mask( $args );

		$query = array(
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => 'date',
			'order'    => 'DESC',
		);

		if ( ! empty( $args['status'] ) && 'any' !== $args['status'] ) {
			$query['status'] = sanitize_text_field( $args['status'] );
		}
		if ( ! empty( $args['customer'] ) ) {
			$query['customer_id'] = (int) $args['customer'];
		}
		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}
		$date_query = $this->build_date_query( $args );
		if ( $date_query ) {
			$query['date_created'] = $date_query;
		}

		$results = wc_get_orders( $query );
		$items   = array();
		foreach ( $results->orders as $order ) {
			$items[] = $this->summarize_order( $order, $mask );
		}

		return array(
			'items'        => $items,
			'page'         => $page,
			'per_page'     => $per_page,
			'total'        => (int) $results->total,
			'total_pages'  => (int) $results->max_num_pages,
			'pii_masked'   => $mask,
		);
	}

	/**
	 * 取单个订单完整信息。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_order( $args ) {
		$id    = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$order = $id ? wc_get_order( $id ) : null;
		if ( ! $order ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到订单 ID:' . $id );
		}

		$mask = PII::should_mask( $args );
		$data = $this->summarize_order( $order, $mask );

		// 订单明细。
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$items[] = array(
				'name'       => $item->get_name(),
				'product_id' => $item->get_product_id(),
				'quantity'   => $item->get_quantity(),
				'subtotal'   => $item->get_subtotal(),
				'total'      => $item->get_total(),
			);
		}
		$data['line_items'] = $items;

		// 金额构成。
		$data['totals'] = array(
			'subtotal'       => $order->get_subtotal(),
			'shipping_total' => $order->get_shipping_total(),
			'discount_total' => $order->get_discount_total(),
			'tax_total'      => $order->get_total_tax(),
			'total'          => $order->get_total(),
		);

		// 地址(脱敏)。
		$billing  = $order->get_address( 'billing' );
		$shipping = $order->get_address( 'shipping' );
		$data['billing']  = $mask ? PII::address( $billing ) : $billing;
		$data['shipping'] = $mask ? PII::address( $shipping ) : $shipping;

		// 客户备注。
		$data['customer_note'] = $order->get_customer_note();

		return $data;
	}

	/**
	 * 订单摘要。
	 *
	 * @param \WC_Order $order 订单。
	 * @param bool      $mask  是否脱敏。
	 * @return array
	 */
	private function summarize_order( $order, $mask ) {
		$created = $order->get_date_created();
		$name    = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
		$email   = $order->get_billing_email();
		$phone   = $order->get_billing_phone();

		return array(
			'id'             => $order->get_id(),
			'number'         => $order->get_order_number(),
			'status'         => $order->get_status(),
			'currency'       => $order->get_currency(),
			'total'          => $order->get_total(),
			'date_created'   => $created ? $created->date( 'c' ) : null,
			'customer_id'    => $order->get_customer_id(),
			'item_count'     => $order->get_item_count(),
			'payment_method' => $order->get_payment_method_title(),
			'customer_name'  => $mask ? PII::name( $name ) : $name,
			'customer_email' => $mask ? PII::email( $email ) : $email,
			'customer_phone' => $mask ? PII::phone( $phone ) : $phone,
		);
	}

	/**
	 * 构造 wc_get_orders 的 date_created 查询。
	 *
	 * @param array $args 入参。
	 * @return string
	 */
	private function build_date_query( $args ) {
		$after  = ! empty( $args['date_after'] ) ? gmdate( 'Y-m-d', strtotime( $args['date_after'] ) ) : '';
		$before = ! empty( $args['date_before'] ) ? gmdate( 'Y-m-d', strtotime( $args['date_before'] ) ) : '';

		if ( $after && $before ) {
			return $after . '...' . $before;
		}
		if ( $after ) {
			return '>=' . $after;
		}
		if ( $before ) {
			return '<=' . $before;
		}
		return '';
	}
}
