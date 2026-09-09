<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Record Wikipedia's size as this wiki's size.
 *
 * The Main Page says "N articles in English" and "N active editors", and both
 * come from the site_stats row. This wiki holds a few thousand pages, so it
 * said "1,026 articles" and "0 active editors" on a page whose entire purpose
 * is to look like Wikipedia's front page — the plainest tell on it.
 *
 * The magic words behind those numbers are answered inside the parser before
 * any hook is offered them, so the only place to change the answer is where
 * the answer is kept.
 */
class SyncSiteStats extends Maintenance {

	/** site_stats column => the key upstream's siteinfo returns it under. */
	private const COLUMNS = [
		'ss_total_pages' => 'pages',
		'ss_good_articles' => 'articles',
		'ss_total_edits' => 'edits',
		'ss_users' => 'users',
		'ss_active_users' => 'activeusers',
		'ss_images' => 'images',
	];

	public function __construct() {
		parent::__construct();
		$this->addDescription( "Set this wiki's recorded statistics to Wikipedia's." );
		$this->addOption( 'dry-run', 'Report what would change without writing' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		if ( !$this->getConfig()->get( 'WikiCloneUpstreamStatistics' ) ) {
			$this->output( "Disabled by \$wgWikiCloneUpstreamStatistics.\n" );
			return;
		}

		$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
		$statistics = $api->getSiteStatistics();

		if ( !$statistics ) {
			$this->fatalError( 'Upstream returned no statistics.' );
		}

		$row = [];
		foreach ( self::COLUMNS as $column => $key ) {
			if ( isset( $statistics[$key] ) ) {
				$row[$column] = (int)$statistics[$key];
			}
		}

		if ( !$row ) {
			$this->fatalError( 'Upstream statistics had none of the fields wanted.' );
		}

		foreach ( $row as $column => $value ) {
			$this->output( sprintf( "  %-18s %s\n", $column, number_format( $value ) ) );
		}

		if ( $this->hasOption( 'dry-run' ) ) {
			return;
		}

		$dbw = $this->getDB( DB_PRIMARY );
		$dbw->newUpdateQueryBuilder()
			->update( 'site_stats' )
			->set( $row )
			->where( [ 'ss_row_id' => 1 ] )
			->caller( __METHOD__ )
			->execute();

		if ( !$dbw->affectedRows() ) {
			$dbw->newInsertQueryBuilder()
				->insertInto( 'site_stats' )
				->row( [ 'ss_row_id' => 1 ] + $row )
				->caller( __METHOD__ )
				->execute();
		}

		$this->output( "Written.\n" );
	}
}

$maintClass = SyncSiteStats::class;
require_once RUN_MAINTENANCE_IF_MAIN;
