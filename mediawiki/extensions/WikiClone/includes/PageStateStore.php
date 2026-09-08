<?php

namespace MediaWiki\Extension\WikiClone;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Bookkeeping for imported pages: where each came from and when it was last
 * looked at. Sync reads the revision id; purge reads the access time.
 */
class PageStateStore {

	public const KIND_ARTICLE = 0;
	public const KIND_DEPENDENCY = 1;

	/** Don't rewrite the access timestamp more than once an hour per page. */
	private const TOUCH_INTERVAL = 3600;

	private IConnectionProvider $dbProvider;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	public function record( int $pageId, int $remoteRevId, int $kind ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$now = $dbw->timestamp();

		$dbw->newReplaceQueryBuilder()
			->replaceInto( 'wikiclone_page' )
			->uniqueIndexFields( [ 'wcp_page' ] )
			->row( [
				'wcp_page' => $pageId,
				'wcp_remote_revid' => $remoteRevId,
				'wcp_fetched' => $now,
				'wcp_accessed' => $now,
				'wcp_kind' => $kind,
			] )
			->caller( __METHOD__ )
			->execute();
	}

	public function forget( int $pageId ): void {
		$this->dbProvider->getPrimaryDatabase()->newDeleteQueryBuilder()
			->deleteFrom( 'wikiclone_page' )
			->where( [ 'wcp_page' => $pageId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Mark a page as read, so the purge knows it is still wanted.
	 *
	 * Rate-limited: a popular page would otherwise take a write on every view
	 * for a timestamp nobody reads at that resolution.
	 */
	public function touch( int $pageId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$cutoff = $dbw->timestamp( (int)wfTimestamp( TS_UNIX ) - self::TOUCH_INTERVAL );

		$dbw->newUpdateQueryBuilder()
			->update( 'wikiclone_page' )
			->set( [ 'wcp_accessed' => $dbw->timestamp() ] )
			->where( [
				'wcp_page' => $pageId,
				$dbw->expr( 'wcp_accessed', '<', $cutoff ),
			] )
			->caller( __METHOD__ )
			->execute();
	}
}
