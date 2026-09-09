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
}

$maintClass = MarkHiddenCategories::class;
require_once RUN_MAINTENANCE_IF_MAIN;
