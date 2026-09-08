<?php

namespace MediaWiki\Extension\WikiClone;

use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;

/**
 * The set of titles that exist upstream.
 *
 * This is deliberately not the `page` table. A row here means "en.wikipedia.org
 * has an article by this name", which is what link colouring and the fetcher
 * need to know; it says nothing about whether we hold the content yet.
 */
class TitleIndex {

	private IConnectionProvider $dbProvider;

	/** @var array<string,bool> per-request memo, keyed "ns:dbkey" */
	private array $memo = [];

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	public function exists( int $namespace, string $dbKey ): bool {
		$memoKey = $namespace . ':' . $dbKey;
		if ( !array_key_exists( $memoKey, $this->memo ) ) {
			$this->memo[$memoKey] = (bool)$this->dbProvider->getReplicaDatabase()
				->newSelectQueryBuilder()
				->select( 'wct_title' )
				->from( 'wikiclone_title' )
				->where( [ 'wct_namespace' => $namespace, 'wct_title' => $dbKey ] )
				->caller( __METHOD__ )
				->fetchField();
		}
		return $this->memo[$memoKey];
	}

	/**
	 * Titles starting with $prefix, for the search box.
	 *
	 * The primary key is (namespace, title), so this is a range scan rather
	 * than a scan of 22 million rows.
	 *
	 * @param int[] $namespaces
	 * @return array<int,array{0:int,1:string}> [ namespace, dbKey ] pairs
	 */
	public function prefixSearch( array $namespaces, string $prefix, int $limit ): array {
		if ( !$namespaces ) {
			return [];
		}

		$dbr = $this->dbProvider->getReplicaDatabase();

		$rows = $dbr->newSelectQueryBuilder()
			->select( [ 'wct_namespace', 'wct_title' ] )
			->from( 'wikiclone_title' )
			->where( [
				'wct_namespace' => $namespaces,
				$dbr->expr(
					'wct_title',
					IExpression::LIKE,
					new LikeValue( $prefix, $dbr->anyString() )
				),
			] )
			// Matches the primary key's order, so this stays a range scan
			// instead of sorting a slice of 22 million rows.
			->orderBy( 'wct_title' )
			->limit( $limit )
			->caller( __METHOD__ )
			->fetchResultSet();

		$results = [];
		foreach ( $rows as $row ) {
			$results[] = [ (int)$row->wct_namespace, $row->wct_title ];
		}

		return $results;
	}
}
