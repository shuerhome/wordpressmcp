<?php
/**
 * wp_comments 工具:评论管理(阶段 4),作者 PII 默认脱敏。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;
use WPMCP\Safety\PII;

/**
 * 评论增删改查与审核工具。
 */
class WP_Comments_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_comments';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '评论管理:list=列出/筛选; get=取单条; approve=通过; unapprove=改待审; spam=标记垃圾; trash=移入回收站; reply=回复(以绑定用户身份); edit=改内容; delete=永久删除(需确认)。作者邮箱/名称默认脱敏(reveal_pii=true 解锁)。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'approve', 'unapprove', 'spam', 'trash', 'reply', 'edit', 'delete' );
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
					'action'     => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'id'         => array(
						'type'        => 'integer',
						'description' => 'get/approve/unapprove/spam/trash/reply/edit/delete 的评论 ID。',
					),
					'post_id'    => array(
						'type'        => 'integer',
						'description' => 'list 按文章过滤;reply 时可省略(自动取父评论所属文章)。',
					),
					'status'     => array(
						'type'        => 'string',
						'description' => 'list 状态:hold(待审)/approve/spam/trash/all,默认 all。',
						'default'     => 'all',
					),
					'search'     => array(
						'type'        => 'string',
						'description' => 'list 关键词。',
					),
					'content'    => array(
						'type'        => 'string',
						'description' => 'reply/edit 的评论内容。',
					),
					'per_page'   => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'reveal_pii' => array(
						'type'        => 'boolean',
						'description' => '解锁评论作者 PII(默认脱敏)。',
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
				return $this->list_comments( $args );
			case 'get':
				return $this->get_comment_data( $args );
			case 'approve':
				return $this->set_status( $args, 'approve', '通过' );
			case 'unapprove':
				return $this->set_status( $args, 'hold', '改为待审' );
			case 'spam':
				return $this->set_status( $args, 'spam', '标记垃圾' );
			case 'trash':
				return $this->trash_comment( $args );
			case 'reply':
				return $this->reply_comment( $args );
			case 'edit':
				return $this->edit_comment( $args );
			case 'delete':
				return $this->delete_comment( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 列出评论。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_comments( $args ) {
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );
		$mask     = PII::should_mask( $args );

		$query = array(
			'status' => isset( $args['status'] ) ? sanitize_key( $args['status'] ) : 'all',
			'number' => $per_page,
			'offset' => ( $page - 1 ) * $per_page,
		);
		if ( ! empty( $args['post_id'] ) ) {
			$query['post_id'] = (int) $args['post_id'];
		}
		if ( ! empty( $args['search'] ) ) {
			$query['search'] = sanitize_text_field( $args['search'] );
		}

		$comments = get_comments( $query );
		$items    = array();
		foreach ( $comments as $comment ) {
			$items[] = $this->summarize_comment( $comment, $mask );
		}

		return array(
			'items'      => $items,
			'page'       => $page,
			'per_page'   => $per_page,
			'pii_masked' => $mask,
		);
	}

	/**
	 * 取单条评论。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_comment_data( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到评论 ID:' . $id );
		}
		$mask            = PII::should_mask( $args );
		$data            = $this->summarize_comment( $comment, $mask );
		$data['content'] = $comment->comment_content;
		return $data;
	}

	/**
	 * 改评论状态。
	 *
	 * @param array  $args   入参。
	 * @param string $status approve/hold/spam。
	 * @param string $label  摘要用中文标签。
	 * @return array|\WP_Error
	 */
	private function set_status( $args, $status, $label ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到评论 ID:' . $id );
		}

		$blocked = $this->guard( $args['action'], $args, sprintf( '评论 #%d %s', $id, $label ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_set_comment_status( $id, $status, true );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'updated' => true, 'id' => $id, 'status' => wp_get_comment_status( $id ) );
	}

	/**
	 * 移入回收站。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function trash_comment( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到评论 ID:' . $id );
		}

		$blocked = $this->guard( 'trash', $args, sprintf( '评论 #%d 移入回收站', $id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_trash_comment( $id );
		return array( 'trashed' => (bool) $result, 'id' => $id );
	}

	/**
	 * 回复评论(以绑定用户身份)。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function reply_comment( $args ) {
		$id     = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$parent = $id ? get_comment( $id ) : null;
		if ( ! $parent ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到父评论 ID:' . $id );
		}
		$content = isset( $args['content'] ) ? (string) $args['content'] : '';
		if ( '' === trim( $content ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'reply 需要 content。' );
		}

		$blocked = $this->guard( 'reply', $args, sprintf( '回复评论 #%d', $id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$user    = wp_get_current_user();
		$post_id = ! empty( $args['post_id'] ) ? (int) $args['post_id'] : (int) $parent->comment_post_ID;

		$comment_id = wp_insert_comment(
			array(
				'comment_post_ID'      => $post_id,
				'comment_parent'       => $id,
				'comment_content'      => $content,
				'user_id'              => $user ? $user->ID : 0,
				'comment_author'       => $user ? $user->display_name : '',
				'comment_author_email' => $user ? $user->user_email : '',
				'comment_approved'     => 1,
			)
		);

		if ( ! $comment_id ) {
			return new \WP_Error( 'wp_mcp_reply_failed', '回复失败。' );
		}

		return array( 'replied' => true, 'comment_id' => $comment_id, 'parent' => $id );
	}

	/**
	 * 编辑评论内容。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function edit_comment( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到评论 ID:' . $id );
		}
		if ( ! isset( $args['content'] ) ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'edit 需要 content。' );
		}

		$blocked = $this->guard( 'edit', $args, sprintf( '编辑评论 #%d 内容', $id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_update_comment(
			array(
				'comment_ID'      => $id,
				'comment_content' => (string) $args['content'],
			)
		);
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array( 'updated' => true, 'id' => $id );
	}

	/**
	 * 永久删除评论。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_comment( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$comment = $id ? get_comment( $id ) : null;
		if ( ! $comment ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到评论 ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, sprintf( '永久删除评论 #%d', $id ) );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = wp_delete_comment( $id, true );
		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 评论摘要(按需脱敏)。
	 *
	 * @param \WP_Comment $comment 评论。
	 * @param bool        $mask    是否脱敏。
	 * @return array
	 */
	private function summarize_comment( $comment, $mask ) {
		$author = $comment->comment_author;
		$email  = $comment->comment_author_email;

		return array(
			'id'            => (int) $comment->comment_ID,
			'post_id'       => (int) $comment->comment_post_ID,
			'post_title'    => get_the_title( $comment->comment_post_ID ),
			'author'        => $mask ? PII::name( $author ) : $author,
			'author_email'  => $mask ? PII::email( $email ) : $email,
			'date'          => $comment->comment_date,
			'status'        => wp_get_comment_status( $comment ),
			'parent'        => (int) $comment->comment_parent,
			'excerpt'       => wp_trim_words( wp_strip_all_tags( $comment->comment_content ), 30 ),
		);
	}
}
