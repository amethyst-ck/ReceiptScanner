<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Existence checks for Cargo-managed tables. A Cargo main table only
 * exists after its declaring template has been saved and the table
 * created, so queries against cargo__* tables must check first.
 */
class CargoTables {

	public function __construct(
		private readonly IConnectionProvider $dbProvider
	) {
	}

	public function mainTableExists( string $mainTable ): bool {
		return (bool)$this->dbProvider->getReplicaDatabase()
			->newSelectQueryBuilder()
			->select( '1' )
			->from( 'cargo_tables' )
			->where( [ 'main_table' => $mainTable ] )
			->caller( __METHOD__ )
			->fetchField();
	}
}
