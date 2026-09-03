# BrainPress 运行镜像 —— 构建源（自维护）
# 用法：./build.sh [版本号] [push]   或   docker build -t <镜像> .
#
# 说明：宝塔应用商店(vendor) 只负责「拉镜像」，不构建；本目录负责把
#       website 代码 + 运行配置 打成可发布的镜像。
#       （纯净版：镜像不带任何内置笔记/示例内容，首次启动即空白库，由用户添加。）

FROM php:8.3-apache

# 开启伪静态模块（路由全靠它）；顺手消除 Apache 的 ServerName 启动提示
RUN a2enmod rewrite \
 && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# 站点路由规则（已同步 website/vault/BrainPress/Deployment.md 的生产 nginx 伪静态）
COPY apache-site.conf /etc/apache2/sites-available/000-default.conf

# PHP 参数：放开上传体积（WebDAV 同步大 PDF 需要）；时区跟随 TZ 环境变量
COPY php.ini /usr/local/etc/php/conf.d/zz-brainpress.ini

# 首次启动初始化脚本 + 默认配置模板（放在 Web 目录之外，不可被访问）
COPY entrypoint.sh config.template.json /usr/local/share/brainpress/
RUN chmod +x /usr/local/share/brainpress/entrypoint.sh

# 应用本体 = website 目录（用 sync-website.sh 从仓库根 website/ 同步）。
# curl / mbstring 扩展官方镜像已内置，无需额外安装
COPY website/ /var/www/html

# 纯净版：镜像不再打包 vault/，容器内 /var/www/html/vault 由 entrypoint 建空目录，
# 用户挂载自己的数据目录或以 WebDAV 同步后自行填充。

# 源文件的私有权限（如 600）会被原样复制进镜像，统一放开为「全局可读、目录可进入」，
# 否则 Apache(www-data) 读不到代码和字体；并补上官方镜像缺失的 PHP 会话目录。
# vault 目录必须存在于镜像层里（即使内容为空）——这是"内容放这里"的入口结构，
# 用户在挂载自己的 vault 前能直接在 Web 根看到它，不会困惑内容放哪。
RUN mkdir -p /var/www/html/vault /var/lib/php/sessions \
 && touch /var/www/html/vault/.keep \
 && chown -R www-data:www-data /var/www/html/vault /var/lib/php/sessions \
 && chmod -R ug+w,a+rX /var/www/html

# 配置文件指向持久化目录 /data（由宝塔 ${APP_PATH}/data/config 挂载）
ENV BP_CONFIG_FILE=/data/config.json

EXPOSE 80
ENTRYPOINT ["/usr/local/share/brainpress/entrypoint.sh"]