=== WP MCP — WordPress/WooCommerce MCP Server ===
Contributors: shuerhome
Tags: mcp, claude, ai, woocommerce, rest-api
Requires at least: 5.9
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 0.5.0
License: GPLv2 or later

把本站变成一个 MCP 服务器,让 Claude 通过站点 URL 精细控制 WordPress / WooCommerce。

== Description ==

本插件在站点内实现一个 MCP(Model Context Protocol)服务器。安装并生成令牌后,
支持 MCP 的 AI 客户端(Claude Code、Claude 桌面版等)可通过站点 URL 直接连接,
管理店铺运营(商品、订单、客户、优惠券、税率、支付、运费、报表)与站点设置。

工具(经能力探测后按站点条件暴露):

* WordPress(8):wp_site / wp_media / wp_taxonomy / wp_users / wp_settings / wp_plugins / wp_system /
  wp_rest(通用 REST 通道:可调用本站任意 REST 接口,覆盖专用工具没有封装的功能)
* WooCommerce(9,装了 WC 才出现):wc_products / wc_orders / wc_reports / wc_inventory /
  wc_customers / wc_coupons / wc_settings / wc_shipping / wc_webhooks

护栏:dry_run 预演、危险与敏感操作二次确认、客户 PII 脱敏、API 密钥隐藏、受保护选项、审计日志、能力探测。

== Installation ==

1. 将 `wp-mcp` 目录打包为 zip,在「插件 → 安装插件 → 上传」中安装并启用。
   注意:只能保留**一份**本插件。从 GitHub 下载的压缩包解压后目录名常是 `wordpressmcp-main`
   或 `wordpressmcp-0.2.x`;若你又上传了一份改名为 `wp-mcp` 的副本,两份同时启用会触发致命错误
   导致整站白屏。务必删掉多余的目录,只留一个(目录名叫什么都行)。
2. 连接(端点 `https://你的站点/wp-json/mcp/v1/rpc`):
   * 支持 OAuth 的客户端(claude.ai、Claude 桌面版、ChatGPT、Gemini、Cursor、VS Code 等):只填端点 URL,按提示在本站登录并选择权限级别。
     需要公网 HTTPS,且固定链接不是「朴素」。
   * 可填请求头的客户端(Claude Code、脚本等):「设置 → WP MCP → 新建令牌」,配置 `Authorization: Bearer <令牌>`。
     Claude Code:`claude mcp add --transport http 站点名 https://你的站点/wp-json/mcp/v1/rpc --header "Authorization: Bearer <令牌>"`

== Changelog ==

= 0.5.0 =
* 新增 OAuth 2.1 登录(按 MCP 授权规范):claude.ai 网页端、Claude 桌面版、ChatGPT、Gemini、Cursor、VS Code 等支持 OAuth 的客户端只需填端点 URL,
  在本站登录并选择权限级别即可连接。支持受保护资源元数据、授权服务器元数据、动态客户端注册、客户端元数据文档、PKCE(S256)、
  刷新令牌轮换(旧刷新令牌被重复使用时自动吊销连接)、吊销端点。OAuth 端点走前台路径,不受 WordPress 应用密码机制干扰。
* 新增多连接与三级权限:只读 / 运营(不含退款)/ 完全管理,在服务端强制执行;连接级别不超过绑定用户自身的权限。
  手动令牌可设名称、绑定用户、有效期;所有连接可单独吊销;只读连接不能解锁客户个人信息。
* 新增限流:每连接每分钟读 120 / 写 30 次、每日经确认的危险操作 50 次(可调);OAuth 注册与令牌端点按 IP 限流。
* 审计日志改存独立数据表:记录连接、用户、工具、动作、目标、结果、说明、IP;后台可筛选、导出 CSV;默认保留 90 天。
* 危险操作确认令牌绑定到发起它的连接,其他连接无法使用。
* 升级:原有单个令牌自动迁移为一条「完全管理」连接,继续可用;旧的审计日志选项被清理。
* 后台设置页重做:连接列表、新建令牌、已注册 OAuth 应用、限流与审计设置。


= 0.4.0 =
**不兼容变更:**已移除网站内容与设计类工具(wp_content、wp_blocks、wp_elementor、wp_design、wp_theme、
wp_comments、wp_menus、wp_widgets),插件专注店铺运营与站点管理。保留 wp_media(商品图片)与 wp_taxonomy(商品分类/标签)。
升级时自动清理旧的设计快照数据。

* 新增 wp_rest 通用 REST 通道:namespaces / routes / describe / call,以绑定用户权限调用本站任意 REST 路由
  (WooCommerce /wc/v3、WordPress /wp/v2、第三方插件)。补齐商品图片/属性/变体、建单改单、税率、支付网关、运费、评价、自定义字段等。
  支持 _fields 精简返回、自动去掉 _links、超出 max_bytes 自动截断;设置页可关闭,并可限定允许的命名空间。
* 安全:wp_rest 的删除、退款、批量操作、用户、插件、主题、站点设置、支付网关、店铺设置、webhook、客户账户等敏感写操作需二次确认;
  不允许用 _method 参数改写请求方法。
* 安全:API 密钥、密码、签名密钥等一律显示为 [hidden](wp_rest、options_get、wc_webhooks);写回时 [hidden] 字段被忽略,不会覆盖真实密钥。
* 安全:wp_rest 返回的客户个人信息默认脱敏(与专用工具一致,reveal_pii 解锁)。
* 安全:options_set 增加受保护名单(站点地址、启用插件、主题、管理员邮箱、默认角色、角色表、密钥盐值、本插件自身设置等),拒绝修改。
* 安全:wp_users 的 create / update / set_role,wp_plugins 的 activate / deactivate,wc_webhooks 的 create / update 改为需二次确认。
* wp_media upload 支持 base64 内容上传(data + filename),可上传本地图片,上限 10MB。
* 修复:店铺有退款记录后 wc_orders list 致命错误(退款被当作订单处理);wc_reports 不再把退款记录计入销售统计;按 ID 取到退款记录时按「未找到订单」处理。
* 多模型兼容:tools/list 返回工具标题与 MCP 标注(readOnlyHint / destructiveHint / openWorldHint);
  initialize 返回与模型厂商无关的使用说明;修正 options_set 的 value 参数缺少类型定义。


= 0.3.0 =
* 新增 wp_elementor 工具(仅在站点启用 Elementor 时暴露):精修 Elementor 页面板块——list_pages 列出 Elementor 页面、get 解析可读元素树、get_raw 取元素完整 settings、find 按文字/类型定位元素、update_element 改某元素设置(文案/图片/链接/显隐)、insert/move/delete_element 增删移板块、update_data 整树替换。所有写操作改动前自动快照(可 rollback 还原)、写后重生成 Elementor CSS;支持 dry_run;删除/整树替换/回滚为危险操作需二次确认。
* wp_blocks 新增 update_block:按序号精修古腾堡顶层区块(attrs 深合并 / inner_html 替换文本),写回正文并留 WordPress 修订。
* 第一版聚焦「精修现有页面」,暂不做从零生成整页布局。

= 0.2.1 =
* 修复:同一插件被装成两个目录(如 GitHub 压缩包的 `wordpressmcp-main` 与改名后的 `wp-mcp`)并同时启用时,因 `wp_mcp()` 函数/类被重复声明而抛出「Cannot redeclare」致命错误、整站(含后台)白屏。现在检测到重复会自动只启用一份,并在后台给出提示。
* 安装说明补充「只保留一份目录」的提醒。

= 0.2.0 =
* 鉴权新增 ?token= 查询参数与 X-MCP-Token 头两种来源,支持 claude.ai 网页端自定义连接器(Bearer 头不变)。
* 设置页给出 Claude Code 命令与网页端连接 URL;README 补全两种连接方式。

= 0.1.1 =
* 阶段 0-4:协议层、只读连通、内容/订单写、设计控制(含快照回滚)、全功能补齐(WP 13 + WC 9 工具)。
* 内置零依赖的 GitHub Releases 私有更新器。

= 0.1.0 =
* 阶段 0:脚手架与协议层。
