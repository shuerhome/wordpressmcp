<?php
/**
 * 写操作护栏:dry_run 预演 + 危险操作 confirm_token 二次确认。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * 护栏:为危险操作签发并校验一次性确认令牌。
 */
class Guard {

	const TR_PREFIX = 'wp_mcp_ct_';
	const TTL       = 300; // 确认令牌有效期(秒)。

	/**
	 * 校验/签发确认令牌。
	 *
	 * - 已带有效 confirm_token:消费令牌,返回 null(放行)。
	 * - 未带令牌:签发新令牌并返回 confirmation_required 结构(应直接返回给调用方)。
	 * - 令牌无效/过期:返回 error 结构。
	 *
	 * @param string $tool    工具名。
	 * @param string $action  动作名。
	 * @param array  $args    入参。
	 * @param string $summary 人类可读的操作摘要。
	 * @return array|null
	 */
	public static function confirm( $tool, $action, $args, $summary ) {
		$signature = self::signature( $tool, $action, $args );
		$token     = isset( $args['confirm_token'] ) ? (string) $args['confirm_token'] : '';

		if ( '' !== $token ) {
			$key    = self::TR_PREFIX . $token;
			$stored = get_transient( $key );
			if ( $stored && hash_equals( $stored, $signature ) ) {
				delete_transient( $key ); // 一次性消费。
				return null;
			}
			return array(
				'error' => 'confirm_token 无效或已过期(或与本次操作不匹配),请不带令牌重新发起以获取新令牌。',
			);
		}

		// 签发新令牌。
		$token = wp_generate_password( 24, false, false );
		set_transient( self::TR_PREFIX . $token, $signature, self::TTL );

		return array(
			'confirmation_required' => true,
			'summary'               => $summary,
			'confirm_token'         => $token,
			'expires_in'            => self::TTL,
			'note'                  => '⚠️ 危险操作。请先向用户说明并取得确认,再带上此 confirm_token 重新调用以执行。',
		);
	}

	/**
	 * 计算操作签名(令牌绑定到具体操作,防止串用)。
	 *
	 * @param string $tool   工具名。
	 * @param string $action 动作名。
	 * @param array  $args   入参。
	 * @return string
	 */
	private static function signature( $tool, $action, $args ) {
		$relevant = $args;
		unset( $relevant['confirm_token'], $relevant['dry_run'] );
		ksort( $relevant );
		return md5( $tool . '|' . $action . '|' . wp_json_encode( $relevant ) );
	}
}
