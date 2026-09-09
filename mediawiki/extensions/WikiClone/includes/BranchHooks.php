<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Config\Config;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Hook\BeforePageDisplayHook;
use MediaWiki\Hook\PageHistoryPager__getQueryInfoHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Hook\SkinTemplateNavigation__UniversalHook;
use MediaWiki\Storage\Hook\PageSaveCompleteHook;
use MediaWiki\SpecialPage\SpecialPage;

/**
 * Reading and writing a branch.
 *
 * The whole read path comes down to one substitution: a branch is a different
 * answer to "which revision of this page is the current one", so once that
 * answer is applied as an oldid, MediaWiki renders, diffs and links the branch
 * without knowing there is such a thing as a branch.
 *
 * Which branch is being read is remembered in a cookie, so an ordinary link
 * from one article to the next stays on the branch without every URL having to
 * carry it. Putting ?branch=name on any URL switches.
 */
class BranchHooks implements
	BeforeInitializeHook,
	BeforePageDisplayHook,
	PageDeleteCompleteHook,
	PageHistoryPager__getQueryInfoHook,
	PageSaveCompleteHook,
	SkinTemplateNavigation__UniversalHook
{

	private const COOKIE = 'wikicloneBranch';

	private Config $config;
	private BranchStore $branches;

	/** Branch being read this request. */
	private static int $active = BranchStore::LIVE;

	/** Set when the branch's revision is not the page's newest one. */
	private static ?int $pinned = null;

	public function __construct( Config $config, BranchStore $branches ) {
		$this->config = $config;
		$this->branches = $branches;
	}

	public static function activeBranch(): int {
		return self::$active;
	}

	/** @inheritDoc */
	public function onBeforeInitialize( $title, $unused, $output, $user, $request, $mediaWiki ) {
		self::$active = $this->resolveBranch( $request );
		self::$pinned = null;

		// Until somebody makes a branch there is nothing to resolve, and every
		// page view should pay nothing for a feature it is not using.
		if ( !$this->branches->listBranches() ) {
			return;
		}

		if ( !$title || !$title->canExist() || !$title->exists() ) {
			return;
		}

		// An explicit revision or diff is the reader asking for something
		// specific; do not overrule it.
		if ( $request->getCheck( 'oldid' ) || $request->getCheck( 'diff' ) ) {
			return;
		}

		// A save is deliberately not on this list. Saving is MediaWiki's own
		// business, and an oldid on the way in is how an ordinary edit turns
		// into a restore of an old revision; the branch claims the revision
		// afterwards instead, in onPageSaveComplete.
		$action = $request->getVal( 'action', 'view' );
		if ( !in_array( $action, [ 'view', 'edit', 'raw' ], true ) ) {
			return;
		}

		$tip = $this->branches->tipFor( self::$active, $title->getArticleID() );
		if ( $tip === null || $tip === $title->getLatestRevID() ) {
			return;
		}

		self::$pinned = $tip;
		$request->setVal( 'oldid', (string)$tip );
	}

	/**
	 * Reading an old revision normally announces itself, and here that would be
	 * wrong twice over: on live it is not an old revision at all — it is the
	 * current one, displaced by somebody's branch edit — and on a branch it is
	 * the branch's current revision. Either way the reader wants the page to
	 * look like the page.
	 *
	 * @inheritDoc
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		if ( self::$pinned === null ) {
			return;
		}

		if ( self::$active === BranchStore::LIVE ) {
			$out->setSubtitle( '' );
			return;
		}

		$out->setSubtitle( $out->msg(
			'wikiclone-branch-viewing',
			$this->branches->nameOf( self::$active )
		)->escaped() );
	}

	/**
	 * The branch menu, alongside the page tabs.
	 *
	 * @param \SkinTemplate $skin
	 * @param array &$links
	 */
	public function onSkinTemplateNavigation__Universal( $skin, &$links ): void {
		$title = $skin->getTitle();
		if ( !$title ) {
			return;
		}

		$items = [
			'wikiclone-branch-live' => $this->menuItem(
				$title, BranchStore::LIVE_NAME, self::$active === BranchStore::LIVE
			),
		];

		foreach ( $this->branches->listBranches() as $branch ) {
			$items['wikiclone-branch-' . $branch['id']] = $this->menuItem(
				$title, $branch['name'], self::$active === $branch['id']
			);
		}

		$items['wikiclone-branch-new'] = [
			'text' => $skin->msg( 'wikiclone-branch-create' )->text(),
			'href' => SpecialPage::getTitleFor( 'Branches' )->getLocalURL(),
		];

		$links['actions'] = array_merge( $links['actions'] ?? [], $items );
	}

	/**
	 * An edit made while reading a branch belongs to that branch. Imports and
	 * syncs never do: they run without a request, where the active branch is
	 * live by construction.
	 *
	 * @param \WikiPage $wikiPage
	 * @param \MediaWiki\User\UserIdentity $user
	 * @param string $summary
	 * @param int $flags
	 * @param \MediaWiki\Revision\RevisionRecord $revisionRecord
	 * @param \MediaWiki\Storage\EditResult $editResult
	 */
	public function onPageSaveComplete(
		$wikiPage, $user, $summary, $flags, $revisionRecord, $editResult
	) {
		if ( self::$active === BranchStore::LIVE ) {
			return;
		}

		$this->branches->recordRevision(
			self::$active,
			$wikiPage->getId(),
			$revisionRecord->getId()
		);
	}

	/**
	 * A deleted page takes its branch history with it: the revisions it points
	 * at are gone, so rows naming them would only be able to mislead.
	 *
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		$this->branches->forgetPage( $pageID );
	}

	/**
	 * A page's history is the branch's history of it, not every branch's at
	 * once.
	 *
	 * @param \HistoryPager $pager
	 * @param array &$queryInfo
	 */
	public function onPageHistoryPager__getQueryInfo( $pager, &$queryInfo ) {
		$pageId = $pager->getWikiPage()->getId();
		if ( !$pageId || !$this->branches->hasBranchRevisions( $pageId ) ) {
			return;
		}

		$line = $this->branches->lineFor( self::$active, $pageId );
		if ( !$line ) {
			return;
		}

		$db = $pager->getDatabase();
		$queryInfo['conds'][] = $db->expr( 'rev_id', '=', $line );
	}

	private function menuItem( $title, string $name, bool $selected ): array {
		return [
			'text' => $name,
			'href' => $title->getLocalURL( [ 'branch' => $name ] ),
			'class' => $selected ? 'selected' : '',
		];
	}

	private function resolveBranch( $request ): int {
		$asked = $request->getVal( 'branch' );

		if ( $asked !== null ) {
			$branch = $this->branchIdByName( $asked );
			if ( $branch !== null ) {
				$request->response()->setCookie(
					self::COOKIE,
					$branch === BranchStore::LIVE ? '' : $asked,
					$branch === BranchStore::LIVE ? time() - 3600 : 0
				);
				return $branch;
			}
		}

		$remembered = $request->getCookie( self::COOKIE );
		if ( $remembered ) {
			return $this->branchIdByName( $remembered ) ?? BranchStore::LIVE;
		}

		return BranchStore::LIVE;
	}

	private function branchIdByName( string $name ): ?int {
		if ( strcasecmp( $name, BranchStore::LIVE_NAME ) === 0 ) {
			return BranchStore::LIVE;
		}

		$branch = $this->branches->getByName( $name );
		return $branch ? $branch['id'] : null;
	}
}
