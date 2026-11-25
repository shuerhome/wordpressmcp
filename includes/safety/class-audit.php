<?php
/**
 * 审计日志:记录每次工具调用,存独立数据表。
 *
 * 记录哪个连接、什么时间、从哪个 IP、调用了什么、目标是什么、结果如何。
 * 按保留天数自动清理。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

use WPMCP\Mcp\Auth;

/**
 * 审计日志。
 */
class Audit {

	const OPT_DAYS     = 'wp_mcp_audit_days';
	const DEFAULT_DAYS = 90;
	const CRON_HOOK    = 'wp_mcp_audit_cleanup';

	/**
	 * 表名。
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_audit';
	}

	/**
	 * 建表。
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				connection_id bigint(20) unsigned NOT NULL DEFAULT 0,
				connection_name varchar(191) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				tool varchar(64) NOT NULL DEFAULT '',
				action varchar(191) NOT NULL DEFAULT '',
				target varchar(255) NOT NULL DEFAULT '',
				result varchar(20) NOT NULL DEFAULT '',
				message varchar(500) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				PRIMARY KEY  (id),
				KEY created_at (created_at),
				KEY connection_id (connection_id),
				KEY tool (tool)
			) {$charset};"
		);
	}

	/**
	 * 记录一次调用。
	 *
	 * @param string $tool    工具名。
	 * @param array  $args    入参(action 必有;id/path 等用于定位目标)。
	 * @param string $result  success / error / denied / confirm / dry_run。
	 * @param string $message 补充说明(错误信息等)。
	 */
	public static function log( $tool, $args, $result = 'success', $message = '' ) {
		if ( ! get_option( 'wp_mcp_audit', 1 ) ) {
			return;
		}
		global $wpdb;
		$conn = Auth::current();
		$wpdb->insert(
			self::table(),
			array(
				'created_at'      => current_time( 'mysql', true ),
				'connection_id'   => $conn ? (int) $conn->id : 0,
				'connection_name' => $conn ? mb_substr( (string) $conn->name, 0, 191 ) : '',
				'user_id'         => get_current_user_id(),
				'tool'            => mb_substr( (string) $tool, 0, 64 ),
				'action'          => mb_substr( isset( $args['action'] ) ? (string) $args['action'] : '', 0, 191 ),
				'target'          => mb_substr( self::target_of( $args ), 0, 255 ),
				'result'          => mb_substr( (string) $result, 0, 20 ),
				'message'         => mb_substr( wp_strip_all_tags( (string) $message ), 0, 500 ),
				'ip'              => mb_substr( self::ip(), 0, 45 ),
			)
		);
	}

	/**
	 * 把工具返回值归类为审计结果。
	 *
	 * @param mixed $result 工具返回值。
	 * @return string
	 */
	public static function result_of( $result ) {
		if ( is_wp_error( $result ) ) {
			return 'error';
		}
		if ( is_array( $result ) ) {
			if ( ! empty( $result['confirmation_required'] ) ) {
				return 'confirm';
			}
			if ( ! empty( $result['dry_run'] ) ) {
				return 'dry_run';
			}
			if ( isset( $result['error'] ) ) {
				return 'error';
			}
		}
		return 'success';
	}

	/**
	 * 查询日志。
	 *
	 * @param array $filters connection_id / tool / result / limit / offset。
	 * @return object[]
	 */
	public static function query( $filters = array() ) {
		global $wpdb;
		$where  = array( '1=1' );
		$params = array();
		if ( ! empty( $filters['connection_id'] ) ) {
			$where[]  = 'connection_id = %d';
			$params[] = (int) $filters['connection_id'];
		}
		if ( ! empty( $filters['tool'] ) ) {
			$where[]  = 'tool = %s';
			$params[] = (string) $filters['tool'];
		}
		if ( ! empty( $filters['result'] ) ) {
			$where[]  = 'result = %s';
			$params[] = (string) $filters['result'];
		}
		$limit    = isset( $filters['limit'] ) ? max( 1, min( 5000, (int) $filters['limit'] ) ) : 100;
		$offset   = isset( $filters['offset'] ) ? max( 0, (int) $filters['offset'] ) : 0;
		$params[] = $limit;
		$params[] = $offset;
		$sql      = 'SELECT * FROM ' . self::table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
		return (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 兼容旧接口:最近 N 条。
	 *
	 * @param int $limit 条数。
	 * @return object[]
	 */
	public static function recent( $limit = 50 ) {
		return self::query( array( 'limit' => $limit ) );
	}

	/**
	 * 清理过期日志。
	 */
	public static function cleanup() {
		global $wpdb;
		$days = (int) get_option( self::OPT_DAYS, self::DEFAULT_DAYS );
		if ( $days <= 0 ) {
			return;
		}
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 从入参中提取目标描述。
	 *
	 * @param array $args 入参。
	 * @return string
	 */
	private static function target_of( $args ) {
		if ( ! empty( $args['path'] ) ) {
			$m = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
			return $m . ' ' . (string) $args['path'];
		}
		foreach ( array( 'id', 'product_id', 'order_id', 'plugin', 'option', 'slug', 'hook' ) as $k ) {
			if ( isset( $args[ $k ] ) && is_scalar( $args[ $k ] ) && '' !== (string) $args[ $k ] ) {
				return $k . '=' . $args[ $k ];
			}
		}
		if ( ! empty( $args['ids'] ) && is_array( $args['ids'] ) ) {
			return 'ids=' . implode( ',', array_slice( array_map( 'strval', $args['ids'] ), 0, 20 ) );
		}
		return '';
	}

	/**
	 * 来源 IP。
	 *
	 * @return string
	 */
	public static function ip() {
		return isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	}
}
