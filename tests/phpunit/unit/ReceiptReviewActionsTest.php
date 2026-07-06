<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use JobQueueGroup;
use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWiki\Extension\ReceiptScanner\Special\SpecialReceiptReview;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWikiUnitTestCase;
use ReflectionMethod;

/**
 * Queue-action semantics: retry idempotence, toggle-kind race gating,
 * and the failed-row action set.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Special\SpecialReceiptReview
 */
class ReceiptReviewActionsTest extends MediaWikiUnitTestCase {

	private function build( QueueStore $store ): SpecialReceiptReview {
		// pushScanJob falls back to getPageTitle() (service container)
		// when the factory yields null — always yield a Title mock.
		$titleFactory = $this->createMock( TitleFactory::class );
		$titleFactory->method( 'makeTitleSafe' )
			->willReturn( $this->createMock( Title::class ) );
		return new SpecialReceiptReview(
			$store,
			$this->createMock( JobQueueGroup::class ),
			$titleFactory
		);
	}

	private function call( SpecialReceiptReview $page, string $method, ...$args ) {
		$m = new ReflectionMethod( $page, $method );
		$m->setAccessible( true );
		return $m->invoke( $page, ...$args );
	}

	private function failedRow(): array {
		return [
			'rsq_id' => 7,
			'rsq_status' => QueueStatus::Failed->value,
			'rsq_file_sha1' => 'abc',
			'rsq_file_name' => 'r.pdf',
			'rsq_uploader' => 1,
			'rsq_kind' => 'expense',
			'rsq_error' => '',
		];
	}

	public function testRetrySkipsWhenActiveRowCoversTheFile(): void {
		$store = $this->createMock( QueueStore::class );
		$store->method( 'get' )->willReturn( $this->failedRow() );
		$store->method( 'findActiveBySha1' )
			->willReturn( [ 'rsq_id' => 99 ] );
		$store->expects( $this->never() )->method( 'enqueue' );
		$this->call( $this->build( $store ), 'retry', 7 );
	}

	public function testRetryClonesWhenNoActiveRow(): void {
		$store = $this->createMock( QueueStore::class );
		$store->method( 'get' )->willReturn( $this->failedRow() );
		$store->method( 'findActiveBySha1' )->willReturn( null );
		$store->expects( $this->once() )->method( 'enqueue' )
			->with( 'abc', 'r.pdf', 1, ReceiptKind::Expense )
			->willReturn( 8 );
		$this->call( $this->build( $store ), 'retry', 7 );
	}

	public function testToggleKindDoesNothingWhenResetLosesTheRace(): void {
		$row = $this->failedRow();
		$row['rsq_status'] = QueueStatus::Ready->value;
		$store = $this->createMock( QueueStore::class );
		$store->method( 'get' )->willReturn( $row );
		$store->method( 'resetToPending' )->willReturn( false );
		$store->expects( $this->never() )->method( 'setKind' );
		$this->call( $this->build( $store ), 'toggleKind', 7 );
	}

	public function testToggleKindFlipsOnceWhenResetWins(): void {
		$row = $this->failedRow();
		$row['rsq_status'] = QueueStatus::Ready->value;
		$store = $this->createMock( QueueStore::class );
		$store->method( 'get' )->willReturn( $row );
		$store->method( 'resetToPending' )->willReturn( true );
		$store->expects( $this->once() )->method( 'setKind' )
			->with( 7, ReceiptKind::Income );
		$this->call( $this->build( $store ), 'toggleKind', 7 );
	}
}
