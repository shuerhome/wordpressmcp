#!/usr/bin/env bash
# 打包插件安装包:build/wp-mcp-<版本>.zip(内含 wp-mcp/ 目录)。
# 用法:bin/build-zip.sh            打包当前提交(HEAD)
#       bin/build-zip.sh v0.6.0     打包某个 tag,并校验 tag 与插件版本一致
set -euo pipefail
cd "$(dirname "$0")/.."

ref="${1:-HEAD}"
version=$(git show "$ref:wp-mcp.php" | sed -n "s/^define( 'WP_MCP_VERSION', '\([^']*\)' );/\1/p")
header=$(git show "$ref:wp-mcp.php" | sed -n 's/^ \* Version: *//p' | tr -d '[:space:]')
stable=$(git show "$ref:readme.txt" | sed -n 's/^Stable tag: *//p' | tr -d '[:space:]')

if [ -z "$version" ] || [ "$version" != "$header" ] || [ "$version" != "$stable" ]; then
  echo "版本号不一致:WP_MCP_VERSION=$version,插件头 Version=$header,readme Stable tag=$stable" >&2
  exit 1
fi
if [ "$ref" != "HEAD" ] && [ "${ref#v}" != "$version" ]; then
  echo "tag $ref 与插件版本 $version 不一致" >&2
  exit 1
fi

mkdir -p build
out="build/wp-mcp-$version.zip"
rm -f "$out"
git archive --worktree-attributes --format=zip --prefix=wp-mcp/ -o "$out" "$ref"

# 自检:必须含主文件,不能含测试与开发文件。
listing=$(unzip -Z1 "$out")
echo "$listing" | grep -qx 'wp-mcp/wp-mcp.php' || { echo "安装包缺少 wp-mcp.php" >&2; exit 1; }
if echo "$listing" | grep -Eq '^wp-mcp/(tests|bin|\.github|docs)/'; then
  echo "安装包包含不应发布的目录" >&2; exit 1
fi
echo "$out"
