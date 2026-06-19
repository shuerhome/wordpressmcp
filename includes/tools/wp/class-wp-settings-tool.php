<?php
/**
 * wp_settings 工具:站点常规/阅读/讨论/固定链接设置(阶段 4)。
 *
 * 仅允许读写白名单内的选项,避免误改任意 option。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 站点设置读写工具(白名单)。
 */
class WP_Settings_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_settings';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '站点设置(白名单):get=读取一组设置(group=general/reading/discussion/permalink/all); set=按 options 键值更新。仅限白名单内选项。改固定链接结构会自动刷新重写规则。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'get', 'set' );
	}

	/**
	 * 允许读写的选项白名单:option_key => 分组。
	 *
	 * @return array
	 */
	private function whitelist() {
		return array(
			// general.
			'blogname'               => 'general',
			'blogdescription'        => 'general',
			'timezone_string'        => 'general',
			'gmt_offset'             => 'general',
			'date_format'            => 'general',
			'time_format'            => 'general',
			'start_of_week'          => 'general',
			'WPLANG'                 => 'general',
			'site_icon'              => 'general',
			// reading.
			'show_on_front'          => 'reading',
			'page_on_front'          => 'reading',
			'page_for_posts'         => 'reading',
			'posts_per_page'         => 'reading',
			'posts_per_rss'          => 'reading',
			'blog_public'            => 'reading',
			// discussion.
			'default_comment_status' => 'discussion',
			'default_ping_status'    => 'discussion',
			'comment_registration'   => 'discussion',
			'require_name_email'     => 'discussion',
			'comment_moderation'     => 'discussion',
			'comments_per_page'      => 'discussion',
			// permalink.
			'permalink_structure'    => 'permalink',
		);
	}

	/**
	 * 各选项的类型,用于清洗:int / bool / text。
	 *
	 * @return array
	 */
	private function types() {
		return array(
			'gmt_offset'             => 'text', // 可为小数。
			'start_of_week'          => 'int',
			'page_on_front'          => 'int',
			'page_for_posts'         => 'int',
			'posts_per_page'         => 'int',
			'posts_per_rss'          => 'int',
			'blog_public'            => 'int',
			'site_icon'              => 'int',
			'comment_registration'   => 'int',
			'require_name_email'     => 'int',
			'comment_moderation'     => 'int',
			'comments_per_page'      => 'int',
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
						'enum'        => array( 'general', 'reading', 'discussion', 'permalink', 'all' ),
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
		if ( ! current_user_can( 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无管理选项权限(manage_options)。' );
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
		$types     = $this->types();
		$apply     = array();
		$rejected  = array();

		foreach ( $args['options'] as $key => $value ) {
			if ( ! isset( $whitelist[ $key ] ) ) {
				$rejected[] = $key;
				continue;
			}
			$apply[ $key ] = $this->sanitize_value( $key, $value, $types );
		}

		if ( empty( $apply ) ) {
			return new \WP_Error( 'wp_mcp_no_valid_keys', '没有可应用的白名单键。被拒绝:' . implode( ', ', $rejected ) );
		}

		$summary = '更新设置:' . implode( ', ', array_keys( $apply ) );
		$blocked = $this->guard( 'set', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$permalink_changed = false;
		foreach ( $apply as $key => $value ) {
			if ( 'permalink_structure' === $key ) {
				$permalink_changed = ( get_option( 'permalink_structure' ) !== $value );
			}
			update_option( $key, $value );
		}

		// 固定链接结构改动后刷新重写规则。
		if ( $permalink_changed ) {
			global $wp_rewrite;
			if ( $wp_rewrite ) {
				$wp_rewrite->set_permalink_structure( $apply['permalink_structure'] );
				$wp_rewrite->flush_rules( true );
			}
		}

		return array(
			'updated'  => array_keys( $apply ),
			'rejected' => $rejected,
		);
	}

	/**
	 * 按类型清洗选项值。
	 *
	 * @param string $key   选项键。
	 * @param mixed  $value 值。
	 * @param array  $types 类型映射。
	 * @return mixed
	 */
	private function sanitize_value( $key, $value, $types ) {
		$type = isset( $types[ $key ] ) ? $types[ $key ] : 'text';
		switch ( $type ) {
			case 'int':
				return (int) $value;
			case 'bool':
				return $value ? 1 : 0;
			default:
				if ( 'permalink_structure' === $key ) {
					return sanitize_option( 'permalink_structure', (string) $value );
				}
				if ( 'blogdescription' === $key || 'blogname' === $key ) {
					return sanitize_text_field( (string) $value );
				}
				return is_scalar( $value ) ? sanitize_text_field( (string) $value ) : $value;
		}
	}
}
