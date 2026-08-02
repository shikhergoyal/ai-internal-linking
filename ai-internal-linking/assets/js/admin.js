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
	 * Show tokens + estimated cost burned by the current run. Stays hidden
	 * until a provider call actually happens, so key-free runs (the TF-IDF
	 * default) show no AI clutter.
	 *
	 * @param {Element} box   Progress container.
	 * @param {Object}  usage Usage payload from the server, or null.
	 */
	function setUsage( box, usage ) {
		if ( ! box ) {
			return;
		}
		var el = box.querySelector( '.ailinking-usage' );
		if ( ! el ) {
			return;
		}
		if ( usage && usage.requests > 0 && usage.text ) {
			el.textContent = usage.text;
			el.style.display = 'block';
		} else {
			el.style.display = 'none';
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
				var problem = d.error || d.last_error || '';
				var doneLabel = problem ? ( cfg.i18n.error + ': ' + problem ) : cfg.i18n.done;
				setBar( box, d.percent, d.done ? doneLabel : label );
				setUsage( box, d.usage );
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

		// Suggestion scan: Start / Pause / Resume / Stop (server-resumable).
		( function () {
			var box     = document.querySelector( '#ailinking-progress-suggest' );
			var scanBtn = document.getElementById( 'ailinking-run-suggest' );
			var pauseB  = document.getElementById( 'ailinking-pause-suggest' );
			var resumeB = document.getElementById( 'ailinking-resume-suggest' );
			var stopB   = document.getElementById( 'ailinking-stop-suggest' );
			if ( ! box || ! scanBtn ) {
				return;
			}
			var looping = false;

			function shown( el, on ) {
				if ( el ) {
					el.style.display = on ? 'inline-block' : 'none';
				}
			}
			function ui( state ) { // 'idle' | 'running' | 'paused'
				shown( scanBtn, state === 'idle' );
				shown( pauseB, state === 'running' );
				shown( resumeB, state === 'paused' );
				shown( stopB, state === 'running' || state === 'paused' );
			}
			function scanLabel( d ) {
				return cfg.i18n.scanning + ' ' + d.processed + ' / ' + d.total + '  (' + d.created + ' ' + cfg.i18n.found + ')';
			}
			function pausedLabel( d ) {
				return cfg.i18n.paused + ' ' + ( d.processed || 0 ) + ' / ' + ( d.total || 0 );
			}

			function tick( start ) {
				post( 'ailinking_run_suggest', { start: start } ).then( function ( res ) {
					if ( ! looping ) {
						return;
					}
					if ( ! res || ! res.success ) {
						setBar( box, 100, cfg.i18n.error );
						looping = false;
						ui( 'idle' );
						return;
					}
					var d = res.data;
					var problem = d.last_error || '';
					setUsage( box, d.usage );
					if ( 'paused' === d.status ) {
						setBar( box, d.percent, pausedLabel( d ) );
						looping = false;
						ui( 'paused' );
						return;
					}
					if ( d.done ) {
						setBar( box, 100, problem ? ( cfg.i18n.error + ': ' + problem ) : cfg.i18n.done );
						looping = false;
						ui( 'idle' );
						return;
					}
					setBar( box, d.percent, scanLabel( d ) );
					tick( 0 );
				} ).catch( function () {
					if ( looping ) {
						setBar( box, 100, cfg.i18n.error );
						looping = false;
						ui( 'idle' );
					}
				} );
			}
			function drive( start ) {
				looping = true;
				ui( 'running' );
				setBar( box, start ? 0 : ( parseInt( box.getAttribute( 'data-scan-percent' ), 10 ) || 0 ), cfg.i18n.scanning );
				tick( start ? 1 : 0 );
			}

			scanBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				if ( ! window.confirm( cfg.i18n.confirmScan ) ) {
					return;
				}
				drive( 1 );
			} );
			if ( resumeB ) {
				resumeB.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					resumeB.disabled = true;
					post( 'ailinking_scan_control', { do: 'resume' } ).then( function () {
						resumeB.disabled = false;
						drive( 0 );
					} ).catch( function () {
						resumeB.disabled = false;
					} );
				} );
			}
			if ( pauseB ) {
				pauseB.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					looping = false;
					pauseB.disabled = true;
					post( 'ailinking_scan_control', { do: 'pause' } ).then( function ( res ) {
						pauseB.disabled = false;
						var d = ( res && res.data ) ? res.data : {};
						setBar( box, d.percent || 0, pausedLabel( d ) );
						setUsage( box, d.usage );
						ui( 'paused' );
					} ).catch( function () {
						pauseB.disabled = false;
						ui( 'paused' );
					} );
				} );
			}
			if ( stopB ) {
				stopB.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					looping = false;
					post( 'ailinking_scan_control', { do: 'stop' } ).then( function () {
						setBar( box, 100, cfg.i18n.done );
						ui( 'idle' );
					} );
				} );
			}

			// Initial state from the server (survives navigation): resumable if a
			// scan was left running/paused mid-way.
			var st = box.getAttribute( 'data-scan-status' );
			ui( ( 'running' === st || 'paused' === st ) ? 'paused' : 'idle' );
		} )();

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

		// Fetch Search Console data (Keywords page), one API page per request.
		var gscBtn = document.getElementById( 'ailinking-gsc-fetch' );
		if ( gscBtn ) {
			gscBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var box = document.querySelector( '#ailinking-progress-gsc' );
				var start = 1;
				gscBtn.disabled = true;

				function loop() {
					post( 'ailinking_gsc_fetch', { start: start } ).then( function ( res ) {
						start = 0;
						if ( ! res || ! res.success ) {
							setBar( box, 100, cfg.i18n.error );
							gscBtn.disabled = false;
							return;
						}
						var d = res.data;
						var problem = d.last_error || '';
						if ( d.done ) {
							setBar( box, 100, problem ? ( cfg.i18n.error + ': ' + problem ) : ( cfg.i18n.done + ' (' + d.created + ')' ) );
							window.setTimeout( function () {
								window.location.reload();
							}, 900 );
						} else {
							setBar( box, d.percent, cfg.i18n.fetchingGsc + ' ' + d.processed );
							loop();
						}
					} ).catch( function () {
						setBar( box, 100, cfg.i18n.error );
						gscBtn.disabled = false;
					} );
				}
				loop();
			} );
		}

		// Test a provider connection (add-key form).
		var testBtn = document.getElementById( 'ailinking-test-conn' );
		if ( testBtn ) {
			testBtn.addEventListener( 'click', function ( e ) {
				e.preventDefault();
				var out = document.getElementById( 'ailinking-test-result' );
				if ( out ) {
					out.textContent = cfg.i18n.testing;
				}
				testBtn.disabled = true;
				post( 'ailinking_test_connection', {
					provider: val( 'provider' ),
					api_key: val( 'api_key' ),
					base_url: val( 'base_url' ),
					model: val( 'model' )
				} ).then( function ( res ) {
					testBtn.disabled = false;
					if ( out ) {
						out.textContent = ( res && res.success && res.data ) ? res.data.message : cfg.i18n.error;
					}
				} ).catch( function () {
					testBtn.disabled = false;
					if ( out ) {
						out.textContent = cfg.i18n.error;
					}
				} );
			} );
		}

	} );

	function val( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

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
			return cfg.i18n.suggestOnly;
		}
		if ( 'anchor_not_found' === reason || 'anchor_ambiguous' === reason || 'integrity_check_failed' === reason ) {
			return cfg.i18n.cantPlace;
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
