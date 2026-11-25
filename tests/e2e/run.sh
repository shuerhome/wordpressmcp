#!/usr/bin/env bash
# 运行端到端测试:启动 PHP 内置服务器 → 冒烟 → 0.4.0 安全 → 0.5.0 OAuth。
# 用法:tests/e2e/run.sh <工作目录>(先用 setup.sh 搭好)
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(cd "${1:?需要工作目录}" && pwd)"

php -S localhost:8899 -t "$WORK/site" "$HERE/router.php" > "$WORK/server.log" 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do
  curl -s --noproxy localhost -o /dev/null http://localhost:8899/ && break
  sleep 0.2
done

status=0
for t in test_040_security.py test_050_oauth.py smoke.py; do
  echo "== $t"
  python3 -I "$HERE/$t" "$WORK" || status=1
done

if grep -q "PHP Fatal" "$WORK/site/debug.log" 2>/dev/null; then
  echo "== 发现 PHP 致命错误:"; grep -A5 "PHP Fatal" "$WORK/site/debug.log"; status=1
fi
exit $status
