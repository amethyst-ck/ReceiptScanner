<?php

namespace MediaWiki\Extension\ReceiptScanner;

/**
 * Kind filter on Special:Ledger — {@see ReceiptKind} plus a `Both`
 * case, which is a filter concept, not a property of a saved receipt.
 */
enum LedgerKindFilter: string {
	case Both    = 'both';
	case Expense = 'expense';
	case Income  = 'income';
}
