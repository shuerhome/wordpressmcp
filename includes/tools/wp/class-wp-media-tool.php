<?php
/**
 * wp_media 工具:媒体库管理(列出/获取/上传/更新/删除)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * 媒体库工具。
 */
class WP_Media_Tool extends Abstract_Tool {

	const MAX_UPLOAD_BYTES = 10485760;

	/**
	 * {@inheritDoc}
	 */
	protected function is_open_world() {
		return true;
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_media';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '媒体库:list=列出附件; get=取附件详情; upload=上传图片/文件到媒体库(url=从网址下载,或 data=base64 内容 + filename,用于本地图片); update=改替代文字/标题/说明; delete=删除附件(需确认)。上传后返回附件 ID,可用于商品图片。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'upload', 'update', 'delete' );
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
					'action'   => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'id'       => array(
						'type'        => 'integer',
						'description' => 'get/update/delete 的附件 ID',
					),
					'url'      => array(
						'type'        => 'string',
						'description' => 'upload 的来源图片/文件 URL(与 data 二选一)',
					),
					'data'     => array(
						'type'        => 'string',
						'description' => 'upload 的文件内容,base64 编码(与 url 二选一,用于上传本地图片)。可带 data:image/png;base64, 前缀。上限 10MB。',
					),
					'filename' => array(
						'type'        => 'string',
						'description' => '用 data 上传时的文件名(含扩展名),如 bag-red.jpg。',
					),
					'title'    => array(
						'type'        => 'string',
						'description' => 'upload/update 标题',
					),
					'alt'      => array(
						'type'        => 'string',
						'description' => 'upload/update 替代文字(alt)',
					),
					'caption'  => array(
						'type'        => 'string',
						'description' => 'upload/update 说明文字',
					),
					'attach_to' => array(
						'type'        => 'integer',
						'description' => 'upload 时附加到的文章 ID(可选)',
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'search'   => array(
						'type'        => 'string',
						'description' => 'list 关键词',
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
				return $this->list_media( $args );
			case 'get':
				return $this->get_media( $args );
			case 'upload':
				return $this->upload_media( $args );
			case 'update':
				return $this->update_media( $args );
			case 'delete':
				return $this->delete_media( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出附件。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_media( $args ) {
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => $per_page,
			'paged'          => $page,
		);
		if ( ! empty( $args['search'] ) ) {
			$query_args['s'] = sanitize_text_field( $args['search'] );
		}

		$query = new \WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = $this->summarize_media( $post->ID );
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
	 * 取附件详情。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_media( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到附件 ID:' . $id );
		}
		return $this->summarize_media( $id );
	}

	/**
	 * 从 URL 上传到媒体库(sideload)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function upload_media( $args ) {
		$url  = isset( $args['url'] ) ? esc_url_raw( $args['url'] ) : '';
		$data = isset( $args['data'] ) ? (string) $args['data'] : '';

		if ( ! $url && '' === $data ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'upload 需要 url 或 data(base64)其一。' );
		}

		$bytes    = null;
		$filename = '';
		if ( '' !== $data ) {
			$filename = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
			if ( '' === $filename || false === strpos( $filename, '.' ) ) {
				return new \WP_Error( 'wp_mcp_bad_args', '用 data 上传时需要带扩展名的 filename,如 photo.jpg。' );
			}
			$data  = preg_replace( '/^data:[^;,]+;base64,/i', '', trim( $data ) );
			$bytes = base64_decode( preg_replace( '/\s+/', '', $data ), true );
			if ( false === $bytes || '' === $bytes ) {
				return new \WP_Error( 'wp_mcp_bad_args', 'data 不是有效的 base64。' );
			}
			if ( strlen( $bytes ) > self::MAX_UPLOAD_BYTES ) {
				return new \WP_Error( 'wp_mcp_too_large', sprintf( '文件超过 %d MB 上限。', self::MAX_UPLOAD_BYTES / 1048576 ) );
			}
			$summary = sprintf( '上传文件到媒体库:%s(%d KB)', $filename, (int) ceil( strlen( $bytes ) / 1024 ) );
		} else {
			$summary = '从 URL 上传到媒体库:' . $url;
		}

		$blocked = $this->guard( 'upload', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';

		if ( null !== $bytes ) {
			$tmp = wp_tempnam( $filename );
			if ( ! $tmp || false === file_put_contents( $tmp, $bytes ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
				return new \WP_Error( 'wp_mcp_upload_failed', '无法写入临时文件。' );
			}
			$name = $filename;
		} else {
			$tmp = download_url( $url );
			if ( is_wp_error( $tmp ) ) {
				return $tmp;
			}
			$name = basename( wp_parse_url( $url, PHP_URL_PATH ) );
		}

		// 文件类型由 WordPress 按允许的 MIME 白名单校验(不允许上传 PHP 等可执行文件)。
		$file_array = array(
			'name'     => $name,
			'tmp_name' => $tmp,
		);

		$attach_to = isset( $args['attach_to'] ) ? (int) $args['attach_to'] : 0;
		$id        = media_handle_sideload( $file_array, $attach_to, isset( $args['title'] ) ? $args['title'] : null );

		if ( is_wp_error( $id ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return $id;
		}

		if ( isset( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}
		if ( isset( $args['caption'] ) ) {
			wp_update_post( array( 'ID' => $id, 'post_excerpt' => $args['caption'] ) );
		}

		return array( 'uploaded' => true, 'media' => $this->summarize_media( $id ) );
	}

	/**
	 * 更新附件元数据。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_media( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到附件 ID:' . $id );
		}

		$blocked = $this->guard( 'update', $args, '更新附件 #' . $id . ' 元数据' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$postarr = array( 'ID' => $id );
		if ( isset( $args['title'] ) ) {
			$postarr['post_title'] = sanitize_text_field( $args['title'] );
		}
		if ( isset( $args['caption'] ) ) {
			$postarr['post_excerpt'] = $args['caption'];
		}
		if ( count( $postarr ) > 1 ) {
			wp_update_post( $postarr );
		}
		if ( isset( $args['alt'] ) ) {
			update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $args['alt'] ) );
		}

		return array( 'updated' => true, 'media' => $this->summarize_media( $id ) );
	}

	/**
	 * 删除附件。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_media( $args ) {
		$id = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		if ( ! $id || 'attachment' !== get_post_type( $id ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到附件 ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, '永久删除附件 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_delete_attachment( $id, true );
		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 附件摘要。
	 *
	 * @param int $id 附件 ID。
	 * @return array
	 */
	private function summarize_media( $id ) {
		return array(
			'id'        => $id,
			'title'     => get_the_title( $id ),
			'url'       => wp_get_attachment_url( $id ),
			'mime_type' => get_post_mime_type( $id ),
			'alt'       => get_post_meta( $id, '_wp_attachment_image_alt', true ),
			'caption'   => wp_get_attachment_caption( $id ),
			'date'      => get_post_field( 'post_date', $id ),
		);
	}
}
