#!/usr/bin/env bash
# Install the recurring jobs. Re-running replaces the previous set.
set -euo pipefail

REPO="$(cd "$(dirname "$0")/.." && pwd)"
LOGS="$HOME/wiki-logs"
mkdir -p "$LOGS"

MARKER="# --- wikiclone ---"
MW="cd $REPO && docker compose exec -T mediawiki php"

# LocalSettings sets $wgJobRunRate = 0 so a page view never also runs a job;
# something has to drain the queue instead, or link tables and category
# membership drift out of date.
#
# The title index is a snapshot of a dump, so articles created since it was
# taken are missing from it and their links render red. Monthly is roughly the
# cadence at which Wikimedia publishes a new one.
NEW=$(cat <<CRON
$MARKER
*/5 * * * * $MW maintenance/run.php runJobs --maxjobs 200 >> $LOGS/jobs.log 2>&1
23 4 * * 0 $MW extensions/WikiClone/maintenance/syncArticles.php >> $LOGS/sync.log 2>&1
47 5 * * 0 $MW extensions/WikiClone/maintenance/purgeStale.php --dependencies >> $LOGS/purge.log 2>&1
13 3 4 * * cd $REPO && ./scripts/04-import-titles.sh >> $LOGS/titles.log 2>&1
$MARKER
CRON
)

# Drop any previous block, then append the current one.
# `crontab -l` exits 1 when the user has no crontab at all, which under
# `set -o pipefail` would abort before the new block was ever emitted — and
# pipe an empty crontab into place.
EXISTING=$(crontab -l 2>/dev/null || true)
printf '%s\n%s\n' "$(printf '%s' "$EXISTING" | sed "/$MARKER/,/$MARKER/d")" "$NEW" | crontab -

echo "Installed:"
crontab -l | sed -n "/$MARKER/,/$MARKER/p"
echo
echo "Logs in $LOGS/"
