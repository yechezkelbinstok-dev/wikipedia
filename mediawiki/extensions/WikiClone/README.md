# WikiClone

Populates the wiki on demand from Wikipedia instead of from a full dump.

## Why a title index

MediaWiki colours a link red when the target page does not exist. On a wiki
that fetches articles lazily, that means *every* article link is red until
someone clicks it — the single most obvious sign you are not on Wikipedia.

`wikiclone_title` holds every title that exists upstream. On English Wikipedia
that is 22.7M rows of `(namespace, title)`, loaded once from Wikimedia's titles
dump:

| Namespace | Rows |
| --- | --- |
| Main (articles and redirects) | 19,217,771 |
| Category | 2,635,515 |
| Template | 899,714 |
| Module | 19,439 |

It is not the `page` table, and the rows are not pages, so the cost is a few GB
rather than 22 million revisions.

Two hooks use it:

- **`HtmlPageLinkRendererBegin`** — a link to a title in the index renders as
  an ordinary blue link even though the page is not here yet.
- **`BeforeInitialize`** — viewing such a page imports it, synchronously, then
  lets the normal page view proceed.

## Why the whole transclusion tree

An article's wikitext is not self-contained. It transcludes templates, which
transclude more templates, which call Lua modules. Importing the article alone
renders a page of red `Template:Infobox` errors, so `ArticleImporter` pulls the
flattened transclusion list (`action=parse&prop=templates` resolves it in one
call) and imports whatever is missing first.

Those sets overlap heavily — the CS1 citation modules, `Infobox`, `Navbox`,
`Reflist`, `Convert` — so the first article in a topic is expensive and the
tenth is nearly free.

## Cost

| | Time |
| --- | --- |
| Cold article, cold templates | 10–30s |
| Cold article, warm templates | 3–8s |
| Warm article | parser cache, sub-second |

`WikiCloneMaxDependencies` caps how much one article may pull in, so a
pathological page cannot stall a request indefinitely.

## Tables

- `wikiclone_title` — upstream titles. Read by both hooks.
- `wikiclone_page` — provenance for what we imported: upstream revision id,
  when fetched, when last viewed, and whether it was requested directly or
  pulled in as a dependency. Sync and purge will both read this.

## Configuration

| Setting | Default |
| --- | --- |
| `$wgWikiCloneEnabled` | `true` |
| `$wgWikiCloneApiUrl` | `https://en.wikipedia.org/w/api.php` |
| `$wgWikiCloneUserAgent` | descriptive agent — Wikimedia's API policy requires one |
| `$wgWikiCloneImportNamespaces` | `[ NS_MAIN ]` |
| `$wgWikiCloneMaxDependencies` | `800` |

## Loading the index

```bash
./scripts/04-import-titles.sh
```

## Sync

```bash
docker compose exec mediawiki php extensions/WikiClone/maintenance/syncArticles.php --dry-run
```

Checking is cheap — 50 titles per API call returns each page's current
revision id — so only pages whose revision actually moved get their text
refetched. A wiki holding 5,000 articles costs roughly 100 calls to check.

## Purge

```bash
docker compose exec mediawiki php extensions/WikiClone/maintenance/purgeStale.php --dry-run
```

Deleting an imported article costs nothing: the title stays in the index, so
links to it are still blue and viewing it fetches it again. `--dependencies`
also drops templates and modules that nothing transcludes any more.

## What is never touched

Both sync and purge skip any page whose newest revision was not written by the
importer. Until branched history exists, syncing over a local edit would
destroy it and purging one would destroy it permanently, so neither is allowed
to happen. `LocalEditDetector` is the single place that decision is made.

## Recurring jobs

`scripts/05-install-cron.sh` installs three:

| When | What |
| --- | --- |
| every 5 min | drain the job queue — `$wgJobRunRate = 0`, so nothing else does |
| Sundays 04:23 | sync |
| Sundays 05:47 | purge, including orphaned dependencies |

## Importing on demand

```bash
docker compose exec mediawiki php extensions/WikiClone/maintenance/importPages.php "Albert Einstein"
docker compose exec mediawiki php extensions/WikiClone/maintenance/importPages.php --file=titles.txt
docker compose exec mediawiki php extensions/WikiClone/maintenance/importPages.php --force "Foobar"
```

Two uses: reproducing an import failure with the errors in front of you, and
pre-warming. Pre-warming is what makes the wiki feel fast — the first article
in a topic pulls its whole template tree, the tenth pulls almost nothing — so
running it ahead of time moves that cost off the first page view.

## When an import goes wrong

MediaWiki can refuse a save for reasons unrelated to the request, and a
dependency that fails to save takes the article's rendering with it. The
importer reports those instead of dropping them: failures are logged with the
title and the reason, and `import()` returns a status carrying them.

The case that motivated this: Wikipedia's stylesheets reference Commons with
protocol-relative URLs (`url(//upload.wikimedia.org/...)`), while
TemplateStyles' default allow-list is anchored on `https://`. The sanitiser
rejected those declarations, refused the save of
`Module:Citation/CS1/styles.css`, and every article with citations rendered
"has no content" errors instead of a reference list — with nothing in the logs.
`$wgTemplateStylesAllowedUrls` in `LocalSettings.php` now accepts both forms.

## Search

MediaWiki searches its own `page` and `searchindex` tables, which on a lazily
populated wiki hold almost nothing — so out of the box the search box finds
nothing and the only way to reach an article is to already be looking at a link
to it.

`SearchHooks` points search at the title index instead:

- **`PrefixSearchBackend`** fills the search box's autocomplete from all 22M
  upstream titles. Locally held pages come first, since those include anything
  you wrote, which is not in the upstream index at all.
- **`SearchGetNearMatch`** makes pressing Enter on an exact title go to the
  article and import it, rather than landing on an empty results page.

**Known limit:** full-text search over article *text* only covers articles
already imported, because there is no local text to search for the rest. Real
Wikipedia-grade full-text search would mean running CirrusSearch against
Elasticsearch, which wants 2–4 GB of its own.

## Matching Wikipedia's presentation

Three things a stock MediaWiki gets wrong that have nothing to do with article
content:

**Namespaces.** English Wikipedia defines several core does not — Portal,
Draft, TimedText. Templates reach into them through Scribunto, and
`mw.title.new( 'Portal:Foo' )` throws *"unrecognized namespace name"* when the
namespace is absent, so a navbox mentioning a portal renders as a red Lua
error where the box should be. `LocalSettings.php` declares them.

**Interface pages.** `MediaWiki:Common.css` carries a great deal of
Wikipedia's look, and messages like `MediaWiki:Nstab-main` are why its tabs
read "Article / Talk" rather than MediaWiki's default "Page / Discussion".
Absent, MediaWiki silently falls back to its own defaults:

```bash
./scripts/06-import-interface.sh
```

**Namespaces in the title index.** Articles link to `Wikipedia:` and `Help:`
pages from their maintenance templates. Indexing main space alone leaves those
red on an otherwise perfect article, so the index covers main, Wikipedia,
Template, Help, Category, Portal and Module.
