<?php
/**
 * OAuth 2.1 授权服务器 + 受保护资源元数据(按 MCP 授权规范)。
 *
 * 客户端接入流程:
 * 1. 调用 MCP 端点得到 401,响应头 WWW-Authenticate 指向受保护资源元数据。
 * 2. 读取 /.well-known/oauth-protected-resource → 找到授权服务器 → 读取
 *    /.well-known/oauth-authorization-server 得到各端点。
 * 3. 动态注册客户端(/mcp-oauth/register),或使用「客户端元数据文档」(client_id 为 https 网址)。
 * 4. 浏览器打开 /mcp-oauth/authorize:登录 WordPress → 授权页选择权限级别 → 回跳带 code。
 * 5. /mcp-oauth/token 用 code + PKCE 换访问令牌与刷新令牌;之后刷新令牌轮换。
 *
 * 所有端点都走前台路径(不经 REST API),避免 WordPress 应用密码机制拦截客户端的 Basic 认证头。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

use WPMCP\Safety\Audit;
use WPMCP\Safety\Rate_Limit;

/**
 * OAuth 端点与客户端管理。
 */
class OAuth {

	const OPT_ENABLED   = 'wp_mcp_oauth_enabled';
	const OPT_DCR       = 'wp_mcp_oauth_dcr';
	const CODE_TTL      = 600;
	const CODE_PREFIX   = 'wp_mcp_oc_';
	const CIMD_PREFIX   = 'wp_mcp_cimd_';
	const MAX_CLIENTS   = 1000;
	const CONSENT_NONCE = 'wp_mcp_oauth_consent';

	/**
	 * 挂钩子。
	 */
	public function register() {
		add_action( 'parse_request', array( $this, 'route' ), 0 );
	}

	/**
	 * 是否启用 OAuth。
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) get_option( self::OPT_ENABLED, 1 );
	}

	/**
	 * 支持的 scope。
	 *
	 * @return string[]
	 */
	public static function scopes() {
		return array( 'mcp:read', 'mcp:ops', 'mcp:full' );
	}

	/**
	 * 发行者(授权服务器标识)。
	 *
	 * @return string
	 */
	public static function issuer() {
		return untrailingslashit( home_url() );
	}

	/**
	 * MCP 端点(受保护资源)。
	 *
	 * @return string
	 */
	public static function resource() {
		return rest_url( WP_MCP_REST_NAMESPACE . '/rpc' );
	}

	/**
	 * 受保护资源元数据 URL。
	 *
	 * @return string
	 */
	public static function resource_metadata_url() {
		return home_url( '/.well-known/oauth-protected-resource' );
	}

	/**
	 * 某个 OAuth 端点的 URL。
	 *
	 * @param string $name authorize / token / register / revoke。
	 * @return string
	 */
	public static function endpoint( $name ) {
		return home_url( '/mcp-oauth/' . $name );
	}

	/**
	 * 前台路由分发(parse_request 最早阶段)。
	 */
	public function route() {
		$path = $this->relative_path();
		if ( null === $path ) {
			return;
		}

		if ( 0 === strpos( $path, '/.well-known/oauth-protected-resource' ) ) {
			$this->cors();
			$this->json( $this->protected_resource_metadata() );
		}
		if ( 0 === strpos( $path, '/.well-known/oauth-authorization-server' ) || 0 === strpos( $path, '/.well-known/openid-configuration' ) ) {
			$this->cors();
			if ( ! self::enabled() ) {
				$this->json( array( 'error' => 'not_found' ), 404 );
			}
			$this->json( $this->authorization_server_metadata() );
		}

		if ( 0 !== strpos( $path, '/mcp-oauth/' ) ) {
			return;
		}

		$name = trim( substr( $path, strlen( '/mcp-oauth/' ) ), '/' );
		if ( 'authorize' !== $name ) {
			$this->cors();
		}
		if ( ! self::enabled() ) {
			$this->oauth_error( 'access_denied', '本站未启用 OAuth 登录。', 404 );
		}
		$pre = Auth::precheck();
		if ( is_wp_error( $pre ) ) {
			$data = $pre->get_error_data();
			$this->oauth_error( 'access_denied', $pre->get_error_message(), isset( $data['status'] ) ? $data['status'] : 403 );
		}

		switch ( $name ) {
			case 'authorize':
				$this->authorize();
				break;
			case 'token':
				$this->token();
				break;
			case 'register':
				$this->register_client();
				break;
			case 'revoke':
				$this->revoke();
				break;
		}
		$this->oauth_error( 'invalid_request', '未知的 OAuth 端点。', 404 );
	}

	/**
	 * 受保护资源元数据(RFC 9728)。
	 *
	 * @return array
	 */
	public function protected_resource_metadata() {
		$meta = array(
			'resource'                 => self::resource(),
			'resource_name'            => get_bloginfo( 'name' ) . ' (WP MCP)',
			'bearer_methods_supported' => array( 'header' ),
			'scopes_supported'         => self::scopes(),
		);
		if ( self::enabled() ) {
			$meta['authorization_servers'] = array( self::issuer() );
		}
		return $meta;
	}

	/**
	 * 授权服务器元数据(RFC 8414)。
	 *
	 * @return array
	 */
	public function authorization_server_metadata() {
		$meta = array(
			'issuer'                                => self::issuer(),
			'authorization_endpoint'                => self::endpoint( 'authorize' ),
			'token_endpoint'                        => self::endpoint( 'token' ),
			'revocation_endpoint'                   => self::endpoint( 'revoke' ),
			'response_types_supported'              => array( 'code' ),
			'response_modes_supported'              => array( 'query' ),
			'grant_types_supported'                 => array( 'authorization_code', 'refresh_token' ),
			'code_challenge_methods_supported'      => array( 'S256' ),
			'token_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'revocation_endpoint_auth_methods_supported' => array( 'none', 'client_secret_post', 'client_secret_basic' ),
			'scopes_supported'                      => self::scopes(),
			'client_id_metadata_document_supported' => true,
			'service_documentation'                 => 'https://github.com/shuerhome/wordpressmcp',
		);
		if ( get_option( self::OPT_DCR, 1 ) ) {
			$meta['registration_endpoint'] = self::endpoint( 'register' );
		}
		return $meta;
	}

	// ---------------------------------------------------------------------
	// 授权端点
	// ---------------------------------------------------------------------

	/**
	 * 授权页:登录 → 选择权限级别 → 回跳。
	 */
	private function authorize() {
		nocache_headers();
		header( 'X-Frame-Options: DENY' );
		header( "Content-Security-Policy: frame-ancestors 'none'" );

		$is_post = isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === $_SERVER['REQUEST_METHOD'];
		$src     = $is_post ? $_POST : $_GET; // phpcs:ignore WordPress.Security.NonceVerification
		$p       = array();
		foreach ( array( 'response_type', 'client_id', 'redirect_uri', 'state', 'scope', 'code_challenge', 'code_challenge_method', 'resource' ) as $k ) {
			$p[ $k ] = isset( $src[ $k ] ) ? trim( (string) wp_unslash( $src[ $k ] ) ) : '';
		}

		// 1. 先确认客户端与回跳地址;之前出错只显示错误页,不能回跳(防止开放重定向)。
		$client = self::get_client( $p['client_id'] );
		if ( ! $client ) {
			$this->error_page( '未知的客户端', '该应用尚未在本站注册,或注册信息已失效。请回到应用中重新连接。' );
		}
		if ( '' === $p['redirect_uri'] && 1 === count( $client['redirect_uris'] ) ) {
			$p['redirect_uri'] = $client['redirect_uris'][0];
		}
		if ( ! self::redirect_matches( $p['redirect_uri'], $client['redirect_uris'] ) ) {
			$this->error_page( '回跳地址不匹配', '应用提供的回跳地址与注册时不一致,出于安全已拒绝。' );
		}

		// 2. 之后的错误通过回跳通知客户端。
		if ( 'code' !== $p['response_type'] ) {
			$this->redirect_error( $p, 'unsupported_response_type', '只支持 response_type=code。' );
		}
		if ( '' === $p['code_challenge'] || 'S256' !== $p['code_challenge_method'] ) {
			$this->redirect_error( $p, 'invalid_request', '必须使用 PKCE(code_challenge_method=S256)。' );
		}
		if ( '' !== $p['resource'] && ! self::resource_ok( $p['resource'] ) ) {
			$this->redirect_error( $p, 'invalid_target', 'resource 参数不是本站的 MCP 端点。' );
		}

		// 3. 需要登录 WordPress。
		if ( ! is_user_logged_in() ) {
			$here = ( is_ssl() ? 'https://' : 'http://' ) . ( isset( $_SERVER['HTTP_HOST'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '' ) . ( isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '' ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			wp_safe_redirect( wp_login_url( $here ) );
			exit;
		}

		$user_id = get_current_user_id();
		$max     = Connections::max_tier_for_user( $user_id );
		if ( null === $max ) {
			$this->error_page( '没有权限', '当前登录的账号没有店铺管理权限,不能授权 AI 应用访问本站。请换用管理员或店铺管理员账号登录。' );
		}

		if ( ! $is_post ) {
			$this->consent_page( $p, $client, $max );
		}

		// 4. 处理授权页提交。
		if ( ! isset( $_POST['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ), self::CONSENT_NONCE ) ) {
			$this->error_page( '页面已过期', '请回到应用中重新发起连接。' );
		}
		if ( ! isset( $_POST['decision'] ) || 'allow' !== $_POST['decision'] ) {
			Audit::log( 'oauth', array( 'action' => 'authorize', 'id' => $client['client_id'] ), 'denied', '用户拒绝授权:' . $client['name'] );
			$this->redirect_error( $p, 'access_denied', '用户拒绝了授权。' );
		}

		$tier = isset( $_POST['tier'] ) ? sanitize_key( wp_unslash( $_POST['tier'] ) ) : '';
		if ( ! Connections::rank( $tier ) || Connections::rank( $tier ) > Connections::rank( $max ) ) {
			$this->error_page( '权限级别无效', '请返回重试。' );
		}
		$name = isset( $_POST['conn_name'] ) ? sanitize_text_field( wp_unslash( $_POST['conn_name'] ) ) : $client['name'];

		$code = wp_generate_password( 43, false, false );
		set_transient(
			self::CODE_PREFIX . Connections::hash( $code ),
			array(
				'client_id'      => $client['client_id'],
				'redirect_uri'   => $p['redirect_uri'],
				'code_challenge' => $p['code_challenge'],
				'user_id'        => $user_id,
				'tier'           => $tier,
				'name'           => $name,
			),
			self::CODE_TTL
		);
		self::touch_client( $client['client_id'] );
		Audit::log( 'oauth', array( 'action' => 'authorize', 'id' => $client['client_id'] ), 'success', sprintf( '授权「%s」级别 %s', $name, $tier ) );

		$this->redirect_to( $p['redirect_uri'], array( 'code' => $code, 'state' => $p['state'], 'iss' => self::issuer() ) );
	}

	/**
	 * 渲染授权页。
	 *
	 * @param array  $p      授权参数。
	 * @param array  $client 客户端。
	 * @param string $max    用户可授予的最高级别。
	 */
	private function consent_page( $p, $client, $max ) {
		$requested = '';
		foreach ( preg_split( '/\s+/', $p['scope'] ) as $s ) {
			if ( 0 === strpos( $s, 'mcp:' ) && Connections::rank( substr( $s, 4 ) ) > Connections::rank( $requested ) ) {
				$requested = substr( $s, 4 );
			}
		}
		$default = $requested ? $requested : Connections::TIER_OPS;
		$default = Connections::min_tier( $default, $max );

		$host = wp_parse_url( $p['redirect_uri'], PHP_URL_HOST );
		if ( ! $host ) {
			$host = strtok( $p['redirect_uri'], ':' ) . '://';
		}
		$user = wp_get_current_user();

		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );
		?>
<!doctype html>
<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>授权 AI 应用访问 <?php echo esc_html( get_bloginfo( 'name' ) ); ?></title>
<style>
body{font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI","PingFang SC","Microsoft YaHei",sans-serif;background:#f0f0f1;color:#1d2327;margin:0;padding:24px 16px}
.box{max-width:520px;margin:0 auto;background:#fff;border-radius:8px;padding:24px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
h1{font-size:20px;margin:0 0 12px}.muted{color:#646970;font-size:13px}code{background:#f6f7f7;padding:2px 4px;border-radius:3px;word-break:break-all}
label.tier{display:block;border:1px solid #dcdcde;border-radius:6px;padding:10px 12px;margin:8px 0;cursor:pointer}
label.tier input{margin-right:6px}.desc{display:block;color:#646970;font-size:13px;margin-left:22px}
input[type=text]{width:100%;box-sizing:border-box;padding:8px;border:1px solid #8c8f94;border-radius:4px}
.btns{display:flex;gap:12px;margin-top:20px}button{flex:1;padding:10px;font-size:15px;border-radius:4px;cursor:pointer;border:1px solid #2271b1}
.allow{background:#2271b1;color:#fff}.deny{background:#fff;color:#2271b1}.warn{background:#fcf9e8;border-left:4px solid #dba617;padding:8px 12px;font-size:13px}
</style></head><body><div class="box">
<h1>「<?php echo esc_html( $client['name'] ); ?>」请求访问本站</h1>
<p>站点:<strong><?php echo esc_html( get_bloginfo( 'name' ) ); ?></strong><br>
授权账号:<strong><?php echo esc_html( $user->display_name ); ?></strong>(<?php echo esc_html( $user->user_login ); ?>)<br>
授权后跳转到:<code><?php echo esc_html( $host ); ?></code></p>
<p class="warn">只在你本人刚刚发起连接时才点「允许」。AI 应用将以你的账号身份、在所选权限内操作本站;删除、退款等危险操作仍需逐次确认。可随时在「设置 → WP MCP」吊销。</p>
<form method="post">
<?php wp_nonce_field( self::CONSENT_NONCE ); ?>
<?php foreach ( $p as $k => $v ) : ?>
<input type="hidden" name="<?php echo esc_attr( $k ); ?>" value="<?php echo esc_attr( $v ); ?>">
<?php endforeach; ?>
<p><strong>权限级别</strong></p>
<?php foreach ( Connections::tiers() as $key => $t ) : ?>
	<?php if ( Connections::rank( $key ) <= Connections::rank( $max ) ) : ?>
<label class="tier"><input type="radio" name="tier" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, $default ); ?>><strong><?php echo esc_html( $t['label'] ); ?></strong><span class="desc"><?php echo esc_html( $t['desc'] ); ?></span></label>
	<?php endif; ?>
<?php endforeach; ?>
<p><strong>连接名称</strong> <span class="muted">(用于在后台和审计日志中区分)</span></p>
<input type="text" name="conn_name" value="<?php echo esc_attr( $client['name'] ); ?>" maxlength="100">
<div class="btns"><button type="submit" name="decision" value="deny" class="deny">拒绝</button><button type="submit" name="decision" value="allow" class="allow">允许</button></div>
</form></div></body></html>
		<?php
		exit;
	}

	// ---------------------------------------------------------------------
	// 令牌端点
	// ---------------------------------------------------------------------

	/**
	 * /mcp-oauth/token。
	 */
	private function token() {
		$this->require_post();
		if ( ! Rate_Limit::hit_ip( 'oauth_token', 60, MINUTE_IN_SECONDS ) ) {
			$this->oauth_error( 'slow_down', '请求过于频繁,请稍后再试。', 429 );
		}
		$in     = $this->input();
		$client = $this->authenticate_client( $in );
		$grant  = isset( $in['grant_type'] ) ? (string) $in['grant_type'] : '';

		if ( 'authorization_code' === $grant ) {
			$code = isset( $in['code'] ) ? (string) $in['code'] : '';
			$key  = self::CODE_PREFIX . Connections::hash( $code );
			$data = '' !== $code ? get_transient( $key ) : false;
			if ( $data ) {
				delete_transient( $key ); // 一次性。
			}
			if ( ! $data || $data['client_id'] !== $client['client_id'] ) {
				$this->oauth_error( 'invalid_grant', '授权码无效、已使用或已过期。' );
			}
			$redirect = isset( $in['redirect_uri'] ) ? (string) $in['redirect_uri'] : '';
			if ( '' !== $redirect && $redirect !== $data['redirect_uri'] ) {
				$this->oauth_error( 'invalid_grant', 'redirect_uri 与授权时不一致。' );
			}
			$verifier = isset( $in['code_verifier'] ) ? (string) $in['code_verifier'] : '';
			if ( ! preg_match( '/^[A-Za-z0-9\-._~]{43,128}$/', $verifier ) || ! hash_equals( $data['code_challenge'], self::s256( $verifier ) ) ) {
				$this->oauth_error( 'invalid_grant', 'PKCE 校验失败。' );
			}
			if ( isset( $in['resource'] ) && '' !== $in['resource'] && ! self::resource_ok( (string) $in['resource'] ) ) {
				$this->oauth_error( 'invalid_target', 'resource 参数不是本站的 MCP 端点。' );
			}

			$tokens = Connections::create_oauth( $data['name'], $data['tier'], (int) $data['user_id'], $client['client_id'] );
			if ( is_wp_error( $tokens ) ) {
				$this->oauth_error( 'invalid_grant', $tokens->get_error_message() );
			}
			$this->token_response( $tokens );
		}

		if ( 'refresh_token' === $grant ) {
			$refresh = isset( $in['refresh_token'] ) ? (string) $in['refresh_token'] : '';
			$tokens  = Connections::refresh( $refresh, $client['client_id'] );
			if ( is_wp_error( $tokens ) ) {
				$this->oauth_error( 'invalid_grant', $tokens->get_error_message() );
			}
			$this->token_response( $tokens );
		}

		$this->oauth_error( 'unsupported_grant_type', '只支持 authorization_code 与 refresh_token。' );
	}

	/**
	 * /mcp-oauth/revoke(RFC 7009):始终返回 200。
	 */
	private function revoke() {
		$this->require_post();
		$in     = $this->input();
		$client = $this->authenticate_client( $in );
		if ( ! empty( $in['token'] ) ) {
			Connections::revoke_by_token( (string) $in['token'], $client['client_id'] );
		}
		$this->json( new \stdClass() );
	}

	/**
	 * 校验令牌端点的客户端身份。失败时直接输出错误。
	 *
	 * @param array $in 请求参数。
	 * @return array 客户端。
	 */
	private function authenticate_client( $in ) {
		$id     = isset( $in['client_id'] ) ? (string) $in['client_id'] : '';
		$secret = isset( $in['client_secret'] ) ? (string) $in['client_secret'] : '';
		$basic  = false;

		$header = Auth::get_auth_header();
		if ( $header && 0 === stripos( $header, 'basic ' ) ) {
			$decoded = base64_decode( trim( substr( $header, 6 ) ), true );
			if ( false !== $decoded && false !== strpos( $decoded, ':' ) ) {
				list( $bid, $bsecret ) = explode( ':', $decoded, 2 );
				$id     = rawurldecode( $bid );
				$secret = rawurldecode( $bsecret );
				$basic  = true;
			}
		}

		$client = '' !== $id ? self::get_client( $id ) : null;
		if ( ! $client ) {
			$this->oauth_error( 'invalid_client', '未知的客户端。', 401, $basic );
		}
		if ( 'none' !== $client['auth_method'] ) {
			if ( '' === $secret || empty( $client['secret_hash'] ) || ! hash_equals( $client['secret_hash'], Connections::hash( $secret ) ) ) {
				$this->oauth_error( 'invalid_client', '客户端认证失败。', 401, $basic );
			}
		}
		return $client;
	}

	/**
	 * 输出令牌响应。
	 *
	 * @param array $tokens 令牌。
	 */
	private function token_response( $tokens ) {
		header( 'Cache-Control: no-store' );
		header( 'Pragma: no-cache' );
		$this->json( $tokens );
	}

	// ---------------------------------------------------------------------
	// 客户端注册与存储
	// ---------------------------------------------------------------------

	/**
	 * 客户端表名。
	 *
	 * @return string
	 */
	public static function clients_table() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_oauth_clients';
	}

	/**
	 * 建表。
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::clients_table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				client_id varchar(191) NOT NULL,
				secret_hash char(64) NOT NULL DEFAULT '',
				auth_method varchar(40) NOT NULL DEFAULT 'none',
				name varchar(191) NOT NULL DEFAULT '',
				redirect_uris text NOT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY client_id (client_id)
			) {$charset};"
		);
	}

	/**
	 * /mcp-oauth/register(RFC 7591 动态注册)。
	 */
	private function register_client() {
		$this->require_post();
		if ( ! get_option( self::OPT_DCR, 1 ) ) {
			$this->oauth_error( 'access_denied', '本站未开放客户端自动注册。', 403 );
		}
		if ( ! Rate_Limit::hit_ip( 'oauth_register', 20, HOUR_IN_SECONDS ) ) {
			$this->oauth_error( 'slow_down', '注册过于频繁,请稍后再试。', 429 );
		}

		$in   = $this->input();
		$uris = isset( $in['redirect_uris'] ) && is_array( $in['redirect_uris'] ) ? array_values( array_filter( array_map( 'strval', $in['redirect_uris'] ) ) ) : array();
		if ( empty( $uris ) || count( $uris ) > 10 ) {
			$this->oauth_error( 'invalid_redirect_uri', '需要 1–10 个 redirect_uris。' );
		}
		foreach ( $uris as $uri ) {
			if ( ! self::redirect_uri_valid( $uri ) ) {
				$this->oauth_error( 'invalid_redirect_uri', '不允许的回跳地址:' . $uri . '(需 https、本机 http 或应用自定义协议)。' );
			}
		}

		$method = isset( $in['token_endpoint_auth_method'] ) ? (string) $in['token_endpoint_auth_method'] : 'client_secret_basic';
		if ( ! in_array( $method, array( 'none', 'client_secret_post', 'client_secret_basic' ), true ) ) {
			$this->oauth_error( 'invalid_client_metadata', '不支持的 token_endpoint_auth_method:' . $method );
		}
		$grants = isset( $in['grant_types'] ) && is_array( $in['grant_types'] ) ? $in['grant_types'] : array( 'authorization_code' );
		if ( array_diff( $grants, array( 'authorization_code', 'refresh_token' ) ) ) {
			$this->oauth_error( 'invalid_client_metadata', '只支持 authorization_code 与 refresh_token。' );
		}
		$name = isset( $in['client_name'] ) ? mb_substr( trim( wp_strip_all_tags( (string) $in['client_name'] ) ), 0, 100 ) : '';
		if ( '' === $name ) {
			$name = (string) wp_parse_url( $uris[0], PHP_URL_HOST );
			$name = '' !== $name ? $name : 'AI 应用';
		}

		global $wpdb;
		$this->prune_clients();
		$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . self::clients_table() ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( $count >= self::MAX_CLIENTS ) {
			$this->oauth_error( 'access_denied', '已注册的客户端过多,请联系站点管理员清理。', 503 );
		}

		list( $client_id, $secret ) = self::create_client( $name, $uris, $method );

		$out = array(
			'client_id'                  => $client_id,
			'client_id_issued_at'        => time(),
			'client_name'                => $name,
			'redirect_uris'              => $uris,
			'token_endpoint_auth_method' => $method,
			'grant_types'                => array( 'authorization_code', 'refresh_token' ),
			'response_types'             => array( 'code' ),
			'scope'                      => implode( ' ', self::scopes() ),
		);
		if ( '' !== $secret ) {
			$out['client_secret']            = $secret;
			$out['client_secret_expires_at'] = 0;
		}
		header( 'Cache-Control: no-store' );
		$this->json( $out, 201 );
	}

	/**
	 * 新建客户端记录。
	 *
	 * @param string   $name   名称。
	 * @param string[] $uris   回跳地址(调用方已校验)。
	 * @param string   $method none / client_secret_post / client_secret_basic。
	 * @param bool     $manual 后台手动创建(不会被自动清理)。
	 * @return array{0:string,1:string} client_id 与明文 secret(公开客户端为空)。
	 */
	public static function create_client( $name, $uris, $method, $manual = false ) {
		global $wpdb;
		$client_id = 'mcpc_' . wp_generate_password( 32, false, false );
		$secret    = 'none' === $method ? '' : 'mcps_' . wp_generate_password( 48, false, false );
		$wpdb->insert(
			self::clients_table(),
			array(
				'client_id'     => $client_id,
				'secret_hash'   => '' === $secret ? '' : Connections::hash( $secret ),
				'auth_method'   => $method,
				'name'          => $name,
				'redirect_uris' => wp_json_encode( array_values( $uris ) ),
				'created_at'    => current_time( 'mysql', true ),
				// 手动创建的应用标记为「已使用」,避免被 24 小时清理规则删除。
				'last_used_at'  => $manual ? current_time( 'mysql', true ) : null,
			)
		);
		return array( $client_id, $secret );
	}

	/**
	 * 取客户端:已注册的,或「客户端元数据文档」(client_id 为 https 网址)。
	 *
	 * @param string $client_id 客户端 ID。
	 * @return array|null
	 */
	public static function get_client( $client_id ) {
		$client_id = (string) $client_id;
		if ( '' === $client_id || strlen( $client_id ) > 255 ) {
			return null;
		}

		if ( 0 === strpos( $client_id, 'https://' ) ) {
			return self::get_metadata_document_client( $client_id );
		}

		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::clients_table() . ' WHERE client_id = %s', $client_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! $row ) {
			return null;
		}
		$uris = json_decode( $row->redirect_uris, true );
		return array(
			'client_id'     => $row->client_id,
			'name'          => $row->name,
			'auth_method'   => $row->auth_method,
			'secret_hash'   => $row->secret_hash,
			'redirect_uris' => is_array( $uris ) ? $uris : array(),
		);
	}

	/**
	 * 读取客户端元数据文档(client_id 本身是一个 https 网址,内容为客户端的 JSON 描述)。
	 *
	 * @param string $url client_id。
	 * @return array|null
	 */
	private static function get_metadata_document_client( $url ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) || empty( $parts['path'] ) || '/' === $parts['path'] || ! empty( $parts['fragment'] ) ) {
			return null;
		}
		$cache_key = self::CIMD_PREFIX . md5( $url );
		$cached    = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// wp_safe_remote_get 拒绝内网地址,防止被用来探测内网(SSRF)。
		$resp = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				'limit_response_size' => 65536,
				'headers'             => array( 'Accept' => 'application/json' ),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$doc = json_decode( wp_remote_retrieve_body( $resp ), true );
		if ( ! is_array( $doc ) || ! isset( $doc['client_id'] ) || $doc['client_id'] !== $url ) {
			return null;
		}
		$uris = isset( $doc['redirect_uris'] ) && is_array( $doc['redirect_uris'] ) ? array_values( array_filter( $doc['redirect_uris'], array( __CLASS__, 'redirect_uri_valid' ) ) ) : array();
		if ( empty( $uris ) ) {
			return null;
		}
		$method = isset( $doc['token_endpoint_auth_method'] ) ? (string) $doc['token_endpoint_auth_method'] : 'none';
		if ( 'none' !== $method ) {
			return null; // 元数据文档客户端只支持公开客户端。
		}
		$name   = isset( $doc['client_name'] ) ? mb_substr( wp_strip_all_tags( (string) $doc['client_name'] ), 0, 100 ) : $parts['host'];
		$client = array(
			'client_id'     => $url,
			'name'          => '' !== $name ? $name : $parts['host'],
			'auth_method'   => 'none',
			'secret_hash'   => '',
			'redirect_uris' => $uris,
		);
		set_transient( $cache_key, $client, HOUR_IN_SECONDS );
		return $client;
	}

	/**
	 * 列出已注册客户端。
	 *
	 * @return object[]
	 */
	public static function clients() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT id, client_id, name, auth_method, redirect_uris, created_at, last_used_at FROM ' . self::clients_table() . ' ORDER BY id DESC LIMIT 200' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 删除客户端(同时吊销其全部连接)。
	 *
	 * @param string $client_id 客户端 ID。
	 */
	public static function delete_client( $client_id ) {
		global $wpdb;
		$wpdb->delete( self::clients_table(), array( 'client_id' => (string) $client_id ) );
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT id FROM ' . Connections::table() . ' WHERE client_id = %s AND revoked_at IS NULL', (string) $client_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( $ids as $id ) {
			Connections::revoke( (int) $id );
		}
	}

	/**
	 * 记录客户端最近使用。
	 *
	 * @param string $client_id 客户端 ID。
	 */
	private static function touch_client( $client_id ) {
		global $wpdb;
		$wpdb->update( self::clients_table(), array( 'last_used_at' => current_time( 'mysql', true ) ), array( 'client_id' => (string) $client_id ) );
	}

	/**
	 * 清理注册后 24 小时内从未完成授权的客户端。
	 */
	private function prune_clients() {
		global $wpdb;
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - DAY_IN_SECONDS );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::clients_table() . ' WHERE last_used_at IS NULL AND created_at < %s', $cutoff ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	// ---------------------------------------------------------------------
	// 校验工具
	// ---------------------------------------------------------------------

	/**
	 * 回跳地址是否允许注册:https、本机 http、或应用自定义协议(如 cursor://)。
	 *
	 * @param string $uri 地址。
	 * @return bool
	 */
	public static function redirect_uri_valid( $uri ) {
		$uri = (string) $uri;
		if ( '' === $uri || strlen( $uri ) > 2000 || false !== strpos( $uri, '#' ) || preg_match( '/[\x00-\x20\x7f]/', $uri ) ) {
			return false;
		}
		$scheme = strtolower( (string) wp_parse_url( $uri, PHP_URL_SCHEME ) );
		if ( '' === $scheme || ! preg_match( '/^[a-z][a-z0-9+.\-]*$/', $scheme ) ) {
			return false;
		}
		if ( 'https' === $scheme ) {
			return (bool) wp_parse_url( $uri, PHP_URL_HOST );
		}
		if ( 'http' === $scheme ) {
			return self::is_loopback( (string) wp_parse_url( $uri, PHP_URL_HOST ) );
		}
		return ! in_array( $scheme, array( 'javascript', 'data', 'file', 'vbscript', 'about', 'blob', 'ftp', 'ws', 'wss' ), true );
	}

	/**
	 * 授权请求的回跳地址是否与注册的一致。本机回跳地址允许端口不同(RFC 8252)。
	 *
	 * @param string   $uri        请求的地址。
	 * @param string[] $registered 注册的地址。
	 * @return bool
	 */
	public static function redirect_matches( $uri, $registered ) {
		if ( '' === (string) $uri ) {
			return false;
		}
		foreach ( $registered as $r ) {
			if ( $uri === $r ) {
				return true;
			}
			$a = wp_parse_url( $uri );
			$b = wp_parse_url( $r );
			if ( $a && $b && isset( $a['scheme'], $b['scheme'], $a['host'], $b['host'] ) && 'http' === $a['scheme'] && 'http' === $b['scheme']
				&& self::is_loopback( $a['host'] ) && $a['host'] === $b['host']
				&& ( isset( $a['path'] ) ? $a['path'] : '' ) === ( isset( $b['path'] ) ? $b['path'] : '' )
				&& ( isset( $a['query'] ) ? $a['query'] : '' ) === ( isset( $b['query'] ) ? $b['query'] : '' ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 是否本机地址。
	 *
	 * @param string $host 主机。
	 * @return bool
	 */
	private static function is_loopback( $host ) {
		return in_array( strtolower( trim( $host, '[]' ) ), array( 'localhost', '127.0.0.1', '::1' ), true );
	}

	/**
	 * resource 参数是否指向本站 MCP 端点。
	 *
	 * @param string $resource 资源。
	 * @return bool
	 */
	public static function resource_ok( $resource ) {
		$norm = function ( $u ) {
			return untrailingslashit( strtolower( strtok( (string) $u, '?#' ) ) );
		};
		$r    = $norm( $resource );
		$ok   = array(
			$norm( self::resource() ),
			$norm( rest_url( WP_MCP_REST_NAMESPACE ) ),
			$norm( self::issuer() ),
		);
		return in_array( $r, $ok, true );
	}

	/**
	 * PKCE S256。
	 *
	 * @param string $verifier code_verifier。
	 * @return string
	 */
	public static function s256( $verifier ) {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
	}

	// ---------------------------------------------------------------------
	// 输出工具
	// ---------------------------------------------------------------------

	/**
	 * 相对站点根的请求路径;不是本插件关心的路径返回 null。
	 *
	 * @return string|null
	 */
	private function relative_path() {
		$uri  = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$base = untrailingslashit( (string) wp_parse_url( home_url(), PHP_URL_PATH ) );
		if ( '' !== $base && 0 === strpos( $path, $base ) ) {
			$path = substr( $path, strlen( $base ) );
		}
		if ( 0 === strpos( $path, '/.well-known/' ) || 0 === strpos( $path, '/mcp-oauth/' ) ) {
			return $path;
		}
		return null;
	}

	/**
	 * 读取请求参数(表单或 JSON)。
	 *
	 * @return array
	 */
	private function input() {
		$type = isset( $_SERVER['CONTENT_TYPE'] ) ? strtolower( (string) $_SERVER['CONTENT_TYPE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( false !== strpos( $type, 'application/json' ) ) {
			$data = json_decode( (string) file_get_contents( 'php://input' ), true );
			return is_array( $data ) ? $data : array();
		}
		return wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * 仅允许 POST(OPTIONS 预检直接放行)。
	 */
	private function require_post() {
		$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : 'GET'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		if ( 'POST' !== $method ) {
			$this->oauth_error( 'invalid_request', '请使用 POST。', 405 );
		}
	}

	/**
	 * 跨域头(浏览器内的 MCP 客户端需要),并处理预检。
	 */
	private function cors() {
		header( 'Access-Control-Allow-Origin: *' );
		header( 'Access-Control-Allow-Methods: GET, POST, OPTIONS' );
		header( 'Access-Control-Allow-Headers: Authorization, Content-Type, MCP-Protocol-Version' );
		header( 'Access-Control-Max-Age: 86400' );
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 204 );
			exit;
		}
	}

	/**
	 * 输出 JSON 并结束。
	 *
	 * @param mixed $data   数据。
	 * @param int   $status 状态码。
	 */
	private function json( $data, $status = 200 ) {
		status_header( $status );
		header( 'Content-Type: application/json; charset=utf-8' );
		echo wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * 输出 OAuth 错误 JSON 并结束。
	 *
	 * @param string $error       错误码。
	 * @param string $description 说明。
	 * @param int    $status      状态码。
	 * @param bool   $basic       客户端用了 Basic 认证(按规范回 WWW-Authenticate)。
	 */
	private function oauth_error( $error, $description, $status = 400, $basic = false ) {
		if ( $basic ) {
			header( 'WWW-Authenticate: Basic realm="wp-mcp"' );
		}
		header( 'Cache-Control: no-store' );
		$this->json(
			array(
				'error'             => $error,
				'error_description' => $description,
			),
			$status
		);
	}

	/**
	 * 带参数回跳并结束。
	 *
	 * @param string $uri    回跳地址。
	 * @param array  $params 参数。
	 */
	private function redirect_to( $uri, $params ) {
		$params = array_filter(
			$params,
			function ( $v ) {
				return '' !== (string) $v;
			}
		);
		$sep = false === strpos( $uri, '?' ) ? '?' : '&';
		// 回跳到客户端(可能是外部网址或应用协议),不能用 wp_safe_redirect。
		header( 'Location: ' . $uri . $sep . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 ), true, 302 );
		exit;
	}

	/**
	 * 回跳错误。
	 *
	 * @param array  $p           授权参数。
	 * @param string $error       错误码。
	 * @param string $description 说明。
	 */
	private function redirect_error( $p, $error, $description ) {
		$this->redirect_to(
			$p['redirect_uri'],
			array(
				'error'             => $error,
				'error_description' => $description,
				'state'             => $p['state'],
				'iss'               => self::issuer(),
			)
		);
	}

	/**
	 * 授权页错误(不回跳)。
	 *
	 * @param string $title   标题。
	 * @param string $message 说明。
	 */
	private function error_page( $title, $message ) {
		wp_die( esc_html( $message ), esc_html( $title ), array( 'response' => 400 ) );
	}
}
