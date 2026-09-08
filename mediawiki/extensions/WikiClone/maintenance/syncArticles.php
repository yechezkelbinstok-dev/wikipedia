<?php

namespace MediaWiki\Extension\WikiClone\Maintenance;

use Maintenance;
use MediaWiki\MediaWikiServices;
use MediaWiki\Title\Title;

$IP = getenv( 'MW_INSTALL_PATH' ) ?: __DIR__ . '/../../..';
require_once "$IP/maintenance/Maintenance.php";

/**
 * Bring imported pages up to date with upstream.
 *
 * Checking is cheap: 50 titles per API call returns each page's current
 * revision id, and only the ones that moved need their text refetched. A wiki
 * holding 5,000 articles costs about 100 calls to check and downloads only
 * what actually changed.
 *
 * Pages carrying local edits are skipped, never overwritten.
 */
class SyncArticles extends Maintenance {

	public function __construct() {
		parent::__construct();
		$this->addDescription( 'Refetch imported pages whose upstream revision has moved on.' );
		$this->addOption( 'kind', 'articles, dependencies, or all (default: all)', false, true );
		$this->addOption( 'limit', 'Maximum pages to check', false, true );
		$this->addOption( 'dry-run', 'Report what would change without writing' );
		$this->requireExtension( 'WikiClone' );
	}

	public function execute() {
		$services = MediaWikiServices::getInstance();
		$api = $services->getService( 'WikiClone.WikipediaApi' );
		$importer = $services->getService( 'WikiClone.ArticleImporter' );
		$localEdits = $services->getService( 'WikiClone.LocalEditDetector' );

		$dryRun = $this->hasOption( 'dry-run' );
		$rows = $this->selectPages();

		$checked = 0;
		$changed = 0;
		$skipped = 0;

		foreach ( array_chunk( $rows, 50, true ) as $batch ) {
			$titles = [];
			foreach ( $batch as $row ) {
				$title = Title::makeTitle( $row->page_namespace, $row->page_title );
				$titles[$title->getPrefixedText()] = $row;
			}

			$checked += count( $titles );
			$remote = $api->getLastRevisionIds( array_keys( $titles ) );

			$stale = [];
			foreach ( $remote as $prefixedTitle => $remoteRevId ) {
				$row = $titles[$prefixedTitle] ?? null;
				if ( !$row || (int)$row->wcp_remote_revid === $remoteRevId ) {
					continue;
				}

				$title = Title::newFromText( $prefixedTitle );
				if ( !$title ) {
					continue;
				}

				if ( $localEdits->hasLocalEdits( $title ) ) {
					$this->output( "  skipping $prefixedTitle (has local edits)\n" );
					$skipped++;
					continue;
				}

				$stale[$prefixedTitle] = [ 'title' => $title, 'revid' => $remoteRevId, 'row' => $row ];
			}

			if ( !$stale ) {
				continue;
			}

			if ( $dryRun ) {
				foreach ( $stale as $prefixedTitle => $_ ) {
					$this->output( "  would sync $prefixedTitle\n" );
				}
				$changed += count( $stale );
				continue;
			}

			foreach ( $api->getWikitext( array_keys( $stale ) ) as $prefixedTitle => $page ) {
				if ( !isset( $stale[$prefixedTitle] ) ) {
					continue;
				}
				$entry = $stale[$prefixedTitle];
				$importer->refresh(
					$entry['title'],
					$page['text'],
					$page['revid'],
					(int)$entry['row']->wcp_kind
				);
				$this->output( "  synced $prefixedTitle\n" );
				$changed++;
			}

			$this->waitForReplication();
		}

		$this->output( "Checked $checked pages, synced $changed, skipped $skipped with local edits.\n" );
	}

	private function selectPages(): array {
		$builder = $this->getDB( DB_REPLICA )->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title', 'wcp_remote_revid', 'wcp_kind' ] )
			->from( 'wikiclone_page' )
			->join( 'page', null, 'page_id = wcp_page' )
			->orderBy( 'wcp_fetched' )
			->caller( __METHOD__ );

		$kind = $this->getOption( 'kind', 'all' );
		if ( $kind === 'articles' ) {
			$builder->where( [ 'wcp_kind' => 0 ] );
		} elseif ( $kind === 'dependencies' ) {
			$builder->where( [ 'wcp_kind' => 1 ] );
		}

		if ( $this->hasOption( 'limit' ) ) {
			$builder->limit( (int)$this->getOption( 'limit' ) );
		}

		return iterator_to_array( $builder->fetchResultSet(), false );
	}
}

$maintClass = SyncArticles::class;
require_once RUN_MAINTENANCE_IF_MAIN;
