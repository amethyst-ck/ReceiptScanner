<?php

namespace MediaWiki\Extension\ReceiptScanner\Hooks;

use MediaWiki\Installer\Hook\LoadExtensionSchemaUpdatesHook;

/**
 * Registers the `receipt_scanner_queue` table with MediaWiki's installer
 * / `update.php`.
 */
class SchemaHooks implements LoadExtensionSchemaUpdatesHook {

	/** @inheritDoc */
	public function onLoadExtensionSchemaUpdates( $updater ) {
		$sqlDir = dirname( __DIR__, 2 ) . '/sql';
		$updater->addExtensionTable(
			'receipt_scanner_queue',
			"$sqlDir/tables.sql"
		);
	}
}
