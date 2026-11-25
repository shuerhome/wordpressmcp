<?php
/**
 * 权限级别策略:决定每个连接能调用哪些工具/动作。
 *
 * 在服务端执行,不依赖模型自觉:越权调用直接拒绝。
 * 未在表中列出的工具或动作(包括第三方经 wp_mcp_register_tools 注册的工具)默认只有「完全管理」可用。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 级别策略。
 */
class Policy {

	/**
	 * 工具 => 各级别允许的动作。full 级别不在表中,始终可用全部动作。
	 *
	 * @return array
	 */
	public static function map() {
		$map = array(
			'wp_site'      => array(
				'read' => array( 'info', 'capabilities', 'health' ),
				'ops'  => array( 'flush_cache' ),
			),
			'wp_media'     => array(
				'read' => array( 'list', 'get' ),
				'ops'  => array( 'upload', 'update', 'delete' ),
			),
			'wp_taxonomy'  => array(
				'read' => array( 'list_taxonomies', 'list', 'get' ),
				'ops'  => array( 'create', 'update', 'delete' ),
			),
			'wp_users'     => array(
				'read' => array( 'list', 'get' ),
			),
			'wp_settings'  => array(
				'read' => array( 'get' ),
			),
			'wp_plugins'   => array(
				'read' => array( 'list' ),
			),
			'wp_system'    => array(
				'read' => array( 'site_health', 'cron_list' ),
			),
			'wp_rest'      => array(
				'read' => array( 'namespaces', 'routes', 'describe', 'call' ), // call 再按方法与路径细分,见 rest_allowed()。
			),
			'wc_products'  => array(
				'read' => array( 'list', 'get', 'variations', 'categories' ),
				'ops'  => array( 'create', 'update', 'delete' ),
			),
			'wc_orders'    => array(
				'read' => array( 'list', 'get' ),
				'ops'  => array( 'set_status', 'note', 'update' ), // refund 仅完全管理。
			),
			'wc_reports'   => array(
				'read' => array( 'sales', 'top_products' ),
			),
			'wc_inventory' => array(
				'read' => array( 'get', 'low_stock' ),
				'ops'  => array( 'set_stock', 'bulk_set' ),
			),
			'wc_customers' => array(
				'read' => array( 'list', 'get' ),
				'ops'  => array( 'create', 'update', 'delete' ),
			),
			'wc_coupons'   => array(
				'read' => array( 'list', 'get' ),
				'ops'  => array( 'create', 'update', 'delete' ),
			),
			'wc_settings'  => array(
				'read' => array( 'get' ),
			),
			'wc_shipping'  => array(
				'read' => array( 'list_zones', 'get_zone' ),
			),
			'wc_webhooks'  => array(
				'read' => array( 'list', 'get' ),
			),
		);

		/**
		 * 过滤级别策略表。
		 *
		 * @param array $map 工具 => array( 'read' => 动作[], 'ops' => 动作[] )。
		 */
		return apply_filters( 'wp_mcp_tier_policy', $map );
	}

	/**
	 * 「运营」级别经 wp_rest 可写的路由(正则)。退款路由始终排除。
	 *
	 * @return string[]
	 */
	public static function ops_rest_write_routes() {
		$routes = array(
			'@^/wc/v\d+/products(/|$)@i',   // 商品、变体、属性、分类、标签、评价、运费类别、批量。
			'@^/wc/v\d+/orders(/|$)@i',     // 订单(退款另行排除)。
			'@^/wc/v\d+/coupons(/|$)@i',
			'@^/wc/v\d+/customers(/|$)@i',
			'@^/wp/v2/media(/|$)@i',        // 商品图片。
			'@^/wp/v2/product_(cat|tag)(/|$)@i',
		);

		/**
		 * 过滤「运营」级别经 wp_rest 可写的路由。
		 *
		 * @param string[] $routes 正则。
		 */
		return apply_filters( 'wp_mcp_ops_rest_write_routes', $routes );
	}

	/**
	 * 该动作是否为写操作(用于限流分类)。
	 *
	 * @param string $tool   工具名。
	 * @param string $action 动作。
	 * @param array  $args   入参。
	 * @return bool
	 */
	public static function is_write( $tool, $action, $args ) {
		if ( 'wp_rest' === $tool ) {
			if ( 'call' !== $action ) {
				return false;
			}
			$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
			return 'GET' !== $method;
		}
		$map = self::map();
		return ! ( isset( $map[ $tool ]['read'] ) && in_array( $action, $map[ $tool ]['read'], true ) );
	}

	/**
	 * 判断当前级别能否调用。
	 *
	 * @param string $tier   连接级别。
	 * @param string $tool   工具名。
	 * @param string $action 动作。
	 * @param array  $args   入参。
	 * @return true|\WP_Error
	 */
	public static function check( $tier, $tool, $action, $args ) {
		if ( Connections::TIER_FULL === $tier ) {
			return true;
		}

		$map     = self::map();
		$allowed = array();
		if ( isset( $map[ $tool ]['read'] ) ) {
			$allowed = $map[ $tool ]['read'];
		}
		if ( Connections::TIER_OPS === $tier && isset( $map[ $tool ]['ops'] ) ) {
			$allowed = array_merge( $allowed, $map[ $tool ]['ops'] );
		}

		if ( ! in_array( $action, $allowed, true ) ) {
			return self::denied( $tier, sprintf( '%s 的 %s', $tool, $action ) );
		}

		if ( 'wp_rest' === $tool && 'call' === $action ) {
			return self::rest_allowed( $tier, $args );
		}

		return true;
	}

	/**
	 * wp_rest call 的细分规则。
	 *
	 * @param string $tier 级别。
	 * @param array  $args 入参。
	 * @return true|\WP_Error
	 */
	private static function rest_allowed( $tier, $args ) {
		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		if ( 'GET' === $method ) {
			return true;
		}

		$path = isset( $args['path'] ) ? (string) $args['path'] : '';
		$pos  = strpos( $path, '/wp-json/' );
		if ( false !== $pos ) {
			$path = substr( $path, $pos + strlen( '/wp-json' ) );
		}
		$path = '/' . ltrim( strtok( $path, '?' ), '/' );

		if ( Connections::TIER_OPS === $tier ) {
			if ( preg_match( '@/refunds(/|$)@i', $path ) ) {
				return self::denied( $tier, '退款' );
			}
			foreach ( self::ops_rest_write_routes() as $pattern ) {
				if ( preg_match( $pattern, $path ) ) {
					return true;
				}
			}
		}

		return self::denied( $tier, sprintf( '%s %s', $method, $path ) );
	}

	/**
	 * 过滤 tools/list:隐藏当前级别完全不能用的工具。
	 *
	 * @param string $tier  级别。
	 * @param array  $tools list_for_mcp() 的结果。
	 * @return array
	 */
	public static function filter_tools( $tier, $tools ) {
		if ( Connections::TIER_FULL === $tier ) {
			return $tools;
		}
		$map = self::map();
		$out = array();
		foreach ( $tools as $tool ) {
			if ( empty( $map[ $tool['name'] ]['read'] ) ) {
				continue;
			}
			if ( Connections::TIER_READ === $tier ) {
				$tool['annotations']['readOnlyHint'] = true;
				unset( $tool['annotations']['destructiveHint'], $tool['annotations']['idempotentHint'] );
			}
			$out[] = $tool;
		}
		return $out;
	}

	/**
	 * 构造越权错误。
	 *
	 * @param string $tier 级别。
	 * @param string $what 操作描述。
	 * @return \WP_Error
	 */
	private static function denied( $tier, $what ) {
		$tiers = Connections::tiers();
		$label = isset( $tiers[ $tier ] ) ? $tiers[ $tier ]['label'] : $tier;
		return new \WP_Error(
			'wp_mcp_forbidden_tier',
			sprintf( '当前连接的权限级别是「%s」,不允许执行:%s。如确需执行,请站点管理员在「设置 → WP MCP」中使用更高级别的连接。', $label, $what )
		);
	}
}
