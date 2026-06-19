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
		$handlers = array(
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'handle_post' ),
				'permission_callback' => array( '\WPMCP\Mcp\Auth', 'check' ),
			),
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'handle_get' ),
				'permission_callback' => array( '\WPMCP\Mcp\Auth', 'check' ),
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
	public function handle_get() {
		return new \WP_REST_Response(
			array( 'error' => 'This MCP server uses stateless Streamable HTTP; use POST.' ),
			405
		);
	}
}
