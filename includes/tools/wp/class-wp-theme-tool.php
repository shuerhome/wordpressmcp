<?php
/**
 * wp_theme 工具:主题管理(阶段 3)。
 *
 * list/get=只读;activate/install/update/delete=危险操作(需二次确认);
 * create_child=创建子主题(可逆性弱,支持 dry_run)。
 *
 * 安装/更新/删除依赖 wp-admin 升级器与文件系统;按当前用户能力把关。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\Backup;

/**
 * 主题管理工具。
 */
class WP_Theme_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_theme';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '主题管理。list=列出已装主题(标注当前/是否块主题); get=取主题详情; activate=切换主题(危险,记录原主题可回滚); install=安装(slug 来自 wordpress.org,或 zip_url,危险); update=更新(危险); delete=删除(危险,不能删当前主题); create_child=基于父主题创建子主题。写操作支持 dry_run;activate/install/update/delete 需二次确认。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'activate', 'install', 'update', 'delete', 'create_child' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'activate', 'install', 'update', 'delete' );
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
					'stylesheet'    => array(
						'type'        => 'string',
						'description' => 'get/activate/update/delete 的主题目录名(stylesheet)。',
					),
					'slug'          => array(
						'type'        => 'string',
						'description' => 'install:wordpress.org 主题 slug。',
					),
					'zip_url'       => array(
						'type'        => 'string',
						'description' => 'install:主题 zip 包 URL(与 slug 二选一)。',
					),
					'parent'        => array(
						'type'        => 'string',
						'description' => 'create_child:父主题 stylesheet,默认当前主题。',
					),
					'name'          => array(
						'type'        => 'string',
						'description' => 'create_child:子主题显示名。',
					),
					'child_slug'    => array(
						'type'        => 'string',
						'description' => 'create_child:子主题目录名,默认「父主题-child」。',
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
		switch ( $action ) {
			case 'list':
				return $this->list_themes();
			case 'get':
				return $this->get_theme( $args );
			case 'activate':
				return $this->activate_theme( $args );
			case 'install':
				return $this->install_theme( $args );
			case 'update':
				return $this->update_theme( $args );
			case 'delete':
				return $this->delete_theme_action( $args );
			case 'create_child':
				return $this->create_child( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出已安装主题。
	 *
	 * @return array
	 */
	private function list_themes() {
		$current = get_stylesheet();
		$out     = array();
		foreach ( wp_get_themes() as $stylesheet => $theme ) {
			$out[] = array(
				'stylesheet'     => $stylesheet,
				'name'           => $theme->get( 'Name' ),
				'version'        => $theme->get( 'Version' ),
				'template'       => $theme->get_template(), // 父主题(子主题时不同于自身)。
				'is_block_theme' => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
				'active'         => ( $stylesheet === $current ),
			);
		}
		return array(
			'active'  => $current,
			'themes'  => $out,
		);
	}

	/**
	 * 取主题详情。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_theme( $args ) {
		$stylesheet = isset( $args['stylesheet'] ) ? (string) $args['stylesheet'] : '';
		$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : wp_get_theme();
		if ( ! $theme->exists() ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到主题:' . $stylesheet );
		}

		return array(
			'stylesheet'     => $theme->get_stylesheet(),
			'name'           => $theme->get( 'Name' ),
			'version'        => $theme->get( 'Version' ),
			'author'         => wp_strip_all_tags( $theme->get( 'Author' ) ),
			'description'    => wp_strip_all_tags( $theme->get( 'Description' ) ),
			'template'       => $theme->get_template(),
			'is_block_theme' => method_exists( $theme, 'is_block_theme' ) ? $theme->is_block_theme() : false,
			'active'         => ( $theme->get_stylesheet() === get_stylesheet() ),
			'errors'         => $theme->errors() ? $theme->errors()->get_error_messages() : array(),
		);
	}

	/**
	 * 切换主题。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function activate_theme( $args ) {
		if ( ! current_user_can( 'switch_themes' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '当前用户无切换主题权限(switch_themes)。' );
		}

		$stylesheet = isset( $args['stylesheet'] ) ? (string) $args['stylesheet'] : '';
		$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : null;
		if ( ! $theme || ! $theme->exists() ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到主题:' . $stylesheet );
		}
		if ( $theme->errors() ) {
			return new \WP_Error( 'wp_mcp_theme_broken', '主题存在错误,拒绝激活:' . implode( '; ', $theme->errors()->get_error_messages() ) );
		}

		$previous = get_stylesheet();
		$summary  = sprintf( '切换主题:%s → %s', $previous, $stylesheet );
		$blocked  = $this->guard( 'activate', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$backup_id = Backup::snapshot( 'active_theme', $summary, array( 'previous' => $previous ) );

		switch_theme( $stylesheet );

		return array(
			'activated' => true,
			'stylesheet' => $stylesheet,
			'previous'  => $previous,
			'backup_id' => $backup_id,
		);
	}

	/**
	 * 安装主题(wordpress.org slug 或 zip URL)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function install_theme( $args ) {
		if ( ! current_user_can( 'install_themes' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '当前用户无安装主题权限(install_themes)。' );
		}

		$slug    = isset( $args['slug'] ) ? sanitize_key( $args['slug'] ) : '';
		$zip_url = isset( $args['zip_url'] ) ? esc_url_raw( $args['zip_url'] ) : '';
		if ( '' === $slug && '' === $zip_url ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'slug 或 zip_url 必填其一。' );
		}

		$summary = $zip_url ? ( '从 zip 安装主题:' . $zip_url ) : ( '从 wordpress.org 安装主题:' . $slug );
		$blocked = $this->guard( 'install', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		// 解析下载包。
		if ( $zip_url ) {
			$package = $zip_url;
		} else {
			$api = themes_api( 'theme_information', array( 'slug' => $slug, 'fields' => array( 'sections' => false ) ) );
			if ( is_wp_error( $api ) ) {
				return $api;
			}
			$package = $api->download_link;
		}

		$skin     = $this->make_skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->install( $package );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_install_failed', '安装失败:' . implode( '; ', $this->skin_errors( $skin ) ) );
		}

		$info = $upgrader->theme_info();
		return array(
			'installed'  => true,
			'stylesheet' => $info ? $info->get_stylesheet() : null,
			'name'       => $info ? $info->get( 'Name' ) : null,
		);
	}

	/**
	 * 更新主题。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_theme( $args ) {
		if ( ! current_user_can( 'update_themes' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '当前用户无更新主题权限(update_themes)。' );
		}

		$stylesheet = isset( $args['stylesheet'] ) ? (string) $args['stylesheet'] : '';
		$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : null;
		if ( ! $theme || ! $theme->exists() ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到主题:' . $stylesheet );
		}

		$summary = '更新主题:' . $stylesheet;
		$blocked = $this->guard( 'update', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		wp_update_themes(); // 刷新可用更新信息。

		$skin     = $this->make_skin();
		$upgrader = new \Theme_Upgrader( $skin );
		$result   = $upgrader->upgrade( $stylesheet );

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( false === $result ) {
			return new \WP_Error( 'wp_mcp_update_failed', '更新失败或无可用更新:' . implode( '; ', $this->skin_errors( $skin ) ) );
		}

		return array(
			'updated'    => true,
			'stylesheet' => $stylesheet,
			'version'    => wp_get_theme( $stylesheet )->get( 'Version' ),
		);
	}

	/**
	 * 删除主题。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_theme_action( $args ) {
		if ( ! current_user_can( 'delete_themes' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '当前用户无删除主题权限(delete_themes)。' );
		}

		$stylesheet = isset( $args['stylesheet'] ) ? (string) $args['stylesheet'] : '';
		$theme      = $stylesheet ? wp_get_theme( $stylesheet ) : null;
		if ( ! $theme || ! $theme->exists() ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到主题:' . $stylesheet );
		}
		if ( $stylesheet === get_stylesheet() || $stylesheet === get_template() ) {
			return new \WP_Error( 'wp_mcp_theme_in_use', '不能删除当前启用的主题或其父主题。' );
		}

		$summary = '永久删除主题:' . $stylesheet;
		$blocked = $this->guard( 'delete', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		$result = delete_theme( $stylesheet );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new \WP_Error( 'wp_mcp_delete_failed', '删除失败。' );
		}

		return array(
			'deleted'    => true,
			'stylesheet' => $stylesheet,
		);
	}

	/**
	 * 创建子主题。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_child( $args ) {
		if ( ! current_user_can( 'install_themes' ) ) {
			return new \WP_Error( 'wp_mcp_forbidden', '当前用户无创建主题权限(install_themes)。' );
		}

		$parent_slug = isset( $args['parent'] ) ? (string) $args['parent'] : get_stylesheet();
		$parent      = wp_get_theme( $parent_slug );
		if ( ! $parent->exists() ) {
			return new \WP_Error( 'wp_mcp_not_found', '父主题不存在:' . $parent_slug );
		}

		$name       = ! empty( $args['name'] ) ? sanitize_text_field( $args['name'] ) : ( $parent->get( 'Name' ) . ' Child' );
		$child_slug = ! empty( $args['child_slug'] ) ? sanitize_title( $args['child_slug'] ) : sanitize_title( $parent_slug . '-child' );

		$summary = sprintf( '基于「%s」创建子主题「%s」(目录 %s)', $parent_slug, $name, $child_slug );
		$blocked = $this->guard( 'create_child', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$ready = $this->load_upgrader();
		if ( is_wp_error( $ready ) ) {
			return $ready;
		}

		global $wp_filesystem;
		$themes_root = $wp_filesystem->wp_themes_dir();
		if ( ! $themes_root ) {
			return new \WP_Error( 'wp_mcp_fs', '无法定位主题目录。' );
		}
		$child_dir = trailingslashit( $themes_root ) . $child_slug;

		if ( $wp_filesystem->is_dir( $child_dir ) ) {
			return new \WP_Error( 'wp_mcp_exists', '目标目录已存在:' . $child_slug );
		}
		if ( ! $wp_filesystem->mkdir( $child_dir, FS_CHMOD_DIR ) ) {
			return new \WP_Error( 'wp_mcp_fs', '创建子主题目录失败。' );
		}

		$style = "/*\n"
			. 'Theme Name: ' . $name . "\n"
			. 'Template: ' . $parent_slug . "\n"
			. 'Version: 1.0.0\n'
			. 'Description: 由 WP MCP 创建的子主题。\n'
			. "*/\n";

		$functions = "<?php\n"
			. "// 子主题:加载父主题样式。\n"
			. "add_action( 'wp_enqueue_scripts', function() {\n"
			. "\twp_enqueue_style( 'wpmcp-parent-style', get_template_directory_uri() . '/style.css' );\n"
			. "} );\n";

		$ok_style = $wp_filesystem->put_contents( trailingslashit( $child_dir ) . 'style.css', $style, FS_CHMOD_FILE );
		$ok_func  = $wp_filesystem->put_contents( trailingslashit( $child_dir ) . 'functions.php', $functions, FS_CHMOD_FILE );

		if ( ! $ok_style || ! $ok_func ) {
			return new \WP_Error( 'wp_mcp_fs', '写入子主题文件失败。' );
		}

		return array(
			'created'    => true,
			'stylesheet' => $child_slug,
			'parent'     => $parent_slug,
		);
	}

	/* ---------------------------------------------------------------------
	 * 升级器 / 文件系统辅助
	 * ------------------------------------------------------------------- */

	/**
	 * 加载升级器与主题相关 admin 文件,并初始化文件系统。
	 *
	 * @return true|\WP_Error
	 */
	private function load_upgrader() {
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		if ( ! \WP_Filesystem() ) {
			return new \WP_Error( 'wp_mcp_fs', '无法初始化文件系统(可能需要 FTP/SSH 凭据,或目录不可写),操作中止。' );
		}

		return true;
	}

	/**
	 * 构造一个静默的升级器皮肤(收集错误而不输出 HTML)。
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
	 * 从皮肤提取错误信息(兼容不同皮肤类型)。
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
