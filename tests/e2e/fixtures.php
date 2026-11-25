<?php
/**
 * 启用 WooCommerce 与本插件,创建测试数据,并把插件状态改成「从旧版升级」以覆盖迁移逻辑。
 * 用法:php fixtures.php <站点目录> <工作目录> activate   (先启用插件)
 *       php fixtures.php <站点目录> <工作目录>            (再建测试数据)
 */
$_SERVER['HTTP_HOST']   = 'localhost:8899';
$_SERVER['REQUEST_URI'] = '/';
require $argv[1] . '/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
wp_set_current_user( 1 );

// 第一步只启用插件:WooCommerce 在启用它的那次请求里尚未完成初始化,建单要放到新进程里做。
if ( isset( $argv[3] ) && 'activate' === $argv[3] ) {
	foreach ( array( 'woocommerce/woocommerce.php', 'wp-mcp/wp-mcp.php' ) as $p ) {
		$r = activate_plugin( $p );
		if ( is_wp_error( $r ) ) {
			fwrite( STDERR, $p . ': ' . $r->get_error_message() . "\n" );
			exit( 1 );
		}
	}
	echo "plugins activated\n";
	exit( 0 );
}
$work = $argv[2];

// 跳过 WooCommerce 首次进入后台时跳转到设置向导。
delete_transient( '_wc_activation_redirect' );

// 测试会在短时间内连续调用,放宽限流(限流测试自己会临时调低)。
update_option( 'wp_mcp_rl_read', 5000 );
update_option( 'wp_mcp_rl_write', 5000 );

// 可变商品 + 变体。
$attr = new WC_Product_Attribute();
$attr->set_name( 'Color' );
$attr->set_options( array( 'Red', 'Blue' ) );
$attr->set_visible( true );
$attr->set_variation( true );
$product = new WC_Product_Variable();
$product->set_name( 'Test Bag' );
$product->set_attributes( array( $attr ) );
$product->set_status( 'publish' );
$pid = $product->save();
$var = new WC_Product_Variation();
$var->set_parent_id( $pid );
$var->set_attributes( array( 'color' => 'Red' ) );
$var->set_regular_price( '99' );
$vid = $var->save();

// 带客户信息的订单。
$order = wc_create_order();
$order->add_product( wc_get_product( $vid ), 2 );
$order->set_address(
	array(
		'first_name' => 'Anna',
		'last_name'  => 'Li',
		'email'      => 'anna@example.com',
		'phone'      => '13800138000',
		'address_1'  => '1 Main St',
		'city'       => 'Berlin',
		'country'    => 'DE',
	),
	'billing'
);
$order->calculate_totals();
$order->set_status( 'processing' );
$oid = $order->save();

// 含密钥的支付网关设置(验证密钥隐藏)。
update_option( 'woocommerce_fakepay_settings', array( 'enabled' => 'yes', 'title' => 'Fake', 'secret_key' => 'sk_live_REAL', 'publishable_key' => 'pk_1' ) );
update_option( 'fakepay_api_key', 'REALKEY' );

// 模拟从 0.3.0 升级:旧的单令牌 + 旧设计快照,下一次请求时触发迁移。
$token = 'mcp_' . wp_generate_password( 48, false, false );
update_option( 'wp_mcp_token_hash', hash( 'sha256', $token ) );
update_option( 'wp_mcp_bound_user', 1 );
update_option( 'wp_mcp_design_backups', array( array( 'x' => 1 ) ) );
update_option( 'wp_mcp_db_version', '0.3.0' );
global $wpdb;
$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'mcp_connections' ); // phpcs:ignore

file_put_contents( $work . '/token.txt', $token );
file_put_contents(
	$work . '/fixtures.json',
	wp_json_encode(
		array(
			'product'   => $pid,
			'variation' => $vid,
			'order'     => $oid,
			'version'   => WP_MCP_VERSION,
		)
	)
);
echo "fixtures: product=$pid variation=$vid order=$oid\n";
