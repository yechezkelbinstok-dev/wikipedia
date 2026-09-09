<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Copy Wikipedia's interwiki map.
 *
 * Prefixed links like [[c:Barack Obama]] or [[s:Executive Order 13506]] are
 * ordinary links to Commons and Wikisource on Wikipedia. Without the interwiki
 * table they are not recognised as interwiki at all, so they render as red
 * links to a local page that will never exist.
 *
 * This also covers language prefixes, which is what makes [[ja:メタ構文変数]]
 * resolve instead of showing red.
 */
class ImportInterwiki extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Copy the upstream interwiki map into this wiki." );
		$this->addOption( 'dry-run', 'Report what would change without writing' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
		$map = $api->getInterwikiMap();

		if ( !$map ) {
			$this->fatalError( 'Upstream returned no interwiki map.' );
		}

		$this->output( 'Upstream map has ' . count( $map ) . " prefixes\n" );

		if ( $this->hasOption( 'dry-run' ) ) {
			foreach ( array_slice( $map, 0, 10 ) as $row ) {
				$this->output( "  {$row['prefix']} -> {$row['url']}\n" );
			}
			$this->output( "  ...\n" );
			return;
		}

		$dbw = $this->getDB( DB_PRIMARY );
		$rows = [];

		foreach ( $map as $entry ) {
			if ( !isset( $entry['prefix'], $entry['url'] ) ) {
				continue;
			}
			$rows[] = [
				'iw_prefix' => $entry['prefix'],
				'iw_url' => $entry['url'],
				'iw_api' => $entry['api'] ?? '',
				'iw_wikiid' => $entry['wikiid'] ?? '',
				// "local" means the target is part of the same family, which is
				// what lets MediaWiki treat the link as internal-ish.
				'iw_local' => !empty( $entry['local'] ) ? 1 : 0,
				'iw_trans' => 0,
			];
		}

		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'interwiki' )
			->uniqueIndexFields( [ 'iw_prefix' ] )
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		// The map is cached; without this the new rows are ignored until it
		// expires on its own.
		MediaWikiServices::getInstance()->getInterwikiLookup()->invalidateCache( '' );

		$this->output( 'Wrote ' . count( $rows ) . " interwiki prefixes.\n" );
	}
}

$maintClass = ImportInterwiki::class;
require_once RUN_MAINTENANCE_IF_MAIN;
