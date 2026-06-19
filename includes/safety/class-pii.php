<?php
/**
 * PII 脱敏:对客户/订单的邮箱、电话、姓名、地址做默认脱敏。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * 脱敏工具。
 */
class PII {

	/**
	 * 根据全局开关与单次入参,判断是否需要脱敏。
	 *
	 * @param array $args 工具入参(reveal_pii=true 可解锁)。
	 * @return bool
	 */
	public static function should_mask( $args ) {
		if ( ! get_option( 'wp_mcp_mask_pii', 1 ) ) {
			return false;
		}
		return empty( $args['reveal_pii'] );
	}

	/**
	 * 脱敏邮箱:j***@example.com。
	 *
	 * @param string $email 邮箱。
	 * @return string
	 */
	public static function email( $email ) {
		$email = (string) $email;
		if ( '' === $email || false === strpos( $email, '@' ) ) {
			return $email;
		}
		list( $local, $domain ) = explode( '@', $email, 2 );
		$first = function_exists( 'mb_substr' ) ? mb_substr( $local, 0, 1 ) : substr( $local, 0, 1 );
		return $first . '***@' . $domain;
	}

	/**
	 * 脱敏电话:仅保留后 4 位。
	 *
	 * @param string $phone 电话。
	 * @return string
	 */
	public static function phone( $phone ) {
		$phone = (string) $phone;
		$len   = strlen( $phone );
		if ( $len <= 4 ) {
			return $phone;
		}
		return str_repeat( '*', $len - 4 ) . substr( $phone, -4 );
	}

	/**
	 * 脱敏姓名:保留每段首字。
	 *
	 * @param string $name 姓名。
	 * @return string
	 */
	public static function name( $name ) {
		$name = trim( (string) $name );
		if ( '' === $name ) {
			return $name;
		}
		$parts = preg_split( '/\s+/', $name );
		$out   = array();
		foreach ( $parts as $p ) {
			$first = function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 1 ) : substr( $p, 0, 1 );
			$out[] = $first . '***';
		}
		return implode( ' ', $out );
	}

	/**
	 * 脱敏地址:仅保留城市/省/国家,隐藏街道与邮编。
	 *
	 * @param array $address 地址关联数组(WC 格式)。
	 * @return array
	 */
	public static function address( $address ) {
		if ( ! is_array( $address ) ) {
			return $address;
		}
		$masked = $address;
		foreach ( array( 'address_1', 'address_2', 'postcode', 'phone', 'company' ) as $k ) {
			if ( isset( $masked[ $k ] ) && '' !== $masked[ $k ] ) {
				$masked[ $k ] = '***';
			}
		}
		if ( isset( $masked['email'] ) ) {
			$masked['email'] = self::email( $masked['email'] );
		}
		return $masked;
	}
}
