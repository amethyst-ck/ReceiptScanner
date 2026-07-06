<?php

namespace MediaWiki\Extension\ReceiptScanner;

/**
 * Small helpers for reading a ledger result row (the associative arrays
 * produced by LedgerStore).
 */
final class LedgerRow {

	/**
	 * The row's amount in the system currency: total_system when the
	 * receipt carried an exchange rate, else the original total.
	 *
	 * @param array<string,mixed> $r
	 */
	public static function systemAmount( array $r ): float {
		return (float)( $r['total_system'] ?? $r['total'] ?? 0 );
	}

	/** Message key for the row's Type label (Expense / Income). */
	public static function kindLabelKey( string $kind ): string {
		return $kind === 'income'
			? 'receiptscanner-ledger-row-income'
			: 'receiptscanner-ledger-row-expense';
	}
}
