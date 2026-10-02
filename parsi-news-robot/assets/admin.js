/* Parsi News Robot — admin behaviour. */
( function ( $ ) {
	'use strict';

	var cfg = window.pnrAdmin || {};

	function message( $el, html, isError ) {
		$el.removeClass( 'pnr-loading' ).html( isError ? '<p class="pnr-error"></p>' : html );
		if ( isError ) {
			$el.find( '.pnr-error' ).text( html );
		}
	}

	// Source editor sub-tabs.
	$( document ).on( 'click', '.pnr-subtabs a', function ( e ) {
		e.preventDefault();
		var target = $( this ).attr( 'href' );
		$( '.pnr-subtabs a' ).removeClass( 'active' );
		$( this ).addClass( 'active' );
		$( '.pnr-tab' ).removeClass( 'active' );
		$( target ).addClass( 'active' );
		try {
			window.sessionStorage.setItem( 'pnrTab', target );
		} catch ( err ) {}
	} );
	try {
		var saved = window.sessionStorage.getItem( 'pnrTab' );
		if ( saved && $( saved ).length ) {
			$( '.pnr-subtabs a[href="' + saved + '"]' ).trigger( 'click' );
		}
	} catch ( err ) {}

	// Source type: RSS vs listing page.
	function toggleType() {
		var isPage = $( 'input[name="pnr[source_type]"]:checked' ).val() === 'page';
		$( '.pnr-page-only' ).toggle( isPage );
		$( '.pnr-rss-only, #pnr-discover' ).toggle( ! isPage );
	}
	$( document ).on( 'change', 'input[name="pnr[source_type]"]', toggleType );
	toggleType();

	// "Only these sections" list.
	function toggleScope() {
		$( '#pnr-random-custom' ).toggle( $( '#pnr-random-scope' ).val() === 'custom' );
	}
	$( document ).on( 'change', '#pnr-random-scope', toggleScope );
	toggleScope();

	// Test source.
	$( document ).on( 'click', '#pnr-test', function () {
		var $out = $( '#pnr-test-result' ).addClass( 'pnr-loading' ).text( cfg.testing );
		var $btn = $( this ).prop( 'disabled', true );
		$.post( cfg.ajax, {
			action: 'pnr_test_source',
			nonce: cfg.nonce,
			form: $( '#post' ).find( '[name^="pnr["]' ).serialize(),
			name: $( '#title' ).val() || ''
		} ).done( function ( res ) {
			if ( res && res.success ) {
				message( $out, res.data );
			} else {
				message( $out, res && res.data ? res.data : cfg.error, true );
			}
		} ).fail( function () {
			message( $out, cfg.error, true );
		} ).always( function () {
			$btn.prop( 'disabled', false );
		} );
	} );

	// Feed discovery.
	$( document ).on( 'click', '#pnr-discover', function () {
		var $out = $( '#pnr-discover-result' ).addClass( 'pnr-loading' ).text( cfg.finding );
		$.post( cfg.ajax, { action: 'pnr_discover', nonce: cfg.nonce, url: $( '#pnr-url' ).val() } )
			.done( function ( res ) {
				if ( ! res || ! res.success ) {
					message( $out, res && res.data ? res.data : cfg.error, true );
					return;
				}
				var $ul = $( '<ul/>' );
				$.each( res.data, function ( i, feed ) {
					var $li = $( '<li/>' );
					$( '<button type="button" class="button button-small">انتخاب</button>' )
						.on( 'click', function () {
							$( '#pnr-url' ).val( feed.url );
							$out.empty();
						} )
						.appendTo( $li );
					$li.append( ' ' ).append( $( '<code dir="ltr"/>' ).text( feed.url ) );
					if ( feed.title ) {
						$li.append( ' — ' ).append( $( '<span/>' ).text( feed.title ) );
					}
					$ul.append( $li );
				} );
				$out.removeClass( 'pnr-loading' ).empty().append( $ul );
			} )
			.fail( function () {
				message( $out, cfg.error, true );
			} );
	} );

	// Roles table.
	function syncRoleRow( $row ) {
		$row.attr( 'data-role', $row.find( '.pnr-role-select' ).val() );
		$row.find( '.pnr-quota-limit' ).toggleClass( 'show', $row.find( '.pnr-quota-select' ).val() === 'limit' );
	}
	$( document ).on( 'change', '.pnr-role-select, .pnr-quota-select', function () {
		syncRoleRow( $( this ).closest( 'tr' ) );
	} );
	$( '.pnr-role-row' ).each( function () {
		syncRoleRow( $( this ) );
	} );

	// Rules rows.
	$( document ).on( 'click', '#pnr-add-rule', function () {
		var index = Date.now();
		$( '#pnr-rules-body' ).append( $( '#pnr-rule-template' ).html().replace( /__i__/g, index ) );
	} );
	$( document ).on( 'click', '.pnr-remove-rule', function () {
		$( this ).closest( 'tr' ).remove();
	} );

	// Source probe tool: checks addresses one by one (each request stays inside PHP's time limit).
	$( document ).on( 'click', '#pnr-probe-suggest', function () {
		var kw = $( '#pnr-probe-kw' ).val() || '';
		var list = JSON.parse( $( '#pnr-probe-suggestions' ).text() || '[]' ).map( function ( u ) {
			return u.replace( 'KEYWORD', encodeURIComponent( kw ) );
		} );
		var $t = $( '#pnr-probe-urls' );
		$t.val( ( $t.val() ? $t.val().trim() + '\n' : '' ) + list.join( '\n' ) );
	} );
	$( document ).on( 'click', '#pnr-probe-run', function () {
		var urls = ( $( '#pnr-probe-urls' ).val() || '' ).split( /\s*\n\s*/ ).filter( Boolean );
		var $btn = $( this ).prop( 'disabled', true );
		var $out = $( '#pnr-probe-results' ).html( '<table class="widefat striped pnr-table"><thead><tr><th>آدرس</th><th>نوع</th><th>تعداد</th><th>شامل کلمه</th><th>متن کامل</th><th>نتیجه</th></tr></thead><tbody></tbody></table>' );
		var $report = $( '#pnr-probe-report' ).val( '' );
		var i = 0;
		var next = function () {
			if ( i >= urls.length ) {
				$( '#pnr-probe-status' ).text( 'تمام شد.' );
				$btn.prop( 'disabled', false );
				return;
			}
			var url = urls[ i++ ];
			$( '#pnr-probe-status' ).text( 'در حال بررسی ' + i + ' از ' + urls.length + '…' );
			$.post( cfg.ajax, { action: 'pnr_probe_url', nonce: cfg.nonce, url: url, kw: $( '#pnr-probe-kw' ).val(), deep: $( '#pnr-probe-deep' ).is( ':checked' ) ? 1 : '' } )
				.done( function ( res ) {
					var d = res && res.success ? res.data : { ok: false, error: ( res && res.data ) || cfg.error, report: '### ' + url + '\nخطا' };
					var $tr = $( '<tr/>' );
					$( '<td dir="ltr"/>' ).text( decodeURIComponent( url ) ).appendTo( $tr );
					$( '<td/>' ).text( d.type || '-' ).appendTo( $tr );
					$( '<td/>' ).text( d.count || 0 ).appendTo( $tr );
					$( '<td/>' ).text( d.share === null || d.share === undefined ? '-' : d.share + '٪' ).appendTo( $tr );
					$( '<td/>' ).text( d.words ? d.words + ' کلمه' : '-' ).appendTo( $tr );
					$( '<td/>' ).html( d.ok ? '<span class="pnr-badge ok">قابل استفاده</span>' : '<span class="pnr-badge error"></span>' ).appendTo( $tr );
					if ( ! d.ok ) {
						$tr.find( '.pnr-badge.error' ).text( d.error || 'خبری پیدا نشد' );
					}
					$out.find( 'tbody' ).append( $tr );
					$report.val( $report.val() + d.report + '\n\n' );
				} )
				.fail( function () {
					$report.val( $report.val() + '### ' + url + '\nخطای ارتباط با سرور (احتمالاً زمان اجرا تمام شد)\n\n' );
				} )
				.always( next );
		};
		next();
	} );
	$( document ).on( 'click', '.pnr-copy-report', function () {
		var $t = $( '#pnr-probe-report' ).trigger( 'select' );
		if ( navigator.clipboard ) {
			navigator.clipboard.writeText( $t.val() );
		} else {
			document.execCommand( 'copy' );
		}
		$( this ).text( cfg.copied );
	} );

	// Confirmations.
	$( document ).on( 'click', '.pnr-confirm', function ( e ) {
		if ( ! window.confirm( $( this ).data( 'confirm' ) || cfg.confirm ) ) {
			e.preventDefault();
		}
	} );

	// Copy buttons.
	$( document ).on( 'click', '.pnr-copy', function () {
		var $btn = $( this );
		var text = $btn.data( 'copy' );
		var done = function () {
			$btn.text( cfg.copied );
		};
		if ( navigator.clipboard && navigator.clipboard.writeText ) {
			navigator.clipboard.writeText( text ).then( done );
		} else {
			var $tmp = $( '<textarea/>' ).val( text ).appendTo( 'body' ).trigger( 'select' );
			document.execCommand( 'copy' );
			$tmp.remove();
			done();
		}
	} );
}( jQuery ) );
