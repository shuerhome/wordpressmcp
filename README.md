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

**阶段 6(多模型接入与权限,0.5.0)**
- ✅ OAuth 2.1 授权服务器(MCP 授权规范):受保护资源元数据、授权服务器元数据、动态客户端注册、客户端元数据文档、PKCE、刷新令牌轮换与重放检测、吊销端点
- ✅ 多连接:手动令牌与 OAuth 连接统一管理,三级权限(只读 / 运营 / 完全管理),可设有效期、单独吊销
- ✅ 限流:每连接每分钟读/写上限、每日危险操作上限
- ✅ 审计:独立数据表,记录连接与 IP,可筛选、导出 CSV,按天数自动清理

**阶段 7(更多平台与工程化,0.6.0)**
- ✅ OpenAPI 接口(文档 + 工具调用端点),供不支持 MCP 的平台与自建 Agent 使用
- ✅ 手动创建 OAuth 应用(GPT Actions 等)
- ✅ 工具说明中英双语
- ✅ 打包脚本、自动发版、CI 端到端测试(PHP 7.4 / 8.3)

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
   > 致命错误、整站白屏。请删掉多余目录,只留一个(目录名任意)。
2. 端点:`https://你的站点/wp-json/mcp/v1/rpc`
3. 按客户端选一种方式连接(0.5.0 起):

### A. 用账号登录(OAuth)— claude.ai、Claude 桌面版、ChatGPT、Gemini、Cursor、VS Code 等

在客户端里添加 MCP 服务器 / 自定义连接器,**只填端点 URL**。客户端会自动:

1. 从 401 响应发现本站的 OAuth 元数据(`/.well-known/oauth-protected-resource`、`/.well-known/oauth-authorization-server`);
2. 自动注册为本站的 OAuth 应用(或使用客户端元数据文档);
3. 打开本站登录页 → 授权页选择**权限级别** → 回到客户端完成连接。

要求:公网 HTTPS;固定链接不是「朴素」;服务器把 `/.well-known/*` 和 `/mcp-oauth/*` 交给 WordPress 处理(见下方排查)。

### B. 手动令牌 — Claude Code、脚本、自建 Agent、Cherry Studio 等可填请求头的客户端

「设置 → WP MCP → 新建令牌」,选名称、级别、绑定用户、有效期,生成后:

```bash
claude mcp add --transport http mysite https://你的站点/wp-json/mcp/v1/rpc --header "Authorization: Bearer <令牌>"
```

其他客户端的通用配置:`{ "url": "https://你的站点/wp-json/mcp/v1/rpc", "headers": { "Authorization": "Bearer <令牌>" } }`。
实在不能填请求头时可用 `?token=<令牌>`(仅限手动令牌,易被日志留存)。

### C. OpenAPI — 不支持 MCP 的平台(GPT 自定义操作、Dify、Coze、n8n、自建 Agent)

- 文档:`https://你的站点/wp-json/mcp/v1/openapi.json`(OpenAPI 3.0,每个工具一个操作)
- 调用:`POST https://你的站点/wp-json/mcp/v1/tools/<工具名>`,请求体就是工具参数,如 `{"action":"list","per_page":10}`
- 返回:成功 `{"ok":true,"result":...}`;出错 `{"ok":false,"error":"..."}`(越权 403、限流 429、参数错 400)
- 鉴权:`Authorization: Bearer <手动令牌>`,或 OAuth。平台要求手动填写 OAuth 信息时(如 GPT Actions),在「设置 → WP MCP → 新建 OAuth 应用」创建,回调地址填平台提供的 callback URL
- 用模型 API 自建 Agent 时,可把 openapi.json 中每个操作的 requestBody schema 直接作为函数(工具)定义

权限、限流、二次确认、审计与 MCP 完全一致。

### 权限级别

| 级别 | 能做什么 |
|---|---|
| 只读 | 查看商品、订单、库存、客户、报表、设置;不能修改任何数据;客户个人信息始终脱敏 |
| 运营 | 只读 + 商品/变体/库存、订单(改状态、备注、编辑,**不含退款**)、优惠券、客户、商品图片与分类 |
| 完全管理 | 全部功能,含退款、插件、用户、站点与店铺设置、支付配置 |

- 在服务端强制执行,越权调用直接拒绝并记入审计。
- 连接的有效级别不超过绑定用户自身的权限(管理员 → 完全管理;店铺管理员 → 运营;仅报表权限 → 只读),用户被降权后其连接随之降级或失效。
- 规则可用过滤器 `wp_mcp_tier_policy`、`wp_mcp_ops_rest_write_routes` 调整。

### OAuth 连不上时排查

| 现象 | 处理 |
|---|---|
| 客户端提示找不到授权服务器 | 浏览器打开 `https://你的站点/.well-known/oauth-authorization-server`,应返回 JSON。若 404:Nginx 常把 `.well-known` 当静态目录,需加 `location ^~ /.well-known/oauth- { try_files $uri /index.php?$args; }`;CDN/防火墙不要拦截该路径 |
| 授权页打不开 / 404 | 检查固定链接不是「朴素」;`/mcp-oauth/authorize` 需能被 WordPress 处理 |
| 登录后一直 401 | 部分 Apache(CGI/FastCGI)会丢弃 `Authorization` 头。在 `.htaccess` 加 `RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]`,或加 `CGIPassAuth On` |
| 云端客户端连接被拒 | 启用了 IP 白名单时,claude.ai、ChatGPT 等云端出口 IP 不固定,请关闭白名单 |
| WordPress 装在子目录 | `/.well-known/` 必须在域名根目录可访问,需在根目录服务器配置中转发到 WordPress |

### 限流与审计

- 每个连接每分钟读 120 次、写 30 次,每天经确认的危险操作 50 次(可在设置页调整,0 为不限)。超限时 AI 收到「请稍后重试」,不影响访客。
- 审计日志记录连接、用户、工具、动作、目标、结果、说明、IP,存独立数据表,默认保留 90 天,可在设置页筛选和导出 CSV。

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

1. 把 [wp-mcp.php](wp-mcp.php)(插件头 `Version` 与 `WP_MCP_VERSION`)与 [readme.txt](readme.txt)(`Stable tag`)的版本号一起改好,合并到 `main`。
2. 打 tag 并推送:`git tag v0.6.0 && git push origin v0.6.0`。
3. GitHub Actions([release.yml](.github/workflows/release.yml))自动校验版本号、用 `bin/build-zip.sh` 打包并创建 Release(附安装包)。
4. 各站后台约 12 小时内自动提示更新;也可在「设置 → WP MCP → 插件更新 → 立即检查更新」强制刷新。

> ⚠️ Release 一旦发布,**所有**装了本插件的站点都会收到更新。发版前确认改动对每个站点都可接受。

本地打包:`bin/build-zip.sh` → `build/wp-mcp-<版本>.zip`(不含测试、文档、开发文件)。

## 测试

```bash
tests/e2e/setup.sh /tmp/wp-mcp-e2e && tests/e2e/run.sh /tmp/wp-mcp-e2e
```

自动搭建 WordPress + WooCommerce(SQLite)测试站并运行全部端到端测试,详见 [tests/e2e/README.md](tests/e2e/README.md)。
每次推送与 PR 会在 GitHub Actions 上以 PHP 7.4 与 8.3 自动运行。

### 配置

- 默认仓库 `shuerhome/wordpressmcp`;如需改,在 `wp-config.php` 定义 `WP_MCP_UPDATE_REPO`。
- 若日后改回私有:自动更新会失效(核心升级器下载源码包不带鉴权),届时改走手动上传 zip 升级。

## 下一步

- 用真实客户端(claude.ai、ChatGPT、Gemini、Cursor 等)在线上站点逐一实测 OAuth 接入,按实际情况修正兼容细节。
- 可选:写操作记录改动前后的值、跨站编排、MCP Prompts 工作流。

已知限制:`wc_reports sales` 为毛销售额,未扣除退款;净额可用 `wp_rest` 调 `/wc-analytics/reports/revenue/stats`。
