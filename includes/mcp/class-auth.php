<?php
/**
 * 鉴权:Bearer 令牌校验 + 绑定用户 + 安全前置检查。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 处理 MCP 端点的鉴权与访问控制。
 */
class Auth {

	const OPT_TOKEN_HASH  = 'wp_mcp_token_hash';
	const OPT_BOUND_USER  = 'wp_mcp_bound_user';
	const OPT_ENABLED     = 'wp_mcp_enabled';
	const OPT_IP_ALLOW    = 'wp_mcp_ip_allowlist';

	/**
	 * REST permission_callback:校验请求是否被授权。
	 *
	 * @param \WP_REST_Request $request 请求。
	 * @return true|\WP_Error
	 */
	public static function check( $request ) {
		// 1. 总开关。
		if ( ! get_option( self::OPT_ENABLED, 1 ) ) {
			return new \WP_Error( 'wp_mcp_disabled', 'MCP 端点已停用', array( 'status' => 503 ) );
		}

		// 2. 强制 HTTPS(本地主机除外,便于开发)。
		if ( ! is_ssl() && ! self::is_local_host() ) {
			return new \WP_Error( 'wp_mcp_https_required', '必须通过 HTTPS 访问', array( 'status' => 403 ) );
		}

		// 3. IP 白名单(可选)。
		if ( ! self::ip_allowed() ) {
			return new \WP_Error( 'wp_mcp_ip_blocked', 'IP 不在白名单内', array( 'status' => 403 ) );
		}

		// 4. 令牌校验(兼容三种来源:Bearer 头 / ?token= 查询参数 / X-MCP-Token 头)。
		$token = self::extract_token( $request );

		if ( $token && self::verify_token( $token ) ) {
			$user_id = (int) get_option( self::OPT_BOUND_USER, 0 );
			if ( $user_id > 0 ) {
				wp_set_current_user( $user_id );
			}
			return true;
		}

		// 5. 兼容:已通过 Application Password 登录且具备管理权限。
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return true;
		}

		return new \WP_Error(
			'wp_mcp_unauthorized',
			'缺少或无效的访问令牌',
			array( 'status' => 401 )
		);
	}

	/**
	 * 生成新令牌,存储其哈希,返回明文(仅此一次)。
	 *
	 * @return string 明文令牌。
	 */
	public static function generate_token() {
		$token = 'mcp_' . wp_generate_password( 48, false, false );
		update_option( self::OPT_TOKEN_HASH, hash( 'sha256', $token ) );
		return $token;
	}

	/**
	 * 吊销当前令牌。
	 */
	public static function revoke_token() {
		delete_option( self::OPT_TOKEN_HASH );
	}

	/**
	 * 是否已设置令牌。
	 *
	 * @return bool
	 */
	public static function has_token() {
		return (bool) get_option( self::OPT_TOKEN_HASH, '' );
	}

	/**
	 * 校验明文令牌是否匹配存储的哈希。
	 *
	 * @param string $token 明文令牌。
	 * @return bool
	 */
	private static function verify_token( $token ) {
		$stored = get_option( self::OPT_TOKEN_HASH, '' );
		if ( empty( $stored ) ) {
			return false;
		}
		return hash_equals( $stored, hash( 'sha256', $token ) );
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
	private static function extract_token( $request ) {
		// 1) Authorization: Bearer。
		$header = self::get_auth_header();
		if ( $header && 0 === stripos( $header, 'bearer ' ) ) {
			return trim( substr( $header, 7 ) );
		}

		// 2) URL 查询参数 ?token=(仅取 URL query,避免误用 JSON-RPC 正文里的同名字段)。
		if ( $request instanceof \WP_REST_Request ) {
			$query = $request->get_query_params();
			if ( ! empty( $query['token'] ) ) {
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
	private static function get_auth_header() {
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
	private static function is_local_host() {
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
