#!/usr/bin/env bash
# BrainPress 本地开发服务器（PHP 内置服务器 + nginx 规则模拟路由）
# 用法:
#   ./start.sh          启动/重启（默认端口 8080）
#   ./start.sh 9090     指定端口启动
#   ./start.sh stop     停止服务器
set -e
DIR="$(cd "$(dirname "$0")" && pwd)"
PORT="${1:-8080}"

if [ "$1" = "stop" ]; then
    pkill -f "php -S 127.0.0.1:.*$DIR/router.php" 2>/dev/null || pkill -f "php -S 127.0.0.1:8080" 2>/dev/null || true
    echo "已停止"
    exit 0
fi

# 停掉旧实例，避免端口占用
pkill -f "php -S 127.0.0.1:$PORT" 2>/dev/null || true
sleep 1

setsid nohup php -S "127.0.0.1:$PORT" -t "$DIR" "$DIR/router.php" </dev/null >"$DIR/.server.log" 2>&1 &
sleep 1

if curl -sf -o /dev/null "http://127.0.0.1:$PORT/"; then
    echo "✅ BrainPress 已启动: http://127.0.0.1:$PORT"
else
    echo "❌ 启动失败，查看日志:"
    tail -20 "$DIR/.server.log"
fi
