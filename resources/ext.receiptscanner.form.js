/**
 * Form polish for Form:Expense and Form:Income.
 *
 * For the `file` field (the parsed receipt) and `supplemental_files`
 * field (unparsed supporting docs), render a clickable preview beside
 * the input — a thumbnail for images, an icon-card for PDFs — and
 *
 * The underlying form inputs are kept in the DOM so submission still
 * carries their values; we just augment them visually.
 */
( function () {
	'use strict';

	function filePathUrl( filename, width ) {
		// mw.util.getUrl handles wikis without short URLs (where the base
		// already carries ?title=), so extra params must go through it.
		return width
			? mw.util.getUrl( 'Special:FilePath/' + filename, { width: width } )
			: mw.util.getUrl( 'Special:FilePath/' + filename );
	}

	/**
	 * URL the preview's <a> should point at when the user clicks
	 * through. For browser-renderable formats this is the raw file
	 * URL; for HEIC/HEIF (which browsers won't decode inline) we
	 * route through the thumbnailer so the new tab gets a JPEG that
	 * actually displays. The extension list and render width come from
	 * ParserHooks via mw.config, shared with
	 * {{#receiptscanner_file_url:}} on the template side.
	 */
	function viewableUrl( filename ) {
		var exts = mw.config.get( 'wgReceiptScannerRenderExts' ) || [ 'heic', 'heif' ];
		var needsRender = new RegExp( '\\.(' + exts.join( '|' ) + ')$', 'i' );
		if ( needsRender.test( filename ) ) {
			return filePathUrl(
				filename,
				mw.config.get( 'wgReceiptScannerViewWidth' ) || 1500
			);
		}
		return filePathUrl( filename );
	}

	function getOouiCellHostFor( $select ) {
		// PageForms wraps pfComboBox/pfTokens in <span class="inputSpan">
		// (or "comboboxSpan"); fall back to parent if neither found.
		var $host = $select.closest( 'span.inputSpan, span.comboboxSpan' );
		return $host.length ? $host : $select.parent();
	}

	/**
	 * Try a real thumbnail first (works for images, and for PDFs when
	 * PdfHandler is enabled). On error, fall back to a labeled card.
	 */
	function renderPreview( filename, thumbWidth ) {
		var $wrap = $( '<span>' ).addClass( 'rs-file-preview' );
		var $link = $( '<a>' ).attr( {
			href: viewableUrl( filename ),
			target: '_blank',
			rel: 'noopener'
		} );
		var $img = $( '<img>' ).attr( {
			src: filePathUrl( filename, thumbWidth || 200 ),
			alt: filename
		} ).addClass( 'rs-file-thumb' ).on( 'error', function () {
			$( this ).replaceWith(
				$( '<span>' ).text( filename )
			);
			$link.addClass( 'rs-file-card' );
		} );
		return $wrap.append( $link.append( $img ) );
	}

	/**
	 * Both file (max values=1) and supplemental_files are rendered by
	 * PageForms as pfTokens multi-selects with autocomplete on the File
	 * namespace. Select2 handles add/remove natively (× on each chip).
	 * We just render a thumbnail strip beneath that mirrors the chips.
	 *
	 * For the MAIN file strip (single big thumb), we also pull the
	 * adjacent {{#receiptscanner_form_actions:}} span (which lives as
	 * a sibling of $host) into a flex-row wrapper with the thumb so
	 * the Clone button sits next to the receipt instead of below it.
	 */
	function bindTokensField( $select, stripClass, thumbWidth ) {
		var $host = getOouiCellHostFor( $select );
		var $strip = $( '<div>' ).addClass( 'rs-file-strip' );
		if ( stripClass ) {
			$strip.addClass( stripClass );
		}

		if ( stripClass === 'rs-file-strip-main' ) {
			// Look for the form-actions span emitted by the parser
			// function. If present, wrap [thumb | actions] in a flex
			// row; otherwise just insert the strip as before.
			var $actions = $host.parent().find( '.rs-form-actions' ).first();
			if ( $actions.length ) {
				var $row = $( '<div>' ).addClass( 'rs-file-row' );
				$host.after( $row );
				$row.append( $strip ).append( $actions );
			} else {
				$host.after( $strip );
			}
		} else {
			$host.after( $strip );
		}

		function renderStrip() {
			$strip.empty();
			var values = $select.val() || [];
			values.forEach( function ( v ) {
				if ( v ) {
					$strip.append( renderPreview( v, thumbWidth ) );
				}
			} );
		}

		renderStrip();
		$select.on( 'change input', renderStrip );
	}

	/**
	 * Warn when total ≠ subtotal + tax + fees. Tolerates a 1-cent
	 * rounding gap. Only fires when all four fields have values.
	 */
	function bindTotalSanityCheck( form ) {
		var $total = $( 'input[name="' + form + '[total]"]' );
		var $sub = $( 'input[name="' + form + '[subtotal]"]' );
		var $tax = $( 'input[name="' + form + '[tax]"]' );
		var $fees = $( 'input[name="' + form + '[fees]"]' );
		if ( !$total.length ) {
			return;
		}
		var $warn = $( '<div>' ).addClass( 'rs-total-warn' ).hide();
		$total.closest( 'tr' ).find( 'td' ).first().append( $warn );

		function check() {
			var num = function ( $el ) {
				var v = parseFloat( ( $el.val() || '' ).replace( /,/g, '' ) );
				return isNaN( v ) ? null : v;
			};
			var t = num( $total );
			var s = num( $sub );
			var x = num( $tax );
			var f = num( $fees );
			// Needs total, subtotal, and at least one of tax/fees;
			// otherwise hide the warning.
			if ( t === null || s === null || x === null && f === null ) {
				$warn.hide();
				return;
			}
			var expected = s + ( x || 0 ) + ( f || 0 );
			if ( Math.abs( expected - t ) > 0.01 ) {
				$warn.text(
					mw.message(
						'receiptscanner-form-total-mismatch',
						t.toFixed( 2 ),
						expected.toFixed( 2 )
					).text()
				).show();
			} else {
				$warn.hide();
			}
		}
		$( [ $total, $sub, $tax, $fees ] ).each( function () {
			this.on( 'input change', check );
		} );
		check();
	}

	/**
	 * Keep total / exchange_rate / total_system consistent. When the
	 * receipt's currency matches the system currency, the user only
	 * touches `total`; exchange_rate is pinned at 1 and total_system
	 * mirrors total. Otherwise: whichever of rate or total_system the
	 * user touched most recently drives the other.
	 */
	function bindCurrencyConversion( form, systemCurrency ) {
		var $cur = $( 'input[name="' + form + '[currency]"]' );
		var $total = $( 'input[name="' + form + '[total]"]' );
		var $rate = $( 'input[name="' + form + '[exchange_rate]"]' );
		var $sys = $( 'input[name="' + form + '[total_system]"]' );
		if ( !$cur.length || !$total.length || !$rate.length || !$sys.length ) {
			return;
		}
		var $rateRow = $rate.closest( 'tr' );
		var $sysRow = $sys.closest( 'tr' );

		var num = function ( $el ) {
			var v = parseFloat( ( $el.val() || '' ).replace( /,/g, '' ) );
			return isNaN( v ) ? null : v;
		};
		var lastTouched = null; // 'rate' | 'sys'

		function refresh() {
			var sameCurrency =
				( $cur.val() || '' ).trim().toUpperCase() ===
				systemCurrency.toUpperCase();
			$rateRow.toggle( !sameCurrency );
			$sysRow.toggle( !sameCurrency );
			if ( sameCurrency ) {
				$rate.val( '1' );
				$sys.val( $total.val() );
				return;
			}
			var t = num( $total );
			if ( t === null ) {
				return;
			}
			if ( lastTouched === 'sys' ) {
				var sv = num( $sys );
				if ( sv !== null && t !== 0 ) {
					$rate.val( ( sv / t ).toFixed( 6 ) );
				}
			} else {
				// Default direction: rate-driven.
				var r = num( $rate );
				if ( r !== null ) {
					$sys.val( ( t * r ).toFixed( 2 ) );
				}
			}
		}

		$cur.on( 'input change', refresh );
		$total.on( 'input change', refresh );
		$rate.on( 'input change', function () {
			lastTouched = 'rate';
			refresh();
		} );
		$sys.on( 'input change', function () {
			lastTouched = 'sys';
			refresh();
		} );
		refresh();
	}

	$( function () {
		var systemCurrency = mw.config.get( 'wgReceiptScannerSystemCurrency' ) || 'USD';
		[ 'Expense', 'Income' ].forEach( function ( form ) {
			var $fileSelect = $( 'select.pfTokens[name="' + form + '[file][]"]' );
			$fileSelect.each( function () {
				// Main receipt: large thumb (`.rs-file-strip-main` overrides
				// the strip's default size cap in form.css).
				bindTokensField( $( this ), 'rs-file-strip-main', 400 );
			} );
			$( 'select.pfTokens[name="' + form + '[supplemental_files][]"]' ).each( function () {
				bindTokensField( $( this ) );
			} );
			bindTotalSanityCheck( form );
			bindCurrencyConversion( form, systemCurrency );

			// Exempt notes from PageForms' client-side pipe validation
			// (the `freeText` class opts a wrapper out of checkForPipes).
			// Safe: the server entity-encodes pipes/braces on save.
			$( 'textarea[name="' + form + '[notes]"]' )
				.closest( '.inputSpan' ).addClass( 'freeText' );
		} );
	} );
}() );
