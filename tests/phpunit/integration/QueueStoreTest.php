<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Integration;

use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWikiIntegrationTestCase;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\QueueStore
 * @group Database
 */
class QueueStoreTest extends MediaWikiIntegrationTestCase {

	private function newStore(): QueueStore {
		return new QueueStore( $this->getServiceContainer()->getConnectionProvider() );
	}

	public function testEnqueueCreatesPendingRow(): void {
		$store = $this->newStore();
		$id = $store->enqueue( 'sha1key', 'receipt.pdf', 42 );

		$row = $store->get( $id );
		$this->assertNotNull( $row );
		$this->assertSame( QueueStatus::Pending->value, $row['rsq_status'] );
		$this->assertSame( 'receipt.pdf', $row['rsq_file_name'] );
		$this->assertSame( '42', (string)$row['rsq_uploader'] );
	}

	public function testGetUnknownReturnsNull(): void {
		$this->assertNull( $this->newStore()->get( 999999 ) );
	}

	public function testFullLifecycle(): void {
		$store = $this->newStore();
		$id = $store->enqueue( 'sha1key', 'receipt.pdf', 1 );

		$store->claimForProcessing( $id );
		$this->assertSame( QueueStatus::Processing->value, $store->get( $id )['rsq_status'] );

		$store->setReady( $id, 'text-layer', [ 'text_source' => 'text-layer', 'fields' => [] ] );
		$row = $store->get( $id );
		$this->assertSame( QueueStatus::Ready->value, $row['rsq_status'] );
		$this->assertSame( 'text-layer', $row['rsq_text_source'] );
		$this->assertNotNull( $row['rsq_response'] );

		$store->setConsumed( $id, 12345 );
		$row = $store->get( $id );
		$this->assertSame( QueueStatus::Consumed->value, $row['rsq_status'] );
		$this->assertSame( '12345', (string)$row['rsq_receipt_page'] );
	}

	public function testSetFailedTruncatesLongError(): void {
		$store = $this->newStore();
		$id = $store->enqueue( 'sha1key', 'f.pdf', 1 );
		$store->setFailed( $id, str_repeat( 'x', 500 ) );

		$row = $store->get( $id );
		$this->assertSame( QueueStatus::Failed->value, $row['rsq_status'] );
		$this->assertLessThanOrEqual( 255, strlen( $row['rsq_error'] ) );
	}

	public function testGetByStatus(): void {
		$store = $this->newStore();
		$store->enqueue( 'a', 'a.pdf', 1 );
		$store->enqueue( 'b', 'b.pdf', 1 );
		$readyId = $store->enqueue( 'c', 'c.pdf', 1 );
		$store->setReady( $readyId, 'ocr', [ 'fields' => [] ] );

		$this->assertCount( 2, $store->getByStatus( QueueStatus::Pending ) );
		$this->assertCount( 1, $store->getByStatus( QueueStatus::Ready ) );
	}

	public function testDeleteNonConsumedKeepsConsumed(): void {
		$store = $this->newStore();
		$sha = 'sharedsha';
		$pendingId = $store->enqueue( $sha, 'f.pdf', 1 );
		$consumedId = $store->enqueue( $sha, 'f.pdf', 1 );
		$store->setConsumed( $consumedId, 1 );

		$store->deleteNonConsumedBySha1( $sha );

		$this->assertNull( $store->get( $pendingId ), 'pending row should be deleted' );
		$this->assertNotNull( $store->get( $consumedId ), 'consumed row should survive' );
	}

	public function testFindSavedBySha1ReturnsRowSavedToPage(): void {
		$store = $this->newStore();
		$sha = 'savedsha';
		$id = $store->enqueue( $sha, 'f.pdf', 1 );
		$store->setConsumed( $id, 4242 );

		$row = $store->findSavedBySha1( $sha );
		$this->assertNotNull( $row, 'a receipt saved to a page should be found' );
		$this->assertSame( (string)$id, (string)$row['rsq_id'] );
	}

	public function testFindSavedBySha1IgnoresDismissedRows(): void {
		// Dismiss records a consumed row with rsq_receipt_page = 0 (no page).
		// Those were never tied to a page and must not block a re-upload.
		$store = $this->newStore();
		$sha = 'dismissedsha';
		$id = $store->enqueue( $sha, 'f.pdf', 1 );
		$store->setConsumed( $id, 0 );

		$this->assertNull( $store->findSavedBySha1( $sha ) );
	}

	public function testFindSavedBySha1IgnoresActiveRows(): void {
		$store = $this->newStore();
		$sha = 'activesha';
		$store->enqueue( $sha, 'f.pdf', 1 );

		$this->assertNull( $store->findSavedBySha1( $sha ) );
	}
}
