<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\CurrencySymbol;
use MediaWiki\Extension\ReceiptScanner\LedgerRow;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\CategoryVocabulary;
use MediaWiki\Extension\ReceiptScanner\Services\LedgerStore;
use MediaWiki\Extension\ReceiptScanner\Services\UserStore;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkRenderer;
use MediaWiki\Title\TitleFactory;

/**
 * Special:Ledger's list view: rollups, results table, bulk-edit form,
 * and the post-bulk flash banner. Delegates the filter form to
 * LedgerFilterFormRenderer and the P&L view to LedgerSummaryRenderer.
 *
 * Pure rendering — no request parsing, no database writes. Filter
 * parsing happens in SpecialLedger; data fetching in LedgerStore.
 */
readonly class LedgerRenderer {
	use MsgTrait;

	private const BULK_FIELD_MSGS = [
		'category' => 'receiptscanner-ledger-bulk-field-category',
		'assignee' => 'receiptscanner-ledger-bulk-field-assignee',
		'party'    => 'receiptscanner-ledger-bulk-field-party',
	];

	/** Per-page error lines shown under the bulk-edit flash banner. */
	private const FLASH_ERROR_CAP = 10;

	public function __construct(
		private IContextSource $context,
		private LinkRenderer $linkRenderer,
		private TitleFactory $titleFactory,
		private LedgerStore $query,
		private CategoryVocabulary $vocabulary,
		private UserStore $userStore,
		private string $systemCurrency
	) {
	}

	/** One-time banner after a bulk-edit POST/redirect. */
	public function renderBulkEditFlash(): void {
		$session = $this->context->getRequest()->getSession();
		$flash = $session->get( 'rs-ledger-bulkedit-flash' );
		if ( !$flash ) {
			return;
		}
		$session->remove( 'rs-ledger-bulkedit-flash' );
		$fieldLabel = isset( self::BULK_FIELD_MSGS[$flash['field']] )
			? $this->msg( self::BULK_FIELD_MSGS[$flash['field']] )->text()
			: $flash['field'];
		$parts = [
			$this->msg( 'receiptscanner-ledger-bulk-flash-main' )
				->plaintextParams( $fieldLabel )
				->numParams( $flash['updated'] )
				->text()
		];
		if ( $flash['skipped'] > 0 ) {
			$parts[] = $this->msg( 'receiptscanner-ledger-bulk-flash-skipped' )
				->numParams( $flash['skipped'] )->text();
		}
		if ( count( $flash['errors'] ) > 0 ) {
			$parts[] = $this->msg( 'receiptscanner-ledger-bulk-flash-errors' )
				->numParams( count( $flash['errors'] ) )->text();
		}
		$this->context->getOutput()->addHTML(
			Html::successBox( htmlspecialchars( implode( ', ', $parts ) . '.' ) )
		);
		if ( count( $flash['errors'] ) > 0 ) {
			$items = '';
			foreach ( array_slice( $flash['errors'], 0, self::FLASH_ERROR_CAP ) as $error ) {
				$items .= Html::element( 'li', [], (string)$error );
			}
			if ( count( $flash['errors'] ) > self::FLASH_ERROR_CAP ) {
				$items .= Html::element( 'li', [], '…' );
			}
			$this->context->getOutput()->addHTML(
				Html::rawElement( 'ul', [ 'class' => 'rs-ledger-bulk-errors' ], $items )
			);
		}
	}

	/** Default view: filter form, rollups, results table, bulk-edit form. */
	public function renderListView( array $rows, array $filters ): void {
		( new LedgerFilterFormRenderer( $this->context, $this->systemCurrency ) )
			->render( $filters );
		$this->renderResults( $rows, $filters );
	}

	/** Print-friendly Schedule C-style P&L view. */
	public function renderSummaryView( array $rows, array $filters ): void {
		( new LedgerSummaryRenderer( $this->context, $this->systemCurrency ) )
			->render( $rows, $filters, $this->serializeFilters( $filters ) );
	}

	private function renderResults( array $rows, array $filters ): void {
		$out = $this->context->getOutput();
		$pageTitle = $this->context->getTitle();
		$serialized = $this->serializeFilters( $filters );

		$csvUrl = $pageTitle->getLocalURL( [ 'format' => 'csv' ] + $serialized );
		$summaryUrl = $pageTitle->getLocalURL( [ 'view' => 'summary' ] + $serialized );
		$totals = $this->summarise( $rows );
		$cur = $this->systemCurrency;
		$out->addHTML( Html::rawElement( 'div', [ 'class' => 'rs-ledger-summary' ],
			Html::element( 'span', [],
				$this->msg( 'receiptscanner-ledger-summary-line' )
					->numParams( count( $rows ) )
					->plaintextParams(
						CurrencySymbol::format( $totals['expense'], $cur ),
						CurrencySymbol::format( $totals['income'], $cur ),
						CurrencySymbol::format( $totals['income'] - $totals['expense'], $cur )
					)
					->text()
			) . ' '
			. Html::element( 'a', [
				'href' => $summaryUrl,
				'class' => 'mw-ui-button',
			], $this->msg( 'receiptscanner-ledger-view-summary' )->text() ) . ' '
			. Html::element( 'a', [
				'href' => $csvUrl,
				'class' => 'mw-ui-button',
			], $this->msg( 'receiptscanner-ledger-export-csv' )->text() )
		) );

		if ( !$rows ) {
			$out->addHTML( Html::rawElement( 'p', [],
				Html::element( 'em', [],
					$this->msg( 'receiptscanner-ledger-no-results' )->text() )
			) );
			return;
		}

		$this->renderRollups( $rows );
		$out->addHTML( $this->resultsTable( $rows, $serialized ) );
		$this->renderBulkEditForm( $serialized );
	}

	private function resultsTable( array $rows, array $serializedFilters ): string {
		$cur = $this->systemCurrency;
		$pageTitle = $this->context->getTitle();
		// Column labels double as each cell's data-label, which the
		// stacked-card layout (narrow viewports) shows inline.
		$label = [
			'date'     => $this->msg( 'receiptscanner-ledger-col-date' )->text(),
			'type'     => $this->msg( 'receiptscanner-ledger-col-type' )->text(),
			'party'    => $this->msg( 'receiptscanner-ledger-col-party' )->text(),
			'amount'   => $this->msg( 'receiptscanner-ledger-col-amount' )->text(),
			'category' => $this->msg( 'receiptscanner-ledger-col-category' )->text(),
			'assignee' => $this->msg( 'receiptscanner-ledger-col-assignee' )->text(),
			'link'     => $this->msg( 'receiptscanner-ledger-col-link' )->text(),
		];
		$body = '';
		foreach ( $rows as $r ) {
			// Icon-only link — the other columns already carry the data.
			$linkCell = '';
			if ( $r['page'] ) {
				$t = $this->titleFactory->newFromText( $r['page'] );
				if ( $t ) {
					// The column header is hidden in the stacked-card
					// layout, so the link itself must carry its meaning.
					$linkCell = $this->linkRenderer->makeLink( $t, '🔗', [
						'title' => $label['link'],
						'aria-label' => $label['link'],
					] );
				}
			}
			// data-rs-kind lets the bulk-edit JS offer only the matching
			// vocabulary when every selected row is the same kind.
			$checkbox = $r['page']
				? Html::element( 'input', [
					'type' => 'checkbox',
					'class' => 'rs-ledger-select',
					'name' => 'bulk_pages[]',
					'value' => $r['page'],
					'form' => 'rs-ledger-bulk-form',
					'data-rs-kind' => $r['kind'],
				] )
				: '';
			// Accounting view: expenses are signed negative (parens), income positive.
			$signedAmount = (float)$r['total_system'] * ( $r['kind'] === 'income' ? 1 : -1 );
			$systemAmount = CurrencySymbol::format( $signedAmount, $cur );
			$amtAttrs = [ 'class' => 'rs-ledger-amount', 'data-label' => $label['amount'] ];
			// Tooltip with the original-currency amount when it differs.
			if (
				$r['currency']
				&& strcasecmp( $r['currency'], $this->systemCurrency ) !== 0
			) {
				$amtAttrs['title'] = $this->msg(
					'receiptscanner-ledger-amount-original-tooltip'
				)->plaintextParams(
					CurrencySymbol::format( (float)$r['total'], $r['currency'] )
				)->text();
			}
			// Assignee → link to User: page (when present).
			$assigneeCell = '';
			if ( !empty( $r['assignee'] ) ) {
				$userTitle = $this->titleFactory->makeTitleSafe( NS_USER, $r['assignee'] );
				$assigneeCell = $userTitle
					? $this->linkRenderer->makeLink( $userTitle, $r['assignee'] )
					: htmlspecialchars( $r['assignee'] );
			}
			// Payee/payer and category cells self-link to this page with
			// that value as the filter, keeping the other active filters.
			$partyCell = $this->filterLinkCell(
				$r['party'], 'party', $serializedFilters,
				'receiptscanner-ledger-filter-by-party-tooltip'
			);
			$categoryCell = $this->filterLinkCell(
				$r['category'], 'category', $serializedFilters,
				'receiptscanner-ledger-filter-by-category-tooltip'
			);
			$body .= Html::rawElement( 'tr',
				[ 'class' => 'rs-ledger-row-' . $r['kind'] ],
				Html::rawElement( 'td', [ 'class' => 'rs-ledger-select-cell' ], $checkbox )
				. Html::rawElement( 'td', [ 'class' => 'rs-ledger-link-col' ], $linkCell )
				. Html::element( 'td', [ 'data-label' => $label['date'] ], $r['date'] ?? '' )
				. Html::element( 'td', [ 'data-label' => $label['type'] ],
					$this->msg( LedgerRow::kindLabelKey( $r['kind'] ) )->text() )
				. Html::rawElement( 'td', [ 'data-label' => $label['party'] ], $partyCell )
				. Html::element( 'td', $amtAttrs, $systemAmount )
				. Html::rawElement( 'td', [ 'data-label' => $label['category'] ], $categoryCell )
				. Html::rawElement( 'td', [ 'data-label' => $label['assignee'] ], $assigneeCell )
			);
		}
		// Column headers, parallel to the row-render order above:
		//   [ message key, optional class on the <th> ]
		$columns = [
			[ 'receiptscanner-ledger-col-link',     'rs-ledger-link-col' ],
			[ 'receiptscanner-ledger-col-date' ],
			[ 'receiptscanner-ledger-col-type' ],
			[ 'receiptscanner-ledger-col-party' ],
			[ 'receiptscanner-ledger-col-amount',   'rs-ledger-amount' ],
			[ 'receiptscanner-ledger-col-category' ],
			[ 'receiptscanner-ledger-col-assignee' ],
		];
		$headCells = Html::rawElement( 'th', [ 'class' => 'rs-ledger-select-cell' ],
			Html::element( 'input', [
				'type' => 'checkbox',
				'class' => 'rs-ledger-select-all',
				'title' => $this->msg( 'receiptscanner-ledger-select-all' )->text(),
			] )
		);
		foreach ( $columns as $col ) {
			$attrs = isset( $col[1] ) ? [ 'class' => $col[1] ] : [];
			$headCells .= Html::element( 'th', $attrs, $this->msg( $col[0] )->text() );
		}
		$head = Html::rawElement( 'tr', [], $headCells );
		// Wrap in a horizontally-scrolling div so the 8-column table
		// doesn't bleed past the viewport on narrow screens.
		return Html::rawElement( 'div', [ 'class' => 'rs-ledger-scroll' ],
			Html::rawElement( 'table', [ 'class' => 'wikitable rs-ledger-table' ],
				Html::rawElement( 'thead', [], $head )
				. Html::rawElement( 'tbody', [], $body )
			)
		);
	}

	/**
	 * A cell value rendered as a link that re-filters this page by that
	 * value (replacing any current filter on the same key). Empty
	 * values yield an empty cell.
	 */
	private function filterLinkCell(
		?string $value, string $filterKey, array $serializedFilters, string $tooltipMsg
	): string {
		if ( $value === null || $value === '' ) {
			return '';
		}
		return Html::element( 'a', [
			'href' => $this->context->getTitle()->getLocalURL(
				[ $filterKey => $value ] + $serializedFilters
			),
			'title' => $this->msg( $tooltipMsg )->plaintextParams( $value )->text(),
		], $value );
	}

	private function renderBulkEditForm( array $serializedFilters ): void {
		$out = $this->context->getOutput();
		$csrf = $this->context->getCsrfTokenSet();
		// Bulk-value autocomplete lists; the JS picks one per field. The
		// category lists stay per-kind so the JS can narrow them to the
		// kinds actually selected (merging only for mixed selections).
		$out->addJsConfigVars( [
			'wgReceiptScannerBulkExpenseCategories' =>
				$this->vocabulary->getPaths( ReceiptKind::Expense ),
			'wgReceiptScannerBulkIncomeCategories' =>
				$this->vocabulary->getPaths( ReceiptKind::Income ),
			'wgReceiptScannerBulkUsers' => $this->userStore->getUsernames(),
			'wgReceiptScannerBulkParties' => $this->query->getParties(),
		] );
		$fieldOptions = '';
		foreach ( self::BULK_FIELD_MSGS as $value => $msgKey ) {
			$fieldOptions .= Html::element( 'option', [ 'value' => $value ],
				$this->msg( $msgKey )->text() );
		}
		$bulkRow = Html::rawElement( 'div', [ 'class' => 'rs-ledger-bulk-row' ],
			Html::element( 'span', [ 'class' => 'rs-ledger-bulk-count' ],
				$this->msg( 'receiptscanner-ledger-bulk-count' )->numParams( 0 )->text() )
			. ' ' . Html::rawElement( 'label', [],
				$this->msg( 'receiptscanner-ledger-bulk-field-label' )->text() . ' '
				. Html::rawElement( 'select', [ 'name' => 'bulk_field' ], $fieldOptions )
			)
			. ' ' . Html::rawElement( 'label', [],
				$this->msg( 'receiptscanner-ledger-bulk-value-label' )->text() . ' '
				. Html::element( 'input', [
					'type' => 'text',
					'name' => 'bulk_value',
					'required' => '',
				] )
			)
			. ' ' . Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button mw-ui-progressive',
			], $this->msg( 'receiptscanner-ledger-bulk-apply' )->text() )
		);
		// Hidden rs_filter_* fields let the post-bulk redirect land back
		// on the same filtered view.
		$hiddenFilters = '';
		foreach ( $serializedFilters as $k => $v ) {
			$hiddenFilters .= Html::hidden( "rs_filter_$k", (string)$v );
		}
		$out->addHTML( Html::rawElement( 'form',
			[
				'id' => 'rs-ledger-bulk-form',
				'class' => 'rs-ledger-bulk-form',
				'method' => 'post',
				'action' => $this->context->getTitle()->getLocalURL(),
			],
			Html::hidden( 'bulkedit', '1' )
			. Html::hidden( 'wpEditToken', $csrf->getToken() )
			. $hiddenFilters
			. $bulkRow
		) );
	}

	private function renderRollups( array $rows ): void {
		$byCategory = [];
		$byMonth = [];
		$uncat = $this->msg( 'receiptscanner-ledger-rollup-uncategorized' )->text();
		foreach ( $rows as $r ) {
			$amt = LedgerRow::systemAmount( $r );
			$kind = $r['kind'];
			$cat = $r['category'] !== null && $r['category'] !== ''
				? $r['category'] : $uncat;
			$byCategory[$cat] ??= [ 'expense' => 0, 'income' => 0, 'count' => 0 ];
			$byCategory[$cat][$kind] += $amt;
			$byCategory[$cat]['count']++;

			$ym = $r['date'] ? substr( $r['date'], 0, 7 ) : $uncat;
			$byMonth[$ym] ??= [ 'expense' => 0, 'income' => 0, 'count' => 0 ];
			$byMonth[$ym][$kind] += $amt;
			$byMonth[$ym]['count']++;
		}
		ksort( $byCategory );
		krsort( $byMonth );

		$out = $this->context->getOutput();
		$out->addHTML( $this->rollupTable(
			$this->msg( 'receiptscanner-ledger-rollup-by-category' )->text(),
			$this->msg( 'receiptscanner-ledger-rollup-col-category' )->text(),
			$byCategory
		) );
		$out->addHTML( $this->rollupTable(
			$this->msg( 'receiptscanner-ledger-rollup-by-month' )->text(),
			$this->msg( 'receiptscanner-ledger-rollup-col-month' )->text(),
			$byMonth
		) );
	}

	private function rollupTable( string $heading, string $keyLabel, array $groups ): string {
		$cur = $this->systemCurrency;
		// data-label feeds the stacked-card layout on narrow viewports;
		// the key cell is the card title and carries no label.
		$label = [
			'expenses' => $this->msg( 'receiptscanner-ledger-rollup-col-expenses' )->text(),
			'income'   => $this->msg( 'receiptscanner-ledger-rollup-col-income' )->text(),
			'net'      => $this->msg( 'receiptscanner-ledger-rollup-col-net' )->text(),
			'count'    => $this->msg( 'receiptscanner-ledger-rollup-col-count' )->text(),
		];
		$body = '';
		foreach ( $groups as $key => $t ) {
			$net = $t['income'] - $t['expense'];
			$netClass = $net >= 0 ? 'rs-ledger-row-income' : 'rs-ledger-row-expense';
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], (string)$key )
				. Html::element( 'td', [
					'class' => 'rs-ledger-amount',
					'data-label' => $label['expenses'],
				], CurrencySymbol::format( $t['expense'], $cur ) )
				. Html::element( 'td', [
					'class' => 'rs-ledger-amount',
					'data-label' => $label['income'],
				], CurrencySymbol::format( $t['income'], $cur ) )
				. Html::element( 'td', [
					'class' => 'rs-ledger-amount ' . $netClass,
					'data-label' => $label['net'],
				], CurrencySymbol::format( $net, $cur ) )
				. Html::element( 'td', [
					'class' => 'rs-ledger-amount',
					'data-label' => $label['count'],
				], (string)$t['count'] )
			);
		}
		$head = Html::rawElement( 'tr', [],
			Html::element( 'th', [], $keyLabel )
			. Html::element( 'th', [ 'class' => 'rs-ledger-amount' ],
				$this->msg( 'receiptscanner-ledger-rollup-col-expenses' )->text() )
			. Html::element( 'th', [ 'class' => 'rs-ledger-amount' ],
				$this->msg( 'receiptscanner-ledger-rollup-col-income' )->text() )
			. Html::element( 'th', [ 'class' => 'rs-ledger-amount' ],
				$this->msg( 'receiptscanner-ledger-rollup-col-net' )->text() )
			. Html::element( 'th', [ 'class' => 'rs-ledger-amount' ],
				$this->msg( 'receiptscanner-ledger-rollup-col-count' )->text() )
		);
		return Html::rawElement( 'details', [
			'class' => 'rs-ledger-rollup',
			'open' => '',
		],
			Html::element( 'summary', [], $heading )
			. Html::rawElement( 'div', [ 'class' => 'rs-ledger-scroll' ],
				Html::rawElement( 'table', [ 'class' => 'wikitable rs-ledger-rollup-table' ],
					Html::rawElement( 'thead', [], $head )
					. Html::rawElement( 'tbody', [], $body )
				)
			)
		);
	}

	private function summarise( array $rows ): array {
		$exp = 0.0;
		$inc = 0.0;
		foreach ( $rows as $r ) {
			$amt = LedgerRow::systemAmount( $r );
			if ( $r['kind'] === 'income' ) {
				$inc += $amt;
			} else {
				$exp += $amt;
			}
		}
		return [ 'expense' => $exp, 'income' => $inc ];
	}

	/**
	 * Filter dict → URL query array. Pins absolute from/to so a "this
	 * month" CSV export stays reproducible after the month changes.
	 */
	private function serializeFilters( array $f ): array {
		$out = [];
		foreach ( [ 'kind', 'min', 'max', 'category', 'assignee', 'party', 'notes' ] as $k ) {
			// Compare against ''/null, not empty(): a legitimate max=0 (or
			// category "0") must survive into the CSV/summary/filter URLs.
			if ( ( $f[$k] ?? null ) !== null && $f[$k] !== '' ) {
				$out[$k] = $f[$k];
			}
		}
		if ( !empty( $f['uncategorized'] ) ) {
			$out['uncategorized'] = '1';
		}
		$out['range'] = 'custom';
		if ( !empty( $f['from'] ) ) {
			$out['from'] = $f['from'];
		}
		if ( !empty( $f['to'] ) ) {
			$out['to'] = $f['to'];
		}
		return $out;
	}
}
