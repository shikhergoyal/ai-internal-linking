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

		// Suggestion approve/reject/restore (status only).
		bindAll( '.ailinking-act', function ( btn, row ) {
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
			} ).catch( reEnable( btn ) );
		} );

		// Apply an approved suggestion to content.
		bindAll( '.ailinking-apply', function ( btn, row ) {
			var id = row.getAttribute( 'data-id' );
			btn.disabled = true;
			post( 'ailinking_apply', { id: id } ).then( function ( res ) {
				if ( res && res.success && res.data && res.data.ok ) {
					window.location.reload();
				} else {
					btn.disabled = false;
					window.alert( applyMessage( res ) );
				}
			} ).catch( reEnable( btn ) );
		} );

		// Undo an applied insertion.
		bindAll( '.ailinking-undo', function ( btn ) {
			var ledger = btn.getAttribute( 'data-ledger' );
			btn.disabled = true;
			undo( ledger, 0, btn );
		} );

		// Recompute audits + click depth.
		var auditBtn = document.getElementById( 'ailinking-run-audits' );
		if ( auditBtn ) {
			auditBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var box = document.querySelector( '#ailinking-progress-audits' );
				setBar( box, 50, cfg.i18n.auditing );
				auditBtn.disabled = true;
				post( 'ailinking_run_audits', {} ).then( function () {
					setBar( box, 100, cfg.i18n.done );
					window.location.reload();
				} ).catch( function () {
					setBar( box, 100, cfg.i18n.error );
					auditBtn.disabled = false;
				} );
			} );
		}

		// Remove all inserted links (batched).
		var rmBtn = document.getElementById( 'ailinking-remove-links' );
		if ( rmBtn ) {
			rmBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( parseInt( rmBtn.getAttribute( 'data-count' ), 10 ) === 0 ) {
					return;
				}
				if ( ! window.confirm( cfg.i18n.confirmRemove ) ) {
					return;
				}
				var box = document.querySelector( '#ailinking-progress-audits' );
				rmBtn.disabled = true;

				function loop() {
					post( 'ailinking_remove_links', {} ).then( function ( res ) {
						if ( ! res || ! res.success ) {
							setBar( box, 100, cfg.i18n.error );
							return;
						}
						setBar( box, res.data.done ? 100 : 50, cfg.i18n.removing + ' (' + res.data.remaining + ')' );
						if ( res.data.done ) {
							window.location.reload();
						} else {
							loop();
						}
					} ).catch( function () {
						setBar( box, 100, cfg.i18n.error );
					} );
				}
				loop();
			} );
		}
	} );

	function undo( ledger, force, btn ) {
		post( 'ailinking_undo', { ledger_id: ledger, force: force } ).then( function ( res ) {
			if ( res && res.success && res.data && res.data.ok ) {
				window.location.reload();
				return;
			}
			if ( res && res.data && 'modified_since' === res.data.reason && ! force ) {
				if ( window.confirm( cfg.i18n.modifiedSince ) ) {
					undo( ledger, 1, btn );
					return;
				}
			} else {
				window.alert( cfg.i18n.error );
			}
			if ( btn ) {
				btn.disabled = false;
			}
		} ).catch( function () {
			if ( btn ) {
				btn.disabled = false;
			}
			window.alert( cfg.i18n.error );
		} );
	}

	function applyMessage( res ) {
		var reason = res && res.data ? res.data.reason : '';
		if ( 'suggest_only' === reason ) {
			return cfg.i18n.error;
		}
		return cfg.i18n.error;
	}

	function bindAll( selector, handler ) {
		document.querySelectorAll( selector ).forEach( function ( btn ) {
			btn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var row = btn.closest( 'tr' );
				handler( btn, row );
			} );
		} );
	}

	function reEnable( btn ) {
		return function () {
			btn.disabled = false;
			window.alert( cfg.i18n.error );
		};
	}
} )();
