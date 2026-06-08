/* global AILinking */
( function () {
	'use strict';

	if ( typeof AILinking === 'undefined' ) {
		return;
	}

	var cfg = AILinking;

	/**
	 * POST to admin-ajax and return the parsed JSON envelope.
	 *
	 * @param {string} action WP AJAX action.
	 * @param {Object} params Extra params.
	 * @return {Promise<Object>}
	 */
	function post( action, params ) {
		var body = new URLSearchParams();
		body.append( 'action', action );
		body.append( 'nonce', cfg.nonce );
		Object.keys( params || {} ).forEach( function ( k ) {
			body.append( k, params[ k ] );
		} );

		return fetch( cfg.ajaxUrl, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
			body: body.toString(),
		} ).then( function ( r ) {
			return r.json();
		} );
	}

	function setBar( box, percent, label ) {
		if ( ! box ) {
			return;
		}
		box.style.display = 'block';
		var span = box.querySelector( '.ailinking-bar span' );
		var text = box.querySelector( '.ailinking-progress-label' );
		if ( span ) {
			span.style.width = percent + '%';
		}
		if ( text ) {
			text.textContent = label;
		}
	}

	/**
	 * Drive a batched job to completion.
	 *
	 * @param {string} action     AJAX action.
	 * @param {string} boxSel      Progress container selector.
	 * @param {string} runningText Status verb.
	 * @param {boolean} showCreated Show "created" count (suggestion scan).
	 */
	function runJob( action, boxSel, runningText, showCreated ) {
		var box = document.querySelector( boxSel );
		var start = 1;

		function step() {
			return post( action, { start: start } ).then( function ( res ) {
				start = 0;
				if ( ! res || ! res.success ) {
					setBar( box, 100, cfg.i18n.error );
					return true;
				}
				var d = res.data;
				var label = runningText + ' ' + d.processed + ' / ' + d.total;
				if ( showCreated ) {
					label += '  (' + d.created + ' found)';
				}
				setBar( box, d.percent, d.done ? cfg.i18n.done : label );
				return !! d.done;
			} ).catch( function () {
				setBar( box, 100, cfg.i18n.error );
				return true;
			} );
		}

		function loop() {
			step().then( function ( done ) {
				if ( ! done ) {
					loop();
				} else {
					setButtonsDisabled( false );
				}
			} );
		}

		setButtonsDisabled( true );
		loop();
	}

	function setButtonsDisabled( state ) {
		[ 'ailinking-run-index', 'ailinking-run-suggest' ].forEach( function ( id ) {
			var b = document.getElementById( id );
			if ( b ) {
				b.disabled = state;
			}
		} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var idxBtn = document.getElementById( 'ailinking-run-index' );
		if ( idxBtn ) {
			idxBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( cfg.i18n.confirmReset ) ) {
					return;
				}
				runJob( 'ailinking_run_index', '#ailinking-progress-index', cfg.i18n.indexing, false );
			} );
		}

		var sugBtn = document.getElementById( 'ailinking-run-suggest' );
		if ( sugBtn ) {
			sugBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				runJob( 'ailinking_run_suggest', '#ailinking-progress-suggest', cfg.i18n.scanning, true );
			} );
		}

		// Suggestion approve/reject/restore.
		var acts = document.querySelectorAll( '.ailinking-act' );
		acts.forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var row = btn.closest( 'tr' );
				if ( ! row ) {
					return;
				}
				var id = row.getAttribute( 'data-id' );
				var status = btn.getAttribute( 'data-status' );
				btn.disabled = true;
				post( 'ailinking_set_status', { id: id, status: status } ).then( function ( res ) {
					if ( res && res.success ) {
						window.location.reload();
					} else {
						btn.disabled = false;
						window.alert( cfg.i18n.error );
					}
				} ).catch( function () {
					btn.disabled = false;
					window.alert( cfg.i18n.error );
				} );
			} );
		} );
	} );
} )();
