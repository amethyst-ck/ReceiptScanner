<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use Wikimedia\Rdbms\IConnectionProvider;

/**
 * Query layer for Special:UnlinkedFiles — finds File: pages that no
 * Expense or Income entry references (neither as the primary `file`
 * nor in `supplemental_files`).
 *
 * File: titles use underscores; Cargo File-typed columns store the
 * display form with spaces — referenced names are normalized to
 * underscore form so the anti-join runs in SQL against page_title.
 */
class UnlinkedFilesStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly CargoTables $cargoTables
	) {
	}

	/**
	 * @return string[] page_title values (underscore form) of files not
	 *   referenced by any Expense or Income entry, sorted, capped at $limit.
	 */
	public function findUnlinkedFiles( int $limit ): array {
		$db = $this->dbProvider->getReplicaDatabase();

		// Referenced filenames, normalized to underscore form. Bounded by
		// the number of ledger entries, so small enough for a NOT IN list.
		$referenced = [];
		foreach ( ReceiptKind::cases() as $kind ) {
			$table = $kind->cargoTable();
			if ( !$this->cargoTables->mainTableExists( $table ) ) {
				continue;
			}
			foreach ( [
				[ 'cargo__' . $table, 'file' ],
				[ 'cargo__' . $table . '__supplemental_files', '_value' ],
			] as [ $from, $col ] ) {
				foreach (
					$db->newSelectQueryBuilder()
						->select( $col )
						->from( $from )
						->where( $db->expr( $col, '!=', '' ) )
						->caller( __METHOD__ )
						->fetchFieldValues()
					as $f
				) {
					$referenced[str_replace( ' ', '_', $f )] = true;
				}
			}
		}

		$qb = $db->newSelectQueryBuilder()
			->select( 'page_title' )
			->from( 'page' )
			->where( [ 'page_namespace' => NS_FILE ] )
			->orderBy( 'page_title' )
			->limit( $limit )
			->caller( __METHOD__ );
		if ( $referenced ) {
			$qb->andWhere( $db->expr( 'page_title', '!=', array_keys( $referenced ) ) );
		}
		return $qb->fetchFieldValues();
	}
}
