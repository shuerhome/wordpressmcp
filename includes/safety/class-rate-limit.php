<?php
/**
 * 限流:按连接计数,读写分开;危险操作按天计数。
 *
 * 计数存在 transient 中(有持久化对象缓存时走缓存,否则落 options 表)。
 * 计数窗口为固定的自然分钟/自然日,实现简单、开销小。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * 简易固定窗口限流。
 */
class Rate_Limit {

	const OPT_READ      = 'wp_mcp_rl_read';      // 每分钟读调用上限。
	const OPT_WRITE     = 'wp_mcp_rl_write';     // 每分钟写调用上限。
	const OPT_DANGEROUS = 'wp_mcp_rl_dangerous'; // 每天经确认执行的危险操作上限。

	const DEFAULT_READ      = 120;
	const DEFAULT_WRITE     = 30;
	const DEFAULT_DANGEROUS = 50;

	/**
	 * 记一次工具调用;超限返回错误。
	 *
	 * @param string $key   计数主体(连接 ID 等)。
	 * @param bool   $write 是否写操作。
	 * @return true|\WP_Error
	 */
	public static function hit_call( $key, $write ) {
		$limit = $write
			? (int) get_option( self::OPT_WRITE, self::DEFAULT_WRITE )
			: (int) get_option( self::OPT_READ, self::DEFAULT_READ );
		$kind  = $write ? 'w' : 'r';
		$res   = self::hit( 'call_' . $kind . '_' . $key, $limit, MINUTE_IN_SECONDS );
		if ( true !== $res ) {
			return new \WP_Error(
				'wp_mcp_rate_limited',
				sprintf(
					'调用过于频繁:本连接每分钟最多 %d 次%s操作,请 %d 秒后重试。如经常触发,可在「设置 → WP MCP」调高限额。',
					$limit,
					$write ? '写' : '读',
					$res
				)
			);
		}
		return true;
	}

	/**
	 * 记一次已确认执行的危险操作;超限返回错误。
	 *
	 * @param string $key 计数主体。
	 * @return true|\WP_Error
	 */
	public static function hit_dangerous( $key ) {
		$limit = (int) get_option( self::OPT_DANGEROUS, self::DEFAULT_DANGEROUS );
		$res   = self::hit( 'danger_' . $key, $limit, DAY_IN_SECONDS );
		if ( true !== $res ) {
			return new \WP_Error(
				'wp_mcp_rate_limited',
				sprintf( '本连接今天已执行 %d 次需确认的危险操作(删除、退款等),已达每日上限。请明天再试,或由管理员在「设置 → WP MCP」调高上限。', $limit )
			);
		}
		return true;
	}

	/**
	 * 按 IP 限流(OAuth 注册、令牌端点等未鉴权入口)。
	 *
	 * @param string $bucket 名称。
	 * @param int    $limit  上限。
	 * @param int    $window 窗口秒数。
	 * @return bool 是否放行。
	 */
	public static function hit_ip( $bucket, $limit, $window ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown';
		return true === self::hit( 'ip_' . $bucket . '_' . md5( $ip ), $limit, $window );
	}

	/**
	 * 固定窗口计数。
	 *
	 * @param string $name   计数名。
	 * @param int    $limit  上限,<=0 表示不限。
	 * @param int    $window 窗口秒数。
	 * @return true|int 放行返回 true,超限返回距窗口结束的秒数。
	 */
	private static function hit( $name, $limit, $window ) {
		if ( $limit <= 0 ) {
			return true;
		}
		$slot  = (int) floor( time() / $window );
		$key   = 'wp_mcp_rl_' . md5( $name . '|' . $slot );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return max( 1, ( $slot + 1 ) * $window - time() );
		}
		set_transient( $key, $count + 1, $window + 5 );
		return true;
	}
}
