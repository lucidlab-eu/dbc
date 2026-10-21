/* Lucid Lab Database Cleaner admin screen. No dependencies. */
( function () {
	'use strict';

	const cfg = window.LucidLabDBC || {};
	const t = cfg.i18n || {};
	const root = document.querySelector( '.lucidlab-dbc-wrap' );
	if ( ! root ) {
		return;
	}

	function fmt( str ) {
		const args = Array.prototype.slice.call( arguments, 1 );
		let i = 0;
		return String( str || '' )
			.replace( /%(\d+)\$s/g, function ( m, n ) {
				return args[ n - 1 ] !== undefined ? args[ n - 1 ] : '';
			} )
			.replace( /%s/g, function () {
				return args[ i++ ] !== undefined ? args[ i - 1 ] : '';
			} )
			.replace( /%%/g, '%' );
	}

	function bytes( n ) {
		const units = [ 'B', 'KB', 'MB', 'GB' ];
		let u = 0;
		n = Number( n ) || 0;
		while ( n >= 1024 && u < units.length - 1 ) {
			n /= 1024;
			u++;
		}
		return (
			( u ? n.toFixed( 1 ).replace( /\.0$/, '' ) : Math.round( n ) ) +
			' ' +
			units[ u ]
		);
	}

	/**
	 * Shared busy state of every .lldbc-btn: white with a border in the button colour, a spinner
	 * before the label, disabled and aria-busy until busy( button, false ) or a page load.
	 *
	 * @param {HTMLElement} button Button or link.
	 * @param {boolean}     on     Busy or not.
	 */
	function busy( button, on ) {
		if ( ! button ) {
			return;
		}
		const isLink = button.tagName === 'A';
		if ( on ) {
			if ( button.classList.contains( 'is-busy' ) ) {
				return;
			}
			button.setAttribute(
				'data-dbc-was-disabled',
				button.disabled ? '1' : '0'
			);
			button.classList.add( 'is-busy' );
			button.setAttribute( 'aria-busy', 'true' );
			if ( isLink ) {
				button.setAttribute( 'aria-disabled', 'true' );
			} else {
				button.disabled = true;
			}
			const spin = document.createElement( 'span' );
			spin.className = 'lldbc-btn-spinner';
			spin.setAttribute( 'aria-hidden', 'true' );
			button.insertBefore( spin, button.firstChild );
			return;
		}
		button.classList.remove( 'is-busy' );
		button.removeAttribute( 'aria-busy' );
		const spin = button.querySelector( '.lldbc-btn-spinner' );
		if ( spin ) {
			spin.parentNode.removeChild( spin );
		}
		if ( isLink ) {
			button.removeAttribute( 'aria-disabled' );
		} else {
			button.disabled =
				button.getAttribute( 'data-dbc-was-disabled' ) === '1';
		}
	}

	/**
	 * Whether a link opens in a new tab or window, or leaves this site (such as the "Get Pro"
	 * links to lucidlab.eu). The current page stays, so such links never get the busy state.
	 *
	 * @param {HTMLAnchorElement} link Link.
	 * @return {boolean} True for new-tab and external links.
	 */
	function opensElsewhere( link ) {
		const target = ( link.getAttribute( 'target' ) || '' ).toLowerCase();
		if ( target !== '' && target !== '_self' ) {
			return true;
		}
		if ( link.classList.contains( 'lucidlab-dbc-pro-link' ) ) {
			return true;
		}
		return (
			!! link.host &&
			( link.host !== window.location.host ||
				link.protocol !== window.location.protocol )
		);
	}

	// Links styled as buttons: busy while the next page or the download starts. Links that open
	// in a new tab or go to another site stay normal buttons (no spinner, never disabled).
	root.addEventListener( 'click', function ( event ) {
		const link = event.target.closest( 'a.lldbc-btn' );
		if (
			! link ||
			opensElsewhere( link ) ||
			event.defaultPrevented ||
			event.button !== 0 ||
			event.metaKey ||
			event.ctrlKey ||
			event.shiftKey
		) {
			return;
		}
		if ( link.classList.contains( 'is-busy' ) ) {
			event.preventDefault();
			return;
		}
		busy( link, true );
		if ( link.hasAttribute( 'data-dbc-download' ) ) {
			// A download does not leave the page; release the button once it has started.
			window.setTimeout( function () {
				busy( link, false );
			}, 4000 );
		}
	} );

	// Buttons come back from the back/forward cache without their busy state.
	window.addEventListener( 'pageshow', function ( event ) {
		if ( event.persisted ) {
			root.querySelectorAll( '.lldbc-btn.is-busy' ).forEach(
				function ( b ) {
					busy( b, false );
				}
			);
		}
	} );

	async function call( op, data ) {
		const body = new FormData();
		body.append( 'action', 'lucidlab_dbc' );
		body.append( 'nonce', cfg.nonce );
		body.append( 'op', op );
		Object.keys( data || {} ).forEach( function ( key ) {
			const value = data[ key ];
			if ( Array.isArray( value ) ) {
				value.forEach( function ( v ) {
					body.append( key + '[]', v );
				} );
			} else {
				body.append( key, value );
			}
		} );
		let response;
		try {
			response = await fetch( cfg.ajaxUrl, {
				method: 'POST',
				credentials: 'same-origin',
				body,
			} );
		} catch {
			throw new Error( t.failed );
		}
		let json = null;
		try {
			json = await response.json();
		} catch {
			throw new Error( t.failed );
		}
		if ( ! json || ! json.success ) {
			throw new Error(
				( json && json.data && json.data.message ) || t.failed
			);
		}
		return json.data;
	}

	function progress( box, pct, text, isError ) {
		if ( ! box ) {
			return;
		}
		box.hidden = false;
		const bar = box.querySelector( '[data-dbc-progress-bar]' );
		const label = box.querySelector( '[data-dbc-progress-text]' );
		if ( bar && pct !== null ) {
			bar.style.width = Math.max( 0, Math.min( 100, pct ) ) + '%';
		}
		if ( label ) {
			label.textContent = text;
			label.style.color = isError ? '#a12224' : '';
		}
	}

	/**
	 * Show the problems of a finished job in the (aria-live) progress text, with a link to the
	 * summary, instead of a blocking alert.
	 *
	 * @param {HTMLElement} box  Progress box.
	 * @param {string[]}    list Messages.
	 * @param {string}      url  Link target.
	 */
	function showProblems( box, list, url ) {
		progress( box, 100, t.problems + ' ' + list.join( ' · ' ), true );
		const label = box && box.querySelector( '[data-dbc-progress-text]' );
		if ( label && url ) {
			const link = document.createElement( 'a' );
			link.href = url;
			link.textContent = t.viewSummary;
			label.appendChild( document.createTextNode( ' ' ) );
			label.appendChild( link );
			link.focus();
		}
	}

	/* ---------- scan ---------- */

	async function runScan( keys, box ) {
		const labels = cfg.categories || {};
		for ( let i = 0; i < keys.length; i++ ) {
			progress(
				box,
				( i / keys.length ) * 100,
				fmt( t.scanning, labels[ keys[ i ] ] || keys[ i ] )
			);
			await call( 'scan', { category: keys[ i ] } );
		}
		progress( box, 100, t.scanDone );
	}

	root.querySelectorAll( '[data-dbc-scan]' ).forEach( function ( button ) {
		button.addEventListener( 'click', async function () {
			const only = button.getAttribute( 'data-dbc-scan' );
			const keys = only ? [ only ] : Object.keys( cfg.categories || {} );
			const box = button
				.closest( '.lucidlab-dbc-card' )
				.querySelector( '[data-dbc-progress]' );
			busy( button, true );
			try {
				await runScan( keys, box );
				window.location.reload();
			} catch ( e ) {
				progress( box, null, e.message, true );
				busy( button, false );
			}
		} );
	} );

	/* ---------- cleanup / optimize jobs ---------- */

	function wait( ms ) {
		return new Promise( function ( resolve ) {
			window.setTimeout( resolve, ms );
		} );
	}

	// Overall progress over the phases of a job.
	function jobPercent( p ) {
		const q = p.queue_total
			? Math.min( p.queue_index, p.queue_total ) / p.queue_total
			: 0;
		const plan =
			p.type === 'optimize'
				? {
						before_analyze: [ 0, 6 ],
						before_measure: [ 6, 10 ],
						optimize: [ 10, 88 ],
						after_analyze: [ 88, 96 ],
						after_measure: [ 96, 100 ],
					}
				: {
						before_analyze: [ 0, 5 ],
						before_measure: [ 5, 8 ],
						delete: [ 8, 60 ],
						optimize: [ 60, 88 ],
						after_analyze: [ 88, 96 ],
						after_measure: [ 96, 100 ],
					};
		const range = plan[ p.phase ] || [ 100, 100 ];
		const part =
			p.phase === 'delete' ? p.index / Math.max( 1, p.total ) : q;
		return (
			range[ 0 ] +
			( range[ 1 ] - range[ 0 ] ) *
				( p.phase === 'before_measure' || p.phase === 'after_measure'
					? 0
					: part )
		);
	}

	function jobText( p ) {
		const n = Math.min( p.queue_index + 1, p.queue_total );
		switch ( p.phase ) {
			case 'before_analyze':
				return fmt( t.phaseBeforeAnalyze, n, p.queue_total );
			case 'before_measure':
				return t.phaseBeforeMeasure;
			case 'delete':
				return fmt(
					t.cleaning,
					p.current,
					Math.min( p.index + 1, p.total ),
					p.total,
					p.rows
				);
			case 'optimize':
				return fmt( t.phaseOptimize, p.table, n, p.queue_total );
			case 'after_analyze':
				return fmt( t.phaseAfterAnalyze, n, p.queue_total );
			case 'after_measure':
				return t.phaseAfterMeasure;
		}
		return '';
	}

	// Runs clean_step until the job is done. Failed requests are retried: a step that hit a proxy
	// time-out during a long OPTIMIZE TABLE is finished by the database, and the next step continues.
	async function runJob( p, box ) {
		let failures = 0;
		while ( ! p.done ) {
			progress(
				box,
				jobPercent( p ),
				p.busy ? fmt( t.busy, p.table || p.current ) : jobText( p )
			);
			if ( p.busy ) {
				await wait( 3000 );
			}
			try {
				p = await call( 'clean_step', {} );
				failures = 0;
			} catch ( e ) {
				failures++;
				if ( failures > 5 || e.message !== t.failed ) {
					throw e;
				}
				progress( box, null, fmt( t.retrying, failures, 5 ) );
				await wait( 5000 );
			}
		}
		progress(
			box,
			100,
			p.savings
				? fmt(
						t.saved,
						bytes( Math.max( 0, p.savings.disk_saved ) ),
						p.savings.disk_percent
					)
				: t.rescanning
		);
		return p;
	}

	/* ---------- findings table ---------- */

	const card = root.querySelector( '[data-dbc-category]' );
	if ( card ) {
		const rows = Array.prototype.slice.call(
			card.querySelectorAll( '[data-dbc-row]' )
		);
		const selection = card.querySelector( '[data-dbc-selection]' );
		const cleanButton = card.querySelector( '[data-dbc-clean]' );
		const confirmBox = card.querySelector( '[data-dbc-confirm]' );
		const riskyWrap = card.querySelector( '[data-dbc-risky-ack-wrap]' );
		const riskyAck = card.querySelector( '[data-dbc-risky-ack]' );
		const confFilter = card.querySelector( '[data-dbc-conf-filter]' );
		let group = '';

		const checked = function () {
			return rows.filter( function ( row ) {
				const box = row.querySelector( '[data-dbc-check]' );
				return box && box.checked;
			} );
		};

		const update = function () {
			const picked = checked();
			let size = 0;
			let risky = false;
			rows.forEach( function ( row ) {
				const box = row.querySelector( '[data-dbc-check]' );
				row.classList.toggle(
					'is-selected',
					!! ( box && box.checked )
				);
			} );
			picked.forEach( function ( row ) {
				size += Number( row.getAttribute( 'data-bytes' ) ) || 0;
				risky = risky || row.getAttribute( 'data-conf' ) === 'risky';
			} );
			if ( selection ) {
				selection.textContent = picked.length
					? fmt( t.selected, picked.length, bytes( size ) )
					: t.noneSelected;
			}
			if ( cleanButton ) {
				cleanButton.disabled = ! picked.length;
			}
			if ( riskyWrap ) {
				riskyWrap.hidden = ! risky;
			}
			if ( ! picked.length && confirmBox ) {
				confirmBox.hidden = true;
			}
			return { picked, risky };
		};

		const applyFilters = function () {
			const conf = confFilter ? confFilter.value : '';
			rows.forEach( function ( row ) {
				const okGroup =
					! group || row.getAttribute( 'data-group' ) === group;
				const okConf =
					! conf || row.getAttribute( 'data-conf' ) === conf;
				row.hidden = ! ( okGroup && okConf );
			} );
		};

		card.querySelectorAll( '[data-dbc-group-filter]' ).forEach(
			function ( pill ) {
				pill.addEventListener( 'click', function () {
					group = pill.getAttribute( 'data-dbc-group-filter' );
					card.querySelectorAll( '[data-dbc-group-filter]' ).forEach(
						function ( other ) {
							other.classList.toggle(
								'nav-tab-active',
								other === pill
							);
							other.setAttribute(
								'aria-pressed',
								other === pill ? 'true' : 'false'
							);
						}
					);
					applyFilters();
				} );
			}
		);
		if ( confFilter ) {
			confFilter.addEventListener( 'change', applyFilters );
		}

		card.addEventListener( 'change', function ( event ) {
			if ( event.target.matches( '[data-dbc-check]' ) ) {
				update();
			}
		} );

		const selectAllVisible = card.querySelector(
			'[data-dbc-select-visible]'
		);
		if ( selectAllVisible ) {
			selectAllVisible.addEventListener( 'change', function () {
				rows.forEach( function ( row ) {
					const box = row.querySelector( '[data-dbc-check]' );
					// Risky findings are never selected in bulk.
					if (
						box &&
						! row.hidden &&
						row.getAttribute( 'data-conf' ) !== 'risky'
					) {
						box.checked = selectAllVisible.checked;
					}
				} );
				update();
			} );
		}

		card.querySelectorAll( '[data-dbc-select]' ).forEach(
			function ( button ) {
				button.addEventListener( 'click', function () {
					const mode = button.getAttribute( 'data-dbc-select' );
					rows.forEach( function ( row ) {
						const box = row.querySelector( '[data-dbc-check]' );
						if ( ! box ) {
							return;
						}
						if ( mode === 'none' ) {
							box.checked = false;
						} else if (
							! row.hidden &&
							row.getAttribute( 'data-conf' ) === 'safe'
						) {
							box.checked = true;
						}
					} );
					if ( selectAllVisible && mode === 'none' ) {
						selectAllVisible.checked = false;
					}
					update();
				} );
			}
		);

		if ( cleanButton && confirmBox ) {
			cleanButton.addEventListener( 'click', function () {
				const state = update();
				confirmBox.querySelector(
					'[data-dbc-confirm-count]'
				).textContent = selection.textContent;
				if ( riskyAck ) {
					riskyAck.checked = false;
				}
				confirmBox.hidden = false;
				riskyWrap.hidden = ! state.risky;
				confirmBox.scrollIntoView( {
					behavior: 'smooth',
					block: 'center',
				} );
			} );
			confirmBox
				.querySelector( '[data-dbc-confirm-no]' )
				.addEventListener( 'click', function () {
					confirmBox.hidden = true;
				} );
			confirmBox
				.querySelector( '[data-dbc-confirm-yes]' )
				.addEventListener( 'click', async function () {
					const state = update();
					const yes = this;
					if ( state.risky && riskyAck && ! riskyAck.checked ) {
						riskyWrap.style.outline = '2px solid #a4231c';
						riskyAck.focus();
						return;
					}
					const box = card.querySelector(
						'[data-dbc-clean-progress]'
					);
					const ids = state.picked.map( function ( row ) {
						return row.getAttribute( 'data-id' );
					} );
					busy( yes, true );
					busy( cleanButton, true );
					confirmBox.hidden = true;
					try {
						let p = await call( 'clean_start', {
							ids,
							risky: riskyAck && riskyAck.checked ? '1' : '0',
						} );
						p = await runJob( p, box );
						await runScan(
							p.categories && p.categories.length
								? p.categories
								: [ card.getAttribute( 'data-dbc-category' ) ],
							box
						);
						const problems = ( p.errors || [] ).concat(
							p.skipped || []
						);
						if ( problems.length ) {
							showProblems( box, problems, cfg.databaseUrl );
							return;
						}
						window.location.href = cfg.databaseUrl;
					} catch ( e ) {
						progress( box, null, e.message, true );
						busy( yes, false );
						busy( cleanButton, false );
						update();
					}
				} );
		}
		update();
	}

	/* ---------- database summary ---------- */

	const dbCard = root.querySelector( '[data-dbc-database]' );
	if ( dbCard ) {
		const dbBox = dbCard.querySelector( '[data-dbc-progress]' );
		const measure = dbCard.querySelector( '[data-dbc-measure]' );
		const optimize = dbCard.querySelector( '[data-dbc-optimize]' );
		const optConfirm = dbCard.querySelector(
			'[data-dbc-optimize-confirm]'
		);
		const runMeasure = async function () {
			busy( measure, true );
			progress( dbBox, 50, t.measuring );
			try {
				await call( 'measure', {} );
				window.location.reload();
			} catch ( e ) {
				progress( dbBox, null, e.message, true );
				busy( measure, false );
			}
		};
		measure.addEventListener( 'click', runMeasure );
		if ( measure.getAttribute( 'data-dbc-auto-measure' ) === '1' ) {
			runMeasure();
		}
		optimize.addEventListener( 'click', function () {
			optConfirm.hidden = false;
		} );
		optConfirm
			.querySelector( '[data-dbc-optimize-no]' )
			.addEventListener( 'click', function () {
				optConfirm.hidden = true;
			} );
		optConfirm
			.querySelector( '[data-dbc-optimize-yes]' )
			.addEventListener( 'click', async function () {
				const yes = this;
				busy( yes, true );
				busy( optimize, true );
				measure.disabled = true;
				optConfirm.hidden = true;
				try {
					const p = await runJob(
						await call( 'optimize_start', {} ),
						dbBox
					);
					if ( p.errors && p.errors.length ) {
						showProblems( dbBox, p.errors, cfg.databaseUrl );
						return;
					}
					window.location.reload();
				} catch ( e ) {
					progress( dbBox, null, e.message, true );
					busy( yes, false );
					busy( optimize, false );
					measure.disabled = false;
				}
			} );
	}

	/* ---------- backups ---------- */

	function armed( button ) {
		if ( button.getAttribute( 'data-dbc-armed' ) === '1' ) {
			return true;
		}
		const original = Array.prototype.map.call(
			button.childNodes,
			function ( node ) {
				return node.cloneNode( true );
			}
		);
		const label = button.textContent.trim();
		const disarm = function () {
			if ( button.getAttribute( 'data-dbc-armed' ) !== '1' ) {
				return;
			}
			button.removeAttribute( 'data-dbc-armed' );
			button.replaceChildren.apply( button, original );
			button.classList.remove( 'lucidlab-dbc-danger' );
		};
		button.setAttribute( 'data-dbc-armed', '1' );
		button.disarm = disarm;
		button.textContent = '✓ ' + label + '?';
		button.classList.add( 'lucidlab-dbc-danger' );
		window.setTimeout( disarm, 5000 );
		return false;
	}

	// Second click of an armed button: back to its normal label, then busy.
	function fire( button ) {
		if ( button.disarm ) {
			button.disarm();
		}
		busy( button, true );
	}

	root.querySelectorAll( '[data-dbc-restore]' ).forEach( function ( button ) {
		button.addEventListener( 'click', async function () {
			if ( ! armed( button ) ) {
				return;
			}
			const id = button.getAttribute( 'data-dbc-restore' );
			const status = button
				.closest( 'tr' )
				.querySelector( '[data-dbc-row-status]' );
			fire( button );
			try {
				let offset = 0;
				let step;
				do {
					step = await call( 'restore_step', { backup: id, offset } );
					offset = step.offset;
					status.textContent = fmt(
						t.restoring,
						Math.round(
							( offset / Math.max( 1, step.size ) ) * 100
						)
					);
					if ( step.errors && step.errors.length ) {
						status.textContent += ' · ' + step.errors.join( '; ' );
					}
				} while ( ! step.done );
				status.textContent = t.restored;
				busy( button, false );
				button.disabled = true;
			} catch ( e ) {
				status.textContent = e.message;
				busy( button, false );
			}
		} );
	} );

	root.querySelectorAll( '[data-dbc-delete-backup]' ).forEach(
		function ( button ) {
			button.addEventListener( 'click', async function () {
				if ( ! armed( button ) ) {
					return;
				}
				const row = button.closest( 'tr' );
				fire( button );
				try {
					await call( 'delete_backup', {
						backup: button.getAttribute( 'data-dbc-delete-backup' ),
					} );
					row.parentNode.removeChild( row );
				} catch ( e ) {
					busy( button, false );
					row.querySelector( '[data-dbc-row-status]' ).textContent =
						e.message;
				}
			} );
		}
	);

	/* ---------- inspect ---------- */

	const dialog = root.querySelector( '[data-dbc-dialog]' );
	if ( dialog ) {
		dialog
			.querySelector( '[data-dbc-dialog-close]' )
			.addEventListener( 'click', function () {
				dialog.close();
			} );
		root.addEventListener( 'click', async function ( event ) {
			const trigger = event.target.closest( '[data-dbc-inspect]' );
			if ( ! trigger ) {
				return;
			}
			event.preventDefault();
			const body = dialog.querySelector( '[data-dbc-dialog-body]' );
			dialog.querySelector( '[data-dbc-dialog-title]' ).textContent =
				trigger.getAttribute( 'data-dbc-inspect-id' );
			body.textContent = t.loading;
			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}
			try {
				const data = await call( 'inspect', {
					type: trigger.getAttribute( 'data-dbc-inspect' ),
					id: trigger.getAttribute( 'data-dbc-inspect-id' ),
				} );
				// The server escapes every value before building this markup.
				body.innerHTML = data.html;
			} catch ( e ) {
				body.textContent = e.message;
			}
		} );
	}

	/* ---------- clean all safe items ---------- */

	const safeCard = root.querySelector( '[data-dbc-safe-card]' );
	if ( safeCard && safeCard.querySelector( '[data-dbc-safe-open]' ) ) {
		const open = safeCard.querySelector( '[data-dbc-safe-open]' );
		const confirmBox = safeCard.querySelector( '[data-dbc-safe-confirm]' );
		const yes = safeCard.querySelector( '[data-dbc-safe-yes]' );
		const no = safeCard.querySelector( '[data-dbc-safe-no]' );
		const box = safeCard.querySelector( '[data-dbc-progress]' );
		open.addEventListener( 'click', function () {
			confirmBox.hidden = false;
			open.setAttribute( 'aria-expanded', 'true' );
			yes.focus();
		} );
		no.addEventListener( 'click', function () {
			confirmBox.hidden = true;
			open.setAttribute( 'aria-expanded', 'false' );
			open.focus();
		} );
		yes.addEventListener( 'click', async function () {
			busy( yes, true );
			busy( open, true );
			confirmBox.hidden = true;
			try {
				progress( box, 0, t.safeStarting );
				let p = await call( 'clean_safe', {} );
				p = await runJob( p, box );
				await runScan(
					p.categories && p.categories.length
						? p.categories
						: Object.keys( cfg.categories || {} ),
					box
				);
				const problems = ( p.errors || [] ).concat( p.skipped || [] );
				if ( problems.length ) {
					showProblems( box, problems, cfg.databaseUrl );
					return;
				}
				window.location.href = cfg.databaseUrl;
			} catch ( e ) {
				progress( box, null, e.message, true );
				busy( yes, false );
				busy( open, false );
			}
		} );
	}

	/* ---------- full backup & restore ---------- */

	// Repeats a step operation until it reports done. Failed requests are retried a few times
	// (a proxy time-out does not stop the job; the next step continues where the last one ended).
	async function steps( op, data, onProgress, stop ) {
		let failures = 0;
		let p = null;
		while ( true ) {
			try {
				p = await call( op, data );
				failures = 0;
			} catch ( e ) {
				failures++;
				if ( failures > 5 || e.message !== t.failed ) {
					throw e;
				}
				onProgress( null, fmt( t.retrying, failures, 5 ) );
				await wait( 5000 );
				continue;
			}
			if ( p.busy ) {
				await wait( 2000 );
				continue;
			}
			if ( stop( p ) ) {
				return p;
			}
		}
	}

	function fullText( p ) {
		return fmt(
			t.fullTable,
			p.index,
			p.tables,
			p.table,
			Number( p.rows ).toLocaleString(),
			bytes( p.size )
		);
	}

	// Writes a full backup to the end; returns the last progress.
	async function runFull( startData, box ) {
		progress( box, 0, t.fullStarting );
		let p = startData
			? await call( 'full_start', startData )
			: { percent: 0 };
		p = await steps(
			'full_step',
			{},
			function ( pct, text ) {
				progress( box, pct, text );
			},
			function ( step ) {
				progress( box, step.percent, fullText( step ) );
				return step.done;
			}
		);
		const s = p.summary || {};
		progress(
			box,
			100,
			fmt(
				t.fullDone,
				bytes( s.size || p.size ),
				s.tables || p.tables,
				Number( s.rows || p.rows ).toLocaleString(),
				s.seconds || p.seconds
			)
		);
		return p;
	}

	function restoreText( p ) {
		if ( p.phase === 'unpack' ) {
			return fmt( t.unpacking, bytes( p.unpacked ) );
		}
		if ( p.phase === 'import' ) {
			return fmt(
				t.importing,
				Math.round( p.percent ),
				p.tables,
				Number( p.statements ).toLocaleString()
			);
		}
		if ( p.phase === 'check' ) {
			return t.checking;
		}
		return t.swapping;
	}

	// Runs the restore steps. Resolves with the final progress, or with needsAck set when the
	// check phase wants a confirmation first.
	function runRestore( box ) {
		return steps(
			'full_restore_step',
			{},
			function ( pct, text ) {
				progress( box, pct, text );
			},
			function ( step ) {
				progress( box, step.percent, restoreText( step ) );
				return step.done || step.failed || step.needsAck;
			}
		);
	}

	const fullCard = root.querySelector( '[data-dbc-full-card]' );
	if ( fullCard ) {
		const fullBox = fullCard.querySelector( '[data-dbc-progress]' );
		const create = fullCard.querySelector( '[data-dbc-full-create]' );
		const runCreate = async function ( button, startData ) {
			busy( button, true );
			if ( button !== create ) {
				busy( create, true );
			}
			try {
				await runFull( startData, fullBox );
				window.setTimeout( function () {
					window.location.reload();
				}, 1500 );
			} catch ( e ) {
				progress( fullBox, null, e.message, true );
				busy( button, false );
				busy( create, false );
			}
		};
		create.addEventListener( 'click', function () {
			const scope = fullCard.querySelector(
				'input[name="lucidlab-dbc-full-scope"]:checked'
			);
			runCreate( create, {
				scope: scope ? scope.value : 'all',
				trigger: 'manual',
			} );
		} );
		const cont = fullCard.querySelector( '[data-dbc-full-continue]' );
		if ( cont ) {
			cont.addEventListener( 'click', function () {
				runCreate( cont, null );
			} );
		}
		const discard = fullCard.querySelector( '[data-dbc-full-discard]' );
		if ( discard ) {
			discard.addEventListener( 'click', async function () {
				if ( ! armed( discard ) ) {
					return;
				}
				fire( discard );
				try {
					await call( 'full_discard', {} );
					window.location.reload();
				} catch ( e ) {
					progress( fullBox, null, e.message, true );
					busy( discard, false );
				}
			} );
		}
	}

	root.querySelectorAll( '[data-dbc-full-delete]' ).forEach(
		function ( button ) {
			button.addEventListener( 'click', async function () {
				if ( ! armed( button ) ) {
					return;
				}
				const row = button.closest( 'tr' );
				fire( button );
				try {
					await call( 'full_delete', {
						backup: button.getAttribute( 'data-dbc-full-delete' ),
					} );
					row.parentNode.removeChild( row );
				} catch ( e ) {
					busy( button, false );
					row.querySelector( '[data-dbc-row-status]' ).textContent =
						e.message;
				}
			} );
		}
	);

	// Shows the warnings of the check phase and waits for "replace anyway" or "cancel".
	function askAck( ackBox, warnings ) {
		const list = ackBox.querySelector( '[data-dbc-ack-list]' );
		list.replaceChildren();
		( warnings || [] ).forEach( function ( text ) {
			const li = document.createElement( 'li' );
			li.textContent = text;
			list.appendChild( li );
		} );
		ackBox.hidden = false;
		const yes = ackBox.querySelector( '[data-dbc-ack-yes]' );
		const no = ackBox.querySelector( '[data-dbc-ack-no]' );
		yes.focus();
		return new Promise( function ( resolve ) {
			const done = function ( answer ) {
				yes.removeEventListener( 'click', onYes );
				no.removeEventListener( 'click', onNo );
				resolve( answer );
			};
			const onYes = function () {
				done( true );
			};
			const onNo = function () {
				done( false );
			};
			yes.addEventListener( 'click', onYes );
			no.addEventListener( 'click', onNo );
		} );
	}

	// Restore loop shared by the dialog and the "continue" card.
	async function driveRestore( box, ackBox ) {
		let p = await runRestore( box );
		while ( p.needsAck ) {
			const ok = await askAck( ackBox, p.warnings );
			const yes = ackBox.querySelector( '[data-dbc-ack-yes]' );
			const no = ackBox.querySelector( '[data-dbc-ack-no]' );
			if ( ! ok ) {
				busy( no, true );
				await call( 'full_restore_cancel', {} );
				busy( no, false );
				ackBox.hidden = true;
				progress( box, 0, t.cancelled );
				return null;
			}
			busy( yes, true );
			p = await call( 'full_restore_step', { ack: '1' } );
			if ( ! p.done && ! p.failed && ! p.needsAck ) {
				p = await runRestore( box );
			}
			busy( yes, false );
			ackBox.hidden = true;
		}
		if ( p.failed ) {
			throw new Error(
				t.restoreFailed + ' ' + ( p.errors || [] ).join( ' · ' )
			);
		}
		let text = fmt( t.restoreDone, p.tables, p.seconds );
		if ( p.skipped ) {
			text += ' ' + fmt( t.skipped, p.skipped );
		}
		progress( box, 100, text );
		window.setTimeout( function () {
			window.location.href = cfg.fullUrl;
		}, 4000 );
		return p;
	}

	const pending = root.querySelector( '[data-dbc-restore-pending]' );
	if ( pending ) {
		const box = pending.querySelector( '[data-dbc-progress]' );
		const ackBox = pending.querySelector( '[data-dbc-ack]' );
		const cont = pending.querySelector( '[data-dbc-restore-continue]' );
		const cancel = pending.querySelector( '[data-dbc-restore-cancel]' );
		cont.addEventListener( 'click', async function () {
			busy( cont, true );
			cancel.disabled = true;
			try {
				if ( ! ( await driveRestore( box, ackBox ) ) ) {
					window.location.reload();
				}
			} catch ( e ) {
				progress( box, null, e.message, true );
				busy( cont, false );
				cancel.disabled = false;
			}
		} );
		cancel.addEventListener( 'click', async function () {
			if ( ! armed( cancel ) ) {
				return;
			}
			fire( cancel );
			try {
				await call( 'full_restore_cancel', {} );
				window.location.reload();
			} catch ( e ) {
				progress( box, null, e.message, true );
				busy( cancel, false );
			}
		} );
	}

	const restoreDialog = root.querySelector( '[data-dbc-restore-dialog]' );
	if ( restoreDialog ) {
		const what = restoreDialog.querySelector( '[data-dbc-restore-what]' );
		const urlBox = restoreDialog.querySelector( '[data-dbc-restore-url]' );
		const confirmInput = restoreDialog.querySelector(
			'[data-dbc-restore-confirm]'
		);
		const go = restoreDialog.querySelector( '[data-dbc-restore-go]' );
		const safety = restoreDialog.querySelector(
			'[data-dbc-restore-safety]'
		);
		const extra = restoreDialog.querySelector( '[data-dbc-restore-extra]' );
		const extraWrap = restoreDialog.querySelector(
			'[data-dbc-restore-extra-wrap]'
		);
		const box = restoreDialog.querySelector( '[data-dbc-progress]' );
		const ackBox = restoreDialog.querySelector( '[data-dbc-ack]' );
		let row = null;
		let running = false;
		restoreDialog
			.querySelectorAll( '[data-dbc-restore-close]' )
			.forEach( function ( button ) {
				button.addEventListener( 'click', function () {
					if ( ! running ) {
						restoreDialog.close();
					}
				} );
			} );
		restoreDialog.addEventListener( 'cancel', function ( event ) {
			if ( running ) {
				event.preventDefault();
			}
		} );
		confirmInput.addEventListener( 'input', function () {
			go.disabled = running || confirmInput.value.trim() !== 'RESTORE';
		} );
		root.querySelectorAll( '[data-dbc-full-restore]' ).forEach(
			function ( button ) {
				button.addEventListener( 'click', function () {
					row = button.closest( 'tr' );
					what.textContent =
						row.getAttribute( 'data-label' ) +
						' · ' +
						row.getAttribute( 'data-created' );
					const mismatch =
						row.getAttribute( 'data-mismatch' ) === '1';
					urlBox.hidden = ! mismatch;
					if ( mismatch ) {
						urlBox.querySelector(
							'[data-dbc-restore-url-text]'
						).textContent = fmt(
							t.urlMismatch,
							row.getAttribute( 'data-siteurl' )
						);
					}
					const own = row.getAttribute( 'data-type' ) === 'full';
					extraWrap.hidden = ! own;
					extra.checked = false;
					safety.checked = true;
					confirmInput.value = '';
					go.disabled = true;
					box.hidden = true;
					ackBox.hidden = true;
					restoreDialog.showModal();
					confirmInput.focus();
				} );
			}
		);
		go.addEventListener( 'click', async function () {
			if ( ! row || confirmInput.value.trim() !== 'RESTORE' ) {
				return;
			}
			const id = row.getAttribute( 'data-dbc-full-row' );
			running = true;
			busy( go, true );
			confirmInput.disabled = true;
			safety.disabled = true;
			extra.disabled = true;
			restoreDialog
				.querySelectorAll( '[data-dbc-restore-close]' )
				.forEach( function ( b ) {
					b.disabled = true;
				} );
			try {
				let safetyId = '';
				if ( safety.checked ) {
					progress( box, 0, t.safetyFirst );
					const scope = row.getAttribute( 'data-scope' );
					const s = await runFull(
						{
							scope: scope === 'prefix' ? 'prefix' : 'all',
							trigger: 'safety',
							protect: id,
						},
						box
					);
					safetyId = s.id;
				}
				await call( 'full_restore_start', {
					backup: id,
					confirm: 'RESTORE',
					drop_extra: extra.checked ? '1' : '0',
					ack:
						row.getAttribute( 'data-mismatch' ) === '1' ? '1' : '0',
					safety: safetyId,
				} );
				const p = await driveRestore( box, ackBox );
				if ( ! p ) {
					throw new Error( t.cancelled );
				}
			} catch ( e ) {
				progress( box, null, e.message, true );
				running = false;
				busy( go, false );
				confirmInput.disabled = false;
				safety.disabled = false;
				extra.disabled = false;
				restoreDialog
					.querySelectorAll( '[data-dbc-restore-close]' )
					.forEach( function ( b ) {
						b.disabled = false;
					} );
			}
		} );
	}

	/* ---------- backups retention ---------- */

	const retention = root.querySelector( '[data-dbc-retention]' );
	if ( retention ) {
		const message = retention.querySelector(
			'[data-dbc-retention-message]'
		);
		retention.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const button =
				event.submitter ||
				retention.querySelector( 'button[type="submit"]' );
			busy( button, true );
			message.textContent = t.saving;
			message.style.color = '';
			try {
				await call( 'retention_save', {
					keep_count: retention.elements.keep_count.value,
					keep_days: retention.elements.keep_days.value,
				} );
				message.textContent = t.settingsSaved;
			} catch ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
			}
			busy( button, false );
		} );
	}

	/* ---------- scan rules (Settings tab) ---------- */

	const scanRules = root.querySelector( '[data-dbc-scanrules]' );
	if ( scanRules ) {
		const message = scanRules.querySelector(
			'[data-dbc-scanrules-message]'
		);
		scanRules.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const button =
				event.submitter ||
				scanRules.querySelector( 'button[type="submit"]' );
			busy( button, true );
			message.textContent = t.saving;
			message.style.color = '';
			try {
				const result = await call( 'scanrules_save', {
					as_days: scanRules.elements.as_days.value,
				} );
				scanRules.elements.as_days.value = result.as_days;
				message.textContent = t.scanRulesSaved;
			} catch ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
			}
			busy( button, false );
		} );
	}

	/* ---------- keep the last N revisions ---------- */

	const revCard = root.querySelector( '[data-dbc-revkeep]' );
	if ( revCard ) {
		const input = revCard.querySelector( '[data-dbc-revkeep-n]' );
		const previewButton = revCard.querySelector(
			'[data-dbc-revkeep-preview]'
		);
		const runButton = revCard.querySelector( '[data-dbc-revkeep-run]' );
		const message = revCard.querySelector( '[data-dbc-revkeep-message]' );
		const box = revCard.querySelector( '[data-dbc-progress]' );
		input.addEventListener( 'input', function () {
			runButton.disabled = true;
			message.textContent = '';
		} );
		previewButton.addEventListener( 'click', async function () {
			busy( previewButton, true );
			try {
				const p = await call( 'revisions_preview', {
					keep: input.value,
				} );
				message.style.color = '';
				message.textContent = p.revisions
					? fmt(
							t.revPreview,
							Number( p.revisions ).toLocaleString(),
							Number( p.posts ).toLocaleString()
						)
					: t.revNone;
				busy( previewButton, false );
				runButton.disabled = ! p.revisions;
			} catch ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
				busy( previewButton, false );
			}
		} );
		runButton.addEventListener( 'click', async function () {
			if ( ! armed( runButton ) ) {
				return;
			}
			fire( runButton );
			busy( previewButton, true );
			try {
				let p = await call( 'revisions_keep', { keep: input.value } );
				p = await runJob( p, box );
				await runScan( [ 'revisions' ], box );
				const problems = ( p.errors || [] ).concat( p.skipped || [] );
				if ( problems.length ) {
					showProblems( box, problems, cfg.databaseUrl );
					return;
				}
				window.location.href = cfg.databaseUrl;
			} catch ( e ) {
				progress( box, null, e.message, true );
				busy( runButton, false );
				busy( previewButton, false );
			}
		} );
	}

	/* ---------- install ping (opt-in) ---------- */

	const pingNotice = root.querySelector( '[data-dbc-ping-notice]' );
	if ( pingNotice ) {
		const yes = pingNotice.querySelector( '[data-dbc-ping-yes]' );
		const no = pingNotice.querySelector( '[data-dbc-ping-no]' );
		const message = pingNotice.querySelector(
			'[data-dbc-ping-notice-message]'
		);
		yes.addEventListener( 'click', async function () {
			busy( yes, true );
			no.disabled = true;
			message.textContent = t.saving;
			message.style.color = '';
			try {
				await call( 'ping_save', { on: '1' } );
				message.textContent = t.pingOn;
				yes.parentNode.parentNode.removeChild( yes.parentNode );
			} catch ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
				no.disabled = false;
			}
			busy( yes, false );
		} );
		no.addEventListener( 'click', async function () {
			busy( no, true );
			yes.disabled = true;
			try {
				await call( 'ping_dismiss', {} );
				pingNotice.hidden = true;
			} catch ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
				yes.disabled = false;
			}
			busy( no, false );
		} );
	}

	const pingForm = root.querySelector( '[data-dbc-ping-form]' );
	if ( pingForm ) {
		const message = pingForm.querySelector( '[data-dbc-ping-message]' );
		pingForm.addEventListener( 'submit', async function ( event ) {
			event.preventDefault();
			const button =
				event.submitter ||
				pingForm.querySelector( 'button[type="submit"]' );
			const on = pingForm.elements.install_ping.checked;
			busy( button, true );
			message.textContent = t.saving;
			message.style.color = '';
			try {
				await call( 'ping_save', { on: on ? '1' : '0' } );
				message.textContent = on ? t.pingOn : t.pingOff;
			} catch ( e ) {
				message.textContent = e.message;
				message.style.color = '#a12224';
			}
			busy( button, false );
		} );
	}

	/* ---------- Pro note ---------- */

	const proNotice = root.querySelector( '[data-dbc-pro-notice]' );
	if ( proNotice ) {
		proNotice
			.querySelector( '[data-dbc-pro-dismiss]' )
			.addEventListener( 'click', function () {
				proNotice.hidden = true;
				call( 'dismiss_pro', {} ).catch( function () {} );
			} );
	}

	// Helpers for add-ons (the Pro add-on builds its cards with them).
	window.LucidLabDBCUI = {
		root,
		cfg,
		t,
		fmt,
		bytes,
		busy,
		call,
		progress,
		steps,
		wait,
		armed,
		fire,
		runJob,
		runFull,
		runScan,
		showProblems,
	};
} )();
