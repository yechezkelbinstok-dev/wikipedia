<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Render a page and report what is wrong with it.
 *
 * Judging fidelity by eye means a human loading the article and squinting at
 * it. These are the things that actually distinguish a good render from a bad
 * one, counted: parser errors, Lua failures, red links, citations.
 *
 * A red link is not necessarily a defect — plenty of articles link to pages
 * that do not exist upstream either — so they are listed rather than merely
 * counted, and --check-upstream asks Wikipedia which of them are red there too.
 */
class CheckPage extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Render a page and report errors, red links and citation counts.' );
		$this->addOption( 'check-upstream', 'Ask Wikipedia which red links are also red there' );
		$this->addArg( 'title', 'Page to render', true );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$title = Title::newFromText( $this->getArg( 0 ) );
		if ( !$title || !$title->exists() ) {
			$this->fatalError( 'No such page here: ' . $this->getArg( 0 ) );
		}

		$services = MediaWikiServices::getInstance();
		$page = $services->getWikiPageFactory()->newFromTitle( $title );

		$start = microtime( true );
		$parserOutput = $page->getParserOutput( $page->makeParserOptions( 'canonical' ) );
		$elapsed = round( microtime( true ) - $start, 2 );

		if ( !$parserOutput ) {
			$this->fatalError( 'Parse produced no output.' );
		}

		// getContentHolderText() is the newer accessor; fall back so this keeps
		// working across MediaWiki versions rather than fataling on one.
		$html = method_exists( $parserOutput, 'getContentHolderText' )
			? $parserOutput->getContentHolderText()
			: $parserOutput->getText();

		$this->output( $title->getPrefixedText() . "\n" );
		$this->output( str_repeat( '-', 60 ) . "\n" );
		$this->output( sprintf( "  rendered      %s bytes in %ss\n", number_format( strlen( $html ) ), $elapsed ) );

		// Up to the element's own closing tag, not to the first tag inside it.
		// Most of these messages name the page they are about with a link, so
		// stopping at the first "<" reported every TemplateStyles failure as
		// the single word "Page".
		$errors = $this->distinct(
			$html,
			'/<(strong|span|div|p)[^>]*class="[^"]*\berror\b[^"]*"[^>]*>(.{0,400}?)<\/\1>/s',
			2
		);
		$this->output( sprintf( "  error spans   %d (%d distinct)\n", $errors['total'], count( $errors['distinct'] ) ) );

		$lua = $this->luaErrors( $html );
		$this->output( sprintf( "  Lua errors    %d (%d distinct)\n",
			array_sum( $lua ), count( $lua ) ) );

		$this->output( sprintf( "  citations     %d\n", substr_count( $html, 'class="reference"' ) ) );

		$redLinks = $this->redLinks( $html );
		$this->output( sprintf( "  red links     %d\n", count( $redLinks ) ) );

		$categories = $this->categories( $parserOutput );
		$this->output( sprintf(
			"  categories    %d shown, %d hidden\n",
			count( $categories['shown'] ), count( $categories['hidden'] )
		) );

		if ( $lua ) {
			$this->output( "\nLua errors:\n" );
			foreach ( $lua as $message => $count ) {
				$this->output( sprintf( "  [%dx] %s\n", $count, $this->trim( $message ) ) );
			}
		}

		if ( $errors['distinct'] ) {
			$this->output( "\ndistinct errors:\n" );
			foreach ( $errors['distinct'] as $message => $count ) {
				$this->output( sprintf( "  [%dx] %s\n", $count, $this->trim( $message ) ) );
			}
		}

		if ( $categories['shown'] && $this->getOption( 'check-upstream' ) ) {
			$wrong = $this->shownButHiddenUpstream( $categories['shown'] );
			$this->output( sprintf(
				"  of those, %d are hidden on Wikipedia — those are the defects\n",
				count( $wrong )
			) );
			foreach ( $wrong as $category ) {
				$this->output( '    ' . $category . "\n" );
			}
		}

		if ( $categories['shown'] && !$this->getOption( 'check-upstream' ) ) {
			$this->output( "\ncategories a reader sees:\n" );
			foreach ( $categories['shown'] as $category ) {
				$this->output( '  ' . $category . "\n" );
			}
		}

		if ( $redLinks ) {
			$upstream = $this->getOption( 'check-upstream' )
				? $this->alsoRedUpstream( $redLinks )
				: null;

			$this->output( "\nred links:\n" );
			foreach ( $redLinks as $red ) {
				$note = '';
				if ( $upstream !== null ) {
					$note = in_array( $red, $upstream, true )
						? '  (also red on Wikipedia — not a defect)'
						: '  (BLUE on Wikipedia — worth investigating)';
				}
				$this->output( '  ' . $red . $note . "\n" );
			}
		}
	}

	/**
	 * The categories printed here that Wikipedia hides. A long footer is not by
	 * itself a fault — Wikipedia's own is long — so counting the ones that
	 * should not be there is the only number that means anything.
	 *
	 * @param string[] $shown category names
	 * @return string[]
	 */
	private function shownButHiddenUpstream( array $shown ): array {
		$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );

		$titles = [];
		foreach ( $shown as $name ) {
			$titles[] = 'Category:' . $name;
		}

		$wrong = [];
		foreach ( $api->getHiddenCategories( $titles ) as $prefixedTitle ) {
			$wrong[] = preg_replace( '/^Category:/', '', $prefixedTitle );
		}

		return $wrong;
	}

	/**
	 * Which categories the footer would print, and which stay out of sight.
	 *
	 * Wikipedia's maintenance categories are hidden, and a reader never sees
	 * them; a clone that prints them at the foot of every article is instantly
	 * distinguishable from the real thing, so this counts both.
	 *
	 * @return array{shown:string[],hidden:string[]}
	 */
	private function categories( $parserOutput ): array {
		$names = method_exists( $parserOutput, 'getCategoryNames' )
			? $parserOutput->getCategoryNames()
			: array_keys( $parserOutput->getCategories() );

		$titles = [];
		foreach ( $names as $name ) {
			$titles[] = Title::makeTitle( NS_CATEGORY, $name );
		}

		if ( !$titles ) {
			return [ 'shown' => [], 'hidden' => [] ];
		}

		$hiddenIds = MediaWikiServices::getInstance()->getPageProps()
			->getProperties( $titles, 'hiddencat' );

		$shown = [];
		$hidden = [];
		foreach ( $titles as $title ) {
			if ( isset( $hiddenIds[$title->getArticleID()] ) ) {
				$hidden[] = $title->getText();
			} else {
				$shown[] = $title->getText();
			}
		}

		return [ 'shown' => $shown, 'hidden' => $hidden ];
	}

	/**
	 * @return array<string,int> message => occurrences
	 */
	private function luaErrors( string $html ): array {
		preg_match_all( '/Lua error[^<]{0,300}/', $html, $matches );

		$counts = [];
		foreach ( $matches[0] ?? [] as $message ) {
			$message = trim( html_entity_decode( $message ) );
			$counts[$message] = ( $counts[$message] ?? 0 ) + 1;
		}
		arsort( $counts );

		return $counts;
	}

	private function distinct( string $html, string $pattern, int $group = 1 ): array {
		preg_match_all( $pattern, $html, $matches );
		$counts = [];
		foreach ( $matches[$group] ?? [] as $text ) {
			$text = trim( html_entity_decode( strip_tags( $text ) ) );
			if ( $text !== '' ) {
				$counts[$text] = ( $counts[$text] ?? 0 ) + 1;
			}
		}
		arsort( $counts );
		return [ 'total' => array_sum( $counts ), 'distinct' => $counts ];
	}

	/** @return string[] */
	private function redLinks( string $html ): array {
		preg_match_all( '/<a[^>]*class="[^"]*\bnew\b[^"]*"[^>]*title="([^"]+)"/', $html, $matches );
		$titles = array_map(
			static fn ( $t ) => preg_replace( '/ \(page does not exist\)$/', '', html_entity_decode( $t ) ),
			$matches[1] ?? []
		);
		return array_values( array_unique( $titles ) );
	}

	/**
	 * @param string[] $titles
	 * @return string[] those that do not exist upstream either
	 */
	private function alsoRedUpstream( array $titles ): array {
		$api = MediaWikiServices::getInstance()->getService( 'WikiClone.WikipediaApi' );
		$present = array_keys( $api->getLastRevisionIds( $titles ) );
		return array_values( array_diff( $titles, $present ) );
	}

	private function trim( string $text ): string {
		$text = preg_replace( '/\s+/', ' ', $text );
		return strlen( $text ) > 150 ? substr( $text, 0, 147 ) . '...' : $text;
	}
}

$maintClass = CheckPage::class;
require_once RUN_MAINTENANCE_IF_MAIN;
