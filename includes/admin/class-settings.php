<?php
/**
 * 后台设置页:端点、令牌、绑定用户、安全开关、能力概览、审计日志。
 *
 * @package WPMCP
 */

namespace WPMCP\Admin;

defined( 'ABSPATH' ) || exit;

use WPMCP\Mcp\Auth;
use WPMCP\Capability\Detector;
use WPMCP\Safety\Audit;
use WPMCP\Update\Updater;

/**
 * 渲染并处理 MCP 设置页。
 */
class Settings {

	const PAGE_SLUG    = 'wp-mcp';
	const NONCE_ACTION = 'wp_mcp_settings';
	const NEW_TOKEN_TR = 'wp_mcp_new_token';

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
	 * 处理表单提交(PRG 模式)。
	 */
	public function handle_actions() {
		if ( ! isset( $_POST['wp_mcp_action'] ) ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		check_admin_referer( self::NONCE_ACTION );

		$action        = sanitize_text_field( wp_unslash( $_POST['wp_mcp_action'] ) );
		$redirect_args = array( 'page' => self::PAGE_SLUG, 'updated' => '1' );

		switch ( $action ) {
			case 'regenerate_token':
				$token = Auth::generate_token();
				set_transient( self::NEW_TOKEN_TR, $token, 120 );
				break;

			case 'revoke_token':
				Auth::revoke_token();
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
		update_option( 'wp_mcp_enabled', isset( $_POST['wp_mcp_enabled'] ) ? 1 : 0 );
		update_option( 'wp_mcp_require_confirm', isset( $_POST['wp_mcp_require_confirm'] ) ? 1 : 0 );
		update_option( 'wp_mcp_mask_pii', isset( $_POST['wp_mcp_mask_pii'] ) ? 1 : 0 );
		update_option( 'wp_mcp_audit', isset( $_POST['wp_mcp_audit'] ) ? 1 : 0 );

		if ( isset( $_POST['wp_mcp_bound_user'] ) ) {
			update_option( 'wp_mcp_bound_user', absint( $_POST['wp_mcp_bound_user'] ) );
		}
		if ( isset( $_POST['wp_mcp_ip_allowlist'] ) ) {
			update_option( 'wp_mcp_ip_allowlist', sanitize_textarea_field( wp_unslash( $_POST['wp_mcp_ip_allowlist'] ) ) );
		}
	}

	/**
	 * 渲染设置页。
	 */
	public function render() {
		$endpoint  = rest_url( WP_MCP_REST_NAMESPACE . '/rpc' );
		$new_token = get_transient( self::NEW_TOKEN_TR );
		if ( $new_token ) {
			delete_transient( self::NEW_TOKEN_TR );
		}
		$snapshot = Detector::snapshot();
		?>
		<div class="wrap">
			<h1>WP MCP 设置</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>已保存。</p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['checked'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>已重新检查更新。如有新版,可在「插件」或「仪表盘 → 更新」页一键升级。</p></div>
			<?php endif; ?>

			<h2>连接信息</h2>
			<table class="form-table">
				<tr>
					<th scope="row">MCP 端点 URL</th>
					<td><code><?php echo esc_html( $endpoint ); ?></code></td>
				</tr>
				<tr>
					<th scope="row">访问令牌</th>
					<td>
						<?php if ( $new_token ) : ?>
							<p><strong>令牌已生成(仅显示这一次,请立即复制):</strong></p>
							<code style="font-size:14px;background:#fff3cd;padding:6px;display:inline-block;"><?php echo esc_html( $new_token ); ?></code>
						<?php elseif ( Auth::has_token() ) : ?>
							<p>已设置令牌(出于安全不再显示明文)。</p>
						<?php else : ?>
							<p><em>尚未生成令牌。</em></p>
						<?php endif; ?>

						<form method="post" style="margin-top:8px;">
							<?php wp_nonce_field( self::NONCE_ACTION ); ?>
							<input type="hidden" name="wp_mcp_action" value="regenerate_token" />
							<button type="submit" class="button"><?php echo Auth::has_token() ? '重新生成令牌' : '生成令牌'; ?></button>
						</form>

						<?php if ( Auth::has_token() ) : ?>
							<form method="post" style="margin-top:6px;">
								<?php wp_nonce_field( self::NONCE_ACTION ); ?>
								<input type="hidden" name="wp_mcp_action" value="revoke_token" />
								<button type="submit" class="button button-link-delete">吊销令牌</button>
							</form>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row">A. Claude Code 连接命令</th>
					<td>
						<code style="display:block;padding:8px;background:#f6f7f7;">claude mcp add --transport http <?php echo esc_html( get_bloginfo( 'name' ) ); ?> <?php echo esc_html( $endpoint ); ?> --header "Authorization: Bearer &lt;令牌&gt;"</code>
						<p class="description">用 Bearer 令牌头鉴权,最安全,推荐 CLI / VSCode / JetBrains。</p>
					</td>
				</tr>
				<tr>
					<th scope="row">B. claude.ai 网页端连接 URL</th>
					<td>
						<code style="display:block;padding:8px;background:#f6f7f7;"><?php echo esc_html( $endpoint ); ?>?token=<?php echo $new_token ? esc_html( rawurlencode( $new_token ) ) : '&lt;令牌&gt;'; ?></code>
						<p class="description">
							网页端「设置 → 连接器 → 添加自定义连接器」填这个 URL(令牌内嵌在 URL 里)。
							<strong>安全提示:</strong>令牌在 URL 中易被日志/历史留存,建议同时启用下方 IP 白名单,并可随时重新生成令牌吊销旧的。
							需站点为<strong>公网 HTTPS</strong>;能否成功连接以实际为准(网页端连接器规则可能变化)。
						</p>
					</td>
				</tr>
			</table>

			<hr/>

			<form method="post">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<input type="hidden" name="wp_mcp_action" value="save_settings" />

				<h2>设置</h2>
				<table class="form-table">
					<tr>
						<th scope="row">启用 MCP 端点</th>
						<td><label><input type="checkbox" name="wp_mcp_enabled" value="1" <?php checked( get_option( 'wp_mcp_enabled', 1 ) ); ?> /> 开启(关闭后端点立即拒绝所有请求)</label></td>
					</tr>
					<tr>
						<th scope="row">绑定用户</th>
						<td>
							<select name="wp_mcp_bound_user">
								<?php
								$bound = (int) get_option( 'wp_mcp_bound_user', 0 );
								$admins = get_users( array( 'role__in' => array( 'administrator' ) ) );
								foreach ( $admins as $u ) {
									printf(
										'<option value="%d" %s>%s (#%d)</option>',
										(int) $u->ID,
										selected( $bound, $u->ID, false ),
										esc_html( $u->display_name ),
										(int) $u->ID
									);
								}
								?>
							</select>
							<p class="description">MCP 调用将以该用户身份执行(决定权限范围)。</p>
						</td>
					</tr>
					<tr>
						<th scope="row">安全</th>
						<td>
							<label><input type="checkbox" name="wp_mcp_require_confirm" value="1" <?php checked( get_option( 'wp_mcp_require_confirm', 1 ) ); ?> /> 危险操作需二次确认</label><br/>
							<label><input type="checkbox" name="wp_mcp_mask_pii" value="1" <?php checked( get_option( 'wp_mcp_mask_pii', 1 ) ); ?> /> 默认脱敏客户/订单 PII</label><br/>
							<label><input type="checkbox" name="wp_mcp_audit" value="1" <?php checked( get_option( 'wp_mcp_audit', 1 ) ); ?> /> 记录审计日志</label>
						</td>
					</tr>
					<tr>
						<th scope="row">IP 白名单(可选)</th>
						<td>
							<textarea name="wp_mcp_ip_allowlist" rows="3" class="large-text" placeholder="留空=不限制。多个 IP 用空格或逗号分隔"><?php echo esc_textarea( get_option( 'wp_mcp_ip_allowlist', '' ) ); ?></textarea>
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
				<tr><th scope="row">主题</th><td><?php echo esc_html( $snapshot['theme']['name'] . '(' . ( 'fse' === $snapshot['theme']['type'] ? 'FSE 块主题' : '经典主题' ) . ')' ); ?></td></tr>
				<tr><th scope="row">WooCommerce</th><td><?php echo $snapshot['woocommerce']['active'] ? esc_html( $snapshot['woocommerce']['version'] . ( $snapshot['woocommerce']['hpos'] ? ' / HPOS 已启用' : '' ) ) : '未安装'; ?></td></tr>
			</table>

			<h2>最近审计</h2>
			<table class="widefat striped">
				<thead><tr><th>时间</th><th>工具</th><th>动作</th><th>结果</th></tr></thead>
				<tbody>
				<?php
				$rows = Audit::recent( 20 );
				if ( empty( $rows ) ) {
					echo '<tr><td colspan="4"><em>暂无记录</em></td></tr>';
				} else {
					foreach ( $rows as $r ) {
						printf(
							'<tr><td>%s</td><td>%s</td><td>%s</td><td>%s</td></tr>',
							esc_html( $r['time'] ),
							esc_html( $r['tool'] ),
							esc_html( $r['action'] ),
							esc_html( $r['result'] )
						);
					}
				}
				?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
