#!/usr/bin/env bash
# 把仓库根 ../website 的【代码】同步进本 image/website 副本。
#
# 铁律：image 与 website 是两个独立交付物，绝不共享同一份文件；
#       更新只靠这个脚本复制。笔记(vault)、config.json、日志属于运行数据，
#       永远不会被同步进来（对镜像改不动种子库，防泄漏密钥）。
#       纯净版：镜像不带任何示例内容，vault 仅存在于仓库 website/ 供开发使用，
#       不会被烧进镜像。
set -e
cd "$(dirname "$0")"
SRC="${BP_WEBSITE_SRC:-../../website}"   # 允许覆盖源路径（如 CI 里指向源码目录）

if command -v rsync >/dev/null 2>&1; then
  rsync -a --delete \
    --exclude 'vault/' \
    --exclude 'config.json' \
    --exclude '.server.log' \
    --exclude '*.log' \
    "$SRC/" website/
else
  # 无 rsync 的机器：tar 镜像复制（同样排除运行数据）
  rm -rf website && mkdir website
  tar -C "$SRC" \
      --exclude='./vault' \
      --exclude='./config.json' \
      --exclude='./.server.log' \
      --exclude='*.log' \
      -cf - . | tar -C website -xf -
fi

echo "✓ website 代码已同步到 image/website/（vault、运行数据未混入；镜像为纯净版）"