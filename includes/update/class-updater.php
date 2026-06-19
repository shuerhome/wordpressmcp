<?php
/**
 * 私有更新:直连 GitHub Releases,让各站像普通插件一样收到更新提示并一键升级。
 *
 * 零运行时依赖:仅用 WordPress 自带的更新钩子 + GitHub 公开 REST API。
 * 发版方式:在仓库建一个 Release(tag 形如 v0.2.0),可附带打包好的插件 zip 资产;
 * 未附资产时回退到 GitHub 自动生成的源码包,并在安装时把目录改名回插件 slug。
 *
 * 私有仓库:通过 `wp_mcp_github_auth_token` 过滤器提供 token(此时建议确保 Release
 * 附带公开可下载的 zip 资产,或自行扩展资产鉴权下载)。
 *
 * @package WPMCP
 */

namespace WPMCP\Update;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub Releases 更新器。
 */
class Updater {

	const CACHE_KEY = 'wp_mcp_update_info';
	const CACHE_TTL = 21600; // 6 小时。
	const NEG_TTL   = 1800;  // 失败时的负缓存:30 分钟。

	/**
	 * 仓库标识 owner/repo。
	 *
	 * @var string
	 */
	private $repo;

	/**
	 * 插件基名(wp-mcp/wp-mcp.php)。
	 *
	 * @var string
	 */
	private $basename;

	/**
	 * 插件 slug(目录名,wp-mcp)。
	 *
	 * @var string
	 */
	private $slug;

	/**
	 * 当前版本。
	 *
	 * @var string
	 */
	private $version;

	/**
	 * 构造。
	 *
	 * @param string $repo        owner/repo。
	 * @param string $plugin_file 插件主文件绝对路径。
	 * @param string $version     当前版本。
	 */
	public function __construct( $repo, $plugin_file, $version ) {
		$this->repo     = trim( (string) $repo );
		$this->basename = plugin_basename( $plugin_file );
		$this->slug     = dirname( $this->basename );
		$this->version  = (string) $version;
	}

	/**
	 * 挂钩子。
	 */
	public function register() {
		add_filter( 'pre_set_site_transient_update_plugins', array( $this, 'inject_update' ) );
		add_filter( 'plugins_api', array( $this, 'plugin_info' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( $this, 'fix_source_dir' ), 10, 4 );
	}

	/**
	 * 向更新事务注入「有新版」信息。
	 *
	 * @param mixed $transient 更新事务对象。
	 * @return mixed
	 */
	public function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}

		$remote = $this->get_remote_info();
		if ( ! $remote || empty( $remote['version'] ) || empty( $remote['download_url'] ) ) {
			return $transient;
		}

		$has_update = version_compare( $remote['version'], $this->version, '>' );

		$item = (object) array(
			'slug'        => $this->slug,
			'plugin'      => $this->basename,
			'new_version' => $has_update ? $remote['version'] : $this->version,
			'url'         => $remote['homepage'],
			'package'     => $has_update ? $remote['download_url'] : '',
		);

		if ( $has_update ) {
			$transient->response[ $this->basename ] = $item;
			unset( $transient->no_update[ $this->basename ] );
		} else {
			// 让「已是最新」状态在后台正确显示。
			$transient->no_update[ $this->basename ] = $item;
			unset( $transient->response[ $this->basename ] );
		}

		return $transient;
	}

	/**
	 * 为「查看详情」弹窗提供信息。
	 *
	 * @param mixed  $result 现有结果。
	 * @param string $action 动作。
	 * @param object $args   参数。
	 * @return mixed
	 */
	public function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action ) {
			return $result;
		}
		if ( empty( $args->slug ) || $args->slug !== $this->slug ) {
			return $result;
		}

		$remote = $this->get_remote_info();
		if ( ! $remote ) {
			return $result;
		}

		return (object) array(
			'name'          => 'WP MCP — WordPress/WooCommerce MCP Server',
			'slug'          => $this->slug,
			'version'       => $remote['version'],
			'author'        => '<a href="https://github.com/' . esc_attr( $this->repo ) . '">shuerhome</a>',
			'homepage'      => $remote['homepage'],
			'download_link' => $remote['download_url'],
			'last_updated'  => $remote['published'],
			'sections'      => array(
				'changelog' => $this->format_changelog( $remote['changelog'] ),
			),
		);
	}

	/**
	 * 修正 GitHub 源码包解压后的目录名(改回插件 slug)。
	 *
	 * @param string $source        解压后的源目录。
	 * @param string $remote_source 临时父目录。
	 * @param object $upgrader      升级器实例。
	 * @param array  $hook_extra    额外信息(含 plugin 基名)。
	 * @return string|\WP_Error
	 */
	public function fix_source_dir( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		// 仅处理本插件的更新。
		if ( empty( $hook_extra['plugin'] ) || $hook_extra['plugin'] !== $this->basename ) {
			return $source;
		}
		if ( ! $wp_filesystem ) {
			return $source;
		}

		$desired = trailingslashit( $remote_source ) . $this->slug;
		if ( untrailingslashit( $source ) === $desired ) {
			return $source;
		}

		if ( $wp_filesystem->move( untrailingslashit( $source ), $desired ) ) {
			return trailingslashit( $desired );
		}

		return $source;
	}

	/**
	 * 取(并缓存)GitHub 最新 Release 信息。
	 *
	 * @return array|false
	 */
	private function get_remote_info() {
		$cached = get_transient( self::CACHE_KEY );
		if ( false !== $cached ) {
			return is_array( $cached ) ? $cached : false; // '' 表示负缓存。
		}

		if ( '' === $this->repo || false === strpos( $this->repo, '/' ) ) {
			return false;
		}

		$url  = 'https://api.github.com/repos/' . $this->repo . '/releases/latest';
		$args = array(
			'timeout' => 15,
			'headers' => array(
				'Accept'     => 'application/vnd.github+json',
				'User-Agent' => 'wp-mcp-updater/' . $this->version,
			),
		);

		$token = (string) apply_filters( 'wp_mcp_github_auth_token', '' );
		if ( '' !== $token ) {
			$args['headers']['Authorization'] = 'Bearer ' . $token;
		}

		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			set_transient( self::CACHE_KEY, '', self::NEG_TTL );
			return false;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['tag_name'] ) ) {
			set_transient( self::CACHE_KEY, '', self::NEG_TTL );
			return false;
		}

		$info = array(
			'version'      => ltrim( (string) $body['tag_name'], 'vV' ),
			'download_url' => $this->pick_download_url( $body ),
			'homepage'     => isset( $body['html_url'] ) ? $body['html_url'] : ( 'https://github.com/' . $this->repo ),
			'changelog'    => isset( $body['body'] ) ? (string) $body['body'] : '',
			'published'    => isset( $body['published_at'] ) ? $body['published_at'] : '',
		);

		set_transient( self::CACHE_KEY, $info, self::CACHE_TTL );
		return $info;
	}

	/**
	 * 选择下载地址:优先 Release 附带的 .zip 资产,否则用源码包 zipball。
	 *
	 * @param array $body GitHub Release 响应。
	 * @return string
	 */
	private function pick_download_url( $body ) {
		if ( ! empty( $body['assets'] ) && is_array( $body['assets'] ) ) {
			foreach ( $body['assets'] as $asset ) {
				if ( isset( $asset['name'], $asset['browser_download_url'] ) && '.zip' === substr( $asset['name'], -4 ) ) {
					return $asset['browser_download_url'];
				}
			}
		}
		return isset( $body['zipball_url'] ) ? $body['zipball_url'] : '';
	}

	/**
	 * 把 Markdown 变更日志转为简单 HTML(用于详情弹窗)。
	 *
	 * @param string $markdown 原文。
	 * @return string
	 */
	private function format_changelog( $markdown ) {
		$text = wp_strip_all_tags( (string) $markdown );
		if ( '' === trim( $text ) ) {
			return '<p>本次发布暂无说明。</p>';
		}
		return wpautop( esc_html( $text ) );
	}

	/**
	 * 清除更新信息缓存(供「检查更新」按钮调用)。
	 */
	public static function flush_cache() {
		delete_transient( self::CACHE_KEY );
	}
}
