<?php

namespace MediaWiki\Extension\ReceiptScanner\Special;

use MediaWiki\Context\IContextSource;
use MediaWiki\Extension\ReceiptScanner\LedgerKindFilter;
use MediaWiki\Html\Html;

/**
 * The filter form at the top of Special:Ledger: kind, date-range
 * preset (with custom from/to), amount bounds, and text filters.
 */
readonly class LedgerFilterFormRenderer {
	use MsgTrait;

	private const PRESETS = [
		'this_month' => 'receiptscanner-ledger-range-this-month',
		'this_week'  => 'receiptscanner-ledger-range-this-week',
		'this_year'  => 'receiptscanner-ledger-range-this-year',
		'last_month' => 'receiptscanner-ledger-range-last-month',
		'last_week'  => 'receiptscanner-ledger-range-last-week',
		'last_year'  => 'receiptscanner-ledger-range-last-year',
		'all'        => 'receiptscanner-ledger-range-all',
		'custom'     => 'receiptscanner-ledger-range-custom',
	];

	public function __construct(
		private IContextSource $context,
		private string $systemCurrency
	) {
	}

	public function render( array $f ): void {
		$out = $this->context->getOutput();
		$pageTitle = $this->context->getTitle();

		$rangeOptions = '';
		foreach ( self::PRESETS as $key => $msgKey ) {
			$attrs = [ 'value' => $key ];
			if ( $f['preset'] === $key ) {
				$attrs['selected'] = '';
			}
			$rangeOptions .= Html::element( 'option', $attrs,
				$this->msg( $msgKey )->text() );
		}
		$kinds = [
			LedgerKindFilter::Both->value    => 'receiptscanner-ledger-kind-both',
			LedgerKindFilter::Expense->value => 'receiptscanner-ledger-kind-expense-only',
			LedgerKindFilter::Income->value  => 'receiptscanner-ledger-kind-income-only',
		];
		$kindOptions = '';
		foreach ( $kinds as $key => $msgKey ) {
			$attrs = [ 'value' => $key ];
			if ( $f['kind'] === $key ) {
				$attrs['selected'] = '';
			}
			$kindOptions .= Html::element( 'option', $attrs,
				$this->msg( $msgKey )->text() );
		}

		$customAttrs = [ 'class' => 'rs-ledger-custom' ];
		if ( $f['preset'] !== 'custom' ) {
			$customAttrs['style'] = 'display:none';
		}

		$row1 = Html::rawElement( 'div', [ 'class' => 'rs-ledger-row' ],
			$this->labelledSelect( $this->msg( 'receiptscanner-ledger-filter-kind' )->text(), 'kind', $kindOptions )
			. $this->labelledSelect( $this->msg( 'receiptscanner-ledger-filter-range' )->text(), 'range', $rangeOptions, 'rs-ledger-range-select' )
			. Html::rawElement( 'span', $customAttrs,
				$this->labelledDate( $this->msg( 'receiptscanner-ledger-filter-from' )->text(), 'from', (string)( $f['from'] ?? '' ) ) . ' '
				. $this->labelledDate( $this->msg( 'receiptscanner-ledger-filter-to' )->text(), 'to', (string)( $f['to'] ?? '' ) )
			)
		);

		$currency = $this->systemCurrency;
		$row2 = Html::rawElement( 'div', [ 'class' => 'rs-ledger-row' ],
			$this->labelledNumber( $this->msg( 'receiptscanner-ledger-filter-min', $currency )->text(), 'min', (string)( $f['min'] ?? '' ) )
			. $this->labelledNumber( $this->msg( 'receiptscanner-ledger-filter-max', $currency )->text(), 'max', (string)( $f['max'] ?? '' ) )
			. $this->labelledText(
				$this->msg( 'receiptscanner-ledger-filter-category' )->text(),
				'category', (string)( $f['category'] ?? '' ),
				$this->msg( 'receiptscanner-ledger-filter-category-placeholder' )->text()
			)
			. $this->labelledCheckbox(
				$this->msg( 'receiptscanner-ledger-filter-uncategorized' )->text(),
				'uncategorized', (bool)( $f['uncategorized'] ?? false )
			)
			. $this->labelledText(
				$this->msg( 'receiptscanner-ledger-filter-assignee' )->text(),
				'assignee', (string)( $f['assignee'] ?? '' ),
				$this->msg( 'receiptscanner-ledger-filter-assignee-placeholder' )->text()
			)
			. $this->labelledText(
				$this->msg( 'receiptscanner-ledger-filter-party' )->text(),
				'party', (string)( $f['party'] ?? '' ),
				$this->msg( 'receiptscanner-ledger-filter-party-placeholder' )->text()
			)
			. $this->labelledText(
				$this->msg( 'receiptscanner-ledger-filter-notes' )->text(),
				'notes', (string)( $f['notes'] ?? '' ),
				$this->msg( 'receiptscanner-ledger-filter-notes-placeholder' )->text()
			)
			. Html::element( 'button', [
				'type' => 'submit',
				'class' => 'mw-ui-button mw-ui-progressive',
			], $this->msg( 'receiptscanner-ledger-apply-filters' )->text() )
		);

		$out->addHTML( Html::rawElement( 'form', [
			'method' => 'get',
			'action' => $pageTitle->getLocalURL(),
			'class' => 'rs-ledger-form',
		], $row1 . $row2 ) );
	}

	private function labelledSelect(
		string $label, string $name, string $options, ?string $cls = null
	): string {
		$selectAttrs = [ 'name' => $name ];
		if ( $cls !== null ) {
			$selectAttrs['class'] = $cls;
		}
		return Html::rawElement( 'label', [],
			$label . ' ' . Html::rawElement( 'select', $selectAttrs, $options )
		);
	}

	private function labelledCheckbox( string $label, string $name, bool $checked ): string {
		$attrs = [
			'type' => 'checkbox',
			'name' => $name,
			'value' => '1',
		];
		if ( $checked ) {
			$attrs['checked'] = '';
		}
		return Html::rawElement( 'label', [ 'class' => 'rs-ledger-checkbox' ],
			$label . ' ' . Html::element( 'input', $attrs )
		);
	}

	private function labelledDate( string $label, string $name, string $value ): string {
		return Html::rawElement( 'label', [],
			$label . ' ' . Html::element( 'input', [
				'type' => 'date',
				'name' => $name,
				'value' => $value,
			] )
		);
	}

	private function labelledNumber( string $label, string $name, string $value ): string {
		return Html::rawElement( 'label', [],
			$label . ' ' . Html::element( 'input', [
				'type' => 'number',
				'step' => '0.01',
				'name' => $name,
				'value' => $value,
			] )
		);
	}

	private function labelledText(
		string $label, string $name, string $value, string $placeholder
	): string {
		return Html::rawElement( 'label', [],
			$label . ' ' . Html::element( 'input', [
				'type' => 'text',
				'name' => $name,
				'value' => $value,
				'placeholder' => $placeholder,
			] )
		);
	}
}
