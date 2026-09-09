<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Search\Hook\PrefixSearchBackendHook;
use MediaWiki\Search\Hook\SearchGetNearMatchHook;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Throwable;
use WANObjectCache;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * Search over everything Wikipedia has, not over the handful of pages held.
 *
 * MediaWiki searches its own tables, which on a lazily populated wiki contain
 * almost nothing. The title index fixes most of that, but it is loaded from a
 * dump and so knows nothing of articles created since, and it can only order
 * alphabetically — which puts "Test&set" and "Test, John" above "Testosterone"
 * where Wikipedia puts the article people meant.
 *
 * So suggestions come from upstream, in upstream's order, carrying upstream's
 * short descriptions and thumbnails. The index remains the offline fallback,
 * and learns the titles upstream returns, so it heals as it is used.
 */
class SearchHooks implements PrefixSearchBackendHook, SearchGetNearMatchHook {

	private const UPSTREAM_TTL = 3600;

	private TitleIndex $titleIndex;
	private WikipediaApi $api;
	private TitleFactory $titleFactory;
	private IConnectionProvider $dbProvider;
	private WANObjectCache $cache;

	/**
	 * Descriptions and thumbnails, keyed by prefixed DB key, for the hooks
	 * that decorate the results after they are chosen.
	 *
	 * @var array<string,array{description:?string,thumbnail:?string}>
	 */
	private array $decorations = [];

	public function __construct(
		TitleIndex $titleIndex,
		WikipediaApi $api,
		TitleFactory $titleFactory,
		IConnectionProvider $dbProvider,
		WANObjectCache $cache
	) {
		$this->titleIndex = $titleIndex;
		$this->api = $api;
		$this->titleFactory = $titleFactory;
		$this->dbProvider = $dbProvider;
		$this->cache = $cache;
	}

	/** @inheritDoc */
	public function onPrefixSearchBackend( $namespaces, $search, $limit, &$results, $offset ) {
		$term = trim( $search );
		if ( $term === '' ) {
			return true;
		}

		$namespaces = $namespaces ?: [ NS_MAIN ];
		$seen = [];
		$out = [];

		// Pages actually held come first: they include anything written here,
		// which upstream has never heard of.
		foreach ( $this->localMatches( $namespaces, $term, $limit ) as $title ) {
			$out[] = $title;
			$seen[$title] = true;
		}

		foreach ( $this->upstreamMatches( $term, $limit + $offset ) as $title ) {
			if ( !isset( $seen[$title] ) ) {
				$out[] = $title;
				$seen[$title] = true;
			}
		}

		// Only if upstream gave us nothing — offline, rate-limited, or a term
		// it does not like — does the local index have to carry the result.
		if ( count( $out ) < $limit + $offset ) {
			foreach ( $this->indexMatches( $namespaces, $term, $limit + $offset ) as $title ) {
				if ( !isset( $seen[$title] ) ) {
					$out[] = $title;
					$seen[$title] = true;
				}
			}
		}

		$results = array_slice( $out, $offset, $limit );

		return false;
	}

	/**
	 * Make Enter on an exact title open the article.
	 *
	 * @inheritDoc
	 */
	public function onSearchGetNearMatch( $term, &$title ) {
		if ( $title instanceof Title && $title->exists() ) {
			return true;
		}

		foreach ( [ $term, ucfirst( trim( $term ) ) ] as $variant ) {
			$candidate = $this->titleFactory->newFromText( $variant );
			if ( !$candidate || !$candidate->canExist() ) {
				continue;
			}

			if ( $this->titleIndex->exists( $candidate->getNamespace(), $candidate->getDBkey() ) ) {
				$title = $candidate;
				return false;
			}

			// Not in the index does not mean not on Wikipedia — the index is a
			// dump snapshot. Pressing Enter on the exact name of an article
			// created since should still open it, so ask upstream.
			if ( $this->existsUpstream( $candidate ) ) {
				$this->titleIndex->remember(
					[ [ $candidate->getNamespace(), $candidate->getDBkey() ] ]
				);
				$title = $candidate;
				return false;
			}
		}

		return true;
	}

	private function existsUpstream( Title $candidate ): bool {
		try {
			return (bool)$this->cache->getWithSetCallback(
				$this->cache->makeKey( 'wikiclone-exists', $candidate->getPrefixedDBkey() ),
				self::UPSTREAM_TTL,
				fn () => $this->api->getLastRevisionIds( [ $candidate->getPrefixedText() ] ) ? 1 : 0
			);
		} catch ( Throwable $e ) {
			wfLogWarning( 'WikiClone upstream existence check failed: ' . $e->getMessage() );
			return false;
		}
	}

	/**
	 * Short descriptions, as Wikipedia's search box shows beneath each title.
	 *
	 * @param array $pageIdentities
	 * @param array &$descriptions
	 */
	public function onSearchResultProvideDescription( array $pageIdentities, &$descriptions ) {
		foreach ( $pageIdentities as $key => $page ) {
			$decoration = $this->decorations[$this->decorationKey( $page )] ?? null;
			if ( $decoration && $decoration['description'] !== null ) {
				$descriptions[$key] = $decoration['description'];
			}
		}
	}

	/**
	 * Thumbnails, likewise. Wikipedia's suggestions are recognisable partly
	 * because each has a picture.
	 *
	 * @param array $pageIdentities
	 * @param array &$results
	 * @param int|null $size
	 */
	public function onSearchResultProvideThumbnail( array $pageIdentities, &$results, $size = null ) {
		foreach ( $pageIdentities as $key => $page ) {
			$decoration = $this->decorations[$this->decorationKey( $page )] ?? null;
			if ( !$decoration || $decoration['thumbnail'] === null ) {
				continue;
			}

			$results[$key] = new \MediaWiki\Search\Entity\SearchResultThumbnail(
				'image/jpeg',
				null,
				$size,
				$size,
				null,
				$decoration['thumbnail'],
				null
			);
		}
	}

	private function decorationKey( $page ): string {
		if ( is_object( $page ) && method_exists( $page, 'getDBkey' ) ) {
			return $page->getDBkey();
		}
		return (string)$page;
	}

	/**
	 * @return string[] prefixed titles, in upstream's order
	 */
	private function upstreamMatches( string $term, int $limit ): array {
		try {
			$suggestions = $this->cache->getWithSetCallback(
				$this->cache->makeKey( 'wikiclone-suggest', $term, $limit ),
				self::UPSTREAM_TTL,
				fn () => $this->api->prefixSearch( $term, $limit )
			);
		} catch ( Throwable $e ) {
			// Search must keep working when upstream does not.
			wfLogWarning( 'WikiClone upstream suggestions failed: ' . $e->getMessage() );
			return [];
		}

		$titles = [];
		$learned = [];

		foreach ( $suggestions as $suggestion ) {
			$title = $this->titleFactory->newFromText( $suggestion['title'] );
			if ( !$title || !$title->canExist() ) {
				continue;
			}

			$titles[] = $title->getPrefixedText();
			$this->decorations[$title->getDBkey()] = [
				'description' => $suggestion['description'] ?? null,
				'thumbnail' => $suggestion['thumbnail'] ?? null,
			];

			if ( !$this->titleIndex->exists( $title->getNamespace(), $title->getDBkey() ) ) {
				$learned[] = [ $title->getNamespace(), $title->getDBkey() ];
			}
		}

		if ( $learned ) {
			// Written after the response, so a search never waits on it.
			DeferredUpdates::addCallableUpdate( function () use ( $learned ) {
				$this->titleIndex->remember( $learned );
			} );
		}

		return $titles;
	}

	/** @return string[] prefixed titles */
	private function indexMatches( array $namespaces, string $term, int $limit ): array {
		$prefix = $this->normalisePrefix( $term );
		if ( $prefix === null ) {
			return [];
		}

		$titles = [];
		foreach ( $this->titleIndex->prefixSearch( $namespaces, $prefix, $limit ) as [ $ns, $dbKey ] ) {
			$titles[] = $this->titleFactory->makeTitle( $ns, $dbKey )->getPrefixedText();
		}

		return $titles;
	}

	/** @return string[] prefixed titles */
	private function localMatches( array $namespaces, string $term, int $limit ): array {
		$prefix = $this->normalisePrefix( $term );
		if ( $prefix === null ) {
			return [];
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'page_namespace', 'page_title' ] )
			->from( 'page' )
			->where( [
				'page_namespace' => $namespaces,
				$dbr->expr(
					'page_title',
					IExpression::LIKE,
					new LikeValue( $prefix, $dbr->anyString() )
				),
			] )
			->orderBy( 'page_title' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$titles = [];
		foreach ( $rows as $row ) {
			$titles[] = $this->titleFactory
				->makeTitle( (int)$row->page_namespace, $row->page_title )
				->getPrefixedText();
		}

		return $titles;
	}

	/**
	 * Titles are stored as MediaWiki stores them, so a search term has to be
	 * normalised the same way before it can be compared: spaces to
	 * underscores, first letter capitalised.
	 */
	private function normalisePrefix( string $search ): ?string {
		$search = trim( $search );
		if ( $search === '' ) {
			return null;
		}

		// A partial title need not parse on its own, so normalise by hand.
		$prefix = str_replace( ' ', '_', $search );
		if ( strpbrk( $prefix, "#<>[]|{}\n\r\t" ) !== false ) {
			return null;
		}

		return ucfirst( $prefix );
	}
}
