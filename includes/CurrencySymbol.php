<?php

namespace MediaWiki\Extension\ReceiptScanner;

/**
 * Canonical ISO 4217 code → display symbol map. Unknown codes pass
 * through unchanged (consumers typically render the code itself).
 */
class CurrencySymbol {

	private const SYMBOLS = [
		'USD' => '$',
		'CAD' => 'CA$',
		'AUD' => 'A$',
		'NZD' => 'NZ$',
		'HKD' => 'HK$',
		'SGD' => 'S$',
		'MXN' => 'MX$',
		'EUR' => '€',
		'GBP' => '£',
		'JPY' => '¥',
		'CNY' => '¥',
		'KRW' => '₩',
		'INR' => '₹',
		'RUB' => '₽',
		'TRY' => '₺',
		'BRL' => 'R$',
		'ZAR' => 'R',
		'CHF' => 'CHF',
		'SEK' => 'kr',
		'NOK' => 'kr',
		'DKK' => 'kr',
		'PLN' => 'zł',
	];

	/** Display symbol for an ISO 4217 code; unknown codes pass through. */
	public static function forCode( string $code ): string {
		$upper = strtoupper( trim( $code ) );
		return self::SYMBOLS[$upper] ?? $upper;
	}

	/**
	 * Accounting-style amount: symbol prefix, negatives in parentheses —
	 * format( -5.30, 'USD' ) → "($5.30)".
	 */
	public static function format( float $amount, string $code ): string {
		$body = self::forCode( $code ) . number_format( abs( $amount ), 2 );
		return $amount < 0 ? "($body)" : $body;
	}
}
