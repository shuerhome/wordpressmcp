<?php
/**
 * wp_blocks 工具:区块解析、可复用区块 CRUD、区块样板列出。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 古腾堡区块工具。
 */
class WP_Blocks_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_blocks';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '古腾堡区块:get_blocks=把某文章正文解析为区块结构便于检查(返回的 index 即下面 update_block 用的序号); update_block=按 index 精修某个顶层区块(attrs 深合并改属性、inner_html 替换其 HTML 文本),写回正文并自动留 WordPress 修订(可用 wp_content:rollback 还原); reusable_list/get/create/update/delete=管理可复用区块(wp_block); patterns=列出已注册的区块样板。修改类支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'get_blocks', 'update_block', 'reusable_list', 'reusable_get', 'reusable_create', 'reusable_update', 'reusable_delete', 'patterns' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'reusable_delete' );
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
					'id'      => array(
						'type'        => 'integer',
						'description' => 'get_blocks 的文章 ID,或 reusable_get/update/delete 的可复用区块 ID',
					),
					'title'   => array(
						'type'        => 'string',
						'description' => 'reusable_create/update 的标题',
					),
					'content' => array(
						'type'        => 'string',
						'description' => 'reusable_create/update 的区块标记内容',
					),
					'index'   => array(
						'type'        => 'integer',
						'description' => 'update_block:要改的顶层区块序号(0 起,对应 get_blocks 返回列表里的位置)。',
					),
					'attrs'   => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'update_block:深合并进该区块属性的键值(如 {"level":3}、{"align":"center"})。',
					),
					'inner_html' => array(
						'type'        => 'string',
						'description' => 'update_block:替换该区块的 HTML 文本(仅适用于无嵌套子区块的区块,如段落/标题)。',
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
		switch ( $action ) {
			case 'get_blocks':
				return $this->get_blocks( $args );
			case 'update_block':
				return $this->update_block( $args );
			case 'reusable_list':
				return $this->reusable_list();
			case 'reusable_get':
				return $this->reusable_get( $args );
			case 'reusable_create':
				return $this->reusable_create( $args );
			case 'reusable_update':
				return $this->reusable_update( $args );
			case 'reusable_delete':
				return $this->reusable_delete( $args );
			case 'patterns':
				return $this->patterns();
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 解析文章正文为区块结构。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_blocks( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章 ID:' . $id );
		}

		$blocks = parse_blocks( $post->post_content );
		return array(
			'post_id' => $id,
			'blocks'  => $this->simplify_blocks( $blocks ),
		);
	}

	/**
	 * 简化区块结构(剔除冗余,保留可读字段)。
	 *
	 * @param array $blocks parse_blocks 结果。
	 * @return array
	 */
	private function simplify_blocks( $blocks ) {
		$out = array();
		foreach ( $blocks as $b ) {
			if ( empty( $b['blockName'] ) ) {
				continue; // 跳过空白文本节点。
			}
			$out[] = array(
				'name'       => $b['blockName'],
				'attrs'      => isset( $b['attrs'] ) ? $b['attrs'] : array(),
				'innerHTML'  => trim( wp_strip_all_tags( isset( $b['innerHTML'] ) ? $b['innerHTML'] : '' ) ),
				'inner'      => ! empty( $b['innerBlocks'] ) ? $this->simplify_blocks( $b['innerBlocks'] ) : array(),
			);
		}
		return $out;
	}

	/**
	 * 精修某文章正文里的一个顶层区块。
	 *
	 * index 对应 get_blocks 返回列表(已跳过空白文本节点)里的序号。
	 * attrs 深合并改属性;inner_html 替换该区块的 HTML 文本(仅限无嵌套子区块的区块)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_block( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章 ID:' . $id );
		}
		if ( ! isset( $args['index'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'index 必填(顶层区块序号,来自 get_blocks)。' );
		}
		$has_attrs = isset( $args['attrs'] ) && is_array( $args['attrs'] ) && ! empty( $args['attrs'] );
		$has_html  = array_key_exists( 'inner_html', $args );
		if ( ! $has_attrs && ! $has_html ) {
			return new \WP_Error( 'wp_mcp_bad_args', '请至少提供 attrs 或 inner_html 之一。' );
		}

		$index  = (int) $args['index'];
		$blocks = parse_blocks( $post->post_content );

		// get_blocks 跳过空白文本节点,这里把可读序号映射回 parse_blocks 的真实下标。
		$named = array();
		foreach ( $blocks as $pos => $b ) {
			if ( ! empty( $b['blockName'] ) ) {
				$named[] = $pos;
			}
		}
		if ( ! isset( $named[ $index ] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', sprintf( 'index 越界:仅有 %d 个区块(0-%d)。', count( $named ), max( 0, count( $named ) - 1 ) ) );
		}
		$real  = $named[ $index ];
		$block = $blocks[ $real ];

		if ( $has_html && ! empty( $block['innerBlocks'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', '该区块含嵌套子区块,不支持直接替换 inner_html;请改用 attrs,或用 wp_content:update 整体更新正文。' );
		}

		$summary = sprintf( '精修文章 #%d 第 %d 个区块「%s」', $id, $index, $block['blockName'] );
		$blocked = $this->guard( 'update_block', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		if ( $has_attrs ) {
			$block['attrs'] = array_replace_recursive( isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array(), $args['attrs'] );
		}
		if ( $has_html ) {
			$html                  = (string) $args['inner_html'];
			$block['innerHTML']    = $html;
			$block['innerContent'] = array( $html );
		}
		$blocks[ $real ] = $block;

		$new_content = serialize_blocks( $blocks );
		$result      = wp_update_post(
			array(
				'ID'           => $id,
				'post_content' => $new_content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'updated' => true,
			'id'      => $id,
			'index'   => $index,
			'block'   => $block['blockName'],
		);
	}

	/**
	 * 列出可复用区块。
	 *
	 * @return array
	 */
	private function reusable_list() {
		$posts = get_posts(
			array(
				'post_type'      => 'wp_block',
				'post_status'    => 'any',
				'posts_per_page' => 100,
			)
		);
		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'    => $p->ID,
				'title' => $p->post_title,
			);
		}
		return array( 'reusable_blocks' => $out );
	}

	/**
	 * 取可复用区块内容。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function reusable_get( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到可复用区块 ID:' . $id );
		}
		return array(
			'id'      => $id,
			'title'   => $post->post_title,
			'content' => $post->post_content,
		);
	}

	/**
	 * 创建可复用区块。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function reusable_create( $args ) {
		$blocked = $this->guard( 'reusable_create', $args, '创建可复用区块「' . ( isset( $args['title'] ) ? $args['title'] : '' ) . '」' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$id = wp_insert_post(
			array(
				'post_type'    => 'wp_block',
				'post_status'  => 'publish',
				'post_title'   => isset( $args['title'] ) ? sanitize_text_field( $args['title'] ) : '未命名区块',
				'post_content' => isset( $args['content'] ) ? $args['content'] : '',
			),
			true
		);
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return array( 'created' => true, 'id' => $id );
	}

	/**
	 * 更新可复用区块。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function reusable_update( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到可复用区块 ID:' . $id );
		}

		$blocked = $this->guard( 'reusable_update', $args, '更新可复用区块 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$postarr = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = $args['content'];
		}
		wp_update_post( $postarr );

		return array( 'updated' => true, 'id' => $id );
	}

	/**
	 * 删除可复用区块。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function reusable_delete( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post || 'wp_block' !== $post->post_type ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到可复用区块 ID:' . $id );
		}

		$blocked = $this->guard( 'reusable_delete', $args, '永久删除可复用区块 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_delete_post( $id, true );
		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 列出已注册的区块样板。
	 *
	 * @return array
	 */
	private function patterns() {
		$out = array();
		if ( class_exists( '\WP_Block_Patterns_Registry' ) ) {
			$registry = \WP_Block_Patterns_Registry::get_instance();
			foreach ( $registry->get_all_registered() as $pattern ) {
				$out[] = array(
					'name'        => isset( $pattern['name'] ) ? $pattern['name'] : '',
					'title'       => isset( $pattern['title'] ) ? $pattern['title'] : '',
					'categories'  => isset( $pattern['categories'] ) ? $pattern['categories'] : array(),
				);
			}
		}
		return array( 'patterns' => $out );
	}
}
