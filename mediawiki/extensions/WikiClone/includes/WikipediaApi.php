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
	 * Everything $prefixedTitle needs in order to render as it does upstream.
	 *
	 * Templates come back flattened — action=parse resolves the whole tree, so
	 * nested templates and the Lua modules behind them arrive in one call.
	 *
	 * Categories come from the same call because they are needed for a reason
	 * that is not obvious: whether a category is hidden is recorded by
	 * __HIDDENCAT__ on the category page, so without those pages MediaWiki
	 * cannot know that "Articles with short description" and the CS1
	 * maintenance categories are meant to be invisible, and prints the lot at
	 * the foot of every article.
	 *
	 * @return string[] prefixed titles, e.g. "Template:Infobox",
	 *         "Module:Citation/CS1", "Category:Use British English"
	 */
	public function getDependencies( string $prefixedTitle ): array {
		$data = $this->request( [
			'action' => 'parse',
			'page' => $prefixedTitle,
			'prop' => 'templates|categories',
			'redirects' => 1,
		] );

		$titles = [];

		foreach ( $data['parse']['templates'] ?? [] as $template ) {
			if ( isset( $template['title'] ) ) {
				$titles[] = $template['title'];
			}
		}

		foreach ( $data['parse']['categories'] ?? [] as $category ) {
			if ( isset( $category['category'] ) ) {
				$titles[] = 'Category:' . strtr( $category['category'], '_', ' ' );
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

	/**
	 * Article titles in a category, following continuation.
	 *
	 * Pre-warming a topic is the point: the first article in one pulls in its
	 * whole template tree and the tenth pulls in almost nothing, so warming a
	 * category ahead of time moves that cost off the first click.
	 *
	 * @return string[] prefixed titles
	 */
	public function getCategoryMembers( string $category, int $limit = 500 ): array {
		if ( !preg_match( '/^Category:/i', $category ) ) {
			$category = 'Category:' . $category;
		}

		$titles = [];
		$continue = [];

		do {
			$data = $this->request( [
				'action' => 'query',
				'list' => 'categorymembers',
				'cmtitle' => $category,
				'cmtype' => 'page',
				'cmlimit' => 'max',
			] + $continue );

			foreach ( $data['query']['categorymembers'] ?? [] as $member ) {
				if ( isset( $member['title'] ) ) {
					$titles[] = $member['title'];
				}
				if ( count( $titles ) >= $limit ) {
					return $titles;
				}
			}

			$continue = $data['continue'] ?? [];
		} while ( $continue );

		return $titles;
	}

	/**
	 * The upstream interwiki map: which prefixes exist and where they point.
	 *
	 * @return array[] entries as the API returns them
	 */
	public function getInterwikiMap(): array {
		$data = $this->request( [
			'action' => 'query',
			'meta' => 'siteinfo',
			'siprop' => 'interwikimap',
		] );

		return $data['query']['interwikimap'] ?? [];
	}

	/**
	 * Upstream's own search suggestions, in upstream's own order.
	 *
	 * The local index can only offer titles alphabetically, which puts
	 * "Test&set" and "Test, John" above "Testosterone". Wikipedia orders by
	 * relevance, and carries the short description and thumbnail its search
	 * box shows, so all three come from one call here.
	 *
	 * It also covers articles created since the index dump was taken, which
	 * the index cannot know about at all.
	 *
	 * @return array<int,array{title:string,description:?string,thumbnail:?string}>
	 */
	public function prefixSearch( string $term, int $limit = 10 ): array {
		$data = $this->request( [
			'action' => 'query',
			'generator' => 'prefixsearch',
			'gpssearch' => $term,
			'gpslimit' => min( $limit, 50 ),
			'prop' => 'pageprops|pageimages',
			'ppprop' => 'wikibase-shortdesc',
			'piprop' => 'thumbnail',
			'pithumbsize' => 80,
		] );

		$pages = $data['query']['pages'] ?? [];

		// The generator returns relevance order in `index`, which the page map
		// does not preserve on its own.
		usort( $pages, static fn ( $a, $b ) => ( $a['index'] ?? PHP_INT_MAX ) <=> ( $b['index'] ?? PHP_INT_MAX ) );

		$results = [];
		foreach ( $pages as $page ) {
			if ( !isset( $page['title'] ) ) {
				continue;
			}
			$results[] = [
				'title' => $page['title'],
				'description' => $page['pageprops']['wikibase-shortdesc'] ?? null,
				'thumbnail' => $page['thumbnail']['source'] ?? null,
			];
		}

		return $results;
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
