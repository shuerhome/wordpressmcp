=== WP MCP — WordPress/WooCommerce MCP Server ===
Contributors: shuerhome
Tags: mcp, claude, ai, woocommerce, rest-api
Requires at least: 5.9
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later

把本站变成一个 MCP 服务器,让 Claude 通过站点 URL 精细控制 WordPress / WooCommerce。

== Description ==

本插件在站点内实现一个 MCP(Model Context Protocol)服务器。安装并生成令牌后,
Claude(Claude Code / Desktop / 网页版)可通过站点 URL 直接连接,精细控制站点的
内容、主题设计、商品与订单等。

阶段 0(当前版本):
* MCP 协议处理器(JSON-RPC 2.0 over 无状态 Streamable HTTP)
* Bearer 令牌鉴权 + 绑定用户
* 能力探测(WordPress / WooCommerce / 主题类型 / 关键插件)
* wp_site 工具(info / capabilities / health / flush_cache)
* 后台设置页(端点、令牌、安全开关、能力概览、审计日志)

== Installation ==

1. 将 `wp-mcp` 目录打包为 zip,在「插件 → 安装插件 → 上传」中安装并启用。
2. 进入「设置 → WP MCP」,点击「生成令牌」,复制明文令牌。
3. 在 Claude Code 中连接:
   `claude mcp add --transport http 站点名 https://你的站点/wp-json/mcp/v1 --header "Authorization: Bearer <令牌>"`

== Changelog ==

= 0.1.0 =
* 阶段 0:脚手架与协议层。
