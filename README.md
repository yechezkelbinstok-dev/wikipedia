# Wikipedia clone

A private MediaWiki instance that looks and behaves like Wikipedia, populated
on demand from Wikipedia's API rather than from a full dump.

## Design

| Concern | Approach |
| --- | --- |
| Look and feel | Real MediaWiki with Vector 2022 (desktop) and MobileFrontend + Minerva (mobile) — the same software Wikipedia runs, so it matches by construction |
| Titles | 22.7M upstream titles pre-loaded — 19.2M articles and redirects, plus templates, categories and modules — so internal links are blue rather than a sea of red |
| Articles | Fetched from the Wikipedia API on first view, then cached locally |
| Images | Never stored — `$wgUseInstantCommons` resolves them from Commons on demand |
| Sync | Compare `lastrevid` in batches of 50 titles; re-fetch only what changed |
| Purge | Evict articles untouched past a TTL. Never touches local edits |
| Editing | Native MediaWiki editing, with local edits on their own branch of a page's history |

## Layout

```
docker-compose.yml     db (MariaDB) + mediawiki + caddy
Dockerfile             MediaWiki image plus MobileFrontend, Minerva, TemplateStyles, Popups
caddy/Caddyfile        TLS termination, automatic Let's Encrypt certificate
mariadb/wiki.cnf       InnoDB tuning for a 4 GB box
mediawiki/             LocalSettings.php, PHP and Apache overrides
scripts/               setup and maintenance
```

Secrets live in `.env` (gitignored), injected as container environment
variables. `LocalSettings.php` reads them with `getenv()` and contains no
credentials, so it is safe to commit.

## Setup

On a fresh Ubuntu 24.04 server:

```bash
git clone https://github.com/yechezkelbinstok-dev/wikipedia.git
cd wikipedia
git checkout claude/wikipedia-clone-feasibility-r4w53t

./scripts/01-server-prep.sh        # swap + Docker
exit                              # log back in so the docker group applies

cd wikipedia
./scripts/02-bootstrap.sh mywiki.duckdns.org
./scripts/03-create-admin.sh YourName 'a-strong-password'
./scripts/04-import-titles.sh     # ~30-60 min, mostly unattended
./scripts/05-install-cron.sh      # job queue, weekly sync, weekly purge
./scripts/06-import-interface.sh  # Wikipedia's CSS, tab labels, sidebar
./scripts/07-prewarm.sh --category "Birds of prey"   # optional, makes clicks instant
```

## Deploying a change

```bash
./scripts/deploy.sh
```

Always use this rather than `git pull && docker compose up -d --build`. The
code is bind-mounted, so a rebuild frequently leaves the image digest
unchanged; compose then declines to recreate the container and the running PHP
keeps serving what it already had. `deploy.sh` restarts the container and then
verifies the site actually returns 200 — the failure mode is silent from the
CLI, because maintenance scripts keep working while every web request 500s.

DNS for the domain must already point at the server — Caddy issues the
certificate over HTTP-01 on first request.

## Access control

The wiki is private by default: reading requires an account, and account
creation is disabled. To make it world-readable, set
`$wgGroupPermissions['*']['read'] = true;` in `mediawiki/LocalSettings.php`.

## Status

- [x] Server, Docker, TLS, MediaWiki with Wikipedia's skins
- [x] Title index import
- [x] On-demand article fetch
- [x] Sync and purge
- [x] Search over the title index
- [ ] Main Page daily refresh
- [ ] Branched page history
- [ ] Wikidata-backed infobox values
