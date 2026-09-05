#!/usr/bin/env bash
# BrainPress 镜像构建 / 推送脚本（本地维护，无 CI）
#
# 单一源码：仓库根 = 网页版 + Docker 版共用同一份代码，无副本。
# 构建上下文 = 仓库根（.dockerignore 挡运行时数据）。
#
# 用法：
#   ./build.sh                 # 构建 latest
#   ./build.sh 1.0.0           # 构建 1.0.0（额外打一个版本 tag）
#   ./build.sh 1.0.0 push      # 构建并推送两个 tag（需要已 docker login）
set -euo pipefail
cd "$(dirname "$0")"

REGISTRY="crpi-k60hf4g69i7wfk22.cn-hongkong.personal.cr.aliyuncs.com"
REPO="dasiwocom/brainpress"
VERSION="${1:-latest}"
ACTION="${2:-}"

[ "${VERSION}" = "latest" ] || echo "› 版本 tag: ${VERSION}"

# 构建：永远打 latest，版本号则是额外 tag
docker build -t "${REGISTRY}/${REPO}:latest" -t "${REGISTRY}/${REPO}:${VERSION}" -f Dockerfile .
echo "✓ 构建完成: ${REGISTRY}/${REPO}:${VERSION}"

# 推送（需先 docker login ${REGISTRY}）
if [ "${ACTION}" = "push" ]; then
    docker push "${REGISTRY}/${REPO}:latest"
    [ "${VERSION}" = "latest" ] || docker push "${REGISTRY}/${REPO}:${VERSION}"
    echo "✓ 已推送, 宝塔商店记得同步 app.json 的 appversion"
else
    echo "› 未推送。需要推送请先: docker login ${REGISTRY}"
    echo "  然后:  docker push ${REGISTRY}/${REPO}:latest ${REGISTRY}/${REPO}:${VERSION}"
fi