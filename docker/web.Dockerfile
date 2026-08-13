# Tender Platform — web-образ для прод.
# nginx со статикой public/ (минимум: index.php + собранные ассеты). PHP
# проксируется на php-fpm контейнер app:9000 (FastCGI).
FROM nginx:1.27-alpine

# Прод-конфиг nginx (без dev-обвязки). Монтируется также read-only из
# docker-compose.prod.yml (volume) — здесь копия для самодостаточности образа.
COPY docker/nginx/prod.conf /etc/nginx/conf.d/default.conf
COPY public/ /var/www/public
