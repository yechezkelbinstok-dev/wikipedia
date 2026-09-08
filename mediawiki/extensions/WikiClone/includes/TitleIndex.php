<?php

namespace MediaWiki\Extension\WikiClone;

use Wikimedia\Rdbms\IConnectionProvider;

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

}
