<?php
/**
 * wp_taxonomy 工具:分类法 term 管理(分类/标签/自定义),阶段 4。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 分类法 term 增删改查工具。
 */
class WP_Taxonomy_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_taxonomy';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '分类法管理:list_taxonomies=列出可用分类法; list=列出某分类法下的 term; get=取单个 term; create=新建 term; update=更新 term; delete=删除 term(需确认)。taxonomy 默认 category(可填 post_tag 或自定义分类法)。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list_taxonomies', 'list', 'get', 'create', 'update', 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'      => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'taxonomy'    => array(
						'type'        => 'string',
						'description' => '分类法别名,默认 category。可填 post_tag、product_cat 或自定义。',
						'default'     => 'category',
					),
					'id'          => array(
						'type'        => 'integer',
						'description' => 'get/update/delete 的 term ID。',
					),
					'name'        => array(
						'type'        => 'string',
						'description' => 'create/update 的 term 名称。',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'create/update 的别名(可选)。',
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'create/update 的父 term ID(层级分类法)。',
					),
					'description' => array(
						'type'        => 'string',
						'description' => 'create/update 的描述。',
					),
					'search'      => array(
						'type'        => 'string',
						'description' => 'list 关键词。',
					),
					'hide_empty'  => array(
						'type'        => 'boolean',
						'description' => 'list 是否隐藏无文章的 term,默认 false。',
						'default'     => false,
					),
					'per_page'    => array(
						'type'    => 'integer',
						'default' => 50,
					),
					'page'        => array(
						'type'    => 'integer',
						'default' => 1,
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
		if ( 'list_taxonomies' === $action ) {
			return $this->list_taxonomies();
		}

		$taxonomy = isset( $args['taxonomy'] ) ? sanitize_key( $args['taxonomy'] ) : 'category';
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return new \WP_Error( 'wp_mcp_bad_taxonomy', '分类法不存在:' . $taxonomy );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_terms( $taxonomy, $args );
			case 'get':
				return $this->get_term_data( $taxonomy, $args );
			case 'create':
				return $this->create_term( $taxonomy, $args );
			case 'update':
				return $this->update_term( $taxonomy, $args );
			case 'delete':
				return $this->delete_term( $taxonomy, $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出可用分类法。
	 *
	 * @return array
	 */
	private function list_taxonomies() {
		$out = array();
		foreach ( get_taxonomies( array( 'show_ui' => true ), 'objects' ) as $tax ) {
			$out[] = array(
				'name'         => $tax->name,
				'label'        => $tax->label,
				'hierarchical' => (bool) $tax->hierarchical,
				'object_type'  => $tax->object_type,
			);
		}
		return array( 'taxonomies' => $out );
	}

	/**
	 * 列出 term。
	 *
	 * @param string $taxonomy 分类法。
	 * @param array  $args     入参。
	 * @return array
	 */
	private function list_terms( $taxonomy, $args ) {
		$per_page = min( 200, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 50 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$query = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => ! empty( $args['hide_empty'] ),
			'number'     => $per_page,
			'offset'     => ( $page - 1 ) * $per_page,
		);
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( $args['search'] );
		}

		$terms = get_terms( $query );
		$total = (int) wp_count_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => ! empty( $args['hide_empty'] ),
			)
		);

		$items = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$items[] = $this->summarize_term( $t );
			}
		}

		return array(
			'taxonomy' => $taxonomy,
			'items'    => $items,
			'page'     => $page,
			'per_page' => $per_page,
			'total'    => $total,
		);
	}

	/**
	 * 取单个 term。
	 *
	 * @param string $taxonomy 分类法。
	 * @param array  $args     入参。
	 * @return array|\WP_Error
	 */
	private function get_term_data( $taxonomy, $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$term = $id ? get_term( $id, $taxonomy ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到 term ID:' . $id );
		}
		$data            = $this->summarize_term( $term );
		$data['description'] = $term->description;
		return $data;
	}

	/**
	 * 创建 term。
	 *
	 * @param string $taxonomy 分类法。
	 * @param array  $args     入参。
	 * @return array|\WP_Error
	 */
	private function create_term( $taxonomy, $args ) {
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create 需要 name。' );
		}

		$blocked = $this->guard( 'create', $args, sprintf( '在 %s 创建 term「%s」', $taxonomy, $name ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_insert_term( $name, $taxonomy, $this->term_args( $args ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'created' => true, 'term' => $this->get_term_data( $taxonomy, array( 'id' => $result['term_id'] ) ) );
	}

	/**
	 * 更新 term。
	 *
	 * @param string $taxonomy 分类法。
	 * @param array  $args     入参。
	 * @return array|\WP_Error
	 */
	private function update_term( $taxonomy, $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$term = $id ? get_term( $id, $taxonomy ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到 term ID:' . $id );
		}

		$blocked = $this->guard( 'update', $args, sprintf( '更新 %s term #%d', $taxonomy, $id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$update = $this->term_args( $args );
		if ( isset( $args['name'] ) ) {
			$update['name'] = sanitize_text_field( $args['name'] );
		}

		$result = wp_update_term( $id, $taxonomy, $update );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'updated' => true, 'term' => $this->get_term_data( $taxonomy, array( 'id' => $id ) ) );
	}

	/**
	 * 删除 term。
	 *
	 * @param string $taxonomy 分类法。
	 * @param array  $args     入参。
	 * @return array|\WP_Error
	 */
	private function delete_term( $taxonomy, $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$term = $id ? get_term( $id, $taxonomy ) : null;
		if ( ! $term || is_wp_error( $term ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到 term ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, sprintf( '删除 %s term #%d「%s」', $taxonomy, $id, $term->name ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_delete_term( $id, $taxonomy );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_delete_failed', '删除失败(可能为默认 term 或不存在)。' );
		}

		return array( 'deleted' => true, 'id' => $id );
	}

	/**
	 * 组装 slug/parent/description 入参(create/update 共用)。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function term_args( $args ) {
		$out = array();
		if ( ! empty( $args['slug'] ) ) {
			$out['slug'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['parent'] ) ) {
			$out['parent'] = (int) $args['parent'];
		}
		if ( isset( $args['description'] ) ) {
			$out['description'] = wp_kses_post( $args['description'] );
		}
		return $out;
	}

	/**
	 * term 摘要。
	 *
	 * @param \WP_Term $term term。
	 * @return array
	 */
	private function summarize_term( $term ) {
		return array(
			'id'     => $term->term_id,
			'name'   => $term->name,
			'slug'   => $term->slug,
			'parent' => $term->parent,
			'count'  => $term->count,
		);
	}
}
