<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\CurrencySymbol;
use MediaWiki\Extension\ReceiptScanner\LedgerRow;
use MediaWiki\Html\Html;

/**
 * Print-friendly Schedule C-style P&L for Special:Ledger: filter range
 * plus categorized income totals, categorized expense totals, and net.
 */
readonly class LedgerSummaryRenderer {
	use MsgTrait;

	public function __construct(
		private IContextSource $context,
		private string $systemCurrency
	) {
	}

	/**
	 * @param array $serializedFilters URL query array reproducing the
	 *   current filter set (for the back-to-detail and CSV links).
	 */
	public function render( array $rows, array $filters, array $serializedFilters ): void {
		$out = $this->context->getOutput();
		$cur = $this->systemCurrency;

		$expenseByCat = [];
		$incomeByCat = [];
		$expenseTotal = 0.0;
		$incomeTotal = 0.0;
		$uncat = $this->msg( 'receiptscanner-ledger-rollup-uncategorised' )->text();
		foreach ( $rows as $r ) {
			$amt = LedgerRow::systemAmount( $r );
			$cat = $r['category'] !== null && $r['category'] !== ''
				? $r['category'] : $uncat;
			if ( $r['kind'] === 'income' ) {
				$incomeByCat[$cat] = ( $incomeByCat[$cat] ?? 0 ) + $amt;
				$incomeTotal += $amt;
			} else {
				$expenseByCat[$cat] = ( $expenseByCat[$cat] ?? 0 ) + $amt;
				$expenseTotal += $amt;
			}
		}
		ksort( $expenseByCat );
		ksort( $incomeByCat );

		$rangeLabel = ( $filters['from'] && $filters['to'] )
			? $filters['from'] . ' — ' . $filters['to']
			: $this->msg( 'receiptscanner-ledger-range-all' )->text();

		$pageTitle = $this->context->getTitle();
		$listUrl = $pageTitle->getLocalURL( [ 'view' => 'list' ] + $serializedFilters );
		$csvUrl = $pageTitle->getLocalURL( [ 'format' => 'csv' ] + $serializedFilters );

		$out->addHTML( Html::rawElement( 'div',
			[ 'class' => 'rs-ledger-summary-toolbar' ],
			Html::element( 'a', [
				'href' => $listUrl,
				'class' => 'mw-ui-button',
			], $this->msg( 'receiptscanner-ledger-back-to-detail' )->text() ) . ' '
			. Html::element( 'a', [
				'href' => $csvUrl,
				'class' => 'mw-ui-button',
			], $this->msg( 'receiptscanner-ledger-export-csv' )->text() ) . ' '
			. Html::element( 'a', [
				'href' => 'javascript:window.print()',
				'class' => 'mw-ui-button',
			], $this->msg( 'receiptscanner-ledger-print' )->text() )
		) );

		$out->addHTML( Html::rawElement( 'div', [ 'class' => 'rs-ledger-summary-doc' ],
			Html::element( 'h2', [], $this->msg( 'receiptscanner-ledger-summary-heading' )->text() )
			. Html::element( 'p', [ 'class' => 'rs-ledger-summary-range' ], $rangeLabel )
			. $this->summaryGroup(
				$this->msg( 'receiptscanner-ledger-summary-income' )->text(),
				$this->msg( 'receiptscanner-ledger-summary-total-income' )->text(),
				$incomeByCat, $incomeTotal, $cur
			)
			. $this->summaryGroup(
				$this->msg( 'receiptscanner-ledger-summary-expenses' )->text(),
				$this->msg( 'receiptscanner-ledger-summary-total-expenses' )->text(),
				$expenseByCat, $expenseTotal, $cur
			)
			. Html::rawElement( 'table', [ 'class' => 'rs-ledger-summary-net' ],
				Html::rawElement( 'tr', [],
					Html::element( 'th', [], $this->msg( 'receiptscanner-ledger-summary-net-income' )->text() )
					. Html::element( 'td', [ 'class' => 'rs-ledger-amount' ],
						CurrencySymbol::format( $incomeTotal - $expenseTotal, $cur ) )
				)
			)
		) );
	}

	private function summaryGroup(
		string $heading, string $totalLabel, array $byCat, float $total, string $cur
	): string {
		if ( !$byCat ) {
			return Html::rawElement( 'section', [],
				Html::element( 'h3', [], $heading )
				. Html::element( 'p', [], $this->msg( 'receiptscanner-ledger-summary-none' )->text() )
			);
		}
		$body = '';
		foreach ( $byCat as $cat => $sum ) {
			$body .= Html::rawElement( 'tr', [],
				Html::element( 'td', [], (string)$cat )
				. Html::element( 'td', [ 'class' => 'rs-ledger-amount' ],
					CurrencySymbol::format( $sum, $cur ) )
			);
		}
		$body .= Html::rawElement( 'tr', [ 'class' => 'rs-ledger-summary-total' ],
			Html::element( 'td', [], $totalLabel )
			. Html::element( 'td', [ 'class' => 'rs-ledger-amount' ],
				CurrencySymbol::format( $total, $cur ) )
		);
		return Html::rawElement( 'section', [],
			Html::element( 'h3', [], $heading )
			. Html::rawElement( 'table',
				[ 'class' => 'rs-ledger-summary-group' ], $body )
		);
	}
}
