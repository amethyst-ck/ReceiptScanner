<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Special\LedgerCsvExporter;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\LedgerCsvExporter
 */
class LedgerCsvExporterTest extends MediaWikiUnitTestCase {

	/**
	 * Capture the CSV body that stream() writes to php://output.
	 * OutputPage::disable() is the only OutputPage method LedgerCsvExporter
	 * touches, so a stubbed mock is enough.
	 */
	private function streamCapture( array $rows, string $currency = 'USD' ): string {
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->once() )->method( 'disable' );
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'msg' )->willReturnCallback(
			fn ( $key, ...$params ) => $this->mockMessage( $key, $params )
		);
		$out->method( 'getContext' )->willReturn( $ctx );
		$exporter = new LedgerCsvExporter( $out, $currency );

		ob_start();
		// header() emits "headers already sent" warnings under PHPUnit's
		// already-bootstrapped output buffer; suppress them — they're a
		// runtime artifact, not a test failure.
		@$exporter->stream( $rows );
		return (string)ob_get_clean();
	}

	/**
	 * Chainable Message mock whose text() echoes the key (plus any
	 * constructor params in parens), so header assertions can match on
	 * message keys — no i18n loaded in unit tests.
	 */
	private function mockMessage( string $key, array $params = [] ): Message {
		$rendered = $params
			? $key . ' (' . implode( ',', $params ) . ')'
			: $key;
		$m = $this->createMock( Message::class );
		$m->method( 'text' )->willReturn( $rendered );
		$m->method( 'parse' )->willReturn( $rendered );
		$m->method( 'plain' )->willReturn( $rendered );
		$m->method( 'escaped' )->willReturn( $rendered );
		foreach ( [ 'numParams', 'params', 'rawParams', 'plaintextParams', 'inContentLanguage', 'title' ] as $chain ) {
			$m->method( $chain )->willReturnSelf();
		}
		return $m;
	}

	private function sampleRow( array $overrides = [] ): array {
		return $overrides + [
			'kind' => 'expense',
			'id' => 1,
			'page' => 'Expense:100000001',
			'date' => '2026-05-01',
			'party' => 'Acme Corp',
			'total' => '120.00',
			'currency' => 'USD',
			'total_system' => '120.00',
			'category' => 'Travel',
			'assignee' => 'alice',
		];
	}

	public function testHeaderRow(): void {
		$csv = $this->streamCapture( [], 'EUR' );
		$lines = explode( "\n", $csv );
		// First (and only) line is the header. text() echoes the message
		// key, so the header carries the eight column keys in order.
		$this->assertSame(
			'receiptscanner-ledger-csv-date,receiptscanner-ledger-csv-type,'
			. 'receiptscanner-ledger-csv-party,"receiptscanner-ledger-csv-total (EUR)",'
			. 'receiptscanner-ledger-csv-original-total,'
			. 'receiptscanner-ledger-csv-original-currency,'
			. 'receiptscanner-ledger-csv-category,receiptscanner-ledger-csv-entry',
			rtrim( $lines[0] )
		);
	}

	public function testRowsAfterHeader(): void {
		$csv = $this->streamCapture( [
			$this->sampleRow(),
			$this->sampleRow( [ 'kind' => 'income', 'party' => 'Customer', 'total' => '500.00', 'total_system' => '500.00' ] ),
		] );
		$lines = array_values( array_filter( explode( "\n", $csv ) ) );
		$this->assertCount( 3, $lines, 'header + 2 data rows' );
		// Type column emits the localized label; the key-echoing mock
		// returns the message key.
		$this->assertStringContainsString( '2026-05-01,receiptscanner-ledger-row-expense,"Acme Corp",120.00,120.00,USD,Travel,Expense:100000001', $lines[1] );
		$this->assertStringContainsString( '2026-05-01,receiptscanner-ledger-row-income,Customer,500.00,500.00,USD,Travel', $lines[2] );
	}

	public function testTotalSystemFallsBackToTotalWhenEmpty(): void {
		// Older rows that pre-date the total_system column.
		$row = $this->sampleRow( [ 'total_system' => null, 'total' => '42.00' ] );
		$csv = $this->streamCapture( [ $row ] );
		$dataLine = explode( "\n", $csv )[1];
		// Column 4 is "Total (currency)" — should pull from total when system is null.
		$this->assertStringContainsString( ',42.00,42.00,USD,', $dataLine );
	}

	public function testNullFieldsBecomeEmptyStrings(): void {
		$row = $this->sampleRow( [
			'date' => null, 'party' => null, 'currency' => null,
			'category' => null, 'page' => null,
		] );
		$csv = $this->streamCapture( [ $row ] );
		$dataLine = explode( "\n", $csv )[1];
		// Leading empty field for date; Type column carries the localized
		// label (key-echoing mock returns the message key).
		$this->assertStringStartsWith( ',receiptscanner-ledger-row-expense,', $dataLine );
	}

	public function testCurrencyInHeaderReflectsConstructorArg(): void {
		$csv = $this->streamCapture( [], 'GBP' );
		$this->assertStringContainsString( 'receiptscanner-ledger-csv-total (GBP)', $csv );
	}
}
