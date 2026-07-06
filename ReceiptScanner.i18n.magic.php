<?php
/**
 * Magic word definitions for the ReceiptScanner extension.
 * Parser functions must be registered here before Parser::setFunctionHook
 * will accept them.
 */

$magicWords = [];

/** English */
$magicWords['en'] = [
	// case-insensitive parser function name + canonical alias
	'receiptscanner_categories' => [ 0, 'receiptscanner_categories' ],
	'receiptscanner_currency_symbol' => [ 0, 'receiptscanner_currency_symbol' ],
	'receiptscanner_format_amount' => [ 0, 'receiptscanner_format_amount' ],
	'receiptscanner_users' => [ 0, 'receiptscanner_users' ],
	'receiptscanner_truncate' => [ 0, 'receiptscanner_truncate' ],
	'receiptscanner_system_currency' => [ 0, 'receiptscanner_system_currency' ],
	'receiptscanner_dashboard' => [ 0, 'receiptscanner_dashboard' ],
	'receiptscanner_form_actions' => [ 0, 'receiptscanner_form_actions' ],
	'receiptscanner_file_url' => [ 0, 'receiptscanner_file_url' ],
];
