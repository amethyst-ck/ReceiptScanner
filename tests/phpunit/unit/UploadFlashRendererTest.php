<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\Special\UploadFlashRenderer;
use MediaWiki\Message\Message;
use MediaWiki\Output\OutputPage;
use MediaWiki\Request\WebRequest;
use MediaWiki\Session\Session;
use MediaWikiUnitTestCase;

/**
 * Headline matrix for the post-upload flash banner: which message the
 * renderer picks for each mix of new / re-queued / already-active /
 * already-reviewed counts, plus the trailing sentences and the
 * item-error and renamed-file lists.
 *
 * Message mocks echo "<key>|<param,param,…>" so assertions pin both
 * the chosen key and the parameters passed to it, without any i18n.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\UploadFlashRenderer
 */
class UploadFlashRendererTest extends MediaWikiUnitTestCase {

	/**
	 * Chainable Message mock whose text() returns the key followed by
	 * every parameter recorded via numParams/params/plaintextParams,
	 * e.g. "receiptscanner-upload-flash-truncated|3,5,2".
	 */
	private function mockMessage( string $key ): Message {
		$params = [];
		$m = $this->createMock( Message::class );
		foreach ( [ 'numParams', 'params', 'plaintextParams' ] as $chain ) {
			$m->method( $chain )->willReturnCallback(
				static function ( ...$args ) use ( $m, &$params ) {
					foreach ( $args as $arg ) {
						$params[] = (string)$arg;
					}
					return $m;
				}
			);
		}
		$render = static function () use ( $key, &$params ) {
			return $params ? $key . '|' . implode( ',', $params ) : $key;
		};
		$m->method( 'text' )->willReturnCallback( $render );
		$m->method( 'plain' )->willReturnCallback( $render );
		return $m;
	}

	/** Render the given session flash and return the captured HTML. */
	private function renderFlash( array $flash ): string {
		$html = '';
		$out = $this->createMock( OutputPage::class );
		$out->method( 'addHTML' )->willReturnCallback(
			static function ( $h ) use ( &$html ) {
				$html .= $h;
			}
		);

		$session = $this->createMock( Session::class );
		$session->method( 'get' )->with( 'rs-upload-flash' )->willReturn( $flash );
		$session->expects( $this->once() )
			->method( 'remove' )->with( 'rs-upload-flash' );

		$req = $this->createMock( WebRequest::class );
		$req->method( 'getSession' )->willReturn( $session );

		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getRequest' )->willReturn( $req );
		$ctx->method( 'msg' )->willReturnCallback( fn ( $key ) => $this->mockMessage( $key ) );

		( new UploadFlashRenderer( $ctx ) )->render();
		return $html;
	}

	public function testNoopWhenSessionHasNoFlash(): void {
		$out = $this->createMock( OutputPage::class );
		$out->expects( $this->never() )->method( 'addHTML' );

		$session = $this->createMock( Session::class );
		$session->method( 'get' )->willReturn( null );
		$session->expects( $this->never() )->method( 'remove' );

		$req = $this->createMock( WebRequest::class );
		$req->method( 'getSession' )->willReturn( $session );

		$ctx = $this->createMock( IContextSource::class );
		$ctx->method( 'getOutput' )->willReturn( $out );
		$ctx->method( 'getRequest' )->willReturn( $req );

		( new UploadFlashRenderer( $ctx ) )->render();
	}

	public function testAllNewUploadsUseQueuedHeadline(): void {
		$html = $this->renderFlash( [ 'queued' => 3, 'newUploads' => 3 ] );
		// $1 = queued minus intra-batch dupes (none here).
		$this->assertStringContainsString( 'receiptscanner-upload-flash-queued|3', $html );
		$this->assertStringContainsString( 'cdx-message--success', $html );
		$this->assertStringNotContainsString( 'queued-mixed', $html );
		$this->assertStringNotContainsString( 'queued-duplicates', $html );
		$this->assertStringNotContainsString( 'flash-truncated', $html );
	}

	public function testMixedNewAndKnownFilesUseMixedHeadline(): void {
		$html = $this->renderFlash( [
			'queued' => 4,
			'newUploads' => 2,
			'reEnqueued' => 2,
			'alreadyActive' => 1,
		] );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-queued-mixed|2,2,1', $html
		);
	}

	public function testDuplicatesOnlyUseDuplicatesHeadline(): void {
		$html = $this->renderFlash( [
			'queued' => 2,
			'newUploads' => 0,
			'reEnqueued' => 2,
			'alreadyActive' => 1,
		] );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-queued-duplicates|2,1', $html
		);
	}

	public function testAlreadyReviewedOnlyHeadlineWhenNothingQueued(): void {
		$html = $this->renderFlash( [
			'queued' => 0,
			'newUploads' => 0,
			'alreadyReviewed' => 4,
		] );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-already-reviewed-only|4', $html
		);
	}

	public function testTruncatedWinsOverEverythingElse(): void {
		$html = $this->renderFlash( [
			'truncated' => true,
			'queued' => 3,
			'newUploads' => 3,
			'reported' => 5,
		] );
		// $1 = received, $2 = selected, $3 = dropped.
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-truncated|3,5,2', $html
		);
		$this->assertStringContainsString( 'cdx-message--warning', $html );
		$this->assertStringNotContainsString( 'flash-queued|', $html );
	}

	public function testTrailingSentencesAppendedToHeadline(): void {
		$html = $this->renderFlash( [
			'queued' => 5,
			'newUploads' => 2,
			'reEnqueued' => 2,
			'alreadyActive' => 0,
			'intraBatchDupes' => 1,
			'alreadyReviewed' => 2,
		] );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-queued-mixed|2,2,0', $html
		);
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-intra-batch-dupes|1', $html
		);
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-already-reviewed|2', $html
		);
	}

	public function testQueuedHeadlineSubtractsIntraBatchDupes(): void {
		$html = $this->renderFlash( [
			'queued' => 3,
			'newUploads' => 3,
			'intraBatchDupes' => 1,
		] );
		$this->assertStringContainsString( 'receiptscanner-upload-flash-queued|2', $html );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-intra-batch-dupes|1', $html
		);
	}

	public function testPerFileErrorsRenderedAsEscapedList(): void {
		$html = $this->renderFlash( [
			'queued' => 1,
			'newUploads' => 1,
			'errors' => [ 'a.pdf: rejected', 'b.pdf: <b>rejected</b>' ],
		] );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-itemerrors', $html
		);
		$this->assertStringContainsString( '<li>a.pdf: rejected</li>', $html );
		// Error strings are escaped, never raw HTML.
		$this->assertStringNotContainsString( '<b>rejected</b>', $html );
	}

	public function testRenamedFilesRenderedAsList(): void {
		$html = $this->renderFlash( [
			'queued' => 1,
			'newUploads' => 1,
			'renamed' => [
				[ 'from' => 'Name.com.pdf', 'to' => 'Name_com.pdf' ],
			],
		] );
		$this->assertStringContainsString(
			'receiptscanner-upload-flash-renamed|1', $html
		);
		$this->assertStringContainsString(
			'receiptscanner-review-renamed-item|Name.com.pdf,Name_com.pdf', $html
		);
	}
}
