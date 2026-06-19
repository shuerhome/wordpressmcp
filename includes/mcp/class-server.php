<?php
/**
 * MCP 协议处理器:解析 JSON-RPC 2.0,分发 MCP 方法。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 处理单条/批量 JSON-RPC 请求。
 */
class Server {

	const PROTOCOL_VERSION   = '2025-06-18';
	const SUPPORTED_VERSIONS = array( '2025-06-18', '2025-03-26', '2024-11-05' );

	/**
	 * 工具注册表。
	 *
	 * @var Registry
	 */
	private $registry;

	/**
	 * 构造。
	 *
	 * @param Registry $registry 注册表。
	 */
	public function __construct( Registry $registry ) {
		$this->registry = $registry;
	}

	/**
	 * 处理一段原始请求体(可能是单条或批量),返回应答数组(或 null 表示纯通知)。
	 *
	 * @param string $raw_body 原始 JSON 字符串。
	 * @return array|null
	 */
	public function handle( $raw_body ) {
		$decoded = json_decode( $raw_body, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return $this->error_response( null, -32700, 'Parse error' );
		}

		// 批量请求。
		if ( is_array( $decoded ) && isset( $decoded[0] ) ) {
			$responses = array();
			foreach ( $decoded as $message ) {
				$resp = $this->handle_message( $message );
				if ( null !== $resp ) {
					$responses[] = $resp;
				}
			}
			return empty( $responses ) ? null : $responses;
		}

		return $this->handle_message( $decoded );
	}

	/**
	 * 处理单条 JSON-RPC 消息。
	 *
	 * @param mixed $message 解码后的消息。
	 * @return array|null 应答(通知则为 null)。
	 */
	private function handle_message( $message ) {
		if ( ! is_array( $message ) || ! isset( $message['method'] ) ) {
			return $this->error_response( null, -32600, 'Invalid Request' );
		}

		$method = $message['method'];
		$id     = isset( $message['id'] ) ? $message['id'] : null;
		$params = isset( $message['params'] ) && is_array( $message['params'] ) ? $message['params'] : array();

		// 通知(无 id):仅 ack,不返回结果。
		$is_notification = ! array_key_exists( 'id', $message );

		switch ( $method ) {
			case 'initialize':
				return $this->result_response( $id, $this->initialize( $params ) );

			case 'notifications/initialized':
			case 'notifications/cancelled':
				return null; // 通知,无应答。

			case 'ping':
				return $this->result_response( $id, new \stdClass() );

			case 'tools/list':
				return $this->result_response( $id, array( 'tools' => $this->registry->list_for_mcp() ) );

			case 'tools/call':
				return $this->tools_call( $id, $params );

			case 'resources/list':
				return $this->result_response( $id, array( 'resources' => array() ) );

			case 'prompts/list':
				return $this->result_response( $id, array( 'prompts' => array() ) );

			default:
				if ( $is_notification ) {
					return null;
				}
				return $this->error_response( $id, -32601, 'Method not found: ' . $method );
		}
	}

	/**
	 * initialize 握手。
	 *
	 * @param array $params 客户端参数。
	 * @return array
	 */
	private function initialize( $params ) {
		$requested = isset( $params['protocolVersion'] ) ? $params['protocolVersion'] : self::PROTOCOL_VERSION;
		$version   = in_array( $requested, self::SUPPORTED_VERSIONS, true ) ? $requested : self::PROTOCOL_VERSION;

		return array(
			'protocolVersion' => $version,
			'capabilities'    => array(
				'tools'     => new \stdClass(),
				'resources' => new \stdClass(),
				'prompts'   => new \stdClass(),
			),
			'serverInfo'      => array(
				'name'    => 'wp-mcp',
				'version' => WP_MCP_VERSION,
			),
		);
	}

	/**
	 * tools/call:执行工具。
	 *
	 * @param mixed $id     请求 id。
	 * @param array $params 参数(name + arguments)。
	 * @return array
	 */
	private function tools_call( $id, $params ) {
		$name = isset( $params['name'] ) ? (string) $params['name'] : '';
		$args = isset( $params['arguments'] ) && is_array( $params['arguments'] ) ? $params['arguments'] : array();

		$tool = $this->registry->get( $name );
		if ( ! $tool ) {
			return $this->error_response( $id, -32602, '未知工具:' . $name );
		}

		$result = $tool->execute( $args );

		if ( is_wp_error( $result ) ) {
			// 工具级错误:以 isError 形式返回(非协议错误)。
			return $this->result_response(
				$id,
				array(
					'content' => array(
						array(
							'type' => 'text',
							'text' => $result->get_error_message(),
						),
					),
					'isError' => true,
				)
			);
		}

		$json = wp_json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return $this->result_response(
			$id,
			array(
				'content'           => array(
					array(
						'type' => 'text',
						'text' => $json,
					),
				),
				'structuredContent' => $result,
				'isError'           => false,
			)
		);
	}

	/**
	 * 构造成功应答。
	 *
	 * @param mixed $id     id。
	 * @param mixed $result 结果。
	 * @return array
	 */
	private function result_response( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * 构造错误应答。
	 *
	 * @param mixed  $id      id。
	 * @param int    $code    错误码。
	 * @param string $message 错误信息。
	 * @return array
	 */
	private function error_response( $id, $code, $message ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => array(
				'code'    => $code,
				'message' => $message,
			),
		);
	}
}
