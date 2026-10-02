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
