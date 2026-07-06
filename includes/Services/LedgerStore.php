<?php

namespace MediaWiki\Extension\ReceiptScanner\Services;

use MediaWiki\Extension\ReceiptScanner\LedgerKindFilter;
use MediaWiki\Extension\ReceiptScanner\NotesEscaper;
use MediaWiki\Extension\ReceiptScanner\ReceiptKind;
use Wikimedia\Rdbms\IConnectionProvider;
use Wikimedia\Rdbms\IExpression;
use Wikimedia\Rdbms\LikeValue;
use Wikimedia\Rdbms\RawSQLExpression;

/**
 * Query layer for Special:Ledger — runs the Expenses and Income Cargo
 * tables through a uniform filter and returns a merged, sorted list.
 */
class LedgerStore {

	public function __construct(
		private readonly IConnectionProvider $dbProvider,
		private readonly CargoTables $cargoTables
	) {
	}

	/**
	 * @param array{kind?:string,from?:?string,to?:?string,min?:?float,max?:?float,category?:?string,uncategorized?:bool,assignee?:?string,party?:?string,notes?:?string,limit?:int} $filters
	 *   `category` is a prefix LIKE match, `uncategorized` keeps only
	 *   rows with a blank category, `assignee` is exact-equals, `party`
	 *   and `notes` are substring LIKE matches (`notes` against the
	 *   numeric-entity-encoded stored form).
	 * @return array<int, array{kind:string,date:?string,party:?string,total:?string,currency:?string,category:?string,page:?string,id:int}>
	 */
	public function run( array $filters ): array {
		$kindFilter = LedgerKindFilter::tryFrom( $filters['kind'] ?? '' )
			?? LedgerKindFilter::Both;
		$limit = (int)( $filters['limit'] ?? 500 );

		$db = $this->dbProvider->getReplicaDatabase();
		$rows = [];

		if ( $kindFilter !== LedgerKindFilter::Income ) {
			$rows = array_merge( $rows,
				$this->queryTable( $db, ReceiptKind::Expense, $filters ) );
		}
		if ( $kindFilter !== LedgerKindFilter::Expense ) {
			$rows = array_merge( $rows,
				$this->queryTable( $db, ReceiptKind::Income, $filters ) );
		}

		// Sort by date desc; null dates last. Tiebreak by id desc.
		usort( $rows, static function ( $a, $b ) {
			$ad = $a['date'] ?: '0000-00-00';
			$bd = $b['date'] ?: '0000-00-00';
			if ( $ad !== $bd ) {
				return $ad < $bd ? 1 : -1;
			}
			return $b['id'] <=> $a['id'];
		} );

		return array_slice( $rows, 0, $limit );
	}

	/** Protected so unit tests can partial-mock canned table results. */
	protected function queryTable(
		$db,
		ReceiptKind $kind,
		array $filters
	): array {
		if ( !$this->cargoTables->mainTableExists( $kind->cargoTable() ) ) {
			return [];
		}
		$partyCol = $kind->partyColumn();

		$qb = $db->newSelectQueryBuilder()
			->select( [
				'_ID', '_pageName', 'date', $partyCol, 'total',
				'currency', 'category', 'assignee',
				'total_system'
			] )
			->from( 'cargo__' . $kind->cargoTable() )
			->caller( __METHOD__ );

		$from = $filters['from'] ?? null;
		$to = $filters['to'] ?? null;
		if ( $from ) {
			$qb->where( $db->expr( 'date', '>=', $from ) );
		}
		if ( $to ) {
			$qb->where( $db->expr( 'date', '<=', $to ) );
		}
		$min = $filters['min'] ?? null;
		$max = $filters['max'] ?? null;
		// Compare against total_system (varchar in Cargo, so CAST), with
		// COALESCE fallback to total for older rows. RawSQLExpression
		// because expr() rejects the fragment; the (float) cast keeps the
		// literal injection-safe.
		$amountExpr = 'CAST(COALESCE(NULLIF(total_system,""),total) AS DECIMAL(20,2))';
		// is_finite: 1e999 casts to INF, which is not a valid SQL literal.
		if ( $min !== null && $min !== '' && is_finite( (float)$min ) ) {
			$qb->where( new RawSQLExpression( $amountExpr . ' >= ' . (float)$min ) );
		}
		if ( $max !== null && $max !== '' && is_finite( (float)$max ) ) {
			$qb->where( new RawSQLExpression( $amountExpr . ' <= ' . (float)$max ) );
		}
		$cat = $filters['category'] ?? null;
		if ( $cat !== null && $cat !== '' ) {
			// IExpression::LIKE — the DBConnRef wrapper doesn't forward
			// the class constant.
			$qb->where( $db->expr( 'category', IExpression::LIKE,
				new LikeValue( $cat, $db->anyString() ) ) );
		}
		if ( $filters['uncategorized'] ?? false ) {
			$qb->where( $db->expr( 'category', '=', '' )->or( 'category', '=', null ) );
		}
		$assignee = $filters['assignee'] ?? null;
		if ( $assignee !== null && $assignee !== '' ) {
			$qb->where( [ 'assignee' => $assignee ] );
		}
		$party = $filters['party'] ?? null;
		if ( $party !== null && $party !== '' ) {
			$qb->where( $db->expr( $partyCol, IExpression::LIKE,
				new LikeValue( $db->anyString(), $party, $db->anyString() ) ) );
		}
		// notes is stored entity-encoded (NotesEscaper, on save), so
		// encode the needle the same way for byte-equal LIKE matches.
		$notes = $filters['notes'] ?? null;
		if ( $notes !== null && $notes !== '' ) {
			$encoded = NotesEscaper::encode( $notes );
			$qb->where( $db->expr( 'notes', IExpression::LIKE,
				new LikeValue( $db->anyString(), $encoded, $db->anyString() )
			) );
		}

		$out = [];
		foreach ( $qb->fetchResultSet() as $row ) {
			// Older rows pre-date total_system; total is already in the
			// system currency there.
			$totalSystem = ( $row->total_system !== null && $row->total_system !== '' )
				? $row->total_system : $row->total;
			$out[] = [
				'kind' => $kind->value,
				'id' => (int)$row->_ID,
				'page' => $row->_pageName,
				'date' => $row->date,
				'party' => $row->$partyCol,
				'total' => $row->total,
				'currency' => $row->currency,
				'total_system' => $totalSystem,
				'category' => $row->category,
				'assignee' => $row->assignee,
			];
		}
		return $out;
	}

	/**
	 * Distinct payee + payer values across both Cargo tables. Sorted,
	 * deduplicated. Feeds the bulk-value autocomplete on Special:Ledger.
	 *
	 * @return string[]
	 */
	public function getParties(): array {
		$db = $this->dbProvider->getReplicaDatabase();
		$all = [];
		foreach ( ReceiptKind::cases() as $kind ) {
			if ( !$this->cargoTables->mainTableExists( $kind->cargoTable() ) ) {
				continue;
			}
			$col = $kind->partyColumn();
			foreach (
				$db->newSelectQueryBuilder()
					->select( $col )
					->distinct()
					->from( 'cargo__' . $kind->cargoTable() )
					->where( $db->expr( $col, '!=', '' ) )
					->caller( __METHOD__ )
					->fetchFieldValues()
				as $v
			) {
				$all[$v] = true;
			}
		}
		$out = array_keys( $all );
		sort( $out );
		return $out;
	}

	/**
	 * Map a date-range preset name to absolute [from, to] strings
	 * (YYYY-MM-DD), or [null, null] for "all".
	 *
	 * @return array{0:?string,1:?string}
	 */
	public static function presetRange( string $preset, ?string $today = null ): array {
		$now = new \DateTimeImmutable( $today ?? 'today' );
		switch ( $preset ) {
			case 'this_week':
				// ISO week: Monday → Sunday.
				$start = $now->modify( 'monday this week' );
				$end = $start->modify( '+6 days' );
				return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
			case 'this_month':
				$start = $now->modify( 'first day of this month' );
				$end = $now->modify( 'last day of this month' );
				return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
			case 'this_year':
				$start = new \DateTimeImmutable( $now->format( 'Y' ) . '-01-01' );
				$end = new \DateTimeImmutable( $now->format( 'Y' ) . '-12-31' );
				return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
			case 'last_week':
				$start = $now->modify( 'monday last week' );
				$end = $start->modify( '+6 days' );
				return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
			case 'last_month':
				$start = $now->modify( 'first day of last month' );
				$end = $now->modify( 'last day of last month' );
				return [ $start->format( 'Y-m-d' ), $end->format( 'Y-m-d' ) ];
			case 'last_year':
				$y = (int)$now->format( 'Y' ) - 1;
				return [ "$y-01-01", "$y-12-31" ];
			default:
				return [ null, null ];
		}
	}
}
