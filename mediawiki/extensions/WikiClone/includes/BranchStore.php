<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\User\UserIdentity;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Branches: separate lines of edit history over the same pages.
 *
 * Branch 0 is "live" — the wiki as Wikipedia has it, moved forward by
 * importing and syncing. A branch forks from live (or from another branch) and
 * diverges only for the pages actually edited on it; everything else it shows
 * is whatever its parent shows. That is what makes a branch cost a handful of
 * rows rather than a copy of the wiki.
 *
 * Branch revisions are ordinary MediaWiki revisions of the same page, which is
 * the whole trick: diffs, history and old-revision rendering all work as they
 * already do, and only the question of which revision is "the" one needs
 * answering here.
 */
class BranchStore {

	public const LIVE = 0;
	public const LIVE_NAME = 'live';

	private IConnectionProvider $dbProvider;

	/** @var array<int,array>|null all branches, loaded once per request */
	private ?array $branches = null;

	public function __construct( IConnectionProvider $dbProvider ) {
		$this->dbProvider = $dbProvider;
	}

	/**
	 * @return array<int,array{id:int,name:string,from:int,user:int,created:string}>
	 */
	public function listBranches(): array {
		if ( $this->branches !== null ) {
			return $this->branches;
		}

		$rows = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( [ 'wcb_id', 'wcb_name', 'wcb_from', 'wcb_user', 'wcb_created' ] )
			->from( 'wikiclone_branch' )
			->orderBy( 'wcb_name' )
			->caller( __METHOD__ )
			->fetchResultSet();

		$this->branches = [];
		foreach ( $rows as $row ) {
			$this->branches[(int)$row->wcb_id] = [
				'id' => (int)$row->wcb_id,
				'name' => $row->wcb_name,
				'from' => (int)$row->wcb_from,
				'user' => (int)$row->wcb_user,
				'created' => $row->wcb_created,
			];
		}

		return $this->branches;
	}

	public function getById( int $id ): ?array {
		return $this->listBranches()[$id] ?? null;
	}

	public function getByName( string $name ): ?array {
		foreach ( $this->listBranches() as $branch ) {
			if ( strcasecmp( $branch['name'], $name ) === 0 ) {
				return $branch;
			}
		}
		return null;
	}

	public function nameOf( int $id ): string {
		return $id === self::LIVE ? self::LIVE_NAME : ( $this->getById( $id )['name'] ?? self::LIVE_NAME );
	}

	/**
	 * @return int the new branch's id
	 */
	public function create( string $name, int $from, UserIdentity $user ): int {
		$dbw = $this->dbProvider->getPrimaryDatabase();
		$dbw->newInsertQueryBuilder()
			->insertInto( 'wikiclone_branch' )
			->row( [
				'wcb_name' => $name,
				'wcb_from' => $from,
				'wcb_user' => $user->getId(),
				'wcb_created' => $dbw->timestamp(),
			] )
			->caller( __METHOD__ )
			->execute();

		$this->branches = null;

		return $dbw->insertId();
	}

	/**
	 * The branch an edit by this person belongs on, made if it is not there yet.
	 *
	 * Editing is what creates a branch. Nobody should have to set one up first:
	 * the live wiki is Wikipedia's and stays Wikipedia's, and the moment you
	 * change a page your version of it becomes yours, on a branch of your own.
	 */
	public function personalBranchFor( UserIdentity $user ): int {
		$name = $user->getName();

		$existing = $this->getByName( $name );
		if ( $existing ) {
			return $existing['id'];
		}

		return $this->create( $name, self::LIVE, $user );
	}

	/**
	 * The revision a branch shows for a page.
	 *
	 * Falls back through the branch's ancestry to live, so a page nobody has
	 * edited on this branch reads exactly as it does everywhere else.
	 *
	 * @return int|null revision id, or null if the page has no revisions at all
	 */
	public function tipFor( int $branchId, int $pageId, int $flags = IDBAccessObject::READ_NORMAL ): ?int {
		$seen = [];
		while ( $branchId !== self::LIVE && !isset( $seen[$branchId] ) ) {
			$seen[$branchId] = true;

			$line = $this->lineFor( $branchId, $pageId, $flags );
			if ( $line ) {
				return (int)max( $line );
			}

			$branchId = $this->getById( $branchId )['from'] ?? self::LIVE;
		}

		return $this->liveTipFor( $pageId, $flags );
	}

	/**
	 * One branch's history of one page: the revisions it is made of, oldest
	 * first. Empty when the branch has never diverged for this page.
	 *
	 * @return int[] revision ids
	 */
	public function lineFor( int $branchId, int $pageId, int $flags = IDBAccessObject::READ_NORMAL ): array {
		if ( $branchId === self::LIVE ) {
			return $this->liveLineFor( $pageId, $flags );
		}

		$ids = $this->db( $flags )->newSelectQueryBuilder()
			->select( 'wcbr_rev' )
			->from( 'wikiclone_branch_rev' )
			->where( [ 'wcbr_branch' => $branchId, 'wcbr_page' => $pageId ] )
			->orderBy( 'wcbr_rev' )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map( 'intval', $ids );
	}

	/**
	 * The live history of a page: its revisions that were not written on a
	 * branch. Editing on a branch leaves page_latest pointing at the branch
	 * revision, so "the newest revision" is not the answer here.
	 *
	 * @return int[] revision ids, oldest first
	 */
	public function liveLineFor( int $pageId, int $flags = IDBAccessObject::READ_NORMAL ): array {
		$db = $this->db( $flags );

		$ids = $db->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'revision' )
			->leftJoin( 'wikiclone_revision_branch', null, 'wcrb_rev = rev_id' )
			->where( [ 'rev_page' => $pageId, 'wcrb_rev' => null ] )
			// By time, not by id. A revision imported from someone's Wikipedia
			// history is written now but happened years ago, and ordering by
			// id would make it the newest thing on the page.
			->orderBy( [ 'rev_timestamp', 'rev_id' ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		return array_map( 'intval', $ids );
	}

	public function liveTipFor( int $pageId, int $flags = IDBAccessObject::READ_NORMAL ): ?int {
		$id = $this->db( $flags )->newSelectQueryBuilder()
			->select( 'rev_id' )
			->from( 'revision' )
			->leftJoin( 'wikiclone_revision_branch', null, 'wcrb_rev = rev_id' )
			->where( [ 'rev_page' => $pageId, 'wcrb_rev' => null ] )
			->orderBy( [ 'rev_timestamp', 'rev_id' ], 'DESC' )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();

		return $id === false || $id === null ? null : (int)$id;
	}

	/**
	 * Whether any revision of this page was written on a branch. When none was,
	 * every revision is live and nothing needs filtering.
	 */
	public function hasBranchRevisions( int $pageId ): bool {
		return (bool)$this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'wcbr_rev' )
			->from( 'wikiclone_branch_rev' )
			->where( [ 'wcbr_page' => $pageId ] )
			->limit( 1 )
			->caller( __METHOD__ )
			->fetchField();
	}

	/**
	 * Which branch a revision was written on. Live revisions answer 0.
	 */
	public function branchOfRevision( int $revId ): int {
		$branch = $this->dbProvider->getReplicaDatabase()->newSelectQueryBuilder()
			->select( 'wcrb_branch' )
			->from( 'wikiclone_revision_branch' )
			->where( [ 'wcrb_rev' => $revId ] )
			->caller( __METHOD__ )
			->fetchField();

		return $branch === false || $branch === null ? self::LIVE : (int)$branch;
	}

	/**
	 * Record a revision as belonging to a branch.
	 *
	 * The first edit to a page on a branch also copies in the line the branch
	 * inherited, so its history reads continuously rather than starting
	 * abruptly at the fork.
	 */
	public function recordRevision( int $branchId, int $pageId, int $revId ): void {
		if ( $branchId === self::LIVE ) {
			return;
		}

		$dbw = $this->dbProvider->getPrimaryDatabase();

		$line = $this->lineFor( $branchId, $pageId, IDBAccessObject::READ_LATEST );
		if ( !$line ) {
			$parent = $this->getById( $branchId )['from'] ?? self::LIVE;
			$line = $this->lineFor( $parent, $pageId, IDBAccessObject::READ_LATEST );
		}

		$rows = [];
		foreach ( array_unique( array_merge( $line, [ $revId ] ) ) as $id ) {
			$rows[] = [ 'wcbr_branch' => $branchId, 'wcbr_page' => $pageId, 'wcbr_rev' => (int)$id ];
		}

		$dbw->newInsertQueryBuilder()
			->insertInto( 'wikiclone_branch_rev' )
			->ignore()
			->rows( $rows )
			->caller( __METHOD__ )
			->execute();

		$dbw->newInsertQueryBuilder()
			->insertInto( 'wikiclone_revision_branch' )
			->ignore()
			->row( [ 'wcrb_rev' => $revId, 'wcrb_branch' => $branchId ] )
			->caller( __METHOD__ )
			->execute();
	}

	/**
	 * Forget a page's branch rows. Called when the page itself goes.
	 */
	public function forgetPage( int $pageId ): void {
		$dbw = $this->dbProvider->getPrimaryDatabase();

		$revIds = $dbw->newSelectQueryBuilder()
			->select( 'wcbr_rev' )
			->from( 'wikiclone_branch_rev' )
			->where( [ 'wcbr_page' => $pageId ] )
			->caller( __METHOD__ )
			->fetchFieldValues();

		$dbw->newDeleteQueryBuilder()
			->deleteFrom( 'wikiclone_branch_rev' )
			->where( [ 'wcbr_page' => $pageId ] )
			->caller( __METHOD__ )
			->execute();

		if ( $revIds ) {
			$dbw->newDeleteQueryBuilder()
				->deleteFrom( 'wikiclone_revision_branch' )
				->where( [ 'wcrb_rev' => array_map( 'intval', $revIds ) ] )
				->caller( __METHOD__ )
				->execute();
		}
	}

	private function db( int $flags ) {
		return ( $flags & IDBAccessObject::READ_LATEST )
			? $this->dbProvider->getPrimaryDatabase()
			: $this->dbProvider->getReplicaDatabase();
	}
}
