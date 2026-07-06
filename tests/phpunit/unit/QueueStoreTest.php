<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Unit tests for {@see QueueStore}'s composed logic.
 *
 * The simple DB wrappers (enqueue, setStatus, setReady, setFailed,
 * setConsumed, etc.) are thin query-builder calls and live in the
 * integration suite — they exercise real SQL. This file covers the
 * methods that compose other methods and have branches worth pinning
 * down independently of the database.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\QueueStore::reprocess
 */
class QueueStoreTest extends MediaWikiUnitTestCase {

	/**
	 * Build a QueueStore where get() and resetToPending() are
	 * mock-overridden. reprocess() drives off resetToPending()'s
	 * guarded-UPDATE result and only fetches the row when it won the
	 * transition, so the DB layer is irrelevant to the branch logic.
	 *
	 * @param bool $reset What resetToPending() returns (transition won).
	 * @param array<string,mixed>|null $getReturn What get() returns.
	 * @return QueueStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function build( bool $reset, ?array $getReturn = null ): QueueStore {
		$store = $this->getMockBuilder( QueueStore::class )
			->setConstructorArgs( [ $this->createMock( IConnectionProvider::class ) ] )
			->onlyMethods( [ 'get', 'resetToPending' ] )
			->getMock();
		$store->method( 'resetToPending' )->willReturn( $reset );
		$store->method( 'get' )->willReturn( $getReturn );
		return $store;
	}

	public function testReprocessReturnsNullWhenRowMissing(): void {
		// Guarded UPDATE matched nothing (missing row) — no fetch follows.
		$store = $this->build( false );
		$store->expects( $this->never() )->method( 'get' );

		$this->assertNull( $store->reprocess( 42 ) );
	}

	public function testReprocessReturnsNullWhenRowAlreadyPending(): void {
		// Idempotency guard: the status-guarded UPDATE excludes pending
		// rows, so resetToPending() reports no transition and reprocess()
		// returns null without fetching.
		$store = $this->build( false );
		$store->expects( $this->never() )->method( 'get' );

		$this->assertNull( $store->reprocess( 42 ) );
	}

	public function testReprocessResetsAndReturnsRowForReady(): void {
		$row = [
			'rsq_id' => 42,
			'rsq_status' => QueueStatus::Ready->value,
			'rsq_file_name' => 'receipt.pdf',
		];
		$store = $this->build( true, $row );
		$store->expects( $this->once() )
			->method( 'resetToPending' )
			->with( 42 );

		$this->assertSame( $row, $store->reprocess( 42 ) );
	}

	public function testReprocessResetsAndReturnsRowForFailed(): void {
		$row = [
			'rsq_id' => 7,
			'rsq_status' => QueueStatus::Failed->value,
			'rsq_file_name' => 'broken.pdf',
		];
		$store = $this->build( true, $row );
		$store->expects( $this->once() )
			->method( 'resetToPending' )
			->with( 7 );

		$this->assertSame( $row, $store->reprocess( 7 ) );
	}

	public function testReprocessResetsConsumedRowsToo(): void {
		// Consumed rows can be reprocessed too — the upload-flow on
		// Special:UnlinkedFiles re-enqueues files whose receipt page
		// was deleted, and Reprocess on a Consumed row revives the
		// parse for an updated sidecar. The only blocker is Pending.
		$row = [
			'rsq_id' => 99,
			'rsq_status' => QueueStatus::Consumed->value,
			'rsq_file_name' => 'old.pdf',
		];
		$store = $this->build( true, $row );
		$store->expects( $this->once() )->method( 'resetToPending' )->with( 99 );

		$this->assertSame( $row, $store->reprocess( 99 ) );
	}
}
