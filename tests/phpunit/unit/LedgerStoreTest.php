<?php

namespace MediaWiki\Extension\ReceiptScanner\Tests\Unit;

use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use MediaWiki\Extension\ReceiptScanner\Services\CargoTables;
use MediaWiki\Extension\ReceiptScanner\Services\LedgerStore;
use MediaWikiUnitTestCase;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IReadableDatabase;

/**
 * @covers \MediaWiki\Extension\ReceiptScanner\Services\LedgerStore
 */
class LedgerStoreTest extends MediaWikiUnitTestCase {

	public function testThisMonth(): void {
		$this->assertSame(
			[ '2026-05-01', '2026-05-31' ],
			LedgerStore::presetRange( 'this_month', '2026-05-15' )
		);
	}

	public function testThisMonthFebruaryLeapYear(): void {
		$this->assertSame(
			[ '2024-02-01', '2024-02-29' ],
			LedgerStore::presetRange( 'this_month', '2024-02-10' )
		);
	}

	public function testThisYear(): void {
		$this->assertSame(
			[ '2026-01-01', '2026-12-31' ],
			LedgerStore::presetRange( 'this_year', '2026-05-15' )
		);
	}

	public function testLastMonthCrossesYearBoundary(): void {
		$this->assertSame(
			[ '2025-12-01', '2025-12-31' ],
			LedgerStore::presetRange( 'last_month', '2026-01-10' )
		);
	}

	public function testLastYear(): void {
		$this->assertSame(
			[ '2025-01-01', '2025-12-31' ],
			LedgerStore::presetRange( 'last_year', '2026-05-15' )
		);
	}

	public function testThisWeekIsoMondayToSunday(): void {
		// 2026-05-15 is a Friday; week starts Monday 2026-05-11.
		$this->assertSame(
			[ '2026-05-11', '2026-05-17' ],
			LedgerStore::presetRange( 'this_week', '2026-05-15' )
		);
	}

	public function testLastWeek(): void {
		// 2026-05-15 is a Friday; previous week starts Monday 2026-05-04.
		$this->assertSame(
			[ '2026-05-04', '2026-05-10' ],
			LedgerStore::presetRange( 'last_week', '2026-05-15' )
		);
	}

	public function testAllReturnsNullPair(): void {
		$this->assertSame(
			[ null, null ],
			LedgerStore::presetRange( 'all', '2026-05-15' )
		);
	}

	public function testUnknownPresetFallsToAll(): void {
		$this->assertSame(
			[ null, null ],
			LedgerStore::presetRange( 'garbage', '2026-05-15' )
		);
	}

	// ----- run() — kind-routing and sort/limit composition -----

	/**
	 * Partial mock that overrides queryTable so tests can plug in
	 * canned per-table results. The SQL is exercised by the
	 * integration suite; this verifies which tables get queried for a
	 * given kind filter and that the post-sort + limit is correct.
	 *
	 * @return LedgerStore&\PHPUnit\Framework\MockObject\MockObject
	 */
	private function buildWithCannedTables( array $byTable ): LedgerStore {
		$dbProvider = $this->createMock( IConnectionProvider::class );
		$dbProvider->method( 'getReplicaDatabase' )
			->willReturn( $this->createMock( IReadableDatabase::class ) );

		$query = $this->getMockBuilder( LedgerStore::class )
			->setConstructorArgs( [ $dbProvider, $this->createMock( CargoTables::class ) ] )
			->onlyMethods( [ 'queryTable' ] )
			->getMock();
		$query->method( 'queryTable' )->willReturnCallback(
			static function ( $db, ReceiptKind $kind ) use ( $byTable ) {
				return $byTable[$kind->cargoTable()] ?? [];
			}
		);
		return $query;
	}

	public function testRunKindBothQueriesBothTables(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [ $this->row( 1, 'expense', '2026-05-01' ) ],
			'Income'   => [ $this->row( 2, 'income', '2026-05-02' ) ],
		] );
		$rows = $query->run( [ 'kind' => 'both' ] );
		$this->assertCount( 2, $rows );
		$kinds = array_column( $rows, 'kind' );
		sort( $kinds );
		$this->assertSame( [ 'expense', 'income' ], $kinds );
	}

	public function testRunKindExpenseSkipsIncome(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [ $this->row( 1, 'expense', '2026-05-01' ) ],
			'Income'   => [ $this->row( 2, 'income', '2026-05-02' ) ],
		] );
		$rows = $query->run( [ 'kind' => 'expense' ] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'expense', $rows[0]['kind'] );
	}

	public function testRunKindIncomeSkipsExpense(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [ $this->row( 1, 'expense', '2026-05-01' ) ],
			'Income'   => [ $this->row( 2, 'income', '2026-05-02' ) ],
		] );
		$rows = $query->run( [ 'kind' => 'income' ] );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'income', $rows[0]['kind'] );
	}

	public function testRunSortsByDateDescendingThenIdDescending(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [
				$this->row( 10, 'expense', '2026-05-01' ),
				$this->row( 11, 'expense', '2026-05-03' ),
				$this->row( 12, 'expense', '2026-05-03' ),
			],
		] );
		$rows = $query->run( [ 'kind' => 'expense' ] );
		// Date desc; tie on 2026-05-03 broken by id desc.
		$this->assertSame( [ 12, 11, 10 ], array_column( $rows, 'id' ) );
	}

	public function testRunPlacesNullDatesLast(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [
				$this->row( 1, 'expense', '2026-05-01' ),
				$this->row( 2, 'expense', null ),
				$this->row( 3, 'expense', '2026-05-02' ),
			],
		] );
		$rows = $query->run( [ 'kind' => 'expense' ] );
		$this->assertSame( [ 3, 1, 2 ], array_column( $rows, 'id' ) );
	}

	public function testRunHonoursLimit(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [
				$this->row( 1, 'expense', '2026-05-01' ),
				$this->row( 2, 'expense', '2026-05-02' ),
				$this->row( 3, 'expense', '2026-05-03' ),
			],
		] );
		$rows = $query->run( [ 'kind' => 'expense', 'limit' => 2 ] );
		$this->assertCount( 2, $rows );
		// Limit is applied AFTER sort: top 2 by date desc.
		$this->assertSame( [ 3, 2 ], array_column( $rows, 'id' ) );
	}

	public function testRunDefaultsToBothWhenKindMissing(): void {
		$query = $this->buildWithCannedTables( [
			'Expenses' => [ $this->row( 1, 'expense', '2026-05-01' ) ],
			'Income'   => [ $this->row( 2, 'income', '2026-05-02' ) ],
		] );
		$rows = $query->run( [] );
		$this->assertCount( 2, $rows );
	}

	private function row( int $id, string $kind, ?string $date ): array {
		return [
			'kind' => $kind,
			'id' => $id,
			'date' => $date,
			'party' => null,
			'total' => '0.00',
			'currency' => 'USD',
			'category' => null,
			'page' => 'X:' . $id,
			'total_system' => '0.00',
			'assignee' => null,
		];
	}
}
