=== WP MCP — WordPress/WooCommerce MCP Server ===
Contributors: shuerhome
Tags: mcp, claude, ai, woocommerce, rest-api
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.2.0
License: GPLv2 or later

把本站变成一个 MCP 服务器,让 Claude 通过站点 URL 精细控制 WordPress / WooCommerce。

== Description ==

本插件在站点内实现一个 MCP(Model Context Protocol)服务器。安装并生成令牌后,
Claude(Claude Code / 网页端)可通过站点 URL 直接连接,精细控制站点的内容、主题设计、
商品与订单等。

工具(经能力探测后按站点条件暴露):

* WordPress(13):wp_site / wp_content / wp_media / wp_blocks / wp_design / wp_theme /
  wp_taxonomy / wp_comments / wp_users / wp_settings / wp_plugins / wp_menus / wp_widgets / wp_system
* WooCommerce(9,装了 WC 才出现):wc_products / wc_orders / wc_reports / wc_inventory /
  wc_customers / wc_coupons / wc_settings / wc_shipping / wc_webhooks

护栏:dry_run 预演、危险操作二次确认、PII 脱敏、审计日志、设计快照回滚、能力探测。

== Installation ==

1. 将 `wp-mcp` 目录打包为 zip,在「插件 → 安装插件 → 上传」中安装并启用。
2. 进入「设置 → WP MCP」,点击「生成令牌」,复制明文令牌。
3. 连接(端点 `https://你的站点/wp-json/mcp/v1/rpc`,鉴权支持 Bearer 头 / ?token= / X-MCP-Token):
   * Claude Code:`claude mcp add --transport http 站点名 https://你的站点/wp-json/mcp/v1/rpc --header "Authorization: Bearer <令牌>"`
   * claude.ai 网页端:设置 → 连接器 → 添加自定义连接器,URL 填 `https://你的站点/wp-json/mcp/v1/rpc?token=<令牌>`(需公网 HTTPS)。

== Changelog ==

= 0.2.0 =
* 鉴权新增 ?token= 查询参数与 X-MCP-Token 头两种来源,支持 claude.ai 网页端自定义连接器(Bearer 头不变)。
* 设置页给出 Claude Code 命令与网页端连接 URL;README 补全两种连接方式。

= 0.1.1 =
* 阶段 0-4:协议层、只读连通、内容/订单写、设计控制(含快照回滚)、全功能补齐(WP 13 + WC 9 工具)。
* 内置零依赖的 GitHub Releases 私有更新器。

= 0.1.0 =
* 阶段 0:脚手架与协议层。
