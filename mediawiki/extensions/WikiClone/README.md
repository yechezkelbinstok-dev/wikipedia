# WikiClone

Populates the wiki on demand from Wikipedia instead of from a full dump.

## Why a title index

MediaWiki colours a link red when the target page does not exist. On a wiki
that fetches articles lazily, that means *every* article link is red until
someone clicks it — the single most obvious sign you are not on Wikipedia.

`wikiclone_title` holds every title that exists upstream: roughly 18 million
rows of `(namespace, title)`, loaded once from Wikimedia's titles dump. It is
not the `page` table, and the rows are not pages, so the cost is a few GB
rather than 18 million revisions.

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
