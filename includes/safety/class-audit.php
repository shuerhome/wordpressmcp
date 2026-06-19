<?php
/**
 * 审计日志:记录工具调用(尤其写操作)。
 *
 * 阶段 0 为轻量实现:写入一个滚动的 option 数组(保留最近 N 条)。
 * 后续阶段可迁移到自定义表。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * 简易审计日志。
 */
class Audit {

	const OPTION   = 'wp_mcp_audit_log';
	const MAX_ROWS = 200;

	/**
	 * 记录一次工具调用。
	 *
	 * @param string $tool   工具名。
	 * @param array  $args   入参。
	 * @param string $result 结果摘要(success/error)。
	 */
	public static function log( $tool, $args, $result = 'success' ) {
		if ( ! get_option( 'wp_mcp_audit', 1 ) ) {
			return;
		}

		$log   = get_option( self::OPTION, array() );
		$log[] = array(
			'time'   => current_time( 'mysql' ),
			'user'   => get_current_user_id(),
			'tool'   => $tool,
			'action' => isset( $args['action'] ) ? $args['action'] : '',
			'result' => $result,
		);

		if ( count( $log ) > self::MAX_ROWS ) {
			$log = array_slice( $log, -self::MAX_ROWS );
		}

		update_option( self::OPTION, $log, false );
	}

	/**
	 * 读取最近的日志条目。
	 *
	 * @param int $limit 条数。
	 * @return array
	 */
	public static function recent( $limit = 50 ) {
		$log = get_option( self::OPTION, array() );
		return array_slice( array_reverse( $log ), 0, $limit );
	}
}
