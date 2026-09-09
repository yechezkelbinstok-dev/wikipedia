<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Import named articles without waiting for someone to click them.
 *
 * Useful for two things: reproducing an import failure with the errors in
 * front of you, and pre-warming. Pre-warming is what makes the wiki feel fast
 * — the first article in a topic pulls in its whole template tree, the tenth
 * pulls in almost nothing — and running it ahead of time moves that cost off
 * the first page view.
 */
class ImportPages extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Import specific pages from upstream.' );
		$this->addOption( 'file', 'File of titles, one per line', false, true );
		$this->addOption( 'force', 'Re-import even if the page already exists' );
		$this->addOption( 'backfill', 'Keep the page but fetch any dependencies it is missing' );
		$this->addOption( 'backfill-all', 'Backfill every article imported so far' );
		$this->addOption( 'category', 'Import every article in this category', false, true );
		$this->addOption( 'most-used', 'Import the templates Wikipedia transcludes most' );
		$this->addOption( 'limit', 'Cap how many titles a category contributes', false, true );
		$this->addOption( 'warm', 'Render each page after importing, so the first real view is served from the parser cache' );
		$this->addArg( 'title', 'Title to import', false, true );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$titles = $this->collectTitles();
		if ( !$titles ) {
			$this->fatalError( 'No titles given. Pass them as arguments or via --file.' );
		}

		$services = MediaWikiServices::getInstance();
		$importer = $services->getService( 'WikiClone.ArticleImporter' );
		$deletePageFactory = $services->getDeletePageFactory();
		$wikiPageFactory = $services->getWikiPageFactory();
		$force = $this->hasOption( 'force' );

		$imported = 0;
		$failed = 0;
		$warmed = 0;

		foreach ( $titles as $text ) {
			$title = Title::newFromText( $text );
			if ( !$title ) {
				$this->output( "  invalid title: $text\n" );
				$failed++;
				continue;
			}

			if ( $title->exists() ) {
				if ( $this->hasOption( 'backfill' ) || $this->hasOption( 'backfill-all' ) ) {
					// import() saves only what is missing, so running it over an
					// existing page collects dependencies added since — the
					// category pages, for instance — without disturbing the
					// article or its history.
					$status = $importer->import( $title );
					$stats = $status->getValue();
					$this->output( sprintf(
						"  backfilled %s (%s new dependencies)\n",
						$title->getPrefixedText(),
						is_array( $stats ) ? $stats['dependencies'] : '?'
					) );
					$imported++;
					$this->waitForReplication();
					continue;
				}

				if ( !$force ) {
					$this->output( "  skipping {$title->getPrefixedText()} (already here)\n" );
					continue;
				}
				$deletePageFactory
					->newDeletePage(
						$wikiPageFactory->newFromTitle( $title ),
						$importer->getImportUser()
					)
					->deleteUnsafe( 're-importing from upstream' );
			}

			$start = microtime( true );
			$status = $importer->import( $title );
			$elapsed = round( microtime( true ) - $start, 1 );

			$stats = $status->getValue();
			$detail = is_array( $stats )
				? sprintf(
					' — %d deps, %ss fetching, %ss saving',
					$stats['dependencies'], $stats['api'], $stats['save']
				)
				: '';

			if ( $status->isGood() ) {
				$this->output( "  imported {$title->getPrefixedText()} ({$elapsed}s{$detail})\n" );
				$imported++;
			} else {
				$this->output( "  PROBLEM {$title->getPrefixedText()} ({$elapsed}s): "
					. Status::wrap( $status )->getWikiText( false, false, 'en' ) . "\n" );
				$failed++;
			}

			if ( $this->hasOption( 'warm' ) && $title->exists() ) {
				$warmStart = microtime( true );
				$page = $wikiPageFactory->newFromTitle( $title );
				$page->getParserOutput( $page->makeParserOptions( 'canonical' ) );
				$this->output( sprintf(
					"    warmed in %ss\n", round( microtime( true ) - $warmStart, 1 )
				) );
				$warmed++;
			}

			$this->waitForReplication();
		}

		$this->output( "Imported $imported, $failed with problems"
			. ( $this->hasOption( 'warm' ) ? ", $warmed warmed" : '' ) . ".\n" );
	}

	/** @return string[] */
	private function collectTitles(): array {
		$titles = [];

		if ( $this->hasOption( 'backfill-all' ) ) {
			$rows = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
				->select( [ 'page_namespace', 'page_title' ] )
				->from( 'wikiclone_page' )
				->join( 'page', null, 'page_id = wcp_page' )
				->where( [ 'wcp_kind' => 0 ] )
				->caller( __METHOD__ )
				->fetchResultSet();

			foreach ( $rows as $row ) {
				$titles[] = Title::makeTitle( $row->page_namespace, $row->page_title )->getPrefixedText();
			}

			$this->output( 'Backfilling ' . count( $titles ) . " imported articles\n" );
		}

		if ( $this->hasOption( 'most-used' ) ) {
			$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
			$templates = $api->getMostTranscludedTemplates(
				(int)$this->getOption( 'limit', 500 )
			);
			$this->output( 'Most-transcluded templates: ' . count( $templates ) . "\n" );
			$titles = array_merge( $titles, $templates );
		}

		if ( $this->hasOption( 'category' ) ) {
			$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
			$members = $api->getCategoryMembers(
				$this->getOption( 'category' ),
				(int)$this->getOption( 'limit', 500 )
			);
			$this->output( 'Category contributed ' . count( $members ) . " titles\n" );
			$titles = array_merge( $titles, $members );
		}

		if ( $this->hasOption( 'file' ) ) {
			$path = $this->getOption( 'file' );
			$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
			if ( $lines === false ) {
				$this->fatalError( "Could not read $path" );
			}
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( $line !== '' && $line[0] !== '#' ) {
					$titles[] = $line;
				}
			}
		}

		foreach ( $this->getArgs() as $arg ) {
			$titles[] = $arg;
		}

		return $titles;
	}
}

$maintClass = ImportPages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
