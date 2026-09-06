#!/bin/sh
# BrainPress 容器首次启动初始化：
#   1. 准备持久化目录，没有配置文件就生成默认模板（之后在后台 /admin 里设置密码等）
#   2. vault 种子逻辑：镜像内置示例库 /seed/vault。
#       ・无挂载卷 → /var/www/html/vault 直接用镜像种子（COPY 时已就位）
#       ・挂载空卷   → 自动把种子拷贝进卷，首次部署也能看到示例库（避免挂卷即空白）
#       ・挂载已有内容的目录 → 以宿主机目录为准不动
#   后两者只在「真为空」时拷贝，不覆盖用户数据。
#   3. 把配置目录与笔记目录交给 www-data（后台保存、WebDAV 同步、写 API 都需要写权限）
set -e

CONFIG_FILE="${BP_CONFIG_FILE:-/data/config.json}"
DATA_DIR="$(dirname "$CONFIG_FILE")"
VAULT_DIR="/var/www/html/vault"
SEED_DIR="/seed/vault"

mkdir -p "$DATA_DIR" "$VAULT_DIR"

if [ -d "$SEED_DIR" ] && [ -z "$(ls -A "$VAULT_DIR" 2>/dev/null)" ]; then
    echo "BrainPress: vault 为空，注入内置示例库（$SEED_DIR → $VAULT_DIR）"
    cp -a "$SEED_DIR"/. "$VAULT_DIR/"
fi

if [ ! -f "$CONFIG_FILE" ]; then
    echo "BrainPress: 初始化配置文件 $CONFIG_FILE"
    cp /usr/local/share/brainpress/config.template.json "$CONFIG_FILE"
fi

chown -R www-data:www-data "$DATA_DIR"
chown -R www-data:www-data "$VAULT_DIR" || true

exec apache2-foreground