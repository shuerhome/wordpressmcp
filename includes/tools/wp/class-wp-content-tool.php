<?php
/**
 * wp_content 工具:文章/页面/CPT 的只读访问(阶段 1)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 内容(文章/页面/自定义文章类型)读取工具。
 *
 * 阶段 1 仅实现只读 action(list/get/revisions/types)。
 */
class WP_Content_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_content';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '管理文章/页面/CPT:list=列出; get=取完整内容; revisions=列出修订; types=列出文章类型; create=创建; update=更新(部分字段); delete=删除(默认回收站,force=true 永久删除,需确认); rollback=回滚到指定修订版本。写操作支持 dry_run 预演。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'revisions', 'types', 'create', 'update', 'delete', 'rollback' );
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
					'action'    => array(
						'type'        => 'string',
						'enum'        => $this->get_actions(),
						'description' => 'list/get/revisions/types',
					),
					'id'        => array(
						'type'        => 'integer',
						'description' => 'get / revisions 时的文章 ID',
					),
					'post_type' => array(
						'type'        => 'string',
						'description' => 'list 时的文章类型,默认 post(可填 page 或 CPT)',
						'default'     => 'post',
					),
					'status'    => array(
						'type'        => 'string',
						'description' => 'list 状态过滤:publish/draft/pending/private/any',
						'default'     => 'any',
					),
					'search'    => array(
						'type'        => 'string',
						'description' => 'list 关键词搜索',
					),
					'author'    => array(
						'type'        => 'integer',
						'description' => 'list 按作者 ID 过滤',
					),
					'per_page'  => array(
						'type'        => 'integer',
						'description' => 'list 每页条数,默认 20,最大 100',
						'default'     => 20,
					),
					'page'      => array(
						'type'        => 'integer',
						'description' => 'list 页码,默认 1',
						'default'     => 1,
					),
					'orderby'   => array(
						'type'        => 'string',
						'description' => 'date/modified/title/ID,默认 date',
						'default'     => 'date',
					),
					'order'     => array(
						'type'        => 'string',
						'enum'        => array( 'ASC', 'DESC' ),
						'default'     => 'DESC',
					),
					'title'       => array(
						'type'        => 'string',
						'description' => 'create/update 标题',
					),
					'content'     => array(
						'type'        => 'string',
						'description' => 'create/update 正文(可为古腾堡区块标记)',
					),
					'excerpt'     => array(
						'type'        => 'string',
						'description' => 'create/update 摘要',
					),
					'slug'        => array(
						'type'        => 'string',
						'description' => 'create/update 别名',
					),
					'parent'      => array(
						'type'        => 'integer',
						'description' => 'create/update 父级 ID(页面层级)',
					),
					'categories'  => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'create/update 分类(名称或 ID)',
					),
					'tags'        => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'create/update 标签(名称)',
					),
					'featured_media' => array(
						'type'        => 'integer',
						'description' => 'create/update 特色图媒体 ID',
					),
					'force'       => array(
						'type'        => 'boolean',
						'description' => 'delete 时 true=永久删除,false=移入回收站(默认)',
						'default'     => false,
					),
					'revision_id' => array(
						'type'        => 'integer',
						'description' => 'rollback 时的修订版本 ID',
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => '危险操作的二次确认令牌(由服务端签发)',
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
			case 'list':
				return $this->list_content( $args );
			case 'get':
				return $this->get_content( $args );
			case 'revisions':
				return $this->list_revisions( $args );
			case 'types':
				return $this->list_types();
			case 'create':
				return $this->create_content( $args );
			case 'update':
				return $this->update_content( $args );
			case 'delete':
				return $this->delete_content( $args );
			case 'rollback':
				return $this->rollback_content( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 创建内容。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_content( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		if ( ! post_type_exists( $post_type ) ) {
			return new \WP_Error( 'wp_mcp_bad_type', '文章类型不存在:' . $post_type );
		}

		$summary = sprintf( '创建 %s「%s」', $post_type, isset( $args['title'] ) ? $args['title'] : '(无标题)' );
		$blocked = $this->guard( 'create', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$postarr = array(
			'post_type'    => $post_type,
			'post_title'   => isset( $args['title'] ) ? wp_kses_post( $args['title'] ) : '',
			'post_content' => isset( $args['content'] ) ? $args['content'] : '',
			'post_excerpt' => isset( $args['excerpt'] ) ? $args['excerpt'] : '',
			'post_status'  => isset( $args['status'] ) && 'any' !== $args['status'] ? sanitize_key( $args['status'] ) : 'draft',
		);
		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['parent'] ) ) {
			$postarr['post_parent'] = (int) $args['parent'];
		}

		$id = wp_insert_post( $postarr, true );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		$this->apply_terms_and_media( $id, $args );

		return array( 'created' => true, 'post' => $this->get_content( array( 'id' => $id ) ) );
	}

	/**
	 * 更新内容(部分字段)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_content( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || ! get_post( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章 ID:' . $id );
		}

		$summary = sprintf( '更新文章 #%d', $id );
		$blocked = $this->guard( 'update', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$postarr = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = wp_kses_post( $args['title'] );
		}
		if ( isset( $args['content'] ) ) {
			$postarr['post_content'] = $args['content'];
		}
		if ( isset( $args['excerpt'] ) ) {
			$postarr['post_excerpt'] = $args['excerpt'];
		}
		if ( isset( $args['status'] ) && 'any' !== $args['status'] ) {
			$postarr['post_status'] = sanitize_key( $args['status'] );
		}
		if ( ! empty( $args['slug'] ) ) {
			$postarr['post_name'] = sanitize_title( $args['slug'] );
		}
		if ( isset( $args['parent'] ) ) {
			$postarr['post_parent'] = (int) $args['parent'];
		}

		$result = wp_update_post( $postarr, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->apply_terms_and_media( $id, $args );

		return array( 'updated' => true, 'post' => $this->get_content( array( 'id' => $id ) ) );
	}

	/**
	 * 删除内容(回收站或永久)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_content( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || ! get_post( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章 ID:' . $id );
		}

		$force   = ! empty( $args['force'] );
		$summary = sprintf( '%s文章 #%d「%s」', $force ? '永久删除' : '移入回收站:', $id, get_the_title( $id ) );

		$blocked = $this->guard( 'delete', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = $force ? wp_delete_post( $id, true ) : wp_trash_post( $id );
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_delete_failed', '删除失败' );
		}

		return array( 'deleted' => true, 'id' => $id, 'force' => $force );
	}

	/**
	 * 回滚到指定修订版本。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function rollback_content( $args ) {
		$revision_id = (int) ( isset( $args['revision_id'] ) ? $args['revision_id'] : 0 );
		$revision    = $revision_id ? wp_get_post_revision( $revision_id ) : null;
		if ( ! $revision ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到修订版本 ID:' . $revision_id );
		}

		$summary = sprintf( '将文章 #%d 回滚到修订版本 #%d', $revision->post_parent, $revision_id );
		$blocked = $this->guard( 'rollback', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_restore_post_revision( $revision_id );
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_rollback_failed', '回滚失败' );
		}

		return array( 'rolled_back' => true, 'post' => $this->get_content( array( 'id' => $revision->post_parent ) ) );
	}

	/**
	 * 应用分类/标签/特色图。
	 *
	 * @param int   $id   文章 ID。
	 * @param array $args 入参。
	 */
	private function apply_terms_and_media( $id, $args ) {
		if ( isset( $args['categories'] ) && is_array( $args['categories'] ) ) {
			$cat_ids = array();
			foreach ( $args['categories'] as $cat ) {
				if ( is_numeric( $cat ) ) {
					$cat_ids[] = (int) $cat;
				} else {
					$term = term_exists( $cat, 'category' );
					if ( ! $term ) {
						$term = wp_insert_term( $cat, 'category' );
					}
					if ( ! is_wp_error( $term ) ) {
						$cat_ids[] = (int) $term['term_id'];
					}
				}
			}
			wp_set_post_categories( $id, $cat_ids );
		}

		if ( isset( $args['tags'] ) && is_array( $args['tags'] ) ) {
			wp_set_post_tags( $id, $args['tags'] );
		}

		if ( isset( $args['featured_media'] ) ) {
			set_post_thumbnail( $id, (int) $args['featured_media'] );
		}
	}

	/**
	 * 列出内容。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_content( $args ) {
		$post_type = isset( $args['post_type'] ) ? sanitize_key( $args['post_type'] ) : 'post';
		if ( ! post_type_exists( $post_type ) ) {
			return array( 'error' => '文章类型不存在:' . $post_type );
		}

		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$query_args = array(
			'post_type'      => $post_type,
			'post_status'    => isset( $args['status'] ) ? $args['status'] : 'any',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'date',
			'order'          => ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}
		if ( ! empty( $args['author'] ) ) {
			$query_args['author'] = (int) $args['author'];
		}

		$query = new \WP_Query( $query_args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->summarize_post( $post );
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $query->found_posts,
			'total_pages' => (int) $query->max_num_pages,
		);
	}

	/**
	 * 取单条完整内容。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_content( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章 ID:' . $id );
		}

		$data                 = $this->summarize_post( $post );
		$data['content']      = $post->post_content;
		$data['content_html'] = apply_filters( 'the_content', $post->post_content );
		$data['parent']       = (int) $post->post_parent;
		$data['menu_order']   = (int) $post->menu_order;
		$data['comment_status'] = $post->comment_status;
		$data['featured_media'] = (int) get_post_thumbnail_id( $post->ID );
		$data['categories']   = wp_get_post_categories( $post->ID, array( 'fields' => 'names' ) );
		$data['tags']         = wp_get_post_tags( $post->ID, array( 'fields' => 'names' ) );

		return $data;
	}

	/**
	 * 列出修订版本。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function list_revisions( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || ! get_post( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章 ID:' . $id );
		}

		$revisions = wp_get_post_revisions( $id );
		$out       = array();
		foreach ( $revisions as $rev ) {
			$out[] = array(
				'id'       => $rev->ID,
				'date'     => $rev->post_modified,
				'author'   => (int) $rev->post_author,
				'title'    => $rev->post_title,
			);
		}

		return array( 'post_id' => $id, 'revisions' => $out );
	}

	/**
	 * 列出可用文章类型。
	 *
	 * @return array
	 */
	private function list_types() {
		$types = get_post_types( array( 'show_ui' => true ), 'objects' );
		$out   = array();
		foreach ( $types as $type ) {
			$out[] = array(
				'name'   => $type->name,
				'label'  => $type->label,
				'public' => (bool) $type->public,
			);
		}
		return array( 'types' => $out );
	}

	/**
	 * 内容摘要(列表用)。
	 *
	 * @param \WP_Post $post 文章。
	 * @return array
	 */
	private function summarize_post( $post ) {
		return array(
			'id'       => $post->ID,
			'type'     => $post->post_type,
			'title'    => get_the_title( $post ),
			'status'   => $post->post_status,
			'slug'     => $post->post_name,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => (int) $post->post_author,
			'link'     => get_permalink( $post ),
			'excerpt'  => wp_strip_all_tags( get_the_excerpt( $post ) ),
		);
	}
}
