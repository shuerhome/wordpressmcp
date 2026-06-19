<?php
/**
 * wp_system 工具:系统维护(阶段 4)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Capability\Detector;

/**
 * 系统健康、计划任务、缓存与选项维护工具。
 */
class WP_System_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_system';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '系统维护:site_health=站点健康概览; cron_list=列出计划任务; cron_run=立即执行某个 hook 的计划任务; cache_flush=清对象缓存; transients_flush=清理过期 transient; options_get=读取某 option; options_set=写入某 option(危险,需确认)。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'site_health', 'cron_list', 'cron_run', 'cache_flush', 'transients_flush', 'options_get', 'options_set' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'options_set' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'        => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'hook'          => array(
						'type'        => 'string',
						'description' => 'cron_run 要立即执行的计划任务 hook 名。',
					),
					'option'        => array(
						'type'        => 'string',
						'description' => 'options_get/options_set 的 option 名。',
					),
					'value'         => array(
						'description' => 'options_set 的值(可为字符串/数字/布尔/对象)。',
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无系统维护权限(manage_options)。' );
		}

		switch ( $action ) {
			case 'site_health':
				return $this->site_health();
			case 'cron_list':
				return $this->cron_list();
			case 'cron_run':
				return $this->cron_run( $args );
			case 'cache_flush':
				return $this->cache_flush( $args );
			case 'transients_flush':
				return $this->transients_flush( $args );
			case 'options_get':
				return $this->options_get( $args );
			case 'options_set':
				return $this->options_set( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 站点健康概览。
	 *
	 * @return array
	 */
	private function site_health() {
		global $wpdb;

		$snapshot = Detector::snapshot();
		$active   = array_filter( (array) get_option( 'active_plugins', array() ) );

		$snapshot['runtime'] = array(
			'wp_debug'      => defined( 'WP_DEBUG' ) && WP_DEBUG,
			'environment'   => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'unknown',
			'memory_limit'  => defined( 'WP_MEMORY_LIMIT' ) ? WP_MEMORY_LIMIT : ini_get( 'memory_limit' ),
			'max_upload'    => size_format( wp_max_upload_size() ),
			'mysql_version' => $wpdb ? $wpdb->db_version() : null,
			'active_plugins' => count( $active ),
			'object_cache'  => wp_using_ext_object_cache(),
		);

		return $snapshot;
	}

	/**
	 * 列出计划任务。
	 *
	 * @return array
	 */
	private function cron_list() {
		$crons = _get_cron_array();
		$out   = array();

		if ( is_array( $crons ) ) {
			foreach ( $crons as $timestamp => $hooks ) {
				foreach ( $hooks as $hook => $events ) {
					foreach ( $events as $event ) {
						$out[] = array(
							'hook'     => $hook,
							'next_run' => gmdate( 'c', $timestamp ),
							'schedule' => isset( $event['schedule'] ) ? $event['schedule'] : 'one-off',
							'interval' => isset( $event['interval'] ) ? (int) $event['interval'] : null,
						);
					}
				}
			}
		}

		return array( 'cron' => $out, 'count' => count( $out ) );
	}

	/**
	 * 立即执行某个 hook 的计划任务。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function cron_run( $args ) {
		$hook = isset( $args['hook'] ) ? sanitize_text_field( $args['hook'] ) : '';
		if ( '' === $hook ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'cron_run 需要 hook。' );
		}

		$blocked = $this->guard( 'cron_run', $args, '立即执行计划任务 hook:' . $hook );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// 取该 hook 最近一次事件的参数后触发。
		$next = wp_next_scheduled( $hook );
		$crons = _get_cron_array();
		$cron_args = array();
		if ( is_array( $crons ) ) {
			foreach ( $crons as $events ) {
				if ( isset( $events[ $hook ] ) ) {
					$first     = reset( $events[ $hook ] );
					$cron_args = isset( $first['args'] ) ? $first['args'] : array();
					break;
				}
			}
		}

		do_action_ref_array( $hook, $cron_args );

		return array(
			'ran'           => true,
			'hook'          => $hook,
			'was_scheduled' => (bool) $next,
		);
	}

	/**
	 * 清对象缓存。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function cache_flush( $args ) {
		$blocked = $this->guard( 'cache_flush', $args, '清空对象缓存' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_cache_flush();

		return array( 'flushed' => (bool) $result );
	}

	/**
	 * 清理过期 transient。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function transients_flush( $args ) {
		$blocked = $this->guard( 'transients_flush', $args, '清理过期 transient' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( function_exists( 'delete_expired_transients' ) ) {
			delete_expired_transients( true );
		}

		return array( 'flushed' => true );
	}

	/**
	 * 读取某 option。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function options_get( $args ) {
		$option = isset( $args['option'] ) ? (string) $args['option'] : '';
		if ( '' === $option ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'options_get 需要 option。' );
		}
		return array(
			'option' => $option,
			'value'  => get_option( $option, null ),
		);
	}

	/**
	 * 写入某 option(危险)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function options_set( $args ) {
		$option = isset( $args['option'] ) ? (string) $args['option'] : '';
		if ( '' === $option ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'options_set 需要 option。' );
		}
		if ( ! array_key_exists( 'value', $args ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'options_set 需要 value。' );
		}

		$blocked = $this->guard( 'options_set', $args, sprintf( '写入 option「%s」', $option ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = update_option( $option, $args['value'] );

		return array( 'updated' => (bool) $result, 'option' => $option );
	}
}
