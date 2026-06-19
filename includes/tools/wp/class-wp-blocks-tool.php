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
		return '古腾堡区块:get_blocks=把某文章正文解析为区块结构便于检查; reusable_list/get/create/update/delete=管理可复用区块(wp_block); patterns=列出已注册的区块样板。修改类支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'get_blocks', 'reusable_list', 'reusable_get', 'reusable_create', 'reusable_update', 'reusable_delete', 'patterns' );
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
