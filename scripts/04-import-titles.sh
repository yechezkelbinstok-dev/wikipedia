#!/usr/bin/env bash
# Load the upstream title index. This is what makes links blue.
#
# Downloads Wikimedia's titles dump into the `dumps` volume, then bulk-loads it.
# Expect the download to take a few minutes and the load 15-40 minutes; both are
# resumable, and re-running skips work already done.
set -euo pipefail
cd "$(dirname "$0")/.."

DUMP_URL="https://dumps.wikimedia.org/enwiki/latest/enwiki-latest-all-titles.gz"
DUMP_FILE="/dumps/enwiki-latest-all-titles.gz"

echo "==> Applying database schema for WikiClone"
docker compose exec -T mediawiki php maintenance/run.php update --quick

echo "==> Fetching the titles dump (skipped if already present)"
docker compose exec -T mediawiki bash -c "
	set -e
	if [ -s '$DUMP_FILE' ]; then
		echo '    already downloaded'
	else
		curl -fL --retry 3 --retry-delay 5 -o '$DUMP_FILE' '$DUMP_URL'
	fi
"

echo "==> Loading titles (main, Wikipedia, Template, Help, Category, Portal, Module)"
docker compose exec -T mediawiki \
	php extensions/WikiClone/maintenance/importTitles.php \
		--file="$DUMP_FILE" \
		--namespaces=0,4,10,12,14,100,828

echo
echo "Title index loaded. Links to real Wikipedia articles will now render blue,"
echo "and clicking one imports the article."
