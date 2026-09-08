<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;

/**
 * Tells sync and purge to keep their hands off pages the user has edited.
 *
 * Until branched history exists, an upstream sync that overwrote a local edit
 * would simply destroy it, and a purge that deleted such a page would destroy
 * it permanently. Both operations therefore skip any page whose newest revision
 * was not written by the importer.
 */
class LocalEditDetector {

	private RevisionLookup $revisionLookup;

	public function __construct( RevisionLookup $revisionLookup ) {
		$this->revisionLookup = $revisionLookup;
	}

	public function hasLocalEdits( Title $title ): bool {
		$revision = $this->revisionLookup->getRevisionByTitle( $title );
		if ( !$revision ) {
			// No revision at all is not something to overwrite or delete either.
			return true;
		}

		$user = $revision->getUser();

		return !$user || $user->getName() !== ArticleImporter::IMPORT_USER;
	}
}
