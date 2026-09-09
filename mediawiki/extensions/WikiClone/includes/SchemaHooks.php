<?php

namespace MediaWiki\Extension\WikiClone;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$sql = __DIR__ . '/../sql/tables.sql';
		$updater->addExtensionTable( 'wikiclone_title', $sql );
		$updater->addExtensionTable( 'wikiclone_page', $sql );

		$branches = __DIR__ . '/../sql/branches.sql';
		$updater->addExtensionTable( 'wikiclone_branch', $branches );
		$updater->addExtensionTable( 'wikiclone_branch_rev', $branches );
		$updater->addExtensionTable( 'wikiclone_revision_branch', $branches );
	}
}
