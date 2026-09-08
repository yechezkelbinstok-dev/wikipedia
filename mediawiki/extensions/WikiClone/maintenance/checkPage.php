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
		$parserOutput = $page->getParserOutput(
			$services->getParserOptionsFactory()->newCanonical( 'canonical' )
		);
		$elapsed = round( microtime( true ) - $start, 2 );

		if ( !$parserOutput ) {
			$this->fatalError( 'Parse produced no output.' );
		}

		$html = $parserOutput->getContentHolderText();

		$this->output( $title->getPrefixedText() . "\n" );
		$this->output( str_repeat( '-', 60 ) . "\n" );
		$this->output( sprintf( "  rendered      %s bytes in %ss\n", number_format( strlen( $html ) ), $elapsed ) );

		$errors = $this->distinct( $html, '/<[^>]*class="[^"]*\berror\b[^"]*"[^>]*>(.*?)</s' );
		$this->output( sprintf( "  error spans   %d (%d distinct)\n", $errors['total'], count( $errors['distinct'] ) ) );

		$lua = substr_count( $html, 'Lua error' );
		$this->output( sprintf( "  Lua errors    %d\n", $lua ) );

		$this->output( sprintf( "  citations     %d\n", substr_count( $html, 'class="reference"' ) ) );

		$redLinks = $this->redLinks( $html );
		$this->output( sprintf( "  red links     %d\n", count( $redLinks ) ) );

		if ( $errors['distinct'] ) {
			$this->output( "\ndistinct errors:\n" );
			foreach ( $errors['distinct'] as $message => $count ) {
				$this->output( sprintf( "  [%dx] %s\n", $count, $this->trim( $message ) ) );
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

	private function distinct( string $html, string $pattern ): array {
		preg_match_all( $pattern, $html, $matches );
		$counts = [];
		foreach ( $matches[1] ?? [] as $text ) {
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
