#!/bin/sh
# BrainPress 容器首次启动初始化：
#   1. 准备持久化目录，没有配置文件就生成默认模板（之后在后台 /admin 里设置密码等）
#   2. 把配置目录与笔记目录交给 www-data（后台保存、WebDAV 同步、写 API 都需要写权限）
# 注：纯净版镜像不带任何内置笔记内容（无 seed 机制）。
#     容器内 /var/www/html/vault 首次启动建为空目录；若宿主机挂载了自己的目录则以其为准。
set -e

CONFIG_FILE="${BP_CONFIG_FILE:-/data/config.json}"
DATA_DIR="$(dirname "$CONFIG_FILE")"
VAULT_DIR="/var/www/html/vault"

mkdir -p "$DATA_DIR" "$VAULT_DIR"

if [ ! -f "$CONFIG_FILE" ]; then
    echo "BrainPress: 初始化配置文件 $CONFIG_FILE"
    cp /usr/local/share/brainpress/config.template.json "$CONFIG_FILE"
fi

chown -R www-data:www-data "$DATA_DIR"
chown -R www-data:www-data "$VAULT_DIR" || true

exec apache2-foreground