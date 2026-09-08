#!/usr/bin/env bash
# Create the first account and make it a sysop + bureaucrat.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ $# -lt 2 ]; then
	echo "usage: $0 <username> <password>" >&2
	exit 1
fi

docker compose exec -T mediawiki \
	php maintenance/run.php createAndPromote --sysop --bureaucrat --force "$1" "$2"

echo "Done. Log in at https://$(grep '^WIKI_DOMAIN=' .env | cut -d= -f2)/wiki/Special:UserLogin"
