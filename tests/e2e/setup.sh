#!/usr/bin/env bash
# 搭建端到端测试站:WordPress(SQLite,无需 MySQL)+ WooCommerce + 本插件。
# 用法:tests/e2e/setup.sh <工作目录>
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PLUGIN="$(cd "$HERE/../.." && pwd)"
WORK="$(mkdir -p "${1:?需要工作目录}" && cd "$1" && pwd)"
WP_BRANCH="${WP_BRANCH:-6.8-branch}"
WC_VERSION="${WC_VERSION:-10.3.5}"

cd "$WORK"
rm -rf site src && mkdir -p src

echo "== WordPress $WP_BRANCH"
git clone -q --depth 1 -b "$WP_BRANCH" https://github.com/WordPress/WordPress.git src/wordpress
cp -r src/wordpress site && rm -rf site/.git

echo "== SQLite 数据库集成"
git clone -q --depth 1 https://github.com/WordPress/sqlite-database-integration.git src/sqlite
SQ="$WORK/site/wp-content/plugins/sqlite-database-integration"
if [ -d src/sqlite/packages/plugin-sqlite-database-integration ]; then
  cp -r src/sqlite/packages/plugin-sqlite-database-integration "$SQ"
  rm -rf "$SQ/wp-includes/database"
  cp -r src/sqlite/packages/mysql-on-sqlite/src "$SQ/wp-includes/database"
else
  cp -r src/sqlite "$SQ" && rm -rf "$SQ/.git"
fi
sed "s#{SQLITE_IMPLEMENTATION_FOLDER_PATH}#$SQ#; s#{SQLITE_PLUGIN}#sqlite-database-integration/load.php#" "$SQ/db.copy" > site/wp-content/db.php

echo "== WooCommerce $WC_VERSION"
curl -fsSL -o src/wc.zip "https://github.com/woocommerce/woocommerce/releases/download/$WC_VERSION/woocommerce.zip"
unzip -q src/wc.zip -d site/wp-content/plugins/

ln -s "$PLUGIN" site/wp-content/plugins/wp-mcp

cat > site/wp-config.php <<'CFG'
<?php
define( 'DB_NAME', 'wp' ); define( 'DB_USER', '' ); define( 'DB_PASSWORD', '' ); define( 'DB_HOST', '' );
define( 'DB_CHARSET', 'utf8' ); define( 'DB_COLLATE', '' );
$table_prefix = 'wp_';
define( 'WP_DEBUG', true ); define( 'WP_DEBUG_LOG', __DIR__ . '/debug.log' ); define( 'WP_DEBUG_DISPLAY', false );
define( 'FS_METHOD', 'direct' ); define( 'DISABLE_WP_CRON', true );
if ( ! defined( 'ABSPATH' ) ) { define( 'ABSPATH', __DIR__ . '/' ); }
require_once ABSPATH . 'wp-settings.php';
CFG

php "$HERE/install.php" "$WORK/site" "$WORK"
php "$HERE/fixtures.php" "$WORK/site" "$WORK" activate
php "$HERE/fixtures.php" "$WORK/site" "$WORK"
echo "== 测试站就绪:$WORK/site"
