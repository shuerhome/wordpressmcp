<?php
/**
 * 密钥脱敏:不把 API 密钥、密码、签名密钥等发送给模型。
 *
 * @package WPMCP
 */

namespace WPMCP\Safety;

defined( 'ABSPATH' ) || exit;

/**
 * 递归隐藏数据中的密钥类字段;写回时丢弃占位符,避免把真实密钥覆盖成占位符。
 */
class Secrets {

	const PLACEHOLDER = '[hidden]';

	/**
	 * 字段名是否像密钥。
	 *
	 * @param string $key 字段名。
	 * @return bool
	 */
	public static function is_secret_key( $key ) {
		if ( ! is_string( $key ) ) {
			return false;
		}
		return (bool) preg_match(
			'/(secret|password|passwd|private[_-]?key|api[_-]?key|apikey|access[_-]?key|access[_-]?token|refresh[_-]?token|auth[_-]?token|bearer|signature|signing|webhook[_-]?key|consumer[_-]?key|salt|^token$|_token$|token_hash)/i',
			$key
		);
	}

	/**
	 * 递归脱敏。
	 *
	 * 处理两种结构:
	 * - 普通键值:{"secret_key": "sk_live_..."}
	 * - WooCommerce 设置字段:{"secret_key": {"value": "sk_...", "type": "password"}},
	 *   或任何 {"type": "password", "value": "..."} 的字段。
	 *
	 * @param mixed $data       数据。
	 * @param bool  $parent_hit 父级字段名已判定为密钥。
	 * @return mixed
	 */
	public static function mask( $data, $parent_hit = false ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}

		$is_password_field = isset( $data['type'] ) && 'password' === $data['type'];

		foreach ( $data as $k => $v ) {
			$hit = self::is_secret_key( $k );
			if ( is_array( $v ) ) {
				$data[ $k ] = self::mask( $v, $hit );
				continue;
			}
			if ( ! is_scalar( $v ) || '' === (string) $v || is_bool( $v ) ) {
				continue;
			}
			if ( $hit || ( 'value' === $k && ( $parent_hit || $is_password_field ) ) ) {
				$data[ $k ] = self::PLACEHOLDER;
			}
		}
		return $data;
	}

	/**
	 * 写入前递归移除值为占位符的字段(模型读到 [hidden] 后原样写回时,保留真实密钥不被覆盖)。
	 *
	 * @param mixed $data 请求体。
	 * @return mixed
	 */
	public static function strip_placeholders( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $k => $v ) {
			if ( self::PLACEHOLDER === $v ) {
				unset( $data[ $k ] );
			} elseif ( is_array( $v ) ) {
				$data[ $k ] = self::strip_placeholders( $v );
			}
		}
		return $data;
	}
}
