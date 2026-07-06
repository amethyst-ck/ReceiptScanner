<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\QueueStatus;
use MediaWiki\Extension\ReceiptScanner\Services\QueueStore;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\Expression;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IDatabase;
use Wikimedia\Rdbms\UpdateQueryBuilder;

/**
 * Unit tests for the atomic conditional-UPDATE primitives on QueueStore:
 * claimForProcessing() and resetToPending(). Both drive their boolean
 * result off IDatabase::affectedRows() and carry a status guard in the
 * WHERE clause so exactly one concurrent caller wins.
 *
 * The query builder executes against the mocked IDatabase, so we can
 * capture the real update() arguments and assert on the guard directly.
 *
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\QueueStore::claimForProcessing
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\QueueStore::resetToPending
 */
class QueueStoreClaimTest extends MediaWikiUnitTestCase {

	/**
	 * @var array<string,mixed>|null Captured args of the last update() call:
	 *   [ 'table' => ..., 'set' => ..., 'conds' => ... ].
	 */
	private ?array $capturedUpdate = null;

	/**
	 * Build a QueueStore over a mocked primary IDatabase whose
	 * affectedRows() returns $affected and whose update() args are
	 * captured for assertions.
	 */
	private function buildStore( int $affected ): QueueStore {
		$db = $this->createMock( IDatabase::class );

		$db->method( 'newUpdateQueryBuilder' )
			->willReturnCallback( static fn () => new UpdateQueryBuilder( $db ) );
		$db->method( 'timestamp' )
			->willReturn( '20240101000000' );
		$db->method( 'expr' )
			->willReturnCallback(
				static fn ( $field, $op, $value ) => new Expression( $field, $op, $value )
			);
		$db->method( 'affectedRows' )->willReturn( $affected );
		$db->method( 'update' )
			->willReturnCallback( function ( $table, $set, $conds ) {
				$this->capturedUpdate = [
					'table' => $table,
					'set' => $set,
					'conds' => $conds,
				];
				return true;
			} );

		$provider = $this->createMock( IConnectionProvider::class );
		$provider->method( 'getPrimaryDatabase' )->willReturn( $db );

		return new QueueStore( $provider );
	}

	public function testClaimReturnsTrueWhenPendingRowClaimed(): void {
		$store = $this->buildStore( 1 );

		$this->assertTrue( $store->claimForProcessing( 42 ) );

		// WHERE guards on the pending status so only one runner wins.
		$this->assertSame(
			QueueStatus::Pending->value,
			$this->capturedUpdate['conds']['rsq_status'],
			'claim must guard on rsq_status = pending'
		);
		$this->assertSame( 42, $this->capturedUpdate['conds']['rsq_id'] );
		$this->assertSame(
			QueueStatus::Processing->value,
			$this->capturedUpdate['set']['rsq_status'],
			'claim must set status to processing'
		);
	}

	public function testClaimReturnsFalseWhenAlreadyClaimed(): void {
		// affectedRows() === 0 means the guard matched no row: another
		// runner already flipped it (or it isn't pending).
		$store = $this->buildStore( 0 );

		$this->assertFalse( $store->claimForProcessing( 42 ) );
	}

	public function testResetToPendingReturnsTrueWhenTransitioned(): void {
		$store = $this->buildStore( 1 );

		$this->assertTrue( $store->resetToPending( 7 ) );

		$this->assertSame(
			QueueStatus::Pending->value,
			$this->capturedUpdate['set']['rsq_status'],
			'reset must set status back to pending'
		);
		// Processed state is cleared so a fresh job re-parses cleanly.
		$this->assertNull( $this->capturedUpdate['set']['rsq_response'] );
		$this->assertNull( $this->capturedUpdate['set']['rsq_error'] );
		// The guard excludes already-pending rows (carried as an Expression
		// condition), so two concurrent resets can't both transition.
		$hasGuard = false;
		foreach ( $this->capturedUpdate['conds'] as $cond ) {
			if ( $cond instanceof Expression ) {
				$hasGuard = true;
			}
		}
		$this->assertTrue( $hasGuard, 'reset must carry a status-guard expression' );
	}

	public function testResetToPendingReturnsFalseWhenNoTransition(): void {
		// affectedRows() === 0 means the row was missing or already pending.
		$store = $this->buildStore( 0 );

		$this->assertFalse( $store->resetToPending( 7 ) );
	}

	public function testReprocessSkipsFetchWhenNoTransition(): void {
		// When resetToPending() reports no transition, reprocess() must not
		// return a row (and callers must not push a job).
		$store = $this->buildStore( 0 );

		$this->assertNull( $store->reprocess( 7 ) );
	}
}
