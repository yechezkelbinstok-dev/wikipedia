<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Hook\ParserGetVariableValueSwitchHook;
use MediaWiki\Http\HttpRequestFactory;
use Wikimedia\ObjectCache\WANObjectCache;

/**
 * Report Wikipedia's own numbers where a page asks how big the wiki is.
 *
 * The Main Page says "N articles in English" and "N active editors", and
 * those come from magic words counting this wiki. That put "1,026 articles"
 * and "0 active editors" on a front page whose whole purpose is to look like
 * Wikipedia's — the single most obvious tell on the page.
 *
 * The counts come from upstream's siteinfo and are cached for a day: they
 * change constantly and nothing here depends on them being to the minute.
 */
class SiteStatsHooks implements ParserGetVariableValueSwitchHook {

	private const TTL = 86400;

	/** Magic word => the key siteinfo returns it under. */
	private const VARIABLES = [
		'numberofarticles' => 'articles',
		'numberofpages' => 'pages',
		'numberofusers' => 'users',
		'numberofactiveusers' => 'activeusers',
		'numberofedits' => 'edits',
		'numberoffiles' => 'images',
		'numberofadmins' => 'admins',
	];

	private HttpRequestFactory $httpRequestFactory;
	private WANObjectCache $cache;
	private string $apiUrl;
	private string $userAgent;
	private bool $enabled;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $cache,
		$config
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $cache;
		$this->apiUrl = $config->get( 'WikiCloneApiUrl' );
		$this->userAgent = $config->get( 'WikiCloneUserAgent' );
		$this->enabled = (bool)$config->get( 'WikiCloneUpstreamStatistics' );
	}

	/** @inheritDoc */
	public function onParserGetVariableValueSwitch(
		$parser, &$variableCache, $magicWordId, &$ret, $frame
	) {
		if ( !$this->enabled || !isset( self::VARIABLES[$magicWordId] ) ) {
			return;
		}

		$statistics = $this->statistics();
		$key = self::VARIABLES[$magicWordId];
		if ( !isset( $statistics[$key] ) ) {
			return;
		}

		$ret = (string)$statistics[$key];
		$variableCache[$magicWordId] = $ret;

		// These change every minute upstream, so a page that shows one must not
		// be cached here as though it were settled.
		$parser->getOutput()->updateCacheExpiry( self::TTL );
	}

	/**
	 * @return array<string,int>
	 */
	private function statistics(): array {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'wikiclone', 'upstream-statistics' ),
			self::TTL,
			function () {
				$url = $this->apiUrl . '?' . http_build_query( [
					'action' => 'query',
					'meta' => 'siteinfo',
					'siprop' => 'statistics',
					'format' => 'json',
					'formatversion' => 2,
				] );

				$request = $this->httpRequestFactory->create( $url, [
					'method' => 'GET',
					'timeout' => 10,
					'userAgent' => $this->userAgent,
				], __METHOD__ );

				if ( !$request->execute()->isOK() ) {
					return [];
				}

				$data = json_decode( $request->getContent(), true );

				return $data['query']['statistics'] ?? [];
			}
		);
	}
}
