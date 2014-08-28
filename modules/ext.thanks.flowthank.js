( function ( $, mw ) {
	'use strict';

	mw.thanks.thanked.cookieName = 'flow-thanked';
	mw.thanks.thanked.attrName = 'data-flow-id';

	var $thankedLabel = $( '<span></span>' )
		.append( mw.msg( 'thanks-button-thanked', mw.user ) )
		.addClass( 'mw-thanks-flow-thanked mw-ui-quiet' );

	var reloadThankedState = function() {
		$( 'a.mw-thanks-flow-thank-link' ).each( function( idx, el ) {
			var $thankLink = $( el );
			if ( mw.thanks.thanked.contains( $thankLink.closest( '.flow-post' ) ) ) {
				$thankLink.before( $thankedLabel.clone() );
				$thankLink.remove();
			}
		} );
	};

	var sendFlowThanks = function( $thankLink ) {
		( new mw.Api ).post( {
			'action' : 'flowthank',
			'postid' : $thankLink.closest( '.flow-post' ).attr( mw.thanks.thanked.attrName ),
			'token' : mw.user.tokens.get( 'editToken' )
		} )
		.done( function( data ) {
			mw.thanks.thanked.push( $thankLink.closest( '.flow-post' ) );
			$thankLink.before( $thankedLabel.clone() );
			$thankLink.remove();
		} )
		.fail( function( errorCode, details ) {
			// TODO: use something besides alert for the error messages
			switch( errorCode ) {
				case 'ratelimited':
					alert( mw.msg( 'thanks-error-ratelimited', mw.user ) );
					break;
				default:
					alert( mw.msg( 'thanks-error-undefined' ) );
			}
		} );
	};

	if ( $.isReady ) {
		// This condition is required for soft-reloads
		// to also trigger the reloadThankedState
		reloadThankedState();
	} else {
		$( document ).ready( reloadThankedState );
	}

	// .on() is needed to make the button work for dynamically loaded posts
	$( '.flow-board' ).on( 'click', 'a.mw-thanks-flow-thank-link', function( e ) {
		var $thankLink = $( this );
		e.preventDefault();
		sendFlowThanks( $thankLink );
	} );

} )( jQuery, mediaWiki );
