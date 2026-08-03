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
			var v = params[ k ];
			if ( Array.isArray( v ) ) {
				// PHP reads repeated name[] pairs as an array; a joined string
				// would arrive as one value and every id would be lost but the first.
				v.forEach( function ( item ) {
					body.append( k + '[]', item );
				} );
				return;
			}
			body.append( k, v );
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
		if ( ! usage || ! usage.requests ) {
			el.style.display = 'none';
			return;
		}
		// Markup is composed and escaped server-side, so the figures drawn here
		// and the ones drawn on page load come from the same code.
		if ( usage.html ) {
			el.innerHTML = usage.html;
		} else if ( usage.text ) {
			el.textContent = usage.text;
		}
		el.style.display = 'block';
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

		// --- Model pickers -------------------------------------------------
		// Each model select follows a provider select named in data-follows, and
		// rebuilds its options from that provider's known models. Typing an id by
		// hand is still possible via "Other model…", because a provider can ship
		// a new model long before this plugin knows its name.
		Array.prototype.slice.call( document.querySelectorAll( '.ailinking-model-select' ) )
			.forEach( function ( sel ) {
				var provider = document.getElementById( sel.getAttribute( 'data-follows' ) );
				var custom = sel.parentNode.querySelector( '.ailinking-model-custom' );
				if ( ! provider || ! custom ) {
					return;
				}

				function showCustom( on ) {
					custom.style.display = on ? 'inline-block' : 'none';
					if ( on ) {
						custom.focus();
					}
				}

				function repopulate( keepValue ) {
					var models = ( cfg.models || {} )[ provider.value ] || [];
					var wanted = keepValue ? ( sel.value === '__custom' ? '__custom' : sel.value ) : '';
					var lastCustom = sel.querySelector( 'option[value="__custom"]' );
					var label = lastCustom ? lastCustom.textContent : 'Other model…';
					var blank = sel.querySelector( 'option[value=""]' );
					var blankLabel = blank ? blank.textContent : 'Provider default';

					sel.innerHTML = '';
					sel.appendChild( new Option( blankLabel, '' ) );
					models.forEach( function ( m ) {
						sel.appendChild( new Option( m, m ) );
					} );
					sel.appendChild( new Option( label, '__custom' ) );

					// A model stored before this plugin knew about it must not be
					// silently dropped just because it is missing from the list.
					if ( wanted && '__custom' !== wanted && models.indexOf( wanted ) === -1 ) {
						custom.value = wanted;
						wanted = '__custom';
					}
					sel.value = wanted || '';
					showCustom( '__custom' === sel.value );
				}

				provider.addEventListener( 'change', function () {
					repopulate( false );
				} );
				sel.addEventListener( 'change', function () {
					showCustom( '__custom' === sel.value );
				} );
			} );

		// Hide key-form fields that the chosen provider has no use for. The hint
		// text said "only for Custom / Local / Azure", which is fine to read and
		// still leaves two irrelevant boxes on screen for everyone else.
		( function () {
			var provider = document.getElementById( 'provider' );
			var rows = Array.prototype.slice.call( document.querySelectorAll( '.ailinking-provider-only' ) );
			if ( ! provider || ! rows.length ) {
				return;
			}
			function sync() {
				var needsBase = ( cfg.needsBase || {} )[ provider.value ];
				rows.forEach( function ( th ) {
					var row = th.closest ? th.closest( 'tr' ) : th.parentNode;
					if ( ! row ) {
						return;
					}
					var needs = th.getAttribute( 'data-needs' );
					// Azure is the only provider with an api-version.
					var show = ( 'api_version' === needs ) ? ( 'azure' === provider.value ) : !! needsBase;
					row.style.display = show ? '' : 'none';
				} );
			}
			provider.addEventListener( 'change', sync );
			sync();
		} )();

		// --- Bulk selection and actions -----------------------------------
		// Chunked on purpose: applying writes to a post per row, so one request
		// carrying 500 of them would run past the PHP time limit and leave the
		// user with no idea how far it got. 25 at a time keeps each request
		// short and lets the count climb while it works.
		var BULK_CHUNK = 25;

		var cbAll    = document.getElementById( 'ailinking-cb-all' );
		var bulkOp   = document.getElementById( 'ailinking-bulk-op' );
		var bulkGo   = document.getElementById( 'ailinking-bulk-go' );
		var bulkNum  = document.getElementById( 'ailinking-bulk-count' );
		var bulkStat = document.getElementById( 'ailinking-bulk-status' );

		function boxes() {
			return Array.prototype.slice.call( document.querySelectorAll( '.ailinking-cb' ) );
		}

		function chosen() {
			return boxes().filter( function ( b ) {
				return b.checked;
			} );
		}

		function refreshBulk() {
			if ( ! bulkNum ) {
				return;
			}
			var n = chosen().length;
			bulkNum.textContent = n
				? ( cfg.i18n.nSelected || '%s selected' ).replace( '%s', n )
				: ( cfg.i18n.noneSelected || 'Nothing selected' );
			if ( bulkGo ) {
				bulkGo.disabled = ! n || ! bulkOp || ! bulkOp.value;
			}
			if ( cbAll ) {
				var all = boxes();
				cbAll.checked = all.length > 0 && n === all.length;
			}
		}

		if ( cbAll ) {
			cbAll.addEventListener( 'change', function () {
				boxes().forEach( function ( b ) {
					b.checked = cbAll.checked;
				} );
				refreshBulk();
			} );
		}
		boxes().forEach( function ( b ) {
			b.addEventListener( 'change', refreshBulk );
		} );
		if ( bulkOp ) {
			bulkOp.addEventListener( 'change', refreshBulk );
		}

		if ( bulkGo ) {
			bulkGo.addEventListener( 'click', function () {
				var op = bulkOp ? bulkOp.value : '';
				var picked = chosen();
				if ( ! op || ! picked.length ) {
					return;
				}

				var ids = picked.map( function ( b ) {
					return b.value;
				} );

				// Say up front how many cannot be written to, rather than
				// reporting it as a failure afterwards.
				if ( 'apply' === op ) {
					var manual = picked.filter( function ( b ) {
						return 'auto' !== b.getAttribute( 'data-safety' );
					} ).length;
					var msg = ( cfg.i18n.confirmBulkApply || 'Apply %s links to your content?' )
						.replace( '%s', ids.length );
					if ( manual ) {
						msg += '\n\n' + ( cfg.i18n.bulkManualNote || '%s are page-builder pages and will be skipped.' )
							.replace( '%s', manual );
					}
					if ( ! window.confirm( msg ) ) {
						return;
					}
				}

				bulkGo.disabled = true;
				if ( bulkOp ) {
					bulkOp.disabled = true;
				}

				var done = 0;
				var changed = 0;
				var failed = 0;
				var reasons = [];

				function step() {
					if ( ! ids.length ) {
						var summary = ( cfg.i18n.bulkDone || '%1$s of %2$s done' )
							.replace( '%1$s', changed )
							.replace( '%2$s', done );
						if ( failed ) {
							summary += ' — ' + ( cfg.i18n.bulkSkipped || '%s skipped' ).replace( '%s', failed );
						}
						if ( reasons.length ) {
							window.alert( summary + '\n\n' + reasons.join( '\n' ) );
						}
						bulkStat.textContent = summary;
						window.location.reload();
						return;
					}

					var chunk = ids.splice( 0, BULK_CHUNK );
					done += chunk.length;
					bulkStat.textContent = ( cfg.i18n.bulkWorking || 'Working… %s' ).replace( '%s', done );

					post( 'ailinking_bulk', { op: op, ids: chunk } ).then( function ( res ) {
						if ( ! res || ! res.success ) {
							bulkStat.textContent = cfg.i18n.error;
							bulkGo.disabled = false;
							if ( bulkOp ) {
								bulkOp.disabled = false;
							}
							return;
						}
						changed += res.data.changed || 0;
						failed += res.data.failed || 0;
						( res.data.reasons || [] ).forEach( function ( r ) {
							if ( reasons.indexOf( r ) === -1 ) {
								reasons.push( r );
							}
						} );
						step();
					} ).catch( function () {
						bulkStat.textContent = cfg.i18n.error;
						bulkGo.disabled = false;
						if ( bulkOp ) {
							bulkOp.disabled = false;
						}
					} );
				}
				step();
			} );
		}
		refreshBulk();

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

		// --- AI engine budget controls ------------------------------------
		// Two preset-or-custom pairs and one plain select, all feeding a live
		// cost estimate so the price of a change is visible before saving it.

		function pickedValue( selectId, numberId ) {
			var sel = document.getElementById( selectId );
			if ( ! sel ) {
				return 0;
			}
			if ( 'custom' === sel.value ) {
				var num = document.getElementById( numberId );
				return num ? parseInt( num.value, 10 ) || 0 : 0;
			}
			return parseInt( sel.value, 10 ) || 0;
		}

		function money( cents ) {
			if ( cents < 100 ) {
				return '$' + ( cents / 100 ).toFixed( cents < 10 ? 3 : 2 );
			}
			return '$' + ( cents / 100 ).toFixed( 2 );
		}

		function thousands( n ) {
			return String( Math.round( n ) ).replace( /\B(?=(\d{3})+(?!\d))/g, ',' );
		}

		function updateEstimate() {
			var box = document.getElementById( 'ailinking-ai-estimate' );
			if ( ! box ) {
				return;
			}
			var d = box.dataset;
			var pageWords = pickedValue( 'ailinking-ai-words', 'ailinking-ai-words-custom' );
			var cands     = pickedValue( 'ailinking-ai-candidates', 'ailinking-ai-candidates-custom' );
			// How much each destination carries depends on how it is described.
			var modeSel = document.getElementById( 'ailinking-ai-describe' );
			var mode = modeSel ? modeSel.value : 'keywords';
			var candWords = 0;
			if ( 'summary' === mode ) {
				var swSel = document.getElementById( 'ailinking-ai-summary-words' );
				candWords = swSel ? parseInt( swSel.value, 10 ) || 0 : 0;
			} else if ( 'keywords' === mode ) {
				var cwSel = document.getElementById( 'ailinking-ai-candidate-words' );
				candWords = cwSel ? parseInt( cwSel.value, 10 ) || 0 : 0;
			}

			// Mirrors LlmSuggester::estimate_tokens(); the coefficients come
			// from the PHP constants via data attributes, so there is one source.
			var words  = pageWords + cands * ( parseFloat( d.titleWords ) + candWords );
			var tokIn  = Math.round( parseFloat( d.overhead ) + words * parseFloat( d.perWord ) );
			var tokOut = parseInt( d.reply, 10 ) || 0;
			var pages  = parseInt( d.pages, 10 ) || 0;
			var perPage = tokIn + tokOut;

			if ( ! pages ) {
				box.textContent = ( cfg.i18n.estimateNone || '%s tokens per page' )
					.replace( '%s', thousands( perPage ) );
				return;
			}

			var cents = ( tokIn * parseFloat( d.rateIn ) + tokOut * parseFloat( d.rateOut ) ) / 1000000;
			box.textContent = ( cfg.i18n.estimate || '%1$s tokens per page, %2$s per scan of %3$s pages' )
				.replace( '%1$s', thousands( perPage ) )
				.replace( '%2$s', money( cents * pages ) )
				.replace( '%3$s', thousands( pages ) );
		}

		[
			[ 'ailinking-ai-words', 'ailinking-ai-words-custom' ],
			[ 'ailinking-ai-candidates', 'ailinking-ai-candidates-custom' ]
		].forEach( function ( pair ) {
			var sel = document.getElementById( pair[0] );
			var num = document.getElementById( pair[1] );
			if ( ! sel || ! num ) {
				return;
			}
			sel.addEventListener( 'change', function () {
				var custom = 'custom' === sel.value;
				num.style.display = custom ? 'inline-block' : 'none';
				if ( custom ) {
					num.focus();
				} else {
					// Keep the field in step so it shows the value actually in use.
					num.value = sel.value;
				}
				updateEstimate();
			} );
			num.addEventListener( 'input', updateEstimate );
		} );

		// Show only the length control that belongs to the chosen description mode.
		var describeSel = document.getElementById( 'ailinking-ai-describe' );
		function syncDescribeMode() {
			var picked = describeSel ? describeSel.value : '';
			Array.prototype.slice.call( document.querySelectorAll( '.ailinking-describe-len' ) )
				.forEach( function ( box ) {
					box.style.display = ( box.getAttribute( 'data-mode' ) === picked ) ? 'inline' : 'none';
				} );
			updateEstimate();
		}
		if ( describeSel ) {
			describeSel.addEventListener( 'change', syncDescribeMode );
			syncDescribeMode();
		}

		[ 'ailinking-ai-candidate-words', 'ailinking-ai-summary-words' ].forEach( function ( id ) {
			var el = document.getElementById( id );
			if ( el ) {
				el.addEventListener( 'change', updateEstimate );
			}
		} );
		updateEstimate();

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
