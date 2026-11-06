/**
 * Light / dark mode toggle.
 *
 * The visitor's choice is stored in localStorage as `theme` and applied as
 * `data-theme` on <html>. With no stored choice the system preference applies.
 * `inc/enqueue.php` prints an inline script that restores the stored choice
 * before first paint; this file only handles the button.
 */
const STORAGE_KEY = 'theme';

function currentTheme() {
	const explicit = document.documentElement.dataset.theme;
	if ( explicit === 'light' || explicit === 'dark' ) {
		return explicit;
	}
	return window.matchMedia( '(prefers-color-scheme: dark)' ).matches
		? 'dark'
		: 'light';
}

function applyTheme( theme ) {
	document.documentElement.dataset.theme = theme;
	try {
		window.localStorage.setItem( STORAGE_KEY, theme );
	} catch ( e ) {
		// Storage unavailable (private mode etc.); the choice lasts for this page only.
	}
	document.querySelectorAll( '.theme-toggle' ).forEach( ( button ) => {
		button.setAttribute( 'aria-pressed', theme === 'dark' ? 'true' : 'false' );
	} );
}

document.addEventListener( 'DOMContentLoaded', () => {
	const buttons = document.querySelectorAll( '.theme-toggle' );
	if ( ! buttons.length ) {
		return;
	}

	buttons.forEach( ( button ) => {
		button.setAttribute(
			'aria-pressed',
			currentTheme() === 'dark' ? 'true' : 'false'
		);
		button.addEventListener( 'click', () => {
			applyTheme( currentTheme() === 'dark' ? 'light' : 'dark' );
		} );
	} );
} );
