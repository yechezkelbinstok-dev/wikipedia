<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Hook\ParserFirstCallInitHook;
use MediaWiki\Parser\Parser;

/**
 * Support for {{SHORTDESC:...}}.
 *
 * Wikipedia gets this magic word from its Wikidata client. Without a provider
 * MediaWiki has no idea what SHORTDESC is, parses the whole thing as an
 * ordinary transclusion, and renders a red link to
 * "Template:SHORTDESC:Some description" near the top of a great many articles.
 *
 * The standalone ShortDescription extension would also supply it, but it is
 * archived upstream and exists only as a lone master branch on Gerrit — too
 * much dependency for one red link, when the behaviour itself is this small.
 *
 * The value is stored as a page property, the way the real implementations do,
 * so a skin or API consumer can pick it up later.
 */
class ShortDescriptionHooks implements ParserFirstCallInitHook {

	/** @inheritDoc */
	public function onParserFirstCallInit( $parser ) {
		$parser->setFunctionHook(
			'shortdesc',
			[ self::class, 'render' ],
			Parser::SFH_NO_HASH
		);
	}

	/**
	 * @param Parser $parser
	 * @param string $description
	 * @return string always empty — the description is metadata, not content
	 */
	public static function render( Parser $parser, string $description = '' ): string {
		$description = trim( $description );

		if ( $description !== '' && $description !== 'none' ) {
			$parser->getOutput()->setPageProperty( 'wikibase-shortdesc', $description );
		}

		return '';
	}
}
