<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use FSFile;
use JobQueueGroup;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Special\SpecialUploadReceipt;
use MediaWiki\Message\Message;
use MediaWiki\User\User;
use MediaWikiUnitTestCase;
use RepoGroup;

/**
 * Unit coverage for SpecialUploadReceipt's testable helpers:
 *
 *   - sanitizeFilename (static) — filename normaliser that defends
 *     against MediaWiki's prohibited-extension validator.
 *   - processUpload — per-file classify-and-dispatch helper. Covered
 *     here for the SHA-based branches (rejected, intraBatchDupe,
 *     alreadyActive, reEnqueued). The new-upload branch ends in
 *     UploadFromFile + performUpload, which writes to disk and runs
 *     the wiki's edit pipeline — integration territory, unblocked
 *     once CanastaBase #167 lands.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\SpecialUploadReceipt::sanitizeFilename
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\SpecialUploadReceipt::processUpload
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\SpecialUploadReceipt::emptyUploadState
 */
class SpecialUploadReceiptTest extends MediaWikiUnitTestCase {

	// ---------- sanitizeFilename ----------

	public function testLeavesSimpleNameUntouched(): void {
		$this->assertSame(
			'plain.pdf',
			SpecialUploadReceipt::sanitizeFilename( 'plain.pdf' )
		);
	}

	public function testReplacesDotInVendorName(): void {
		// The real-world case: Name.com gets its embedded dot replaced so
		// MediaWiki doesn't reject "com" as a prohibited extension.
		$this->assertSame(
			'2025-12-15-Name_com.pdf',
			SpecialUploadReceipt::sanitizeFilename( '2025-12-15-Name.com.pdf' )
		);
	}

	public function testReplacesMultipleEmbeddedDots(): void {
		$this->assertSame(
			'a_b_c_d.pdf',
			SpecialUploadReceipt::sanitizeFilename( 'a.b.c.d.pdf' )
		);
	}

	public function testPreservesFinalExtension(): void {
		foreach ( [ 'pdf', 'jpg', 'jpeg', 'png', 'heic' ] as $ext ) {
			$this->assertSame(
				"foo_bar.$ext",
				SpecialUploadReceipt::sanitizeFilename( "foo.bar.$ext" ),
				"extension .$ext"
			);
		}
	}

	public function testFilenameWithNoDotsIsUnchanged(): void {
		$this->assertSame(
			'noext',
			SpecialUploadReceipt::sanitizeFilename( 'noext' )
		);
	}

	public function testLeadingDotIsPreserved(): void {
		// Filename that starts with a dot — strrpos finds the same dot,
		// stem is empty, no replacement happens. (Edge case; not a
		// real receipt filename, but the function shouldn't choke.)
		$this->assertSame(
			'.hidden',
			SpecialUploadReceipt::sanitizeFilename( '.hidden' )
		);
	}

	public function testTrailingDotBeforeExtension(): void {
		// "Rip Tie_ Inc..pdf" — the receipt user actually has one of
		// these. Stem is "Rip Tie_ Inc.", trailing dot in the stem
		// becomes "_", final ".pdf" stays.
		$this->assertSame(
			'Rip Tie_ Inc_.pdf',
			SpecialUploadReceipt::sanitizeFilename( 'Rip Tie_ Inc..pdf' )
		);
	}

	// ---------- emptyUploadState ----------

	public function testEmptyUploadStateHasExpectedShape(): void {
		$state = SpecialUploadReceipt::emptyUploadState();
		$this->assertSame( [], $state['errors'] );
		$this->assertSame( [], $state['renamed'] );
		$this->assertSame( 0, $state['newUploads'] );
		$this->assertSame( 0, $state['reEnqueued'] );
		$this->assertSame( 0, $state['alreadyActive'] );
		$this->assertSame( 0, $state['intraBatchDupes'] );
		$this->assertSame( 0, $state['alreadyReviewed'] );
	}

	// ---------- processUpload (SHA-based branches) ----------

	/**
	 * Write some bytes to a temp file the test owns; cleaned up in
	 * tearDown. Returns [path, sha1Base36] so tests can pre-populate
	 * seenInBatch or set up findFileFromKey mocks.
	 *
	 * @return array{0:string, 1:string}
	 */
	private function makeUploadTempFile( string $content = "fake-pdf-bytes" ): array {
		$path = tempnam( sys_get_temp_dir(), 'rs-upload-test' );
		file_put_contents( $path, $content );
		$this->tempFiles[] = $path;
		return [ $path, FSFile::getSha1Base36FromPath( $path ) ];
	}

	/** @var list<string> */
	private array $tempFiles = [];

	protected function tearDown(): void {
		foreach ( $this->tempFiles as $p ) {
			if ( file_exists( $p ) ) {
				unlink( $p );
			}
		}
		$this->tempFiles = [];
		parent::tearDown();
	}

	private function build(
		?QueueStore $queueStore = null,
		?RepoGroup $repoGroup = null,
		?JobQueueGroup $jqg = null
	): SpecialUploadReceipt {
		// Partial-mock msg() so the rejected-upload branch can localize
		// without a live SpecialPage context (no service container in
		// unit tests). All other methods stay real.
		$page = $this->getMockBuilder( SpecialUploadReceipt::class )
			->setConstructorArgs( [
				$queueStore ?? $this->createMock( QueueStore::class ),
				$jqg ?? $this->createMock( JobQueueGroup::class ),
				$repoGroup ?? $this->createMock( RepoGroup::class ),
			] )
			->onlyMethods( [ 'msg' ] )
			->getMock();
		$page->method( 'msg' )->willReturnCallback(
			fn ( $key ) => $this->mockMessage( $key )
		);
		return $page;
	}

	/** Chainable Message mock whose text() echoes the key. */
	private function mockMessage( string $key ): Message {
		$m = $this->createMock( Message::class );
		$m->method( 'text' )->willReturn( $key );
		foreach ( [ 'numParams', 'params', 'rawParams', 'plaintextParams' ] as $chain ) {
			$m->method( $chain )->willReturnSelf();
		}
		return $m;
	}

	private function makeUser( int $id = 1 ): User {
		$user = $this->createMock( User::class );
		$user->method( 'getId' )->willReturn( $id );
		return $user;
	}

	public function testProcessUploadRecordsErrorWhenUploadErrorFlagSet(): void {
		$page = $this->build();
		$state = SpecialUploadReceipt::emptyUploadState();
		$seen = [];

		// UPLOAD_ERR_PARTIAL = 3 (PHP's "only partially uploaded").
		$page->processUpload(
			[ 'name' => 'r.pdf', 'tmp_name' => null, 'error' => 3, 'size' => 0 ],
			ReceiptKind::Expense,
			$this->makeUser(),
			$seen,
			$state
		);

		$this->assertCount( 1, $state['errors'] );
		$this->assertSame( 0, $state['newUploads'] );
		$this->assertSame( 0, $state['intraBatchDupes'] );
		$this->assertSame( [], $seen );
	}

	public function testProcessUploadCountsIntraBatchDupesWhenShaSeenAlready(): void {
		[ $path, $sha ] = $this->makeUploadTempFile();
		$page = $this->build();
		$state = SpecialUploadReceipt::emptyUploadState();
		$seen = [ $sha => true ];  // pretend we already saw this content

		$page->processUpload(
			[ 'name' => 'dup.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 14 ],
			ReceiptKind::Expense,
			$this->makeUser(),
			$seen,
			$state
		);

		$this->assertSame( 1, $state['intraBatchDupes'] );
		$this->assertSame( 0, $state['newUploads'] );
		$this->assertSame( 0, $state['alreadyActive'] );
		$this->assertSame( [], $state['errors'] );
	}

	public function testProcessUploadCountsAlreadyActiveWhenFileExistsAndQueueRowExists(): void {
		[ $path, $sha ] = $this->makeUploadTempFile();
		// Existing file in the wiki AND an active queue row → no work
		// to do, just bump alreadyActive.
		$existing = $this->createMock( \File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFileFromKey' )->with( $sha )
			->willReturn( $existing );

		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'findActiveBySha1' )->with( $sha )
			->willReturn( [ 'rsq_id' => 99, 'rsq_status' => 'pending' ] );
		$queueStore->expects( $this->never() )->method( 'enqueue' );

		$jqg = $this->createMock( JobQueueGroup::class );
		$jqg->expects( $this->never() )->method( 'lazyPush' );

		$page = $this->build( $queueStore, $repoGroup, $jqg );
		$state = SpecialUploadReceipt::emptyUploadState();
		$seen = [];

		$page->processUpload(
			[ 'name' => 'r.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 14 ],
			ReceiptKind::Expense,
			$this->makeUser(),
			$seen,
			$state
		);

		$this->assertSame( 1, $state['alreadyActive'] );
		$this->assertSame( 0, $state['reEnqueued'] );
		$this->assertSame( 0, $state['newUploads'] );
		$this->assertTrue( $seen[$sha] );  // SHA recorded for later dedupe
	}

	public function testProcessUploadReEnqueuesWhenFileExistsButNoActiveQueueRow(): void {
		[ $path, $sha ] = $this->makeUploadTempFile();
		$existing = $this->createMock( \File::class );
		$existing->method( 'getName' )->willReturn( 'r.pdf' );
		$existing->method( 'getTitle' )->willReturn(
			$this->createMock( \MediaWiki\Title\Title::class )
		);

		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFileFromKey' )->with( $sha )
			->willReturn( $existing );

		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'findActiveBySha1' )->with( $sha )->willReturn( null );
		// No saved-to-page row either, so re-enqueue is the correct branch.
		$queueStore->method( 'findSavedBySha1' )->with( $sha )->willReturn( null );
		$queueStore->expects( $this->once() )
			->method( 'enqueue' )
			->with( $sha, 'r.pdf', 7, ReceiptKind::Expense )
			->willReturn( 42 );

		$jqg = $this->createMock( JobQueueGroup::class );
		$jqg->expects( $this->once() )->method( 'lazyPush' )
			->with( $this->callback( static function ( $job ): bool {
				return $job instanceof \JobSpecification
					&& $job->getParams()['rsq_id'] === 42;
			} ) );

		$page = $this->build( $queueStore, $repoGroup, $jqg );
		$state = SpecialUploadReceipt::emptyUploadState();
		$seen = [];

		$page->processUpload(
			[ 'name' => 'r.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 14 ],
			ReceiptKind::Expense,
			$this->makeUser( 7 ),
			$seen,
			$state
		);

		$this->assertSame( 1, $state['reEnqueued'] );
		$this->assertSame( 0, $state['alreadyActive'] );
		$this->assertSame( 0, $state['newUploads'] );
	}

	public function testProcessUploadCountsAlreadyReviewedWhenFileSavedToPage(): void {
		[ $path, $sha ] = $this->makeUploadTempFile();
		// File exists in the wiki, no active queue row, but a consumed row
		// tied to a saved receipt page exists. The receipt was already
		// reviewed and saved, so re-uploading must NOT re-enqueue it —
		// closing the loophole that bypassed the form-side Reprocess removal.
		$existing = $this->createMock( \File::class );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFileFromKey' )->with( $sha )
			->willReturn( $existing );

		$queueStore = $this->createMock( QueueStore::class );
		$queueStore->method( 'findActiveBySha1' )->with( $sha )->willReturn( null );
		$queueStore->method( 'findSavedBySha1' )->with( $sha )
			->willReturn( [
				'rsq_id' => 7,
				'rsq_status' => 'consumed',
				'rsq_receipt_page' => 123,
			] );
		$queueStore->expects( $this->never() )->method( 'enqueue' );

		$jqg = $this->createMock( JobQueueGroup::class );
		$jqg->expects( $this->never() )->method( 'lazyPush' );

		$page = $this->build( $queueStore, $repoGroup, $jqg );
		$state = SpecialUploadReceipt::emptyUploadState();
		$seen = [];

		$page->processUpload(
			[ 'name' => 'r.pdf', 'tmp_name' => $path, 'error' => UPLOAD_ERR_OK, 'size' => 14 ],
			ReceiptKind::Expense,
			$this->makeUser(),
			$seen,
			$state
		);

		$this->assertSame( 1, $state['alreadyReviewed'] );
		$this->assertSame( 0, $state['reEnqueued'] );
		$this->assertSame( 0, $state['alreadyActive'] );
		$this->assertSame( 0, $state['newUploads'] );
		$this->assertTrue( $seen[$sha] );  // SHA recorded for later dedupe
	}
}
