<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\CurrencySymbol;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\CurrencySymbol
 */
class CurrencySymbolTest extends MediaWikiUnitTestCase {

	public function testKnownCodeReturnsSymbol(): void {
		$this->assertSame( '$', CurrencySymbol::forCode( 'USD' ) );
		$this->assertSame( '€', CurrencySymbol::forCode( 'EUR' ) );
		$this->assertSame( '¥', CurrencySymbol::forCode( 'JPY' ) );
		$this->assertSame( 'CA$', CurrencySymbol::forCode( 'CAD' ) );
	}

	public function testCaseInsensitive(): void {
		$this->assertSame( '$', CurrencySymbol::forCode( 'usd' ) );
		$this->assertSame( '€', CurrencySymbol::forCode( 'Eur' ) );
	}

	public function testWhitespaceTrimmed(): void {
		$this->assertSame( '£', CurrencySymbol::forCode( '  GBP  ' ) );
		$this->assertSame( '$', CurrencySymbol::forCode( "USD\n" ) );
	}

	public function testUnknownCodePassesThrough(): void {
		$this->assertSame( 'XYZ', CurrencySymbol::forCode( 'XYZ' ) );
		$this->assertSame( 'KZT', CurrencySymbol::forCode( 'kzt' ) );
	}

	public function testEmptyInput(): void {
		$this->assertSame( '', CurrencySymbol::forCode( '' ) );
		$this->assertSame( '', CurrencySymbol::forCode( '   ' ) );
	}

	public function testSharedSlashConventions(): void {
		// Currencies that share a glyph use a prefix to disambiguate.
		$this->assertSame( '¥', CurrencySymbol::forCode( 'CNY' ) );
		$this->assertSame( 'CHF', CurrencySymbol::forCode( 'CHF' ) );
		$this->assertSame( 'kr', CurrencySymbol::forCode( 'SEK' ) );
	}

	// ---- format() ----

	public function testFormatPositive(): void {
		$this->assertSame( '$5.30', CurrencySymbol::format( 5.30, 'USD' ) );
		$this->assertSame( '€100.00', CurrencySymbol::format( 100, 'EUR' ) );
	}

	public function testFormatZero(): void {
		// Zero is non-negative — no parens.
		$this->assertSame( '$0.00', CurrencySymbol::format( 0, 'USD' ) );
		$this->assertSame( '$0.00', CurrencySymbol::format( 0.0, 'USD' ) );
	}

	public function testFormatNegativeUsesParens(): void {
		$this->assertSame( '($5.30)', CurrencySymbol::format( -5.30, 'USD' ) );
		$this->assertSame( '(€10.60)', CurrencySymbol::format( -10.6, 'EUR' ) );
	}

	public function testFormatUnknownCodePassesThroughAsPrefix(): void {
		$this->assertSame( 'XYZ100.00', CurrencySymbol::format( 100, 'XYZ' ) );
		$this->assertSame( '(XYZ100.00)', CurrencySymbol::format( -100, 'XYZ' ) );
	}

	public function testFormatThousandsSeparator(): void {
		$this->assertSame( '$1,234.56', CurrencySymbol::format( 1234.56, 'USD' ) );
		$this->assertSame( '($1,234.56)', CurrencySymbol::format( -1234.56, 'USD' ) );
	}
}
