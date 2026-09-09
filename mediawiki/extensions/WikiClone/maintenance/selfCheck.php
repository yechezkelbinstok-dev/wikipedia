<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use Throwable;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Assert that this extension is actually wired up.
 *
 * A hook handler naming an interface that does not exist passes a syntax
 * check, registers without complaint, and then throws only when something
 * tries to construct it. Search was broken that way from the day it was
 * written: the class could not load, so neither of its hooks ever ran, while
 * the rest of the wiki carried on looking healthy.
 *
 * Everything here is a thing that has actually broken. Run from deploy.sh,
 * so a deploy that leaves the extension half-wired fails loudly.
 */
class SelfCheck extends Maintenance {

	private int $failures = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Check that hooks, services and libraries are wired up.' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$this->checkHooks();
		$this->checkServices();
		$this->checkSearch();
		$this->checkWikibase();
		$this->checkTitleIndex();

		$this->output( "\n" );
		if ( $this->failures ) {
			$this->fatalError( "$this->failures check(s) failed." );
		}
		$this->output( "All checks passed.\n" );
	}

	/**
	 * Constructing a handler is the point: registration alone proves nothing,
	 * because a missing interface only surfaces on instantiation.
	 */
	private function checkHooks(): void {
		$container = MediaWikiServices::getInstance()->getHookContainer();

		foreach ( [
			'HtmlPageLinkRendererBegin',
			'BeforeInitialize',
			'PageDeleteComplete',
			'PrefixSearchBackend',
			'SearchGetNearMatch',
			'ParserFirstCallInit',
			'ScribuntoExternalLibraries',
		] as $hook ) {
			$this->attempt( "hook $hook constructs", static function () use ( $container, $hook ) {
				$handlers = $container->getHandlers( $hook );
				if ( !$handlers ) {
					throw new \RuntimeException( 'no handlers registered' );
				}
			} );
		}
	}

	private function checkServices(): void {
		$services = MediaWikiServices::getInstance();

		foreach ( [
			'WikiClone.TitleIndex',
			'WikiClone.PageStateStore',
			'WikiClone.LocalEditDetector',
			'WikiClone.WikipediaApi',
			'WikiClone.WikidataClient',
			'WikiClone.ArticleImporter',
		] as $service ) {
			$this->attempt( "service $service", static function () use ( $services, $service ) {
				$services->getService( $service );
			} );
		}
	}

	private function checkSearch(): void {
		$this->attempt( 'search returns index titles', static function () {
			$engine = MediaWikiServices::getInstance()->getSearchEngineFactory()->create();
			$engine->setLimitOffset( 5 );
			$suggestions = $engine->completionSearch( 'Barack Ob' );

			if ( !count( $suggestions->getSuggestions() ) ) {
				throw new \RuntimeException(
					'no suggestions — the title index is not reaching search'
				);
			}
		} );
	}

	private function checkWikibase(): void {
		$this->attempt( 'mw.wikibase is offered to Lua', static function () {
			$libraries = [];
			MediaWikiServices::getInstance()->getHookContainer()
				->run( 'ScribuntoExternalLibraries', [ 'lua', &$libraries ] );

			if ( !isset( $libraries['mw.wikibase'] ) ) {
				throw new \RuntimeException( 'mw.wikibase was not registered' );
			}
		} );
	}

	private function checkTitleIndex(): void {
		$this->attempt( 'title index is populated', static function () {
			$count = MediaWikiServices::getInstance()->getConnectionProvider()
				->getReplicaDatabase()
				->newSelectQueryBuilder()
				->select( 'wct_title' )
				->from( 'wikiclone_title' )
				->limit( 1 )
				->caller( __METHOD__ )
				->fetchField();

			if ( !$count ) {
				throw new \RuntimeException( 'wikiclone_title is empty' );
			}
		} );
	}

	private function attempt( string $what, callable $check ): void {
		try {
			$check();
			$this->output( "  ok    $what\n" );
		} catch ( Throwable $e ) {
			$this->output( "  FAIL  $what: " . $e->getMessage() . "\n" );
			$this->failures++;
		}
	}
}

$maintClass = SelfCheck::class;
require_once RUN_MAINTENANCE_IF_MAIN;
