/* global wpLoupeAdmin */

/**
 * WP Loupe Admin Field Configuration Manager
 * Handles the configuration UI for field indexing, filtering, and sorting
 */
class WpLoupeFieldManager {
	constructor() {
		this.__ = wp.i18n.__;
		this.fieldConfigContainer = document.getElementById(
			'wp-loupe-fields-config'
		);
		this.postTypeFieldset = document.getElementById(
			'wp_loupe_custom_post_types'
		);
		this.savedFields = wpLoupeAdmin.savedFields || {};
		this.configuredPostTypes = wpLoupeAdmin.configuredPostTypes || [];
		this.protectedFields = [ 'post_date', 'post_modified' ];
		this.nonSortableFieldTypes = [ 'post_content', 'post_excerpt' ];
		this.pendingOperations = 0;
		this.reindexButton = document.getElementById(
			'wp-loupe-reindex-button'
		);
		this.cancelButton = document.getElementById(
			'wp-loupe-reindex-cancel'
		);
		this.progressWrap = document.getElementById(
			'wp-loupe-reindex-progress'
		);
		this.progressBar = document.getElementById( 'wp-loupe-reindex-bar' );
		this.progressLabel = document.getElementById(
			'wp-loupe-reindex-progress-label'
		);
		this.healthContainer = document.getElementById(
			'wp-loupe-index-health'
		);
		this.cancelRequested = false;

		// Track selected post types for comparison
		this.previouslySelectedPostTypes = this.getSelectedPostTypes();

		this.statusMessageContainer = null;
		this.init();
	}

	/**
	 * Return the currently selected post types.
	 *
	 * On the Fields tab this reads the checkbox picker; elsewhere (e.g. the
	 * Dashboard) it falls back to the saved/configured post types.
	 *
	 * @return {Array<string>} Selected post type slugs.
	 */
	getSelectedPostTypes() {
		const checkboxes = document.querySelectorAll(
			'.wp-loupe-post-type-checkbox'
		);
		if ( checkboxes.length > 0 ) {
			return Array.from( checkboxes )
				.filter( ( cb ) => cb.checked )
				.map( ( cb ) => cb.value );
		}
		return Array.isArray( this.configuredPostTypes )
			? [ ...this.configuredPostTypes ]
			: [];
	}

	/**
	 * Initialize the manager
	 */
	init() {
		this.createStatusMessage();
		this.preventFormSubmission();
		this.bindReindexButton();
		this.bindCancelButton();
		this.bindPostTypeCheckboxes();
		this.bindEvents();
		this.loadInitialFields();
		this.loadIndexHealth();
	}

	/**
	 * Bind the separate Reindex button to the batched REST endpoint.
	 */
	bindReindexButton() {
		if ( ! this.reindexButton ) {
			return;
		}
		this.reindexButton.addEventListener( 'click', async ( e ) => {
			e.preventDefault();
			if ( this.pendingOperations > 0 ) {
				this.showStatusMessage(
					this.__(
						'Please wait for pending operations to finish before reindexing.',
						'loupe-search'
					),
					'info'
				);
				return;
			}
			await this.startBatchedReindex();
		} );
	}

	async startBatchedReindex() {
		if ( ! this.reindexButton ) {
			return;
		}
		const selectedPostTypes = this.getSelectedPostTypes();
		if ( selectedPostTypes.length === 0 ) {
			this.showStatusMessage(
				this.__(
					'No post types selected for indexing.',
					'loupe-search'
				),
				'error'
			);
			return;
		}

		this.cancelRequested = false;
		this.reindexButton.disabled = true;
		this.showProgress( 0, this.__( 'Starting…', 'loupe-search' ) );
		if ( this.cancelButton ) {
			this.cancelButton.classList.remove( 'hidden' );
		}
		this.showStatusMessage(
			this.__(
				'Starting batched reindex. Keep this tab open…',
				'loupe-search'
			),
			'info'
		);

		let cursor = null;
		let isFirst = true;
		const batchSize = 500;

		try {
			// eslint-disable-next-line no-constant-condition
			while ( true ) {
				if ( this.cancelRequested ) {
					this.showStatusMessage(
						this.__( 'Reindex cancelled.', 'loupe-search' ),
						'info'
					);
					break;
				}
				const response = await wp.apiFetch( {
					path: 'loupe-search/v1/reindex-batch',
					method: 'POST',
					data: {
						reset: isFirst,
						cursor,
						batch_size: batchSize,
						post_types: selectedPostTypes,
					},
				} );
				isFirst = false;

				if ( response && response.done ) {
					this.showProgress(
						100,
						this.__( 'Complete', 'loupe-search' )
					);
					this.showStatusMessage(
						this.__(
							'Reindex completed successfully!',
							'loupe-search'
						),
						'success'
					);
					this.loadIndexHealth();
					break;
				}

				cursor = response ? response.cursor : null;
				const processed = response ? response.processed : 0;
				const currentPostType = response
					? response.currentPostType
					: null;
				const processedPostType = response
					? response.processedPostType
					: 0;
				const total = response ? response.total : null;
				const totals = response ? response.totals : null;
				let pct = null;
				let msg = '';
				if ( total && total > 0 ) {
					pct = Math.min(
						100,
						Math.round( ( processed / total ) * 100 )
					);
					msg = `${ this.__(
						'Reindexing',
						'loupe-search'
					) }: ${ pct }% (${ processed }/${ total })`;
				} else {
					msg = `${ this.__(
						'Reindexing',
						'loupe-search'
					) }: ${ processed }`;
				}
				if ( currentPostType ) {
					const ptTotal =
						totals && totals[ currentPostType ]
							? totals[ currentPostType ]
							: null;
					if ( ptTotal && ptTotal > 0 ) {
						msg += ` — ${ currentPostType }: ${ processedPostType }/${ ptTotal }`;
					} else {
						msg += ` — ${ currentPostType }: ${ processedPostType }`;
					}
				}
				this.showProgress( pct, msg );
				this.showStatusMessage( msg, 'info' );

				if ( ! cursor ) {
					// Safety valve: if server didn't provide cursor but not done, stop.
					throw new Error(
						this.__(
							'Reindex stalled: missing cursor. Try again or check server logs.',
							'loupe-search'
						)
					);
				}
			}
		} catch ( error ) {
			this.showStatusMessage(
				`${ this.__( 'Reindex failed', 'loupe-search' ) }: ${
					error.message
				}`,
				'error'
			);
		} finally {
			this.reindexButton.disabled = false;
			if ( this.cancelButton ) {
				this.cancelButton.classList.add( 'hidden' );
			}
		}
	}

	/**
	 * Bind the Cancel button for an in-progress reindex.
	 */
	bindCancelButton() {
		if ( ! this.cancelButton ) {
			return;
		}
		this.cancelButton.addEventListener( 'click', ( e ) => {
			e.preventDefault();
			this.cancelRequested = true;
		} );
	}

	/**
	 * Update the determinate progress bar.
	 * @param {number|null} pct   Percentage 0-100, or null for indeterminate.
	 * @param {string}      label Text label to show beside the bar.
	 */
	showProgress( pct, label ) {
		if ( this.progressWrap ) {
			this.progressWrap.classList.remove( 'hidden' );
		}
		if ( this.progressBar ) {
			if ( pct === null || pct === undefined ) {
				this.progressBar.removeAttribute( 'value' );
			} else {
				this.progressBar.value = pct;
			}
		}
		if ( this.progressLabel && label ) {
			this.progressLabel.textContent = label;
		}
	}

	/**
	 * Fetch and render the per-post-type index health table.
	 */
	async loadIndexHealth() {
		if ( ! this.healthContainer ) {
			return;
		}
		try {
			const response = await wp.apiFetch( {
				path: 'loupe-search/v1/index-status',
				method: 'GET',
			} );
			const items =
				response && Array.isArray( response.items )
					? response.items
					: [];
			this.renderIndexHealth( items );
		} catch ( error ) {
			this.healthContainer.innerHTML = '';
			const p = document.createElement( 'p' );
			p.className = 'description';
			p.textContent = `${ this.__(
				'Unable to load index status',
				'loupe-search'
			) }: ${ error.message }`;
			this.healthContainer.appendChild( p );
		}
	}

	/**
	 * Render the index health table from status items.
	 * @param {Array<Object>} items Status items from /index-status.
	 */
	renderIndexHealth( items ) {
		if ( ! this.healthContainer ) {
			return;
		}
		this.healthContainer.innerHTML = '';

		if ( items.length === 0 ) {
			const p = document.createElement( 'p' );
			p.className = 'description';
			p.textContent = this.__(
				'No post types are configured for indexing yet. Add one on the Fields tab.',
				'loupe-search'
			);
			this.healthContainer.appendChild( p );
			return;
		}

		const reasons = {
			index_missing: this.__( 'Not indexed', 'loupe-search' ),
			index_needs_reindex: this.__( 'Needs reindex', 'loupe-search' ),
			index_unreadable: this.__( 'Index unreadable', 'loupe-search' ),
			unknown_post_type: this.__( 'Unknown post type', 'loupe-search' ),
			invalid_post_type: this.__( 'Invalid post type', 'loupe-search' ),
		};

		const table = document.createElement( 'table' );
		table.className = 'widefat striped wp-loupe-health-table';
		const thead = document.createElement( 'thead' );
		thead.innerHTML = `<tr><th>${ this.__(
			'Post type',
			'loupe-search'
		) }</th><th>${ this.__( 'Status', 'loupe-search' ) }</th><th>${ this.__(
			'Published',
			'loupe-search'
		) }</th></tr>`;
		table.appendChild( thead );

		const tbody = document.createElement( 'tbody' );
		items.forEach( ( item ) => {
			const tr = document.createElement( 'tr' );

			const nameCell = document.createElement( 'td' );
			nameCell.textContent = item.label || item.postType;
			tr.appendChild( nameCell );

			const statusCell = document.createElement( 'td' );
			const badge = document.createElement( 'span' );
			badge.className = 'wp-loupe-badge';
			if ( item.ready ) {
				badge.classList.add( 'is-ready' );
				badge.textContent = this.__( 'Ready', 'loupe-search' );
			} else {
				badge.classList.add( 'is-warning' );
				badge.textContent =
					reasons[ item.reason ] ||
					this.__( 'Needs reindex', 'loupe-search' );
			}
			statusCell.appendChild( badge );
			tr.appendChild( statusCell );

			const countCell = document.createElement( 'td' );
			countCell.textContent =
				item.published !== undefined ? item.published : '';
			tr.appendChild( countCell );

			tbody.appendChild( tr );
		} );
		table.appendChild( tbody );
		this.healthContainer.appendChild( table );
	}

	/**
	 * Create status message container
	 */
	createStatusMessage() {
		const anchor = this.fieldConfigContainer || this.progressWrap;
		if ( ! anchor ) {
			return;
		}

		this.statusMessageContainer = document.createElement( 'div' );
		this.statusMessageContainer.className = 'wp-loupe-status hidden';
		this.statusMessageContainer.style.marginTop = '10px';
		this.statusMessageContainer.style.padding = '10px 15px';
		this.statusMessageContainer.style.border = '1px solid #ccd0d4';
		this.statusMessageContainer.style.background = '#f8f9f9';

		anchor.parentNode.insertBefore( this.statusMessageContainer, anchor );
	}

	/**
	 * Show status message
	 * @param {string} message The message to display.
	 * @param {string} type    Message type ('info', 'success', 'error').
	 */
	showStatusMessage( message, type = 'info' ) {
		if ( ! this.statusMessageContainer || ! message ) {
			return;
		}

		// Set message colors based on type
		const colors = {
			success: { border: '#46b450', bg: '#ecf7ed' },
			error: { border: '#dc3232', bg: '#fbeaea' },
			info: { border: '#00a0d2', bg: '#e5f5fa' },
		};

		const { border, bg } = colors[ type ] || colors.info;

		this.statusMessageContainer.style.borderColor = border;
		this.statusMessageContainer.style.background = bg;
		this.statusMessageContainer.textContent = message;
		this.statusMessageContainer.classList.remove( 'hidden' );

		// Auto-hide after 10 seconds for success messages
		if ( type === 'success' ) {
			setTimeout( () => {
				this.statusMessageContainer.classList.add( 'hidden' );
			}, 10000 );
		}
	}

	/**
	 * Hide status message
	 */
	hideStatusMessage() {
		if ( this.statusMessageContainer ) {
			this.statusMessageContainer.classList.add( 'hidden' );
		}
	}

	/**
	 * Bind change events to the post type checkbox picker.
	 */
	bindPostTypeCheckboxes() {
		const checkboxes = document.querySelectorAll(
			'.wp-loupe-post-type-checkbox'
		);
		if ( checkboxes.length === 0 ) {
			return;
		}

		checkboxes.forEach( ( checkbox ) => {
			checkbox.addEventListener( 'change', async ( e ) => {
				const postType = e.target.value;
				if ( ! postType ) {
					return;
				}

				if ( e.target.checked ) {
					if (
						! this.previouslySelectedPostTypes.includes( postType )
					) {
						await this.handlePostTypeAddition( postType );
					}
				} else {
					await this.handlePostTypeRemoval( postType );
				}
			} );
		} );
	}

	/**
	 * Handle post type removal
	 * @param {string} postType - The post type being removed
	 */
	async handlePostTypeRemoval( postType ) {
		this.showStatusMessage(
			`${ this.__(
				'Deleting database structure for',
				'loupe-search'
			) }: ${ postType }…`,
			'info'
		);

		try {
			this.pendingOperations++;

			// Call the API to delete the database
			const response = await wp.apiFetch( {
				path: 'loupe-search/v1/delete-database',
				method: 'POST',
				data: { post_type: postType },
			} );

			this.showStatusMessage( `${ response.message }`, 'success' );

			// Update tracking array and remove UI elements
			this.previouslySelectedPostTypes =
				this.previouslySelectedPostTypes.filter(
					( type ) => type !== postType
				);
			this.removePostTypeSection( postType );
		} catch ( error ) {
			this.showStatusMessage(
				`${ this.__(
					'Error deleting database for',
					'loupe-search'
				) } ${ postType }: ${ error.message }`,
				'error'
			);
		} finally {
			this.pendingOperations--;
		}
	}

	/**
	 * Handle post type addition
	 * @param {string} postType - The post type being added
	 */
	async handlePostTypeAddition( postType ) {
		this.showStatusMessage(
			`${ this.__(
				'Creating database structure for',
				'loupe-search'
			) }: ${ postType }…`,
			'info'
		);

		try {
			this.pendingOperations++;

			// Call the API to create the database structure only (no indexing)
			const response = await wp.apiFetch( {
				path: 'loupe-search/v1/create-database',
				method: 'POST',
				data: { post_type: postType },
			} );

			this.showStatusMessage(
				`${ response.message } ${ this.__(
					'Please configure fields below and click Reindex to complete setup.',
					'loupe-search'
				) }`,
				'success'
			);

			// Add to tracking array
			if ( ! this.previouslySelectedPostTypes.includes( postType ) ) {
				this.previouslySelectedPostTypes.push( postType );
			}

			// Add UI elements for this post type
			const fields = await this.getPostTypeFields( postType );
			this.addFieldConfigSection( postType, fields, true );
		} catch ( error ) {
			this.showStatusMessage(
				`${ this.__(
					'Error creating database for',
					'loupe-search'
				) } ${ postType }: ${ error.message }`,
				'error'
			);
		} finally {
			this.pendingOperations--;
		}
	}

	/**
	 * Bind event listeners
	 */
	bindEvents() {
		document.addEventListener( 'change', ( e ) => {
			if ( e.target.classList.contains( 'wp-loupe-sortable-toggle' ) ) {
				const directionSelect = e.target
					.closest( 'tr' )
					?.querySelector( '.wp-loupe-sort-direction' );
				if ( directionSelect ) {
					directionSelect.disabled = ! e.target.checked;
				}
			}
		} );
	}

	/**
	 * Load initial fields for pre-selected post types
	 */
	loadInitialFields() {
		this.updateFieldsConfig();
	}

	/**
	 * Update the fields configuration UI
	 * @param {Array} newPostTypes - Array of newly added post types
	 */
	async updateFieldsConfig( newPostTypes = [] ) {
		if ( ! this.fieldConfigContainer ) {
			return;
		}

		if ( this.pendingOperations > 0 ) {
			// If operations are pending, wait a bit and check again
			setTimeout( () => this.updateFieldsConfig( newPostTypes ), 100 );
			return;
		}

		// Get currently selected post types directly from DOM
		const selectedPostTypes = this.getSelectedPostTypes();

		// Clear existing fields UI
		if ( newPostTypes.length === 0 ) {
			// Only clear all if not adding specific types
			this.fieldConfigContainer.innerHTML = '';
		}

		if ( selectedPostTypes.length === 0 ) {
			return;
		}

		for ( const postType of selectedPostTypes ) {
			// Skip if this section already exists and we're not refreshing everything
			if (
				newPostTypes.length > 0 &&
				! newPostTypes.includes( postType ) &&
				this.doesPostTypeSectionExist( postType )
			) {
				continue;
			}

			try {
				const fields = await this.getPostTypeFields( postType );
				if ( Object.keys( fields ).length > 0 ) {
					this.addFieldConfigSection(
						postType,
						fields,
						newPostTypes.includes( postType )
					);
				}
			} catch ( error ) {
				this.showStatusMessage(
					`${ this.__(
						'Error loading fields for',
						'loupe-search'
					) } ${ postType }: ${ error.message }`,
					'error'
				);
			}
		}
	}

	/**
	 * Check if a post type section already exists in the UI
	 * @param {string} postType - The post type to check
	 * @return {boolean} True if the section exists
	 */
	doesPostTypeSectionExist( postType ) {
		if ( ! this.fieldConfigContainer ) {
			return false;
		}

		const sections = this.fieldConfigContainer.querySelectorAll(
			'.wp-loupe-post-type-fields'
		);
		for ( const section of sections ) {
			if ( section.dataset.postType === postType ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove a post type section from the UI
	 * @param {string} postType - The post type to remove
	 */
	removePostTypeSection( postType ) {
		if ( ! this.fieldConfigContainer ) {
			return;
		}

		const sections = this.fieldConfigContainer.querySelectorAll(
			'.wp-loupe-post-type-fields'
		);
		for ( const section of sections ) {
			if ( section.dataset.postType === postType ) {
				section.remove();
				break;
			}
		}
	}

	/**
	 * Fetch fields for a post type from the API
	 * @param {string} postType Post type slug.
	 * @return {Object} Field data.
	 */
	async getPostTypeFields( postType ) {
		// Attempt REST fetch; gracefully fallback to localized cache if route absent (404) or error.
		try {
			const response = await wp.apiFetch( {
				path: `loupe-search/v1/post-type-fields/${ postType }`,
				method: 'GET',
			} );
			// Basic validation: ensure object returned
			if ( response && typeof response === 'object' ) {
				return response;
			}
		} catch ( e ) {
			// Swallow and use fallback
			// console.debug('Field route missing, using fallback cache for', postType, e);
		}
		const fallback =
			( window.wpLoupeAdmin &&
				wpLoupeAdmin.availableCache &&
				wpLoupeAdmin.availableCache[ postType ] ) ||
			{};
		return fallback;
	}

	/**
	 * Add field configuration UI section for a post type
	 * @param {string}  postType      The post type.
	 * @param {Object}  fields        Fields data.
	 * @param {boolean} isNewPostType Whether this is a newly added post type.
	 */
	addFieldConfigSection( postType, fields, isNewPostType ) {
		// Remove existing section for this post type if it exists
		this.removePostTypeSection( postType );

		const section = this.createSectionElement( postType );
		const table = this.createFieldTable( postType, fields, isNewPostType );

		section.appendChild( table );
		this.fieldConfigContainer.appendChild( section );
	}

	/**
	 * Create the section container element
	 * @param {string} postType Post type slug.
	 * @return {HTMLElement} Section element.
	 */
	createSectionElement( postType ) {
		const section = document.createElement( 'details' );
		section.className = 'wp-loupe-post-type-fields';
		section.open = true;
		section.dataset.postType = postType;

		// Add post type header as the accordion summary
		const summary = document.createElement( 'summary' );
		const label =
			this.savedFields[ postType ] && this.savedFields[ postType ].__label
				? this.savedFields[ postType ].__label
				: postType;
		summary.textContent = label;
		section.appendChild( summary );

		return section;
	}

	/**
	 * Create field configuration table
	 * @param {string}  postType      The post type.
	 * @param {Object}  fields        Fields data.
	 * @param {boolean} isNewPostType Whether this is a newly added post type.
	 * @return {HTMLElement} Table element.
	 */
	createFieldTable( postType, fields, isNewPostType ) {
		const table = document.createElement( 'table' );
		table.className = 'wp-loupe-fields-table widefat';

		const thead = this.createTableHeader();
		const tbody = this.createTableBody( postType, fields, isNewPostType );

		table.appendChild( thead );
		table.appendChild( tbody );

		return table;
	}

	/**
	 * Create table header
	 * @return {HTMLElement} Table header element.
	 */
	createTableHeader() {
		const thead = document.createElement( 'thead' );
		const headerRow = document.createElement( 'tr' );

		const headerLabels = [
			this.__( 'Field', 'loupe-search' ),
			this.__( 'Indexable', 'loupe-search' ),
			this.__( 'Weight', 'loupe-search' ),
			this.__( 'Filterable', 'loupe-search' ),
			this.__( 'Sortable', 'loupe-search' ),
			this.__( 'Sort Direction', 'loupe-search' ),
		];

		headerLabels.forEach( ( text ) => {
			const th = document.createElement( 'th' );
			th.textContent = text;
			headerRow.appendChild( th );
		} );

		thead.appendChild( headerRow );
		return thead;
	}

	/**
	 * Create table body with field rows
	 * @param {string}  postType      The post type.
	 * @param {Object}  fields        Fields data.
	 * @param {boolean} isNewPostType Whether this is a newly added post type.
	 * @return {HTMLElement} Table body element.
	 */
	createTableBody( postType, fields, isNewPostType ) {
		const tbody = document.createElement( 'tbody' );

		Object.entries( fields ).forEach( ( [ fieldKey, fieldData ] ) => {
			const row = this.createFieldRow(
				postType,
				fieldKey,
				fieldData,
				isNewPostType
			);
			tbody.appendChild( row );
		} );

		return tbody;
	}

	/**
	 * Create a table row for a field
	 * @param {string}        postType      The post type.
	 * @param {string}        fieldKey      Field key.
	 * @param {Object|string} fieldData     Field data.
	 * @param {boolean}       isNewPostType Whether this is a newly added post type.
	 * @return {HTMLElement} Table row.
	 */
	createFieldRow( postType, fieldKey, fieldData, isNewPostType ) {
		const fieldLabel =
			typeof fieldData === 'string' ? fieldData : fieldData.label;
		const savedFieldSettings =
			this.savedFields[ postType ]?.[ fieldKey ] || {};

		const isProtected = this.protectedFields.includes( fieldKey );
		const isTitle = fieldKey === 'post_title';
		const isDateField =
			fieldKey === 'post_date' || fieldKey === 'post_modified';
		const row = document.createElement( 'tr' );

		// Add field name cell
		row.appendChild( this.createLabelCell( fieldLabel ) );

		// Create inputs
		const {
			indexableInput,
			weightInput,
			filterableInput,
			sortableInput,
			directionSelect,
		} = this.createFieldInputs(
			postType,
			fieldKey,
			isNewPostType,
			isProtected,
			isTitle,
			isDateField,
			savedFieldSettings
		);

		// Add cells to row
		row.appendChild( this.createInputCell( indexableInput ) );
		row.appendChild( this.createInputCell( weightInput ) );
		row.appendChild( this.createInputCell( filterableInput ) );
		row.appendChild( this.createInputCell( sortableInput ) );
		row.appendChild( this.createInputCell( directionSelect ) );

		// Add event listener for indexable checkbox
		this.bindFieldRowEvents(
			indexableInput,
			weightInput,
			filterableInput,
			sortableInput,
			directionSelect
		);

		return row;
	}

	/**
	 * Create a cell with a label
	 * @param {string} labelText Label text.
	 * @return {HTMLElement} Table cell with label.
	 */
	createLabelCell( labelText ) {
		const cell = document.createElement( 'td' );
		const label = document.createElement( 'label' );
		label.textContent = labelText;
		cell.appendChild( label );
		return cell;
	}

	/**
	 * Create a cell with an input element
	 * @param {HTMLElement} input Input element.
	 * @return {HTMLElement} Table cell with input.
	 */
	createInputCell( input ) {
		const cell = document.createElement( 'td' );
		cell.appendChild( input );
		return cell;
	}

	/**
	 * Create all input controls for a field row
	 * @param {string}  postType           The post type.
	 * @param {string}  fieldKey           Field key.
	 * @param {boolean} isNewPostType      Whether this is a newly added post type.
	 * @param {boolean} isProtected        Whether the field is protected.
	 * @param {boolean} isTitle            Whether the field is a title.
	 * @param {boolean} isDateField        Whether the field is a date field.
	 * @param {Object}  savedFieldSettings Saved field settings.
	 * @return {Object} Object containing all input elements.
	 */
	createFieldInputs(
		postType,
		fieldKey,
		isNewPostType,
		isProtected,
		isTitle,
		isDateField,
		savedFieldSettings
	) {
		// Create indexable input
		const indexableInput = document.createElement( 'input' );
		indexableInput.type = 'checkbox';
		indexableInput.name = `wp_loupe_fields[${ postType }][${ fieldKey }][indexable]`;
		indexableInput.value = '1';
		indexableInput.checked =
			isNewPostType ||
			isProtected ||
			isDateField ||
			savedFieldSettings.indexable === true;
		indexableInput.disabled = isProtected || isDateField;

		// Create weight input
		const weightInput = document.createElement( 'input' );
		weightInput.type = 'number';
		weightInput.step = '0.1';
		weightInput.min = '0';
		weightInput.name = `wp_loupe_fields[${ postType }][${ fieldKey }][weight]`;
		weightInput.value =
			savedFieldSettings.weight || ( isTitle ? '2.0' : '1.0' );
		weightInput.className = 'small-text';
		weightInput.disabled = ! indexableInput.checked;

		// Create filterable input
		const filterableInput = document.createElement( 'input' );
		filterableInput.type = 'checkbox';
		filterableInput.name = `wp_loupe_fields[${ postType }][${ fieldKey }][filterable]`;
		filterableInput.value = '1';
		filterableInput.checked =
			isNewPostType || isDateField || savedFieldSettings.filterable;
		filterableInput.disabled = ! indexableInput.checked;

		// Check if this field is sortable (scalar)
		const isScalar = this.isScalarField( fieldKey );

		// Create sortable input
		const sortableInput = document.createElement( 'input' );
		sortableInput.type = 'checkbox';
		sortableInput.name = `wp_loupe_fields[${ postType }][${ fieldKey }][sortable]`;
		sortableInput.value = '1';
		sortableInput.className = 'wp-loupe-sortable-toggle';
		// Only check by default for new post types if it's a scalar field
		sortableInput.checked =
			( isNewPostType && isScalar ) ||
			isDateField ||
			savedFieldSettings.sortable;
		sortableInput.disabled = ! indexableInput.checked || ! isScalar;

		// Create sort direction select
		const directionSelect = this.createSortDirectionSelect(
			postType,
			fieldKey,
			isDateField,
			savedFieldSettings,
			sortableInput.checked,
			indexableInput.checked
		);

		return {
			indexableInput,
			weightInput,
			filterableInput,
			sortableInput,
			directionSelect,
		};
	}

	/**
	 * Create sort direction select element
	 * @param {string}  postType           - The post type
	 * @param {string}  fieldKey           - Field key
	 * @param {boolean} isDateField        - Whether this is a date field
	 * @param {Object}  savedFieldSettings - Saved field settings
	 * @param {boolean} sortableChecked    - Whether sortable is checked
	 * @param {boolean} indexableChecked   - Whether indexable is checked
	 * @return {HTMLElement} - Select element
	 */
	createSortDirectionSelect(
		postType,
		fieldKey,
		isDateField,
		savedFieldSettings,
		sortableChecked,
		indexableChecked
	) {
		const directionSelect = document.createElement( 'select' );
		directionSelect.name = `wp_loupe_fields[${ postType }][${ fieldKey }][sort_direction]`;
		directionSelect.className = 'wp-loupe-sort-direction';
		directionSelect.disabled = ! sortableChecked || ! indexableChecked;

		const options = [
			{
				value: 'asc',
				text: this.__( 'Ascending', 'loupe-search' ),
				selected: savedFieldSettings.sort_direction === 'asc',
			},
			{
				value: 'desc',
				text: this.__( 'Descending', 'loupe-search' ),
				selected:
					isDateField ||
					savedFieldSettings.sort_direction === 'desc' ||
					! savedFieldSettings.sort_direction,
			},
		];

		options.forEach( ( option ) => {
			const optionElement = document.createElement( 'option' );
			optionElement.value = option.value;
			optionElement.textContent = option.text;
			optionElement.selected = option.selected;
			directionSelect.appendChild( optionElement );
		} );

		return directionSelect;
	}

	/**
	 * Bind events to field row inputs
	 * @param {HTMLElement} indexableInput  - Indexable checkbox
	 * @param {HTMLElement} weightInput     - Weight input
	 * @param {HTMLElement} filterableInput - Filterable checkbox
	 * @param {HTMLElement} sortableInput   - Sortable checkbox
	 * @param {HTMLElement} directionSelect - Direction select
	 */
	bindFieldRowEvents(
		indexableInput,
		weightInput,
		filterableInput,
		sortableInput,
		directionSelect
	) {
		indexableInput.addEventListener( 'change', () => {
			const isChecked = indexableInput.checked;
			weightInput.disabled = ! isChecked;
			filterableInput.disabled = ! isChecked;

			const fieldName = sortableInput.name.split( '[' )[ 2 ];
			sortableInput.disabled =
				! isChecked || ! this.isScalarField( fieldName );
			directionSelect.disabled = ! isChecked || ! sortableInput.checked;
		} );
	}

	/**
	 * Check if a field is scalar (string or number) and can be sortable
	 * @param {string} fieldName - Field name
	 * @return {boolean} - True if field can be sortable
	 */
	isScalarField( fieldName ) {
		// Remove the closing bracket if it exists in the fieldName
		const cleanFieldName = fieldName
			? fieldName.replace( /\].*$/, '' )
			: '';

		// Taxonomy fields are arrays, not scalar
		if ( cleanFieldName.startsWith( 'taxonomy_' ) ) {
			return false;
		}

		// Known non-scalar fields
		if ( this.nonSortableFieldTypes.includes( cleanFieldName ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Prevent form submission when pressing enter
	 */
	preventFormSubmission() {
		const form =
			this.postTypeFieldset?.form ||
			this.postTypeFieldset?.closest( 'form' );
		if ( ! form ) {
			return;
		}

		const originalSubmit = form.onsubmit;
		form.onsubmit = ( e ) => {
			// Allow submit button (Save Settings) submissions
			if ( e.submitter && e.submitter.type === 'submit' ) {
				this.showStatusMessage(
					this.__( 'Saving settings…', 'loupe-search' ),
					'info'
				);
				return originalSubmit ? originalSubmit( e ) : true;
			}
			e.preventDefault();
			return false;
		};
	}
}

// Initialize the field manager when the DOM is loaded
document.addEventListener( 'DOMContentLoaded', () => {
	// Ensure REST nonce is set for wp.apiFetch.
	if ( window.wp && wp.apiFetch && wp.apiFetch.createNonceMiddleware ) {
		const nonce =
			window.wpLoupeAdmin && wpLoupeAdmin.nonce
				? wpLoupeAdmin.nonce
				: null;
		if ( nonce ) {
			wp.apiFetch.use( wp.apiFetch.createNonceMiddleware( nonce ) );
		}
	}

	new WpLoupeFieldManager();

	// Endpoint copy buttons (moved from inline script in settings template)
	( function () {
		const liveRegionId = 'wp-loupe-copy-live';
		let live = document.getElementById( liveRegionId );
		if ( ! live ) {
			live = document.createElement( 'div' );
			live.id = liveRegionId;
			live.className = 'screen-reader-text';
			live.setAttribute( 'role', 'status' );
			live.setAttribute( 'aria-live', 'polite' );
			document.body.appendChild( live );
		}
		document.addEventListener( 'click', function ( e ) {
			const btn = e.target.closest( '.wp-loupe-copy-endpoint' );
			if ( ! btn ) {
				return;
			}
			const val = btn.getAttribute( 'data-copy' );
			if ( ! val ) {
				return;
			}
			const originalHTML = btn.innerHTML;
			const __ =
				window.wp && wp.i18n && wp.i18n.__ ? wp.i18n.__ : ( s ) => s;
			const copiedLabel = __( 'Copied', 'loupe-search' );
			const failedLabel = __( 'Copy failed', 'loupe-search' );

			function acknowledge( success ) {
				btn.classList.add( 'wp-loupe-copied' );
				btn.innerHTML = success ? copiedLabel : failedLabel;
				live.textContent =
					( success ? copiedLabel : failedLabel ) +
					( success ? ': ' + val : '' );
				setTimeout( function () {
					btn.innerHTML = originalHTML;
					btn.classList.remove( 'wp-loupe-copied' );
				}, 1600 );
			}

			// Modern API path
			if (
				window.navigator &&
				window.navigator.clipboard &&
				window.navigator.clipboard.writeText
			) {
				window.navigator.clipboard
					.writeText( val )
					.then( function () {
						acknowledge( true );
					} )
					.catch( function () {
						// Fallback to legacy method
						const ta = document.createElement( 'textarea' );
						ta.value = val;
						ta.style.position = 'fixed';
						ta.style.opacity = '0';
						document.body.appendChild( ta );
						ta.select();
						try {
							if ( document.execCommand( 'copy' ) ) {
								acknowledge( true );
							} else {
								acknowledge( false );
							}
						} catch ( err ) {
							acknowledge( false );
						}
						document.body.removeChild( ta );
					} );
			} else {
				// Legacy fallback immediately
				const ta = document.createElement( 'textarea' );
				ta.value = val;
				ta.style.position = 'fixed';
				ta.style.opacity = '0';
				document.body.appendChild( ta );
				ta.select();
				try {
					if ( document.execCommand( 'copy' ) ) {
						acknowledge( true );
					} else {
						acknowledge( false );
					}
				} catch ( err ) {
					acknowledge( false );
				}
				document.body.removeChild( ta );
			}
		} );
	} )();
} );

// Add DOM utility functions
if ( window.Element && ! window.Element.prototype.matches ) {
	window.Element.prototype.matches =
		window.Element.prototype.msMatchesSelector ||
		window.Element.prototype.webkitMatchesSelector;
}

if ( window.Element && ! window.Element.prototype.closest ) {
	window.Element.prototype.closest = function ( s ) {
		let el = this;
		do {
			if ( el.matches( s ) ) {
				return el;
			}
			el = el.parentElement || el.parentNode;
		} while ( el !== null && el.nodeType === 1 );
		return null;
	};
}
