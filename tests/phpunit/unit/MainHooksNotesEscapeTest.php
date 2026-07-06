<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\Hooks\MainHooks;
use MediaWikiUnitTestCase;

/**
 * Covers MainHooks::encodeFreeTextValues — the value-level encoding
 * pass (raw request values located verbatim and encoded, closing the
 * newline+`|name=` smuggling vector) and the notes-regex fallback used
 * when a raw value is not found in the composed wikitext.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Hooks\MainHooks
 */
class MainHooksNotesEscapeTest extends MediaWikiUnitTestCase {

	// ----- value pass: raw request values available -----

	public function testPipeInPayeeIsEncoded(): void {
		$payee = 'Evil Corp | totally legit';
		$content = "{{Expense\n|queue_id=7\n|payee=$payee\n|notes=x\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [ 'payee' => $payee ] );
		$this->assertStringContainsString( '|payee=Evil Corp &#124; totally legit', $out );
		$this->assertStringContainsString( "\n|queue_id=7\n", $out );
	}

	public function testParamSmugglingViaPayeeIsNeutralized(): void {
		// A crafted receipt can put newline+`|queue_id=` into the parsed
		// payee. The full raw value is replaced, so the fake param is
		// encoded and the real queue_id (7) is untouched.
		$payee = "Evil\n|queue_id=9";
		$content = "{{Expense\n|queue_id=7\n|payee=$payee\n|notes=x\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [ 'payee' => $payee ] );
		$this->assertStringContainsString( "|payee=Evil\n&#124;queue_id=9", $out );
		$this->assertStringContainsString( "\n|queue_id=7\n", $out );
		$this->assertStringNotContainsString( "\n|queue_id=9", $out );
	}

	public function testFakeParamLineInNotesIsNeutralized(): void {
		// Formerly the residual bypass: a notes line mimicking a real
		// param read as a boundary. With the raw value available, the
		// whole value is encoded.
		$notes = "hello\n|category=Injected";
		$content = "{{Expense\n|notes=$notes\n|category=Food\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [ 'notes' => $notes ] );
		$this->assertStringContainsString( "|notes=hello\n&#124;category=Injected", $out );
		$this->assertStringContainsString( "\n|category=Food\n", $out );
		$this->assertStringNotContainsString( '&#124;category=Food', $out );
	}

	public function testCrlfValueMatchesLfComposedContent(): void {
		$notes = "a | b\r\nsecond";
		$content = "{{Expense\n|notes=a | b\nsecond\n|category=Food\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [ 'notes' => $notes ] );
		$this->assertStringContainsString( "|notes=a &#124; b\nsecond", $out );
	}

	public function testTemplateSyntaxInValueIsEncoded(): void {
		$notes = 'danger {{Evil}} and [[link]]';
		$content = "{{Expense\n|notes=$notes\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [ 'notes' => $notes ] );
		$this->assertStringContainsString(
			'danger &#123;&#123;Evil&#125;&#125; and &#91;&#91;link&#93;&#93;',
			$out
		);
	}

	public function testNonStringAndCleanValuesAreSkipped(): void {
		$content = "{{Expense\n|file=A.pdf\n|payee=Plain payee\n|notes=clean\n|category=Food\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [
			'file' => [ 'A.pdf' ],
			'payee' => 'Plain payee',
			'notes' => 'clean',
		] );
		$this->assertSame( $content, $out );
	}

	// ----- fallback: raw values unavailable / not found verbatim -----

	public function testFallbackMultilinePlainTextFullyEncoded(): void {
		$content = "{{Expense\n|amount=5\n|notes=line one\nline two | with a pipe\n|category=Food\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [] );
		$this->assertStringContainsString( 'line two &#124; with a pipe', $out );
		$this->assertStringContainsString( "\n|category=Food\n", $out );
	}

	public function testFallbackStrayPipeLineDoesNotBreakOut(): void {
		$content = "{{Expense\n|amount=5\n|notes=hello\n|not a real param\n|category=Injected\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [] );
		$this->assertStringContainsString( '&#124;not a real param', $out );
		$this->assertStringContainsString( "\n|category=Injected\n", $out );
		$this->assertStringNotContainsString( '&#124;category=Injected', $out );
	}

	public function testFallbackIsNoopAfterValuePass(): void {
		$notes = 'a | b';
		$content = "{{Expense\n|notes=$notes\n|category=Food\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [ 'notes' => $notes ] );
		// Encoding ran in the value pass; the fallback must not double-encode.
		$this->assertStringContainsString( '|notes=a &#124; b', $out );
		$this->assertStringNotContainsString( '&amp;', $out );
	}

	public function testFallbackNotesIsLastParamBeforeClose(): void {
		$content = "{{Expense\n|amount=5\n|notes=first\nsecond | third\n}}";
		$out = MainHooks::encodeFreeTextValues( $content, [] );
		$this->assertStringContainsString( "second &#124; third\n}}", $out );
	}

	public function testPlainContentUnchanged(): void {
		$content = "{{Expense\n|notes=simple note\n|category=Food\n}}";
		$this->assertSame( $content, MainHooks::encodeFreeTextValues( $content, [] ) );
	}
}
