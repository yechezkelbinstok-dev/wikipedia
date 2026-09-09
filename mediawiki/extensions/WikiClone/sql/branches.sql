-- A branch is a separate line of edit history over the same pages.
--
-- Branch 0 is "live" and has no row here: it is the wiki as Wikipedia has it,
-- moved forward by importing and syncing. Every other branch forks from one
-- of them and diverges only for the pages actually edited on it.
CREATE TABLE /*_*/wikiclone_branch (
	wcb_id INT UNSIGNED NOT NULL AUTO_INCREMENT,
	wcb_name VARBINARY(255) NOT NULL,
	-- The branch this one forked from; 0 for live.
	wcb_from INT UNSIGNED NOT NULL DEFAULT 0,
	wcb_user INT UNSIGNED NOT NULL DEFAULT 0,
	wcb_created BINARY(14) NOT NULL,
	PRIMARY KEY (wcb_id),
	UNIQUE KEY /*i*/wcb_name (wcb_name)
) /*$wgDBTableOptions*/;

-- The revisions making up one branch's history of one page, oldest first by
-- revision id. Rows appear only once a page has been edited on the branch;
-- until then the branch shows whatever its parent shows, which is what keeps
-- a branch from costing a copy of the whole wiki.
--
-- The line includes the revisions inherited at the moment of the first edit,
-- so a branch's history reads as one continuous story rather than starting
-- abruptly at the fork.
CREATE TABLE /*_*/wikiclone_branch_rev (
	wcbr_branch INT UNSIGNED NOT NULL,
	wcbr_page INT UNSIGNED NOT NULL,
	wcbr_rev INT UNSIGNED NOT NULL,
	PRIMARY KEY (wcbr_branch, wcbr_page, wcbr_rev)
) /*$wgDBTableOptions*/;

-- Where a revision was written. Only revisions written on a branch are listed,
-- so the live history of a page is simply its revisions that are absent here —
-- which is what keeps someone else's branch out of the live page's history.
CREATE TABLE /*_*/wikiclone_revision_branch (
	wcrb_rev INT UNSIGNED NOT NULL,
	wcrb_branch INT UNSIGNED NOT NULL,
	PRIMARY KEY (wcrb_rev)
) /*$wgDBTableOptions*/;
