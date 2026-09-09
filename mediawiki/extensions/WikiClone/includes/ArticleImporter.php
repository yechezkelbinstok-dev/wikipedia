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
	private TitleIndex $titleIndex;
	private int $maxDependencies;

	public function __construct(
		WikipediaApi $api,
		WikiPageFactory $wikiPageFactory,
		TitleFactory $titleFactory,
		PageStateStore $pageState,
		IContentHandlerFactory $contentHandlerFactory,
		LinkBatchFactory $linkBatchFactory,
		TitleIndex $titleIndex,
		int $maxDependencies
	) {
		$this->api = $api;
		$this->wikiPageFactory = $wikiPageFactory;
		$this->titleFactory = $titleFactory;
		$this->pageState = $pageState;
		$this->contentHandlerFactory = $contentHandlerFactory;
		$this->linkBatchFactory = $linkBatchFactory;
		$this->titleIndex = $titleIndex;
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
				$this->markHiddenCategories( $pages );
				$stats['api'] += microtime( true ) - $mark;

				$mark = microtime( true );
				$status->merge( $this->saveMany( $pages, $user, PageStateStore::KIND_DEPENDENCY ) );
				$stats['save'] += microtime( true ) - $mark;
			} else {
				$stats['api'] += microtime( true ) - $mark;
			}

			// A template imported by name is still a dependency, not an
			// article: purging articles nobody has read must not take the
			// template tree out from under the ones that remain.
			$dependencyNamespaces = [ NS_TEMPLATE ];
			if ( defined( 'NS_MODULE' ) ) {
				$dependencyNamespaces[] = NS_MODULE;
			}

			$kind = in_array( $title->getNamespace(), $dependencyNamespaces, true )
				? PageStateStore::KIND_DEPENDENCY
				: PageStateStore::KIND_ARTICLE;

			$mark = microtime( true );
			$status->merge( $this->saveMany( $articles, $user, $kind ) );
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
		$linkedTitles = null;
		$dependencies = $this->api->getDependencies( $prefixedTitle, $linkedTitles );
		$this->rememberLinks( $linkedTitles ?? [] );

		$candidates = [];
		foreach ( $dependencies as $candidate ) {
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
	 * Add the titles upstream says exist to the index, so links to them are
	 * blue.
	 *
	 * The index is a snapshot of a dump: an article written since it was taken
	 * is not in it, and renders red here while being perfectly blue on
	 * Wikipedia. Waiting for the monthly reload to fix that means a month of
	 * links that look broken, and this answer arrives free with a call the
	 * import is already making.
	 *
	 * @param string[] $prefixedTitles
	 */
	private function rememberLinks( array $prefixedTitles ): void {
		$rows = [];
		foreach ( $prefixedTitles as $prefixedTitle ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( $title && $title->canExist() ) {
				$rows[] = [ $title->getNamespace(), $title->getDBkey() ];
			}
		}

		if ( $rows ) {
			$this->titleIndex->remember( $rows );
		}
	}

	/**
	 * Wikipedia hides its maintenance categories — "Articles with short
	 * description", the CS1 ones — and readers never see them at the foot of an
	 * article. The flag lives on the category page as __HIDDENCAT__, but
	 * upstream almost never writes the magic word directly: the page reads
	 * {{Wikipedia category|hidden=yes}} and the template emits it.
	 *
	 * Importing that template, and the tree behind it, for every category an
	 * article belongs to would add a round of fetching to a page view that is
	 * already the slowest thing here, and buys nothing a reader can see. So we
	 * state the flag the template would have produced. The rendered category
	 * page is unaffected; the categories simply stop showing, as upstream.
	 *
	 * @param array<string,array{text:string,revid:int}> &$pages
	 */
	private function markHiddenCategories( array &$pages ): void {
		$categories = [];
		foreach ( array_keys( $pages ) as $prefixedTitle ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( $title && $title->getNamespace() === NS_CATEGORY ) {
				$categories[] = $prefixedTitle;
			}
		}

		if ( !$categories ) {
			return;
		}

		foreach ( $this->api->getHiddenCategories( $categories ) as $prefixedTitle ) {
			if ( isset( $pages[$prefixedTitle] ) ) {
				$pages[$prefixedTitle]['text'] = self::withHiddenMarker( $pages[$prefixedTitle]['text'] );
			}
		}
	}

	/**
	 * @return string $text with __HIDDENCAT__ on it, unchanged if it is already there
	 */
	public static function withHiddenMarker( string $text ): string {
		if ( str_contains( $text, '__HIDDENCAT__' ) ) {
			return $text;
		}

		return rtrim( $text, "\n" ) . "\n__HIDDENCAT__\n";
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
			$status->merge( $this->save(
				$title, $page['text'], $page['revid'], $user, $kind, $page['model'] ?? null
			) );
		}

		if ( $searchUpdatesDisabled !== null ) {
			global $wgDisableSearchUpdate;
			$wgDisableSearchUpdate = $searchUpdatesDisabled;
		}

		return $status;
	}

	private function save(
		Title $title, string $text, int $remoteRevId, User $user, int $kind,
		?string $model = null
	): StatusValue {
		// Existence was last checked against the link cache, before any of this
		// import's own saves. A title can appear twice in one transclusion tree,
		// or be created by an earlier save in the same run, and the stale answer
		// then drives a create that fails with "it already exists". Ask the
		// primary database, which knows what this run has just written.
		if ( $title->getArticleID( IDBAccessObject::READ_LATEST ) ) {
			return StatusValue::newGood();
		}

		$content = $this->makeContent( $title, $text, $model );

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
	 * Fetch category pages without resolving what they transclude.
	 *
	 * A category page is needed here for one reason — it is where the hidden
	 * flag lives — and pulling the template tree behind each of several
	 * hundred of them to get that would cost far more than it is worth. The
	 * page arrives with upstream's wikitext, so it is right the moment its
	 * templates turn up through some other article.
	 *
	 * @param string[] $prefixedTitles
	 * @return int how many were created
	 */
	public function importCategoryPages( array $prefixedTitles ): int {
		if ( !$prefixedTitles ) {
			return 0;
		}

		$before = 0;
		foreach ( $prefixedTitles as $prefixedTitle ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( $title && $title->exists() ) {
				$before++;
			}
		}

		$pages = [];
		foreach ( $this->api->getCategoryPages( $prefixedTitles ) as $prefixedTitle => $page ) {
			$pages[$prefixedTitle] = [
				'text' => $page['hidden'] ? self::withHiddenMarker( $page['text'] ) : $page['text'],
				'revid' => $page['revid'],
			];
		}

		$this->saveMany( $pages, $this->getImportUser(), PageStateStore::KIND_DEPENDENCY );

		$created = 0;
		foreach ( array_keys( $pages ) as $prefixedTitle ) {
			$title = $this->titleFactory->newFromText( $prefixedTitle );
			if ( $title && $title->getArticleID( IDBAccessObject::READ_LATEST ) ) {
				$created++;
			}
		}

		return max( 0, $created - $before );
	}

	/**
	 * Put __HIDDENCAT__ on a category page we already hold.
	 *
	 * The repair path for categories imported before we knew to ask: see
	 * markHiddenCategories() for why the flag is stated rather than rendered.
	 *
	 * @return bool whether the page needed changing
	 */
	public function markHidden( Title $title ): bool {
		$page = $this->wikiPageFactory->newFromTitle( $title );
		$content = $page->getContent();
		if ( !$content ) {
			return false;
		}

		$text = $content->serialize();
		$marked = self::withHiddenMarker( $text );
		if ( $marked === $text ) {
			return false;
		}

		$handler = $this->contentHandlerFactory->getContentHandler( $title->getContentModel() );
		$updater = $page->newPageUpdater( $this->getImportUser() );
		$updater->setContent( SlotRecord::MAIN, $handler->unserializeContent( $marked ) );
		$updater->saveRevision(
			CommentStoreComment::newUnsavedComment( 'Hidden upstream, so hidden here' ),
			EDIT_UPDATE | EDIT_FORCE_BOT | EDIT_SUPPRESS_RC
		);

		$saveStatus = $updater->getStatus();
		if ( !$saveStatus || !$saveStatus->isOK() ) {
			wfLogWarning( 'WikiClone could not mark ' . $title->getPrefixedText() . ' hidden: ' . (
				$saveStatus ? $saveStatus->getWikiText( false, false, 'en' ) : 'no status'
			) );
			return false;
		}

		return true;
	}

	/**
	 * Replace a page's content with the current upstream revision. Used by
	 * sync, where the page already exists and we are moving it forward.
	 */
	public function refresh(
		Title $title, string $text, int $remoteRevId, int $kind, ?string $model = null
	): void {
		$user = $this->getImportUser();

		// A sync replaces the page with upstream's text, which would drop the
		// hidden flag we stated at import time. Ask again rather than assume:
		// upstream is free to have unhidden the category since.
		if ( $title->getNamespace() === NS_CATEGORY
			&& $this->api->getHiddenCategories( [ $title->getPrefixedText() ] )
		) {
			$text = self::withHiddenMarker( $text );
		}

		$updater = $this->wikiPageFactory->newFromTitle( $title )->newPageUpdater( $user );
		$updater->setContent( SlotRecord::MAIN, $this->makeContent( $title, $text, $model ) );

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
	 * Build the page's content, in the model upstream keeps it in.
	 *
	 * Inferring the model from the title is what put "Wikipedia:Main Page/
	 * styles.css" in the wiki as wikitext: TemplateStyles is only enabled for
	 * a few namespaces, so MediaWiki's default for a .css page outside them is
	 * ordinary wikitext, and TemplateStyles then refuses to use it — printing a
	 * red error across the front page. Upstream knows what the page is; ask it.
	 */
	private function makeContent( Title $title, string $text, ?string $model ) {
		$factory = $this->contentHandlerFactory;

		if ( $model !== null && $model !== $title->getContentModel() ) {
			try {
				if ( $factory->isDefinedModel( $model ) ) {
					return $factory->getContentHandler( $model )->unserializeContent( $text );
				}
			} catch ( Throwable $e ) {
				// An upstream model this wiki does not have — fall through and
				// store it as whatever the title says, which is what we did
				// before asking at all.
				wfLogWarning(
					'WikiClone could not use upstream content model ' . $model
					. ' for ' . $title->getPrefixedText() . ': ' . $e->getMessage()
				);
			}
		}

		return $factory->getContentHandler( $title->getContentModel() )
			->unserializeContent( $text );
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
