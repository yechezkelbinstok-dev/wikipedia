<?php

namespace MediaWiki\Extension\WikiClone;

/**
 * Registers mw.wikibase with Scribunto.
 *
 * Wikipedia's modules assume a Wikidata client is present. Registering the
 * library is what turns "attempt to index field 'wikibase'" into either real
 * data or the graceful nil the modules already know how to handle.
 */
class ScribuntoHooks {

	/**
	 * @param string $engine
	 * @param string[] &$extraLibraries
	 */
	public function onScribuntoExternalLibraries( string $engine, array &$extraLibraries ) {
		if ( $engine === 'lua' ) {
			$extraLibraries['mw.wikibase'] = WikibaseLibrary::class;
		}
	}
}
