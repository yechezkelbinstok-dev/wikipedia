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

## Scribunto's own budget

Scribunto enforces a CPU limit separate from PHP's `max_execution_time`, and
its 7-second default is Wikimedia's — set for Wikimedia's hardware. On a shared
2-vCPU host a long article's module tree runs past it, the standalone Lua
interpreter is killed with `SIGXCPU`, and every module call after that point on
the page fails. One import came back with 538 Lua errors, all from a single
timeout. `$wgScribuntoEngineConf['luastandalone']['cpuLimit']` is raised to 60.

## {{SHORTDESC:}}

Wikipedia gets this magic word from its Wikidata client. Without a provider
MediaWiki does not recognise it, parses the whole thing as an ordinary
transclusion, and renders a red link to `Template:SHORTDESC:Some description`
near the top of a great many articles.

The standalone ShortDescription extension would also supply it, but it is
archived upstream and exists only as a lone `master` branch on Gerrit — too
much dependency for one red link when the behaviour is forty lines. WikiClone
registers the magic word itself and stores the value as a page property, the
way the real implementations do, so a skin or API consumer can use it later.

## Checking a render

```bash
docker compose exec mediawiki php extensions/WikiClone/maintenance/checkPage.php \
    --check-upstream "Barack Obama"
```

Reports parse errors, Lua failures, citation count and red links, rather than
asking someone to load the page and squint at it. A red link is not
automatically a defect — plenty of articles link to pages that do not exist on
Wikipedia either — so `--check-upstream` asks which of them are red there too,
and only the rest are worth investigating.

## Speed

Measured on the 2 vCPU host, after LuaSandbox and the Scribunto limits were
sorted out:

| | Cold render | Warm (parser cache) |
| --- | --- | --- |
| Internet Engineering Task Force (58 citations) | 4.4s | 0.13s |
| Barack Obama (2.9 MB, 497 citations) | 24.4s | 0.17s |

Cold cost tracks how much of the template tree is already present, which is
why warming a topic converges so sharply — importing and rendering three
articles from one category, in order:

| | New templates | Import | Render |
| --- | --- | --- | --- |
| Falcon | 8 | 4.4s | 1.1s |
| Barred forest falcon | 3 | 1.3s | 0.6s |
| Falcon adenovirus 1 | 11 | 1.2s | 0.3s |

The cold cost is paid once per article, ever. What makes browsing feel fast is
that the second view of anything is a sixth of a second, so the lever that
matters is **pre-warming** — paying that first cost in advance rather than
while someone is waiting:

```bash
./scripts/07-prewarm.sh --category "Birds of prey"
```

Within a cold import, fetching is not the cost: 110 dependencies took 0.7s to
fetch and 24s to save. That is why dependency saves skip the search index —
nobody full-text searches `Module:Citation/CS1`.

## Wikidata

Wikipedia's modules reach into Wikidata through `mw.wikibase`. Without a
provider that table is nil and every module touching it dies with *"attempt to
index field 'wikibase'"* — five of them on a single article, behind
official-website links, sister-project boxes and archive links.

`WikidataClient` fetches entities from wikidata.org on demand and caches them,
in the same spirit as the article importer: nothing is stored up front, and
what gets used sticks around. `WikibaseLibrary` exposes it to Lua under the
names and return shapes the real client uses, because the modules calling them
are Wikipedia's own and were written against it.

Only the surface those modules actually use is implemented. Anything else
returns nil or an empty table — which is exactly what the real client does for
an article with no Wikidata item, so a module takes its "no data" path rather
than failing.

## Why some links are still red

The title index comes from a dump, and a dump is a snapshot: an article created
after it was taken is not in it, so links to it render red even though it
exists. Two things address that — the cron refreshes the index monthly, and
`$wgWikiCloneFetchUnindexed` means that navigating directly to such a page
still asks upstream, so it loads even while the index is stale.

## Interwiki links

Prefixed links like `[[c:Barack Obama]]` or `[[s:Executive Order 13506]]` are
ordinary links to Commons and Wikisource on Wikipedia. Without the interwiki
table MediaWiki does not recognise the prefix at all and renders a red link to
a local page that will never exist. Language prefixes are the same story, which
is what leaves `[[ja:メタ構文変数]]` red.

```bash
docker compose exec mediawiki php extensions/WikiClone/maintenance/importInterwiki.php
```

## Testing search

The wiki requires an account to read, so an anonymous request to the search API
is refused before it reaches any of the extension's code — which makes `curl`
useless for telling a broken search from a correctly protected one:

```
{"error":"rest-read-denied","httpCode":403}
```

Run the same completion search the suggestion endpoint runs, without the
permission check in the way:

```bash
docker compose exec mediawiki php extensions/WikiClone/maintenance/searchTest.php "Barack Ob"
```
