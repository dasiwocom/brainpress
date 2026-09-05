# BrainPress 运行镜像 —— 单一源码构建
# 用法：./build.sh [版本号] [push]   或   docker build -f Dockerfile .
#
# 说明：仓库根就是唯一源码（网页版 = Docker 版同一份代码）。
#       构建上下文指向仓库根，.dockerignore 负责挡掉 config.json/cache/日志。
#       vault/ 随源码一并打进镜像作为种子库；容器可写层/挂载卷保存用户改动。

FROM php:8.3-apache

# 开启伪静态模块（路由全靠它）；顺手消除 Apache 的 ServerName 启动提示
RUN a2enmod rewrite \
 && printf 'ServerName localhost\n' > /etc/apache2/conf-available/servername.conf \
 && a2enconf servername

# 站点路由规则（已同步 vault/BrainPress/Deployment.md 的生产 nginx 伪静态）
COPY apache-site.conf /etc/apache2/sites-available/000-default.conf

# PHP 参数：放开上传体积（WebDAV 同步大 PDF 需要）；时区跟随 TZ 环境变量
COPY php.ini /usr/local/etc/php/conf.d/zz-brainpress.ini

# 首次启动初始化脚本 + 默认配置模板（放在 Web 目录之外，不可被访问）
COPY entrypoint.sh config.template.json /usr/local/share/brainpress/
RUN chmod +x /usr/local/share/brainpress/entrypoint.sh

# 应用本体 = 仓库根全部源码（含 vault/）。curl/mbstring 官方镜像已内置
COPY . /var/www/html

# 安全布局：Web 根 root 所有、代码只读（php:8.3-apache 基础镜像默认把
# /var/www/html 设为 www-data 可写 1777，这里收掉）。
# 运行时需要写的只有四处，单独建成 www-data 属主：
#   cache/           目录树扫描缓存（BP_CACHE_DIR）
#   ima_cache.json   ima 索引缓存（IMA_CACHE_FILE）
#   vault/           笔记库（可写层/挂载卷保存用户改动）
#   /var/lib/php/sessions  PHP 会话目录
RUN rm -f /var/www/html/Dockerfile /var/www/html/build.sh \
      /var/www/html/apache-site.conf /var/www/html/php.ini \
      /var/www/html/config.template.json /var/www/html/entrypoint.sh \
 && mkdir -p /var/www/html/cache /var/www/html/vault /var/lib/php/sessions \
 && touch /var/www/html/ima_cache.json \
 && chown -R root:root /var/www/html \
 && chown www-data:www-data /var/www/html/cache /var/www/html/vault \
      /var/www/html/ima_cache.json /var/lib/php/sessions \
 && chmod 755 /var/www/html \
 && chmod -R a+rX /var/www/html

# 配置文件指向持久化目录 /data（由宝塔 ${APP_PATH}/data/config 挂载）
ENV BP_CONFIG_FILE=/data/config.json

EXPOSE 80
ENTRYPOINT ["/usr/local/share/brainpress/entrypoint.sh"]