<?php
/**
 * wp_menus 工具:导航菜单管理(阶段 4)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 导航菜单与菜单项工具。
 */
class WP_Menus_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_menus';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '导航菜单:list=列出菜单; get=取某菜单的菜单项; create=建菜单; delete=删菜单(需确认); add_item=加菜单项(自定义链接或指向文章/页面/分类); update_item=改菜单项; delete_item=删菜单项; locations=列出主题导航位置及分配情况; assign_location=把菜单分配到某位置。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'create', 'delete', 'add_item', 'update_item', 'delete_item', 'locations', 'assign_location' );
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
					'action'       => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'id'           => array(
						'type'        => 'integer',
						'description' => 'get/delete/add_item 的菜单 ID。',
					),
					'name'         => array(
						'type'        => 'string',
						'description' => 'create 的菜单名称。',
					),
					'item_id'      => array(
						'type'        => 'integer',
						'description' => 'update_item/delete_item 的菜单项 ID。',
					),
					'title'        => array(
						'type'        => 'string',
						'description' => 'add_item/update_item 的显示标题。',
					),
					'url'          => array(
						'type'        => 'string',
						'description' => 'add_item/update_item 自定义链接 URL(type=custom 时)。',
					),
					'object_id'    => array(
						'type'        => 'integer',
						'description' => 'add_item 指向的对象 ID(文章/页面/分类 term ID)。',
					),
					'object'       => array(
						'type'        => 'string',
						'description' => 'add_item 对象类型:page/post/category/自定义,默认 custom。',
					),
					'item_type'    => array(
						'type'        => 'string',
						'description' => 'add_item 菜单项类型:custom/post_type/taxonomy,默认 custom。',
					),
					'parent_item'  => array(
						'type'        => 'integer',
						'description' => 'add_item/update_item 父菜单项 ID(做子菜单)。',
					),
					'location'     => array(
						'type'        => 'string',
						'description' => 'assign_location 的主题位置标识(见 locations)。',
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
			case 'list':
				return $this->list_menus();
			case 'get':
				return $this->get_menu( $args );
			case 'create':
				return $this->create_menu( $args );
			case 'delete':
				return $this->delete_menu( $args );
			case 'add_item':
				return $this->save_item( $args, 0 );
			case 'update_item':
				return $this->save_item( $args, (int) ( isset( $args['item_id'] ) ? $args['item_id'] : 0 ) );
			case 'delete_item':
				return $this->delete_item( $args );
			case 'locations':
				return $this->locations();
			case 'assign_location':
				return $this->assign_location( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出菜单。
	 *
	 * @return array
	 */
	private function list_menus() {
		$out = array();
		foreach ( wp_get_nav_menus() as $menu ) {
			$out[] = array(
				'id'    => $menu->term_id,
				'name'  => $menu->name,
				'slug'  => $menu->slug,
				'count' => $menu->count,
			);
		}
		return array( 'menus' => $out );
	}

	/**
	 * 取某菜单的菜单项。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_menu( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$menu = $id ? wp_get_nav_menu_object( $id ) : null;
		if ( ! $menu ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到菜单 ID:' . $id );
		}

		$items = wp_get_nav_menu_items( $id );
		$out   = array();
		if ( $items ) {
			foreach ( $items as $item ) {
				$out[] = array(
					'item_id'   => (int) $item->ID,
					'title'     => $item->title,
					'url'       => $item->url,
					'type'      => $item->type,
					'object'    => $item->object,
					'object_id' => (int) $item->object_id,
					'parent'    => (int) $item->menu_item_parent,
					'order'     => (int) $item->menu_order,
				);
			}
		}

		return array( 'id' => $id, 'name' => $menu->name, 'items' => $out );
	}

	/**
	 * 创建菜单。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_menu( $args ) {
		$name = isset( $args['name'] ) ? sanitize_text_field( $args['name'] ) : '';
		if ( '' === $name ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create 需要 name。' );
		}

		$blocked = $this->guard( 'create', $args, '创建菜单「' . $name . '」' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$id = wp_create_nav_menu( $name );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array( 'created' => true, 'id' => (int) $id, 'name' => $name );
	}

	/**
	 * 删除菜单。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_menu( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$menu = $id ? wp_get_nav_menu_object( $id ) : null;
		if ( ! $menu ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到菜单 ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, sprintf( '删除菜单 #%d「%s」', $id, $menu->name ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_delete_nav_menu( $id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 新增/更新菜单项。
	 *
	 * @param array $args    入参。
	 * @param int   $item_id 菜单项 ID(0=新增)。
	 * @return array|\WP_Error
	 */
	private function save_item( $args, $item_id ) {
		$menu_id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );

		if ( $item_id ) {
			// 更新:从已有菜单项推断所属菜单。
			$existing = get_post( $item_id );
			if ( ! $existing || 'nav_menu_item' !== $existing->post_type ) {
				return new \WP_Error( 'wp_mcp_not_found', '未找到菜单项 ID:' . $item_id );
			}
			if ( ! $menu_id ) {
				$terms   = wp_get_object_terms( $item_id, 'nav_menu' );
				$menu_id = ( $terms && ! is_wp_error( $terms ) ) ? (int) $terms[0]->term_id : 0;
			}
		}

		if ( ! $menu_id || ! wp_get_nav_menu_object( $menu_id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到菜单 ID:' . $menu_id );
		}

		$action  = $item_id ? 'update_item' : 'add_item';
		$blocked = $this->guard( $action, $args, sprintf( '%s菜单 #%d 的菜单项', $item_id ? '更新' : '新增', $menu_id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$type        = isset( $args['item_type'] ) ? sanitize_key( $args['item_type'] ) : 'custom';
		$item_fields = array(
			'menu-item-status' => 'publish',
			'menu-item-type'   => $type,
		);
		if ( isset( $args['title'] ) ) {
			$item_fields['menu-item-title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['url'] ) ) {
			$item_fields['menu-item-url'] = esc_url_raw( $args['url'] );
		}
		if ( isset( $args['object'] ) ) {
			$item_fields['menu-item-object'] = sanitize_key( $args['object'] );
		}
		if ( isset( $args['object_id'] ) ) {
			$item_fields['menu-item-object-id'] = (int) $args['object_id'];
		}
		if ( isset( $args['parent_item'] ) ) {
			$item_fields['menu-item-parent-id'] = (int) $args['parent_item'];
		}

		$result = wp_update_nav_menu_item( $menu_id, $item_id, $item_fields );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'saved'   => true,
			'menu_id' => $menu_id,
			'item_id' => (int) $result,
		);
	}

	/**
	 * 删除菜单项。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_item( $args ) {
		$item_id = (int) ( isset( $args['item_id'] ) ? $args['item_id'] : 0 );
		$item    = $item_id ? get_post( $item_id ) : null;
		if ( ! $item || 'nav_menu_item' !== $item->post_type ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到菜单项 ID:' . $item_id );
		}

		$blocked = $this->guard( 'delete_item', $args, sprintf( '删除菜单项 #%d', $item_id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_delete_post( $item_id, true );
		return array( 'deleted' => (bool) $result, 'item_id' => $item_id );
	}

	/**
	 * 列出主题导航位置及当前分配。
	 *
	 * @return array
	 */
	private function locations() {
		$registered = get_registered_nav_menus();
		$assigned   = get_nav_menu_locations();
		$out        = array();
		foreach ( $registered as $loc => $desc ) {
			$menu_id = isset( $assigned[ $loc ] ) ? (int) $assigned[ $loc ] : 0;
			$menu    = $menu_id ? wp_get_nav_menu_object( $menu_id ) : null;
			$out[]   = array(
				'location'    => $loc,
				'description' => $desc,
				'menu_id'     => $menu_id,
				'menu_name'   => $menu ? $menu->name : null,
			);
		}
		return array( 'locations' => $out );
	}

	/**
	 * 把菜单分配到主题位置。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function assign_location( $args ) {
		$location = isset( $args['location'] ) ? sanitize_key( $args['location'] ) : '';
		$menu_id  = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );

		$registered = get_registered_nav_menus();
		if ( '' === $location || ! isset( $registered[ $location ] ) ) {
			return new \WP_Error( 'wp_mcp_bad_location', '无效的导航位置:' . $location );
		}
		if ( $menu_id && ! wp_get_nav_menu_object( $menu_id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到菜单 ID:' . $menu_id );
		}

		$blocked = $this->guard( 'assign_location', $args, sprintf( '把菜单 #%d 分配到位置「%s」', $menu_id, $location ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$locations              = get_theme_mod( 'nav_menu_locations', array() );
		if ( ! is_array( $locations ) ) {
			$locations = array();
		}
		$locations[ $location ] = $menu_id;
		set_theme_mod( 'nav_menu_locations', $locations );

		return array( 'assigned' => true, 'location' => $location, 'menu_id' => $menu_id );
	}
}
