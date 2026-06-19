# WordPress / WooCommerce MCP 插件 — 完整开发方案

> 版本 v1.0 ｜ 定位:单人自用、多站点(5-10)、通过插件让 Claude 精细控制 WordPress / WooCommerce 全部功能。

---

## 1. 项目概述

### 1.1 目标
做一个 **WordPress 插件**,装到站点后,该站点本身就成为一个 **MCP 服务器**。Claude(Claude Code / Desktop / 网页版)通过站点 URL 直接连接,即可精细控制该站的 WordPress 与 WooCommerce 全部功能——内容、主题设计、商品、订单等。

### 1.2 核心架构决策(已确定)
| 决策 | 选择 | 理由 |
|------|------|------|
| 形态 | **插件即 MCP 服务器**(PHP,跑在 WP 内部) | 直接调用 WP/WC 函数,不受 REST API 暴露范围限制,覆盖率接近 100% |
| 连接 | **Claude 直连每个站的 URL**,不做中枢 | 自用场景最简单;各站令牌隔离,安全性更好;插件完全一致,易分发 |
| 跨站 | **由 Claude 编排**(同时连多个站,Claude 汇总/分发) | 不需要任何站持有别站凭证;满足"对比销售""同步商品"等需求 |
| 工具粒度 | **精炼的动作型工具(~20 个,action + 参数)** | 控制上下文占用,使同时连 5-10 个站仍轻量 |
| 传输 | **Streamable HTTP(无状态)** | 契合 PHP 请求-响应模型 |
| 鉴权 | **Bearer 令牌 / Application Password** | 自用足够,免 OAuth 复杂度 |
| 规模 | 单人、5-10 站 | 不做多租户、不做计费、不做隔离层 |

### 1.3 不做什么(明确排除)
- ❌ 多租户 / SaaS / 计费 / 用户隔离
- ❌ 中枢站(改为 Claude 直连 + 编排;保留为未来可选优化)
- ❌ 外置 Node 服务 / Cloudflare Workers(全部逻辑在插件内)

---

## 2. 系统架构

```
┌──────────────────────────────┐
│   Claude (Code / Desktop / Web) │
│   同时直连需要操作的若干站点      │
└──────────────────────────────┘
   │ MCP over HTTP (Streamable, 无状态)
   │ Authorization: Bearer <每站独立令牌>
   │
   ├──────────────┬──────────────┬─────────── ...
   ▼              ▼              ▼
站点A 插件       站点B 插件       站点C 插件
https://A/wp-json/mcp/v1   ...   ...
┌────────────────────────────┐
│  插件内部(每个站一份,完全相同) │
│  ┌──────────────────────┐  │
│  │ MCP 协议处理器 (JSON-RPC)│  │
│  ├──────────────────────┤  │
│  │ 鉴权 + 能力探测         │  │
│  ├──────────────────────┤  │
│  │ 工具注册表 (~20 个动作工具)│  │
│  ├──────────────────────┤  │
│  │ 安全护栏 (dry_run/确认/审计)│  │
│  ├──────────────────────┤  │
│  │ 适配层 → 直接调用:       │  │
│  │  wp_insert_post()      │  │
│  │  wc_get_orders()       │  │
│  │  wp_get_global_styles()│  │
│  │  get_theme_mod() ...   │  │
│  └──────────────────────┘  │
└────────────────────────────┘
```

**跨站工作方式**:Claude 同时连上相关站点,例如"对比 A/B/C 本周销售"→ Claude 分别调用各站 `wc_reports` 再汇总;"把商品上架到 A/B/C"→ Claude 依次调用各站 `wc_products`。编排发生在 Claude 端,无需任何站点互相持有凭证。

---

## 3. 技术选型

| 层 | 选型 |
|----|------|
| 语言 | PHP 7.4+ / 8.x(跟随 WP 要求) |
| 框架 | 原生 WordPress Plugin API + `register_rest_route()` |
| 协议 | MCP(JSON-RPC 2.0 over Streamable HTTP) |
| 鉴权 | Application Password / 自管 Bearer 令牌 |
| 长任务 | Action Scheduler(WooCommerce 自带,异步绕开 PHP 时限) |
| 依赖管理 | Composer(仅用于开发/打包,运行时尽量零外部依赖) |
| 本地测试 | `wp-env`(Docker)或 LocalWP,装一份带 WooCommerce 的站 |
| 代码规范 | WordPress Coding Standards (PHPCS) |
| 打包 | 构建脚本输出可上传的 `.zip` |

---

## 4. MCP 协议实现

插件注册一个 REST 命名空间作为 MCP 端点:

```
POST  /wp-json/mcp/v1            ← 主端点,接收所有 JSON-RPC 请求
GET   /wp-json/mcp/v1            ← (可选) SSE,无状态模式下可不实现
```

### 4.1 需实现的 JSON-RPC 方法
| 方法 | 说明 |
|------|------|
| `initialize` | 握手,返回协议版本与服务器能力(tools/resources/prompts) |
| `tools/list` | 返回该站可用工具(经能力探测过滤) |
| `tools/call` | 执行某个工具 |
| `resources/list` / `resources/read` | 只读上下文(theme.json、站点结构、订单快照等) |
| `prompts/list` / `prompts/get` | 封装好的工作流提示词 |
| `ping` | 健康探测 |

### 4.2 传输策略
- 采用 **无状态 Streamable HTTP**:每个 POST 请求自包含,直接同步返回 JSON-RPC 结果,不维护长连接 SSE → 完美契合 PHP 短生命周期。
- 重操作(重建缩略图、大批量、导出)交给 **Action Scheduler** 异步执行,工具立即返回 `task_id`,再用 `wp_system(action: task_status)` 查询进度。

---

## 5. 鉴权与连接

### 5.1 令牌
- 插件后台生成 **每站独立** 的访问令牌(可重新生成、可吊销)。
- 校验:`Authorization: Bearer <token>`,或复用 WordPress **Application Password**。
- 令牌映射到一个具备所需权限的 WP 用户(建议专建一个管理员或自定义角色用户)。

### 5.2 插件后台设置页(`设置 → MCP`)
```
本站 MCP 端点:  https://本站.com/wp-json/mcp/v1     [复制]
访问令牌:        ak_xxxxxxxxxxxx        [重新生成] [吊销]
绑定用户:        [选择 WP 用户 ▾]
能力概览:        WP 6.x | WooCommerce 9.x(已检测) | FSE 块主题
安全开关:        [✓] 危险操作需确认  [✓] PII 脱敏  [✓] 审计日志
IP 白名单(可选): ____________
一键关闭 MCP:    [ 停用端点 ]
```

### 5.3 Claude 端连接
```bash
claude mcp add --transport http 站点A \
  https://站点A.com/wp-json/mcp/v1 \
  --header "Authorization: Bearer ak_xxxx"
```
要跨站时,把相关站点各加一条即可;Claude 会自动按站点给工具加前缀(`站点A:wc_orders`)。

---

## 6. 能力探测

`initialize` / `tools/list` 时,插件探测当前站点环境,**只暴露适用工具**:
- WordPress 版本、是否 FSE 块主题(决定 `wp_design` 的能力分支)
- WooCommerce 是否安装、版本、是否启用 HPOS(决定是否暴露 `wc_*`)
- 已装关键插件(Elementor / ACF / CartFlows 等)→ 决定是否启用对应桥接
- 当前用户能力(capabilities)→ 隐藏无权操作的工具

这样纯博客站不会出现订单工具,无 WooCommerce 不会出现商品工具。

---

## 7. 工具设计(精炼动作型,~20 个)

为控制上下文占用,采用 **「域工具 + action 参数」** 而非上百个碎工具。每个工具统一支持:
- `action`:操作类型(list/get/create/update/delete/...)
- `dry_run`(默认 false):预演,返回将要改动的 diff,不真正执行
- `confirm_token`:危险操作的二次确认令牌

### 7.1 WordPress 工具

| 工具 | 主要 action | 通道 |
|------|------------|------|
| `wp_site` | info / capabilities / health / flush_cache | 直调 |
| `wp_content` | list / get / create / update / delete / revisions / rollback(文章/页面/CPT) | 直调 |
| `wp_blocks` | get_blocks / set_blocks / reusable_* / patterns(古腾堡区块) | 直调 |
| `wp_media` | list / upload / update / delete / regenerate | 直调 |
| `wp_taxonomy` | list / create / update / delete(分类/标签/自定义) | 直调 |
| `wp_comments` | list / approve / spam / reply / edit / delete | 直调 |
| `wp_menus` | list / create / update / reorder / assign_location | 直调 |
| `wp_widgets` | list / update / assign(小工具/侧边栏) | 直调 |
| `wp_users` | list / get / create / update / delete / set_role(PII敏感) | 直调 |
| `wp_settings` | get / set(常规/阅读/讨论/固定链接/站点图标) | 直调 |
| `wp_theme` | list / activate / install / update / delete / create_child | 直调 |
| `wp_design` | global_styles / templates / template_parts / theme_mods / custom_css / fonts | 直调 |
| `wp_plugins` | list / activate / deactivate / install / update / delete / settings | 直调 |
| `wp_system` | site_health / cron / transients / options / import / export / task_status | 直调 |

### 7.2 WooCommerce 工具(检测到 WC 才暴露)

| 工具 | 主要 action | 通道 |
|------|------------|------|
| `wc_products` | list / get / create / update / delete / variations / attributes | 直调 |
| `wc_inventory` | get / set_stock / low_stock / bulk_set | 直调 |
| `wc_orders` | list / get / create / update / set_status / note / refund / delete | 直调 |
| `wc_customers` | list / get / create / update / delete(默认脱敏) | 直调 |
| `wc_coupons` | list / create / update / delete | 直调 |
| `wc_reports` | sales / orders / products / customers / coupons / taxes / kpi | 直调 |
| `wc_settings` | general / products / tax / shipping / checkout / accounts / emails | 直调 |
| `wc_shipping` | zones / methods / locations | 直调 |
| `wc_webhooks` | list / create / update / delete | 直调 |

### 7.3 MCP Resources(只读上下文)
- `theme.json`(当前全局样式)、模板清单、站点结构图
- 商品/订单实时快照、低库存清单、待处理订单

### 7.4 MCP Prompts(工作流)
- 「重设计首页 Hero 并预览」「生成本周销售周报」「批量处理待发货订单」「上新商品(图+价+库存+分类)」「换品牌主色并回滚演练」

---

## 8. 完整功能需求清单

> 标注:🟢只读 ｜ 🟡可写(可逆) ｜ 🔴危险(不可逆/删除/资金)。所有 🟡🔴 支持 `dry_run`;🔴 强制二次确认。

### 第一部分:WordPress 核心

**A. 内容管理(文章/页面/CPT)**
- 列出/搜索/获取 🟢;创建/更新/草稿/定时发布/状态切换 🟡;删除/回收站/永久删除 🔴
- 修订历史查看/回滚 🟡;自动草稿 🟡;meta/自定义字段(含 ACF)读写 🟡
- 特色图/摘要/slug/置顶/密码保护/评论开关/作者指派 🟡

**B. 区块 / 古腾堡**
- 按区块语义读写内容 🟡;可复用区块 CRUD 🟡;样板 patterns 应用 🟡;区块校验/序列化 🟡

**C. 媒体库**
- 列出/搜索 🟢;上传 🟡;改 alt/标题/裁剪 🟡;删除 🔴;重建缩略图 🟡

**D. 分类法**
- 分类/标签/自定义 term CRUD 🟡;层级与 term meta 🟡

**E. 评论**
- 列出/获取 🟢;审核/回复/编辑 🟡;删除 🔴

**F. 导航菜单**
- 菜单 CRUD、菜单项排序/层级 🟡;位置分配 🟡;FSE 导航区块 🟡

**G. 小工具/侧边栏**
- 区域列出 🟢;小工具 CRUD/分配 🟡

**H. 用户与权限**
- 列出/搜索/获取 🟢(PII);创建/更新/改角色 🟡;删除/重指派 🔴;角色能力管理 🔴;Application Passwords 🟡

**I. 站点设置**
- 常规(标题/副标题/时区/语言/日期) 🟡;阅读(首页/每页数/可见性) 🟡;讨论 🟡;固定链接 🔴;站点图标/Logo 🟡

**J. 主题管理**
- 列出/当前 🟢;激活/切换 🔴;安装(.org/zip)/更新/删除 🔴;子主题创建 🟡

**K. 设计/外观** ⭐
- *FSE 块主题*:全局样式(调色板/字体/间距/圆角) 🟡;模板 CRUD 🟡;模板部件(页眉/页脚) 🟡;字体库 🟡;样式变体 🟡
- *经典主题*:theme_mods/Customizer 读写 🟡;Additional CSS 🟡;主题文件编辑 🔴
- *页面构建器(后期)*:Elementor `_elementor_data` 读写 🔴脆弱

**L. 插件管理**
- 列出/状态 🟢;激活/停用 🟡;安装(.org/zip)/更新/删除 🔴;逐插件设置读写 🟡

**M. 系统维护**
- Site Health 🟢;WP-Cron 列/触发/删 🟡;Transient/对象缓存清理 🟡;options 读写 🔴;导入/导出(WXR) 🟡;数据库搜索替换 🔴;缓存插件清缓存 🟡

### 第二部分:WooCommerce

**N. 商品**
- 列出/搜索/获取(含 batch) 🟢;创建/更新(简单/可变/分组/外部) 🟡;删除 🔴
- 变体 CRUD 🟡;属性+项 🟡;分类/标签/配送类别 🟡;评价 CRUD/审核 🟡
- 价格/促销价/定时促销/SKU/虚拟/可下载 🟡;追加/交叉销售 🟡

**O. 库存**
- 查询/缺货/低库存 🟢;设库存数量/状态/后补订单 🟡;批量改 🟡

**P. 订单** ⭐
- 列出/筛选(状态/日期/客户/金额) 🟢(PII);详情(明细/地址/支付/运单) 🟢(PII)
- 创建/编辑/增删订单项 🟡;改状态 🟡;备注(客户可见/内部) 🟡;退款(全/部分) 🔴资金;删除 🔴
- HPOS 高性能订单存储兼容

**Q. 客户**
- 列出/搜索/获取(默认脱敏) 🟢(PII);创建/更新/地址 🟡;删除 🔴;下载权限/消费统计 🟢

**R. 优惠券**
- CRUD(固定/百分比/免运费) 🟡;使用限制(最低消费/适用商品/次数) 🟡;删除 🔴

**S. 报表与分析**
- 销售报表(日/周/月/区间) 🟢;营收/订单/商品/分类/优惠券/税/退款 🟢;热销/客户分析/库存 🟢;KPI 🟢

**T. 税务**
- 税率 CRUD/税务分类 🟡;税务设置(含税价/按地址计税) 🟡

**U. 配送**
- 配送区域 CRUD 🟡;区域内配送方式 🟡;地理位置 🟡

**V. 支付网关**
- 列出/启用/禁用/排序 🟡;网关配置(标题/描述/测试模式,密钥敏感) 🟡

**W. WooCommerce 设置**
- 常规(地址/货币/销售地区) 🟡;商品设置 🟡;账户与隐私 🟡;结账页 🟡;邮件通知 🟡

**X. Webhooks / 系统**
- Webhook CRUD 🟡;系统状态报告 🟢;系统工具(清缓存/重建权限) 🟡;数据接口(国家/货币) 🟢

**Y. 扩展(如已安装)**
- Subscriptions 🟡;Bookings/Memberships 🟡;CartFlows 桥接 🟡

---

## 9. 跨站操作策略

跨站由 **Claude 编排**,但有几类要规范处理:

| 类型 | 做法 | 难点 |
|------|------|------|
| 跨站对比(只读) | Claude 调各站 `wc_reports` 后汇总 | 货币/时区统一 |
| 跨站同步商品(写) | Claude 逐站调 `wc_products`,每站 `dry_run` 预演→确认→执行 | **分类/属性/货币按各站映射**,不能裸复制 |
| 跨站更新同一商品 | 按 **SKU** 匹配各站对应商品 | 同一商品各站 ID 不同,需身份映射 |
| 部分失败 | 逐站返回成功/失败,支持重试 | 跨站无事务,不假装原子 |

`wc_products` 工具应支持 `match_by: sku|slug` 参数,便于跨站定位"同一个商品"。

---

## 10. 安全与护栏

1. **dry_run 预演**:所有 🟡🔴 默认可先返回 diff 不执行
2. **二次确认**:🔴 操作(退款/删除/动文件/改权限/装删主题插件)需 `confirm_token`
3. **自动备份**:设计/设置类改动前自动快照,失败一键回滚;内容用 WP 原生修订版本
4. **PII 脱敏**:订单/客户的邮箱/电话/地址默认脱敏,显式请求才解锁
5. **审计日志**:每个写操作记录 谁/改了什么/何时/前后值,后台可查
6. **令牌安全**:每站独立令牌,可吊销;不进对话/日志;支持 IP 白名单
7. **限流**:防止异常高频调用拖垮站点
8. **一键关闭**:后台可随时停用 MCP 端点
9. **强制 HTTPS**:非 HTTPS 拒绝服务

---

## 11. 分发与更新(5-10 站)

- **打包**:构建脚本输出标准 `.zip`,可直接上传安装
- **更新机制(二选一)**:
  - 简单:手动上传新 `.zip`(站少时可接受)
  - 推荐:自建 **私有插件更新服务器**(各站像普通插件一样自动检查更新),装到 5-10 站后一处发版、各站自动提示升级
- **配置可移植**:插件无站点专属硬编码,装到任意站即用;首次激活引导生成令牌

---

## 12. 插件目录结构(建议)

```
wp-mcp/
├── wp-mcp.php                  # 插件主文件(头信息、激活/停用钩子)
├── composer.json
├── readme.txt
├── includes/
│   ├── class-plugin.php        # 引导/单例
│   ├── mcp/
│   │   ├── class-server.php    # MCP 协议处理(JSON-RPC、initialize、分发)
│   │   ├── class-transport.php # Streamable HTTP(无状态)
│   │   ├── class-auth.php      # 令牌校验/Application Password
│   │   └── class-registry.php  # 工具/资源/提示词注册表
│   ├── tools/
│   │   ├── abstract-tool.php   # 工具基类(action 分发、dry_run、确认、审计)
│   │   ├── wp/                 # wp_* 工具实现
│   │   └── wc/                 # wc_* 工具实现
│   ├── capability/
│   │   └── class-detector.php  # 能力探测(WP/WC/主题类型/已装插件)
│   ├── safety/
│   │   ├── class-guard.php     # dry_run / 确认 / 限流
│   │   ├── class-backup.php    # 快照与回滚
│   │   ├── class-pii.php       # 脱敏
│   │   └── class-audit.php     # 审计日志
│   └── admin/
│       └── class-settings.php  # 后台设置页(端点/令牌/开关/审计查看)
├── assets/                     # 后台 UI 资源
├── tests/                      # PHPUnit / 集成测试
└── build/                      # 打包脚本输出
```

---

## 13. 分阶段开发计划

| 阶段 | 内容 | 里程碑 | 预估 |
|------|------|--------|------|
| **0 脚手架与协议** | 插件骨架、MCP 协议处理器、无状态 Streamable HTTP、鉴权、后台设置页、能力探测 | Claude 能连上,`tools/list` 可见,`wp_site:info` 可调用 | 3-5 天 |
| **1 只读连通** | `wp_site`、`wp_content`(读)、`wc_orders`(读)、`wc_products`(读)、`wc_reports` | 在 Claude 里查看站点全部核心数据 | 3-4 天 |
| **2 内容与订单写** | `wp_content`/`wp_media`/`wp_blocks` 写;`wc_orders`/`wc_products`/`wc_inventory` 写;dry_run 护栏 | 能管理内容与订单(带预演/确认) | 1 周 |
| **3 设计控制** ⭐ | `wp_design`(FSE 全局样式/模板/部件)、`wp_theme`、经典 theme_mods/Custom CSS、备份回滚 | 能改主题设计并安全回滚 | 1-1.5 周 |
| **4 全功能补齐** | `wp_taxonomy/comments/menus/widgets/users/settings/plugins/system`;`wc_customers/coupons/settings/shipping/webhooks` | 全功能覆盖 | 1-1.5 周 |
| **5 安全加固与分发** | 审计日志、PII 脱敏、危险确认、限流;打包、私有更新服务器 | 可安全部署到全部 5-10 站 | 3-5 天 |
| **6 跨站与打磨**(可选) | 跨站编排使用规范、身份映射(SKU)、MCP Prompts 工作流 | 一条指令跨站对比/同步顺畅 | 1 周 |

**MVP = 阶段 0-3**(约 3-4 周):能查订单、管内容、改 FSE 主题设计。

---

## 14. 关键风险

| 风险 | 影响 | 应对 |
|------|------|------|
| 公网端点被攻破 | 整站失守 | 强令牌+HTTPS+限流+IP白名单+可一键关闭+审计 |
| 主题类型差异 | 设计控制深度不一 | 能力探测分支:FSE 一等公民,经典走 theme_mods,构建器后期 |
| PHP 执行时限 | 重操作超时 | Action Scheduler 异步 + task_status 查询 |
| 各站版本漂移 | 工具不兼容 | 能力探测 + 优雅降级 |
| 缓存插件 | 改动前台不刷新 | 写操作后自动清缓存 |
| 跨站异构 | 同步出错 | SKU 身份映射、逐站映射分类/货币、dry_run、部分失败可重试 |
| 客户 PII 合规 | 隐私风险 | 默认脱敏、按需解锁、审计 |
| 多站更新维护 | 升级繁琐 | 私有更新服务器,一处发版 |

---

## 15. 下一步

1. 搭建本地测试站(`wp-env` + WooCommerce)
2. 开始 **阶段 0**:插件骨架 + MCP 协议处理器 + 鉴权 + `wp_site:info`,先打通 Claude 连接
3. 按里程碑推进,每阶段产出可在 Claude 里实测的能力

---

*本文件为活文档,随开发推进更新。*
