<?php

namespace MediaWiki\Extension\WikiClone;

use HtmlArmor;
use MediaWiki\Config\Config;
use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Deferred\DeferredUpdates;
use MediaWiki\Html\Html;
use MediaWiki\Linker\Hook\HtmlPageLinkRendererBeginHook;
use MediaWiki\Page\Hook\PageDeleteCompleteHook;
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

		if ( !$this->titleIndex->exists( $title->getNamespace(), $title->getDBkey() ) ) {
			return;
		}

		$status = $this->importer->import( $title );
		if ( !$status->isGood() ) {
			wfLogWarning(
				'WikiClone import of ' . $title->getPrefixedText() . ' was incomplete: '
				. $status->getWikiText( false, false, 'en' )
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
}
