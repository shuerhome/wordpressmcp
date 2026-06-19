<?php
/**
 * wc_products 工具:商品只读访问(阶段 1)。
 *
 * @package WPMCP
 */

namespace WPMCP\Tools\WC;

defined( 'ABSPATH' ) || exit;

use WPMCP\Tools\Abstract_Tool;

/**
 * WooCommerce 商品读取工具。
 */
class WC_Products_Tool extends Abstract_Tool {

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return 'wc_products';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return '管理 WooCommerce 商品:list=列出; get=取完整商品; variations=列出变体; categories=列出商品分类; create=创建商品; update=更新商品; delete=删除商品(需确认)。写操作支持 dry_run。';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_actions() {
		return array( 'list', 'get', 'variations', 'categories', 'create', 'update', 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	protected function destructive_actions() {
		return array( 'delete' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_input_schema() {
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				array(
					'action'       => array(
						'type' => 'string',
						'enum' => $this->get_actions(),
					),
					'id'           => array(
						'type'        => 'integer',
						'description' => 'get / variations 时的商品 ID',
					),
					'search'       => array(
						'type'        => 'string',
						'description' => 'list 关键词(名称/SKU)',
					),
					'category'     => array(
						'type'        => 'string',
						'description' => 'list 按分类别名(slug)过滤',
					),
					'status'       => array(
						'type'        => 'string',
						'description' => 'list 状态:publish/draft/any,默认 any',
						'default'     => 'any',
					),
					'stock_status' => array(
						'type'        => 'string',
						'description' => 'list 库存状态:instock/outofstock/onbackorder',
					),
					'per_page'     => array(
						'type'    => 'integer',
						'default' => 20,
					),
					'page'         => array(
						'type'    => 'integer',
						'default' => 1,
					),
					'orderby'      => array(
						'type'    => 'string',
						'default' => 'date',
					),
					'order'        => array(
						'type' => 'string',
						'enum' => array( 'ASC', 'DESC' ),
					),
					'name'          => array(
						'type'        => 'string',
						'description' => 'create/update 商品名称',
					),
					'product_type'  => array(
						'type'        => 'string',
						'description' => 'create 商品类型:simple(默认)/variable',
						'default'     => 'simple',
					),
					'regular_price' => array(
						'type'        => 'string',
						'description' => 'create/update 原价',
					),
					'sale_price'    => array(
						'type'        => 'string',
						'description' => 'create/update 促销价',
					),
					'sku'           => array(
						'type'        => 'string',
						'description' => 'create/update SKU',
					),
					'description'   => array(
						'type'        => 'string',
						'description' => 'create/update 详细描述',
					),
					'short_description' => array(
						'type'        => 'string',
						'description' => 'create/update 简短描述',
					),
					'manage_stock'  => array(
						'type'        => 'boolean',
						'description' => 'create/update 是否管理库存',
					),
					'stock_quantity' => array(
						'type'        => 'integer',
						'description' => 'create/update 库存数量',
					),
					'categories_set' => array(
						'type'        => 'array',
						'items'       => array( 'type' => 'string' ),
						'description' => 'create/update 设置分类(名称或 ID)',
					),
					'confirm_token' => array(
						'type' => 'string',
					),
				),
				$this->common_properties()
			),
			'required'   => array( 'action' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	protected function run( $action, $args ) {
		if ( ! function_exists( 'wc_get_products' ) ) {
			return new \WP_Error( 'wp_mcp_no_wc', 'WooCommerce 未启用' );
		}

		switch ( $action ) {
			case 'list':
				return $this->list_products( $args );
			case 'get':
				return $this->get_product( $args );
			case 'variations':
				return $this->list_variations( $args );
			case 'categories':
				return $this->list_categories();
			case 'create':
				return $this->create_product( $args );
			case 'update':
				return $this->update_product( $args );
			case 'delete':
				return $this->delete_product( $args );
			default:
				return new \WP_Error( 'wp_mcp_unknown_action', '未知 action' );
		}
	}

	/**
	 * 创建商品。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function create_product( $args ) {
		$summary = '创建商品「' . ( isset( $args['name'] ) ? $args['name'] : '' ) . '」';
		$blocked = $this->guard( 'create', $args, $summary );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$type = isset( $args['product_type'] ) ? sanitize_key( $args['product_type'] ) : 'simple';
		$product = ( 'variable' === $type ) ? new \WC_Product_Variable() : new \WC_Product_Simple();

		$this->apply_product_props( $product, $args );

		$id = $product->save();
		if ( ! $id ) {
			return new \WP_Error( 'wp_mcp_create_failed', '商品创建失败' );
		}

		return array( 'created' => true, 'product' => $this->get_product( array( 'id' => $id ) ) );
	}

	/**
	 * 更新商品。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function update_product( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到商品 ID:' . $id );
		}

		$blocked = $this->guard( 'update', $args, '更新商品 #' . $id );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$this->apply_product_props( $product, $args );
		$product->save();

		return array( 'updated' => true, 'product' => $this->get_product( array( 'id' => $id ) ) );
	}

	/**
	 * 删除商品。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function delete_product( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到商品 ID:' . $id );
		}

		$blocked = $this->guard( 'delete', $args, '永久删除商品 #' . $id . '「' . $product->get_name() . '」' );
		if ( null !== $blocked ) {
			return $blocked;
		}

		$result = $product->delete( true );
		return array( 'deleted' => (bool) $result, 'id' => $id );
	}

	/**
	 * 把入参写入商品对象(create/update 共用)。
	 *
	 * @param \WC_Product $product 商品对象。
	 * @param array       $args    入参。
	 */
	private function apply_product_props( $product, $args ) {
		if ( isset( $args['name'] ) ) {
			$product->set_name( sanitize_text_field( $args['name'] ) );
		}
		if ( isset( $args['status'] ) && 'any' !== $args['status'] ) {
			$product->set_status( sanitize_key( $args['status'] ) );
		}
		if ( isset( $args['description'] ) ) {
			$product->set_description( $args['description'] );
		}
		if ( isset( $args['short_description'] ) ) {
			$product->set_short_description( $args['short_description'] );
		}
		if ( isset( $args['sku'] ) ) {
			try {
				$product->set_sku( wc_clean( $args['sku'] ) );
			} catch ( \Exception $e ) {
				// SKU 重复等异常忽略,保留其余属性。
				$product->add_meta_data( '_wp_mcp_sku_error', $e->getMessage(), true );
			}
		}
		if ( isset( $args['regular_price'] ) ) {
			$product->set_regular_price( (string) $args['regular_price'] );
		}
		if ( isset( $args['sale_price'] ) ) {
			$product->set_sale_price( (string) $args['sale_price'] );
		}
		if ( isset( $args['manage_stock'] ) ) {
			$product->set_manage_stock( (bool) $args['manage_stock'] );
		}
		if ( isset( $args['stock_quantity'] ) ) {
			$product->set_stock_quantity( (int) $args['stock_quantity'] );
		}
		if ( isset( $args['categories_set'] ) && is_array( $args['categories_set'] ) ) {
			$product->set_category_ids( $this->resolve_category_ids( $args['categories_set'] ) );
		}
	}

	/**
	 * 把分类名称/ID 解析为 product_cat term id 列表。
	 *
	 * @param array $cats 名称或 ID 列表。
	 * @return int[]
	 */
	private function resolve_category_ids( $cats ) {
		$ids = array();
		foreach ( $cats as $cat ) {
			if ( is_numeric( $cat ) ) {
				$ids[] = (int) $cat;
				continue;
			}
			$term = term_exists( $cat, 'product_cat' );
			if ( ! $term ) {
				$term = wp_insert_term( $cat, 'product_cat' );
			}
			if ( ! is_wp_error( $term ) ) {
				$ids[] = (int) $term['term_id'];
			}
		}
		return $ids;
	}

	/**
	 * 列出商品。
	 *
	 * @param array $args 入参。
	 * @return array
	 */
	private function list_products( $args ) {
		$per_page = min( 100, max( 1, (int) ( isset( $args['per_page'] ) ? $args['per_page'] : 20 ) ) );
		$page     = max( 1, (int) ( isset( $args['page'] ) ? $args['page'] : 1 ) );

		$query = array(
			'status'   => isset( $args['status'] ) ? $args['status'] : 'any',
			'limit'    => $per_page,
			'page'     => $page,
			'paginate' => true,
			'orderby'  => isset( $args['orderby'] ) ? sanitize_key( $args['orderby'] ) : 'date',
			'order'    => ( isset( $args['order'] ) && 'ASC' === strtoupper( $args['order'] ) ) ? 'ASC' : 'DESC',
		);

		if ( ! empty( $args['search'] ) ) {
			$query['s'] = sanitize_text_field( $args['search'] );
		}
		if ( ! empty( $args['category'] ) ) {
			$query['category'] = array( sanitize_title( $args['category'] ) );
		}
		if ( ! empty( $args['stock_status'] ) ) {
			$query['stock_status'] = sanitize_key( $args['stock_status'] );
		}

		$results = wc_get_products( $query );
		$items   = array();
		foreach ( $results->products as $product ) {
			$items[] = $this->summarize_product( $product );
		}

		return array(
			'items'       => $items,
			'page'        => $page,
			'per_page'    => $per_page,
			'total'       => (int) $results->total,
			'total_pages' => (int) $results->max_num_pages,
		);
	}

	/**
	 * 取单个商品完整信息。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function get_product( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product ) {
			return new \WP_Error( 'wp_mcp_not_found', '未找到商品 ID:' . $id );
		}

		$data                  = $this->summarize_product( $product );
		$data['description']   = $product->get_description();
		$data['short_description'] = $product->get_short_description();
		$data['weight']        = $product->get_weight();
		$data['dimensions']    = array(
			'length' => $product->get_length(),
			'width'  => $product->get_width(),
			'height' => $product->get_height(),
		);
		$data['tags']          = wp_get_post_terms( $id, 'product_tag', array( 'fields' => 'names' ) );
		$data['attributes']    = $this->format_attributes( $product );
		$data['image']         = wp_get_attachment_url( $product->get_image_id() );
		$data['gallery']       = array_map( 'wp_get_attachment_url', $product->get_gallery_image_ids() );

		if ( $product->is_type( 'variable' ) ) {
			$data['variation_ids'] = $product->get_children();
		}

		return $data;
	}

	/**
	 * 列出可变商品的变体。
	 *
	 * @param array $args 入参。
	 * @return array|\WP_Error
	 */
	private function list_variations( $args ) {
		$id      = (int) ( isset( $args['id'] ) ? $args['id'] : 0 );
		$product = $id ? wc_get_product( $id ) : null;
		if ( ! $product || ! $product->is_type( 'variable' ) ) {
			return new \WP_Error( 'wp_mcp_not_variable', '商品不存在或不是可变商品:' . $id );
		}

		$out = array();
		foreach ( $product->get_children() as $vid ) {
			$v = wc_get_product( $vid );
			if ( ! $v ) {
				continue;
			}
			$out[] = array(
				'id'             => $v->get_id(),
				'sku'            => $v->get_sku(),
				'attributes'     => $v->get_attributes(),
				'price'          => $v->get_price(),
				'regular_price'  => $v->get_regular_price(),
				'sale_price'     => $v->get_sale_price(),
				'stock_status'   => $v->get_stock_status(),
				'stock_quantity' => $v->get_stock_quantity(),
			);
		}

		return array( 'product_id' => $id, 'variations' => $out );
	}

	/**
	 * 列出商品分类。
	 *
	 * @return array
	 */
	private function list_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'product_cat',
				'hide_empty' => false,
			)
		);
		$out = array();
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $t ) {
				$out[] = array(
					'id'     => $t->term_id,
					'name'   => $t->name,
					'slug'   => $t->slug,
					'parent' => $t->parent,
					'count'  => $t->count,
				);
			}
		}
		return array( 'categories' => $out );
	}

	/**
	 * 商品摘要。
	 *
	 * @param \WC_Product $product 商品。
	 * @return array
	 */
	private function summarize_product( $product ) {
		return array(
			'id'             => $product->get_id(),
			'name'           => $product->get_name(),
			'sku'            => $product->get_sku(),
			'type'           => $product->get_type(),
			'status'         => $product->get_status(),
			'price'          => $product->get_price(),
			'regular_price'  => $product->get_regular_price(),
			'sale_price'     => $product->get_sale_price(),
			'stock_status'   => $product->get_stock_status(),
			'stock_quantity' => $product->get_stock_quantity(),
			'categories'     => wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) ),
			'permalink'      => $product->get_permalink(),
		);
	}

	/**
	 * 格式化商品属性。
	 *
	 * @param \WC_Product $product 商品。
	 * @return array
	 */
	private function format_attributes( $product ) {
		$out = array();
		foreach ( $product->get_attributes() as $key => $attr ) {
			if ( is_object( $attr ) && method_exists( $attr, 'get_name' ) ) {
				$out[] = array(
					'name'    => wc_attribute_label( $attr->get_name() ),
					'options' => $attr->get_options(),
					'variation' => $attr->get_variation(),
				);
			}
		}
		return $out;
	}
}
