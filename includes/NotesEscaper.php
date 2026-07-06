<?php

namespace MediaWiki\Extension\ReceiptScanner;

/**
 * Encode user-typed text for storage as a template parameter value:
 * `|`, `{{`, `}}`, `[[`, `]]` become numeric entities that render back
 * to the originals. Applied at save (PageForms::WritePageData) and at
 * search (LedgerStore notes filter) so LIKE matches byte-for-byte.
 */
class NotesEscaper {

	private const REPLACEMENTS = [
		'|' => '&#124;',
		'{{' => '&#123;&#123;',
		'}}' => '&#125;&#125;',
		'[[' => '&#91;&#91;',
		']]' => '&#93;&#93;',
	];

	/** NOT idempotent across calls — never encode twice. */
	public static function encode( string $value ): string {
		return strtr( $value, self::REPLACEMENTS );
	}
}
