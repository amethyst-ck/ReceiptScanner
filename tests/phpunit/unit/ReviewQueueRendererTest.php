<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Special\ReviewQueueRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;

/**
 * Unit coverage for ReviewQueueRenderer's testable helpers.
 *
 * The bulk of the class is render code that needs a full request
 * context; tested via integration. This file pins the pure logic:
 *
 *   - translateError() — the three branches that decide whether a
 *     stored rsq_error value gets translated, falls through as legacy
 *     English, or falls through because the prefix is present but the
 *     code is unknown to the renderer.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\ReviewQueueRenderer::translateError
 */
class ReviewQueueRendererTest extends MediaWikiUnitTestCase {

	/**
	 * Build a ReviewQueueRenderer over a mock context whose msg()
	 * records the key it was called with and returns a Message whose
	 * text() is the key itself — that lets the assertions verify both
	 * "we translated" and "we asked for the right key" in one assertion.
	 */
	private function build( array &$msgCalls = [] ): ReviewQueueRenderer {
		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'msg' )->willReturnCallback(
			function ( $key ) use ( &$msgCalls ) {
				$msgCalls[] = $key;
				$message = $this->createMock( Message::class );
				$message->method( 'text' )->willReturn( $key );
				return $message;
			}
		);
		return new ReviewQueueRenderer(
			$ctx,
			$this->createMock( QueueStore::class ),
			$this->createMock( TitleFactory::class ),
			$this->createMock( Title::class )
		);
	}

	public function testLegacyStringWithoutPrefixReturnsUnchanged(): void {
		// Rows written before the rserror|<code> scheme existed (and
		// any future Throwable getMessage() that didn't go through the
		// known catch paths) should display verbatim.
		$msgCalls = [];
		$renderer = $this->build( $msgCalls );
		$this->assertSame(
			'sidecar down: HTTP 503',
			$renderer->translateError( 'sidecar down: HTTP 503' )
		);
		$this->assertSame( [], $msgCalls,
			'msg() should not be invoked for legacy English strings' );
	}

	public function testEmptyErrorReturnsEmpty(): void {
		// rsq_error is NULLable; the renderer coerces to '' at the call
		// site, so translateError gets ''. Should be a no-op.
		$renderer = $this->build();
		$this->assertSame( '', $renderer->translateError( '' ) );
	}

	public function testKnownCodeResolvesToLocalisedMessage(): void {
		// Happy path: ReceiptScanJob wrote rserror|filegone; the
		// renderer maps it to receiptscanner-error-file-gone and
		// returns the message text.
		$msgCalls = [];
		$renderer = $this->build( $msgCalls );
		$this->assertSame(
			'receiptscanner-error-file-gone',
			$renderer->translateError(
				QueueStore::ERROR_PREFIX . 'filegone'
			)
		);
		$this->assertSame(
			[ 'receiptscanner-error-file-gone' ],
			$msgCalls
		);
	}

	public function testAllRegisteredCodesResolveToMessageKeys(): void {
		// Sanity check that every code stored by the writer side has a
		// matching entry in ERROR_MESSAGES — protects against drift
		// where adding a new error code at the writer is forgotten at
		// the renderer.
		$codes = [
			'filegone',
			'internal',
			'unreadable',
			'request',
			'badresponse',
		];
		foreach ( $codes as $code ) {
			$msgCalls = [];
			$renderer = $this->build( $msgCalls );
			$result = $renderer->translateError( QueueStore::ERROR_PREFIX . $code );
			$this->assertNotSame(
				QueueStore::ERROR_PREFIX . $code,
				$result,
				"code '$code' should resolve to a message, not fall through"
			);
			$this->assertCount( 1, $msgCalls,
				"code '$code' should invoke msg() exactly once" );
			$this->assertStringStartsWith(
				'receiptscanner-error-',
				$msgCalls[0]
			);
		}
	}

	public function testUnknownCodeFallsThroughVerbatim(): void {
		// rserror| prefix present but suffix not in ERROR_MESSAGES.
		// Could happen if a future writer added a new code but the
		// renderer hasn't been redeployed yet. Don't crash, don't
		// translate to a bogus key — show the raw token so an
		// operator looking at the row can still tell what went wrong.
		$msgCalls = [];
		$renderer = $this->build( $msgCalls );
		$this->assertSame(
			QueueStore::ERROR_PREFIX . 'nonsense',
			$renderer->translateError( QueueStore::ERROR_PREFIX . 'nonsense' )
		);
		$this->assertSame( [], $msgCalls,
			'msg() should not be invoked for unknown codes' );
	}
}
