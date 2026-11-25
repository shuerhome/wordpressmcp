<?php
/**
 * 传输层:无状态 Streamable HTTP(基于 WP REST 路由)。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 注册 MCP 端点并桥接到协议处理器。
 */
class Transport {

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
	 * 注册 REST 路由。
	 */
	public function register_routes() {
		// 同时注册到 /rpc 子路径与根 /。
		// /rpc 是推荐端点:无尾斜杠,可绕开"尾斜杠被服务器/CDN 剥除"导致的 404。
		// 鉴权在回调内完成(需要在 401 时附带 WWW-Authenticate 头,供客户端发现 OAuth)。
		$handlers = array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => '__return_true',
			),
		);

		register_rest_route( WP_MCP_REST_NAMESPACE, '/rpc', $handlers );
		register_rest_route( WP_MCP_REST_NAMESPACE, '/', $handlers );
	}

	/**
	 * 处理 POST:JSON-RPC 消息。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return \WP_REST_Response
	 */
	public function handle_post( $request ) {
		$auth = Auth::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return self::auth_error( $auth );
		}

		$server   = new Server( $this->registry );
		$response = $server->handle( $request->get_body() );

		// 纯通知:无应答,返回 202。
		if ( null === $response ) {
			return new \WP_REST_Response( null, 202 );
		}

		$rest = new \WP_REST_Response( $response, 200 );
		$rest->header( 'Content-Type', 'application/json; charset=utf-8' );
		return $rest;
	}

	/**
	 * 处理 GET:无状态模式不提供 SSE 流。
	 *
	 * @return \WP_REST_Response
	 */
	public function handle_get( $request ) {
		$auth = Auth::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			return self::auth_error( $auth );
		}
		return new \WP_REST_Response(
			array( 'error' => 'This MCP server uses stateless Streamable HTTP; use POST.' ),
			405
		);
	}

	/**
	 * 鉴权失败响应。401 时附带 WWW-Authenticate,指向 OAuth 元数据。
	 *
	 * @param \WP_Error $error 错误。
	 * @return \WP_REST_Response
	 */
	private static function auth_error( $error ) {
		$data   = $error->get_error_data();
		$status = isset( $data['status'] ) ? (int) $data['status'] : 401;
		$resp   = new \WP_REST_Response(
			array(
				'code'    => $error->get_error_code(),
				'message' => $error->get_error_message(),
			),
			$status
		);
		if ( 401 === $status ) {
			$resp->header( 'WWW-Authenticate', Auth::www_authenticate( 'wp_mcp_unauthorized' === $error->get_error_code() && false !== strpos( $error->get_error_message(), '无效' ) ? 'invalid_token' : '' ) );
		}
		return $resp;
	}

	/**
	 * 跨域:允许浏览器内客户端读取 WWW-Authenticate,并发送 MCP 协议头。
	 */
	public static function register_cors() {
		add_filter(
			'rest_exposed_cors_headers',
			function ( $headers ) {
				$headers[] = 'WWW-Authenticate';
				$headers[] = 'Mcp-Session-Id';
				return $headers;
			}
		);
		add_filter(
			'rest_allowed_cors_headers',
			function ( $headers ) {
				$headers[] = 'MCP-Protocol-Version';
				$headers[] = 'Mcp-Session-Id';
				$headers[] = 'X-MCP-Token';
				return $headers;
			}
		);
	}
}
