<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
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

		foreach ( $titles as $text ) {
			$title = Title::newFromText( $text );
			if ( !$title ) {
				$this->output( "  invalid title: $text\n" );
				$failed++;
				continue;
			}

			if ( $title->exists() ) {
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

			if ( $status->isGood() ) {
				$this->output( "  imported {$title->getPrefixedText()} ({$elapsed}s)\n" );
				$imported++;
			} else {
				$this->output( "  PROBLEM {$title->getPrefixedText()} ({$elapsed}s): "
					. $status->getWikiText( false, false, 'en' ) . "\n" );
				$failed++;
			}

			$this->waitForReplication();
		}

		$this->output( "Imported $imported, $failed with problems.\n" );
	}

	/** @return string[] */
	private function collectTitles(): array {
		$titles = [];

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

		for ( $i = 0; $i < $this->getArgCount(); $i++ ) {
			$titles[] = $this->getArg( $i );
		}

		return $titles;
	}
}

$maintClass = ImportPages::class;
require_once RUN_MAINTENANCE_IF_MAIN;
