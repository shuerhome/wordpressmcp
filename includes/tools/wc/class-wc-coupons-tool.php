<?php
/**
 * wc_coupons 工具:WooCommerce 优惠券管理(阶段 4)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce 优惠券工具。
 */
class WC_Coupons_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_coupons';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '管理 WooCommerce 优惠券:list=列出; get=取详情; create=创建; update=更新; delete=删除(需确认)。支持折扣类型(percent/fixed_cart/fixed_product)、金额、有效期、最低/最高消费、使用次数、免运费、适用商品。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'create', 'update', 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'         => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'id'             => array(
						'type'        => 'integer',
						'description' => 'get/update/delete 的优惠券 ID。',
					),
					'code'           => array(
						'type'        => 'string',
						'description' => 'create 的优惠码;get 也可用 code 查询。',
					),
					'discount_type'  => array(
						'type'        => 'string',
						'description' => 'create/update 折扣类型:percent / fixed_cart / fixed_product。',
					),
					'amount'         => array(
						'type'        => 'string',
						'description' => 'create/update 折扣值(百分比填数字如 10)。',
					),
					'description'    => array(
						'type'        => 'string',
						'description' => 'create/update 描述。',
					),
					'free_shipping'  => array(
						'type'        => 'boolean',
						'description' => 'create/update 是否含免运费。',
					),
					'expiry_date'    => array(
						'type'        => 'string',
						'description' => 'create/update 到期日 YYYY-MM-DD。',
					),
					'minimum_amount' => array(
						'type'        => 'string',
						'description' => 'create/update 最低消费。',
					),
					'maximum_amount' => array(
						'type'        => 'string',
						'description' => 'create/update 最高消费。',
					),
					'usage_limit'    => array(
						'type'        => 'integer',
						'description' => 'create/update 总使用次数上限。',
					),
					'individual_use' => array(
						'type'        => 'boolean',
						'description' => 'create/update 是否不可与其他券叠加。',
					),
					'product_ids'    => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'integer' ),
						'description' => 'create/update 适用商品 ID。',
					),
					'per_page'       => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'           => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'confirm_token'  => array(
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
		if ( ! class_exists( '\WC_Coupon' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_coupons( $args );
			case 'get':
				return $this->get_coupon( $args );
			case 'create':
				return $this->create_coupon( $args );
			case 'update':
				return $this->update_coupon( $args );
			case 'delete':
				return $this->delete_coupon( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出优惠券。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_coupons( $args ) {
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$query = new \WP_Query(
			array(
				'post_type'      => 'shop_coupon',
				'post_status'    => 'publish',
				'posts_per_page' => $per_page,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->summarize_coupon( new \WC_Coupon( $post->ID ) );
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * 取单个优惠券。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_coupon( $args ) {
		$coupon = $this->load_coupon( $args );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		$data                   = $this->summarize_coupon( $coupon );
		$data['description']    = $coupon->get_description();
		$data['minimum_amount'] = $coupon->get_minimum_amount();
		$data['maximum_amount'] = $coupon->get_maximum_amount();
		$data['individual_use'] = $coupon->get_individual_use();
		$data['product_ids']    = $coupon->get_product_ids();
		return $data;
	}

	/**
	 * 创建优惠券。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_coupon( $args ) {
		$code = isset( $args['code'] ) ? wc_format_coupon_code( $args['code'] ) : '';
		if ( '' === $code ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create 需要 code。' );
		}
		if ( wc_get_coupon_id_by_code( $code ) ) {
			return new \WP_Error( 'wp_mcp_exists', '优惠码已存在:' . $code );
		}

		$blocked = $this->guard( 'create', $args, '创建优惠券「' . $code . '」' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$coupon = new \WC_Coupon();
		$coupon->set_code( $code );
		$this->apply_props( $coupon, $args );
		$id = $coupon->save();

		if ( ! $id ) {
			return new \WP_Error( 'wp_mcp_create_failed', '创建失败。' );
		}

		return array( 'created' => true, 'coupon' => $this->get_coupon( array( 'id' => $id ) ) );
	}

	/**
	 * 更新优惠券。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_coupon( $args ) {
		$coupon = $this->load_coupon( $args );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		$blocked = $this->guard( 'update', $args, '更新优惠券 #' . $coupon->get_id() );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$this->apply_props( $coupon, $args );
		$id = $coupon->save();

		return array( 'updated' => true, 'coupon' => $this->get_coupon( array( 'id' => $id ) ) );
	}

	/**
	 * 删除优惠券。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_coupon( $args ) {
		$coupon = $this->load_coupon( $args );
		if ( is_wp_error( $coupon ) ) {
			return $coupon;
		}

		$id      = $coupon->get_id();
		$blocked = $this->guard( 'delete', $args, sprintf( '永久删除优惠券 #%d「%s」', $id, $coupon->get_code() ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = $coupon->delete( true );
		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 按 id 或 code 载入优惠券。
	 *
	 * @param array $args 入参。
	 * @return \WC_Coupon|\WP_Error
	 */
	private function load_coupon( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id && ! empty( $args['code'] ) ) {
			$id = (int) wc_get_coupon_id_by_code( wc_format_coupon_code( $args['code'] ) );
		}
		if ( ! $id || 'shop_coupon' !== get_post_type( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到优惠券(id 或 code)。' );
		}
		return new \WC_Coupon( $id );
	}

	/**
	 * 把入参写入优惠券对象。
	 *
	 * @param \WC_Coupon $coupon 优惠券。
	 * @param array      $args   入参。
	 */
	private function apply_props( $coupon, $args ) {
		if ( isset( $args['discount_type'] ) ) {
			$coupon->set_discount_type( sanitize_text_field( $args['discount_type'] ) );
		}
		if ( isset( $args['amount'] ) ) {
			$coupon->set_amount( wc_format_decimal( $args['amount'] ) );
		}
		if ( isset( $args['description'] ) ) {
			$coupon->set_description( sanitize_text_field( $args['description'] ) );
		}
		if ( isset( $args['free_shipping'] ) ) {
			$coupon->set_free_shipping( (bool) $args['free_shipping'] );
		}
		if ( isset( $args['expiry_date'] ) ) {
			$coupon->set_date_expires( $args['expiry_date'] ? strtotime( $args['expiry_date'] ) : null );
		}
		if ( isset( $args['minimum_amount'] ) ) {
			$coupon->set_minimum_amount( wc_format_decimal( $args['minimum_amount'] ) );
		}
		if ( isset( $args['maximum_amount'] ) ) {
			$coupon->set_maximum_amount( wc_format_decimal( $args['maximum_amount'] ) );
		}
		if ( isset( $args['usage_limit'] ) ) {
			$coupon->set_usage_limit( (int) $args['usage_limit'] );
		}
		if ( isset( $args['individual_use'] ) ) {
			$coupon->set_individual_use( (bool) $args['individual_use'] );
		}
		if ( isset( $args['product_ids'] ) && is_array( $args['product_ids'] ) ) {
			$coupon->set_product_ids( array_map( 'intval', $args['product_ids'] ) );
		}
	}

	/**
	 * 优惠券摘要。
	 *
	 * @param \WC_Coupon $coupon 优惠券。
	 * @return array
	 */
	private function summarize_coupon( $coupon ) {
		$expires = $coupon->get_date_expires();
		return array(
			'id'            => $coupon->get_id(),
			'code'          => $coupon->get_code(),
			'discount_type' => $coupon->get_discount_type(),
			'amount'        => $coupon->get_amount(),
			'free_shipping' => $coupon->get_free_shipping(),
			'usage_count'   => $coupon->get_usage_count(),
			'usage_limit'   => $coupon->get_usage_limit(),
			'expiry_date'   => $expires ? $expires->date( 'Y-m-d' ) : null,
		);
	}
}
