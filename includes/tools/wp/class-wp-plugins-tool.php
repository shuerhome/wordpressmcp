<?php
/**
 * wp_plugins 工具:插件管理(阶段 4)。
 *
 * list/activate/deactivate 为常规写;install/update/delete 为危险操作需确认。
 * 安装/更新/删除依赖 wp-admin 升级器与文件系统,并按当前用户能力把关。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 插件管理工具。
 */
class WP_Plugins_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '插件管理:list=列出(标注启用/可更新); activate/deactivate=启停(plugin=插件文件,如 akismet/akismet.php); install=安装(slug 来自 wordpress.org,或 zip_url,危险); update=更新(危险); delete=删除(危险,需先停用)。写操作支持 dry_run;install/update/delete 需二次确认。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'activate', 'deactivate', 'install', 'update', 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'install', 'update', 'delete' );
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
					'plugin'        => array(
						'type'        => 'string',
						'description' => 'activate/deactivate/update/delete 的插件文件(相对 plugins 目录,如 akismet/akismet.php)。',
					),
					'slug'          => array(
						'type'        => 'string',
						'description' => 'install:wordpress.org 插件 slug。',
					),
					'zip_url'       => array(
						'type'        => 'string',
						'description' => 'install:插件 zip 包 URL(与 slug 二选一)。',
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
		require_once ABSPATH . 'wp-admin/includes/plugin.php';

		switch ( $action ) {
			case 'list':
				return $this->list_plugins();
			case 'activate':
				return $this->activate( $args );
			case 'deactivate':
				return $this->deactivate( $args );
			case 'install':
				return $this->install( $args );
			case 'update':
				return $this->update( $args );
			case 'delete':
				return $this->delete( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出已安装插件。
	 *
	 * @return array
	 */
	private function list_plugins() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无插件管理权限(activate_plugins)。' );
		}

		$updates = get_site_transient( 'update_plugins' );
		$has_upd = ( $updates && ! empty( $updates->response ) ) ? $updates->response : array();

		$out = array();
		foreach ( get_plugins() as $file => $data ) {
			$out[] = array(
				'plugin'           => $file,
				'name'             => $data['Name'],
				'version'          => $data['Version'],
				'active'           => is_plugin_active( $file ),
				'update_available' => isset( $has_upd[ $file ] ),
			);
		}

		return array( 'plugins' => $out );
	}

	/**
	 * 启用插件。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function activate( $args ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无启用插件权限(activate_plugins)。' );
		}
		$plugin = $this->plugin_arg( $args );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		$blocked = $this->guard( 'activate', $args, '启用插件:' . $plugin );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = activate_plugin( $plugin );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'activated' => true, 'plugin' => $plugin );
	}

	/**
	 * 停用插件。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function deactivate( $args ) {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无停用插件权限(activate_plugins)。' );
		}
		$plugin = $this->plugin_arg( $args );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		$blocked = $this->guard( 'deactivate', $args, '停用插件:' . $plugin );
		if ( null !== $blocked ) {
			return $blocked;
		}

		deactivate_plugins( $plugin );

		return array( 'deactivated' => true, 'plugin' => $plugin );
	}

	/**
	 * 安装插件。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function install( $args ) {
		if ( ! current_user_can( 'install_plugins' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无安装插件权限(install_plugins)。' );
		}

		$slug    = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		$zip_url = isset( $args['zip_url'] ) ? esc_url_raw( $args['zip_url'] ) : '';
		if ( '' === $slug && '' === $zip_url ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'slug 或 zip_url 必填其一。' );
		}

		$summary = $zip_url ? ( '从 zip 安装插件:' . $zip_url ) : ( '从 wordpress.org 安装插件:' . $slug );
		$blocked = $this->guard( 'install', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		if ( $zip_url ) {
			$package = $zip_url;
		} else {
			$api = plugins_api( 'plugin_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$package = $api->download_link;
		}

		$skin     = $this->make_skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_install_failed', '安装失败:' . implode( '; ', $this->skin_errors( $skin ) ) );
		}

		return array(
			'installed' => true,
			'plugin'    => $upgrader->plugin_info(),
		);
	}

	/**
	 * 更新插件。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update( $args ) {
		if ( ! current_user_can( 'update_plugins' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无更新插件权限(update_plugins)。' );
		}
		$plugin = $this->plugin_arg( $args );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}

		$blocked = $this->guard( 'update', $args, '更新插件:' . $plugin );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		wp_update_plugins();

		$skin     = $this->make_skin();
		$upgrader = new \Plugin_Upgrader( $skin );
		$result   = $upgrader->upgrade( $plugin );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'wp_mcp_update_failed', '更新失败或无可用更新:' . implode( '; ', $this->skin_errors( $skin ) ) );
		}

		return array(
			'updated' => true,
			'plugin'  => $plugin,
			'version' => $this->plugin_version( $plugin ),
		);
	}

	/**
	 * 删除插件(须先停用)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete( $args ) {
		if ( ! current_user_can( 'delete_plugins' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '无删除插件权限(delete_plugins)。' );
		}
		$plugin = $this->plugin_arg( $args );
		if ( is_wp_error( $plugin ) ) {
			return $plugin;
		}
		if ( is_plugin_active( $plugin ) ) {
			return new \WP_Error( 'wp_mcp_plugin_active', '插件仍处于启用状态,请先 deactivate。' );
		}

		$blocked = $this->guard( 'delete', $args, '永久删除插件:' . $plugin );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = delete_plugins( array( $plugin ) );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_delete_failed', '删除失败。' );
		}

		return array( 'deleted' => true, 'plugin' => $plugin );
	}

	/* ---------------------------------------------------------------------
	 * 辅助
	 * ------------------------------------------------------------------- */

	/**
	 * 校验并返回 plugin 文件参数。
	 *
	 * @param array $args 入参。
	 * @return string|\WP_Error
	 */
	private function plugin_arg( $args ) {
		$plugin = isset( $args['plugin'] ) ? trim( (string) $args['plugin'] ) : '';
		if ( '' === $plugin ) {
			return new \WP_Error( 'wp_mcp_bad_args', '需要 plugin(插件文件,如 akismet/akismet.php)。' );
		}
		$plugins = get_plugins();
		if ( ! isset( $plugins[ $plugin ] ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到已安装插件:' . $plugin );
		}
		return $plugin;
	}

	/**
	 * 取插件当前版本。
	 *
	 * @param string $plugin 插件文件。
	 * @return string|null
	 */
	private function plugin_version( $plugin ) {
		$plugins = get_plugins();
		return isset( $plugins[ $plugin ]['Version'] ) ? $plugins[ $plugin ]['Version'] : null;
	}

	/**
	 * 加载升级器与文件系统。
	 *
	 * @return true|\WP_Error
	 */
	private function load_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( ! \WP_Filesystem() ) {
			return new \WP_Error( 'wp_mcp_fs', '无法初始化文件系统(可能需要 FTP/SSH 凭据),操作中止。' );
		}

		return true;
	}

	/**
	 * 构造静默升级器皮肤。
	 *
	 * @return \WP_Upgrader_Skin
	 */
	private function make_skin() {
		if ( class_exists( '\WP_Ajax_Upgrader_Skin' ) ) {
			return new \WP_Ajax_Upgrader_Skin();
		}
		if ( class_exists( '\Automatic_Upgrader_Skin' ) ) {
			return new \Automatic_Upgrader_Skin();
		}
		return new \WP_Upgrader_Skin();
	}

	/**
	 * 从皮肤提取错误信息。
	 *
	 * @param \WP_Upgrader_Skin $skin 皮肤。
	 * @return string[]
	 */
	private function skin_errors( $skin ) {
		if ( method_exists( $skin, 'get_errors' ) ) {
			$errors = $skin->get_errors();
			if ( is_wp_error( $errors ) ) {
				return $errors->get_error_messages();
			}
		}
		if ( isset( $skin->result ) && is_wp_error( $skin->result ) ) {
			return $skin->result->get_error_messages();
		}
		return array( '未知错误' );
	}
}
