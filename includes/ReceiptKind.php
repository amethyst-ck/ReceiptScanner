<?php

namespace MediaWiki\Extension\ReceiptScanner;

/**
 * Whether a receipt represents money going out (Expense) or in
 * (Income), plus the facts each kind implies (Cargo table, party
 * column, form name, namespace). {@see LedgerKindFilter} adds `Both`.
 */
enum ReceiptKind: string {
	case Expense = 'expense';
	case Income  = 'income';

	/** The kind living in the given namespace, or null for any other namespace. */
	public static function tryFromNamespace( int $ns ): ?self {
		return match ( $ns ) {
			NS_RECEIPTSCANNER_EXPENSE => self::Expense,
			NS_RECEIPTSCANNER_INCOME => self::Income,
			default => null,
		};
	}

	public function other(): self {
		return $this === self::Expense ? self::Income : self::Expense;
	}

	/** Cargo main table recording entries of this kind. */
	public function cargoTable(): string {
		return $this === self::Expense ? 'Expenses' : 'Income';
	}

	/** Counterparty column/field name: who was paid vs. who paid. */
	public function partyColumn(): string {
		return $this === self::Expense ? 'payee' : 'payer';
	}

	/**
	 * Template parameter holding the accounting category. Kind-specific
	 * to keep it distinct from MediaWiki page categories; the Cargo
	 * column stays `category` on both tables.
	 */
	public function categoryParameter(): string {
		return $this === self::Expense ? 'expense_category' : 'income_category';
	}

	/** PageForms form name (also the template and namespace label). */
	public function formName(): string {
		return $this === self::Expense ? 'Expense' : 'Income';
	}
}
