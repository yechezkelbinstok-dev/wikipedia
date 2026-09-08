<?php

namespace MediaWiki\Extension\WikiClone;

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
	private int $maxDependencies;

	public function __construct(
		WikipediaApi $api,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory,
		PageStateStore $pageState,
		IContentHandlerFactory $contentHandlerFactory,
		int $maxDependencies
	) {
		$this->api = $api;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
		$this->pageState = $pageState;
		$this->contentHandlerFactory = $contentHandlerFactory;
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

			$dependencies = $this->missingDependencies( $prefixed );
			if ( $dependencies ) {
				$status->merge( $this->saveMany(
					$this->api->getWikitext( $dependencies ),
					$user,
					PageStateStore::KIND_DEPENDENCY
				) );
			}

			$status->merge( $this->saveMany( $articles, $user, PageStateStore::KIND_ARTICLE ) );

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
		$missing = [];

		foreach ( $this->api->getTransclusions( $prefixedTitle ) as $candidate ) {
			$dependency = $this->titleFactory->newFromText( $candidate );
			if ( !$dependency || $dependency->exists() ) {
				continue;
			}
			$missing[] = $dependency->getPrefixedText();
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

		foreach ( $pages as $prefixedTitle => $page ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( !$title || $title->exists() ) {
				continue;
			}
			$status->merge( $this->save( $title, $page['text'], $page['revid'], $user, $kind ) );
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
