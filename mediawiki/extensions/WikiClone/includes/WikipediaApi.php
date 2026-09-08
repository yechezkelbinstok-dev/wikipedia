<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Http\HttpRequestFactory;
use RuntimeException;

/**
 * Thin client for the upstream MediaWiki action API.
 *
 * Requests are deliberately serial: Wikimedia's API etiquette asks clients to
 * keep concurrency low, and the import is bounded by politeness rather than by
 * our own CPU anyway.
 */
class WikipediaApi {

	private const TITLES_PER_REQUEST = 50;

	private HttpRequestFactory $httpRequestFactory;
	private string $apiUrl;
	private string $userAgent;

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		string $apiUrl,
		string $userAgent
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->apiUrl = $apiUrl;
		$this->userAgent = $userAgent;
	}

	/**
	 * Every page transcluded by $prefixedTitle, flattened — action=parse
	 * resolves the whole tree, so nested templates and the Lua modules behind
	 * them come back in one call.
	 *
	 * @return string[] prefixed titles, e.g. "Template:Infobox", "Module:Citation/CS1"
	 */
	public function getTransclusions( string $prefixedTitle ): array {
		$data = $this->request( [
			'action' => 'parse',
			'page' => $prefixedTitle,
			'prop' => 'templates',
			'redirects' => 1,
		] );

		$titles = [];
		foreach ( $data['parse']['templates'] ?? [] as $template ) {
			if ( isset( $template['title'] ) ) {
				$titles[] = $template['title'];
			}
		}
		return $titles;
	}

	/**
	 * Fetch wikitext for up to any number of titles, batched.
	 *
	 * @param string[] $prefixedTitles
	 * @return array<string,array{text:string,revid:int}> keyed by the title the
	 *         API echoed back, which may differ from the requested one when a
	 *         redirect or normalisation was applied
	 */
	public function getWikitext( array $prefixedTitles ): array {
		$result = [];

		foreach ( array_chunk( $prefixedTitles, self::TITLES_PER_REQUEST ) as $chunk ) {
			$data = $this->request( [
				'action' => 'query',
				'titles' => implode( '|', $chunk ),
				'prop' => 'revisions',
				'rvprop' => 'content|ids',
				'rvslots' => 'main',
			] );

			foreach ( $data['query']['pages'] ?? [] as $page ) {
				if ( isset( $page['missing'] ) || !isset( $page['revisions'][0] ) ) {
					continue;
				}
				$revision = $page['revisions'][0];
				$content = $revision['slots']['main']['content'] ?? null;
				if ( $content === null ) {
					continue;
				}
				$result[$page['title']] = [
					'text' => $content,
					'revid' => (int)( $revision['revid'] ?? 0 ),
				];
			}
		}

		return $result;
	}

	/**
	 * Current upstream revision ids, for deciding what a sync needs to refetch.
	 *
	 * @param string[] $prefixedTitles
	 * @return array<string,int> title => lastrevid
	 */
	public function getLastRevisionIds( array $prefixedTitles ): array {
		$result = [];

		foreach ( array_chunk( $prefixedTitles, self::TITLES_PER_REQUEST ) as $chunk ) {
			$data = $this->request( [
				'action' => 'query',
				'titles' => implode( '|', $chunk ),
				'prop' => 'info',
			] );

			foreach ( $data['query']['pages'] ?? [] as $page ) {
				if ( !isset( $page['missing'] ) && isset( $page['lastrevid'] ) ) {
					$result[$page['title']] = (int)$page['lastrevid'];
				}
			}
		}

		return $result;
	}

	private function request( array $params ): array {
		$params['format'] = 'json';
		$params['formatversion'] = 2;

		$url = $this->apiUrl . '?' . http_build_query( $params );

		$request = $this->httpRequestFactory->create( $url, [
			'method' => 'GET',
			'timeout' => 30,
			'userAgent' => $this->userAgent,
		], __METHOD__ );

		$status = $request->execute();
		if ( !$status->isOK() ) {
			throw new RuntimeException(
				'Upstream API request failed: ' . $status->getMessage( false, false, 'en' )->text()
			);
		}

		$data = json_decode( $request->getContent(), true );
		if ( !is_array( $data ) ) {
			throw new RuntimeException( 'Upstream API returned a response that was not JSON' );
		}
		if ( isset( $data['error'] ) ) {
			throw new RuntimeException(
				'Upstream API error: ' . ( $data['error']['info'] ?? $data['error']['code'] ?? 'unknown' )
			);
		}

		return $data;
	}
}
