# WP MCP — WordPress/WooCommerce MCP 服务器插件

把本站变成一个 **MCP 服务器**,让 Claude 通过站点 URL 直接、精细地控制 WordPress / WooCommerce。

> 完整方案见 [`docs/development-plan.md`](docs/development-plan.md)。

## 当前进度:阶段 0 → 阶段 3(MVP 已齐)

**阶段 0(脚手架与协议层)**
- ✅ MCP 协议处理器(JSON-RPC 2.0 over 无状态 Streamable HTTP)
- ✅ Bearer 令牌鉴权 + 绑定用户 + HTTPS/IP 护栏
- ✅ 能力探测(WordPress / WooCommerce / 主题类型 / 关键插件)
- ✅ 工具注册表 + 抽象工具基类(统一 action / dry_run / 审计)
- ✅ `wp_site`:`info` / `capabilities` / `health` / `flush_cache`
- ✅ 后台设置页:端点、令牌、绑定用户、安全开关、能力概览、审计日志

**阶段 1(只读连通)**
- ✅ `wp_content`:`list` / `get` / `revisions` / `types`(文章/页面/CPT)
- ✅ `wc_products`:`list` / `get` / `variations` / `categories`
- ✅ `wc_orders`:`list` / `get`(客户 PII 默认脱敏,`reveal_pii` 可解锁)
- ✅ `wc_reports`:`sales` / `top_products`(按日期区间聚合)
- ✅ WC 工具按能力探测条件注册(无 WooCommerce 则不暴露)
- ✅ PII 脱敏助手(邮箱/电话/姓名/地址)

**阶段 2(内容与订单写操作)**
- ✅ 写护栏 `Guard`:`dry_run` 预演 + 危险操作 `confirm_token` 一次性二次确认
- ✅ `wp_content` 写:`create` / `update` / `delete`(回收站/永久) / `rollback`(修订回滚)
- ✅ `wp_media`:`list` / `get` / `upload`(URL sideload) / `update` / `delete`
- ✅ `wp_blocks`:`get_blocks`(解析) / `reusable_*`(可复用区块 CRUD) / `patterns`
- ✅ `wc_products` 写:`create` / `update` / `delete`
- ✅ `wc_orders` 写:`set_status` / `note` / `refund`(资金,需确认) / `update`
- ✅ `wc_inventory`:`get` / `set_stock` / `bulk_set` / `low_stock`

**阶段 3(设计控制)**
- ✅ 设计快照仓库 `Backup`:写操作前自动存旧值,可按快照 ID 回滚
- ✅ `wp_design`(FSE 块主题):`get/set_global_styles`(theme.json 用户层,深合并)、`list/get/update/revert_template`、`list/get/update_template_part`
- ✅ `wp_design`(经典主题):`get/set_theme_mods`、`get/set_custom_css`(Additional CSS)
- ✅ `wp_design` 通用:`list_fonts`、`backups`(列快照)、`rollback`(按 ID 还原,需确认)
- ✅ `wp_theme`:`list` / `get` / `activate`(记录原主题可回滚) / `install`(slug 或 zip) / `update` / `delete` / `create_child`
- ✅ FSE/经典分支:能力探测区分块主题,FSE 专属 action 在经典主题上友好报错

**阶段 4(全功能补齐)**
- ✅ `wp_taxonomy`:`list_taxonomies` / `list` / `get` / `create` / `update` / `delete`(分类/标签/自定义)
- ✅ `wp_comments`:`list` / `get` / `approve` / `unapprove` / `spam` / `trash` / `reply` / `edit` / `delete`(作者 PII 脱敏)
- ✅ `wp_users`:`list` / `get` / `create` / `update` / `set_role` / `delete`(邮箱脱敏 + 能力校验)
- ✅ `wp_settings`:`get` / `set`(白名单:常规/阅读/讨论/固定链接;改链接自动刷新重写)
- ✅ `wp_plugins`:`list` / `activate` / `deactivate` / `install` / `update` / `delete`
- ✅ `wp_menus`:`list` / `get` / `create` / `delete` / `add_item` / `update_item` / `delete_item` / `locations` / `assign_location`
- ✅ `wp_widgets`:`sidebars` / `get`(只读盘点;区块小工具写入易碎,布局调整走 `wp_design`)
- ✅ `wp_system`:`site_health` / `cron_list` / `cron_run` / `cache_flush` / `transients_flush` / `options_get` / `options_set`
- ✅ `wc_customers`:`list` / `get` / `create` / `update` / `delete`(PII 脱敏 + 订单数/消费额)
- ✅ `wc_coupons`:`list` / `get` / `create` / `update` / `delete`
- ✅ `wc_settings`:`get` / `set`(货币/店铺地址/销售地区/商品/结账,白名单)
- ✅ `wc_shipping`:`list_zones` / `get_zone` / `create_zone` / `add_method` / `delete_zone`
- ✅ `wc_webhooks`:`list` / `get` / `create` / `update` / `delete`

> 工具总数:WordPress 13 个 + WooCommerce 9 个,经能力探测后按站点条件暴露。

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
│   │   ├── wp/                 # site/content/media/blocks/design/theme/taxonomy/comments/users/settings/plugins/menus/widgets/system
│   │   └── wc/                 # products/orders/reports/inventory/customers/coupons/settings/shipping/webhooks
│   ├── capability/class-detector.php
│   ├── safety/                 # audit / pii / guard / backup(快照回滚)
│   └── admin/class-settings.php
├── composer.json
└── readme.txt
```

## 安装与连接

1. 把 `wp-mcp` 目录打包为 zip,后台「插件 → 上传安装」并启用。
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

## 更新机制

插件内置一个**零依赖**的更新器([includes/update/class-updater.php](includes/update/class-updater.php)),
可直连仓库的 **GitHub Releases** 检查新版。但它的「检查版本」与「下载安装」两步都依赖对仓库的**匿名访问**:

- ✅ **仓库为 Public 时**:自动更新开箱即用——各站后台像普通插件一样收到更新提示、一键升级。
- ⚠️ **本仓库当前为 Private**:匿名访问拿不到 Releases,且 WordPress 升级器下载源码包时不带鉴权,
  所以**自动更新不生效**(更新器会优雅失败,不影响插件功能)。私有仓库请走下面的**手动升级**。

> 即便用 `wp_mcp_github_auth_token` 过滤器给「检查」步骤提供 token,核心升级器的「下载」步骤仍不带鉴权,
> 私有源码包会下载失败。因此私有仓库下不建议依赖自动更新。

### 手动升级(私有仓库)

1. 改好代码,把 [wp-mcp.php](wp-mcp.php) 与 [readme.txt](readme.txt) 的版本号一起 bump(如 `0.2.0`),提交推送。
2. 把插件打包为 zip(根目录即 `wp-mcp/`)。
3. 各站后台「插件 → 上传插件」覆盖安装;或用本插件的 `wp_plugins` 工具远程升级。

### 想要自动更新

把仓库改为 Public 即可,**无需改代码**:更新器会自动开始工作。默认仓库 `shuerhome/wordpressmcp`,
可用常量 `WP_MCP_UPDATE_REPO`(定义在 `wp-config.php`)覆盖。发版时在仓库发布 Release(tag 形如 `v0.2.0`,
`v` 前缀自动去掉);因布局是「插件文件放仓库根」,GitHub 源码包可直接安装,无需额外附 zip 资产。

## 下一步(阶段 5:安全加固与分发)

审计日志落自定义表(替代滚动 option)、写操作记录前后值;调用限流;打包构建脚本输出
可上传 `.zip`;自建私有插件更新服务器(5-10 站一处发版、各站自动提示升级)。
可选阶段 6:跨站编排规范、SKU 身份映射、MCP Prompts 工作流。
