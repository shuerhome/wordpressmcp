<?php
/**
 * 鉴权:按令牌识别连接、绑定用户、计算有效级别,以及安全前置检查。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 处理 MCP 端点的鉴权与访问控制。
 */
class Auth {

	const OPT_TOKEN_HASH  = 'wp_mcp_token_hash'; // 0.5.0 前的单令牌(升级时迁移为一条连接)。
	const OPT_BOUND_USER  = 'wp_mcp_bound_user';
	const OPT_ENABLED     = 'wp_mcp_enabled';
	const OPT_IP_ALLOW    = 'wp_mcp_ip_allowlist';

	/**
	 * 本次请求的连接(含有效级别 effective_tier)。
	 *
	 * @var object|null
	 */
	private static $current = null;

	/**
	 * 当前连接。
	 *
	 * @return object|null
	 */
	public static function current() {
		return self::$current;
	}

	/**
	 * 当前连接的有效级别。
	 *
	 * @return string|null
	 */
	public static function tier() {
		return self::$current ? self::$current->effective_tier : null;
	}

	/**
	 * 校验 MCP 请求,成功时绑定用户并记录当前连接。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return object|\WP_Error 连接或错误(错误的 data.status 为 HTTP 状态码)。
	 */
	public static function authenticate( $request ) {
		self::$current = null;

		$pre = self::precheck();
		if ( is_wp_error( $pre ) ) {
			return $pre;
		}

		$from_query = false;
		$token      = self::extract_token( $request, $from_query );
		$conn       = null;

		if ( '' !== $token ) {
			$conn = Connections::find_by_token( $token );
			// URL 中的令牌只接受手动令牌(OAuth 访问令牌必须走请求头)。
			if ( $conn && $from_query && 'static' !== $conn->type ) {
				$conn = null;
			}
			if ( ! $conn ) {
				return new \WP_Error( 'wp_mcp_unauthorized', '访问令牌无效、已过期或已吊销', array( 'status' => 401 ) );
			}
		} elseif ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			// 兼容:通过 Application Password(Basic 认证)登录的管理员。
			$conn = (object) array(
				'id'      => 0,
				'name'    => 'Application Password: ' . wp_get_current_user()->user_login,
				'type'    => 'app_password',
				'tier'    => Connections::TIER_FULL,
				'user_id' => get_current_user_id(),
			);
		}

		if ( ! $conn ) {
			return new \WP_Error( 'wp_mcp_unauthorized', '缺少访问令牌', array( 'status' => 401 ) );
		}

		$max = Connections::max_tier_for_user( (int) $conn->user_id );
		if ( null === $max ) {
			return new \WP_Error( 'wp_mcp_forbidden', '该连接绑定的用户已不存在或没有店铺管理权限', array( 'status' => 403 ) );
		}

		wp_set_current_user( (int) $conn->user_id );
		$conn->effective_tier = Connections::min_tier( $conn->tier, $max );
		self::$current        = $conn;

		if ( $conn->id ) {
			Connections::touch( $conn, \WPMCP\Safety\Audit::ip() );
		}
		return $conn;
	}

	/**
	 * 通用前置检查:总开关、HTTPS、IP 白名单。OAuth 端点也复用。
	 *
	 * @return true|\WP_Error
	 */
	public static function precheck() {
		if ( ! get_option( self::OPT_ENABLED, 1 ) ) {
			return new \WP_Error( 'wp_mcp_disabled', 'MCP 端点已停用', array( 'status' => 503 ) );
		}
		if ( ! is_ssl() && ! self::is_local_host() ) {
			return new \WP_Error( 'wp_mcp_https_required', '必须通过 HTTPS 访问', array( 'status' => 403 ) );
		}
		if ( ! self::ip_allowed() ) {
			return new \WP_Error( 'wp_mcp_ip_blocked', 'IP 不在白名单内', array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * 未授权时的 WWW-Authenticate 头(指向 OAuth 受保护资源元数据,供客户端自动发现登录方式)。
	 *
	 * @param string $error 可选错误码(invalid_token 等)。
	 * @return string
	 */
	public static function www_authenticate( $error = '' ) {
		$value = 'Bearer resource_metadata="' . OAuth::resource_metadata_url() . '"';
		$value .= ', scope="' . implode( ' ', OAuth::scopes() ) . '"';
		if ( $error ) {
			$value .= ', error="' . $error . '"';
		}
		return $value;
	}

	/**
	 * 提取访问令牌,按优先级尝试三种来源:
	 *  1) Authorization: Bearer <token>  —— Claude Code / 标准 MCP 客户端。
	 *  2) ?token=<token> 查询参数         —— claude.ai 网页端连接器(令牌内嵌在 URL,无法传自定义头)。
	 *  3) X-MCP-Token: <token> 头         —— 其他可设头但不便用 Authorization 的客户端。
	 *
	 * @param \WP_REST_Request|mixed $request 请求。
	 * @return string
	 */
	private static function extract_token( $request, &$from_query = false ) {
		// 1) Authorization: Bearer。
		$header = self::get_auth_header();
		if ( $header && 0 === stripos( $header, 'bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		// 2) URL 查询参数 ?token=(仅取 URL query,避免误用 JSON-RPC 正文里的同名字段)。
		if ( $request instanceof \WP_REST_Request ) {
			$query = $request->get_query_params();
			if ( ! empty( $query['token'] ) ) {
				$from_query = true;
				return trim( (string) $query['token'] );
			}
		}

		// 3) X-MCP-Token 头。
		if ( ! empty( $_SERVER['HTTP_X_MCP_TOKEN'] ) ) {
			return trim( wp_unslash( $_SERVER['HTTP_X_MCP_TOKEN'] ) );
		}

		return '';
	}

	/**
	 * 兼容多种服务器环境获取 Authorization 头。
	 *
	 * @return string
	 */
	public static function get_auth_header() {
		if ( ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
			return trim( wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) );
		}
		if ( ! empty( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) ) {
			return trim( wp_unslash( $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ) );
		}
		if ( function_exists( 'getallheaders' ) ) {
			foreach ( getallheaders() as $key => $value ) {
				if ( 'authorization' === strtolower( $key ) ) {
					return trim( $value );
				}
			}
		}
		return '';
	}

	/**
	 * 当前请求是否来自本地主机。
	 *
	 * @return bool
	 */
	public static function is_local_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		$host = preg_replace( '/:\d+$/', '', $host );
		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}
		if ( '.local' === substr( $host, -6 ) || '.test' === substr( $host, -5 ) ) {
			return true;
		}
		return false;
	}

	/**
	 * IP 是否被允许(白名单为空则全部放行)。
	 *
	 * @return bool
	 */
	private static function ip_allowed() {
		$allow = trim( (string) get_option( self::OPT_IP_ALLOW, '' ) );
		if ( '' === $allow ) {
			return true;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? trim( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		$list = array_filter( array_map( 'trim', preg_split( '/[\s,]+/', $allow ) ) );
		return in_array( $ip, $list, true );
	}
}
