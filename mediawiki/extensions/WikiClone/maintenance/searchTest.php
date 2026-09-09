<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Exercise the search box's backend from the command line.
 *
 * The wiki requires an account to read, so an anonymous request to the search
 * API is refused before it reaches any of this — which makes curl useless for
 * telling a broken search from a correctly protected one. This runs the same
 * completion search the suggestion endpoint runs, without the permission
 * check in the way.
 */
class SearchTest extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Run a completion search, as the search box does.' );
		$this->addOption( 'limit', 'How many suggestions to ask for', false, true );
		$this->addArg( 'term', 'What to search for', true );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$term = $this->getArg( 0 );
		$limit = (int)$this->getOption( 'limit', 10 );

		$engine = MediaWikiServices::getInstance()->getSearchEngineFactory()->create();
		$engine->setLimitOffset( $limit );

		$start = microtime( true );
		$suggestions = $engine->completionSearch( $term );
		$elapsed = round( ( microtime( true ) - $start ) * 1000 );

		$this->output( "completionSearch(\"$term\") — {$elapsed}ms\n" );

		$count = 0;
		foreach ( $suggestions->getSuggestions() as $suggestion ) {
			$title = $suggestion->getSuggestedTitle();
			$this->output( sprintf(
				"  %-55s %s\n",
				$suggestion->getText(),
				$title && $title->exists() ? '(held locally)' : '(index only)'
			) );
			$count++;
		}

		if ( !$count ) {
			$this->output( "  no suggestions — search is not seeing the title index\n" );
		}

		$this->output( "$count suggestions.\n" );
	}
}

$maintClass = SearchTest::class;
require_once RUN_MAINTENANCE_IF_MAIN;
