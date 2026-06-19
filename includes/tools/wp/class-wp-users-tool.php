<?php
/**
 * wp_users 工具:用户与角色管理(阶段 4),邮箱默认脱敏。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\PII;

/**
 * 用户增删改查与角色工具。
 */
class WP_Users_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_users';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '用户管理:list=列出/搜索; get=取详情; create=创建; update=更新资料; set_role=改角色; delete=删除(需确认,可 reassign 把内容转给他人)。邮箱默认脱敏(reveal_pii=true 解锁)。受当前绑定用户能力限制。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'create', 'update', 'set_role', 'delete' );
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
						'description' => 'get/update/set_role/delete 的用户 ID。',
					),
					'search'       => array(
						'type'        => 'string',
						'description' => 'list 关键词(登录名/邮箱/昵称)。',
					),
					'role'         => array(
						'type'        => 'string',
						'description' => 'list 按角色过滤;create 的初始角色。',
					),
					'role_to'      => array(
						'type'        => 'string',
						'description' => 'set_role 的目标角色(如 editor/author/subscriber)。',
					),
					'username'     => array(
						'type'        => 'string',
						'description' => 'create 的登录名(必填)。',
					),
					'email'        => array(
						'type'        => 'string',
						'description' => 'create/update 的邮箱。',
					),
					'password'     => array(
						'type'        => 'string',
						'description' => 'create/update 的密码(create 留空则自动生成强密码)。',
					),
					'display_name' => array(
						'type'        => 'string',
						'description' => 'create/update 的显示名。',
					),
					'first_name'   => array(
						'type'        => 'string',
						'description' => 'create/update 的名。',
					),
					'last_name'    => array(
						'type'        => 'string',
						'description' => 'create/update 的姓。',
					),
					'reassign'     => array(
						'type'        => 'integer',
						'description' => 'delete 时把该用户的内容转交给此用户 ID(留空则一并删除内容)。',
					),
					'per_page'     => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'         => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'reveal_pii'   => array(
						'type'        => 'boolean',
						'description' => '解锁用户邮箱(默认脱敏)。',
						'default'     => false,
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
				return $this->list_users( $args );
			case 'get':
				return $this->get_user( $args );
			case 'create':
				return $this->create_user( $args );
			case 'update':
				return $this->update_user( $args );
			case 'set_role':
				return $this->set_role( $args );
			case 'delete':
				return $this->delete_user( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出用户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function list_users( $args ) {
		if ( ! current_user_can( 'list_users' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无列出用户权限(list_users)。' );
		}

		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$mask     = PII::should_mask( $args );

		$query = array(
			'number' => $per_page,
			'paged'  => $page,
			'fields' => 'all',
		);
		if ( ! empty( $args['role'] ) ) {
			$query['role'] = sanitize_key( $args['role'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$query['search']         = '*' . sanitize_text_field( $args['search'] ) . '*';
			$query['search_columns'] = array( 'user_login', 'user_email', 'user_nicename', 'display_name' );
		}

		$user_query = new \WP_User_Query( $query );
		$items      = array();
		foreach ( $user_query->get_results() as $user ) {
			$items[] = $this->summarize_user( $user, $mask );
		}

		return array(
			'items'      => $items,
			'page'       => $page,
			'per_page'   => $per_page,
			'total'      => (int) $user_query->get_total(),
			'pii_masked' => $mask,
		);
	}

	/**
	 * 取单个用户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_user( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$user = $id ? get_userdata( $id ) : null;
		if ( ! $user ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到用户 ID:' . $id );
		}
		if ( ! current_user_can( 'list_users' ) && get_current_user_id() !== $id ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无查看该用户权限。' );
		}

		$mask               = PII::should_mask( $args );
		$data               = $this->summarize_user( $user, $mask );
		$data['first_name'] = $user->first_name;
		$data['last_name']  = $user->last_name;
		$data['url']        = $user->user_url;
		$data['bio']        = get_user_meta( $id, 'description', true );
		return $data;
	}

	/**
	 * 创建用户。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_user( $args ) {
		if ( ! current_user_can( 'create_users' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无创建用户权限(create_users)。' );
		}

		$username = isset( $args['username'] ) ? sanitize_user( $args['username'] ) : '';
		$email    = isset( $args['email'] ) ? sanitize_email( $args['email'] ) : '';
		if ( '' === $username || '' === $email ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'create 需要 username 与 email。' );
		}

		$blocked = $this->guard( 'create', $args, sprintf( '创建用户「%s」<%s>', $username, $email ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$userdata = array(
			'user_login'   => $username,
			'user_email'   => $email,
			'user_pass'    => ! empty( $args['password'] ) ? (string) $args['password'] : wp_generate_password( 20, true, true ),
			'role'         => ! empty( $args['role'] ) ? sanitize_key( $args['role'] ) : get_option( 'default_role', 'subscriber' ),
		);
		foreach ( array( 'display_name', 'first_name', 'last_name' ) as $k ) {
			if ( isset( $args[ $k ] ) ) {
				$userdata[ $k ] = sanitize_text_field( $args[ $k ] );
			}
		}

		$id = wp_insert_user( $userdata );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		return array( 'created' => true, 'user' => $this->get_user( array( 'id' => $id ) ) );
	}

	/**
	 * 更新用户资料。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_user( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$user = $id ? get_userdata( $id ) : null;
		if ( ! $user ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到用户 ID:' . $id );
		}
		if ( ! current_user_can( 'edit_user', $id ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无编辑该用户权限。' );
		}

		$blocked = $this->guard( 'update', $args, sprintf( '更新用户 #%d', $id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$userdata = array( 'ID' => $id );
		if ( isset( $args['email'] ) ) {
			$userdata['user_email'] = sanitize_email( $args['email'] );
		}
		if ( ! empty( $args['password'] ) ) {
			$userdata['user_pass'] = (string) $args['password'];
		}
		foreach ( array( 'display_name', 'first_name', 'last_name' ) as $k ) {
			if ( isset( $args[ $k ] ) ) {
				$userdata[ $k ] = sanitize_text_field( $args[ $k ] );
			}
		}

		$result = wp_update_user( $userdata );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'updated' => true, 'user' => $this->get_user( array( 'id' => $id ) ) );
	}

	/**
	 * 改角色。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_role( $args ) {
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$user = $id ? get_userdata( $id ) : null;
		if ( ! $user ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到用户 ID:' . $id );
		}
		if ( ! current_user_can( 'promote_users' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无变更角色权限(promote_users)。' );
		}
		$role = isset( $args['role_to'] ) ? sanitize_key( $args['role_to'] ) : '';
		if ( '' === $role || ! get_role( $role ) ) {
			return new \WP_Error( 'wp_mcp_bad_role', '无效角色:' . $role );
		}

		$blocked = $this->guard( 'set_role', $args, sprintf( '用户 #%d 角色改为 %s', $id, $role ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$user->set_role( $role );

		return array( 'updated' => true, 'id' => $id, 'roles' => $user->roles );
	}

	/**
	 * 删除用户(可重新指派内容)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_user( $args ) {
		if ( ! current_user_can( 'delete_users' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无删除用户权限(delete_users)。' );
		}
		$id   = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$user = $id ? get_userdata( $id ) : null;
		if ( ! $user ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到用户 ID:' . $id );
		}
		if ( get_current_user_id() === $id ) {
			return new \WP_Error( 'wp_mcp_self_delete', '不能删除当前绑定用户自身。' );
		}

		$reassign = ! empty( $args['reassign'] ) ? (int) $args['reassign'] : null;
		$summary  = sprintf( '删除用户 #%d%s', $id, $reassign ? ( ',内容转交 #' . $reassign ) : ',并删除其内容' );
		$blocked  = $this->guard( 'delete', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		require_once ABSPATH . 'wp-admin/includes/user.php';
		$result = wp_delete_user( $id, $reassign );

		return array( 'deleted' => (bool) $result, 'id' => $id, 'reassign' => $reassign );
	}

	/**
	 * 用户摘要(按需脱敏)。
	 *
	 * @param \WP_User $user 用户。
	 * @param bool     $mask 是否脱敏。
	 * @return array
	 */
	private function summarize_user( $user, $mask ) {
		return array(
			'id'           => $user->ID,
			'username'     => $user->user_login,
			'display_name' => $user->display_name,
			'email'        => $mask ? PII::email( $user->user_email ) : $user->user_email,
			'roles'        => $user->roles,
			'registered'   => $user->user_registered,
		);
	}
}
