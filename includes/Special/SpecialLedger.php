<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Config\Config;
use MediaWiki\Extension\ReceiptScanner\LedgerKindFilter;
use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Services\LedgerStore;
use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\Request\WebRequest;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\TitleFactory;

/**
 * Special:Ledger — combined Expenses + Income view with date / amount /
 * category / kind filters, rollups, CSV export, Schedule-C summary
 * view, and bulk category/assignee/party edits.
 *
 * This class is the dispatcher: it parses the request, runs the query,
 * and hands off to the appropriate collaborator:
 *   - LedgerBulkEditController for the bulk-edit POST
 *   - LedgerCsvExporter for ?format=csv
 *   - LedgerRenderer for HTML (summary + list views)
 */
class SpecialLedger extends SpecialPage {

	private readonly string $systemCurrency;

	public function __construct(
		private readonly LedgerStore $query,
		private readonly BulkEditService $bulkEdit,
		private readonly CategoryVocabulary $vocabulary,
		private readonly UserStore $userStore,
		private readonly TitleFactory $titleFactory,
		Config $mainConfig
	) {
		parent::__construct( 'Ledger' );
		$this->systemCurrency = (string)$mainConfig->get( 'ReceiptScannerSystemCurrency' );
	}

	public function execute( $subPage ) {
		$this->setHeaders();
		$this->requireLogin();

		$req = $this->getRequest();
		$csrf = $this->getContext()->getCsrfTokenSet();
		if (
			$req->wasPosted()
			&& $csrf->matchToken( $req->getVal( 'wpEditToken' ) )
			&& $req->getRawVal( 'bulkedit' )
		) {
			$controller = new LedgerBulkEditController(
				$this->getContext(), $this->bulkEdit, $this->titleFactory, $this->getPageTitle()
			);
			$controller->handle( $req );
			return;
		}

		$filters = $this->parseFilters( $req );
		$rows = $this->query->run( $filters );

		if ( $req->getRawVal( 'format' ) === 'csv' ) {
			$exporter = new LedgerCsvExporter( $this->getOutput(), $this->systemCurrency );
			$exporter->stream( $rows );
			return;
		}

		$out = $this->getOutput();
		$out->addModuleStyles( 'ext.receiptscanner.ledger' );
		$out->addModules( 'ext.receiptscanner.ledger' );

		$renderer = new LedgerRenderer(
			$this->getContext(),
			$this->getLinkRenderer(),
			$this->titleFactory,
			$this->query,
			$this->vocabulary,
			$this->userStore,
			$this->systemCurrency
		);
		if ( $req->getRawVal( 'view' ) === 'summary' ) {
			$renderer->renderSummaryView( $rows, $filters );
			return;
		}
		$renderer->renderBulkEditFlash();
		$renderer->renderListView( $rows, $filters );
	}

	private function parseFilters( WebRequest $req ): array {
		$preset = $req->getRawVal( 'range', 'this_year' );
		$from = $req->getRawVal( 'from' );
		$to = $req->getRawVal( 'to' );
		if ( $preset !== 'custom' ) {
			[ $from, $to ] = LedgerStore::presetRange( $preset );
		}
		return [
			'preset' => $preset,
			'kind' => $req->getRawVal( 'kind', LedgerKindFilter::Both->value ),
			'from' => $from,
			'to' => $to,
			'min' => $req->getRawVal( 'min' ),
			'max' => $req->getRawVal( 'max' ),
			'category' => $req->getRawVal( 'category' ),
			'uncategorized' => $req->getCheck( 'uncategorized' ),
			'assignee' => $req->getRawVal( 'assignee' ),
			'party' => $req->getRawVal( 'party' ),
			'notes' => $req->getRawVal( 'notes' ),
		];
	}
}
