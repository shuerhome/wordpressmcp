# WP MCP 0.3.0 — 发布说明与交接记录

> 日期:2026-06-22 · 状态:已发布、staging 部分实测 · 本文件不含任何令牌明文,可公开。

## 一、概要

0.3.0 在原有 WordPress / WooCommerce 控制能力之上,新增**页面板块编辑**:

- **Elementor 页面精修**(新工具 `wp_elementor`,仅在站点启用 Elementor 时出现)
- **古腾堡单块精修**(`wp_blocks` 新增 `update_block`)

已发布到 GitHub(latest release **v0.3.0**,带 `wp-mcp-0.3.0.zip` 资产),并已部署到 staging 验证 block 部分。

## 二、本版改动

### 新增工具 `wp_elementor`(条件注册:`defined('ELEMENTOR_VERSION')`)

| action | 作用 |
|---|---|
| `list_pages` | 列出用 Elementor 搭建的页面/文章 + ID |
| `get` | 把某页解析成可读元素树(id / 类型 / 文字预览) |
| `get_raw` | 取某元素(或整页)完整原始 settings |
| `find` | 按文字或 widget 类型定位元素,返回 id |
| `update_element` | 按 id 深合并改某元素 settings(文案/图片/链接/显隐) |
| `insert_element` / `move_element` / `delete_element` | 增 / 移 / 删板块 |
| `update_data` | 整树替换(高危) |
| `backups` / `rollback` | 列快照 / 按快照 ID 还原整页 |

实现要点:读 `_elementor_data`(`json_decode(get_post_meta())`),写回用 `update_post_meta(..., wp_slash(wp_json_encode()))`,写后用 `\Elementor\Core\Files\CSS\Post::create($id)->update()` + `files_manager->clear_cache()` 重生成 CSS。

### `wp_blocks` 新增 `update_block`

按序号精修古腾堡顶层区块:`attrs` 深合并改属性、`inner_html` 替换块的 HTML 文本(仅限无嵌套子区块的块);序号映射到 `parse_blocks` 真实下标,`serialize_blocks` 写回,留 WordPress 修订(可用 `wp_content:rollback` 还原)。

### 护栏(全部复用现有设施)

写前自动 `Backup::snapshot`、`dry_run` 预演、危险操作 `confirm_token` 两步确认、`Audit` 审计。`wp_elementor` 的危险动作:`delete_element` / `update_data` / `rollback`。

## 三、发布与分发

- GitHub:`shuerhome/wordpressmcp`,latest release v0.3.0(含 zip 资产,更新器优先用它而非源码包)。
- 各站升级:后台「设置 → WP MCP → 立即检查更新」→ 插件页一键更新;或「上传插件 → 替换现有」传 `wp-mcp-0.3.0.zip`。
- 升级**保留令牌与设置**(存数据库 `wp_options`,激活钩子用 `add_option` 不覆盖)。**不要先删旧插件**。

## 四、连接方式

- **Claude Code**:`claude mcp add --transport http <名> https://站点/wp-json/mcp/v1/rpc --header "Authorization: Bearer <令牌>"`
- **Claude 桌面版**:用 `npx mcp-remote` 桥接(静态 Bearer 令牌,免 OAuth),配置文件 `%APPDATA%\Claude\claude_desktop_config.json`。桌面版对远程服务器默认走 OAuth,故不能直接填 URL。
- **claude.ai 网页端**:自定义连接器填 `…/rpc?token=<令牌>`(需 ≥0.2.0;网页端若强制 OAuth 则此路不通)。

## 五、测试结论

### 已运行时验证(staging `store.shuerhome.com`,0.3.0)

- `wp_blocks update_block`:文本替换 ✅、属性深合并 ✅、序号映射 ✅、`dry_run` 预演不写 ✅、删除两步 `confirm_token` ✅;测试页建→改→永久删全过,**痕迹已清**。
- 0.3.0 整体加载**无报错**;能力门控正确(该站无 Elementor → `wp_elementor` 正确地不暴露)。

### 待验证

- **`wp_elementor` 本身尚未在真实 Elementor 页面跑过**(staging 是 Woodmart、未装 Elementor)。
- 但它**复用的护栏**(guard / dry_run / confirm_token / Backup)已在线上证明可用。

### 如何验证 `wp_elementor`(下次)

在装了 Elementor 的环境(staging 或生产 annaluxbag)更到 0.3.0 后:

1. `tools/list` 确认 `wp_elementor` 出现;
2. `list_pages` → `get` → `find` 读取定位;
3. `update_element` **先 `dry_run` 预演,确认无误再真改**;
4. `rollback` 用返回的 `backup_id` 还原;
5. 建议先拿**不重要的页面**试,每个写操作都先 `dry_run`。

## 六、已知限制

- **经典主题**(annaluxbag 的 Hello Elementor):`wp_design` 的 FSE(模板/全局样式)不适用,只剩 `theme_mods` + 附加 CSS。
- **页面构建器**:仅支持 Elementor;WPBakery / Divi / Woodmart 自带构建器 / CartFlows 漏斗等**未支持**。
- 第一版 `wp_elementor` 聚焦**精修现有页面**,不做从零生成整页布局。
- `Backup` 快照存单个 option(非自动加载,最多 50 条);Elementor 整页快照可能偏大,后续可考虑迁到自定义表。

## 七、安全

- 令牌以 sha256 哈希存数据库,明文仅生成时显示一次。
- **测试/演示用过的令牌建议重新生成、吊销旧的**(尤其曾内嵌在 URL 或贴进对话/第三方工具的)。
- 网页端 `?token=` 在 URL 中易被日志/历史留存,建议配合 IP 白名单。

## 八、环境基线(便于复现)

- 生产 `annaluxbag.com`:WooCommerce 10.8.1(HPOS)/ 主题 Hello Elementor + Elementor + CartFlows(经典主题)。
- staging `store.shuerhome.com`:主题 Woodmart 8.4.1 + WooCommerce 10.7.0(HPOS),**无 Elementor**。

## 九、下一步候选

- 在带 Elementor 的环境实测 `wp_elementor`,通过后再放心用于生产。
- 阶段 5 余项:审计落自定义表、调用限流、打包脚本。
- 可选:`wp_elementor` 扩到「从零生成整页」、Elementor 更多 widget 的结构化辅助。
