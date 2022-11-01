window.onload = () => {
	document.body.addEventListener( 'click', (e) => {
		if ( !e.target.matches( '#hs_select_all_code' ) ) {
			return;
		}
		e.preventDefault();

		const hs_code = document.getElementById( 'hs_code' );
		if ( ! hs_code ) {
			console.warn( 'Headstart Select All: Cannot find hs_code element' );
			return;
		}
		if ( ! document.createRange || ! window.getSelection ) {
			console.warn( 'Headstart Select All: Cannot find needed browser APIs' );
			return;
		}

		const range = document.createRange();
		range.selectNode( hs_code );
		window.getSelection().removeAllRanges();
		window.getSelection().addRange( range );
	} );
};
