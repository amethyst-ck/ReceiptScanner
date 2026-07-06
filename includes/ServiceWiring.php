<?php

use MediaWiki\Config\ServiceOptions;
use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Extension\ReceiptScanner\Services\CargoTables;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Services\LedgerStore;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarClient;
use MediaWiki\Extension\ReceiptScanner\Services\UnlinkedFilesStore;
use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\MediaWikiServices;

return [
	'ReceiptScanner.QueueStore' => static function ( MediaWikiServices $services ): QueueStore {
		return new QueueStore( $services->getConnectionProvider() );
	},

	'ReceiptScanner.SidecarClient' => static function ( MediaWikiServices $services ): SidecarClient {
		return new SidecarClient(
			$services->getHttpRequestFactory(),
			new ServiceOptions(
				SidecarClient::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},

	'ReceiptScanner.CategoryVocabulary' => static function ( MediaWikiServices $services ): CategoryVocabulary {
		return new CategoryVocabulary(
			$services->getMainWANObjectCache(),
			$services->getWikiPageFactory(),
			$services->getTitleFactory(),
			new ServiceOptions(
				CategoryVocabulary::CONSTRUCTOR_OPTIONS,
				$services->getMainConfig()
			)
		);
	},

	'ReceiptScanner.UserStore' => static function ( MediaWikiServices $services ): UserStore {
		return new UserStore(
			$services->getMainWANObjectCache(),
			$services->getConnectionProvider(),
			$services->getUserFactory()
		);
	},

	'ReceiptScanner.CargoTables' => static function ( MediaWikiServices $services ): CargoTables {
		return new CargoTables( $services->getConnectionProvider() );
	},

	'ReceiptScanner.LedgerStore' => static function ( MediaWikiServices $services ): LedgerStore {
		return new LedgerStore(
			$services->getConnectionProvider(),
			$services->getService( 'ReceiptScanner.CargoTables' )
		);
	},

	'ReceiptScanner.UnlinkedFilesStore' => static function ( MediaWikiServices $services ): UnlinkedFilesStore {
		return new UnlinkedFilesStore(
			$services->getConnectionProvider(),
			$services->getService( 'ReceiptScanner.CargoTables' )
		);
	},

	'ReceiptScanner.BulkEditService' => static function ( MediaWikiServices $services ): BulkEditService {
		return new BulkEditService(
			$services->getWikiPageFactory(),
			$services->getTitleFactory()
		);
	},
];
