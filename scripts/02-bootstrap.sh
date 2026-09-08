#!/usr/bin/env bash
# Bring up the stack and install MediaWiki's schema.
# Safe to re-run: it will not overwrite an existing .env.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
	if [ $# -lt 1 ]; then
		echo "usage: $0 <your-subdomain.duckdns.org>" >&2
		exit 1
	fi
	echo "==> Generating .env"
	cat > .env <<ENV
WIKI_DOMAIN=$1
DB_NAME=wikidb
DB_USER=wikiuser
DB_PASSWORD=$(openssl rand -hex 24)
DB_ROOT_PASSWORD=$(openssl rand -hex 24)
MW_SECRET_KEY=$(openssl rand -hex 32)
MW_UPGRADE_KEY=$(openssl rand -hex 16)
ENV
	chmod 600 .env
else
	echo "==> .env already exists, keeping it"
fi

echo "==> Building MediaWiki image (first run pulls ~1 GB, be patient)"
docker compose build

echo "==> Starting database"
docker compose up -d db
echo "    waiting for MariaDB to report healthy..."
for i in $(seq 1 60); do
	status=$(docker inspect --format '{{.State.Health.Status}}' "$(docker compose ps -q db)" 2>/dev/null || echo starting)
	[ "$status" = "healthy" ] && break
	sleep 3
done
[ "${status:-}" = "healthy" ] || { echo "MariaDB did not become healthy; see: docker compose logs db"; exit 1; }
echo "    healthy"

echo "==> Starting MediaWiki and Caddy"
docker compose up -d

echo "==> Creating database schema (this takes a minute or two)"
docker compose exec -T mediawiki php maintenance/run.php update --quick

echo
echo "Schema installed. Next: scripts/03-create-admin.sh <username> <password>"
