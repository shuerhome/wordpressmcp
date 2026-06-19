<?php
/**
 * wc_customers 工具:WooCommerce 客户管理(阶段 4),默认 PII 脱敏。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\PII;

/**
 * WooCommerce 客户工具。
 */
class WC_Customers_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_customers';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '管理 WooCommerce 客户:list=列出/搜索; get=取详情(含订单数/消费额/地址); create=创建; update=更新资料与地址; delete=删除(需确认)。邮箱/姓名/地址默认脱敏(reveal_pii=true 解锁)。写操作支持 dry_run。';
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
					'action'       => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'id'           => array(
						'type'        => 'integer',
						'description' => 'get/update/delete 的客户(用户)ID。',
					),
					'search'       => array(
						'type'        => 'string',
						'description' => 'list 关键词(邮箱/登录名/昵称)。',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'create/update 邮箱(create 必填)。',
					),
					'username'     => array(
						'type'        => 'string',
						'description' => 'create 登录名(留空自动生成)。',
					),
					'password'     => array(
						'type'        => 'string',
						'description' => 'create 密码(留空自动生成)。',
					),
					'first_name'   => array(
						'type'        => 'string',
						'description' => 'create/update 名。',
					),
					'last_name'    => array(
						'type'        => 'string',
						'description' => 'create/update 姓。',
					),
					'billing'      => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'update 账单地址字段(address_1/city/postcode/country/phone 等)。',
					),
					'shipping'     => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'update 配送地址字段。',
					),
					'per_page'     => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'         => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'reveal_pii'   => array(
						'type'        => 'boolean',
						'description' => '解锁客户 PII(默认脱敏)。',
						'default'     => false,
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
		if ( ! class_exists( '\WC_Customer' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_customers( $args );
			case 'get':
				return $this->get_customer( $args );
			case 'create':
				return $this->create_customer( $args );
			case 'update':
				return $this->update_customer( $args );
			case 'delete':
				return $this->delete_customer( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出客户。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_customers( $args ) {
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$mask     = PII::should_mask( $args );

		$query = array(
			'role'   => 'customer',
			'number' => $per_page,
			'paged'  => $page,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search']         = '*' . sanitize_text_field( $args['search'] ) . '*';
			$query['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		$user_query = new \WP_User_Query( $query );
		$items      = array();
		foreach ( $user_query->get_results() as $user ) {
			$items[] = $this->summarize_customer( $user->ID, $mask );
		}

		return array(
			'items'      => $items,
			'page'       => $page,
			'per_page'   => $per_page,
			'total'      => (int) $user_query->get_total(),
			'pii_masked' => $mask,
		);
	}

	/**
	 * 取单个客户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_customer( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || ! get_userdata( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到客户 ID:' . $id );
		}

		$mask     = PII::should_mask( $args );
		$customer = new \WC_Customer( $id );
		$data     = $this->summarize_customer( $id, $mask );

		$billing  = $customer->get_billing();
		$shipping = $customer->get_shipping();
		$data['billing']  = $mask ? PII::address( $billing ) : $billing;
		$data['shipping'] = $mask ? PII::address( $shipping ) : $shipping;

		return $data;
	}

	/**
	 * 创建客户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_customer( $args ) {
		if ( ! current_user_can( 'create_users' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无创建用户权限(create_users)。' );
		}
		$email = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		if ( '' === $email ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create 需要 email。' );
		}

		$blocked = $this->guard( 'create', $args, '创建客户 <' . $email . '>' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$username = ! empty( $args['username'] ) ? sanitize_user( $args['username'] ) : '';
		$password = ! empty( $args['password'] ) ? (string) $args['password'] : '';

		$id = wc_create_new_customer( $email, $username, $password );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// 写入姓名。
		$customer = new \WC_Customer( $id );
		$this->apply_names( $customer, $args );
		$customer->save();

		return array( 'created' => true, 'customer' => $this->get_customer( array( 'id' => $id ) ) );
	}

	/**
	 * 更新客户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_customer( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || ! get_userdata( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到客户 ID:' . $id );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无编辑该用户权限。' );
		}

		$blocked = $this->guard( 'update', $args, '更新客户 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$customer = new \WC_Customer( $id );
		if ( isset( $args['email'] ) ) {
			$customer->set_email( sanitize_email( $args['email'] ) );
		}
		$this->apply_names( $customer, $args );
		$this->apply_address( $customer, 'billing', $args );
		$this->apply_address( $customer, 'shipping', $args );
		$customer->save();

		return array( 'updated' => true, 'customer' => $this->get_customer( array( 'id' => $id ) ) );
	}

	/**
	 * 删除客户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_customer( $args ) {
		if ( ! current_user_can( 'delete_users' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无删除用户权限(delete_users)。' );
		}
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || ! get_userdata( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到客户 ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, '删除客户 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $id );

		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 写入名/姓。
	 *
	 * @param \WC_Customer $customer 客户对象。
	 * @param array        $args     入参。
	 */
	private function apply_names( $customer, $args ) {
		if ( isset( $args['first_name'] ) ) {
			$customer->set_first_name( sanitize_text_field( $args['first_name'] ) );
		}
		if ( isset( $args['last_name'] ) ) {
			$customer->set_last_name( sanitize_text_field( $args['last_name'] ) );
		}
	}

	/**
	 * 写入账单/配送地址。
	 *
	 * @param \WC_Customer $customer 客户对象。
	 * @param string       $type     billing 或 shipping。
	 * @param array        $args     入参。
	 */
	private function apply_address( $customer, $type, $args ) {
		if ( empty( $args[ $type ] ) || ! is_array( $args[ $type ] ) ) {
			return;
		}
		$allowed = array( 'first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone' );
		foreach ( $args[ $type ] as $field => $value ) {
			$field = sanitize_key( $field );
			if ( ! in_array( $field, $allowed, true ) ) {
				continue;
			}
			// shipping 没有 email/phone setter。
			$setter = 'set_' . $type . '_' . $field;
			if ( method_exists( $customer, $setter ) ) {
				$customer->{$setter}( sanitize_text_field( $value ) );
			}
		}
	}

	/**
	 * 客户摘要(按需脱敏)。
	 *
	 * @param int  $id   客户用户 ID。
	 * @param bool $mask 是否脱敏。
	 * @return array
	 */
	private function summarize_customer( $id, $mask ) {
		$user    = get_userdata( $id );
		$name    = trim( get_user_meta( $id, 'first_name', true ) . ' ' . get_user_meta( $id, 'last_name', true ) );
		$email   = $user ? $user->user_email : '';
		$created = $user ? $user->user_registered : null;

		return array(
			'id'           => $id,
			'username'     => $user ? $user->user_login : '',
			'name'         => $mask ? PII::name( $name ) : $name,
			'email'        => $mask ? PII::email( $email ) : $email,
			'registered'   => $created,
			'orders_count' => function_exists( 'wc_get_customer_order_count' ) ? (int) wc_get_customer_order_count( $id ) : null,
			'total_spent'  => function_exists( 'wc_get_customer_total_spent' ) ? (float) wc_get_customer_total_spent( $id ) : null,
		);
	}
}
