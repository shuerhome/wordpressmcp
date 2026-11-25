<?php
/**
 * 工具抽象基类:统一 action 分发、dry_run、确认、审计。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools;

defined( 'ABSPATH' ) || exit;

use WPMCP\Safety\Audit;
use WPMCP\Safety\Guard;

/**
 * 所有 MCP 工具的基类。
 */
abstract class Abstract_Tool {

	/**
	 * 工具名(如 wp_site)。
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * 工具描述(给模型看)。
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * JSON Schema 输入定义。
	 *
	 * @return array
	 */
	abstract public function get_input_schema();

	/**
	 * 执行某个 action。
	 *
	 * @param string $action 动作名。
	 * @param array  $args   入参(已含 action)。
	 * @return array|\WP_Error 结构化结果。
	 */
	abstract protected function run( $action, $args );

	/**
	 * 该工具支持的 action 列表。
	 *
	 * @return string[]
	 */
	abstract public function get_actions();

	/**
	 * 入口:校验、分发、审计。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	public function execute( $args ) {
		$action = isset( $args['action'] ) ? (string) $args['action'] : '';

		if ( '' === $action ) {
			return new \WP_Error( 'wp_mcp_missing_action', '缺少 action 参数' );
		}

		if ( ! in_array( $action, $this->get_actions(), true ) ) {
			return new \WP_Error(
				'wp_mcp_unknown_action',
				sprintf( '未知 action「%s」,可用:%s', $action, implode( ', ', $this->get_actions() ) )
			);
		}

		$result = $this->run( $action, $args );

		Audit::log( $this->get_name(), $args, Audit::result_of( $result ), is_wp_error( $result ) ? $result->get_error_message() : '' );

		return $result;
	}

	/**
	 * 是否为预演模式。
	 *
	 * @param array $args 入参。
	 * @return bool
	 */
	protected function is_dry_run( $args ) {
		return ! empty( $args['dry_run'] );
	}

	/**
	 * 该工具的危险(需二次确认)动作列表。子类按需覆盖。
	 *
	 * @return string[]
	 */
	protected function destructive_actions() {
		return array();
	}

	/**
	 * 写操作前置护栏:统一处理 dry_run 预演与危险操作确认。
	 *
	 * 返回非 null 时,调用方应立即把该数组作为结果返回(不再执行真实变更)。
	 * 返回 null 表示放行,可继续执行。
	 *
	 * @param string $action  动作名。
	 * @param array  $args    入参。
	 * @param string $summary 人类可读的操作摘要(预演与确认都会用到)。
	 * @return array|null
	 */
	protected function guard( $action, $args, $summary ) {
		// 1. 预演:只回报将要发生什么,不执行。
		if ( $this->is_dry_run( $args ) ) {
			return array(
				'dry_run'  => true,
				'would'    => $summary,
				'executed' => false,
			);
		}

		// 2. 危险操作:需确认令牌(受全局开关控制)。
		if ( in_array( $action, $this->destructive_actions(), true ) && get_option( 'wp_mcp_require_confirm', 1 ) ) {
			return Guard::confirm( $this->get_name(), $action, $args, $summary );
		}

		return null;
	}

	/**
	 * 该工具是否只读(所有 action 都不改数据)。子类按需覆盖。
	 *
	 * @return bool
	 */
	protected function is_read_only() {
		return false;
	}

	/**
	 * 该工具是否会访问站外资源(下载文件、连接插件目录、向外发送数据)。子类按需覆盖。
	 *
	 * @return bool
	 */
	protected function is_open_world() {
		return false;
	}

	/**
	 * MCP 工具标注(annotations):供客户端判断是否需要先请用户确认。
	 *
	 * 一个工具包含多个 action,标注按「最危险的 action」给出;
	 * 具体哪些 action 需二次确认由服务端的 confirm_token 机制保证。
	 *
	 * @return array
	 */
	public function get_annotations() {
		$read_only = $this->is_read_only();
		$out       = array(
			'title'           => $this->get_title(),
			'readOnlyHint'    => $read_only,
			'openWorldHint'   => $this->is_open_world(),
		);
		if ( ! $read_only ) {
			$out['destructiveHint'] = ! empty( $this->destructive_actions() );
			$out['idempotentHint']  = false;
		}
		return $out;
	}

	/**
	 * 给人看的工具标题(英文,便于各家客户端显示)。
	 *
	 * @return string
	 */
	public function get_title() {
		$name  = $this->get_name();
		$words = explode( '_', $name );
		$map   = array(
			'wp' => 'WordPress',
			'wc' => 'WooCommerce',
			'rest' => 'REST API',
		);
		$out = array();
		foreach ( $words as $w ) {
			$out[] = isset( $map[ $w ] ) ? $map[ $w ] : ucfirst( $w );
		}
		return implode( ' ', $out );
	}

	/**
	 * 标准化的输入 schema 公共字段(供子类合并)。
	 *
	 * @return array
	 */
	protected function common_properties() {
		return array(
			'dry_run' => array(
				'type'        => 'boolean',
				'description' => '预演模式:只返回将要改动的内容,不真正执行。',
				'default'     => false,
			),
		);
	}
}
