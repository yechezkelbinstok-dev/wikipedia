<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Everything that makes this wiki pass for Wikipedia, checked at once.
 *
 * selfCheck asks whether the machinery is wired up. This asks the question a
 * reader would: does the site look and behave like the thing it is imitating?
 * Each check is something that has actually been wrong, and each one that can
 * be settled against upstream is settled against upstream rather than by eye.
 */
class Audit extends Maintenance {

	private int $failures = 0;
	private int $warnings = 0;

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Audit the wiki against the real Wikipedia.' );
		$this->addOption( 'page', 'Article to render for the content checks', false, true );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$this->section( 'Front page' );
		$this->checkMainPage();

		$this->section( 'Interface' );
		$this->checkInterfacePages();
		$this->checkLogos();

		$this->section( 'Footnotes and references' );
		$this->checkCiteLabels();

		$this->section( 'Accounts and sessions' );
		$this->checkSessions();

		$this->section( 'Namespaces' );
		$this->checkNamespaces();

		$this->section( 'Rendering' );
		$this->checkRendering();

		$this->section( 'Speed' );
		$this->checkSpeed();

		$this->output( "\n" );
		if ( $this->failures ) {
			$this->fatalError( "$this->failures problem(s), $this->warnings warning(s)." );
		}
		$this->output( "No problems. $this->warnings warning(s).\n" );
	}

	private function checkMainPage(): void {
		$title = Title::newMainPage();
		$content = MediaWikiServices::getInstance()->getWikiPageFactory()
			->newFromTitle( $title )->getContent();
		$text = $content ? $content->serialize() : '';

		$this->assert(
			'the front page is Wikipedia\'s, not MediaWiki\'s default',
			!str_contains( $text, 'MediaWiki has been installed' ) && $text !== ''
		);

		// Wikipedia's Main Page shows the day's featured article, which lives
		// at a dated title that did not exist yesterday. Holding that page is
		// the only proof the daily refresh is working.
		$today = Title::newFromText(
			"Wikipedia:Today's featured article/" . gmdate( 'F j, Y' )
		);
		$this->assert(
			"today's featured article is here (" . gmdate( 'F j, Y' ) . ')',
			$today && $today->exists()
		);

		// And then look at it. Checking the front page's wikitext and not its
		// render is how a red TemplateStyles error sat across the top of it
		// while this audit reported no problems at all: the render checks only
		// ever ran on an article.
		// The front page carries no reference list, and neither does
		// Wikipedia's, so it is not the page to ask about citations.
		$this->checkRender( $title, false );

		$this->assert(
			'the front page shows Wikipedia\'s article count, not this wiki\'s',
			$this->articleCount() > 1000000,
			'shows ' . number_format( $this->articleCount() )
		);
	}

	private function articleCount(): int {
		return (int)\SiteStats::articles();
	}

	private function checkInterfacePages(): void {
		$path = __DIR__ . '/../data/interface-pages.txt';
		$lines = @file( $path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES ) ?: [];

		$absent = [];
		foreach ( $lines as $line ) {
			$line = trim( $line );
			if ( $line === '' || $line[0] === '#' ) {
				continue;
			}
			$title = Title::newFromText( $line );
			if ( $title && !$title->exists() ) {
				$absent[] = $line;
			}
		}

		// Wikipedia does not customise every one of these; where it leaves
		// MediaWiki's default in place, so do we, and the page is absent on
		// both wikis. Only the ones upstream has written are worth having, so
		// ask rather than treating every absence as a fault.
		$missing = [];
		if ( $absent ) {
			$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
			$missing = array_values(
				array_intersect( $absent, array_keys( $api->getLastRevisionIds( $absent ) ) )
			);
		}

		$this->assert(
			'every interface page Wikipedia customises is present',
			!$missing,
			$missing ? 'missing: ' . implode( ', ', $missing ) : ''
		);

		$notUpstream = array_diff( $absent, $missing );
		if ( $notUpstream ) {
			$this->output( '  note  ' . count( $notUpstream )
				. " listed pages are not customised on Wikipedia either\n" );
		}
	}

	private function checkLogos(): void {
		global $wgLogos, $wgFavicon;

		$urls = [ 'favicon' => $wgFavicon ];
		foreach ( [ 'icon', 'wordmark', 'tagline' ] as $part ) {
			$value = $wgLogos[$part] ?? null;
			if ( is_array( $value ) ) {
				$value = $value['src'] ?? null;
			}
			$urls[$part] = $value;
		}

		foreach ( $urls as $part => $url ) {
			if ( !$url ) {
				$this->assert( "logo: $part is configured", false );
				continue;
			}
			$this->assert(
				"logo: $part loads",
				$this->fetchable( $url ),
				$url
			);
		}

		// Vector 2022 draws the globe from $wgLogos['icon']; the wordmark
		// alone is what a wiki looks like when the icon has not been set.
		$this->assert(
			'the header logo has all three parts',
			isset( $wgLogos['icon'], $wgLogos['wordmark'], $wgLogos['tagline'] )
		);
	}

	private function checkCiteLabels(): void {
		// Cite prints a named group's own name when it has no label list, so a
		// note that should read [a] reads [lower-alpha 1]. {{efn}} uses this
		// group, so it shows up on any article with explanatory notes.
		$groups = [ 'lower-alpha', 'upper-alpha', 'lower-roman', 'upper-roman', 'note' ];

		$absent = [];
		foreach ( $groups as $group ) {
			$title = Title::makeTitle( NS_MEDIAWIKI, 'Cite link label group-' . $group );
			if ( $title->exists() ) {
				$this->output( "  ok    footnote labels for $group\n" );
			} else {
				$absent[$group] = $title->getPrefixedText();
			}
		}

		if ( !$absent ) {
			return;
		}

		// A group Wikipedia has no label list for is one Cite numbers there
		// too, so its absence here is not a difference.
		$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
		$upstream = array_keys( $api->getLastRevisionIds( array_values( $absent ) ) );

		foreach ( $absent as $group => $prefixedTitle ) {
			if ( in_array( $prefixedTitle, $upstream, true ) ) {
				$this->assert( "footnote labels for $group", false );
			} else {
				$this->output(
					"  note  $group has no label list on Wikipedia either\n"
				);
			}
		}
	}

	private function checkSessions(): void {
		global $wgObjectCacheSessionExpiry, $wgRememberMe, $wgSessionCacheType;

		$this->assert(
			'sessions outlast a single sitting',
			$wgObjectCacheSessionExpiry >= 7 * 24 * 3600,
			'currently ' . round( $wgObjectCacheSessionExpiry / 3600 ) . 'h'
		);
		$this->assert(
			'"keep me logged in" is offered, as on Wikipedia',
			$wgRememberMe === 'choose'
		);
		$this->assert(
			'sessions survive a container restart',
			$wgSessionCacheType === CACHE_DB
		);
	}

	private function checkNamespaces(): void {
		global $wgWikiCloneImportNamespaces;

		foreach ( [ NS_MAIN => 'articles', NS_USER => 'user pages and sandboxes' ] as $ns => $what ) {
			$this->assert(
				"$what are fetched on view",
				in_array( $ns, $wgWikiCloneImportNamespaces, true )
			);
		}
	}

	private function checkRendering(): void {
		$name = $this->getOption( 'page', 'Barack Obama' );
		$title = Title::newFromText( $name );
		if ( !$title || !$title->exists() ) {
			$this->warn( "no local copy of $name, skipping the render checks" );
			return;
		}

		$this->checkRender( $title );
	}

	/**
	 * Render a page and hold it to what a reader would notice: no error
	 * messages, no Lua failures, no link red here that is blue on Wikipedia,
	 * no category Wikipedia hides.
	 */
	private function checkRender( Title $title, bool $expectCitations = true ): void {
		$name = $title->getPrefixedText();
		$page = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle( $title );
		$output = $page->getParserOutput( $page->makeParserOptions( 'canonical' ) );
		$html = method_exists( $output, 'getContentHolderText' )
			? $output->getContentHolderText()
			: $output->getText();

		$this->assert( "$name: no Lua errors", !str_contains( $html, 'Lua error' ) );

		$errors = $this->errorMessages( $html );
		$this->assert(
			"$name: no error messages",
			!$errors,
			$errors ? implode( ' | ', array_slice( $errors, 0, 3 ) ) : ''
		);
		// A broken label renders as the group's name and a number — "[lower-alpha
		// 1]". The bare words appear in ordinary CSS too, so matching those
		// reports a fault on a page that is perfectly fine.
		$brokenLabels = preg_match_all(
			'/\[(?:lower|upper)-(?:alpha|roman|greek) \d+\]/', $html
		);
		$this->assert(
			"$name: footnote labels are letters, not group names",
			!$brokenLabels,
			$brokenLabels ? "$brokenLabels labels still read as the group name" : ''
		);
		if ( $expectCitations ) {
			$this->assert(
				"$name: citations rendered",
				substr_count( $html, 'class="reference"' ) > 0
			);
		}

		$red = $this->redLinks( $html );
		if ( $red ) {
			$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
			$blueUpstream = array_intersect( $red, array_keys( $api->getLastRevisionIds( $red ) ) );
			$this->assert(
				"$name: no links red here but blue on Wikipedia",
				!$blueUpstream,
				$blueUpstream ? implode( ', ', array_slice( $blueUpstream, 0, 5 ) ) : ''
			);
		} else {
			$this->assert( "$name: no links red here but blue on Wikipedia", true );
		}

		$shown = $this->shownCategories( $output );
		if ( $shown ) {
			$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
			$titles = array_map( static fn ( $c ) => 'Category:' . $c, $shown );
			$wrong = $api->getHiddenCategories( $titles );
			$this->assert(
				"$name: shows no category Wikipedia hides",
				!$wrong,
				$wrong ? implode( ', ', array_slice( $wrong, 0, 5 ) ) : ''
			);
		}
	}

	private function checkSpeed(): void {
		$services = MediaWikiServices::getInstance();

		$this->assert(
			'Lua runs in the fast engine',
			extension_loaded( 'luasandbox' ),
			'luastandalone starts a process per parse'
		);

		$templates = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( 'COUNT(*)' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_TEMPLATE ] )
			->caller( __METHOD__ )
			->fetchField();

		// The slow part of a cold view is the template tree, and those trees
		// overlap heavily. A few thousand templates in hand is the difference
		// between a cold view fetching a hundred pages and fetching none.
		$this->assert(
			'the common template core is here',
			(int)$templates >= 2000,
			(int)$templates . ' templates — run importPages.php --most-used'
		);

		$warm = Title::newFromText( $this->getOption( 'page', 'Barack Obama' ) );
		if ( $warm && $warm->exists() ) {
			$page = $services->getWikiPageFactory()->newFromTitle( $warm );
			$start = microtime( true );
			$page->getParserOutput( $page->makeParserOptions( 'canonical' ) );
			$elapsed = round( microtime( true ) - $start, 2 );
			$this->output( sprintf(
				"  note  a warm view of %s took %ss\n", $warm->getPrefixedText(), $elapsed
			) );
		}
	}

	/**
	 * The error messages a reader would see, in full.
	 *
	 * @return string[]
	 */
	private function errorMessages( string $html ): array {
		preg_match_all(
			'/<(strong|span|div|p)[^>]*class="[^"]*\berror\b[^"]*"[^>]*>(.*?)<\/\1>/s',
			$html,
			$matches
		);

		$messages = [];
		foreach ( $matches[2] ?? [] as $text ) {
			$text = trim( preg_replace( '/\s+/', ' ', html_entity_decode( strip_tags( $text ) ) ) );
			if ( $text !== '' ) {
				$messages[$text] = true;
			}
		}

		return array_keys( $messages );
	}

	/** @return string[] */
	private function redLinks( string $html ): array {
		preg_match_all(
			'/<a[^>]*class="[^"]*\bnew\b[^"]*"[^>]*title="([^"]+)"/', $html, $matches
		);
		$titles = array_map(
			static fn ( $t ) => preg_replace(
				'/ \(page does not exist\)$/', '', html_entity_decode( $t )
			),
			$matches[1] ?? []
		);
		return array_values( array_unique( $titles ) );
	}

	/** @return string[] */
	private function shownCategories( $output ): array {
		$names = method_exists( $output, 'getCategoryNames' )
			? $output->getCategoryNames()
			: array_keys( $output->getCategories() );

		$titles = [];
		foreach ( $names as $name ) {
			$titles[] = Title::makeTitle( NS_CATEGORY, $name );
		}
		if ( !$titles ) {
			return [];
		}

		$hidden = MediaWikiServices::getInstance()->getPageProps()
			->getProperties( $titles, 'hiddencat' );

		$shown = [];
		foreach ( $titles as $title ) {
			if ( !isset( $hidden[$title->getArticleID()] ) ) {
				$shown[] = $title->getText();
			}
		}
		return $shown;
	}

	private function fetchable( string $url ): bool {
		$request = MediaWikiServices::getInstance()->getHttpRequestFactory()
			->create( $url, [ 'method' => 'HEAD', 'timeout' => 10 ], __METHOD__ );
		return $request->execute()->isOK();
	}

	private function section( string $name ): void {
		$this->output( "\n$name\n" . str_repeat( '-', 60 ) . "\n" );
	}

	private function assert( string $what, bool $ok, string $detail = '' ): void {
		if ( $ok ) {
			$this->output( "  ok    $what\n" );
			return;
		}
		$this->failures++;
		$this->output( "  FAIL  $what" . ( $detail ? " — $detail" : '' ) . "\n" );
	}

	private function warn( string $what ): void {
		$this->warnings++;
		$this->output( "  warn  $what\n" );
	}
}

$maintClass = Audit::class;
require_once RUN_MAINTENANCE_IF_MAIN;
