/**
 * Seeds the "Exclude from Loupe Search" Quick Edit checkbox from the row's
 * Search column, which core's inline-edit script knows nothing about.
 */
( function () {
	if ( typeof window.inlineEditPost === 'undefined' ) {
		return;
	}

	const coreEdit = window.inlineEditPost.edit;

	window.inlineEditPost.edit = function ( id ) {
		coreEdit.apply( this, arguments );

		const postId =
			typeof id === 'object' ? parseInt( this.getId( id ), 10 ) : id;
		if ( ! postId ) {
			return;
		}

		const row = document.getElementById( 'post-' + postId );
		const editRow = document.getElementById( 'edit-' + postId );
		if ( ! row || ! editRow ) {
			return;
		}

		const marker = row.querySelector( '.loupe-search-exclusion' );
		const checkbox = editRow.querySelector(
			'input[name="loupe_search_exclude"]'
		);
		if ( marker && checkbox ) {
			checkbox.checked = marker.dataset.excluded === '1';
		}
	};
} )();
