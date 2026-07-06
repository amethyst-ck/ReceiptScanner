<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\BulkEditService;
use MediaWiki\Request\WebRequest;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;

/**
 * Handles the bulk-edit POST on Special:Ledger: validate, dispatch to
 * BulkEditService, stash a flash summary, redirect back.
 */
readonly class LedgerBulkEditController {

	public function __construct(
		private IContextSource $context,
		private BulkEditService $bulkEdit,
		private TitleFactory $titleFactory,
		private Title $pageTitle
	) {
	}

	public function handle( WebRequest $req ): void {
		$field = $req->getRawVal( 'bulk_field' );
		$value = (string)$req->getVal( 'bulk_value', '' );
		$pages = $req->getArray( 'bulk_pages' );
		$allowed = [ 'category', 'assignee', 'party' ];
		$out = $this->context->getOutput();
		if ( !$field || !in_array( $field, $allowed, true ) || !$pages ) {
			$out->redirect( $this->pageTitle->getLocalURL() );
			return;
		}
		$user = $this->context->getUser();
		$authority = $this->context->getAuthority();
		$summary = $this->context->msg( 'receiptscanner-ledger-bulk-edit-summary' )
			->inContentLanguage()->plain();
		if ( $field === 'party' || $field === 'category' ) {
			$result = $this->applyPerKindEdit(
				$field, $pages, $value, $user, $authority, $summary );
		} else {
			$result = $this->bulkEdit->setField(
				$pages, $field, $value, $user, $authority, $summary
			);
		}
		$req->getSession()->set(
			'rs-ledger-bulkedit-flash',
			[
				'updated' => $result['updated'],
				'skipped' => $result['skipped'],
				'errors' => $result['errors'],
				'field' => $field,
				'value' => $value,
			]
		);
		// Rebuild the filter query from the rs_filter_* hidden fields so
		// the redirect lands back on the filtered view.
		$redirectQuery = [];
		foreach ( [ 'kind', 'range', 'from', 'to', 'min', 'max',
				'category', 'uncategorized', 'assignee', 'party', 'notes' ] as $k ) {
			$v = $req->getVal( "rs_filter_$k", '' );
			if ( $v !== '' ) {
				$redirectQuery[$k] = $v;
			}
		}
		$out->redirect( $this->pageTitle->getLocalURL( $redirectQuery ) );
	}

	/**
	 * "Party" and "category" are kind-specific template parameters
	 * (payee/payer, expense_category/income_category) — split the
	 * selection by kind and merge the results.
	 */
	private function applyPerKindEdit(
		string $field, array $pages, string $value, $user, $authority, string $summary
	): array {
		$byKind = [];
		$result = [ 'updated' => 0, 'skipped' => 0, 'errors' => [] ];
		foreach ( $pages as $p ) {
			$t = $this->titleFactory->newFromText( $p );
			$kind = $t ? ReceiptKind::tryFromNamespace( $t->getNamespace() ) : null;
			if ( $kind ) {
				$byKind[$kind->value][] = $p;
			} else {
				// Match the assignee path, which reports these through
				// BulkEditService — the flash counts must add up.
				$result['errors'][] = $this->context
					->msg( 'receiptscanner-bulk-error-wrong-namespace', $p )
					->inContentLanguage()->text();
			}
		}
		foreach ( $byKind as $kindValue => $batch ) {
			$kind = ReceiptKind::from( $kindValue );
			$param = $field === 'party'
				? $kind->partyColumn()
				: $kind->categoryParameter();
			$r = $this->bulkEdit->setField( $batch, $param, $value, $user, $authority, $summary );
			$result['updated'] += $r['updated'];
			$result['skipped'] += $r['skipped'];
			$result['errors'] = array_merge( $result['errors'], $r['errors'] );
		}
		return $result;
	}
}
