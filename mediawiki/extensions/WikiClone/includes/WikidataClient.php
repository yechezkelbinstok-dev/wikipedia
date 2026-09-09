<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Http\HttpRequestFactory;
use MediaWiki\Title\Title;
use Throwable;
use WANObjectCache;

/**
 * Just enough of a Wikidata client for the modules that expect one.
 *
 * Wikipedia's templates reach into Wikidata through mw.wikibase. Without a
 * provider that table is nil, and every module touching it dies with
 * "attempt to index field 'wikibase'" — five of them on a single article,
 * including the ones behind official-website links and sister-project boxes.
 *
 * This fetches entities from wikidata.org on demand and caches them, in the
 * same spirit as the article importer: nothing is stored up front, and what
 * gets used sticks around.
 */
class WikidataClient {

	private const ENTITY_TTL = 86400;
	private const ID_TTL = 86400;

	private HttpRequestFactory $httpRequestFactory;
	private WANObjectCache $cache;
	private string $userAgent;

	/** @var array<string,array|null> per-request memo */
	private array $entities = [];

	public function __construct(
		HttpRequestFactory $httpRequestFactory,
		WANObjectCache $cache,
		string $userAgent
	) {
		$this->httpRequestFactory = $httpRequestFactory;
		$this->cache = $cache;
		$this->userAgent = $userAgent;
	}

	/**
	 * The Wikidata item a Wikipedia article is about, e.g. "Q76" for
	 * Barack Obama.
	 */
	public function getEntityIdForTitle( Title $title ): ?string {
		return $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'wikiclone-entity-id', $title->getPrefixedDBkey() ),
			self::ID_TTL,
			function () use ( $title ) {
				$data = $this->request( 'https://en.wikipedia.org/w/api.php', [
					'action' => 'query',
					'prop' => 'pageprops',
					'ppprop' => 'wikibase_item',
					'titles' => $title->getPrefixedText(),
					'redirects' => 1,
				] );

				foreach ( $data['query']['pages'] ?? [] as $page ) {
					$id = $page['pageprops']['wikibase_item'] ?? null;
					if ( $id ) {
						return $id;
					}
				}

				// Cached as a sentinel: "no item" is an answer worth keeping,
				// or every render of such a page asks again.
				return '';
			}
		) ?: null;
	}

	/**
	 * Full entity data in the shape Wikibase hands to Lua: labels,
	 * descriptions, claims and sitelinks, keyed as the API returns them.
	 */
	public function getEntity( string $id ): ?array {
		if ( !self::isValidEntityId( $id ) ) {
			return null;
		}

		if ( array_key_exists( $id, $this->entities ) ) {
			return $this->entities[$id];
		}

		$entity = $this->cache->getWithSetCallback(
			$this->cache->makeKey( 'wikiclone-entity', $id ),
			self::ENTITY_TTL,
			function () use ( $id ) {
				$data = $this->request( 'https://www.wikidata.org/w/api.php', [
					'action' => 'wbgetentities',
					'ids' => $id,
					'props' => 'labels|descriptions|claims|sitelinks|datatype',
				] );

				$entity = $data['entities'][$id] ?? null;
				if ( !$entity || isset( $entity['missing'] ) ) {
					return [];
				}
				return $entity;
			}
		);

		$this->entities[$id] = $entity ?: null;

		return $this->entities[$id];
	}

	/**
	 * Statements for a property, preferring those Wikidata marks preferred —
	 * which is what "best" means here, and what callers of getBestStatements
	 * expect.
	 *
	 * @return array[] list of statements
	 */
	public function getBestStatements( string $id, string $property ): array {
		$entity = $this->getEntity( $id );
		$statements = $entity['claims'][strtoupper( $property )] ?? [];

		$preferred = array_values( array_filter(
			$statements,
			static fn ( $s ) => ( $s['rank'] ?? 'normal' ) === 'preferred'
		) );
		if ( $preferred ) {
			return $preferred;
		}

		return array_values( array_filter(
			$statements,
			static fn ( $s ) => ( $s['rank'] ?? 'normal' ) !== 'deprecated'
		) );
	}

	/** @return array[] every statement for a property, deprecated ones included */
	public function getAllStatements( string $id, string $property ): array {
		$entity = $this->getEntity( $id );
		return array_values( $entity['claims'][strtoupper( $property )] ?? [] );
	}

	/** @return array{0:?string,1:?string} value and the language it came from */
	public function getLabel( string $id, string $language = 'en' ): array {
		return $this->pickTerm( $this->getEntity( $id )['labels'] ?? [], $language );
	}

	/** @return array{0:?string,1:?string} value and the language it came from */
	public function getDescription( string $id, string $language = 'en' ): array {
		return $this->pickTerm( $this->getEntity( $id )['descriptions'] ?? [], $language );
	}

	public function getSitelink( string $id, string $site = 'enwiki' ): ?string {
		return $this->getEntity( $id )['sitelinks'][$site]['title'] ?? null;
	}

	/**
	 * Render a snak the way Wikibase's formatters would: a bare value for
	 * simple types, a label for a reference to another entity.
	 *
	 * Modules call this to turn raw statement data into text, and a nil here
	 * is what "attempt to call field 'renderSnak'" means from Lua.
	 */
	public function formatSnak( array $snak ): string {
		$type = $snak['snaktype'] ?? 'value';
		if ( $type === 'novalue' || $type === 'somevalue' ) {
			return '';
		}

		$value = $snak['datavalue']['value'] ?? null;

		switch ( $snak['datavalue']['type'] ?? '' ) {
			case 'string':
				return is_string( $value ) ? $value : '';

			case 'wikibase-entityid':
				$id = $value['id'] ?? null;
				if ( !$id ) {
					return '';
				}
				return $this->getLabel( $id )[0] ?? $id;

			case 'monolingualtext':
				return $value['text'] ?? '';

			case 'quantity':
				// Amounts arrive signed, e.g. "+1234".
				return ltrim( (string)( $value['amount'] ?? '' ), '+' );

			case 'time':
				return $this->formatTime( (string)( $value['time'] ?? '' ) );

			case 'globecoordinate':
				if ( isset( $value['latitude'], $value['longitude'] ) ) {
					return $value['latitude'] . ', ' . $value['longitude'];
				}
				return '';

			default:
				return is_scalar( $value ) ? (string)$value : '';
		}
	}

	/**
	 * Every value of a property, formatted and joined — what
	 * entity:formatPropertyValues() returns alongside the property's label.
	 *
	 * @return array{value:string,label:string}
	 */
	public function formatPropertyValues( string $id, string $property ): array {
		$values = [];
		foreach ( $this->getBestStatements( $id, $property ) as $statement ) {
			$formatted = $this->formatSnak( $statement['mainsnak'] ?? [] );
			if ( $formatted !== '' ) {
				$values[] = $formatted;
			}
		}

		return [
			'value' => implode( ', ', $values ),
			'label' => $this->getLabel( strtoupper( $property ) )[0] ?? $property,
		];
	}

	/**
	 * Wikidata times look like "+1961-08-04T00:00:00Z", with zeroes standing
	 * in for parts that are not known.
	 */
	private function formatTime( string $time ): string {
		if ( !preg_match( '/^([+-])(\d{4,})-(\d{2})-(\d{2})/', $time, $m ) ) {
			return $time;
		}

		[ , $sign, $year, $month, $day ] = $m;
		$year = (int)$year;

		if ( $month === '00' ) {
			$formatted = (string)$year;
		} elseif ( $day === '00' ) {
			$formatted = date( 'F Y', mktime( 0, 0, 0, (int)$month, 1, 2000 ) ) ;
			$formatted = preg_replace( '/2000/', (string)$year, $formatted );
		} else {
			$formatted = sprintf( '%d %s %d', (int)$day,
				date( 'F', mktime( 0, 0, 0, (int)$month, 1, 2000 ) ), $year );
		}

		return $sign === '-' ? $formatted . ' BCE' : $formatted;
	}

	public function entityExists( string $id ): bool {
		return $this->getEntity( $id ) !== null;
	}

	/**
	 * Whether this entity has already been loaded in this request.
	 *
	 * Wikibase counts loading an entity against the parser's expensive-call
	 * budget, but not reading one it already holds. Counting every access
	 * exhausts the budget on any article that consults Wikidata more than a
	 * hundred times, and the page ends in "too many expensive function calls".
	 */
	public function isLoaded( string $id ): bool {
		return array_key_exists( $id, $this->entities );
	}

	public static function isValidEntityId( string $id ): bool {
		return (bool)preg_match( '/^[QPL]\d+$/', $id );
	}

	public static function getEntityUrl( string $id ): ?string {
		return self::isValidEntityId( $id )
			? 'https://www.wikidata.org/wiki/' . $id
			: null;
	}

	/** @return array{0:?string,1:?string} */
	private function pickTerm( array $terms, string $language ): array {
		$term = $terms[$language] ?? $terms['en'] ?? null;
		return $term
			? [ $term['value'] ?? null, $term['language'] ?? null ]
			: [ null, null ];
	}

	private function request( string $endpoint, array $params ): array {
		$params['format'] = 'json';
		$params['formatversion'] = 2;

		try {
			$request = $this->httpRequestFactory->create(
				$endpoint . '?' . http_build_query( $params ),
				[ 'method' => 'GET', 'timeout' => 15, 'userAgent' => $this->userAgent ],
				__METHOD__
			);

			if ( !$request->execute()->isOK() ) {
				return [];
			}

			$data = json_decode( $request->getContent(), true );
			return is_array( $data ) ? $data : [];
		} catch ( Throwable $e ) {
			// Wikidata being unreachable should degrade a template, never take
			// the page down with it.
			wfLogWarning( 'WikiClone Wikidata request failed: ' . $e->getMessage() );
			return [];
		}
	}
}
