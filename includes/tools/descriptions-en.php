<?php
/**
 * 工具的英文说明(给非中文模型看)。
 *
 * tools/list 中的 description 为「英文 + 中文」;OpenAPI 文档只用英文(部分平台限制长度)。
 * 第三方工具可用过滤器 wp_mcp_tool_descriptions_en 补充。
 *
 * @package WPMCP
 */

defined( 'ABSPATH' ) || exit;

return array(
	'wp_site'      => 'Site overview. Actions: info (site name, URL, versions), capabilities (what this site supports: WooCommerce, HPOS, plugins), health (quick health check), flush_cache. Call capabilities first.',
	'wp_media'     => 'Media library. Actions: list, get, upload (from a URL, or base64 `data` + `filename` for a local image, max 10MB; returns the attachment ID to use as a product image), update (alt text, title, caption), delete (needs confirmation).',
	'wp_taxonomy'  => 'Categories, tags and custom taxonomies, including product_cat and product_tag. Actions: list_taxonomies, list, get, create, update, delete (needs confirmation).',
	'wp_users'     => 'WordPress users. Actions: list, get, create, update, set_role, delete. All writes need confirmation. Emails are masked by default.',
	'wp_settings'  => 'Site settings (allowlisted general/reading/discussion/permalink options). Actions: get, set.',
	'wp_plugins'   => 'Plugins. Actions: list, activate, deactivate, install (slug or zip URL), update, delete. Everything except list needs confirmation.',
	'wp_system'    => 'Maintenance. Actions: site_health, cron_list, cron_run, cache_flush, transients_flush, options_get, options_set (needs confirmation; critical options such as siteurl and active_plugins are protected). Secrets are returned as [hidden].',
	'wp_rest'      => 'Generic gateway to any REST route on this site (WooCommerce /wc/v3, WordPress /wp/v2, other plugins), run as the connected user. Use it for anything the dedicated tools do not cover: product images, attributes and variations, creating and editing orders, tax rates, payment gateways, shipping, reviews, custom fields. Actions: namespaces, routes (filter by namespace/search), describe (parameters of a route), call (method + path + query + body). Pass query _fields/per_page to keep responses small. Non-GET calls accept dry_run; DELETE, refunds, batch and other sensitive writes need confirmation. Customer data is masked by default; secrets are shown as [hidden] and [hidden] values are ignored when written back.',
	'wc_products'  => 'WooCommerce products. Actions: list (search, category, status, stock filters), get, variations, categories, create, update, delete (needs confirmation). For images, attributes or variation edits use wp_rest with /wc/v3/products.',
	'wc_orders'    => 'WooCommerce orders. Actions: list (status, customer, date filters), get, set_status, note, refund (needs confirmation; full administration only), update. Customer data is masked unless reveal_pii=true.',
	'wc_reports'   => 'Sales reports (read-only). Actions: sales (revenue, order count, average order value for a date range; gross, refunds not deducted), top_products. Defaults to the last 7 days.',
	'wc_inventory' => 'Stock levels. Actions: get, set_stock, bulk_set, low_stock.',
	'wc_customers' => 'WooCommerce customers. Actions: list, get, create, update, delete (needs confirmation). Personal data is masked by default.',
	'wc_coupons'   => 'Coupons. Actions: list, get, create, update, delete (needs confirmation).',
	'wc_settings'  => 'Store settings (allowlisted: currency, store address, selling locations, product and checkout options). Actions: get, set.',
	'wc_shipping'  => 'Shipping zones. Actions: list_zones, get_zone, create_zone, add_method (flat_rate, free_shipping, local_pickup), delete_zone (needs confirmation). To change rates of existing methods use wp_rest with /wc/v3/shipping/zones.',
	'wc_webhooks'  => 'WooCommerce webhooks. Actions: list, get, create, update, delete. Writes need confirmation because webhooks send store data to external URLs. Secrets are returned as [hidden].',
);
