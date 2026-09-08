<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Hook\SearchGetNearMatchHook;
use MediaWiki\Search\Hook\PrefixSearchBackendHook;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * Search over the title index rather than over the handful of pages we happen
 * to hold.
 *
 * MediaWiki searches its own `page` and `searchindex` tables, which on a
 * lazily populated wiki contain almost nothing — so the search box finds
 * nothing and the only way to reach an article is to already be looking at a
 * link to it. That is not how anyone uses Wikipedia. These hooks let the
 * search box see all 22 million upstream titles; picking one imports it, the
 * same as following a link.
 */
class SearchHooks implements PrefixSearchBackendHook, SearchGetNearMatchHook {

	private TitleIndex $titleIndex;
	private TitleFactory $titleFactory;
	private IConnectionProvider $dbProvider;

	public function __construct(
		TitleIndex $titleIndex,
		TitleFactory $titleFactory,
		IConnectionProvider $dbProvider
	) {
		$this->titleIndex = $titleIndex;
		$this->titleFactory = $titleFactory;
		$this->dbProvider = $dbProvider;
	}

	/**
	 * Feed the search box's autocomplete.
	 *
	 * Locally held pages come first — those include anything you wrote, which
	 * is not in the upstream index at all — then upstream titles fill the rest.
	 *
	 * @inheritDoc
	 */
	public function onPrefixSearchBackend( $namespaces, $search, $limit, &$results, $offset ) {
		$prefix = $this->normalisePrefix( $search );
		if ( $prefix === null ) {
			return true;
		}

		$namespaces = $namespaces ?: [ NS_MAIN ];
		$seen = [];
		$out = [];

		foreach ( $this->localMatches( $namespaces, $prefix, $limit ) as $title ) {
			$out[] = $title;
			$seen[$title] = true;
		}

		foreach ( $this->titleIndex->prefixSearch( $namespaces, $prefix, $limit * 2 ) as [ $ns, $dbKey ] ) {
			$title = $this->titleFactory->makeTitle( $ns, $dbKey );
			$text = $title->getPrefixedText();
			if ( isset( $seen[$text] ) ) {
				continue;
			}
			$out[] = $text;
			$seen[$text] = true;
			if ( count( $out ) >= $limit + $offset ) {
				break;
			}
		}

		$results = array_slice( $out, $offset, $limit );

		return false;
	}

	/**
	 * Make pressing Enter on an exact title go to the article.
	 *
	 * Without this, searching for a page we have not fetched drops you on an
	 * empty results page instead of the article, because MediaWiki only counts
	 * a "near match" for a page that already exists.
	 *
	 * @inheritDoc
	 */
	public function onSearchGetNearMatch( $term, &$title ) {
		if ( $title instanceof Title && $title->exists() ) {
			return true;
		}

		foreach ( $this->candidates( $term ) as $candidate ) {
			if ( $this->titleIndex->exists( $candidate->getNamespace(), $candidate->getDBkey() ) ) {
				$title = $candidate;
				return false;
			}
		}

		return true;
	}

	/**
	 * Upstream titles are stored exactly as MediaWiki stores page titles, so a
	 * search term has to be normalised the same way before it can be compared:
	 * spaces become underscores and the first letter is capitalised.
	 *
	 * @return string|null null when the term cannot begin a valid title
	 */
	private function normalisePrefix( string $search ): ?string {
		$search = trim( $search );
		if ( $search === '' ) {
			return null;
		}

		// A partial title need not parse on its own ("Albert Ein" does, "Foo|"
		// does not), so normalise by hand rather than via TitleFactory.
		$prefix = str_replace( ' ', '_', $search );
		if ( strpbrk( $prefix, "#<>[]|{}\n\r\t" ) !== false ) {
			return null;
		}

		return ucfirst( $prefix );
	}

	/**
	 * @param int[] $namespaces
	 * @return string[] prefixed titles
	 */
	private function localMatches( array $namespaces, string $prefix, int $limit ): array {
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
	 * @return Title[] the term as written, and with the capitalisation
	 *         MediaWiki would apply
	 */
	private function candidates( string $term ): array {
		$candidates = [];

		foreach ( [ $term, ucfirst( trim( $term ) ) ] as $variant ) {
			$title = $this->titleFactory->newFromText( $variant );
			if ( $title && $title->canExist() ) {
				$candidates[$title->getPrefixedDBkey()] = $title;
			}
		}

		return array_values( $candidates );
	}
}
