<?php
/**
 * OpenAPI 接口:让不支持 MCP、但支持函数调用 / OpenAPI 导入的平台也能使用同一套工具。
 *
 * - GET  /wp-json/mcp/v1/openapi.json      OpenAPI 3.0 文档(每个工具一个操作)
 * - POST /wp-json/mcp/v1/tools/{tool}      调用工具,请求体即工具参数
 *
 * 与 MCP 共用鉴权(手动令牌 / OAuth)、权限级别、限流、确认机制与审计。
 * 适用于 GPT 自定义操作(Actions)、Dify、Coze、n8n,以及直接用各家模型 API 的函数调用自建 Agent。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * OpenAPI 文档与工具调用端点。
 */
class OpenAPI {

	const DESC_LIMIT = 300; // 部分平台(如 GPT Actions)对操作说明有长度限制。

	/**
	 * 注册表。
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
		register_rest_route(
			WP_MCP_REST_NAMESPACE,
			'/openapi.json',
			array(
				'methods'             => 'GET',
				'callback'            => array( $this, 'document' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			WP_MCP_REST_NAMESPACE,
			'/tools/(?P<tool>[a-z0-9_]+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'call' ),
				'permission_callback' => '__return_true', // 鉴权在回调内完成(401 需附带 WWW-Authenticate)。
			)
		);
	}

	/**
	 * GET openapi.json。带有效令牌时只列出该连接级别可用的工具。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return \WP_REST_Response
	 */
	public function document( $request ) {
		$tier = null;
		if ( '' !== Auth::get_auth_header() ) {
			$conn = Auth::authenticate( $request );
			if ( ! is_wp_error( $conn ) ) {
				$tier = $conn->effective_tier;
			}
		}
		return new \WP_REST_Response( $this->build( $tier ), 200 );
	}

	/**
	 * POST tools/{tool}。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return \WP_REST_Response
	 */
	public function call( $request ) {
		$auth = Auth::authenticate( $request );
		if ( is_wp_error( $auth ) ) {
			$data   = $auth->get_error_data();
			$status = isset( $data['status'] ) ? (int) $data['status'] : 401;
			$resp   = new \WP_REST_Response( array( 'ok' => false, 'error' => $auth->get_error_message() ), $status );
			if ( 401 === $status ) {
				$resp->header( 'WWW-Authenticate', Auth::www_authenticate() );
			}
			return $resp;
		}

		$name = (string) $request['tool'];
		$args = $request->get_json_params();
		if ( ! is_array( $args ) ) {
			$args = array();
		}
		// 兼容把参数包在 arguments 里的调用方式。
		if ( isset( $args['arguments'] ) && is_array( $args['arguments'] ) && ! isset( $args['action'] ) ) {
			$args = $args['arguments'];
		}

		$result = Server::run_tool( $this->registry, $name, $args );

		if ( is_wp_error( $result ) ) {
			$code   = $result->get_error_code();
			$status = 400;
			if ( 'wp_mcp_unknown_tool' === $code ) {
				$status = 404;
			} elseif ( 'wp_mcp_forbidden_tier' === $code ) {
				$status = 403;
			} elseif ( 'wp_mcp_rate_limited' === $code ) {
				$status = 429;
			}
			return new \WP_REST_Response( array( 'ok' => false, 'error' => $result->get_error_message() ), $status );
		}

		return new \WP_REST_Response( array( 'ok' => true, 'result' => $result ), 200 );
	}

	/**
	 * 生成 OpenAPI 3.0 文档。
	 *
	 * @param string|null $tier 连接级别;null 表示列出全部工具。
	 * @return array
	 */
	public function build( $tier = null ) {
		$tools = $this->registry->list_for_mcp();
		if ( $tier ) {
			$tools = Policy::filter_tools( $tier, $tools );
		}

		$paths = array();
		foreach ( $tools as $t ) {
			$desc = Registry::english_description( $t['name'] );
			if ( '' === $desc ) {
				$desc = $t['description'];
			}
			$paths[ '/tools/' . $t['name'] ] = array(
				'post' => array(
					'operationId' => $t['name'],
					'summary'     => $t['title'],
					'description' => self::clip( $desc ),
					'x-openai-isConsequential' => empty( $t['annotations']['readOnlyHint'] ),
					'requestBody' => array(
						'required' => true,
						'content'  => array(
							'application/json' => array( 'schema' => $t['inputSchema'] ),
						),
					),
					'responses'   => array(
						'200' => array(
							'description' => 'Tool result. If result.confirmation_required is true, ask the user, then call again with the same arguments plus confirm_token.',
							'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/ToolResult' ) ) ),
						),
						'default' => array(
							'description' => 'Error (bad arguments, permission level, rate limit).',
							'content'     => array( 'application/json' => array( 'schema' => array( '$ref' => '#/components/schemas/ToolError' ) ) ),
						),
					),
				),
			);
		}

		$doc = array(
			'openapi'    => '3.0.3',
			'info'       => array(
				'title'       => get_bloginfo( 'name' ) . ' – WP MCP',
				'version'     => WP_MCP_VERSION,
				'description' => 'Manage this WooCommerce store. Each operation is one tool; pass "action" plus its parameters. Start with wp_site (action=capabilities). Write actions accept dry_run=true. Destructive actions return confirmation_required with a confirm_token: ask the user, then repeat the call with confirm_token.',
			),
			'servers'    => array( array( 'url' => untrailingslashit( rest_url( WP_MCP_REST_NAMESPACE ) ) ) ),
			'paths'      => empty( $paths ) ? new \stdClass() : $paths,
			'components' => array(
				'schemas'         => array(
					'ToolResult' => array(
						'type'       => 'object',
						'properties' => array(
							'ok'     => array( 'type' => 'boolean' ),
							'result' => array( 'type' => 'object', 'additionalProperties' => true ),
						),
					),
					'ToolError'  => array(
						'type'       => 'object',
						'properties' => array(
							'ok'    => array( 'type' => 'boolean' ),
							'error' => array( 'type' => 'string' ),
						),
					),
				),
				'securitySchemes' => array(
					'bearerAuth' => array(
						'type'        => 'http',
						'scheme'      => 'bearer',
						'description' => 'Token created in WordPress admin: Settings → WP MCP → New token.',
					),
				),
			),
			'security'   => array( array( 'bearerAuth' => array() ) ),
		);

		if ( OAuth::enabled() ) {
			$doc['components']['securitySchemes']['oauth2'] = array(
				'type'  => 'oauth2',
				'flows' => array(
					'authorizationCode' => array(
						'authorizationUrl' => OAuth::endpoint( 'authorize' ),
						'tokenUrl'         => OAuth::endpoint( 'token' ),
						'refreshUrl'       => OAuth::endpoint( 'token' ),
						'scopes'           => array(
							'mcp:read' => 'Read-only',
							'mcp:ops'  => 'Store operations (no refunds)',
							'mcp:full' => 'Full administration',
						),
					),
				),
			);
			$doc['security'][] = array( 'oauth2' => array() );
		}

		return $doc;
	}

	/**
	 * 截断说明文字。
	 *
	 * @param string $text 文本。
	 * @return string
	 */
	private static function clip( $text ) {
		$text = trim( preg_replace( '/\s+/', ' ', (string) $text ) );
		if ( mb_strlen( $text ) <= self::DESC_LIMIT ) {
			return $text;
		}
		return rtrim( mb_substr( $text, 0, self::DESC_LIMIT - 1 ) ) . '…';
	}
}
