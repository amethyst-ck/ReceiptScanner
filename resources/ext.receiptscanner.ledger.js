/**
 * Special:Ledger bulk-edit selection UX.
 * - Header checkbox toggles all visible row checkboxes.
 * - Footer form's "N selected" badge updates live.
 * - Form is hidden by CSS until at least one row is selected.
 */
( function () {
	'use strict';

	/**
	 * Local-list autocomplete: shows up to 20 matches from `getItems()`
	 * (prefix match, case-insensitive). Reused for the bulk-value field.
	 */
	function bindLocalAutocomplete( $input, getItems ) {
		var $menu = $( '<ul>' ).addClass( 'rs-file-menu' ).hide();
		var $wrap = $( '<span>' ).addClass( 'rs-ac-wrap' );
		$input.wrap( $wrap );
		$input.parent().append( $menu );
		var activeIdx = -1;

		function rows() { return $menu.children(); }
		function setActive( i ) {
			rows().removeClass( 'rs-file-menu-active' );
			var $r = rows().eq( i );
			if ( $r.length ) {
				$r.addClass( 'rs-file-menu-active' );
				activeIdx = i;
			} else {
				activeIdx = -1;
			}
		}
		function pick( v ) {
			$input.val( v );
			$menu.hide();
			activeIdx = -1;
		}
		function refresh() {
			var q = ( $input.val() || '' ).toLowerCase();
			var items = getItems().filter( function ( x ) {
				return q === '' || x.toLowerCase().indexOf( q ) !== -1;
			} ).slice( 0, 20 );
			$menu.empty();
			items.forEach( function ( n, i ) {
				var $li = $( '<li>' ).text( n )
					.on( 'mousedown', function ( e ) {
						e.preventDefault();
						pick( n );
					} )
					.on( 'mouseenter', function () {
						setActive( i );
					} );
				$menu.append( $li );
			} );
			$menu.toggle( items.length > 0 );
			activeIdx = -1;
		}
		$input.on( 'input focus', refresh );
		$input.on( 'keydown', function ( e ) {
			var n = rows().length;
			if ( e.key === 'ArrowDown' ) {
				e.preventDefault();
				setActive( ( activeIdx + 1 ) % Math.max( n, 1 ) );
			} else if ( e.key === 'ArrowUp' ) {
				e.preventDefault();
				setActive( ( activeIdx - 1 + n ) % Math.max( n, 1 ) );
			} else if ( e.key === 'Enter' && activeIdx >= 0 ) {
				e.preventDefault();
				pick( rows().eq( activeIdx ).text() );
			} else if ( e.key === 'Escape' ) {
				$menu.hide();
				activeIdx = -1;
			}
		} );
		$input.on( 'blur', function () {
			setTimeout( function () { $menu.hide(); }, 150 );
		} );
	}

	$( function () {
		// Range-select toggle: custom date pickers appear only when
		// "Custom range" is selected.
		var $range = $( '.rs-ledger-range-select' );
		var $custom = $( '.rs-ledger-custom' );
		if ( $range.length && $custom.length ) {
			$range.on( 'change', function () {
				$custom.css( 'display', $range.val() === 'custom' ? '' : 'none' );
			} );
		}

		var $rows = $( '.rs-ledger-select' );
		var $all = $( '.rs-ledger-select-all' );
		var $form = $( '#rs-ledger-bulk-form' );
		var $count = $form.find( '.rs-ledger-bulk-count' );
		if ( !$rows.length ) {
			return;
		}
		function refresh() {
			var n = $rows.filter( ':checked' ).length;
			$count.text( mw.message( 'receiptscanner-ledger-bulk-count', n ).text() );
			$form.toggleClass( 'rs-ledger-bulk-form--active', n > 0 );
		}
		$rows.on( 'change', refresh );
		$all.on( 'change', function () {
			$rows.prop( 'checked', this.checked ).trigger( 'change' );
		} );
		$form.on( 'submit', function ( e ) {
			if ( $rows.filter( ':checked' ).length === 0 ) {
				e.preventDefault();
			}
		} );
		refresh();

		// Autocomplete the "new value" input based on the chosen field —
		// same data as the underlying form's combobox.
		var expenseCategories = mw.config.get( 'wgReceiptScannerBulkExpenseCategories' ) || [];
		var incomeCategories = mw.config.get( 'wgReceiptScannerBulkIncomeCategories' ) || [];
		var users = mw.config.get( 'wgReceiptScannerBulkUsers' ) || [];
		var parties = mw.config.get( 'wgReceiptScannerBulkParties' ) || [];
		var $field = $form.find( 'select[name="bulk_field"]' );
		var $value = $form.find( 'input[name="bulk_value"]' );

		// Category vocabulary for the current selection: expense-only or
		// income-only selections get their own list; mixed selections get
		// the merged, deduplicated union.
		function categoriesForSelection() {
			var kinds = {};
			$rows.filter( ':checked' ).each( function () {
				kinds[ $( this ).attr( 'data-rs-kind' ) ] = true;
			} );
			if ( kinds.expense && !kinds.income ) {
				return expenseCategories;
			}
			if ( kinds.income && !kinds.expense ) {
				return incomeCategories;
			}
			var seen = {};
			return expenseCategories.concat( incomeCategories ).filter( function ( c ) {
				if ( seen[ c ] ) {
					return false;
				}
				seen[ c ] = true;
				return true;
			} );
		}

		bindLocalAutocomplete( $value, function () {
			switch ( $field.val() ) {
				case 'assignee':
					return users;
				case 'party':
					return parties;
				default: // category
					return categoriesForSelection();
			}
		} );
		$field.on( 'change', function () {
			$value.val( '' );
		} );
	} );
}() );
