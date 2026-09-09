<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\MediaWikiServices;

return [
	'WikiClone.TitleIndex' => static function ( MediaWikiServices $services ): TitleIndex {
		return new TitleIndex( $services->getConnectionProvider() );
	},

	'WikiClone.LocalEditDetector' => static function ( MediaWikiServices $services ): LocalEditDetector {
		return new LocalEditDetector( $services->getRevisionLookup() );
	},

	'WikiClone.PageStateStore' => static function ( MediaWikiServices $services ): PageStateStore {
		return new PageStateStore( $services->getConnectionProvider() );
	},

	'WikiClone.WikidataClient' => static function ( MediaWikiServices $services ): WikidataClient {
		return new WikidataClient(
			$services->getHttpRequestFactory(),
			$services->getMainWANObjectCache(),
			$services->getMainConfig()->get( 'WikiCloneUserAgent' )
		);
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
			$services->getService( 'WikiClone.PageStateStore' ),
			$services->getContentHandlerFactory(),
			$services->getLinkBatchFactory(),
			$services->getMainConfig()->get( 'WikiCloneMaxDependencies' )
		);
	},
];
