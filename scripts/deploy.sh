#!/usr/bin/env bash
# The one sanctioned way to ship a change to the server.
#
# `docker compose up -d --build` alone is not enough: the application code is
# bind-mounted, so rebuilding the image often leaves its digest unchanged,
# compose then declines to recreate the container, and the running PHP keeps
# whatever it had. Restarting is what actually picks the new code up. The
# health check at the end exists because that failure is silent from the CLI —
# maintenance scripts keep working while every web request returns 500.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Pulling"
git pull --ff-only

echo "==> Building"
docker compose up -d --build

echo "==> Restarting MediaWiki so it picks up the new code"
docker compose restart mediawiki

echo "==> Applying schema changes"
docker compose exec -T mediawiki php maintenance/run.php update --quick

echo "==> Health check"
domain=$(grep '^WIKI_DOMAIN=' .env | cut -d= -f2-)
for attempt in $(seq 1 10); do
	code=$(curl -sS -o /dev/null -w '%{http_code}' "https://$domain/" || echo 000)
	if [ "$code" = "200" ]; then
		echo "    https://$domain/ -> 200"
		echo
		echo "Deployed. HEAD is now $(git rev-parse --short HEAD)."
		exit 0
	fi
	echo "    attempt $attempt: HTTP $code"
	sleep 3
done

echo
echo "DEPLOY FAILED: the site is not returning 200." >&2
echo "Recent MediaWiki logs:" >&2
docker compose logs --tail=40 mediawiki >&2
exit 1
