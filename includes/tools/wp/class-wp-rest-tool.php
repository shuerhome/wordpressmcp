<?php
/**
 * wp_rest 工具:通用 REST 通道(阶段 5)。
 *
 * 在站内直接分发到任意已注册的 REST 路由(/wp/v2、/wc/v3、第三方插件……),
 * 以绑定用户的权限执行。用于覆盖专用工具没有封装的全部后台功能。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WP;

defined( 'ABSPATH' ) || exit;

use WPMCP\Safety\Audit;
use WPMCP\Safety\Guard;
use WPMCP\Safety\Pii;
use WPMCP\Safety\Secrets;
use WPMCP\Tools\Abstract_Tool;

/**
 * 通用 REST 通道工具。
 */
class WP_Rest_Tool extends Abstract_Tool {

	const DEFAULT_MAX_BYTES = 100000;
	const HARD_MAX_BYTES    = 1000000;
	const MAX_ROUTES        = 200;

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wp_rest';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '通用 REST 通道 / Generic REST gateway: 以绑定用户权限调用本站任意 REST 路由(WordPress /wp/v2、WooCommerce /wc/v3、以及第三方插件注册的路由)。专用工具(wc_products、wc_orders 等)没有覆盖的功能都走这里,例如商品图片/属性/变体、创建与编辑订单、税率、支付网关、运费、商品评价、自定义字段(meta)。'
			. '用法: namespaces=列出命名空间; routes=列出路由(可按 namespace / search 过滤); describe=查看某路由各方法的参数定义; call=发起请求(method + path + query + body)。'
			. '调用前不确定参数时先 describe。列表请求建议用 query 传 per_page、page、_fields 控制体积。POST/PUT/PATCH 支持 dry_run。DELETE 以及退款、批量操作、用户、插件、支付网关、站点设置、webhook、客户账户等敏感写操作需二次确认(confirm_token)。'
			. '返回结果中的客户个人信息默认脱敏;API 密钥、密码等一律显示为 [hidden],写回时值为 [hidden] 的字段会被忽略、保留原值。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'namespaces', 'routes', 'describe', 'call' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'        => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'namespace'     => array(
						'type'        => 'string',
						'description' => 'routes 时按命名空间过滤,如 wc/v3、wp/v2。',
					),
					'search'        => array(
						'type'        => 'string',
						'description' => 'routes 时按路由路径子串过滤,如 products、tax。',
					),
					'method'        => array(
						'type'        => 'string',
						'enum'        => array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ),
						'description' => 'call 的 HTTP 方法,默认 GET。',
						'default'     => 'GET',
					),
					'path'          => array(
						'type'        => 'string',
						'description' => 'describe / call 的路由路径,不含 /wp-json 前缀,如 /wc/v3/products/123/variations。describe 也可传 routes 返回的路由模式。',
					),
					'query'         => array(
						'type'                 => 'object',
						'description'          => 'call 的查询参数(键值),如 {"per_page":20,"_fields":"id,name,price"}。',
						'additionalProperties' => true,
					),
					'body'          => array(
						'type'                 => 'object',
						'description'          => 'call 的请求体(JSON 对象),用于 POST/PUT/PATCH。',
						'additionalProperties' => true,
					),
					'max_bytes'     => array(
						'type'        => 'integer',
						'description' => '响应体积上限(字节),默认 100000,最大 1000000。超出时列表会被截断并提示。',
						'default'     => self::DEFAULT_MAX_BYTES,
					),
					'reveal_pii'    => array(
						'type'        => 'boolean',
						'description' => '返回未脱敏的客户个人信息(邮箱/电话/姓名/地址/IP)。默认脱敏,仅在确有必要时使用。',
						'default'     => false,
					),
					'confirm_token' => array(
						'type'        => 'string',
						'description' => 'DELETE 二次确认令牌(首次调用返回)。',
					),
				),
				$this->common_properties()
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * call 可能包含删除、退款等危险操作(具体是否需确认由 sensitive_reason 判定)。
	 * 仅用于工具标注;本工具不经基类 guard()。
	 *
	 * @return string[]
	 */
	protected function destructive_actions() {
		return array( 'call' );
	}

	/**
	 * 入口:与基类一致,但审计里记录「方法 + 路径」而非仅 action。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	public function execute( $args ) {
		$action = isset( $args['action'] ) ? (string) $args['action'] : '';

		if ( '' === $action ) {
			return new \WP_Error( 'wp_mcp_missing_action', '缺少 action 参数' );
		}
		if ( ! in_array( $action, $this->get_actions(), true ) ) {
			return new \WP_Error(
				'wp_mcp_unknown_action',
				sprintf( '未知 action「%s」,可用:%s', $action, implode( ', ', $this->get_actions() ) )
			);
		}

		$result = $this->run( $action, $args );

		$label = $action;
		if ( 'call' === $action ) {
			$label = sprintf( 'call %s %s', $this->method_of( $args ), isset( $args['path'] ) ? (string) $args['path'] : '' );
		}
		Audit::log( $this->get_name(), array( 'action' => $label ), is_wp_error( $result ) ? 'error' : 'success' );

		return $result;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( $action, $args ) {
		switch ( $action ) {
			case 'namespaces':
				$self = $this;
				$list = array_filter(
					rest_get_server()->get_namespaces(),
					function ( $ns ) use ( $self ) {
						return ! $self->is_own_route( '/' . $ns . '/' ) && $self->namespace_allowed( '/' . $ns . '/' );
					}
				);
				return array( 'namespaces' => array_values( $list ) );
			case 'routes':
				return $this->routes( $args );
			case 'describe':
				return $this->describe( $args );
			case 'call':
				return $this->call( $args );
		}
		return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
	}

	/**
	 * 列出路由。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function routes( $args ) {
		$namespace = isset( $args['namespace'] ) ? trim( (string) $args['namespace'], '/' ) : '';
		$search    = isset( $args['search'] ) ? (string) $args['search'] : '';

		$out   = array();
		$total = 0;
		foreach ( rest_get_server()->get_routes( $namespace ) as $route => $handlers ) {
			if ( $this->is_own_route( $route ) || ! $this->namespace_allowed( $route ) ) {
				continue;
			}
			if ( '' !== $search && false === stripos( $route, $search ) ) {
				continue;
			}
			++$total;
			if ( count( $out ) >= self::MAX_ROUTES ) {
				continue;
			}
			$out[] = array(
				'route'   => $route,
				'methods' => $this->methods_of_handlers( $handlers ),
			);
		}

		$result = array(
			'total'  => $total,
			'routes' => $out,
		);
		$allowed = $this->allowed_namespaces();
		if ( ! empty( $allowed ) ) {
			$result['allowed_namespaces'] = $allowed;
		}
		if ( $total > count( $out ) ) {
			$result['note'] = sprintf( '仅返回前 %d 条,请用 namespace 或 search 缩小范围。', self::MAX_ROUTES );
		}
		return $result;
	}

	/**
	 * 查看某路由各方法的参数定义。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function describe( $args ) {
		$path = $this->normalize_path( isset( $args['path'] ) ? $args['path'] : '' );
		if ( '' === $path ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'describe 需要 path。' );
		}

		$routes = rest_get_server()->get_routes();
		$match  = null;

		if ( isset( $routes[ $path ] ) ) {
			$match = $path; // 直接传了路由模式。
		} else {
			foreach ( $routes as $route => $handlers ) {
				if ( preg_match( '@^' . $route . '$@i', $path ) ) {
					$match = $route;
					break;
				}
			}
		}

		if ( null === $match || $this->is_own_route( $match ) || ! $this->namespace_allowed( $match ) ) {
			return new \WP_Error( 'wp_mcp_not_found', '没有匹配的路由:' . $path . '。可先用 routes 查找。' );
		}

		$endpoints = array();
		foreach ( $routes[ $match ] as $handler ) {
			if ( ! is_array( $handler ) || ! isset( $handler['methods'] ) ) {
				continue;
			}
			$params = array();
			if ( ! empty( $handler['args'] ) && is_array( $handler['args'] ) ) {
				foreach ( $handler['args'] as $name => $def ) {
					$params[ $name ] = $this->slim_arg( is_array( $def ) ? $def : array() );
				}
			}
			$endpoints[] = array(
				'methods' => array_keys( array_filter( (array) $handler['methods'] ) ),
				'params'  => empty( $params ) ? new \stdClass() : $params,
			);
		}

		return array(
			'route'     => $match,
			'endpoints' => $endpoints,
		);
	}

	/**
	 * 发起 REST 调用。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function call( $args ) {
		$method = $this->method_of( $args );
		$path   = $this->normalize_path( isset( $args['path'] ) ? $args['path'] : '' );
		$query  = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
		$body   = isset( $args['body'] ) && is_array( $args['body'] ) ? $args['body'] : array();

		if ( '' === $path ) {
			return new \WP_Error( 'wp_mcp_bad_args', 'call 需要 path,如 /wc/v3/products。' );
		}
		if ( $this->is_own_route( $path ) ) {
			return new \WP_Error( 'wp_mcp_forbidden_route', '不能通过 wp_rest 调用 MCP 自身的端点。' );
		}
		if ( ! $this->namespace_allowed( $path ) ) {
			return new \WP_Error(
				'wp_mcp_forbidden_route',
				'该路由不在允许的命名空间内(设置 → WP MCP → REST 通道允许的命名空间)。当前允许:' . implode( ', ', $this->allowed_namespaces() )
			);
		}

		// path 里带了 ?a=b 时并入 query。
		$qpos = strpos( $path, '?' );
		if ( false !== $qpos ) {
			$extra = array();
			wp_parse_str( substr( $path, $qpos + 1 ), $extra );
			$query = array_merge( $extra, $query );
			$path  = substr( $path, 0, $qpos );
		}

		// 不允许通过参数改写 HTTP 方法(绕过确认)。
		unset( $query['_method'] );
		// 限定命名空间时不允许 _embed(内嵌资源可能来自其他命名空间)。
		if ( ! empty( $this->allowed_namespaces() ) ) {
			unset( $query['_embed'] );
		}
		// 模型读到 [hidden] 后原样写回时,忽略这些字段,避免覆盖真实密钥。
		$body = Secrets::strip_placeholders( $body );

		if ( 'GET' !== $method ) {
			$summary = sprintf( '%s %s', $method, $path );
			if ( ! empty( $body ) ) {
				$summary .= ' body=' . wp_json_encode( $body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			}

			if ( $this->is_dry_run( $args ) ) {
				return array(
					'dry_run'  => true,
					'would'    => $summary,
					'executed' => false,
				);
			}

			$sensitive = $this->sensitive_reason( $method, $path );
			if ( null !== $sensitive && get_option( 'wp_mcp_require_confirm', 1 ) ) {
				$summary  = '[' . $sensitive . '] ' . $summary;
				$sig_args = array(
					'method'        => $method,
					'path'          => $path,
					'query'         => $query,
					'body'          => $body,
					'confirm_token' => isset( $args['confirm_token'] ) ? $args['confirm_token'] : '',
				);
				$blocked  = Guard::confirm( $this->get_name(), 'call', $sig_args, $summary );
				if ( null !== $blocked ) {
					return $blocked;
				}
			}
		}

		$request = new \WP_REST_Request( $method, $path );
		if ( ! empty( $query ) ) {
			$request->set_query_params( $query );
		}
		if ( 'GET' !== $method && ! empty( $body ) ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}

		$server   = rest_get_server();
		$response = rest_ensure_response( rest_do_request( $request ) );
		// 与真实 HTTP 请求一致地应用 _fields 等后处理(rest_do_request 不会触发)。
		$response = apply_filters( 'rest_post_dispatch', $response, $server, $request );
		$embed    = isset( $query['_embed'] ) ? rest_parse_embed_param( $query['_embed'] ) : false;
		$data     = $server->response_to_data( $response, $embed );
		$status   = (int) $response->get_status();

		// 去掉 _links(体积大、对模型无用),除非显式要求。
		if ( empty( $query['_links'] ) ) {
			$data = $this->strip_links( $data );
		}

		// 密钥一律隐藏(不提供解锁)。
		$data = Secrets::mask( $data );

		// 与专用工具一致:默认脱敏客户 PII。
		$masked = Pii::should_mask( $args );
		if ( $masked ) {
			$data = $this->mask_pii( $data );
		}

		if ( $status >= 400 ) {
			$message = is_array( $data ) && isset( $data['message'] ) ? (string) $data['message'] : 'REST 请求失败';
			$code    = is_array( $data ) && isset( $data['code'] ) ? (string) $data['code'] : 'rest_error';
			$detail  = '';
			if ( is_array( $data ) && ! empty( $data['data']['params'] ) ) {
				$detail = ' 参数问题:' . wp_json_encode( $data['data']['params'], JSON_UNESCAPED_UNICODE );
			}
			return new \WP_Error(
				'wp_mcp_rest_' . $status,
				sprintf( 'HTTP %d [%s] %s%s(%s %s)', $status, $code, $message, $detail, $method, $path )
			);
		}

		$result = array(
			'status' => $status,
			'method' => $method,
			'path'   => $path,
		);
		if ( $masked ) {
			$result['pii_masked'] = true;
		}

		$headers = $response->get_headers();
		if ( isset( $headers['X-WP-Total'] ) ) {
			$result['total'] = (int) $headers['X-WP-Total'];
		}
		if ( isset( $headers['X-WP-TotalPages'] ) ) {
			$result['total_pages'] = (int) $headers['X-WP-TotalPages'];
		}

		return $this->fit( $result, $data, $args );
	}

	/**
	 * 把响应数据装入结果,超出体积上限时截断并提示。
	 *
	 * @param array $result 结果骨架。
	 * @param mixed $data   响应数据。
	 * @param array $args   入参。
	 * @return array
	 */
	private function fit( $result, $data, $args ) {
		$max = isset( $args['max_bytes'] ) ? (int) $args['max_bytes'] : self::DEFAULT_MAX_BYTES;
		$max = max( 1000, min( self::HARD_MAX_BYTES, $max ) );

		$size = strlen( (string) wp_json_encode( $data ) );
		if ( $size <= $max ) {
			$result['data'] = $data;
			return $result;
		}

		$result['truncated']  = true;
		$result['full_bytes'] = $size;
		$hint                 = '响应超过 max_bytes。请用 query 的 _fields 只取需要的字段、减小 per_page,或调大 max_bytes。';

		// 列表:保留能装下的前若干条。
		if ( is_array( $data ) && ( empty( $data ) || array_keys( $data ) === range( 0, count( $data ) - 1 ) ) ) {
			$kept = array();
			$used = 2;
			foreach ( $data as $item ) {
				$len = strlen( (string) wp_json_encode( $item ) ) + 1;
				if ( $used + $len > $max ) {
					break;
				}
				$kept[] = $item;
				$used  += $len;
			}
			$result['data']           = $kept;
			$result['items_returned'] = count( $kept );
			$result['items_total']    = count( $data );
			$result['note']           = $hint;
			return $result;
		}

		// 对象:只给顶层键名,让调用方用 _fields 精确取。
		$result['data'] = null;
		$result['keys'] = is_array( $data ) ? array_keys( $data ) : array();
		$result['note'] = $hint;
		return $result;
	}

	/**
	 * 判断写操作是否敏感(需二次确认)。返回原因,不敏感时返回 null。
	 *
	 * @param string $method HTTP 方法。
	 * @param string $path   路径。
	 * @return string|null
	 */
	private function sensitive_reason( $method, $path ) {
		if ( 'GET' === $method ) {
			return null;
		}
		if ( 'DELETE' === $method ) {
			return '删除';
		}

		$rules = array(
			'@^/wc/v\d+/orders/\d+/refunds@i' => '退款(涉及资金)',
			'@/batch(/|$)@i'                    => '批量操作(可能包含删除)',
			'@^/batch/v\d+@i'                  => '批量操作(可能包含删除)',
			'@^/wp/v2/users@i'                  => '用户/角色/密码变更',
			'@^/wp/v2/plugins@i'                => '插件安装或启停',
			'@^/wp/v2/themes@i'                 => '主题变更',
			'@^/wp/v2/settings@i'               => '站点设置变更',
			'@^/wc/v\d+/payment_gateways@i'    => '支付网关变更',
			'@^/wc/v\d+/settings@i'            => '店铺设置变更',
			'@^/wc/v\d+/webhooks@i'            => 'Webhook 变更(可能把数据发往外部地址)',
			'@^/wc/v\d+/system_status/tools@i' => '运行系统维护工具',
			'@^/wc/v\d+/customers@i'           => '客户账户变更',
		);

		/**
		 * 过滤 wp_rest 敏感写操作规则:正则 => 原因。
		 *
		 * @param array  $rules  规则。
		 * @param string $method HTTP 方法。
		 * @param string $path   路径。
		 */
		$rules = apply_filters( 'wp_mcp_rest_sensitive_routes', $rules, $method, $path );

		foreach ( $rules as $pattern => $reason ) {
			if ( preg_match( $pattern, $path ) ) {
				return (string) $reason;
			}
		}
		return null;
	}

	/**
	 * 设置页配置的允许命名空间(空数组 = 不限制)。
	 *
	 * @return string[]
	 */
	private function allowed_namespaces() {
		$raw = trim( (string) get_option( 'wp_mcp_rest_namespaces', '' ) );
		if ( '' === $raw ) {
			return array();
		}
		$list = array_map(
			function ( $ns ) {
				return trim( $ns, '/ ' );
			},
			preg_split( '/[\s,]+/', $raw )
		);
		return array_values( array_filter( $list ) );
	}

	/**
	 * 路由/路径是否在允许的命名空间内。
	 *
	 * @param string $route 路由或路径。
	 * @return bool
	 */
	public function namespace_allowed( $route ) {
		$allowed = $this->allowed_namespaces();
		if ( empty( $allowed ) ) {
			return true;
		}
		$route = '/' . ltrim( $route, '/' );
		foreach ( $allowed as $ns ) {
			if ( 0 === stripos( $route, '/' . $ns . '/' ) || 0 === strcasecmp( $route, '/' . $ns ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * 递归去掉响应中的 _links。
	 *
	 * @param mixed $data 响应数据。
	 * @return mixed
	 */
	private function strip_links( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		unset( $data['_links'] );
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = $this->strip_links( $v );
			}
		}
		return $data;
	}

	/**
	 * 按字段名递归脱敏个人信息(订单、客户、用户等任意路由通用)。
	 *
	 * @param mixed $data 响应数据。
	 * @return mixed
	 */
	private function mask_pii( $data ) {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		foreach ( $data as $k => $v ) {
			if ( is_array( $v ) ) {
				$data[ $k ] = $this->mask_pii( $v );
				continue;
			}
			if ( ! is_string( $v ) || '' === $v || ! is_string( $k ) ) {
				continue;
			}
			$key = strtolower( $k );
			if ( false !== strpos( $key, 'email' ) ) {
				$data[ $k ] = Pii::email( $v );
			} elseif ( false !== strpos( $key, 'phone' ) ) {
				$data[ $k ] = Pii::phone( $v );
			} elseif ( in_array( $key, array( 'first_name', 'last_name', 'billing_first_name', 'billing_last_name', 'shipping_first_name', 'shipping_last_name' ), true ) ) {
				$data[ $k ] = Pii::name( $v );
			} elseif ( in_array( $key, array( 'address_1', 'address_2', 'postcode', 'company', 'customer_ip_address', 'ip_address' ), true ) ) {
				$data[ $k ] = '***';
			}
		}
		return $data;
	}

	/**
	 * 取规范化的 HTTP 方法。
	 *
	 * @param array $args 入参。
	 * @return string
	 */
	private function method_of( $args ) {
		$method = isset( $args['method'] ) ? strtoupper( (string) $args['method'] ) : 'GET';
		return in_array( $method, array( 'GET', 'POST', 'PUT', 'PATCH', 'DELETE' ), true ) ? $method : 'GET';
	}

	/**
	 * 规范化路径:去掉站点 URL / wp-json 前缀,保证以 / 开头。
	 *
	 * @param mixed $path 原始路径。
	 * @return string
	 */
	private function normalize_path( $path ) {
		$path = trim( (string) $path );
		if ( '' === $path ) {
			return '';
		}
		$pos = strpos( $path, '/wp-json/' );
		if ( false !== $pos ) {
			$path = substr( $path, $pos + strlen( '/wp-json' ) );
		} elseif ( preg_match( '@^https?://@i', $path ) ) {
			return ''; // 只接受本站 REST 路径,不接受外部 URL。
		}
		return '/' . ltrim( $path, '/' );
	}

	/**
	 * 是否为 MCP 自身的路由(禁止经通道回调,避免递归)。
	 *
	 * @param string $route 路由或路径。
	 * @return bool
	 */
	public function is_own_route( $route ) {
		$own = '/' . trim( WP_MCP_REST_NAMESPACE, '/' );
		return 0 === strpos( $route, $own . '/' ) || $route === $own;
	}

	/**
	 * 汇总一条路由支持的方法。
	 *
	 * @param array $handlers 路由处理器。
	 * @return string[]
	 */
	private function methods_of_handlers( $handlers ) {
		$methods = array();
		foreach ( (array) $handlers as $handler ) {
			if ( is_array( $handler ) && isset( $handler['methods'] ) ) {
				foreach ( (array) $handler['methods'] as $m => $on ) {
					if ( $on ) {
						$methods[ $m ] = true;
					}
				}
			}
		}
		return array_keys( $methods );
	}

	/**
	 * 精简参数定义,只留对调用方有用的字段。
	 *
	 * @param array $def 原始定义。
	 * @return array
	 */
	private function slim_arg( $def ) {
		$out = array();
		foreach ( array( 'type', 'required', 'enum', 'default', 'format' ) as $key ) {
			if ( isset( $def[ $key ] ) && ! ( 'required' === $key && ! $def[ $key ] ) ) {
				$out[ $key ] = $def[ $key ];
			}
		}
		if ( isset( $def['description'] ) ) {
			$out['description'] = wp_html_excerpt( wp_strip_all_tags( (string) $def['description'] ), 160, '…' );
		}
		if ( isset( $def['items']['type'] ) ) {
			$out['items'] = $def['items']['type'];
		}
		if ( isset( $def['properties'] ) && is_array( $def['properties'] ) ) {
			$out['properties'] = array_keys( $def['properties'] );
		}
		return empty( $out ) ? new \stdClass() : $out;
	}
}
