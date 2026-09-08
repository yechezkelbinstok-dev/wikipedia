#!/usr/bin/env bash
# Import the interface pages that carry Wikipedia's presentation.
#
# Without these MediaWiki falls back to its own defaults, which is why a fresh
# install labels the tabs "Page / Discussion" instead of "Article / Talk" and
# is missing MediaWiki:Common.css entirely — a great deal of Wikipedia's look
# lives in that stylesheet rather than in the skin.
set -euo pipefail
cd "$(dirname "$0")/.."

echo "==> Importing interface pages"
docker compose exec -T mediawiki php extensions/WikiClone/maintenance/importPages.php \
	--force --file=extensions/WikiClone/data/interface-pages.txt

echo
echo "==> Clearing the message cache"
docker compose exec -T mediawiki php maintenance/run.php rebuildLocalisationCache --force || true
docker compose restart mediawiki

echo
echo "Done. A page or two may report PROBLEM if it does not exist upstream;"
echo "that is expected and harmless."
