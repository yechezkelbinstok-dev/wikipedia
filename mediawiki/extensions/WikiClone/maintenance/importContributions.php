<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\MediaWikiServices;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\User\User;
use Wikimedia\Rdbms\IDBAccessObject;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Bring an account's Wikipedia edit history here.
 *
 * Special:Contributions reads this wiki's revision table, so an account with
 * edits on Wikipedia and none here has an empty contributions page however
 * much it has written. This copies those edits in: each one becomes a real
 * revision of the same page, attributed to the same name, carrying its
 * original timestamp and edit summary.
 *
 * They go in as history, not as the current text. An edit made in 2019 is not
 * what the article says today, and inserting a revision without moving the
 * page's current pointer is how a wiki records that — the same thing MediaWiki
 * does when it imports an old revision of a page it already holds.
 */
class ImportContributions extends Maintenance {

	private const BATCH = 50;

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Copy an account's Wikipedia edit history into this wiki." );
		$this->addOption( 'user', 'Account name, the same on both wikis', true, true );
		$this->addOption( 'limit', 'Most edits to copy (default 1000)', false, true );
		$this->addOption( 'dry-run', 'Report what would be copied without writing' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$api = $services->getService( 'WikiClone.WikipediaApi' );
		$importer = $services->getService( 'WikiClone.ArticleImporter' );
		$revisionStore = $services->getRevisionStore();
		$dryRun = $this->hasOption( 'dry-run' );

		$name = $this->getOption( 'user' );
		$user = User::newFromName( $name );
		if ( !$user || !$user->isRegistered() ) {
			$this->fatalError(
				"There is no account called \"$name\" here. Contributions have to belong "
				. 'to a local account, so create it first.'
			);
		}

		$contributions = $api->getUserContributions(
			$name, (int)$this->getOption( 'limit', 1000 )
		);
		$this->output( 'Edits on Wikipedia: ' . count( $contributions ) . "\n" );

		if ( !$contributions ) {
			return;
		}

		$copied = 0;
		$skipped = 0;
		$missing = 0;

		foreach ( array_chunk( $contributions, self::BATCH ) as $batch ) {
			$revisions = $dryRun
				? []
				: $api->getRevisionsById( array_column( $batch, 'revid' ) );

			foreach ( $batch as $edit ) {
				$title = Title::newFromText( $edit['title'] );
				if ( !$title ) {
					$missing++;
					continue;
				}

				if ( $dryRun ) {
					$this->output( "  would copy {$edit['title']} ({$edit['timestamp']})\n" );
					$copied++;
					continue;
				}

				$revision = $revisions[$edit['revid']] ?? null;
				if ( !$revision ) {
					// Deleted, suppressed, or a page that has since gone.
					$this->output( "  no content for {$edit['title']} r{$edit['revid']}\n" );
					$missing++;
					continue;
				}

				// The page has to be here before a revision of it can be.
				if ( !$title->exists() ) {
					$importer->import( $title );

					// A Title remembers whether it existed the first time it
					// was asked, and it was asked before the import. Without
					// this the page is on disk and the Title still says it is
					// not, so every page fetched during the run is reported as
					// one that could not be fetched.
					$title->resetArticleID( false );

					if ( !$title->getArticleID( IDBAccessObject::READ_LATEST ) ) {
						$this->output( "  could not import {$edit['title']}\n" );
						$missing++;
						continue;
					}
				}

				if ( $this->alreadyHave( $title, $user, $revision['timestamp'] ) ) {
					$skipped++;
					continue;
				}

				$this->insert( $revisionStore, $title, $user, $revision );
				$this->output( "  copied {$edit['title']} ({$revision['timestamp']})\n" );
				$copied++;
				$this->waitForReplication();
			}
		}

		$this->output(
			"Copied $copied, $skipped already here, $missing unavailable.\n"
		);
	}

	/**
	 * An edit is identified by who made it, where, and when — the upstream
	 * revision id means nothing in this wiki's numbering, so it cannot be the
	 * key that makes a second run a no-op.
	 */
	private function alreadyHave( Title $title, User $user, string $timestamp ): bool {
		$db = $this->getDB( DB_REPLICA );

		return (bool)$db->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'revision' )
			->join( 'actor', null, 'actor_id = rev_actor' )
			->where( [
				'rev_page' => $title->getArticleID( IDBAccessObject::READ_LATEST ),
				'actor_name' => $user->getName(),
				'rev_timestamp' => $db->timestamp( $timestamp ),
			] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * @param array{title:string,text:string,timestamp:string,comment:string,user:string} $revision
	 */
	private function insert( $revisionStore, Title $title, User $user, array $revision ): void {
		$services = MediaWikiServices::getInstance();
		$handler = $services->getContentHandlerFactory()
			->getContentHandler( $title->getContentModel() );

		$record = new MutableRevisionRecord( $title );
		$record->setPageId( $title->getArticleID( IDBAccessObject::READ_LATEST ) );
		$record->setContent( SlotRecord::MAIN, $handler->unserializeContent( $revision['text'] ) );
		$record->setUser( $user );
		$record->setTimestamp( wfTimestamp( TS_MW, $revision['timestamp'] ) );
		$record->setComment(
			CommentStoreComment::newUnsavedComment( $revision['comment'] ?: '' )
		);
		$record->setMinorEdit( false );

		// No parent: this revision's place in the page's story is its
		// timestamp, and claiming a parent it never had would put a wrong
		// byte-count difference next to it in the history.
		$record->setParentId( 0 );

		$revisionStore->insertRevisionOn( $record, $this->getDB( DB_PRIMARY ) );
	}
}

$maintClass = ImportContributions::class;
require_once RUN_MAINTENANCE_IF_MAIN;
