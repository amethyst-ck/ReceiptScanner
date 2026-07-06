<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Integration;

use File;
use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Jobs\ReceiptScanJob;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarClient;
use MediaWiki\Extension\ReceiptScanner\Services\SidecarException;
use MediaWiki\Title\Title;
use MediaWikiIntegrationTestCase;
use RepoGroup;
use RuntimeException;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Jobs\ReceiptScanJob
 * @group Database
 */
class ReceiptScanJobTest extends MediaWikiIntegrationTestCase {

	private function store(): QueueStore {
		return new QueueStore( $this->getServiceContainer()->getConnectionProvider() );
	}

	private function mockFileLookup(): void {
		$file = $this->createMock( File::class );
		$file->method( 'getLocalRefPath' )->willReturn( '/tmp/does-not-matter' );
		$repoGroup = $this->createMock( RepoGroup::class );
		$repoGroup->method( 'findFileFromKey' )->willReturn( $file );
		$this->setService( 'RepoGroup', $repoGroup );
	}

	private function setSidecar( SidecarClient $client ): void {
		$this->setService( 'ReceiptScanner.SidecarClient', $client );
	}

	private function runJob( int $rsqId ): void {
		// Construct through the service container so the RepoGroup /
		// SidecarClient mocks installed via setService() are picked up,
		// mirroring what JobFactory does with the extension.json spec.
		$services = $this->getServiceContainer();
		$job = new ReceiptScanJob(
			Title::makeTitle( NS_FILE, 'Receipt.pdf' ),
			[ 'rsq_id' => $rsqId ],
			$services->getService( 'ReceiptScanner.QueueStore' ),
			$services->getService( 'ReceiptScanner.SidecarClient' ),
			$services->getRepoGroup()
		);
		$this->assertTrue( $job->run() );
	}

	public function testReadyOutcome(): void {
		$this->mockFileLookup();
		$client = $this->createMock( SidecarClient::class );
		$client->method( 'parse' )->willReturn( [
			'text_source' => 'text-layer',
			'fields' => [ 'total' => [ 'value' => '17.20', 'source' => 'generic' ] ],
			'note' => null,
		] );
		$this->setSidecar( $client );

		$id = $this->store()->enqueue( 'sha', 'Receipt.pdf', 1 );
		$this->runJob( $id );

		$row = $this->store()->get( $id );
		$this->assertSame( QueueStatus::Ready->value, $row['rsq_status'] );
		$this->assertSame( 'text-layer', $row['rsq_text_source'] );
	}

	public function testEmptyExtractionStillReady(): void {
		$this->mockFileLookup();
		$client = $this->createMock( SidecarClient::class );
		$client->method( 'parse' )->willReturn( [
			'text_source' => 'ocr',
			'fields' => [],
			'note' => 'no fields extracted',
		] );
		$this->setSidecar( $client );

		$id = $this->store()->enqueue( 'sha', 'Receipt.pdf', 1 );
		$this->runJob( $id );

		$row = $this->store()->get( $id );
		$this->assertSame( QueueStatus::Ready->value, $row['rsq_status'],
			'empty extraction is a successful result, not a failure' );
	}

	public function testFailedOutcomeWhenSidecarThrowsGenericException(): void {
		// A non-SidecarException leaks no English to the row; the
		// underlying message lands in the server log, the user sees
		// the localised "internal error" string at render time.
		$this->mockFileLookup();
		$client = $this->createMock( SidecarClient::class );
		$client->method( 'parse' )->willThrowException( new RuntimeException( 'sidecar down' ) );
		$this->setSidecar( $client );

		$id = $this->store()->enqueue( 'sha', 'Receipt.pdf', 1 );
		$this->runJob( $id );

		$row = $this->store()->get( $id );
		$this->assertSame( QueueStatus::Failed->value, $row['rsq_status'] );
		$this->assertSame(
			QueueStore::ERROR_PREFIX . ReceiptScanJob::ERR_INTERNAL,
			$row['rsq_error']
		);
	}

	public function testFailedOutcomeCarriesSidecarErrorCode(): void {
		$this->mockFileLookup();
		$client = $this->createMock( SidecarClient::class );
		$client->method( 'parse' )->willThrowException(
			new SidecarException( SidecarClient::ERR_REQUEST, 'HTTP 503' )
		);
		$this->setSidecar( $client );

		$id = $this->store()->enqueue( 'sha', 'Receipt.pdf', 1 );
		$this->runJob( $id );

		$row = $this->store()->get( $id );
		$this->assertSame( QueueStatus::Failed->value, $row['rsq_status'] );
		$this->assertSame(
			QueueStore::ERROR_PREFIX . SidecarClient::ERR_REQUEST,
			$row['rsq_error']
		);
	}

	public function testReadyDropsRawText(): void {
		// Belt-and-suspenders against accidental persistence of OCR text:
		// even if a sidecar (current or older / third-party) includes
		// raw_text in its response, QueueStore::setReady must strip it
		// before storing rsq_response.
		$this->mockFileLookup();
		$client = $this->createMock( SidecarClient::class );
		$client->method( 'parse' )->willReturn( [
			'text_source' => 'text-layer',
			'fields' => [ 'total' => [ 'value' => '17.20', 'source' => 'generic' ] ],
			'note' => null,
			'raw_text' => 'Secret receipt OCR text that must not be persisted.',
		] );
		$this->setSidecar( $client );

		$id = $this->store()->enqueue( 'sha', 'Receipt.pdf', 1 );
		$this->runJob( $id );

		$row = $this->store()->get( $id );
		$stored = json_decode( $row['rsq_response'], true );
		$this->assertArrayNotHasKey( 'raw_text', $stored );
		$this->assertStringNotContainsString(
			'Secret receipt OCR text', $row['rsq_response']
		);
	}

	public function testNonPendingRowIsSkipped(): void {
		$this->mockFileLookup();
		$client = $this->createMock( SidecarClient::class );
		$client->expects( $this->never() )->method( 'parse' );
		$this->setSidecar( $client );

		$store = $this->store();
		$id = $store->enqueue( 'sha', 'Receipt.pdf', 1 );
		$store->setConsumed( $id, 1 );

		$this->runJob( $id );
		$this->assertSame( QueueStatus::Consumed->value, $store->get( $id )['rsq_status'] );
	}
}
