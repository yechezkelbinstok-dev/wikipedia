<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\Extension\WikiClone\ArticleImporter;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Drop imported articles nobody has looked at in a while.
 *
 * Deleting one costs nothing: the title stays in the index, so links to it are
 * still blue and viewing it fetches it again. Two things are never purged —
 * pages carrying local edits, and templates still transcluded by something
 * that remains.
 */
class PurgeStale extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Delete imported articles untouched past the staleness window.' );
		$this->addOption( 'days', 'Override $wgWikiCloneStaleAfterDays', false, true );
		$this->addOption( 'limit', 'Maximum pages to delete in this run', false, true );
		$this->addOption( 'dependencies', 'Also drop templates and modules nothing transcludes any more' );
		$this->addOption( 'dry-run', 'Report what would be deleted without deleting' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$days = (int)$this->getOption(
			'days',
			$this->getConfig()->get( 'WikiCloneStaleAfterDays' )
		);
		$limit = (int)$this->getOption( 'limit', 500 );

		$deleted = $this->purgeArticles( $days, $limit );

		if ( $this->hasOption( 'dependencies' ) ) {
			$deleted += $this->purgeOrphanedDependencies( $days, $limit );
		}

		$this->output( "Done. " . ( $this->hasOption( 'dry-run' ) ? 'Would delete' : 'Deleted' ) . " $deleted pages.\n" );
	}

	private function purgeArticles( int $days, int $limit ): int {
		$dbr = $this->getDB( DB_REPLICA );
		$cutoff = $dbr->timestamp( (int)wfTimestamp( TS_UNIX ) - $days * 86400 );

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'wikiclone_page' )
			->join( 'page', null, 'page_id = wcp_page' )
			->where( [
				'wcp_kind' => 0,
				$dbr->expr( 'wcp_accessed', '<', $cutoff ),
			] )
			->orderBy( 'wcp_accessed' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->output( "Articles untouched for $days+ days: " . $rows->numRows() . "\n" );

		return $this->deleteAll( $rows, 'stale: not viewed in ' . $days . ' days' );
	}

	/**
	 * Templates and modules are only worth keeping while something transcludes
	 * them. Once the articles that pulled them in are gone, so are they.
	 */
	private function purgeOrphanedDependencies( int $days, int $limit ): int {
		$dbr = $this->getDB( DB_REPLICA );
		$cutoff = $dbr->timestamp( (int)wfTimestamp( TS_UNIX ) - $days * 86400 );

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'wikiclone_page' )
			->join( 'page', null, 'page_id = wcp_page' )
			->leftJoin( 'templatelinks', null, 'tl_target_id = lt_id' )
			->leftJoin( 'linktarget', null, [
				'lt_namespace = page_namespace',
				'lt_title = page_title',
			] )
			->where( [
				'wcp_kind' => 1,
				'tl_from' => null,
				$dbr->expr( 'wcp_accessed', '<', $cutoff ),
			] )
			->orderBy( 'wcp_accessed' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->output( "Orphaned dependencies: " . $rows->numRows() . "\n" );

		return $this->deleteAll( $rows, 'orphaned: nothing transcludes this any more' );
	}

	private function deleteAll( $rows, string $reason ): int {
		$services = MediaWikiServices::getInstance();
		$localEdits = $services->getService( 'WikiClone.LocalEditDetector' );
		$deletePageFactory = $services->getDeletePageFactory();
		$wikiPageFactory = $services->getWikiPageFactory();
		$user = \MediaWiki\User\User::newSystemUser(
			ArticleImporter::IMPORT_USER,
			[ 'steal' => true ]
		);

		$dryRun = $this->hasOption( 'dry-run' );
		$count = 0;

		foreach ( $rows as $row ) {
			$title = Title::makeTitle( $row->page_namespace, $row->page_title );

			if ( $localEdits->hasLocalEdits( $title ) ) {
				$this->output( "  keeping {$title->getPrefixedText()} (has local edits)\n" );
				continue;
			}

			if ( $dryRun ) {
				$this->output( "  would delete {$title->getPrefixedText()}\n" );
				$count++;
				continue;
			}

			$status = $deletePageFactory
				->newDeletePage( $wikiPageFactory->newFromTitle( $title ), $user )
				->deleteUnsafe( $reason );

			if ( $status->isOK() ) {
				$this->output( "  deleted {$title->getPrefixedText()}\n" );
				$count++;
			} else {
				$this->output( "  FAILED {$title->getPrefixedText()}\n" );
			}

			$this->waitForReplication();
		}

		return $count;
	}
}

$maintClass = PurgeStale::class;
require_once RUN_MAINTENANCE_IF_MAIN;
