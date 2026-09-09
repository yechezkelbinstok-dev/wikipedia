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
	private const RETRIES = 4;
	private const BACKOFF_SECONDS = 2;
	private const MAX_BACKOFF_SECONDS = 30;

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
	 * @param string[]|null &$linkedTitles filled with the prefixed titles this
	 *        page links to that exist upstream
	 * @return string[] prefixed titles, e.g. "Template:Infobox",
	 *         "Module:Citation/CS1", "Category:Use British English"
	 */
	public function getDependencies( string $prefixedTitle, ?array &$linkedTitles = null ): array {
		$data = $this->request( [
			'action' => 'parse',
			'page' => $prefixedTitle,
			'prop' => 'templates|categories|links',
			'redirects' => 1,
		] );

		// Links are not fetched — an article's links are most of Wikipedia —
		// but which of them upstream says exist is worth keeping. The title
		// index is a dump snapshot, so an article written since it was taken
		// renders as a red link here while being perfectly blue on Wikipedia,
		// and this is that answer arriving free with a call already being made.
		$linkedTitles = [];
		foreach ( $data['parse']['links'] ?? [] as $link ) {
			if ( isset( $link['title'], $link['exists'] ) ) {
				$linkedTitles[] = $link['title'];
			}
		}

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
	 * Category pages, with the one thing we want them for.
	 *
	 * Wikitext and the hidden flag come from a single query rather than one
	 * each. That halves the requests a sweep makes, which matters: asking
	 * Wikipedia twice for every one of a thousand category pages is what gets
	 * a client told to slow down.
	 *
	 * @param string[] $prefixedTitles
	 * @return array<string,array{text:string,revid:int,hidden:bool}>
	 */
	public function getCategoryPages( array $prefixedTitles ): array {
		$result = [];

		foreach ( array_chunk( $prefixedTitles, self::TITLES_PER_REQUEST ) as $chunk ) {
			$data = $this->request( [
				'action' => 'query',
				'titles' => implode( '|', $chunk ),
				'prop' => 'revisions|pageprops',
				'ppprop' => 'hiddencat',
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
					'hidden' => isset( $page['pageprops']['hiddencat'] ),
				];
			}
		}

		return $result;
	}

	/**
	 * Which of these categories upstream treats as hidden.
	 *
	 * Wikipedia sets the flag with __HIDDENCAT__, but almost never writes the
	 * magic word itself: the category page says {{Wikipedia category|hidden=yes}}
	 * and the template emits it. Reading the resulting page property is
	 * therefore the only way to learn the answer without importing a template
	 * tree for every category page we touch.
	 *
	 * @param string[] $prefixedTitles
	 * @return string[] the subset that is hidden, as the API spells them
	 */
	public function getHiddenCategories( array $prefixedTitles ): array {
		$hidden = [];

		foreach ( array_chunk( $prefixedTitles, self::TITLES_PER_REQUEST ) as $chunk ) {
			$data = $this->request( [
				'action' => 'query',
				'titles' => implode( '|', $chunk ),
				'prop' => 'pageprops',
				'ppprop' => 'hiddencat',
			] );

			foreach ( $data['query']['pages'] ?? [] as $page ) {
				if ( isset( $page['title'], $page['pageprops']['hiddencat'] ) ) {
					$hidden[] = $page['title'];
				}
			}
		}

		return $hidden;
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
	 * The templates Wikipedia transcludes most, most-used first.
	 *
	 * Worth having before anyone asks for them. The slow part of a cold page
	 * view is not fetching the article, it is fetching the hundred templates
	 * and modules behind it, and those are the same hundred for almost every
	 * article. Importing the common core once turns most later cold views into
	 * a fetch and a render.
	 *
	 * @return string[] prefixed titles
	 */
	public function getMostTranscludedTemplates( int $limit = 500 ): array {
		$titles = [];
		$continue = [];

		do {
			$data = $this->request( [
				'action' => 'query',
				'list' => 'querypage',
				'qppage' => 'Mostlinkedtemplates',
				'qplimit' => 'max',
			] + $continue );

			foreach ( $data['query']['querypage']['results'] ?? [] as $result ) {
				if ( isset( $result['title'] ) ) {
					$titles[] = $result['title'];
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

		$request = null;
		$status = null;

		// Wikimedia answers a client that asks too fast with 429, and the right
		// response to being told to slow down is to slow down rather than to
		// fail the import. When it says how long to wait, wait that long;
		// otherwise back off further each time.
		$wait = 0;

		for ( $attempt = 0; $attempt < self::RETRIES; $attempt++ ) {
			if ( $wait > 0 ) {
				sleep( min( $wait, self::MAX_BACKOFF_SECONDS ) );
			}

			$request = $this->httpRequestFactory->create( $url, [
				'method' => 'GET',
				'timeout' => 30,
				'userAgent' => $this->userAgent,
			], __METHOD__ );

			$status = $request->execute();
			if ( $status->isOK() ) {
				break;
			}

			if ( !in_array( $request->getStatus(), [ 429, 503 ], true ) ) {
				break;
			}

			$retryAfter = (int)$request->getResponseHeader( 'Retry-After' );
			$wait = $retryAfter > 0
				? $retryAfter
				: self::BACKOFF_SECONDS << $attempt;
		}

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
