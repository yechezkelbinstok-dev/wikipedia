<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Cache\LinkBatchFactory;
use MediaWiki\CommentStore\CommentStoreComment;
use MediaWiki\Content\IContentHandlerFactory;
use MediaWiki\Page\WikiPageFactory;
use MediaWiki\Revision\SlotRecord;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\User\User;
use StatusValue;
use Throwable;
use Wikimedia\Rdbms\IDBAccessObject;

/**
 * Pulls an article, plus whatever it needs in order to render, from upstream.
 *
 * An article's wikitext is not self-contained: it transcludes templates, which
 * transclude more templates, which call Lua modules. Importing the article
 * alone would render a page of red "Template:Infobox" errors, so we import the
 * whole transclusion tree. Those sets overlap heavily between articles, so the
 * cost falls off sharply after the first few imports.
 */
class ArticleImporter {

	public const IMPORT_USER = 'WikiClone importer';

	private WikipediaApi $api;
	private WikiPageFactory $wikiPageFactory;
	private TitleFactory $titleFactory;
	private PageStateStore $pageState;
	private IContentHandlerFactory $contentHandlerFactory;
	private LinkBatchFactory $linkBatchFactory;
	private int $maxDependencies;

	public function __construct(
		WikipediaApi $api,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory,
		PageStateStore $pageState,
		IContentHandlerFactory $contentHandlerFactory,
		LinkBatchFactory $linkBatchFactory,
		int $maxDependencies
	) {
		$this->api = $api;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
		$this->pageState = $pageState;
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->maxDependencies = $maxDependencies;
	}

	/**
	 * Import $title and everything it transcludes. Idempotent: pages we already
	 * hold are left alone, which is what makes the second article on a topic
	 * so much cheaper than the first.
	 */
	public function import( Title $title ): StatusValue {
		$user = $this->getImportUser();

		try {
			$prefixed = $title->getPrefixedText();

			$articles = $this->api->getWikitext( [ $prefixed ] );
			if ( !$articles ) {
				return StatusValue::newFatal( 'wikiclone-import-missing', $prefixed );
			}

			$status = StatusValue::newGood();
			$stats = [ 'dependencies' => 0, 'api' => 0.0, 'save' => 0.0 ];

			$mark = microtime( true );
			$dependencies = $this->missingDependencies( $prefixed );
			$stats['dependencies'] = count( $dependencies );

			if ( $dependencies ) {
				$pages = $this->api->getWikitext( $dependencies );
				$stats['api'] += microtime( true ) - $mark;

				$mark = microtime( true );
				$status->merge( $this->saveMany( $pages, $user, PageStateStore::KIND_DEPENDENCY ) );
				$stats['save'] += microtime( true ) - $mark;
			} else {
				$stats['api'] += microtime( true ) - $mark;
			}

			$mark = microtime( true );
			$status->merge( $this->saveMany( $articles, $user, PageStateStore::KIND_ARTICLE ) );
			$stats['save'] += microtime( true ) - $mark;

			$stats['api'] = round( $stats['api'], 1 );
			$stats['save'] = round( $stats['save'], 1 );
			$status->setResult( $status->isOK(), $stats );

			return $status;
		} catch ( Throwable $e ) {
			// A failed import must never take the page view down with it: the
			// reader should get MediaWiki's ordinary "no such page" instead.
			wfLogWarning( 'WikiClone import of ' . $title->getPrefixedText() . ' failed: ' . $e->getMessage() );
			return StatusValue::newFatal( 'wikiclone-import-failed', $e->getMessage() );
		}
	}

	/**
	 * @return string[] prefixed titles this wiki does not hold yet
	 */
	private function missingDependencies( string $prefixedTitle ): array {
		$candidates = [];
		foreach ( $this->api->getTransclusions( $prefixedTitle ) as $candidate ) {
			$title = $this->titleFactory->newFromText( $candidate );
			if ( $title ) {
				$candidates[] = $title;
			}
		}

		if ( !$candidates ) {
			return [];
		}

		// Prime the link cache in one query. Asking each of several hundred
		// titles whether it exists, one at a time, is several hundred queries
		// before the import has fetched anything at all.
		$this->linkBatchFactory->newLinkBatch( $candidates )
			->setCaller( __METHOD__ )
			->execute();

		$missing = [];
		foreach ( $candidates as $title ) {
			if ( $title->exists() ) {
				continue;
			}
			$missing[] = $title->getPrefixedText();
			if ( count( $missing ) >= $this->maxDependencies ) {
				break;
			}
		}

		return $missing;
	}

	/**
	 * @param array<string,array{text:string,revid:int}> $pages
	 * @param int $kind one of PageStateStore::KIND_*
	 */
	private function saveMany( array $pages, User $user, int $kind ): StatusValue {
		$status = StatusValue::newGood();

		// Every save builds a search index entry, which means parsing the page
		// for text. For a template or a Lua module that work is pure waste —
		// nobody full-text searches Module:Citation/CS1 — and an article drags
		// in a hundred of them, where saving, not fetching, is almost all of
		// the wait.
		$searchUpdatesDisabled = null;
		if ( $kind === PageStateStore::KIND_DEPENDENCY ) {
			global $wgDisableSearchUpdate;
			$searchUpdatesDisabled = $wgDisableSearchUpdate;
			$wgDisableSearchUpdate = true;
		}

		foreach ( $pages as $prefixedTitle => $page ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( !$title || $title->exists() ) {
				continue;
			}
			$status->merge( $this->save( $title, $page['text'], $page['revid'], $user, $kind ) );
		}

		if ( $searchUpdatesDisabled !== null ) {
			global $wgDisableSearchUpdate;
			$wgDisableSearchUpdate = $searchUpdatesDisabled;
		}

		return $status;
	}

	private function save(
		Title $title, string $text, int $remoteRevId, User $user, int $kind
	): StatusValue {
		$handler = $this->contentHandlerFactory->getContentHandler(
			$title->getContentModel()
		);
		$content = $handler->unserializeContent( $text );

		$updater = $this->wikiPageFactory->newFromTitle( $title )->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, $content );
		$revision = $updater->saveRevision(
			CommentStoreComment::newUnsavedComment(
				'Imported from en.wikipedia.org (revision ' . $remoteRevId . ')'
			),
			EDIT_NEW | EDIT_FORCE_BOT | EDIT_SUPPRESS_RC
		);

		// MediaWiki can refuse a save for reasons that have nothing to do with
		// the request being wrong — a content sanitiser rejecting the upstream
		// text, for instance. Dropping that silently is how one missing
		// stylesheet turns into every citation on the wiki rendering an error
		// with nothing in the logs to explain it.
		$saveStatus = $updater->getStatus();
		if ( !$revision || !$saveStatus || !$saveStatus->isOK() ) {
			$why = $saveStatus
				? $saveStatus->getWikiText( false, false, 'en' )
				: 'saveRevision() returned no revision';

			wfLogWarning(
				'WikiClone could not save ' . $title->getPrefixedText() . ': ' . $why
			);

			return StatusValue::newFatal(
				'wikiclone-save-failed', $title->getPrefixedText(), $why
			);
		}

		$pageId = $title->getArticleID( IDBAccessObject::READ_LATEST );
		if ( $pageId ) {
			$this->pageState->record( $pageId, $remoteRevId, $kind );
		}

		return StatusValue::newGood();
	}

	/**
	 * Replace a page's content with the current upstream revision. Used by
	 * sync, where the page already exists and we are moving it forward.
	 */
	public function refresh( Title $title, string $text, int $remoteRevId, int $kind ): void {
		$user = $this->getImportUser();

		$handler = $this->contentHandlerFactory->getContentHandler( $title->getContentModel() );
		$updater = $this->wikiPageFactory->newFromTitle( $title )->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, $handler->unserializeContent( $text ) );

		$revision = $updater->saveRevision(
			CommentStoreComment::newUnsavedComment(
				'Synced from en.wikipedia.org (revision ' . $remoteRevId . ')'
			),
			EDIT_UPDATE | EDIT_FORCE_BOT | EDIT_SUPPRESS_RC
		);

		$saveStatus = $updater->getStatus();
		if ( !$revision || !$saveStatus || !$saveStatus->isOK() ) {
			wfLogWarning( 'WikiClone could not sync ' . $title->getPrefixedText() . ': ' . (
				$saveStatus ? $saveStatus->getWikiText( false, false, 'en' ) : 'no revision'
			) );
			return;
		}

		$this->pageState->record(
			$title->getArticleID( IDBAccessObject::READ_LATEST ),
			$remoteRevId,
			$kind
		);
	}

	/**
	 * Imports are attributed to a system account, not to whoever happened to
	 * click the link — page history should read as "imported", not "edited by
	 * the reader".
	 */
	public function getImportUser(): User {
		return User::newSystemUser( self::IMPORT_USER, [ 'steal' => true ] );
	}
}
