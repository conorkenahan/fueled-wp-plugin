/**
 * Front-end JavaScript for the Featured Content Block.
 *
 * Vanilla JS only — no jQuery, no framework. Enqueued by PHP only on pages
 * that contain the block (has_block guard), so this file never loads globally.
 *
 * Responsibilities:
 *   1. Fade cards in as they scroll into view (IntersectionObserver).
 *   2. Enhance keyboard focus styles on card links for older browsers.
 */

( function () {
	'use strict';

	// Selectors match the PHP template and BEM classes in style.css.
	var BLOCK_SELECTOR = '.wp-block-featured-content-block-featured-posts';
	var CARD_SELECTOR  = '.fcb-card';
	var LINK_SELECTOR  = '.fcb-card__link';

	// -------------------------------------------------------------------------
	// Scroll-in animation
	// -------------------------------------------------------------------------

	/**
	 * Observe each card in the block and add .fcb-is-visible when it enters
	 * the viewport. style.css defines the transition; JS only toggles the class.
	 *
	 * Cards are marked with .fcb-will-animate by this function (not by PHP) so
	 * that users without JavaScript see fully visible cards from the start.
	 *
	 * @param {Element} block  The block wrapper element.
	 */
	function initScrollAnimation( block ) {
		var cards = block.querySelectorAll( CARD_SELECTOR );

		// Browsers without IntersectionObserver support (IE 11) see all cards
		// immediately — graceful degradation without a polyfill.
		if ( ! ( 'IntersectionObserver' in window ) ) {
			return;
		}

		// Respect the user's system-level motion preference before setting up
		// the animation. The CSS also guards this, but checking here avoids
		// adding/removing classes unnecessarily.
		if ( window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches ) {
			return;
		}

		// Mark cards as animation-pending now that we know JS is running.
		cards.forEach( function ( card ) {
			card.classList.add( 'fcb-will-animate' );
		} );

		var observer = new IntersectionObserver(
			function ( entries ) {
				entries.forEach( function ( entry ) {
					if ( entry.isIntersecting ) {
						entry.target.classList.add( 'fcb-is-visible' );
						// Stop watching once the card has animated in — no reason
						// to fire again if the user scrolls back up and down.
						observer.unobserve( entry.target );
					}
				} );
			},
			{
				// Fire when 10% of the card is in view. rootMargin pulls the
				// trigger point 40px above the viewport bottom so the animation
				// completes before the card is fully scrolled into view.
				threshold:  0.1,
				rootMargin: '0px 0px -40px 0px',
			}
		);

		cards.forEach( function ( card ) {
			observer.observe( card );
		} );
	}

	// -------------------------------------------------------------------------
	// Keyboard focus enhancement
	// -------------------------------------------------------------------------

	/**
	 * Add .fcb-card--focused to the card whose link gains focus via keyboard,
	 * remove it on blur.
	 *
	 * Modern browsers handle this natively with the :focus-visible CSS pseudo-
	 * class defined in style.css. This JS enhancement provides the same visual
	 * treatment in older browsers that don't support :focus-visible.
	 *
	 * @param {Element} block  The block wrapper element.
	 */
	function initFocusStyles( block ) {
		// focusin/focusout bubble — a single listener on the block handles all
		// child links without attaching per-card handlers.
		block.addEventListener( 'focusin', function ( event ) {
			var link = event.target.closest( LINK_SELECTOR );
			if ( link ) {
				var card = link.closest( CARD_SELECTOR );
				if ( card ) {
					card.classList.add( 'fcb-card--focused' );
				}
			}
		} );

		block.addEventListener( 'focusout', function ( event ) {
			var link = event.target.closest( LINK_SELECTOR );
			if ( link ) {
				var card = link.closest( CARD_SELECTOR );
				if ( card ) {
					card.classList.remove( 'fcb-card--focused' );
				}
			}
		} );
	}

	// -------------------------------------------------------------------------
	// Bootstrap
	// -------------------------------------------------------------------------

	function init() {
		var blocks = document.querySelectorAll( BLOCK_SELECTOR );

		// PHP guards with has_block(), so this is a safety net only.
		if ( ! blocks.length ) {
			return;
		}

		// Support multiple block instances on the same page independently.
		blocks.forEach( function ( block ) {
			initScrollAnimation( block );
			initFocusStyles( block );
		} );
	}

	// Run immediately if the DOM is already parsed (script loaded in footer),
	// otherwise wait for DOMContentLoaded.
	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
} )();
