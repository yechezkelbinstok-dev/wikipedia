<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Hide the category pages upstream hides.
 *
 * The repair pass for categories imported before the importer knew to ask.
 * Categories imported since arrive with the flag already on them, so a second
 * run of this reports nothing to do.
 */
class MarkHiddenCategories extends Maintenance {

	private const BATCH = 50;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Mark locally held category pages hidden where upstream hides them.' );
		$this->addOption( 'dry-run', 'Report what would change without changing it' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$api = $services->getService( 'WikiClone.WikipediaApi' );
		$importer = $services->getService( 'WikiClone.ArticleImporter' );
		$dryRun = $this->hasOption( 'dry-run' );

		$this->fetchCategoriesInUse( $importer, $dryRun );

		$rows = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_CATEGORY ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$this->output( 'Category pages held: ' . count( $rows ) . "\n" );

		$titles = [];
		foreach ( $rows as $dbKey ) {
			$titles[] = Title::makeTitle( NS_CATEGORY, $dbKey )->getPrefixedText();
		}

		$marked = 0;
		$hidden = 0;

		foreach ( array_chunk( $titles, self::BATCH ) as $chunk ) {
			foreach ( $api->getHiddenCategories( $chunk ) as $prefixedTitle ) {
				$hidden++;
				$title = Title::newFromText( $prefixedTitle );
				if ( !$title || !$title->exists() ) {
					continue;
				}

				if ( $dryRun ) {
					$this->output( "  would hide {$prefixedTitle}\n" );
					$marked++;
					continue;
				}

				if ( $importer->markHidden( $title ) ) {
					$this->output( "  hid {$prefixedTitle}\n" );
					$marked++;
				}
			}

			$this->waitForReplication();
		}

		$this->output( "Hidden upstream: $hidden. Changed here: $marked.\n" );
	}
	/**
	 * Create the category pages that are in use here but were never fetched.
	 *
	 * A page cannot be marked hidden if it does not exist, and a good many of
	 * the categories an article lands in never appear in upstream's own parse
	 * of it: they are added by our render rather than Wikipedia's — the CS1
	 * maintenance categories a slightly different module version produces, the
	 * "Pages with broken file links" a missing file produces — so the
	 * transclusion tree does not name them and nothing has fetched them.
	 *
	 * Taking them from categorylinks instead catches exactly the categories a
	 * reader here would see, which is the set that matters.
	 */
	private function fetchCategoriesInUse( $importer, bool $dryRun ): void {
		$missing = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( 'cl_to' )
			->distinct()
			->from( 'categorylinks' )
			->leftJoin( 'page', null, [
				'page_namespace' => NS_CATEGORY,
				'page_title = cl_to',
			] )
			->where( [ 'page_id' => null ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$this->output( 'Categories in use with no page here: ' . count( $missing ) . "\n" );

		if ( !$missing || $dryRun ) {
			return;
		}

		$titles = [];
		foreach ( $missing as $dbKey ) {
			$titles[] = Title::makeTitle( NS_CATEGORY, $dbKey )->getPrefixedText();
		}

		$created = 0;
		foreach ( array_chunk( $titles, self::BATCH ) as $chunk ) {
			$created += $importer->importCategoryPages( $chunk );
			$this->waitForReplication();
		}

		$this->output( "Fetched $created of them.\n" );
	}
}

$maintClass = MarkHiddenCategories::class;
require_once RUN_MAINTENANCE_IF_MAIN;
