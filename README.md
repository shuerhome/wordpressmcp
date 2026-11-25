# WP MCP — WordPress/WooCommerce MCP 服务器插件

把本站变成一个 **MCP 服务器**,让 Claude 通过站点 URL 直接、精细地控制 WordPress / WooCommerce。

> 完整方案见 [`docs/development-plan.md`](docs/development-plan.md)。

## 当前进度

> **0.4.0 范围调整**:已移除网站内容与设计类工具(文章页面、区块、Elementor、全局样式/模板、主题、评论、菜单、小工具),专注店铺运营与站点管理。保留 `wp_media`(商品图片)与 `wp_taxonomy`(商品分类/标签)。
> 新增通用 REST 通道 `wp_rest`,补齐专用工具未覆盖的 WooCommerce / WordPress / 第三方插件接口。

**阶段 0(脚手架与协议层)**
- ✅ MCP 协议处理器(JSON-RPC 2.0 over 无状态 Streamable HTTP)
- ✅ Bearer 令牌鉴权 + 绑定用户 + HTTPS/IP 护栏
- ✅ 能力探测(WordPress / WooCommerce / 主题类型 / 关键插件)
- ✅ 工具注册表 + 抽象工具基类(统一 action / dry_run / 审计)
- ✅ `wp_site`:`info` / `capabilities` / `health` / `flush_cache`
- ✅ 后台设置页:端点、令牌、绑定用户、安全开关、能力概览、审计日志

**阶段 1(只读连通)**
- ✅ `wc_products`:`list` / `get` / `variations` / `categories`
- ✅ `wc_orders`:`list` / `get`(客户 PII 默认脱敏,`reveal_pii` 可解锁)
- ✅ `wc_reports`:`sales` / `top_products`(按日期区间聚合)
- ✅ WC 工具按能力探测条件注册(无 WooCommerce 则不暴露)
- ✅ PII 脱敏助手(邮箱/电话/姓名/地址)

**阶段 2(内容与订单写操作)**
- ✅ 写护栏 `Guard`:`dry_run` 预演 + 危险操作 `confirm_token` 一次性二次确认
- ✅ `wp_media`:`list` / `get` / `upload`(URL sideload) / `update` / `delete`
- ✅ `wc_products` 写:`create` / `update` / `delete`
- ✅ `wc_orders` 写:`set_status` / `note` / `refund`(资金,需确认) / `update`
- ✅ `wc_inventory`:`get` / `set_stock` / `bulk_set` / `low_stock`

**阶段 4(全功能补齐)**
- ✅ `wp_taxonomy`:`list_taxonomies` / `list` / `get` / `create` / `update` / `delete`(分类/标签/自定义)
- ✅ `wp_users`:`list` / `get` / `create` / `update` / `set_role` / `delete`(邮箱脱敏 + 能力校验)
- ✅ `wp_settings`:`get` / `set`(白名单:常规/阅读/讨论/固定链接;改链接自动刷新重写)
- ✅ `wp_plugins`:`list` / `activate` / `deactivate` / `install` / `update` / `delete`
- ✅ `wp_system`:`site_health` / `cron_list` / `cron_run` / `cache_flush` / `transients_flush` / `options_get` / `options_set`
- ✅ `wc_customers`:`list` / `get` / `create` / `update` / `delete`(PII 脱敏 + 订单数/消费额)
- ✅ `wc_coupons`:`list` / `get` / `create` / `update` / `delete`
- ✅ `wc_settings`:`get` / `set`(货币/店铺地址/销售地区/商品/结账,白名单)
- ✅ `wc_shipping`:`list_zones` / `get_zone` / `create_zone` / `add_method` / `delete_zone`
- ✅ `wc_webhooks`:`list` / `get` / `create` / `update` / `delete`

**阶段 5(通用 REST 通道)**
- ✅ `wp_rest`:`namespaces` / `routes`(按命名空间或关键词查路由) / `describe`(查参数定义) / `call`(调用本站任意 REST 路由)
  - 以绑定用户权限执行;非 GET 支持 `dry_run`;删除与敏感写操作需二次确认(见下文「写操作护栏」);客户 PII 默认脱敏(`reveal_pii` 解锁);API 密钥一律隐藏
  - 支持 `_fields` 精简返回、自动去掉 `_links`、超过 `max_bytes` 自动截断
  - 覆盖:商品图片/属性/变体、建单与改单、税率、支付网关、运费、评价、post meta、第三方插件接口等
  - 设置页可关闭,并可限定允许的命名空间(如只放行 `wc/v3 wc-analytics wp/v2`)
- ✅ `wp_media` 支持 base64 上传本地图片(`data` + `filename`,上限 10MB)
- ✅ 多模型兼容:`tools/list` 带工具标题与 MCP 标注(只读 / 危险 / 访问站外);`initialize` 下发与厂商无关的使用说明

> 工具总数:WordPress 8 个 + WooCommerce 9 个,经能力探测后按站点条件暴露。

## 目录结构

```
wp-mcp/
├── wp-mcp.php                  # 插件主文件
├── includes/
│   ├── class-plugin.php        # 引导:加载依赖、挂钩子
│   ├── mcp/
│   │   ├── class-server.php    # JSON-RPC 协议处理
│   │   ├── class-transport.php # REST 路由(Streamable HTTP)
│   │   ├── class-auth.php      # 令牌校验 / 绑定用户 / 护栏
│   │   └── class-registry.php  # 工具注册表
│   ├── tools/
│   │   ├── abstract-tool.php   # 工具基类
│   │   ├── wp/                 # site/media/taxonomy/users/settings/plugins/system/rest
│   │   └── wc/                 # products/orders/reports/inventory/customers/coupons/settings/shipping/webhooks
│   ├── capability/class-detector.php
│   ├── safety/                 # audit / pii / guard
│   └── admin/class-settings.php
├── composer.json
└── readme.txt
```

## 安装与连接

1. 把 `wp-mcp` 目录打包为 zip,后台「插件 → 上传安装」并启用。
   > ⚠️ **只保留一份本插件。** 从 GitHub 下载的压缩包解压目录名常是 `wordpressmcp-main` /
   > `wordpressmcp-0.2.x`;若再上传一份改名为 `wp-mcp` 的副本,两份同时启用会因重复声明而触发
   > 致命错误、整站白屏。请删掉多余目录,只留一个(目录名任意)。0.2.1 起即便误装两份也会自动
   > 只启用一份并在后台提示,但仍建议清理干净。
2. 「设置 → WP MCP」→「生成令牌」,复制明文。
3. 端点(两个都可用,推荐 `/rpc`,无尾斜杠更稳):
   `https://你的站点/wp-json/mcp/v1/rpc`

鉴权支持三种来源(任一即可):`Authorization: Bearer <令牌>` 头、`?token=<令牌>` 查询参数、`X-MCP-Token: <令牌>` 头。

### A. Claude Code(CLI / VSCode / JetBrains)— 推荐

```bash
claude mcp add --transport http mysite https://你的站点/wp-json/mcp/v1/rpc \
  --header "Authorization: Bearer <令牌>"
```

### B. claude.ai 网页端(自定义连接器)

「设置 → 连接器 → 添加自定义连接器」,URL 填(令牌内嵌在 URL):

```
https://你的站点/wp-json/mcp/v1/rpc?token=<令牌>
```

- 需站点为**公网 HTTPS**(claude.ai 云端要能访问到)。
- 令牌在 URL 中易被日志/历史留存,**建议同时启用 IP 白名单**,并按需重新生成令牌吊销旧的。
- 网页端连接器规则可能变化;能否连通以实际为准。若网页端要求 OAuth,则需后续给插件补 OAuth(规划中)。

## 本地冒烟测试(无需 Claude)

端点是标准 JSON-RPC,可用 curl 直接测:

```bash
# initialize
curl -s -X POST https://你的站点/wp-json/mcp/v1/ \
  -H "Authorization: Bearer <令牌>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18"}}'

# tools/list
curl -s -X POST https://你的站点/wp-json/mcp/v1/ \
  -H "Authorization: Bearer <令牌>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'

# 调用 wp_site:info
curl -s -X POST https://你的站点/wp-json/mcp/v1/ \
  -H "Authorization: Bearer <令牌>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"wp_site","arguments":{"action":"info"}}}'
```

## 写操作护栏说明

- **dry_run**:任意写操作传 `dry_run: true` → 只返回 `would`(将要发生什么),不执行。
- **危险操作确认**:`delete` / `refund` 等需两步:
  1. 先正常调用 → 返回 `confirmation_required` + 一个 `confirm_token`(5 分钟有效、绑定本次操作)。
  2. 取得用户确认后,带上该 `confirm_token` 再次调用 → 执行。
  - 受设置页「危险操作需二次确认」开关控制。
- **需确认的操作**:
  - 专用工具:各类 `delete`、`wc_orders refund`、`wp_users create/update/set_role`、`wp_plugins activate/deactivate/install/update`、`wc_webhooks create/update`、`wp_system options_set`
  - `wp_rest`:所有 DELETE,以及对退款、批量(`/batch`)、用户、插件、主题、站点设置、支付网关、店铺设置、webhook、系统工具、客户账户的写操作。可用过滤器 `wp_mcp_rest_sensitive_routes` 增改规则
- **密钥隐藏**:API 密钥、密码、签名密钥等一律返回 `[hidden]`,不提供解锁;写回时值为 `[hidden]` 的字段被忽略、保留原值。
- **受保护选项**:`options_set` 拒绝修改站点地址、启用插件、主题、管理员邮箱、注册与默认角色、角色表、密钥盐值及本插件自身设置(过滤器 `wp_mcp_protected_options`)。

## 更新机制(自动更新已启用)

插件内置一个**零依赖**的更新器([includes/update/class-updater.php](includes/update/class-updater.php)),
直连仓库的 **GitHub Releases** 检查新版。**本仓库为 Public,自动更新已生效**——各站后台像普通插件一样
收到更新提示、一键升级,无需逐站手动传包。

### 发版流程

1. 改好代码,把 [wp-mcp.php](wp-mcp.php) 与 [readme.txt](readme.txt) 的版本号一起 bump(如 `0.2.0`)。
2. 提交并推送到 `main`。
3. 打 tag 并发布 Release:tag 形如 `v0.2.0`(`v` 前缀会被自动去掉)。
4. 各站后台约 12 小时内自动提示更新;也可在「设置 → WP MCP → 插件更新 → 立即检查更新」强制刷新。

因布局是「插件文件放仓库根」,GitHub 源码包可直接安装,无需额外附 zip 资产(附打包好的 zip 更稳)。

### 配置

- 默认仓库 `shuerhome/wordpressmcp`;如需改,在 `wp-config.php` 定义 `WP_MCP_UPDATE_REPO`。
- 若日后改回私有:自动更新会失效(核心升级器下载源码包不带鉴权),届时改走手动上传 zip 升级。

## 下一步

- **0.5.0**:OAuth 登录(让 claude.ai 网页端、ChatGPT 等只接受 OAuth 的客户端可接入)、多令牌分级(只读/运营/完全管理)、调用限流、审计增强
- **0.6.0**:工具说明中英双语、自动化测试与 CI、打包脚本

已知限制:`wc_reports sales` 为毛销售额,未扣除退款;净额可用 `wp_rest` 调 `/wc-analytics/reports/revenue/stats`。

原规划中的其他项:审计日志落自定义表(替代滚动 option)、写操作记录前后值;打包构建脚本输出
可上传 `.zip`;自建私有插件更新服务器(5-10 站一处发版、各站自动提示升级)。
可选阶段 6:跨站编排规范、SKU 身份映射、MCP Prompts 工作流。
