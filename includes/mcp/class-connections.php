<?php
/**
 * 连接(令牌)存储:每个 AI 客户端一条记录,可分级、过期、单独吊销。
 *
 * 两种来源:
 * - static:在后台手动生成的令牌(Claude Code、Cursor 等可填请求头的客户端)。
 * - oauth :客户端通过 OAuth 授权页获得的访问令牌 + 刷新令牌。
 *
 * 令牌只存 sha256 哈希,明文只在生成时返回一次。
 *
 * @package WPMCP
 */

namespace WPMCP\Mcp;

defined( 'ABSPATH' ) || exit;

/**
 * 连接仓库。
 */
class Connections {

	const TIER_READ = 'read';
	const TIER_OPS  = 'ops';
	const TIER_FULL = 'full';

	const ACCESS_TTL  = 3600;            // OAuth 访问令牌 1 小时。
	const REFRESH_TTL = 30 * DAY_IN_SECONDS; // OAuth 刷新令牌 30 天(每次刷新顺延)。

	/**
	 * 表名。
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'mcp_connections';
	}

	/**
	 * 建表(dbDelta,可重复执行)。
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$table   = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				name varchar(191) NOT NULL DEFAULT '',
				type varchar(20) NOT NULL DEFAULT 'static',
				tier varchar(20) NOT NULL DEFAULT 'read',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				client_id varchar(255) NOT NULL DEFAULT '',
				token_hash char(64) NOT NULL DEFAULT '',
				token_expires datetime DEFAULT NULL,
				refresh_hash char(64) NOT NULL DEFAULT '',
				prev_refresh_hash char(64) NOT NULL DEFAULT '',
				refresh_expires datetime DEFAULT NULL,
				expires_at datetime DEFAULT NULL,
				created_at datetime NOT NULL,
				last_used_at datetime DEFAULT NULL,
				last_ip varchar(45) NOT NULL DEFAULT '',
				revoked_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY token_hash (token_hash),
				KEY refresh_hash (refresh_hash),
				KEY prev_refresh_hash (prev_refresh_hash)
			) {$charset};"
		);
	}

	/**
	 * 所有级别及说明。
	 *
	 * @return array
	 */
	public static function tiers() {
		return array(
			self::TIER_READ => array(
				'label' => '只读',
				'desc'  => '查看商品、订单、库存、客户、报表等,不能修改任何数据',
			),
			self::TIER_OPS  => array(
				'label' => '运营',
				'desc'  => '只读 + 管理商品、库存、订单(不含退款)、优惠券、客户、商品图片与分类',
			),
			self::TIER_FULL => array(
				'label' => '完全管理',
				'desc'  => '全部功能,含退款、插件、用户、站点与店铺设置、支付配置',
			),
		);
	}

	/**
	 * 级别高低(用于比较)。
	 *
	 * @param string $tier 级别。
	 * @return int
	 */
	public static function rank( $tier ) {
		$map = array(
			self::TIER_READ => 1,
			self::TIER_OPS  => 2,
			self::TIER_FULL => 3,
		);
		return isset( $map[ $tier ] ) ? $map[ $tier ] : 0;
	}

	/**
	 * 某用户最多能获得的级别(由其 WordPress 权限决定)。
	 *
	 * @param int $user_id 用户 ID。
	 * @return string|null null 表示该用户无权使用 MCP。
	 */
	public static function max_tier_for_user( $user_id ) {
		if ( ! $user_id || ! get_userdata( $user_id ) ) {
			return null;
		}
		if ( user_can( $user_id, 'manage_options' ) ) {
			return self::TIER_FULL;
		}
		if ( user_can( $user_id, 'manage_woocommerce' ) ) {
			return self::TIER_OPS;
		}
		if ( user_can( $user_id, 'view_woocommerce_reports' ) ) {
			return self::TIER_READ;
		}
		return null;
	}

	/**
	 * 取两个级别中较低者。
	 *
	 * @param string $a 级别。
	 * @param string $b 级别。
	 * @return string
	 */
	public static function min_tier( $a, $b ) {
		return self::rank( $a ) <= self::rank( $b ) ? $a : $b;
	}

	/**
	 * 新建手动令牌。
	 *
	 * @param string   $name         名称。
	 * @param string   $tier         级别。
	 * @param int      $user_id      绑定用户。
	 * @param int|null $expires_days 过期天数,null/0 为不过期。
	 * @return array{id:int,token:string}|\WP_Error
	 */
	public static function create_static( $name, $tier, $user_id, $expires_days = null ) {
		$check = self::validate_tier_for_user( $tier, $user_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$token = 'mcp_' . wp_generate_password( 48, false, false );
		$wpdb->insert(
			self::table(),
			array(
				'name'       => self::clean_name( $name, '手动令牌' ),
				'type'       => 'static',
				'tier'       => $tier,
				'user_id'    => (int) $user_id,
				'token_hash' => self::hash( $token ),
				'expires_at' => $expires_days ? gmdate( 'Y-m-d H:i:s', time() + (int) $expires_days * DAY_IN_SECONDS ) : null,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return array(
			'id'    => (int) $wpdb->insert_id,
			'token' => $token,
		);
	}

	/**
	 * 以已有哈希导入令牌(用于从旧版单令牌迁移)。
	 *
	 * @param string $name    名称。
	 * @param string $hash    sha256 哈希。
	 * @param int    $user_id 绑定用户。
	 * @return int
	 */
	public static function import_static_hash( $name, $hash, $user_id ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'name'       => self::clean_name( $name, '旧令牌' ),
				'type'       => 'static',
				'tier'       => self::TIER_FULL,
				'user_id'    => (int) $user_id,
				'token_hash' => $hash,
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * 新建 OAuth 连接并签发令牌。
	 *
	 * @param string $name      名称(通常为客户端名)。
	 * @param string $tier      级别。
	 * @param int    $user_id   授权用户。
	 * @param string $client_id OAuth 客户端 ID。
	 * @return array|\WP_Error 令牌响应字段。
	 */
	public static function create_oauth( $name, $tier, $user_id, $client_id ) {
		$check = self::validate_tier_for_user( $tier, $user_id );
		if ( is_wp_error( $check ) ) {
			return $check;
		}
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'name'       => self::clean_name( $name, 'OAuth 客户端' ),
				'type'       => 'oauth',
				'tier'       => $tier,
				'user_id'    => (int) $user_id,
				'client_id'  => substr( (string) $client_id, 0, 255 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
		return self::issue_oauth_tokens( (int) $wpdb->insert_id );
	}

	/**
	 * 为 OAuth 连接签发(或轮换)访问令牌与刷新令牌。
	 *
	 * @param int    $id            连接 ID。
	 * @param string $prev_refresh  被替换的刷新令牌哈希(用于检测重放)。
	 * @return array
	 */
	public static function issue_oauth_tokens( $id, $prev_refresh = '' ) {
		global $wpdb;
		$access  = 'mcpa_' . wp_generate_password( 48, false, false );
		$refresh = 'mcpr_' . wp_generate_password( 48, false, false );
		$wpdb->update(
			self::table(),
			array(
				'token_hash'        => self::hash( $access ),
				'token_expires'     => gmdate( 'Y-m-d H:i:s', time() + self::ACCESS_TTL ),
				'refresh_hash'      => self::hash( $refresh ),
				'prev_refresh_hash' => $prev_refresh,
				'refresh_expires'   => gmdate( 'Y-m-d H:i:s', time() + self::REFRESH_TTL ),
			),
			array( 'id' => (int) $id )
		);
		$row = self::get( $id );
		return array(
			'access_token'  => $access,
			'token_type'    => 'Bearer',
			'expires_in'    => self::ACCESS_TTL,
			'refresh_token' => $refresh,
			'scope'         => 'mcp:' . ( $row ? $row->tier : self::TIER_READ ),
		);
	}

	/**
	 * 按访问令牌(手动令牌或 OAuth 访问令牌)查找有效连接。
	 *
	 * @param string $token 明文令牌。
	 * @return object|null
	 */
	public static function find_by_token( $token ) {
		if ( '' === (string) $token ) {
			return null;
		}
		global $wpdb;
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s LIMIT 1', self::hash( $token ) ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( ! $row || ! hash_equals( $row->token_hash, self::hash( $token ) ) ) {
			return null;
		}
		if ( $row->revoked_at ) {
			return null;
		}
		$now = time();
		if ( $row->expires_at && strtotime( $row->expires_at . ' UTC' ) < $now ) {
			return null;
		}
		if ( 'oauth' === $row->type && ( ! $row->token_expires || strtotime( $row->token_expires . ' UTC' ) < $now ) ) {
			return null;
		}
		return $row;
	}

	/**
	 * 用刷新令牌换新令牌(轮换)。
	 *
	 * 若出示的是「已被换掉的旧刷新令牌」,视为令牌被盗用,立即吊销整条连接。
	 *
	 * @param string $refresh   明文刷新令牌。
	 * @param string $client_id 客户端 ID。
	 * @return array|\WP_Error
	 */
	public static function refresh( $refresh, $client_id ) {
		global $wpdb;
		$hash = self::hash( $refresh );
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE type = 'oauth' AND ( refresh_hash = %s OR prev_refresh_hash = %s ) LIMIT 1", $hash, $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( ! $row || $row->revoked_at ) {
			return new \WP_Error( 'invalid_grant', '刷新令牌无效或已吊销。' );
		}
		if ( $row->client_id !== (string) $client_id ) {
			return new \WP_Error( 'invalid_grant', '刷新令牌不属于该客户端。' );
		}
		if ( hash_equals( (string) $row->prev_refresh_hash, $hash ) && ! hash_equals( (string) $row->refresh_hash, $hash ) ) {
			self::revoke( $row->id );
			return new \WP_Error( 'invalid_grant', '刷新令牌已被使用过,出于安全已吊销该连接,请重新授权。' );
		}
		if ( ! $row->refresh_expires || strtotime( $row->refresh_expires . ' UTC' ) < time() ) {
			return new \WP_Error( 'invalid_grant', '刷新令牌已过期,请重新授权。' );
		}
		if ( null === self::max_tier_for_user( (int) $row->user_id ) ) {
			self::revoke( $row->id );
			return new \WP_Error( 'invalid_grant', '授权用户已无权限。' );
		}
		return self::issue_oauth_tokens( (int) $row->id, $hash );
	}

	/**
	 * 按任意令牌(访问或刷新)吊销对应连接。用于 OAuth revoke 端点。
	 *
	 * @param string $token     明文令牌。
	 * @param string $client_id 客户端 ID(仅吊销属于该客户端的连接)。
	 */
	public static function revoke_by_token( $token, $client_id ) {
		global $wpdb;
		$hash = self::hash( $token );
		$row  = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE token_hash = %s OR refresh_hash = %s LIMIT 1', $hash, $hash ) // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
		if ( $row && 'oauth' === $row->type && $row->client_id === (string) $client_id ) {
			self::revoke( $row->id );
		}
	}

	/**
	 * 吊销连接。
	 *
	 * @param int $id 连接 ID。
	 */
	public static function revoke( $id ) {
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'revoked_at'   => current_time( 'mysql', true ),
				'token_hash'   => '',
				'refresh_hash' => '',
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * 记录使用(最多每分钟写一次库)。
	 *
	 * @param object $row 连接。
	 * @param string $ip  来源 IP。
	 */
	public static function touch( $row, $ip ) {
		if ( ! empty( $row->last_used_at ) && strtotime( $row->last_used_at . ' UTC' ) > time() - 60 && $row->last_ip === $ip ) {
			return;
		}
		global $wpdb;
		$wpdb->update(
			self::table(),
			array(
				'last_used_at' => current_time( 'mysql', true ),
				'last_ip'      => substr( (string) $ip, 0, 45 ),
			),
			array( 'id' => (int) $row->id )
		);
	}

	/**
	 * 取一条。
	 *
	 * @param int $id ID。
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', (int) $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 列出未吊销的连接。
	 *
	 * @return object[]
	 */
	public static function active() {
		global $wpdb;
		return (array) $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE revoked_at IS NULL ORDER BY id DESC' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/**
	 * 是否有任何可用连接。
	 *
	 * @return bool
	 */
	public static function has_any() {
		return ! empty( self::active() );
	}

	/**
	 * 校验级别合法且不超过用户权限。
	 *
	 * @param string $tier    级别。
	 * @param int    $user_id 用户。
	 * @return true|\WP_Error
	 */
	private static function validate_tier_for_user( $tier, $user_id ) {
		if ( ! self::rank( $tier ) ) {
			return new \WP_Error( 'wp_mcp_bad_tier', '未知的权限级别。' );
		}
		$max = self::max_tier_for_user( $user_id );
		if ( null === $max ) {
			return new \WP_Error( 'wp_mcp_bad_user', '该用户没有店铺管理权限,不能绑定 MCP 连接。' );
		}
		if ( self::rank( $tier ) > self::rank( $max ) ) {
			return new \WP_Error( 'wp_mcp_bad_tier', '该用户的权限不足以授予这个级别。' );
		}
		return true;
	}

	/**
	 * 清理名称。
	 *
	 * @param string $name     名称。
	 * @param string $fallback 默认值。
	 * @return string
	 */
	private static function clean_name( $name, $fallback ) {
		$name = trim( wp_strip_all_tags( (string) $name ) );
		return '' === $name ? $fallback : mb_substr( $name, 0, 100 );
	}

	/**
	 * 令牌哈希。
	 *
	 * @param string $token 明文。
	 * @return string
	 */
	public static function hash( $token ) {
		return hash( 'sha256', (string) $token );
	}
}
