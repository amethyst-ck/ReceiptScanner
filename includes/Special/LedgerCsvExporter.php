<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Extension\ReceiptScanner\LedgerRow;
use MediaWiki\Output\OutputPage;

/**
 * Streams a Special:Ledger result set as CSV. Disables the output
 * pipeline and writes directly to php://output.
 */
readonly class LedgerCsvExporter {

	public function __construct(
		private OutputPage $out,
		private string $systemCurrency
	) {
	}

	/**
	 * Emit CSV headers + one row per ledger entry directly to the response,
	 * bypassing MediaWiki's normal output pipeline.
	 */
	public function stream( array $rows ): void {
		$this->out->disable();
		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="ledger.csv"' );
		$h = fopen( 'php://output', 'wb' );
		$ctx = $this->out->getContext();
		fputcsv( $h, [
			$ctx->msg( 'receiptscanner-ledger-csv-date' )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-type' )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-party' )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-total', $this->systemCurrency )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-original-total' )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-original-currency' )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-category' )->text(),
			$ctx->msg( 'receiptscanner-ledger-csv-entry' )->text(),
		] );
		foreach ( $rows as $r ) {
			$kindLabel = $ctx->msg( LedgerRow::kindLabelKey( $r['kind'] ) )->text();
			// Guard every cell against CSV formula injection. The total
			// columns are varchar user-typed form values, not trusted
			// numbers — guardAmount() keeps genuinely numeric values
			// (including negatives) raw and neutralizes everything else.
			fputcsv( $h, [
				self::guard( $r['date'] ?? '' ),
				self::guard( $kindLabel ),
				self::guard( $r['party'] ?? '' ),
				self::guardAmount( $r['total_system'] ?? $r['total'] ?? '' ),
				self::guardAmount( $r['total'] ?? '' ),
				self::guard( $r['currency'] ?? '' ),
				self::guard( $r['category'] ?? '' ),
				self::guard( $r['page'] ?? '' ),
			] );
		}
		fclose( $h );
	}

	/**
	 * Neutralize a text cell that a spreadsheet would treat as a formula.
	 * A leading `=`, `+`, `-`, `@`, tab, or CR triggers evaluation on
	 * open, so prefix such cells with a single quote.
	 */
	private static function guard( string $cell ): string {
		if ( $cell !== '' && preg_match( '/^[=+\-@\t\r]/', $cell ) ) {
			return "'" . $cell;
		}
		return $cell;
	}

	/** An amount cell: numeric values stay raw, anything else is guarded. */
	private static function guardAmount( $cell ): string {
		$cell = (string)$cell;
		return is_numeric( $cell ) ? $cell : self::guard( $cell );
	}
}
