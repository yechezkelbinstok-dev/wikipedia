<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;
use RefreshLinksJob;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Re-render the pages a Lua error was cached on.
 *
 * Rendered pages are kept for months, so fixing a module or mw.wikibase does
 * not reach a page that already failed: it goes on serving the red error until
 * the cache expires. Scribunto files every such page under its tracking
 * category, which makes them findable without throwing the whole cache away —
 * and a parse here costs tens of seconds, so the whole cache is a lot.
 */
class PurgeScriptErrors extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Purge the cached render of every page that had a Lua error.' );
		$this->addOption( 'dry-run', 'List the pages without purging them' );
		$this->requireExtension( 'WikiClone' );
		$this->requireExtension( 'Scribunto' );
	}

	public function execute() {
		$category = Title::makeTitleSafe(
			NS_CATEGORY,
			wfMessage( 'scribunto-common-error-category' )->inContentLanguage()->text()
		);

		$dbr = $this->getDB( DB_REPLICA );
		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_id', 'page_namespace', 'page_title' ] )
			->from( 'categorylinks' )
			->join( 'page', null, 'page_id = cl_from' )
			->where( [ 'cl_to' => $category->getDBkey() ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->output( 'Pages in ' . $category->getPrefixedText() . ': ' . $rows->numRows() . "\n" );

		$services = MediaWikiServices::getInstance();
		$pages = $services->getWikiPageFactory();
		$jobs = $services->getJobQueueGroup();
		$dryRun = $this->hasOption( 'dry-run' );

		foreach ( $rows as $row ) {
			$title = Title::newFromRow( $row );
			$this->output( '  ' . $title->getPrefixedText() . "\n" );

			if ( $dryRun ) {
				continue;
			}

			// The purge is what readers see: the next view renders afresh. The
			// links refresh takes the page back out of the category once it
			// renders cleanly, so the next run does not purge it again.
			$pages->newFromTitle( $title )->doPurge();
			$jobs->lazyPush( new RefreshLinksJob( $title, [] ) );
		}

		$this->output( 'Done. ' . ( $dryRun ? 'Would purge' : 'Purged' ) . ' ' . $rows->numRows() . " pages.\n" );
	}
}

$maintClass = PurgeScriptErrors::class;
require_once RUN_MAINTENANCE_IF_MAIN;
