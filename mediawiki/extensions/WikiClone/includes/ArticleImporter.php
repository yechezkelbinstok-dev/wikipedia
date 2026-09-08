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
use Wikimedia\Rdbms\IConnectionProvider;
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

	private const IMPORT_USER = 'WikiClone importer';

	private WikipediaApi $api;
	private WikiPageFactory $wikiPageFactory;
	private TitleFactory $titleFactory;
	private IConnectionProvider $dbProvider;
	private IContentHandlerFactory $contentHandlerFactory;
	private int $maxDependencies;

	public function __construct(
		WikipediaApi $api,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory,
		IConnectionProvider $dbProvider,
		IContentHandlerFactory $contentHandlerFactory,
		int $maxDependencies
	) {
		$this->api = $api;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
		$this->dbProvider = $dbProvider;
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

			$dependencies = $this->missingDependencies( $prefixed );
			if ( $dependencies ) {
				$this->saveMany( $this->api->getWikitext( $dependencies ), $user, 1 );
			}

			$this->saveMany( $articles, $user, 0 );

			return StatusValue::newGood();
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
	 * @param int $kind 0 for a requested article, 1 for a dependency
	 */
	private function saveMany( array $pages, User $user, int $kind ): void {
		foreach ( $pages as $prefixedTitle => $page ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( !$title || $title->exists() ) {
				continue;
			}
			$this->save( $title, $page['text'], $page['revid'], $user, $kind );
		}
	}

	private function save( Title $title, string $text, int $remoteRevId, User $user, int $kind ): void {
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

		if ( !$revision ) {
			return;
		}

		$pageId = $title->getArticleID( IDBAccessObject::READ_LATEST );
		if ( !$pageId ) {
			return;
		}

		$now = $this->dbProvider->getPrimaryDatabase()->timestamp();
		$this->dbProvider->getPrimaryDatabase()->newReplaceQueryBuilder()
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

	/**
	 * Imports are attributed to a system account, not to whoever happened to
	 * click the link — page history should read as "imported", not "edited by
	 * the reader".
	 */
	private function getImportUser(): User {
		return User::newSystemUser( self::IMPORT_USER, [ 'steal' => true ] );
	}
}
