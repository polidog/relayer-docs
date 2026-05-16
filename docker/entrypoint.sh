#!/bin/sh
set -e

# Cloud Run sets $PORT (default 8080). nginx config can't read env
# vars, so substitute the placeholder at start-up.
: "${PORT:=8080}"
sed -i "s/__PORT__/${PORT}/g" /etc/nginx/nginx.conf

# nginx temp dirs live under /tmp (the only writable path on Cloud Run).
mkdir -p /tmp/nginx-client /tmp/nginx-proxy /tmp/nginx-fastcgi \
         /tmp/nginx-scgi /tmp/nginx-uwsgi

# php-fpm in the background (no pid file — avoids writing to the
# read-only image FS), nginx in the foreground as PID 1.
php-fpm --nodaemonize &
exec nginx -g 'daemon off;'
