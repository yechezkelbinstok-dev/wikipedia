<?php

namespace MediaWiki\Extension\WikiClone;

use HtmlArmor;
use MediaWiki\Config\Config;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
use MediaWiki\Status\Status;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

class Hooks implements
	HtmlPageLinkRendererBeginHook,
	BeforeInitializeHook,
	PageDeleteCompleteHook
{

	private Config $config;
	private TitleIndex $titleIndex;
	private ArticleImporter $importer;
	private PageStateStore $pageState;
	private TitleFactory $titleFactory;

	public function __construct(
		Config $config,
		TitleIndex $titleIndex,
		ArticleImporter $importer,
		PageStateStore $pageState,
		TitleFactory $titleFactory
	) {
		$this->config = $config;
		$this->titleIndex = $titleIndex;
		$this->importer = $importer;
		$this->pageState = $pageState;
		$this->titleFactory = $titleFactory;
	}

	/**
	 * Colour links to titles we know exist upstream as normal links, even
	 * though we have not fetched them yet.
	 *
	 * Without this, a freshly built wiki renders every article as a red link —
	 * the single most obvious tell that you are not on Wikipedia. The title
	 * index makes the link blue; clicking it triggers the import below.
	 *
	 * @inheritDoc
	 */
	public function onHtmlPageLinkRendererBegin(
		$linkRenderer, $target, &$text, &$extraAttribs, &$query, &$ret
	) {
		if ( !$this->config->get( 'WikiCloneEnabled' ) ) {
			return true;
		}

		$title = $this->titleFactory->newFromLinkTarget( $target );

		// Only titles MediaWiki currently believes are missing are interesting;
		// everything else already renders correctly.
		if ( !$title->canExist() || $title->isKnown() ) {
			return true;
		}

		if ( !$this->titleIndex->exists( $title->getNamespace(), $title->getDBkey() ) ) {
			return true;
		}

		// Build the anchor here rather than calling back into the link renderer:
		// makeKnownLink() re-fires this same hook, which would recurse forever.
		$attribs = is_array( $extraAttribs ) ? $extraAttribs : [];
		$attribs['href'] = $title->getLinkURL( $query );
		if ( !isset( $attribs['title'] ) ) {
			$attribs['title'] = $title->getPrefixedText();
		}

		$label = $text !== null
			? HtmlArmor::getHtml( $text )
			: htmlspecialchars( $title->getPrefixedText() );

		$ret = Html::rawElement( 'a', $attribs, $label );

		return false;
	}

	/**
	 * Fetch an article the first time it is viewed.
	 *
	 * This runs synchronously, so a cold article blocks the request for as long
	 * as the import takes — several seconds, and longer for a page whose
	 * template tree is not yet warm. That is the trade for not storing a full
	 * dump; a warm page is served from the parser cache like any other.
	 *
	 * @inheritDoc
	 */
	public function onBeforeInitialize( $title, $unused, $output, $user, $request, $mediaWiki ) {
		if ( !$this->config->get( 'WikiCloneEnabled' ) ) {
			return;
		}

		if ( $request->getVal( 'action', 'view' ) !== 'view' ) {
			return;
		}

		if ( !$title instanceof Title || !$title->canExist() ) {
			return;
		}

		if ( $title->exists() ) {
			// Reading a page is what keeps it from being purged.
			$pageId = $title->getArticleID();
			DeferredUpdates::addCallableUpdate( function () use ( $pageId ) {
				$this->pageState->touch( $pageId );
			} );
			return;
		}

		$namespaces = $this->config->get( 'WikiCloneImportNamespaces' );
		if ( !in_array( $title->getNamespace(), $namespaces, true ) ) {
			return;
		}

		// The index is a snapshot of a dump, so an article created since it was
		// taken is simply not in it. For a link that is only a colour decision
		// this does not matter, but someone who has navigated here directly is
		// asking for a specific page, and it is worth one request upstream to
		// find out whether it exists.
		if ( !$this->titleIndex->exists( $title->getNamespace(), $title->getDBkey() )
			&& !$this->config->get( 'WikiCloneFetchUnindexed' )
		) {
			return;
		}

		$status = $this->importer->import( $title );
		if ( !$status->isGood() ) {
			wfLogWarning(
				'WikiClone import of ' . $title->getPrefixedText() . ' was incomplete: '
				. Status::wrap( $status )->getWikiText( false, false, 'en' )
			);
		}
	}

	/**
	 * A deleted page's bookkeeping row is dead weight: re-importing the same
	 * title creates a new page id, so the old row would never be reused.
	 *
	 * @inheritDoc
	 */
	public function onPageDeleteComplete(
		$page, $deleter, $reason, $pageID, $deletedRev, $logEntry, $archivedRevisionCount
	) {
		$this->pageState->forget( $pageID );
	}

	/**
	 * Treat an indexed title as one MediaWiki knows about.
	 *
	 * Search results are filtered through Title::isKnown(), so suggestions for
	 * articles we have not fetched were being generated and then thrown away —
	 * the search box could only ever offer the handful of pages already held.
	 * The title index is precisely a statement that these pages exist, so this
	 * is what it means.
	 *
	 * It also makes links to them blue through MediaWiki's own machinery
	 * rather than through the anchor built in onHtmlPageLinkRendererBegin,
	 * which now only has to handle what this cannot reach.
	 *
	 * @param Title $title
	 * @param bool &$isKnown
	 */
	public function onTitleIsAlwaysKnown( $title, &$isKnown ) {
		if ( !$this->config->get( 'WikiCloneEnabled' ) || !$title->canExist() ) {
			return;
		}

		if ( $this->titleIndex->exists( $title->getNamespace(), $title->getDBkey() ) ) {
			$isKnown = true;
		}
	}
}
