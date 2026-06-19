<?php
/**
 * 设计/设置类改动的快照与回滚存储。
 *
 * 在执行可能影响外观/设置的写操作前,先保存「旧值」快照;
 * 之后可通过 wp_design:rollback 按快照 ID 还原。
 *
 * 轻量实现:写入一个滚动的 option 数组(保留最近 N 条),与审计日志同思路。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * 简易快照仓库。
 */
class Backup {

	const OPTION   = 'wp_mcp_design_backups';
	const MAX_ROWS = 50;

	/**
	 * 保存一条快照,返回快照 ID。
	 *
	 * @param string $type  快照类型(global_styles / wp_template / wp_template_part / theme_mods / custom_css / active_theme)。
	 * @param string $label 人类可读说明。
	 * @param array  $data  还原所需的数据(结构由类型决定)。
	 * @return string 快照 ID。
	 */
	public static function snapshot( $type, $label, $data ) {
		$id  = 'bk_' . wp_generate_password( 12, false, false );
		$log = get_option( self::OPTION, array() );

		$log[] = array(
			'id'    => $id,
			'time'  => current_time( 'mysql' ),
			'user'  => get_current_user_id(),
			'type'  => $type,
			'label' => $label,
			'data'  => $data,
		);

		if ( count( $log ) > self::MAX_ROWS ) {
			$log = array_slice( $log, -self::MAX_ROWS );
		}

		update_option( self::OPTION, $log, false );

		return $id;
	}

	/**
	 * 列出最近的快照(不含还原数据,便于浏览)。
	 *
	 * @param int $limit 条数。
	 * @return array
	 */
	public static function all( $limit = 50 ) {
		$log  = array_reverse( get_option( self::OPTION, array() ) );
		$log  = array_slice( $log, 0, $limit );
		$out  = array();

		foreach ( $log as $row ) {
			$out[] = array(
				'id'    => isset( $row['id'] ) ? $row['id'] : '',
				'time'  => isset( $row['time'] ) ? $row['time'] : '',
				'user'  => isset( $row['user'] ) ? $row['user'] : 0,
				'type'  => isset( $row['type'] ) ? $row['type'] : '',
				'label' => isset( $row['label'] ) ? $row['label'] : '',
			);
		}

		return $out;
	}

	/**
	 * 取出某条快照的完整数据。
	 *
	 * @param string $id 快照 ID。
	 * @return array|null
	 */
	public static function get( $id ) {
		foreach ( get_option( self::OPTION, array() ) as $row ) {
			if ( isset( $row['id'] ) && $row['id'] === $id ) {
				return $row;
			}
		}
		return null;
	}
}
