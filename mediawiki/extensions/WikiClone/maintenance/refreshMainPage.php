<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Bring the Main Page up to today's, the way Wikipedia's own turns over.
 *
 * The Main Page is the one article that goes stale on a clock rather than
 * when somebody edits it, and it goes stale in two different ways.
 *
 * Some of what it shows lives at a dated title — "Wikipedia:Today's featured
 * article/September 9, 2026" — so at midnight UTC the Main Page starts
 * transcluding a page that has never existed here. Nothing on an existing
 * page's fetch path covers that, because the Main Page itself has not
 * changed; only what it points at has. Re-resolving its dependencies picks
 * the new subpages up.
 *
 * The rest — In the news, Did you know — keeps one title and is edited in
 * place, so those need the ordinary staleness check instead.
 *
 * Finally the render is dropped and rebuilt here rather than left for the
 * first reader after midnight, who would otherwise pay for it.
 */
class RefreshMainPage extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Refetch the Main Page and everything it shows today." );
		$this->addOption( 'title', 'Page to refresh (default: Main Page)', false, true );
		$this->addOption( 'dry-run', 'Report what would change without writing' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$api = $services->getService( 'WikiClone.WikipediaApi' );
		$importer = $services->getService( 'WikiClone.ArticleImporter' );
		$localEdits = $services->getService( 'WikiClone.LocalEditDetector' );
		$wikiPageFactory = $services->getWikiPageFactory();

		$title = Title::newFromText( $this->getOption( 'title', 'Main Page' ) );
		if ( !$title ) {
			$this->fatalError( 'Not a valid title.' );
		}

		$dryRun = $this->hasOption( 'dry-run' );

		// Today's subpages, and anything else the Main Page has grown since it
		// was last looked at. import() writes only what is missing, so on a day
		// when nothing new appeared this costs one API call and no saves.
		if ( $dryRun ) {
			$this->output( "Would import missing dependencies.\n" );
		} else {
			$start = microtime( true );
			$status = $importer->import( $title );
			$stats = $status->getValue();
			$this->output( sprintf(
				"Imported %s new dependencies in %ss.\n",
				is_array( $stats ) ? $stats['dependencies'] : '?',
				round( microtime( true ) - $start, 1 )
			) );
			if ( !$status->isGood() ) {
				$this->output( '  PROBLEM: '
					. Status::wrap( $status )->getWikiText( false, false, 'en' ) . "\n" );
			}
		}

		$this->output( 'Synced ' . $this->syncStale( $title, $api, $importer, $localEdits, $dryRun )
			. " pages edited upstream.\n" );

		if ( $dryRun ) {
			return;
		}

		// The Main Page's wikitext asks what day it is, so its cached render
		// belongs to yesterday even when every page behind it is current.
		$page = $wikiPageFactory->newFromTitle( $title );
		$page->doPurge();

		$start = microtime( true );
		$page->getParserOutput( $page->makeParserOptions( 'canonical' ) );
		$this->output( sprintf( "Rebuilt the render in %ss.\n", round( microtime( true ) - $start, 1 ) ) );
	}

	/**
	 * Refetch the page and its dependencies where upstream has moved on.
	 */
	private function syncStale(
		Title $title, $api, $importer, $localEdits, bool $dryRun
	): int {
		$held = [];
		foreach ( array_merge( [ $title->getPrefixedText() ], $api->getDependencies( $title->getPrefixedText() ) ) as $text ) {
			$dependency = Title::newFromText( $text );
			if ( $dependency && $dependency->exists() ) {
				$held[$dependency->getPrefixedText()] = $dependency;
			}
		}

		if ( !$held ) {
			return 0;
		}

		$known = $this->knownRevisionIds( $held );
		$changed = 0;

		foreach ( array_chunk( array_keys( $held ), 50 ) as $chunk ) {
			$stale = [];
			foreach ( $api->getLastRevisionIds( $chunk ) as $prefixedTitle => $remoteRevId ) {
				// A page with no import record is not a page to leave alone —
				// it is a page we have never fetched. The Main Page is exactly
				// that: MediaWiki creates it at install time, so it existed
				// before anything here could import it, and "skip what we do
				// not have a record of" left the wiki's front page reading
				// "MediaWiki has been installed."
				if ( isset( $known[$prefixedTitle] )
					&& $known[$prefixedTitle]['revid'] === $remoteRevId
				) {
					continue;
				}
				if ( $localEdits->hasLocalEdits( $held[$prefixedTitle] ) ) {
					$this->output( "  skipping $prefixedTitle (has local edits)\n" );
					continue;
				}
				$stale[$prefixedTitle] = $remoteRevId;
			}

			if ( !$stale ) {
				continue;
			}

			if ( $dryRun ) {
				foreach ( array_keys( $stale ) as $prefixedTitle ) {
					$this->output( "  would sync $prefixedTitle\n" );
					$changed++;
				}
				continue;
			}

			foreach ( $api->getWikitext( array_keys( $stale ) ) as $prefixedTitle => $page ) {
				if ( !isset( $stale[$prefixedTitle] ) ) {
					continue;
				}
				$importer->refresh(
					$held[$prefixedTitle],
					$page['text'],
					$page['revid'],
					$known[$prefixedTitle]['kind'] ?? (
						$held[$prefixedTitle]->equals( $title ) ? 0 : 1
					)
				);
				$this->output( "  synced $prefixedTitle\n" );
				$changed++;
			}

			$this->waitForReplication();
		}

		return $changed;
	}

	/**
	 * @param array<string,Title> $held
	 * @return array<string,array{revid:int,kind:int}>
	 */
	private function knownRevisionIds( array $held ): array {
		$ids = [];
		foreach ( $held as $title ) {
			$ids[$title->getArticleID()] = $title->getPrefixedText();
		}

		$rows = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( [ 'wcp_page', 'wcp_remote_revid', 'wcp_kind' ] )
			->from( 'wikiclone_page' )
			->where( [ 'wcp_page' => array_keys( $ids ) ] )
			->caller( __METHOD__ )
			->fetchResultSet();

		$known = [];
		foreach ( $rows as $row ) {
			$prefixedTitle = $ids[(int)$row->wcp_page] ?? null;
			if ( $prefixedTitle !== null ) {
				$known[$prefixedTitle] = [
					'revid' => (int)$row->wcp_remote_revid,
					'kind' => (int)$row->wcp_kind,
				];
			}
		}

		return $known;
	}
}

$maintClass = RefreshMainPage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
