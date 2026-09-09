<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Extension\Scribunto\Engines\LuaCommon\LibraryBase;
use MediaWiki\MediaWikiServices;

/**
 * Exposes {@see WikidataClient} to Lua as mw.wikibase.
 *
 * The names and return shapes follow the real Wikibase client, because the
 * modules calling them are Wikipedia's own and were written against it.
 */
class WikibaseLibrary extends LibraryBase {

	private ?WikidataClient $client = null;

	public function register() {
		$interface = [
			'getEntityIdForCurrentPage' => [ $this, 'getEntityIdForCurrentPage' ],
			'getEntity' => [ $this, 'getEntity' ],
			'getLabel' => [ $this, 'getLabel' ],
			'getDescription' => [ $this, 'getDescription' ],
			'getSitelink' => [ $this, 'getSitelink' ],
			'getBestStatements' => [ $this, 'getBestStatements' ],
			'getAllStatements' => [ $this, 'getAllStatements' ],
			'entityExists' => [ $this, 'entityExists' ],
			'getEntityUrl' => [ $this, 'getEntityUrl' ],
			'renderSnak' => [ $this, 'renderSnak' ],
			'formatPropertyValues' => [ $this, 'formatPropertyValues' ],
		];

		return $this->getEngine()->registerInterface(
			__DIR__ . '/mw.wikibase.lua',
			$interface,
			[]
		);
	}

	private function client(): WikidataClient {
		$this->client ??= MediaWikiServices::getInstance()->getService( 'WikiClone.WikidataClient' );
		return $this->client;
	}

	public function getEntityIdForCurrentPage(): array {
		$title = $this->getTitle();
		$this->incrementExpensiveFunctionCount();

		return [ $title ? $this->client()->getEntityIdForTitle( $title ) : null ];
	}

	public function getEntity( $id = null ): array {
		if ( !is_string( $id ) ) {
			return [ null ];
		}
		$this->incrementExpensiveFunctionCount();

		$entity = $this->client()->getEntity( $id );

		// Lua cannot be handed a PHP list with holes, and an empty claims set
		// must arrive as a table rather than as nil.
		return [ $entity ? self::forLua( $entity ) : null ];
	}

	public function getLabel( $id = null, $language = 'en' ): array {
		if ( !is_string( $id ) ) {
			return [ null, null ];
		}
		return $this->client()->getLabel( $id, is_string( $language ) ? $language : 'en' );
	}

	public function getDescription( $id = null, $language = 'en' ): array {
		if ( !is_string( $id ) ) {
			return [ null, null ];
		}
		return $this->client()->getDescription( $id, is_string( $language ) ? $language : 'en' );
	}

	public function getSitelink( $id = null, $site = null ): array {
		if ( !is_string( $id ) ) {
			return [ null ];
		}
		return [ $this->client()->getSitelink( $id, is_string( $site ) ? $site : 'enwiki' ) ];
	}

	public function getBestStatements( $id = null, $property = null ): array {
		if ( !is_string( $id ) || !is_string( $property ) ) {
			return [ [] ];
		}
		$this->incrementExpensiveFunctionCount();

		return [ self::forLua( $this->client()->getBestStatements( $id, $property ), true ) ];
	}

	public function getAllStatements( $id = null, $property = null ): array {
		if ( !is_string( $id ) || !is_string( $property ) ) {
			return [ [] ];
		}
		$this->incrementExpensiveFunctionCount();

		return [ self::forLua( $this->client()->getAllStatements( $id, $property ), true ) ];
	}

	public function entityExists( $id = null ): array {
		return [ is_string( $id ) && $this->client()->entityExists( $id ) ];
	}

	public function getEntityUrl( $id = null ): array {
		return [ is_string( $id ) ? WikidataClient::getEntityUrl( $id ) : null ];
	}

	public function renderSnak( $snak = null ): array {
		return [ is_array( $snak ) ? $this->client()->formatSnak( $snak ) : '' ];
	}

	public function formatPropertyValues( $id = null, $property = null ): array {
		if ( !is_string( $id ) || !is_string( $property ) ) {
			return [ [ 'value' => '', 'label' => '' ] ];
		}
		$this->incrementExpensiveFunctionCount();

		return [ $this->client()->formatPropertyValues( $id, $property ) ];
	}

	/**
	 * Lua tables are 1-based, and a PHP list handed straight across arrives
	 * indexed from 0 — where Lua's own iteration will not find it.
	 *
	 * @param mixed $value
	 * @param bool $isList whether $value is a sequence rather than a map
	 * @return mixed
	 */
	private static function forLua( $value, bool $isList = false ) {
		if ( !is_array( $value ) ) {
			return $value;
		}

		$out = [];
		$index = 1;

		foreach ( $value as $key => $item ) {
			$converted = is_array( $item ) ? self::forLua( $item, array_is_list( $item ) ) : $item;
			if ( $isList ) {
				$out[$index++] = $converted;
			} else {
				$out[$key] = $converted;
			}
		}

		return $out;
	}
}
