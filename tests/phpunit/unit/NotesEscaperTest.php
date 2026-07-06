<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\NotesEscaper;
use MediaWikiUnitTestCase;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\NotesEscaper
 */
class NotesEscaperTest extends MediaWikiUnitTestCase {

	public function testPlainTextUnchanged(): void {
		$this->assertSame(
			'just a normal note',
			NotesEscaper::encode( 'just a normal note' )
		);
	}

	public function testPipeEncoded(): void {
		$this->assertSame(
			'hello&#124;world',
			NotesEscaper::encode( 'hello|world' )
		);
	}

	public function testDoubleBracesEncoded(): void {
		$this->assertSame(
			'see &#123;&#123;Other&#125;&#125; for more',
			NotesEscaper::encode( 'see {{Other}} for more' )
		);
	}

	public function testWikilinkEncoded(): void {
		$this->assertSame(
			'cf &#91;&#91;Foo&#93;&#93;',
			NotesEscaper::encode( 'cf [[Foo]]' )
		);
	}

	public function testAllAtOnce(): void {
		// Worst-case attack: a string with all five special sequences.
		$this->assertSame(
			'a&#124;b&#123;&#123;c&#125;&#125;d&#91;&#91;e&#93;&#93;',
			NotesEscaper::encode( 'a|b{{c}}d[[e]]' )
		);
	}

	public function testRoundTripIsByteEqualForLikeSearch(): void {
		// The whole point: a search query encoded the same way as the
		// stored value will LIKE-match it.
		$raw = 'hotel|conf room';
		$stored = NotesEscaper::encode( $raw );
		$queryEncoded = NotesEscaper::encode( $raw );
		$this->assertSame( $stored, $queryEncoded );
		$this->assertStringContainsString( $queryEncoded, $stored );
	}

	public function testSingleBracesUntouched(): void {
		// Only DOUBLE braces are dangerous to the template parser.
		$this->assertSame( '{lonely}', NotesEscaper::encode( '{lonely}' ) );
	}

	public function testSingleBracketsUntouched(): void {
		// Only double brackets create wikilinks.
		$this->assertSame( '[lonely]', NotesEscaper::encode( '[lonely]' ) );
	}

	public function testTripleBracesEncodesAsTwoPlusOne(): void {
		// `{{{` is `{{` (encoded) + bare `{` (untouched).
		$this->assertSame(
			'&#123;&#123;{',
			NotesEscaper::encode( '{{{' )
		);
	}

	public function testEmpty(): void {
		$this->assertSame( '', NotesEscaper::encode( '' ) );
	}
}
