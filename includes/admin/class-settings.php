<?php
/**
 * 后台设置页:连接(令牌 / OAuth)、安全开关、限流、审计日志、更新。
 *
 * @package WPMCP
 */

namespace WPMCP\Admin;

defined( 'ABSPATH' ) || exit;

use WPMCP\Mcp\Connections;
use WPMCP\Mcp\OAuth;
use WPMCP\Capability\Detector;
use WPMCP\Safety\Audit;
use WPMCP\Safety\Rate_Limit;
use WPMCP\Update\Updater;

/**
 * 渲染并处理 MCP 设置页。
 */
class Settings {

	const PAGE_SLUG    = 'wp-mcp';
	const NONCE_ACTION = 'wp_mcp_settings';
	const NEW_TOKEN_TR  = 'wp_mcp_new_token_';
	const NEW_CLIENT_TR = 'wp_mcp_new_client_';

	/**
	 * 注册菜单。
	 */
	public function register_menu() {
		add_options_page(
			'WP MCP',
			'WP MCP',
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render' )
		);
	}

	/**
	 * 处理表单提交(PRG 模式)与审计导出。
	 */
	public function handle_actions() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( isset( $_GET['page'], $_GET['wp_mcp_export'] ) && self::PAGE_SLUG === $_GET['page'] ) {
			check_admin_referer( 'wp_mcp_export' );
			$this->export_audit();
		}

		if ( ! isset( $_POST['wp_mcp_action'] ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION );

		$action        = sanitize_text_field( wp_unslash( $_POST['wp_mcp_action'] ) );
		$redirect_args = array( 'page' => self::PAGE_SLUG, 'updated' => '1' );

		switch ( $action ) {
			case 'create_token':
				$name    = isset( $_POST['token_name'] ) ? sanitize_text_field( wp_unslash( $_POST['token_name'] ) ) : '';
				$tier    = isset( $_POST['token_tier'] ) ? sanitize_key( wp_unslash( $_POST['token_tier'] ) ) : '';
				$user_id = isset( $_POST['token_user'] ) ? absint( $_POST['token_user'] ) : 0;
				$days    = isset( $_POST['token_days'] ) ? absint( $_POST['token_days'] ) : 0;
				$created = Connections::create_static( $name, $tier, $user_id, $days ? $days : null );
				if ( is_wp_error( $created ) ) {
					$redirect_args = array( 'page' => self::PAGE_SLUG, 'error' => rawurlencode( $created->get_error_message() ) );
				} else {
					set_transient( self::NEW_TOKEN_TR . get_current_user_id(), $created['token'], 120 );
				}
				break;

			case 'revoke_connection':
				$id = isset( $_POST['connection_id'] ) ? absint( $_POST['connection_id'] ) : 0;
				if ( $id ) {
					Connections::revoke( $id );
				}
				break;

			case 'create_client':
				$cname = isset( $_POST['client_name'] ) ? sanitize_text_field( wp_unslash( $_POST['client_name'] ) ) : '';
				$curis = isset( $_POST['client_redirects'] ) ? array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', sanitize_textarea_field( wp_unslash( $_POST['client_redirects'] ) ) ) ) ) ) : array();
				$bad   = array_filter(
					$curis,
					function ( $u ) {
						return ! OAuth::redirect_uri_valid( $u );
					}
				);
				if ( '' === $cname || empty( $curis ) || $bad ) {
					$redirect_args = array( 'page' => self::PAGE_SLUG, 'error' => rawurlencode( '请填写应用名称与有效的回调地址(https、本机 http 或应用协议)。' . ( $bad ? '无效:' . implode( ' ', $bad ) : '' ) ) );
					break;
				}
				list( $new_id, $new_secret ) = OAuth::create_client( mb_substr( $cname, 0, 100 ), $curis, 'client_secret_post', true );
				set_transient( self::NEW_CLIENT_TR . get_current_user_id(), array( $new_id, $new_secret ), 120 );
				break;

			case 'delete_client':
				$client = isset( $_POST['client_id'] ) ? sanitize_text_field( wp_unslash( $_POST['client_id'] ) ) : '';
				if ( '' !== $client ) {
					OAuth::delete_client( $client );
				}
				break;

			case 'save_settings':
				$this->save_settings();
				break;

			case 'check_updates':
				Updater::flush_cache();
				delete_site_transient( 'update_plugins' );
				wp_update_plugins();
				$redirect_args = array( 'page' => self::PAGE_SLUG, 'checked' => '1' );
				break;
		}

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'options-general.php' ) ) );
		exit;
	}

	/**
	 * 保存常规设置。
	 */
	private function save_settings() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- 已在 handle_actions 校验。
		foreach ( array( 'wp_mcp_enabled', 'wp_mcp_oauth_enabled', 'wp_mcp_oauth_dcr', 'wp_mcp_require_confirm', 'wp_mcp_mask_pii', 'wp_mcp_audit', 'wp_mcp_enable_rest' ) as $flag ) {
			update_option( $flag, isset( $_POST[ $flag ] ) ? 1 : 0 );
		}
		foreach ( array( Rate_Limit::OPT_READ, Rate_Limit::OPT_WRITE, Rate_Limit::OPT_DANGEROUS, Audit::OPT_DAYS ) as $num ) {
			if ( isset( $_POST[ $num ] ) ) {
				update_option( $num, absint( $_POST[ $num ] ) );
			}
		}
		if ( isset( $_POST['wp_mcp_ip_allowlist'] ) ) {
			update_option( 'wp_mcp_ip_allowlist', sanitize_textarea_field( wp_unslash( $_POST['wp_mcp_ip_allowlist'] ) ) );
		}
		if ( isset( $_POST['wp_mcp_rest_namespaces'] ) ) {
			update_option( 'wp_mcp_rest_namespaces', sanitize_textarea_field( wp_unslash( $_POST['wp_mcp_rest_namespaces'] ) ) );
		}
		// phpcs:enable
	}

	/**
	 * 导出审计日志 CSV。
	 */
	private function export_audit() {
		$rows = Audit::query( array_merge( $this->audit_filters(), array( 'limit' => 5000 ) ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="wp-mcp-audit-' . gmdate( 'Ymd-His' ) . '.csv"' );
		$out = fopen( 'php://output', 'w' );
		fwrite( $out, "\xEF\xBB\xBF" ); // Excel 识别 UTF-8。
		fputcsv( $out, array( 'time_utc', 'connection_id', 'connection', 'user_id', 'tool', 'action', 'target', 'result', 'message', 'ip' ) );
		foreach ( $rows as $r ) {
			fputcsv( $out, array( $r->created_at, $r->connection_id, $r->connection_name, $r->user_id, $r->tool, $r->action, $r->target, $r->result, $r->message, $r->ip ) );
		}
		fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		exit;
	}

	/**
	 * 当前审计筛选条件。
	 *
	 * @return array
	 */
	private function audit_filters() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- 只读筛选。
		return array(
			'connection_id' => isset( $_GET['audit_conn'] ) ? absint( $_GET['audit_conn'] ) : 0,
			'tool'          => isset( $_GET['audit_tool'] ) ? sanitize_key( wp_unslash( $_GET['audit_tool'] ) ) : '',
			'result'        => isset( $_GET['audit_result'] ) ? sanitize_key( wp_unslash( $_GET['audit_result'] ) ) : '',
		);
		// phpcs:enable
	}

	/**
	 * 可绑定连接的用户(具备店铺管理或报表权限)。
	 *
	 * @return \WP_User[]
	 */
	private function eligible_users() {
		$users = get_users( array( 'number' => 200, 'orderby' => 'display_name' ) );
		return array_values(
			array_filter(
				$users,
				function ( $u ) {
					return null !== Connections::max_tier_for_user( $u->ID );
				}
			)
		);
	}

	/**
	 * UTC 时间转站点时区显示。
	 *
	 * @param string|null $utc UTC 时间。
	 * @return string
	 */
	private function when( $utc ) {
		if ( empty( $utc ) ) {
			return '—';
		}
		return get_date_from_gmt( $utc, 'Y-m-d H:i' );
	}

	/**
	 * 渲染设置页。
	 */
	public function render() {
		$endpoint  = rest_url( WP_MCP_REST_NAMESPACE . '/rpc' );
		$tr_key    = self::NEW_TOKEN_TR . get_current_user_id();
		$new_token = get_transient( $tr_key );
		if ( $new_token ) {
			delete_transient( $tr_key );
		}
		$new_client = get_transient( self::NEW_CLIENT_TR . get_current_user_id() );
		if ( $new_client ) {
			delete_transient( self::NEW_CLIENT_TR . get_current_user_id() );
		}
		$snapshot    = Detector::snapshot();
		$tiers       = Connections::tiers();
		$connections = Connections::active();
		$plain_links = '' === (string) get_option( 'permalink_structure' );
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap">
			<h1>WP MCP 设置</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>已保存。</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['error'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php echo esc_html( rawurldecode( sanitize_text_field( wp_unslash( $_GET['error'] ) ) ) ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['checked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>已重新检查更新。如有新版,可在「插件」或「仪表盘 → 更新」页一键升级。</p></div>
			<?php endif; ?>
			<?php if ( $plain_links ) : ?>
				<div class="notice notice-warning"><p><strong>固定链接为「朴素」:</strong>OAuth 登录需要启用固定链接(设置 → 固定链接,选「文章名」等任意非朴素结构),否则 ChatGPT、claude.ai 等客户端无法完成登录。手动令牌不受影响。</p></div>
			<?php endif; ?>

			<?php if ( $new_token ) : ?>
				<div class="notice notice-warning" style="padding:12px;">
					<p><strong>令牌已生成,仅显示这一次,请立即复制保存:</strong></p>
					<p><code style="font-size:14px;background:#fff3cd;padding:6px;display:inline-block;"><?php echo esc_html( $new_token ); ?></code></p>
					<p>Claude Code:</p>
					<code style="display:block;padding:8px;background:#f6f7f7;">claude mcp add --transport http <?php echo esc_html( sanitize_title( get_bloginfo( 'name' ) ) ); ?> <?php echo esc_html( $endpoint ); ?> --header "Authorization: Bearer <?php echo esc_html( $new_token ); ?>"</code>
					<p>其他客户端(Cursor、VS Code、Gemini CLI、Cherry Studio 等)的 JSON 配置:</p>
					<code style="display:block;padding:8px;background:#f6f7f7;white-space:pre;">{ "url": "<?php echo esc_html( $endpoint ); ?>", "headers": { "Authorization": "Bearer <?php echo esc_html( $new_token ); ?>" } }</code>
				</div>
			<?php endif; ?>

			<?php if ( $new_client ) : ?>
				<div class="notice notice-warning" style="padding:12px;">
					<p><strong>OAuth 应用已创建,密钥仅显示这一次:</strong></p>
					<table class="form-table">
						<tr><th>Client ID</th><td><code><?php echo esc_html( $new_client[0] ); ?></code></td></tr>
						<tr><th>Client Secret</th><td><code style="background:#fff3cd;"><?php echo esc_html( $new_client[1] ); ?></code></td></tr>
						<tr><th>Authorization URL</th><td><code><?php echo esc_html( OAuth::endpoint( 'authorize' ) ); ?></code></td></tr>
						<tr><th>Token URL</th><td><code><?php echo esc_html( OAuth::endpoint( 'token' ) ); ?></code></td></tr>
						<tr><th>Scope</th><td><code>mcp:ops</code>(或 mcp:read / mcp:full;授权页上可再选)</td></tr>
						<tr><th>Token 认证方式</th><td>POST 请求体(client_secret_post)或 Basic 头均可</td></tr>
					</table>
				</div>
			<?php endif; ?>

			<h2>如何连接</h2>
			<table class="form-table">
				<tr>
					<th scope="row">MCP 端点 URL</th>
					<td><code><?php echo esc_html( $endpoint ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">A. 用账号登录(OAuth)</th>
					<td>
						<p>适用于 claude.ai 网页端、Claude 桌面版、ChatGPT、Gemini、Cursor、VS Code 等支持 OAuth 的客户端:在客户端中添加 MCP 服务器 / 自定义连接器,<strong>只填上面的端点 URL</strong>,客户端会自动跳转到本站登录并让你选择权限级别。</p>
						<p class="description">需要公网 HTTPS 与非「朴素」固定链接。授权后的连接出现在下方「连接列表」,可随时吊销。<?php echo OAuth::enabled() ? '' : '<strong>当前已关闭 OAuth,请在下方设置中开启。</strong>'; ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row">B. 手动令牌</th>
					<td>
						<p>适用于可填写请求头的客户端(Claude Code、脚本、自建 Agent 等):在下方「新建令牌」生成后,按提示配置 <code>Authorization: Bearer &lt;令牌&gt;</code>。</p>
						<p class="description">不能填请求头的客户端也可把令牌拼在 URL 上(<code>?token=</code>),但令牌易被日志留存,建议配合 IP 白名单。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">C. OpenAPI(不支持 MCP 的平台)</th>
					<td>
						<p>适用于 GPT 自定义操作(Actions)、Dify、Coze、n8n,或用各家模型 API 的函数调用自建 Agent:导入下面的 OpenAPI 文档,鉴权选 Bearer(手动令牌)或 OAuth。权限、限流、确认、审计与 MCP 完全一致。</p>
						<code style="display:block;padding:8px;background:#f6f7f7;"><?php echo esc_html( rest_url( WP_MCP_REST_NAMESPACE . '/openapi.json' ) ); ?></code>
						<p class="description">平台需要手动填写 OAuth 客户端信息时(如 GPT Actions),在下方「新建 OAuth 应用」创建,回调地址填平台给出的 callback URL。</p>
					</td>
				</tr>
			</table>

			<h2>连接列表</h2>
			<table class="widefat striped">
				<thead><tr><th>名称</th><th>方式</th><th>级别</th><th>用户</th><th>创建</th><th>最近使用</th><th>过期</th><th></th></tr></thead>
				<tbody>
				<?php if ( empty( $connections ) ) : ?>
					<tr><td colspan="8"><em>暂无连接。</em></td></tr>
				<?php endif; ?>
				<?php foreach ( $connections as $c ) : ?>
					<?php
					$u       = get_userdata( (int) $c->user_id );
					$expires = 'oauth' === $c->type ? $c->refresh_expires : $c->expires_at;
					$expired = $expires && strtotime( $expires . ' UTC' ) < time();
					?>
					<tr>
						<td><strong><?php echo esc_html( $c->name ); ?></strong> <span class="description">#<?php echo (int) $c->id; ?></span></td>
						<td><?php echo 'oauth' === $c->type ? 'OAuth' : '手动令牌'; ?></td>
						<td><?php echo esc_html( isset( $tiers[ $c->tier ] ) ? $tiers[ $c->tier ]['label'] : $c->tier ); ?></td>
						<td><?php echo $u ? esc_html( $u->user_login ) : '<em>已删除</em>'; ?></td>
						<td><?php echo esc_html( $this->when( $c->created_at ) ); ?></td>
						<td><?php echo esc_html( $this->when( $c->last_used_at ) ); ?><?php echo $c->last_ip ? '<br><span class="description">' . esc_html( $c->last_ip ) . '</span>' : ''; ?></td>
						<td><?php echo $expires ? esc_html( $this->when( $expires ) ) . ( $expired ? ' <strong>(已过期)</strong>' : '' ) : '不过期'; ?></td>
						<td>
							<form method="post" onsubmit="return confirm('吊销后该连接立即失效,确定?');">
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>
								<input type="hidden" name="wp_mcp_action" value="revoke_connection" />
								<input type="hidden" name="connection_id" value="<?php echo (int) $c->id; ?>" />
								<button type="submit" class="button button-link-delete">吊销</button>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">OAuth 连接的过期时间是刷新令牌到期时间,客户端持续使用会自动顺延。连接的有效级别不会超过所绑定用户本身的权限。</p>

			<h3>新建令牌</h3>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="wp_mcp_action" value="create_token" />
				<table class="form-table">
					<tr>
						<th scope="row">名称</th>
						<td><input type="text" name="token_name" class="regular-text" placeholder="例如:Claude Code 运营" maxlength="100" required /></td>
					</tr>
					<tr>
						<th scope="row">权限级别</th>
						<td>
							<?php foreach ( $tiers as $key => $t ) : ?>
								<label style="display:block;margin-bottom:4px;"><input type="radio" name="token_tier" value="<?php echo esc_attr( $key ); ?>" <?php checked( $key, Connections::TIER_OPS ); ?> /> <strong><?php echo esc_html( $t['label'] ); ?></strong>:<?php echo esc_html( $t['desc'] ); ?></label>
							<?php endforeach; ?>
						</td>
					</tr>
					<tr>
						<th scope="row">绑定用户</th>
						<td>
							<select name="token_user">
								<?php foreach ( $this->eligible_users() as $u ) : ?>
									<option value="<?php echo (int) $u->ID; ?>" <?php selected( $u->ID, get_current_user_id() ); ?>><?php echo esc_html( $u->display_name . ' (' . $u->user_login . ')' ); ?></option>
								<?php endforeach; ?>
							</select>
							<p class="description">AI 以该用户身份操作;级别不能超过该用户的权限。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">有效期</th>
						<td>
							<select name="token_days">
								<option value="0">不过期</option>
								<option value="7">7 天</option>
								<option value="30">30 天</option>
								<option value="90" selected>90 天</option>
								<option value="365">1 年</option>
							</select>
						</td>
					</tr>
				</table>
				<?php submit_button( '生成令牌', 'secondary' ); ?>
			</form>

			<h3>新建 OAuth 应用(手动)</h3>
			<p class="description">仅在平台不支持自动注册、要求你填写 Client ID / Secret 时使用(如 GPT Actions)。支持自动注册的客户端无需这一步。</p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="wp_mcp_action" value="create_client" />
				<table class="form-table">
					<tr><th scope="row">应用名称</th><td><input type="text" name="client_name" class="regular-text" placeholder="例如:我的 GPT" maxlength="100" required /></td></tr>
					<tr><th scope="row">回调地址</th><td><textarea name="client_redirects" rows="2" class="large-text" placeholder="平台提供的 OAuth callback URL,多个用空格或换行分隔" required></textarea></td></tr>
				</table>
				<?php submit_button( '创建 OAuth 应用', 'secondary' ); ?>
			</form>

			<?php $clients = OAuth::clients(); ?>
			<?php if ( ! empty( $clients ) ) : ?>
				<h3>已注册的 OAuth 应用</h3>
				<table class="widefat striped">
					<thead><tr><th>应用</th><th>回跳地址</th><th>注册</th><th>最近授权</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $clients as $cl ) : ?>
						<?php $uris = json_decode( $cl->redirect_uris, true ); ?>
						<tr>
							<td><?php echo esc_html( $cl->name ); ?></td>
							<td><code><?php echo esc_html( implode( ' ', is_array( $uris ) ? $uris : array() ) ); ?></code></td>
							<td><?php echo esc_html( $this->when( $cl->created_at ) ); ?></td>
							<td><?php echo esc_html( $this->when( $cl->last_used_at ) ); ?></td>
							<td>
								<form method="post" onsubmit="return confirm('删除应用会同时吊销它的全部连接,确定?');">
									<?php wp_nonce_field( self::NONCE_ACTION ); ?>
									<input type="hidden" name="wp_mcp_action" value="delete_client" />
									<input type="hidden" name="client_id" value="<?php echo esc_attr( $cl->client_id ); ?>" />
									<button type="submit" class="button button-link-delete">删除</button>
								</form>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">注册后 24 小时内未完成授权的应用会被自动清理。</p>
			<?php endif; ?>

			<hr/>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="wp_mcp_action" value="save_settings" />

				<h2>设置</h2>
				<table class="form-table">
					<tr>
						<th scope="row">启用</th>
						<td>
							<label><input type="checkbox" name="wp_mcp_enabled" value="1" <?php checked( get_option( 'wp_mcp_enabled', 1 ) ); ?> /> 启用 MCP 端点(关闭后立即拒绝所有请求)</label><br/>
							<label><input type="checkbox" name="wp_mcp_oauth_enabled" value="1" <?php checked( get_option( 'wp_mcp_oauth_enabled', 1 ) ); ?> /> 允许用账号登录(OAuth)</label><br/>
							<label><input type="checkbox" name="wp_mcp_oauth_dcr" value="1" <?php checked( get_option( 'wp_mcp_oauth_dcr', 1 ) ); ?> /> 允许 AI 应用自动注册(多数 OAuth 客户端需要;关闭后只接受使用元数据文档的客户端)</label><br/>
							<label><input type="checkbox" name="wp_mcp_enable_rest" value="1" <?php checked( get_option( 'wp_mcp_enable_rest', 1 ) ); ?> /> 启用通用 REST 通道(wp_rest)</label>
						</td>
					</tr>
					<tr>
						<th scope="row">安全</th>
						<td>
							<label><input type="checkbox" name="wp_mcp_require_confirm" value="1" <?php checked( get_option( 'wp_mcp_require_confirm', 1 ) ); ?> /> 危险操作需二次确认</label><br/>
							<label><input type="checkbox" name="wp_mcp_mask_pii" value="1" <?php checked( get_option( 'wp_mcp_mask_pii', 1 ) ); ?> /> 默认脱敏客户个人信息</label><br/>
							<label><input type="checkbox" name="wp_mcp_audit" value="1" <?php checked( get_option( 'wp_mcp_audit', 1 ) ); ?> /> 记录审计日志</label>,保留
							<input type="number" min="0" name="<?php echo esc_attr( Audit::OPT_DAYS ); ?>" value="<?php echo (int) get_option( Audit::OPT_DAYS, Audit::DEFAULT_DAYS ); ?>" style="width:70px;" /> 天(0=永久)
						</td>
					</tr>
					<tr>
						<th scope="row">限流(每个连接)</th>
						<td>
							每分钟读 <input type="number" min="0" name="<?php echo esc_attr( Rate_Limit::OPT_READ ); ?>" value="<?php echo (int) get_option( Rate_Limit::OPT_READ, Rate_Limit::DEFAULT_READ ); ?>" style="width:80px;" /> 次,
							写 <input type="number" min="0" name="<?php echo esc_attr( Rate_Limit::OPT_WRITE ); ?>" value="<?php echo (int) get_option( Rate_Limit::OPT_WRITE, Rate_Limit::DEFAULT_WRITE ); ?>" style="width:80px;" /> 次;
							每天经确认的危险操作(删除、退款等)<input type="number" min="0" name="<?php echo esc_attr( Rate_Limit::OPT_DANGEROUS ); ?>" value="<?php echo (int) get_option( Rate_Limit::OPT_DANGEROUS, Rate_Limit::DEFAULT_DANGEROUS ); ?>" style="width:80px;" /> 次
							<p class="description">0 表示不限制。超出后 AI 会收到「请稍后重试」,不影响网站访客。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">IP 白名单(可选)</th>
						<td>
							<textarea name="wp_mcp_ip_allowlist" rows="2" class="large-text" placeholder="留空=不限制。多个 IP 用空格或逗号分隔"><?php echo esc_textarea( get_option( 'wp_mcp_ip_allowlist', '' ) ); ?></textarea>
							<p class="description">注意:claude.ai、ChatGPT 等云端客户端的出口 IP 不固定,启用白名单后它们可能无法连接。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">REST 通道允许的命名空间(可选)</th>
						<td>
							<textarea name="wp_mcp_rest_namespaces" rows="2" class="large-text" placeholder="留空=不限制。例如只管店铺:wc/v3 wc-analytics wp/v2"><?php echo esc_textarea( get_option( 'wp_mcp_rest_namespaces', '' ) ); ?></textarea>
							<p class="description">限制 wp_rest 能调用的接口范围,多个用空格或逗号分隔。商品图片和分类依赖 wp/v2,只管店铺时也建议保留。</p>
						</td>
					</tr>
				</table>
				<?php submit_button( '保存设置' ); ?>
			</form>

			<hr/>

			<h2>插件更新</h2>
			<table class="form-table">
				<tr>
					<th scope="row">当前版本</th>
					<td><code><?php echo esc_html( WP_MCP_VERSION ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">更新来源</th>
					<td>
						<code><?php echo esc_html( defined( 'WP_MCP_UPDATE_REPO' ) ? WP_MCP_UPDATE_REPO : 'shuerhome/wordpressmcp' ); ?></code>(GitHub Releases)
						<p class="description">在该仓库发布新 Release(tag 形如 <code>v0.2.0</code>)后,各站会自动收到更新提示。</p>
						<form method="post" style="margin-top:8px;">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<input type="hidden" name="wp_mcp_action" value="check_updates" />
							<button type="submit" class="button">立即检查更新</button>
						</form>
					</td>
				</tr>
			</table>

			<h2>能力概览</h2>
			<table class="form-table">
				<tr><th scope="row">WordPress</th><td><?php echo esc_html( $snapshot['wordpress']['version'] . ' / PHP ' . $snapshot['wordpress']['php'] ); ?></td></tr>
				<tr><th scope="row">WooCommerce</th><td><?php echo $snapshot['woocommerce']['active'] ? esc_html( $snapshot['woocommerce']['version'] . ( $snapshot['woocommerce']['hpos'] ? ' / HPOS 已启用' : '' ) ) : '未安装'; ?></td></tr>
			</table>

			<h2 id="audit">审计日志</h2>
			<?php
			$filters = $this->audit_filters();
			$rows    = Audit::query( array_merge( $filters, array( 'limit' => 100 ) ) );
			$results = array(
				''        => '全部结果',
				'success' => '成功',
				'error'   => '出错',
				'denied'  => '被拒绝(越权/限流)',
				'confirm' => '待确认',
				'dry_run' => '预演',
			);
			$export  = wp_nonce_url(
				add_query_arg(
					array(
						'page'          => self::PAGE_SLUG,
						'wp_mcp_export' => 1,
						'audit_conn'    => $filters['connection_id'],
						'audit_tool'    => $filters['tool'],
						'audit_result'  => $filters['result'],
					),
					admin_url( 'options-general.php' )
				),
				'wp_mcp_export'
			);
			?>
			<form method="get" style="margin-bottom:8px;">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
				<select name="audit_conn">
					<option value="0">全部连接</option>
					<?php foreach ( $connections as $c ) : ?>
						<option value="<?php echo (int) $c->id; ?>" <?php selected( $filters['connection_id'], (int) $c->id ); ?>><?php echo esc_html( $c->name ); ?></option>
					<?php endforeach; ?>
				</select>
				<input type="text" name="audit_tool" value="<?php echo esc_attr( $filters['tool'] ); ?>" placeholder="工具名,如 wc_orders" />
				<select name="audit_result">
					<?php foreach ( $results as $k => $label ) : ?>
						<option value="<?php echo esc_attr( $k ); ?>" <?php selected( $filters['result'], $k ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<button type="submit" class="button">筛选</button>
				<a class="button" href="<?php echo esc_url( $export ); ?>">导出 CSV</a>
			</form>
			<table class="widefat striped">
				<thead><tr><th>时间</th><th>连接</th><th>工具</th><th>动作</th><th>目标</th><th>结果</th><th>说明</th><th>IP</th></tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="8"><em>暂无记录</em></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $r ) : ?>
					<tr>
						<td><?php echo esc_html( $this->when( $r->created_at ) ); ?></td>
						<td><?php echo esc_html( $r->connection_name ); ?></td>
						<td><?php echo esc_html( $r->tool ); ?></td>
						<td><?php echo esc_html( $r->action ); ?></td>
						<td><code><?php echo esc_html( $r->target ); ?></code></td>
						<td><?php echo esc_html( isset( $results[ $r->result ] ) ? $results[ $r->result ] : $r->result ); ?></td>
						<td><?php echo esc_html( $r->message ); ?></td>
						<td><?php echo esc_html( $r->ip ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">显示最近 100 条;导出最多 5000 条。</p>
		</div>
		<?php
		// phpcs:enable
	}
}
