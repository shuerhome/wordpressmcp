# 端到端测试

在一个临时 WordPress 站点上(SQLite 数据库,无需 MySQL)安装 WooCommerce 与本插件,
以真实 HTTP 请求调用 MCP 与 OpenAPI 端点。

```bash
tests/e2e/setup.sh /tmp/wp-mcp-e2e   # 下载 WordPress、SQLite 集成、WooCommerce 并安装,生成测试数据
tests/e2e/run.sh /tmp/wp-mcp-e2e     # 启动 php -S,依次运行全部测试
```

需要:PHP 7.4+(含 pdo_sqlite)、Python 3.8+、git、curl、unzip,以及访问 GitHub 的网络。
可用环境变量 `WP_BRANCH`(默认 `6.8-branch`)、`WC_VERSION`(默认 `10.3.5`)切换版本。

| 文件 | 内容 |
|---|---|
| `test_040_security.py` | 敏感操作确认、密钥隐藏、受保护选项、base64 上传、命名空间限制、升级清理 |
| `test_050_oauth.py` | 模拟完整 OAuth 客户端:发现 → 动态注册 → 登录授权 → PKCE → 刷新轮换与重放检测 → 吊销;三级权限、用户降权、限流、审计、后台页面 |
| `smoke.py` | 17 个工具的基本读操作、双语说明、OpenAPI 文档与调用 |

测试数据由 `fixtures.php` 生成,并把插件状态设为「从 0.3.0 升级」,以覆盖迁移逻辑。
