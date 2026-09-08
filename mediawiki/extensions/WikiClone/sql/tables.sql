-- Every valid upstream title. Populated in bulk from the enwiki titles dump.
-- Rows here are not MediaWiki pages: they exist so that a link to a title we
-- have not fetched yet still renders blue, and so the fetcher knows a title is
-- real before it spends a request on it.
CREATE TABLE /*_*/wikiclone_title (
	wct_namespace INT NOT NULL,
	wct_title VARBINARY(255) NOT NULL,
	wct_is_redirect TINYINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (wct_namespace, wct_title)
) /*$wgDBTableOptions*/;

-- Provenance for pages we imported: what upstream revision they came from,
-- when they were fetched, and when they were last looked at (for purging).
CREATE TABLE /*_*/wikiclone_page (
	wcp_page INT UNSIGNED NOT NULL,
	wcp_remote_revid INT UNSIGNED NOT NULL DEFAULT 0,
	wcp_fetched BINARY(14) NOT NULL,
	wcp_accessed BINARY(14) NOT NULL,
	-- 0 = requested article, 1 = dependency pulled in to make one render
	wcp_kind TINYINT UNSIGNED NOT NULL DEFAULT 0,
	PRIMARY KEY (wcp_page)
) /*$wgDBTableOptions*/;

CREATE INDEX /*i*/wcp_kind_accessed ON /*_*/wikiclone_page (wcp_kind, wcp_accessed);
