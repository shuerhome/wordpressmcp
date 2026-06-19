<?php
/**
 * wc_webhooks 工具:WooCommerce Webhook 管理(阶段 4)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce Webhook 工具。
 */
class WC_Webhooks_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_webhooks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '管理 WooCommerce Webhook:list=列出; get=取详情; create=创建(name/topic/delivery_url); update=更新; delete=删除(需确认)。topic 形如 order.created / product.updated / customer.created。status:active/paused/disabled。写操作支持 dry_run。';
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
						'description' => 'get/update/delete 的 webhook ID。',
					),
					'name'         => array(
						'type'        => 'string',
						'description' => 'create/update 名称。',
					),
					'topic'        => array(
						'type'        => 'string',
						'description' => 'create/update 主题,如 order.created / product.updated。',
					),
					'delivery_url' => array(
						'type'        => 'string',
						'description' => 'create/update 投递 URL。',
					),
					'status'       => array(
						'type'        => 'string',
						'description' => 'create/update 状态:active / paused / disabled。',
					),
					'secret'       => array(
						'type'        => 'string',
						'description' => 'create/update 签名密钥(留空自动生成)。',
					),
					'per_page'     => array(
						'type'    => 'integer',
						'default' => 50,
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
		if ( ! function_exists( 'wc_get_webhook' ) || ! class_exists( '\WC_Webhook' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无 WooCommerce 管理权限(manage_woocommerce)。' );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_webhooks( $args );
			case 'get':
				return $this->get_webhook( $args );
			case 'create':
				return $this->create_webhook( $args );
			case 'update':
				return $this->update_webhook( $args );
			case 'delete':
				return $this->delete_webhook( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出 webhook。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_webhooks( $args ) {
		$per_page   = min( 200, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 50 ) ) );
		$data_store = \WC_Data_Store::load( 'webhook' );
		$ids        = $data_store->search_webhooks( array( 'limit' => $per_page ) );

		$out = array();
		foreach ( $ids as $id ) {
			$webhook = wc_get_webhook( $id );
			if ( $webhook ) {
				$out[] = $this->summarize_webhook( $webhook );
			}
		}

		return array( 'webhooks' => $out );
	}

	/**
	 * 取单个 webhook。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_webhook( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$webhook = $id ? wc_get_webhook( $id ) : null;
		if ( ! $webhook ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到 webhook ID:' . $id );
		}

		$data                 = $this->summarize_webhook( $webhook );
		$data['secret']       = $webhook->get_secret();
		$data['failure_count'] = $webhook->get_failure_count();
		return $data;
	}

	/**
	 * 创建 webhook。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_webhook( $args ) {
		$topic = isset( $args['topic'] ) ? sanitize_text_field( $args['topic'] ) : '';
		$url   = isset( $args['delivery_url'] ) ? esc_url_raw( $args['delivery_url'] ) : '';
		if ( '' === $topic || '' === $url ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create 需要 topic 与 delivery_url。' );
		}

		$blocked = $this->guard( 'create', $args, sprintf( '创建 webhook(%s → %s)', $topic, $url ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$webhook = new \WC_Webhook();
		$webhook->set_name( isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : ( 'Webhook ' . $topic ) );
		$webhook->set_topic( $topic );
		$webhook->set_delivery_url( $url );
		$webhook->set_status( isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'active' );
		$webhook->set_secret( ! empty( $args['secret'] ) ? (string) $args['secret'] : wp_generate_password( 32, false ) );
		$webhook->set_user_id( get_current_user_id() );
		$id = $webhook->save();

		if ( ! $id ) {
			return new \WP_Error( 'wp_mcp_create_failed', '创建失败。' );
		}

		return array( 'created' => true, 'webhook' => $this->get_webhook( array( 'id' => $id ) ) );
	}

	/**
	 * 更新 webhook。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_webhook( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$webhook = $id ? wc_get_webhook( $id ) : null;
		if ( ! $webhook ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到 webhook ID:' . $id );
		}

		$blocked = $this->guard( 'update', $args, '更新 webhook #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( isset( $args['name'] ) ) {
			$webhook->set_name( sanitize_text_field( $args['name'] ) );
		}
		if ( isset( $args['topic'] ) ) {
			$webhook->set_topic( sanitize_text_field( $args['topic'] ) );
		}
		if ( isset( $args['delivery_url'] ) ) {
			$webhook->set_delivery_url( esc_url_raw( $args['delivery_url'] ) );
		}
		if ( isset( $args['status'] ) ) {
			$webhook->set_status( sanitize_key( $args['status'] ) );
		}
		if ( isset( $args['secret'] ) ) {
			$webhook->set_secret( (string) $args['secret'] );
		}
		$webhook->save();

		return array( 'updated' => true, 'webhook' => $this->get_webhook( array( 'id' => $id ) ) );
	}

	/**
	 * 删除 webhook。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_webhook( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$webhook = $id ? wc_get_webhook( $id ) : null;
		if ( ! $webhook ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到 webhook ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, '永久删除 webhook #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = $webhook->delete( true );

		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * webhook 摘要。
	 *
	 * @param \WC_Webhook $webhook webhook。
	 * @return array
	 */
	private function summarize_webhook( $webhook ) {
		return array(
			'id'           => $webhook->get_id(),
			'name'         => $webhook->get_name(),
			'topic'        => $webhook->get_topic(),
			'status'       => $webhook->get_status(),
			'delivery_url' => $webhook->get_delivery_url(),
		);
	}
}
