<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Load the upstream title index from a Wikimedia titles dump.
 *
 * This is what keeps links blue. The rows are not pages — see sql/tables.sql —
 * so this stays cheap: roughly 18 million rows of (namespace, title) rather
 * than 18 million revisions.
 *
 * Dumps: https://dumps.wikimedia.org/enwiki/latest/
 *   enwiki-latest-all-titles-in-ns0.gz   articles and redirects only
 *   enwiki-latest-all-titles.gz          every namespace, with a namespace column
 */
class ImportTitles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Bulk-load the upstream title index from a Wikimedia titles dump.' );
		$this->addOption( 'file', 'Path to the .gz titles dump', true, true );
		$this->addOption( 'namespaces', 'Comma-separated namespaces to keep (dumps with a namespace column only)', false, true );
		$this->addOption( 'batch-size', 'Rows per INSERT', false, true );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$path = $this->getOption( 'file' );
		$batchSize = (int)$this->getOption( 'batch-size', 5000 );
		$keep = array_map( 'intval', explode( ',', $this->getOption( 'namespaces', '0,4,10,12,14,100,828' ) ) );

		$handle = gzopen( $path, 'rb' );
		if ( !$handle ) {
			$this->fatalError( "Could not open $path" );
		}

		$dbw = $this->getDB( DB_PRIMARY );
		$header = gzgets( $handle );
		$hasNamespaceColumn = str_contains( (string)$header, "\t" );

		$this->output( $hasNamespaceColumn
			? "Dump has a namespace column; keeping namespaces: " . implode( ',', $keep ) . "\n"
			: "Dump is single-column; treating every title as namespace 0\n" );

		$rows = [];
		$read = 0;
		$inserted = 0;

		while ( ( $line = gzgets( $handle ) ) !== false ) {
			$line = rtrim( $line, "\r\n" );
			if ( $line === '' ) {
				continue;
			}
			$read++;

			if ( $hasNamespaceColumn ) {
				[ $namespace, $title ] = array_pad( explode( "\t", $line, 2 ), 2, '' );
				$namespace = (int)$namespace;
				if ( !in_array( $namespace, $keep, true ) ) {
					continue;
				}
			} else {
				$namespace = 0;
				$title = $line;
			}

			// page_title is VARBINARY(255); anything longer cannot be a real title.
			if ( $title === '' || strlen( $title ) > 255 ) {
				continue;
			}

			$rows[] = [ 'wct_namespace' => $namespace, 'wct_title' => $title ];

			if ( count( $rows ) >= $batchSize ) {
				$inserted += $this->flush( $dbw, $rows );
				$rows = [];
				$this->output( "  read $read, inserted $inserted\n" );
			}
		}

		if ( $rows ) {
			$inserted += $this->flush( $dbw, $rows );
		}

		gzclose( $handle );
		$this->output( "Done. Read $read lines, inserted $inserted titles.\n" );
	}

	private function flush( $dbw, array $rows ): int {
		$dbw->newInsertQueryBuilder()
			->insertInto( 'wikiclone_title' )
			->ignore()
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		$this->waitForReplication();

		return count( $rows );
	}
}

$maintClass = ImportTitles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
