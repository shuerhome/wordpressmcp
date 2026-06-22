<?php
/**
 * wp_elementor 工具:精修 Elementor 页面(阶段 5)。
 *
 * Elementor 把页面布局存在 post meta `_elementor_data`(一棵 JSON 元素树:
 * section/column/container/widget,每个元素有 id / elType / widgetType / settings / elements)。
 * 本工具读取这棵树、按 element id 精确改某个元素的设置(文字/图片/链接/显隐)、增删移板块,
 * 或整树替换。所有写操作改动前自动快照旧的 `_elementor_data`,可用 rollback 还原;写后重生成
 * Elementor 的 CSS 缓存,否则改动不显示。
 *
 * 仅在检测到 Elementor 时由 Registry 注册。第一版聚焦「精修现有页面」,不做从零生成整页。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\Backup;

/**
 * Elementor 页面编辑工具。
 */
class WP_Elementor_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_elementor';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return 'Elementor 页面精修。list_pages=列出用 Elementor 搭建的页面/文章; get=把某页的元素树解析为可读结构(含每个元素的 id/类型/文字预览); get_raw=取某个元素(或整页)的完整原始 settings 以便精确改; find=按文字或 widget 类型定位元素返回其 id; update_element=按 element id 深合并改该元素的 settings(改标题/文案/按钮链接/图片/显隐); insert_element=往某父元素下插入新元素; move_element=移动元素到新位置; delete_element=删除元素; update_data=整树替换(高危); backups=列出快照; rollback=按快照 ID 还原整页。所有写操作改动前自动快照、写后重生成 CSS;支持 dry_run 预演;delete_element/update_data/rollback 为危险操作需二次确认。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array(
			'list_pages',
			'get',
			'get_raw',
			'find',
			'update_element',
			'insert_element',
			'move_element',
			'delete_element',
			'update_data',
			'backups',
			'rollback',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'delete_element', 'update_data', 'rollback' );
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
					'post_id'       => array(
						'type'        => 'integer',
						'description' => '目标页面/文章 ID(get/get_raw/find/各写操作必填)。用 list_pages 找 ID。',
					),
					'element_id'    => array(
						'type'        => 'string',
						'description' => 'Elementor 元素 id(7-8 位十六进制,来自 get/find)。update_element/get_raw/move_element/delete_element 用;insert_element 时为父元素 id(留空=插到页面顶层)。',
					),
					'settings'      => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'update_element:要深合并进该元素 settings 的键值(如 {"title":"新标题"}、{"link":{"url":"https://…"}}、{"editor":"<p>新文案</p>"})。',
					),
					'element'       => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'insert_element:要插入的新元素对象(至少含 elType;widget 还需 widgetType 与 settings)。缺 id 会自动生成。',
					),
					'position'      => array(
						'type'        => 'integer',
						'description' => 'insert_element/move_element:在目标父元素子列表中的插入位置(0 起;省略=末尾)。',
					),
					'target_id'     => array(
						'type'        => 'string',
						'description' => 'move_element:目标父元素 id(留空=移到页面顶层)。',
					),
					'elements'      => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'object' ),
						'description' => 'update_data:整页新的元素树(高危,会整体替换)。',
					),
					'query'         => array(
						'type'        => 'string',
						'description' => 'find:要匹配的文字片段(在标题/文案/按钮文字等里搜)。',
					),
					'widget_type'   => array(
						'type'        => 'string',
						'description' => 'find:按 widget 类型过滤(如 heading/button/image/text-editor)。',
					),
					'backup_id'     => array(
						'type'        => 'string',
						'description' => 'rollback 的快照 ID(来自 backups)。',
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => '危险操作的二次确认令牌(由服务端签发)。',
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
		// 除 backups 外都需要 Elementor 在场。
		if ( 'backups' !== $action ) {
			$ok = $this->ensure_elementor();
			if ( is_wp_error( $ok ) ) {
				return $ok;
			}
		}

		switch ( $action ) {
			case 'list_pages':
				return $this->list_pages( $args );
			case 'get':
				return $this->get_page( $args );
			case 'get_raw':
				return $this->get_raw( $args );
			case 'find':
				return $this->find( $args );
			case 'update_element':
				return $this->update_element( $args );
			case 'insert_element':
				return $this->insert_element( $args );
			case 'move_element':
				return $this->move_element( $args );
			case 'delete_element':
				return $this->delete_element( $args );
			case 'update_data':
				return $this->update_data( $args );
			case 'backups':
				return array( 'backups' => Backup::all() );
			case 'rollback':
				return $this->rollback( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/* ---------------------------------------------------------------------
	 * 读
	 * ------------------------------------------------------------------- */

	/**
	 * 列出用 Elementor 搭建的页面/文章。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_pages( $args ) {
		$posts = get_posts(
			array(
				'post_type'      => array( 'page', 'post' ),
				'post_status'    => array( 'publish', 'draft', 'private' ),
				'posts_per_page' => 100,
				'meta_key'       => '_elementor_edit_mode', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => 'builder', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'modified',
				'order'          => 'DESC',
			)
		);

		$out = array();
		foreach ( $posts as $p ) {
			$out[] = array(
				'id'       => $p->ID,
				'title'    => $p->post_title,
				'type'     => $p->post_type,
				'status'   => $p->post_status,
				'modified' => $p->post_modified,
				'link'     => get_permalink( $p ),
			);
		}

		return array( 'pages' => $out );
	}

	/**
	 * 把某页解析为可读元素树。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_page( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$elements = $this->read_elements( $post->ID );

		return array(
			'post_id'  => $post->ID,
			'title'    => $post->post_title,
			'tree'     => $this->simplify( $elements ),
		);
	}

	/**
	 * 取某个元素(或整页)的完整原始 settings。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_raw( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$elements = $this->read_elements( $post->ID );
		$eid      = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';

		if ( '' === $eid ) {
			return array(
				'post_id'  => $post->ID,
				'elements' => $elements,
			);
		}

		$el = $this->get_element( $elements, $eid );
		if ( null === $el ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到元素 id:' . $eid );
		}

		return array(
			'post_id' => $post->ID,
			'element' => $el,
		);
	}

	/**
	 * 按文字片段 / widget 类型定位元素。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function find( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}

		$query  = isset( $args['query'] ) ? trim( (string) $args['query'] ) : '';
		$widget = isset( $args['widget_type'] ) ? trim( (string) $args['widget_type'] ) : '';
		if ( '' === $query && '' === $widget ) {
			return new \WP_Error( 'wp_mcp_bad_args', '请至少提供 query 或 widget_type 之一。' );
		}

		$elements = $this->read_elements( $post->ID );
		$matches  = array();
		$this->walk(
			$elements,
			function ( $el ) use ( $query, $widget, &$matches ) {
				$type    = isset( $el['elType'] ) ? $el['elType'] : '';
				$wtype   = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
				$preview = ( 'widget' === $type ) ? $this->widget_preview( $wtype, isset( $el['settings'] ) ? $el['settings'] : array() ) : '';

				$hit = true;
				if ( '' !== $widget && $wtype !== $widget ) {
					$hit = false;
				}
				if ( $hit && '' !== $query ) {
					if ( false === strpos( $this->lc( $preview ), $this->lc( $query ) ) ) {
						$hit = false;
					}
				}
				if ( $hit ) {
					$matches[] = array(
						'id'      => isset( $el['id'] ) ? $el['id'] : '',
						'type'    => $type,
						'widget'  => $wtype,
						'preview' => $preview,
					);
				}
			}
		);

		return array(
			'post_id' => $post->ID,
			'matches' => $matches,
		);
	}

	/* ---------------------------------------------------------------------
	 * 写
	 * ------------------------------------------------------------------- */

	/**
	 * 按 element id 深合并改某元素的 settings。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_element( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$eid = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		if ( '' === $eid ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'element_id 必填。' );
		}
		if ( ! isset( $args['settings'] ) || ! is_array( $args['settings'] ) || empty( $args['settings'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'settings 必填,且为非空对象。' );
		}

		$elements = $this->read_elements( $post->ID );
		if ( null === $this->get_element( $elements, $eid ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到元素 id:' . $eid );
		}

		$summary = sprintf( '改 Elementor 元素 %s 的 settings:%s(页 #%d)', $eid, implode( ', ', array_keys( $args['settings'] ) ), $post->ID );
		$blocked = $this->guard( 'update_element', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$found    = false;
		$override = $args['settings'];
		$elements = $this->map_element(
			$elements,
			$eid,
			function ( $el ) use ( $override ) {
				$el['settings'] = $this->deep_merge( isset( $el['settings'] ) ? $el['settings'] : array(), $override );
				return $el;
			},
			$found
		);

		return $this->commit( $post->ID, $elements, $summary, array( 'updated' => true, 'element_id' => $eid ) );
	}

	/**
	 * 往某父元素下插入新元素。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function insert_element( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! isset( $args['element'] ) || ! is_array( $args['element'] ) || empty( $args['element'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'element 必填(要插入的元素对象)。' );
		}

		$parent_id = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		$position  = isset( $args['position'] ) ? (int) $args['position'] : null;
		$new_el    = $this->ensure_ids( array( $args['element'] ) );
		$new_el    = $new_el[0];

		$elements = $this->read_elements( $post->ID );
		if ( '' !== $parent_id && null === $this->get_element( $elements, $parent_id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到父元素 id:' . $parent_id );
		}

		$summary = sprintf( '在 Elementor %s 下插入新元素(页 #%d)', '' === $parent_id ? '页面顶层' : ( '元素 ' . $parent_id ), $post->ID );
		$blocked = $this->guard( 'insert_element', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$elements = $this->insert_into( $elements, $parent_id, $new_el, $position );

		return $this->commit( $post->ID, $elements, $summary, array( 'inserted' => true, 'element_id' => $new_el['id'] ) );
	}

	/**
	 * 移动元素到新父元素/位置。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function move_element( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$eid = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		if ( '' === $eid ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'element_id 必填。' );
		}

		$elements = $this->read_elements( $post->ID );
		$node     = $this->get_element( $elements, $eid );
		if ( null === $node ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到元素 id:' . $eid );
		}

		$target   = isset( $args['target_id'] ) ? (string) $args['target_id'] : '';
		$position = isset( $args['position'] ) ? (int) $args['position'] : null;
		if ( '' !== $target && null === $this->get_element( $elements, $target ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到目标父元素 id:' . $target );
		}
		if ( $target === $eid ) {
			return new \WP_Error( 'wp_mcp_bad_args', '不能把元素移动到它自己下面。' );
		}

		$summary = sprintf( '移动 Elementor 元素 %s 到 %s(页 #%d)', $eid, '' === $target ? '页面顶层' : ( '元素 ' . $target ), $post->ID );
		$blocked = $this->guard( 'move_element', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$removed  = false;
		$elements = $this->remove_element( $elements, $eid, $removed );
		$elements = $this->insert_into( $elements, $target, $node, $position );

		// 后置校验:若移动后元素不见了(常见于把元素移进它自己的子树),不写入、报错。
		if ( null === $this->get_element( $elements, $eid ) ) {
			return new \WP_Error( 'wp_mcp_move_failed', '移动失败:目标位置无效(目标可能在被移动元素内部)。未做任何改动。' );
		}

		return $this->commit( $post->ID, $elements, $summary, array( 'moved' => true, 'element_id' => $eid ) );
	}

	/**
	 * 删除元素。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_element( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		$eid = isset( $args['element_id'] ) ? (string) $args['element_id'] : '';
		if ( '' === $eid ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'element_id 必填。' );
		}

		$elements = $this->read_elements( $post->ID );
		if ( null === $this->get_element( $elements, $eid ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到元素 id:' . $eid );
		}

		$summary = sprintf( '删除 Elementor 元素 %s(页 #%d)', $eid, $post->ID );
		$blocked = $this->guard( 'delete_element', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$removed  = false;
		$elements = $this->remove_element( $elements, $eid, $removed );

		return $this->commit( $post->ID, $elements, $summary, array( 'deleted' => true, 'element_id' => $eid ) );
	}

	/**
	 * 整树替换(高危)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_data( $args ) {
		$post = $this->resolve_post( $args );
		if ( is_wp_error( $post ) ) {
			return $post;
		}
		if ( ! isset( $args['elements'] ) || ! is_array( $args['elements'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'elements 必填(整页新的元素树数组)。' );
		}

		$summary = sprintf( '整树替换 Elementor 页面 #%d 的内容', $post->ID );
		$blocked = $this->guard( 'update_data', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$elements = $this->ensure_ids( $args['elements'] );

		return $this->commit( $post->ID, $elements, $summary, array( 'replaced' => true ) );
	}

	/**
	 * 按快照 ID 还原整页 `_elementor_data`。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function rollback( $args ) {
		$id = isset( $args['backup_id'] ) ? (string) $args['backup_id'] : '';
		$bk = $id ? Backup::get( $id ) : null;
		if ( ! $bk ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到快照:' . $id );
		}
		$bk_type = isset( $bk['type'] ) ? $bk['type'] : '';
		if ( 'elementor_data' !== $bk_type ) {
			return new \WP_Error( 'wp_mcp_rollback_unsupported', '该快照不是 Elementor 类型,请用对应工具回滚:' . $bk_type );
		}

		$summary = sprintf( '回滚 Elementor 快照 %s(%s)', $id, isset( $bk['label'] ) ? $bk['label'] : '' );
		$blocked = $this->guard( 'rollback', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$data    = isset( $bk['data'] ) ? $bk['data'] : array();
		$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
		if ( ! $post_id || ! get_post( $post_id ) ) {
			return new \WP_Error( 'wp_mcp_rollback_failed', '快照对应的页面已不存在。' );
		}

		$elements = isset( $data['elements'] ) && is_array( $data['elements'] ) ? $data['elements'] : array();
		$this->write_elements( $post_id, $elements );
		$this->regen_css( $post_id );

		return array(
			'rolled_back' => true,
			'backup_id'   => $id,
			'post_id'     => $post_id,
		);
	}

	/* ---------------------------------------------------------------------
	 * 提交(快照 + 写入 + 重生成 CSS)
	 * ------------------------------------------------------------------- */

	/**
	 * 落盘:先快照旧值,再写入新树,再重生成 CSS。
	 *
	 * @param int    $post_id  页面 ID。
	 * @param array  $elements 新的元素树。
	 * @param string $summary  操作摘要(用于快照标签)。
	 * @param array  $extra    要并入返回值的附加字段。
	 * @return array
	 */
	private function commit( $post_id, $elements, $summary, $extra ) {
		$backup_id = Backup::snapshot(
			'elementor_data',
			$summary,
			array(
				'post_id'  => $post_id,
				'elements' => $this->read_elements( $post_id ), // 旧值。
			)
		);

		$this->write_elements( $post_id, $elements );
		$this->regen_css( $post_id );

		return array_merge(
			$extra,
			array(
				'post_id'   => $post_id,
				'backup_id' => $backup_id,
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Elementor 数据读写底层
	 * ------------------------------------------------------------------- */

	/**
	 * 读取并解析某页的 `_elementor_data`。
	 *
	 * @param int $post_id 页面 ID。
	 * @return array
	 */
	private function read_elements( $post_id ) {
		$raw = get_post_meta( $post_id, '_elementor_data', true );
		if ( is_array( $raw ) ) {
			return $raw; // 个别环境已被解析。
		}
		if ( is_string( $raw ) && '' !== $raw ) {
			$data = json_decode( $raw, true );
			if ( is_array( $data ) ) {
				return $data;
			}
		}
		return array();
	}

	/**
	 * 写回某页的 `_elementor_data`(用 wp_slash 抵消 update_post_meta 的 unslash)。
	 *
	 * @param int   $post_id  页面 ID。
	 * @param array $elements 元素树。
	 */
	private function write_elements( $post_id, $elements ) {
		update_post_meta( $post_id, '_elementor_data', wp_slash( wp_json_encode( $elements ) ) );
		update_post_meta( $post_id, '_elementor_edit_mode', 'builder' );
		if ( defined( 'ELEMENTOR_VERSION' ) ) {
			update_post_meta( $post_id, '_elementor_version', ELEMENTOR_VERSION );
		}
	}

	/**
	 * 重生成该页的 Elementor CSS,并清全局缓存(否则改动不显示)。
	 *
	 * @param int $post_id 页面 ID。
	 */
	private function regen_css( $post_id ) {
		if ( class_exists( '\Elementor\Core\Files\CSS\Post' ) ) {
			try {
				\Elementor\Core\Files\CSS\Post::create( $post_id )->update();
			} catch ( \Throwable $e ) {
				// CSS 重生成失败不致命:数据已写入,清缓存后前台访问会再生。
			}
		}
		if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
			\Elementor\Plugin::$instance->files_manager->clear_cache();
		}
	}

	/* ---------------------------------------------------------------------
	 * 元素树遍历/改写工具
	 * ------------------------------------------------------------------- */

	/**
	 * 递归找出 id 匹配的元素(只读返回副本)。
	 *
	 * @param array  $elements 元素树。
	 * @param string $id       元素 id。
	 * @return array|null
	 */
	private function get_element( $elements, $id ) {
		foreach ( $elements as $el ) {
			if ( isset( $el['id'] ) && $el['id'] === $id ) {
				return $el;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$found = $this->get_element( $el['elements'], $id );
				if ( null !== $found ) {
					return $found;
				}
			}
		}
		return null;
	}

	/**
	 * 递归遍历每个元素并回调。
	 *
	 * @param array    $elements 元素树。
	 * @param callable $fn       回调(收到元素副本)。
	 */
	private function walk( $elements, callable $fn ) {
		foreach ( $elements as $el ) {
			$fn( $el );
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$this->walk( $el['elements'], $fn );
			}
		}
	}

	/**
	 * 递归把 id 匹配的元素经 $fn 改写后写回(不可变风格)。
	 *
	 * @param array    $elements 元素树。
	 * @param string   $id       目标 id。
	 * @param callable $fn       收到元素、返回改写后的元素。
	 * @param bool     $found    是否已命中(引用)。
	 * @return array
	 */
	private function map_element( $elements, $id, callable $fn, &$found ) {
		foreach ( $elements as $i => $el ) {
			if ( $found ) {
				break;
			}
			if ( isset( $el['id'] ) && $el['id'] === $id ) {
				$elements[ $i ] = $fn( $el );
				$found          = true;
				return $elements;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$elements[ $i ]['elements'] = $this->map_element( $el['elements'], $id, $fn, $found );
			}
		}
		return $elements;
	}

	/**
	 * 递归删除 id 匹配的元素。
	 *
	 * @param array  $elements 元素树。
	 * @param string $id       目标 id。
	 * @param bool   $removed  是否已删除(引用)。
	 * @return array
	 */
	private function remove_element( $elements, $id, &$removed ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! $removed && isset( $el['id'] ) && $el['id'] === $id ) {
				$removed = true;
				continue;
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = $this->remove_element( $el['elements'], $id, $removed );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * 把元素插入到指定父元素(空=顶层)的子列表的指定位置。
	 *
	 * @param array    $elements  元素树。
	 * @param string   $parent_id 父元素 id(空=顶层)。
	 * @param array    $new_el    要插入的元素。
	 * @param int|null $position  位置(null=末尾)。
	 * @return array
	 */
	private function insert_into( $elements, $parent_id, $new_el, $position ) {
		if ( '' === $parent_id ) {
			return $this->splice_in( $elements, $new_el, $position );
		}

		$found = false;
		return $this->map_element(
			$elements,
			$parent_id,
			function ( $el ) use ( $new_el, $position ) {
				$children       = ( isset( $el['elements'] ) && is_array( $el['elements'] ) ) ? $el['elements'] : array();
				$el['elements'] = $this->splice_in( $children, $new_el, $position );
				return $el;
			},
			$found
		);
	}

	/**
	 * 在列表的指定位置插入一项。
	 *
	 * @param array    $list     列表。
	 * @param mixed    $item     项。
	 * @param int|null $position 位置(null/越界=末尾)。
	 * @return array
	 */
	private function splice_in( $list, $item, $position ) {
		if ( null === $position || $position < 0 || $position >= count( $list ) ) {
			$list[] = $item;
			return $list;
		}
		array_splice( $list, $position, 0, array( $item ) );
		return $list;
	}

	/**
	 * 递归为缺 id 的元素生成 id(插入/整树替换时用)。
	 *
	 * @param array $elements 元素树。
	 * @return array
	 */
	private function ensure_ids( $elements ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			if ( empty( $el['id'] ) ) {
				$el['id'] = $this->new_id();
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$el['elements'] = $this->ensure_ids( $el['elements'] );
			}
			$out[] = $el;
		}
		return $out;
	}

	/**
	 * 生成一个 Elementor 风格的 8 位十六进制元素 id。
	 *
	 * @return string
	 */
	private function new_id() {
		return substr( md5( uniqid( (string) wp_rand(), true ) ), 0, 8 );
	}

	/* ---------------------------------------------------------------------
	 * 展示/通用
	 * ------------------------------------------------------------------- */

	/**
	 * 把元素树简化为可读结构(id + 类型 + 文字预览 + 子级)。
	 *
	 * @param array $elements 元素树。
	 * @return array
	 */
	private function simplify( $elements ) {
		$out = array();
		foreach ( $elements as $el ) {
			if ( ! is_array( $el ) ) {
				continue;
			}
			$type = isset( $el['elType'] ) ? $el['elType'] : '?';
			$row  = array(
				'id'   => isset( $el['id'] ) ? $el['id'] : '',
				'type' => $type,
			);
			if ( 'widget' === $type ) {
				$row['widget']  = isset( $el['widgetType'] ) ? $el['widgetType'] : '';
				$preview        = $this->widget_preview( $row['widget'], isset( $el['settings'] ) ? $el['settings'] : array() );
				if ( '' !== $preview ) {
					$row['preview'] = $preview;
				}
			}
			if ( ! empty( $el['elements'] ) && is_array( $el['elements'] ) ) {
				$row['children'] = $this->simplify( $el['elements'] );
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * 从 widget settings 里抽一段可读文字预览。
	 *
	 * @param string $widget   widget 类型。
	 * @param array  $settings 元素 settings。
	 * @return string
	 */
	private function widget_preview( $widget, $settings ) {
		if ( ! is_array( $settings ) ) {
			return '';
		}
		$keys = array( 'title', 'editor', 'text', 'title_text', 'description_text', 'heading', 'caption', 'html', 'tab_title' );
		foreach ( $keys as $k ) {
			if ( isset( $settings[ $k ] ) && is_string( $settings[ $k ] ) ) {
				$txt = trim( wp_strip_all_tags( $settings[ $k ] ) );
				if ( '' !== $txt ) {
					return $this->cut( $txt, 100 );
				}
			}
		}
		if ( isset( $settings['image']['url'] ) && is_string( $settings['image']['url'] ) ) {
			return '[image] ' . $settings['image']['url'];
		}
		return '';
	}

	/**
	 * 解析并校验 post_id 对应的文章。
	 *
	 * @param array $args 入参。
	 * @return \WP_Post|\WP_Error
	 */
	private function resolve_post( $args ) {
		$id   = isset( $args['post_id'] ) ? (int) $args['post_id'] : 0;
		$post = $id ? get_post( $id ) : null;
		if ( ! $post ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到文章/页面 ID:' . $id . '(用 list_pages 查可编辑的 Elementor 页面)' );
		}
		return $post;
	}

	/**
	 * 确认 Elementor 在场。
	 *
	 * @return true|\WP_Error
	 */
	private function ensure_elementor() {
		if ( ! defined( 'ELEMENTOR_VERSION' ) ) {
			return new \WP_Error( 'wp_mcp_no_elementor', '本站未启用 Elementor,wp_elementor 不可用。' );
		}
		return true;
	}

	/**
	 * 深合并:关联数组按键递归合并;标量与列表整体覆盖。
	 *
	 * @param mixed $base     原值。
	 * @param mixed $override 覆盖值。
	 * @return mixed
	 */
	private function deep_merge( $base, $override ) {
		if ( ! is_array( $base ) || ! is_array( $override ) ) {
			return $override;
		}
		if ( $this->is_list( $base ) || $this->is_list( $override ) ) {
			return $override;
		}
		foreach ( $override as $key => $value ) {
			$base[ $key ] = array_key_exists( $key, $base ) ? $this->deep_merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/**
	 * 是否为连续数字索引的列表数组。
	 *
	 * @param array $arr 数组。
	 * @return bool
	 */
	private function is_list( $arr ) {
		if ( array() === $arr ) {
			return false;
		}
		return array_keys( $arr ) === range( 0, count( $arr ) - 1 );
	}

	/**
	 * 小写化(mbstring 缺失时退回 strtolower),与 class-pii 的防御写法一致。
	 *
	 * @param string $s 字符串。
	 * @return string
	 */
	private function lc( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $s ) : strtolower( (string) $s );
	}

	/**
	 * 截断到指定长度(mbstring 缺失时退回 substr)。
	 *
	 * @param string $s   字符串。
	 * @param int    $len 长度。
	 * @return string
	 */
	private function cut( $s, $len ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, 0, $len ) : substr( (string) $s, 0, $len );
	}
}
