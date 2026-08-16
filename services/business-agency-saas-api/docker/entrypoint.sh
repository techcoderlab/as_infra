#!/bin/bash
###############################################################################
# entrypoint.sh — shared by business-agency-saas-api / both queue workers /
# scheduler (same image, only the trailing command differs).
#
#   1. Render php.ini / opcache.ini / www.conf from *.template via envsubst,
#      so tuning values set in infra/docker-compose.yml / infra/.env take
#      effect without rebuilding the image.
#   2. Wait briefly for Postgres and Redis to accept TCP connections —
#      belt-and-braces on top of `depends_on: condition: service_healthy`.
#   3. exec the real command (php-fpm, queue:work, or the scheduler loop).
#
# Deliberately NOT run here: `artisan migrate`, `config:cache`, `route:cache`.
# Those run exactly once per deploy, as an explicit step in
# .github/workflows/deploy.yml, BEFORE `docker compose up -d` recreates
# these containers — running them from every one of
# business-agency-saas-api / worker-default / worker-ai / scheduler on every
# boot would race four processes against the same migration lock.
###############################################################################
set -euo pipefail

render() {
  local template="$1" dest="$2"
  envsubst < "$template" > "$dest"
}

render /usr/local/etc/php-templates/99-app.ini.template     /usr/local/etc/php/conf.d/99-app.ini
render /usr/local/etc/php-templates/98-opcache.ini.template  /usr/local/etc/php/conf.d/98-opcache.ini
render /usr/local/etc/php-templates/www.conf.template          /usr/local/etc/php-fpm.d/www.conf

wait_for_tcp() {
  local host="$1" port="$2" label="$3" tries=0
  until (echo > "/dev/tcp/${host}/${port}") >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 30 ]; then
      echo "entrypoint: gave up waiting for ${label} (${host}:${port}) after 30s" >&2
      break
    fi
    sleep 1
  done
}

if [ -n "${DB_HOST:-}" ]; then
  wait_for_tcp "${DB_HOST}" "${DB_PORT:-5432}" "PostgreSQL"
fi
if [ -n "${REDIS_HOST:-}" ]; then
  wait_for_tcp "${REDIS_HOST}" "${REDIS_PORT:-6379}" "Redis"
fi

exec "$@"
