<?php
/**
 * wp_widgets 工具:侧边栏与小工具(阶段 4)。
 *
 * 说明:现代 WordPress 默认使用「区块小工具」,其实例数据结构脆弱且易随主题/版本漂移,
 * 直接写入风险高。本工具因此以「只读盘点」为主——列出侧边栏区域及其内的小工具与设置,
 * 便于 Claude 了解现状;调整布局建议走 wp_design(模板部件)或后台区块编辑器。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 侧边栏/小工具只读盘点工具。
 */
class WP_Widgets_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_widgets';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '侧边栏/小工具(只读盘点):sidebars=列出所有小工具区域及各自的小工具数量; get=取某区域内的小工具及其实例设置。注:现代区块小工具写入易碎,本工具仅读;布局调整请用 wp_design 或后台编辑器。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'sidebars', 'get' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'     => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'sidebar_id' => array(
						'type'        => 'string',
						'description' => 'get 的侧边栏区域 ID(见 sidebars 返回)。',
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
		switch ( $action ) {
			case 'sidebars':
				return $this->list_sidebars();
			case 'get':
				return $this->get_sidebar( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出所有小工具区域。
	 *
	 * @return array
	 */
	private function list_sidebars() {
		global $wp_registered_sidebars;

		$widgets = wp_get_sidebars_widgets();
		$out     = array();

		if ( is_array( $wp_registered_sidebars ) ) {
			foreach ( $wp_registered_sidebars as $id => $sidebar ) {
				$out[] = array(
					'id'           => $id,
					'name'         => isset( $sidebar['name'] ) ? $sidebar['name'] : $id,
					'description'  => isset( $sidebar['description'] ) ? $sidebar['description'] : '',
					'widget_count' => isset( $widgets[ $id ] ) ? count( (array) $widgets[ $id ] ) : 0,
				);
			}
		}

		// 未使用(停用)的小工具。
		$out[] = array(
			'id'           => 'wp_inactive_widgets',
			'name'         => '(停用的小工具)',
			'description'  => '',
			'widget_count' => isset( $widgets['wp_inactive_widgets'] ) ? count( (array) $widgets['wp_inactive_widgets'] ) : 0,
		);

		return array( 'sidebars' => $out );
	}

	/**
	 * 取某区域内的小工具及实例设置。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_sidebar( $args ) {
		global $wp_registered_widgets;

		$sidebar_id = isset( $args['sidebar_id'] ) ? (string) $args['sidebar_id'] : '';
		if ( '' === $sidebar_id ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'get 需要 sidebar_id。' );
		}

		$map        = wp_get_sidebars_widgets();
		$widget_ids = isset( $map[ $sidebar_id ] ) ? (array) $map[ $sidebar_id ] : array();

		$out = array();
		foreach ( $widget_ids as $widget_id ) {
			$base     = preg_replace( '/-\d+$/', '', $widget_id );
			$number   = (int) preg_replace( '/^.*-(\d+)$/', '$1', $widget_id );
			$settings = get_option( 'widget_' . $base, array() );

			$out[] = array(
				'widget_id' => $widget_id,
				'base'      => $base,
				'name'      => isset( $wp_registered_widgets[ $widget_id ]['name'] ) ? $wp_registered_widgets[ $widget_id ]['name'] : $base,
				'settings'  => isset( $settings[ $number ] ) ? $settings[ $number ] : null,
			);
		}

		return array(
			'sidebar_id' => $sidebar_id,
			'widgets'    => $out,
		);
	}
}
