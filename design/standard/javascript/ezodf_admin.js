/*
 * ezodf: the live parts of the Documents pages. An element with data-ezodf-live="<status URL>" (the Document
 * converter page while an installation, a start of the server or a test runs) asks the server every two seconds
 * and updates the elements with data-ezodf-field and the progress bar data-ezodf-meter in place; when the job has
 * ended the page is loaded again, so every part shows the new state. Forms with data-a4-confirm ask first in the
 * designs without admin4's a4_actions.js. Without JavaScript the pages work as they are (reload to see progress).
 * Loaded through design.ini [JavaScriptSettings] BackendJavaScriptList; no inline script.
 */
(function ( window, document ) {
    'use strict';

    function text( root, name, value ) {
        var els = root.querySelectorAll( '[data-ezodf-field="' + name + '"]' );
        for ( var i = 0; i < els.length; i++ )
            els[i].textContent = value;
    }

    function list( root, name, lines ) {
        var el = root.querySelector( '[data-ezodf-list="' + name + '"]' );
        if ( !el )
            return;
        while ( el.firstChild )
            el.removeChild( el.firstChild );
        for ( var i = 0; i < lines.length; i++ ) {
            var li = document.createElement( 'li' ), code = document.createElement( 'code' );
            code.textContent = lines[i];
            li.appendChild( code );
            el.appendChild( li );
        }
    }

    function meter( root, percent ) {
        var m = root.querySelector( '[data-ezodf-meter]' );
        if ( !m )
            return;
        var bar = m.querySelector( 'span' );
        bar.className = 'a4-pct-' + Math.max( 0, Math.min( 100, percent - percent % 4 ) );
        m.setAttribute( 'aria-valuenow', String( percent ) );
    }

    function live( root ) {
        var url = root.getAttribute( 'data-ezodf-live' ), misses = 0, wasBusy = true;
        if ( !url || !window.fetch )
            return;
        function tick() {
            window.fetch( url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } } )
                .then( function ( r ) { if ( !r.ok ) throw new Error( r.status ); return r.json(); } )
                .then( function ( s ) {
                    misses = 0;
                    if ( s.install ) {
                        text( root, 'install_message', s.install.message );
                        if ( s.install.total > 0 )
                            text( root, 'install_bytes', s.install.mb + ' / ' + s.install.total_mb + ' MB' );
                        meter( root, s.install.percent );
                        if ( s.install.log )
                            list( root, 'install_log', s.install.log );
                    }
                    if ( s.jobs && s.jobs.test && s.jobs.test.progress )
                        text( root, 'test_job', s.jobs.test.progress );
                    if ( !s.busy && wasBusy ) {
                        window.location.reload();
                        return;
                    }
                    wasBusy = s.busy;
                    window.setTimeout( tick, 2000 );
                } )
                .catch( function () {
                    if ( ++misses < 10 )
                        window.setTimeout( tick, 4000 );
                } );
        }
        window.setTimeout( tick, 1500 );
    }

    function confirmForms() {
        document.addEventListener( 'submit', function ( e ) {
            // admin4's a4_actions.js (window.a4Actions) asks itself: asking here as well would ask twice
            if ( window.a4Actions )
                return;
            var form = e.target, q = form && form.getAttribute && form.getAttribute( 'data-a4-confirm' );
            if ( q && !form.hasAttribute( 'data-a4-confirmed' ) && !window.confirm( q ) )
                e.preventDefault();
        }, true );
    }

    function start() {
        var roots = document.querySelectorAll( '[data-ezodf-live]' );
        for ( var i = 0; i < roots.length; i++ )
            live( roots[i] );
        confirmForms();
    }

    if ( document.readyState === 'loading' )
        document.addEventListener( 'DOMContentLoaded', start );
    else
        start();
}( window, document ));
