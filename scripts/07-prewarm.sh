#!/usr/bin/env bash
# Pre-warm a topic, so clicking into it is instant rather than slow.
#
# A cold article costs seconds: its whole template tree has to be fetched and
# every page saved. A warm one is served from the parser cache in about a sixth
# of a second. Pre-warming pays the first cost in advance, in the background,
# for articles you know you will read.
#
#   ./scripts/07-prewarm.sh --category "Birds of prey"
#   ./scripts/07-prewarm.sh --file my-titles.txt
#   ./scripts/07-prewarm.sh "Peregrine falcon" "Golden eagle"
#
# Run it under tmux for anything large; it is deliberately unhurried, because
# the upstream API is a shared resource.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ $# -eq 0 ]; then
	sed -n '3,12p' "$0" | sed 's/^# \?//'
	exit 1
fi

args=()
for arg in "$@"; do
	# A --file path is given relative to the repository; the container sees it
	# under the extension directory.
	case "$arg" in
		--file=*) args+=( "--file=extensions/WikiClone/data/${arg#--file=}" ) ;;
		*) args+=( "$arg" ) ;;
	esac
done

docker compose exec -T mediawiki \
	php extensions/WikiClone/maintenance/importPages.php --warm "${args[@]}"
