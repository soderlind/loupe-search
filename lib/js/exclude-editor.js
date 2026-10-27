/**
 * Block-editor panel for marking a post as an Excluded post.
 *
 * @param {Object} wp WordPress script globals.
 */
( function ( wp ) {
	const { registerPlugin } = wp.plugins;
	const { PluginDocumentSettingPanel } = wp.editor;
	const { CheckboxControl } = wp.components;
	const { useSelect, useDispatch } = wp.data;
	const { createElement: el } = wp.element;
	const { __ } = wp.i18n;

	const FIELD = 'loupe_search_exclude';

	function ExcludePanel() {
		const excluded = useSelect(
			( select ) =>
				!! select( 'core/editor' ).getEditedPostAttribute( FIELD ),
			[]
		);
		const { editPost } = useDispatch( 'core/editor' );

		return el(
			PluginDocumentSettingPanel,
			{
				name: 'loupe-search-exclude',
				title: __( 'Loupe Search', 'loupe-search' ),
			},
			el( CheckboxControl, {
				__nextHasNoMarginBottom: true,
				label: __( 'Exclude from Loupe Search', 'loupe-search' ),
				help: __(
					'Hides this post from site search. It stays reachable by its URL; this is not access control.',
					'loupe-search'
				),
				checked: excluded,
				onChange: ( value ) => editPost( { [ FIELD ]: value } ),
			} )
		);
	}

	registerPlugin( 'loupe-search-exclude', { render: ExcludePanel } );
} )( window.wp );
