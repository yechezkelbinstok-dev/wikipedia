<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\WikitextContent;
use MediaWiki\Extension\WikiClone\BranchStore;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Prove that a branch edit stays on its branch.
 *
 * The claim being checked is the one the whole feature rests on: after an edit
 * made on a branch, the branch shows the edit and live still shows what it
 * showed before, and each has a history of its own. Checking it by hand means
 * loading two pages and comparing them by eye, which is exactly the kind of
 * verification that quietly stops happening.
 *
 * It writes to a page of its own and deletes it afterwards.
 */
class BranchTest extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Check that branch edits stay on their branch.' );
		$this->addOption( 'keep', 'Leave the test page and branch behind for inspection' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$branches = $services->getService( 'WikiClone.BranchStore' );
		$wikiPageFactory = $services->getWikiPageFactory();

		$user = \MediaWiki\User\User::newSystemUser( 'WikiClone importer', [ 'steal' => true ] );
		$title = Title::makeTitle( NS_PROJECT, 'WikiClone branch self-test' );

		if ( $title->exists() ) {
			$this->deletePage( $title, $user );
		}

		$live = $this->edit( $wikiPageFactory, $title, $user, 'live text', 'live revision' );
		$this->output( "live revision:   $live\n" );

		$name = 'selftest-' . wfTimestampNow();
		$branchId = $branches->create( $name, BranchStore::LIVE, $user );
		$this->output( "branch:          $name (id $branchId)\n" );

		$branchRev = $this->edit( $wikiPageFactory, $title, $user, 'branch text', 'branch revision' );
		$branches->recordRevision( $branchId, $title->getArticleID(), $branchRev );
		$this->output( "branch revision: $branchRev\n\n" );

		$pageId = $title->getArticleID();
		$failures = 0;

		$failures += $this->check(
			'the branch shows the branch revision',
			$branches->tipFor( $branchId, $pageId ), $branchRev
		);
		$failures += $this->check(
			'live still shows the live revision',
			$branches->tipFor( BranchStore::LIVE, $pageId ), $live
		);
		$failures += $this->check(
			"live's history leaves the branch revision out",
			implode( ',', $branches->liveLineFor( $pageId ) ), (string)$live
		);
		$failures += $this->check(
			"the branch's history carries both",
			implode( ',', $branches->lineFor( $branchId, $pageId ) ), "$live,$branchRev"
		);
		$failures += $this->check(
			'the branch revision knows which branch it is on',
			$branches->branchOfRevision( $branchRev ), $branchId
		);
		$failures += $this->check(
			'the live revision is not on a branch',
			$branches->branchOfRevision( $live ), BranchStore::LIVE
		);

		if ( !$this->hasOption( 'keep' ) ) {
			$this->deletePage( $title, $user );
			$failures += $this->check(
				'deleting the page forgets its branch rows',
				$branches->hasBranchRevisions( $pageId ) ? 'yes' : 'no', 'no'
			);
		}

		$this->output( "\n" );
		if ( $failures ) {
			$this->fatalError( "$failures checks failed." );
		}
		$this->output( "All checks passed.\n" );
	}

	private function edit( $wikiPageFactory, Title $title, $user, string $text, string $summary ): int {
		$updater = $wikiPageFactory->newFromTitle( $title )->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, new WikitextContent( $text ) );
		$revision = $updater->saveRevision(
			CommentStoreComment::newUnsavedComment( $summary ),
			EDIT_SUPPRESS_RC
		);

		$status = $updater->getStatus();
		if ( !$revision || !$status || !$status->isOK() ) {
			$this->fatalError( 'Could not write the test page: ' . (
				$status ? \MediaWiki\Status\Status::wrap( $status )->getWikiText( false, false, 'en' ) : 'no status'
			) );
		}

		return $revision->getId();
	}

	private function deletePage( Title $title, $user ): void {
		$services = MediaWikiServices::getInstance();
		$services->getDeletePageFactory()
			->newDeletePage( $services->getWikiPageFactory()->newFromTitle( $title ), $user )
			->deleteUnsafe( 'branch self-test cleanup' );
	}

	private function check( string $what, $actual, $expected ): int {
		if ( (string)$actual === (string)$expected ) {
			$this->output( "  ok    $what\n" );
			return 0;
		}

		$this->output( "  FAIL  $what (expected $expected, got $actual)\n" );
		return 1;
	}
}

$maintClass = BranchTest::class;
require_once RUN_MAINTENANCE_IF_MAIN;
