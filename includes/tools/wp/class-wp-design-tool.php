<?php
/**
 * wp_design 工具:主题设计控制(阶段 3)。
 *
 * - FSE 块主题:全局样式(theme.json 用户层)、模板、模板部件。
 * - 经典主题:theme_mods / Customizer、Additional CSS。
 * - 通用:字体清单(只读)、设计快照浏览与一键回滚。
 *
 * 所有写操作改动前自动快照旧值,可用 rollback 还原。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\Backup;

/**
 * 设计/外观控制工具。
 */
class WP_Design_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_design';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '主题设计控制。FSE 块主题:get_global_styles/set_global_styles=读写全局样式(调色板/字体/间距等 theme.json 用户层); list_templates/get_template/update_template/revert_template=模板; list_template_parts/get_template_part/update_template_part=模板部件(页眉/页脚)。经典主题:get_theme_mods/set_theme_mods、get_custom_css/set_custom_css。通用:list_fonts=字体清单; backups=列出设计快照; rollback=按快照 ID 还原。写操作改动前自动快照,支持 dry_run;revert_template/rollback 为危险操作需二次确认。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array(
			'get_global_styles',
			'set_global_styles',
			'list_templates',
			'get_template',
			'update_template',
			'revert_template',
			'list_template_parts',
			'get_template_part',
			'update_template_part',
			'get_theme_mods',
			'set_theme_mods',
			'get_custom_css',
			'set_custom_css',
			'list_fonts',
			'backups',
			'rollback',
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'revert_template', 'rollback' );
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
					'settings'      => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'set_global_styles:要合并进 theme.json 用户层的 settings(如 color.palette、typography 等),局部深合并。',
					),
					'styles'        => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'set_global_styles:要合并进 theme.json 用户层的 styles(如 color、typography、spacing、elements、blocks),局部深合并。',
					),
					'template_id'   => array(
						'type'        => 'string',
						'description' => 'get_template/update_template/revert_template 及部件版的标识。可填完整 ID(stylesheet//slug)或仅 slug(自动补当前主题)。',
					),
					'content'       => array(
						'type'        => 'string',
						'description' => 'update_template/update_template_part 的区块标记内容。',
					),
					'title'         => array(
						'type'        => 'string',
						'description' => 'update_template* 时新建自定义版本的标题(可选)。',
					),
					'area'          => array(
						'type'        => 'string',
						'description' => 'update_template_part 新建时的区域:header/footer/uncategorized(可选)。',
					),
					'mods'          => array(
						'type'                 => 'object',
						'additionalProperties' => true,
						'description'          => 'set_theme_mods:键值对形式的 theme_mod(如 {"custom_logo":12,"background_color":"ffffff"})。',
					),
					'css'           => array(
						'type'        => 'string',
						'description' => 'set_custom_css 的 Additional CSS 内容。',
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
		switch ( $action ) {
			case 'get_global_styles':
				return $this->get_global_styles();
			case 'set_global_styles':
				return $this->set_global_styles( $args );
			case 'list_templates':
				return $this->list_block_templates( 'wp_template' );
			case 'get_template':
				return $this->get_block_template( $args, 'wp_template' );
			case 'update_template':
				return $this->update_block_template( $args, 'wp_template', 'update_template' );
			case 'revert_template':
				return $this->revert_block_template( $args );
			case 'list_template_parts':
				return $this->list_block_templates( 'wp_template_part' );
			case 'get_template_part':
				return $this->get_block_template( $args, 'wp_template_part' );
			case 'update_template_part':
				return $this->update_block_template( $args, 'wp_template_part', 'update_template_part' );
			case 'get_theme_mods':
				return $this->get_theme_mods();
			case 'set_theme_mods':
				return $this->set_theme_mods( $args );
			case 'get_custom_css':
				return $this->get_custom_css();
			case 'set_custom_css':
				return $this->set_custom_css( $args );
			case 'list_fonts':
				return $this->list_fonts();
			case 'backups':
				return array( 'backups' => Backup::all() );
			case 'rollback':
				return $this->rollback( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/* ---------------------------------------------------------------------
	 * FSE:全局样式
	 * ------------------------------------------------------------------- */

	/**
	 * 确认当前为支持 theme.json 的块主题。
	 *
	 * @return true|\WP_Error
	 */
	private function ensure_fse() {
		if ( ! ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) ) {
			return new \WP_Error( 'wp_mcp_not_fse', '当前为经典主题,FSE 全局样式/模板不可用。请改用 get_theme_mods/set_theme_mods 与 get_custom_css/set_custom_css。' );
		}
		if ( ! class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			return new \WP_Error( 'wp_mcp_no_theme_json', '当前 WordPress 版本不支持 theme.json 全局样式。' );
		}
		return true;
	}

	/**
	 * 读取当前主题的用户层全局样式。
	 *
	 * @return array|\WP_Error
	 */
	private function get_global_styles() {
		$fse = $this->ensure_fse();
		if ( is_wp_error( $fse ) ) {
			return $fse;
		}

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = $post_id ? get_post( $post_id ) : null;
		$raw     = ( $post && $post->post_content ) ? json_decode( $post->post_content, true ) : array();
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}

		return array(
			'post_id'  => (int) $post_id,
			'version'  => isset( $raw['version'] ) ? $raw['version'] : null,
			'settings' => isset( $raw['settings'] ) ? $raw['settings'] : new \stdClass(),
			'styles'   => isset( $raw['styles'] ) ? $raw['styles'] : new \stdClass(),
		);
	}

	/**
	 * 写入(深合并)用户层全局样式。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_global_styles( $args ) {
		$fse = $this->ensure_fse();
		if ( is_wp_error( $fse ) ) {
			return $fse;
		}

		$has_settings = isset( $args['settings'] ) && is_array( $args['settings'] );
		$has_styles   = isset( $args['styles'] ) && is_array( $args['styles'] );
		if ( ! $has_settings && ! $has_styles ) {
			return new \WP_Error( 'wp_mcp_bad_args', '请至少提供 settings 或 styles 之一。' );
		}

		$summary = '更新全局样式(' . ( $has_settings ? 'settings ' : '' ) . ( $has_styles ? 'styles' : '' ) . ')';
		$blocked = $this->guard( 'set_global_styles', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$post_id = \WP_Theme_JSON_Resolver::get_user_global_styles_post_id();
		$post    = get_post( $post_id );
		$current = ( $post && $post->post_content ) ? json_decode( $post->post_content, true ) : array();
		if ( ! is_array( $current ) ) {
			$current = array();
		}

		// 改动前快照(存旧的原始 JSON)。
		$backup_id = Backup::snapshot(
			'global_styles',
			$summary,
			array(
				'post_id' => (int) $post_id,
				'content' => $post ? $post->post_content : '',
			)
		);

		if ( $has_settings ) {
			$current['settings'] = $this->deep_merge( isset( $current['settings'] ) ? $current['settings'] : array(), $args['settings'] );
		}
		if ( $has_styles ) {
			$current['styles'] = $this->deep_merge( isset( $current['styles'] ) ? $current['styles'] : array(), $args['styles'] );
		}
		if ( empty( $current['version'] ) ) {
			$current['version'] = ( class_exists( '\WP_Theme_JSON' ) && defined( 'WP_Theme_JSON::LATEST_SCHEMA_VERSION' ) ) ? \WP_Theme_JSON::LATEST_SCHEMA_VERSION : 2;
		}

		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => wp_json_encode( $current ),
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		\WP_Theme_JSON_Resolver::clean_cached_data();

		return array(
			'updated'   => true,
			'post_id'   => (int) $post_id,
			'backup_id' => $backup_id,
		);
	}

	/* ---------------------------------------------------------------------
	 * FSE:模板 / 模板部件
	 * ------------------------------------------------------------------- */

	/**
	 * 列出模板或模板部件。
	 *
	 * @param string $post_type wp_template 或 wp_template_part。
	 * @return array|\WP_Error
	 */
	private function list_block_templates( $post_type ) {
		$fse = $this->ensure_fse();
		if ( is_wp_error( $fse ) ) {
			return $fse;
		}

		$templates = get_block_templates( array(), $post_type );
		$out       = array();
		foreach ( $templates as $tmpl ) {
			$row = array(
				'id'          => $tmpl->id,
				'slug'        => $tmpl->slug,
				'title'       => $tmpl->title,
				'description' => $tmpl->description,
				'source'      => $tmpl->source, // theme(主题自带) / custom(已自定义,存于数据库)。
				'customized'  => ! empty( $tmpl->wp_id ),
			);
			if ( 'wp_template_part' === $post_type && isset( $tmpl->area ) ) {
				$row['area'] = $tmpl->area;
			}
			$out[] = $row;
		}

		return array(
			'post_type' => $post_type,
			'templates' => $out,
		);
	}

	/**
	 * 取单个模板/模板部件的完整内容。
	 *
	 * @param array  $args      入参。
	 * @param string $post_type 类型。
	 * @return array|\WP_Error
	 */
	private function get_block_template( $args, $post_type ) {
		$fse = $this->ensure_fse();
		if ( is_wp_error( $fse ) ) {
			return $fse;
		}

		$id   = $this->normalize_template_id( $args );
		if ( '' === $id ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'template_id 必填' );
		}
		$tmpl = get_block_template( $id, $post_type );
		if ( ! $tmpl ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到:' . $id );
		}

		$data = array(
			'id'          => $tmpl->id,
			'slug'        => $tmpl->slug,
			'title'       => $tmpl->title,
			'description' => $tmpl->description,
			'source'      => $tmpl->source,
			'customized'  => ! empty( $tmpl->wp_id ),
			'content'     => $tmpl->content,
		);
		if ( 'wp_template_part' === $post_type && isset( $tmpl->area ) ) {
			$data['area'] = $tmpl->area;
		}

		return $data;
	}

	/**
	 * 更新模板/模板部件内容(主题自带模板会被「自定义」为数据库版本)。
	 *
	 * @param array  $args      入参。
	 * @param string $post_type 类型。
	 * @param string $action    动作名(用于护栏)。
	 * @return array|\WP_Error
	 */
	private function update_block_template( $args, $post_type, $action ) {
		$fse = $this->ensure_fse();
		if ( is_wp_error( $fse ) ) {
			return $fse;
		}

		$id = $this->normalize_template_id( $args );
		if ( '' === $id ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'template_id 必填' );
		}
		if ( ! isset( $args['content'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'content 必填' );
		}

		$tmpl = get_block_template( $id, $post_type );
		if ( ! $tmpl ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到:' . $id );
		}

		$label   = 'wp_template_part' === $post_type ? '模板部件' : '模板';
		$summary = sprintf( '更新%s「%s」', $label, $tmpl->title ? $tmpl->title : $id );
		$blocked = $this->guard( $action, $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// 改动前快照。
		$backup_id = Backup::snapshot(
			$post_type,
			$summary,
			array(
				'id'        => $id,
				'post_type' => $post_type,
				'wp_id'     => ! empty( $tmpl->wp_id ) ? (int) $tmpl->wp_id : null,
				'content'   => $tmpl->content,
				'existed'   => ! empty( $tmpl->wp_id ),
				'title'     => $tmpl->title,
				'area'      => isset( $tmpl->area ) ? $tmpl->area : '',
			)
		);

		$pid = $this->write_block_template( $id, $post_type, $args['content'], $tmpl, $args );
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}

		return array(
			'updated'   => true,
			'id'        => $id,
			'wp_id'     => (int) $pid,
			'backup_id' => $backup_id,
		);
	}

	/**
	 * 写入模板内容:已自定义则更新,否则新建数据库版本。
	 *
	 * @param string                 $id        模板完整 ID。
	 * @param string                 $post_type 类型。
	 * @param string                 $content   区块标记。
	 * @param \WP_Block_Template|null $tmpl      现有模板对象(用于取标题/区域/wp_id)。
	 * @param array                  $args      入参(可覆盖 title/area)。
	 * @return int|\WP_Error 文章 ID。
	 */
	private function write_block_template( $id, $post_type, $content, $tmpl, $args ) {
		$wp_id = ( $tmpl && ! empty( $tmpl->wp_id ) ) ? (int) $tmpl->wp_id : 0;

		if ( $wp_id ) {
			$result = wp_update_post(
				array(
					'ID'           => $wp_id,
					'post_content' => $content,
				),
				true
			);
			return is_wp_error( $result ) ? $result : $wp_id;
		}

		// 新建自定义版本。
		$slug  = ( false !== strpos( $id, '//' ) ) ? substr( $id, strpos( $id, '//' ) + 2 ) : $id;
		$title = ! empty( $args['title'] ) ? $args['title'] : ( $tmpl ? $tmpl->title : $slug );

		$pid = wp_insert_post(
			array(
				'post_type'    => $post_type,
				'post_status'  => 'publish',
				'post_name'    => sanitize_title( $slug ),
				'post_title'   => $title,
				'post_content' => $content,
				'post_excerpt' => $tmpl ? $tmpl->description : '',
			),
			true
		);
		if ( is_wp_error( $pid ) ) {
			return $pid;
		}

		// 绑定到当前主题。
		wp_set_post_terms( $pid, get_stylesheet(), 'wp_theme' );

		// 模板部件需指定区域。
		if ( 'wp_template_part' === $post_type ) {
			$area = ! empty( $args['area'] ) ? $args['area'] : ( ( $tmpl && ! empty( $tmpl->area ) ) ? $tmpl->area : 'uncategorized' );
			wp_set_post_terms( $pid, $area, 'wp_template_part_area' );
		}

		return $pid;
	}

	/**
	 * 把已自定义的模板回退到主题默认(删除数据库版本)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function revert_block_template( $args ) {
		$fse = $this->ensure_fse();
		if ( is_wp_error( $fse ) ) {
			return $fse;
		}

		$id = $this->normalize_template_id( $args );
		if ( '' === $id ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'template_id 必填' );
		}
		$tmpl = get_block_template( $id, 'wp_template' );
		if ( ! $tmpl ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到模板:' . $id );
		}
		if ( empty( $tmpl->wp_id ) ) {
			return new \WP_Error( 'wp_mcp_not_customized', '该模板未自定义,无需回退。' );
		}

		$summary = sprintf( '回退模板「%s」到主题默认(删除自定义版本)', $tmpl->title ? $tmpl->title : $id );
		$blocked = $this->guard( 'revert_template', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$backup_id = Backup::snapshot(
			'wp_template',
			$summary,
			array(
				'id'        => $id,
				'post_type' => 'wp_template',
				'wp_id'     => (int) $tmpl->wp_id,
				'content'   => $tmpl->content,
				'existed'   => true,
				'title'     => $tmpl->title,
				'area'      => '',
			)
		);

		wp_delete_post( (int) $tmpl->wp_id, true );

		return array(
			'reverted'  => true,
			'id'        => $id,
			'backup_id' => $backup_id,
		);
	}

	/**
	 * 规范化 template_id:无 stylesheet 前缀时补当前主题。
	 *
	 * @param array $args 入参。
	 * @return string
	 */
	private function normalize_template_id( $args ) {
		$id = isset( $args['template_id'] ) ? trim( (string) $args['template_id'] ) : '';
		if ( '' === $id ) {
			return '';
		}
		if ( false === strpos( $id, '//' ) ) {
			$id = get_stylesheet() . '//' . $id;
		}
		return $id;
	}

	/* ---------------------------------------------------------------------
	 * 经典:theme_mods / Customizer
	 * ------------------------------------------------------------------- */

	/**
	 * 读取当前主题的 theme_mods。
	 *
	 * @return array
	 */
	private function get_theme_mods() {
		$mods = get_theme_mods();
		return array(
			'stylesheet' => get_stylesheet(),
			'mods'       => $mods ? $mods : new \stdClass(),
		);
	}

	/**
	 * 批量写入 theme_mods。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_theme_mods( $args ) {
		if ( ! isset( $args['mods'] ) || ! is_array( $args['mods'] ) || empty( $args['mods'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'mods 必填,且为非空键值对。' );
		}

		$summary = sprintf( '更新 %d 项 theme_mod:%s', count( $args['mods'] ), implode( ', ', array_keys( $args['mods'] ) ) );
		$blocked = $this->guard( 'set_theme_mods', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		// 快照旧值(区分「原本无此项」)。
		$existing = get_theme_mods();
		$existing = $existing ? $existing : array();
		$old      = array();
		$absent   = array();
		foreach ( $args['mods'] as $key => $value ) {
			if ( array_key_exists( $key, $existing ) ) {
				$old[ $key ] = $existing[ $key ];
			} else {
				$absent[] = $key;
			}
		}
		$backup_id = Backup::snapshot(
			'theme_mods',
			$summary,
			array(
				'mods'   => $old,
				'absent' => $absent,
			)
		);

		foreach ( $args['mods'] as $key => $value ) {
			set_theme_mod( sanitize_key( $key ), $value );
		}

		return array(
			'updated'   => true,
			'keys'      => array_keys( $args['mods'] ),
			'backup_id' => $backup_id,
		);
	}

	/* ---------------------------------------------------------------------
	 * 通用:Additional CSS / 字体
	 * ------------------------------------------------------------------- */

	/**
	 * 读取 Additional CSS。
	 *
	 * @return array
	 */
	private function get_custom_css() {
		return array(
			'stylesheet' => get_stylesheet(),
			'css'        => wp_get_custom_css(),
		);
	}

	/**
	 * 写入 Additional CSS。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function set_custom_css( $args ) {
		if ( ! isset( $args['css'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'css 必填(传空字符串可清空)。' );
		}

		$summary = '更新 Additional CSS(' . strlen( (string) $args['css'] ) . ' 字节)';
		$blocked = $this->guard( 'set_custom_css', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$backup_id = Backup::snapshot(
			'custom_css',
			$summary,
			array( 'css' => wp_get_custom_css() )
		);

		$result = wp_update_custom_css_post( (string) $args['css'] );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'updated'   => true,
			'backup_id' => $backup_id,
		);
	}

	/**
	 * 列出当前可用字体族(合并后的 theme.json typography)。
	 *
	 * @return array
	 */
	private function list_fonts() {
		$families = array();

		if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
			$merged   = \WP_Theme_JSON_Resolver::get_merged_data();
			$settings = $merged->get_settings();
			$raw      = isset( $settings['typography']['fontFamilies'] ) ? $settings['typography']['fontFamilies'] : array();

			// fontFamilies 可能按来源(theme/custom/default)分组,也可能是平铺列表。
			$candidates = array();
			if ( isset( $raw[0] ) ) {
				$candidates = $raw;
			} else {
				foreach ( $raw as $group ) {
					if ( is_array( $group ) ) {
						$candidates = array_merge( $candidates, $group );
					}
				}
			}

			foreach ( $candidates as $f ) {
				if ( ! is_array( $f ) ) {
					continue;
				}
				$families[] = array(
					'name'       => isset( $f['name'] ) ? $f['name'] : ( isset( $f['slug'] ) ? $f['slug'] : '' ),
					'slug'       => isset( $f['slug'] ) ? $f['slug'] : '',
					'fontFamily' => isset( $f['fontFamily'] ) ? $f['fontFamily'] : '',
				);
			}
		}

		return array( 'fonts' => $families );
	}

	/* ---------------------------------------------------------------------
	 * 回滚
	 * ------------------------------------------------------------------- */

	/**
	 * 按快照 ID 还原。
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

		$summary = sprintf( '回滚快照 %s(%s)', $id, $bk['label'] );
		$blocked = $this->guard( 'rollback', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$data = isset( $bk['data'] ) ? $bk['data'] : array();

		switch ( $bk['type'] ) {
			case 'global_styles':
				$post_id = isset( $data['post_id'] ) ? (int) $data['post_id'] : 0;
				if ( ! $post_id || ! get_post( $post_id ) ) {
					return new \WP_Error( 'wp_mcp_rollback_failed', '全局样式快照对应的记录已不存在。' );
				}
				wp_update_post(
					array(
						'ID'           => $post_id,
						'post_content' => isset( $data['content'] ) ? $data['content'] : '',
					)
				);
				if ( class_exists( '\WP_Theme_JSON_Resolver' ) ) {
					\WP_Theme_JSON_Resolver::clean_cached_data();
				}
				break;

			case 'wp_template':
			case 'wp_template_part':
				$tid  = isset( $data['id'] ) ? $data['id'] : '';
				$tmpl = $tid ? get_block_template( $tid, $bk['type'] ) : null;
				$pid  = $this->write_block_template( $tid, $bk['type'], isset( $data['content'] ) ? $data['content'] : '', $tmpl, $data );
				if ( is_wp_error( $pid ) ) {
					return $pid;
				}
				break;

			case 'theme_mods':
				$mods   = isset( $data['mods'] ) ? $data['mods'] : array();
				$absent = isset( $data['absent'] ) ? $data['absent'] : array();
				foreach ( $mods as $key => $value ) {
					set_theme_mod( $key, $value );
				}
				foreach ( $absent as $key ) {
					remove_theme_mod( $key );
				}
				break;

			case 'custom_css':
				wp_update_custom_css_post( isset( $data['css'] ) ? (string) $data['css'] : '' );
				break;

			case 'active_theme':
				$prev = isset( $data['previous'] ) ? $data['previous'] : '';
				if ( $prev && wp_get_theme( $prev )->exists() ) {
					switch_theme( $prev );
				} else {
					return new \WP_Error( 'wp_mcp_rollback_failed', '原主题已不存在,无法回滚激活状态。' );
				}
				break;

			default:
				return new \WP_Error( 'wp_mcp_rollback_unsupported', '不支持自动回滚的快照类型:' . $bk['type'] );
		}

		return array(
			'rolled_back' => true,
			'backup_id'   => $id,
			'type'        => $bk['type'],
		);
	}

	/* ---------------------------------------------------------------------
	 * 工具方法
	 * ------------------------------------------------------------------- */

	/**
	 * 深合并两个数组:关联数组按键递归合并;标量与列表(数字索引数组)直接覆盖。
	 *
	 * @param mixed $base     原值。
	 * @param mixed $override 覆盖值。
	 * @return mixed
	 */
	private function deep_merge( $base, $override ) {
		if ( ! is_array( $base ) || ! is_array( $override ) ) {
			return $override;
		}
		// 列表(如调色板)整体替换,避免按下标错位合并。
		if ( $this->is_list( $base ) || $this->is_list( $override ) ) {
			return $override;
		}
		foreach ( $override as $key => $value ) {
			$base[ $key ] = array_key_exists( $key, $base ) ? $this->deep_merge( $base[ $key ], $value ) : $value;
		}
		return $base;
	}

	/**
	 * 判断是否为「列表」(连续数字索引)数组。
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
}
