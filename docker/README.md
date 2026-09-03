# 📦 BrainPress · Docker 交付说明

本目录包含 BrainPress 的**两块独立交付物**：

| 目录 | 作用 | 谁在用 |
|------|------|--------|
| `apps/brainpress/` | 宝塔 Docker 应用商店提交包（严格按 `aaPanel/appstore` 规范，仅「拉镜像+运行参数」） | 宝塔面板 |
| `image/` | **镜像构建源**（Dockerfile + 运行配置 + 代码副本 + 示例笔记库），本地维护，产出推送到阿里云 ACR | 维护者 |

> ⚠️ 宝塔商店包只是「下载方式」，**不含源码**。发布新版本时须先在本机用 `image/build.sh` 构建镜像并推送到公开仓库，宝塔面板才能拉到。

## 目录结构

```
docker/
├── README.md
├── apps/brainpress/            # ── 宝塔应用商店提交包 ──
│   ├── app.json                #   元信息（apptype=Tools、field↔env 一一对应）
│   ├── ico-dkapp_brainpress.png#   100×100 图标
│   └── brainpress/
│       ├── docker-compose.yml  #   BT_SERVICE_NAME6 + ${HOST_IP}:${PORT}:80
│       │                       #   + ${APP_PATH} 挂载 + createdBy:bt_apps + baota_net
│       └── .env                #   VERSION/WEB_HTTP_PORT/HOST_IP/CPUS/MEMORY_LIMIT/APP_PATH
└── image/                      # ── 镜像构建源（自维护）──
    ├── Dockerfile              #   php:8.3-apache 基础镜像
    ├── apache-site.conf        #   生产 nginx 伪静态的同款 Apache 规则（含 /graph 等最新路由）
    ├── php.ini                 #   上传 512M（WebDAV 大 PDF）
    ├── config.template.json    #   默认配置（含 font_preset/pin_navbar）
    ├── entrypoint.sh           #   首次启动播种示例库 + 生成配置
    ├── vault-seed/             #   示例笔记库（首次启动自动播种）
    ├── website/                #   website 最新代码副本（sync-website.sh 同步）
    ├── sync-website.sh         #   website → 代码副本（排除 vault/config/日志）
    ├── build.sh                #   构建 + 打 tag + 推送 ACR 一键脚本
    └── .dockerignore
```

## 发布新版本（维护者流程）

```bash
cd docker/image
./build.sh                # 构建 latest
./build.sh 1.0.0          # 额外打版本 tag 1.0.0
# 推送（需先 `docker login crpi-...` ）：
./build.sh 1.0.0 push
```

推送后如该版本要在商店可选，需同步更新 `apps/brainpress/app.json` 的 `appversion`。

## 宝塔安装（应用商店，未上架时装自定义源）

1. 将 `apps/brainpress/` 放入 `aaPanel/appstore`（fork 后 PR，或面板自定义下载源指向你的分支）
2. 宝塔 → 软件商店 → Docker → 应用商店 → BrainPress → 安装
3. 表单填端口（默认 `8080`）；数据落 `/www/dk_project/dk_app/brainpress/data/`（`vault/` 笔记库 + `config/` 配置）
4. 访问 `http://IP:8080`，后台 `/admin` 首次设置密码

## 存档

- 示例库种子来源：`../网站源码/website/vault`（仅官方示例文档，不含运行数据）
- 代码副本来源：`../网站源码/website`（排除 `vault/`、`config.json`、日志）