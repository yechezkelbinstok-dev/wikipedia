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
		$this->checkSuggestionDetail();
		$this->checkTitleIndex();

		$this->output( "\n" );
		if ( $this->failures ) {
			$this->fatalError( "$this->failures check(s) failed." );
		}
		$this->output( "All checks passed.\n" );
	}

	/**
	 * Loading a handler class is the point. Registration proves nothing: a
	 * handler naming an interface that does not exist registers happily and
	 * fails only when the autoloader is asked for it, which is exactly how
	 * search stayed broken while everything looked fine.
	 */
	private function checkHooks(): void {
		$manifest = json_decode(
			(string)file_get_contents( __DIR__ . '/../extension.json' ),
			true
		);

		foreach ( $manifest['HookHandlers'] ?? [] as $name => $spec ) {
			$class = $spec['class'] ?? '';

			$this->attempt( "handler $name ($class) loads", static function () use ( $class ) {
				// class_exists() runs the autoloader, so an interface that
				// cannot be resolved surfaces here rather than in production.
				if ( !class_exists( $class ) ) {
					throw new \RuntimeException( 'class could not be loaded' );
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

	/**
	 * Searching for something held locally proves nothing — MediaWiki finds
	 * its own pages unaided. The index is only reaching search if a title
	 * that exists upstream and *not* here comes back.
	 */
	private function checkSearch(): void {
		$this->attempt( 'search reaches the title index', static function () {
			$engine = MediaWikiServices::getInstance()->getSearchEngineFactory()->create();
			$engine->setLimitOffset( 20 );
			$suggestions = $engine->completionSearch( 'Barack Obama' );

			$fromIndex = 0;
			foreach ( $suggestions->getSuggestions() as $suggestion ) {
				$title = $suggestion->getSuggestedTitle();
				if ( $title && !$title->exists() ) {
					$fromIndex++;
				}
			}

			if ( !$fromIndex ) {
				throw new \RuntimeException(
					'only locally held pages came back; the index is not reaching search'
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

	/**
	 * A suggestion without a description is a suggestion that does not look
	 * like Wikipedia's, which is the whole point of asking upstream for them.
	 */
	private function checkSuggestionDetail(): void {
		$this->attempt( 'suggestions carry descriptions', static function () {
			$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
			$results = $api->prefixSearch( 'Testost', 5 );

			if ( !$results ) {
				throw new \RuntimeException( 'upstream returned no suggestions' );
			}

			foreach ( $results as $result ) {
				if ( ( $result['description'] ?? null ) !== null ) {
					return;
				}
			}

			throw new \RuntimeException( 'none of them carried a description' );
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
