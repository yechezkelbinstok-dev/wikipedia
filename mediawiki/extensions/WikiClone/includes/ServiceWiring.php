<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\MediaWikiServices;

return [
	'WikiClone.TitleIndex' => static function ( MediaWikiServices $services ): TitleIndex {
		return new TitleIndex( $services->getConnectionProvider() );
	},

	'WikiClone.WikipediaApi' => static function ( MediaWikiServices $services ): WikipediaApi {
		$config = $services->getMainConfig();
		return new WikipediaApi(
			$services->getHttpRequestFactory(),
			$config->get( 'WikiCloneApiUrl' ),
			$config->get( 'WikiCloneUserAgent' )
		);
	},

	'WikiClone.ArticleImporter' => static function ( MediaWikiServices $services ): ArticleImporter {
		return new ArticleImporter(
			$services->getService( 'WikiClone.WikipediaApi' ),
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			$services->getConnectionProvider(),
			$services->getContentHandlerFactory(),
			$services->getMainConfig()->get( 'WikiCloneMaxDependencies' )
		);
	},
];
