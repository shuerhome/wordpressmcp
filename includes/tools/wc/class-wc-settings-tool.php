<?php
/**
 * wc_settings 工具:WooCommerce 设置(阶段 4),白名单读写。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce 常规设置读写工具(白名单)。
 */
class WC_Settings_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return 'WooCommerce 设置(白名单):get=读取一组设置(group=general/currency/store_address/products/checkout/all); set=按 options 键值更新。仅限白名单内选项。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'get', 'set' );
	}

	/**
	 * 白名单:option_key => 分组。
	 *
	 * @return array
	 */
	private function whitelist() {
		return array(
			// currency.
			'woocommerce_currency'              => 'currency',
			'woocommerce_currency_pos'          => 'currency',
			'woocommerce_price_thousand_sep'    => 'currency',
			'woocommerce_price_decimal_sep'     => 'currency',
			'woocommerce_price_num_decimals'    => 'currency',
			// store address.
			'woocommerce_store_address'         => 'store_address',
			'woocommerce_store_address_2'       => 'store_address',
			'woocommerce_store_city'            => 'store_address',
			'woocommerce_default_country'       => 'store_address',
			'woocommerce_store_postcode'        => 'store_address',
			// general / selling location.
			'woocommerce_allowed_countries'         => 'general',
			'woocommerce_specific_allowed_countries' => 'general',
			'woocommerce_all_except_countries'      => 'general',
			// products.
			'woocommerce_weight_unit'           => 'products',
			'woocommerce_dimension_unit'        => 'products',
			'woocommerce_enable_reviews'        => 'products',
			'woocommerce_manage_stock'          => 'products',
			'woocommerce_notify_low_stock_amount' => 'products',
			// checkout / accounts.
			'woocommerce_enable_guest_checkout' => 'checkout',
			'woocommerce_enable_checkout_login_reminder' => 'checkout',
			'woocommerce_enable_signup_and_login_from_checkout' => 'checkout',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'  => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'group'   => array(
						'type'        => 'string',
						'enum'        => array( 'general', 'currency', 'store_address', 'products', 'checkout', 'all' ),
						'description' => 'get 时的分组,默认 all。',
						'default'     => 'all',
					),
					'options' => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'set 时的「选项键 => 值」。仅白名单内的键会被应用。',
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
		if ( ! function_exists( 'WC' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无 WooCommerce 管理权限(manage_woocommerce)。' );
		}

		switch ( $action ) {
			case 'get':
				return $this->get_settings( $args );
			case 'set':
				return $this->set_settings( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 读取设置。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function get_settings( $args ) {
		$group = isset( $args['group'] ) ? sanitize_key( $args['group'] ) : 'all';
		$out   = array();
		foreach ( $this->whitelist() as $key => $g ) {
			if ( 'all' === $group || $group === $g ) {
				$out[ $key ] = get_option( $key );
			}
		}
		return array( 'group' => $group, 'settings' => $out );
	}

	/**
	 * 写入设置。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_settings( $args ) {
		if ( empty( $args['options'] ) || ! is_array( $args['options'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'set 需要非空 options 对象。' );
		}

		$whitelist = $this->whitelist();
		$apply     = array();
		$rejected  = array();

		foreach ( $args['options'] as $key => $value ) {
			if ( ! isset( $whitelist[ $key ] ) ) {
				$rejected[] = $key;
				continue;
			}
			$apply[ $key ] = is_array( $value ) ? array_map( 'sanitize_text_field', $value ) : sanitize_text_field( (string) $value );
		}

		if ( empty( $apply ) ) {
			return new \WP_Error( 'wp_mcp_no_valid_keys', '没有可应用的白名单键。被拒绝:' . implode( ', ', $rejected ) );
		}

		$summary = '更新 WC 设置:' . implode( ', ', array_keys( $apply ) );
		$blocked = $this->guard( 'set', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		foreach ( $apply as $key => $value ) {
			update_option( $key, $value );
		}

		return array(
			'updated'  => array_keys( $apply ),
			'rejected' => $rejected,
		);
	}
}
