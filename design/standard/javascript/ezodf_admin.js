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

    // ---- the Import documents page: files, folders (also dropped), sent one by one ----------------------------

    function size( n ) {
        return n > 1048576 ? ( n / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( n / 1024 ) ) + ' kB';
    }

    function walk( entry, prefix, done ) {
        // a dropped folder: every file with its path inside the folder
        if ( entry.isFile ) {
            entry.file( function ( f ) { done( [ { file: f, path: prefix + f.name } ] ); }, function () { done( [] ); } );
            return;
        }
        var reader = entry.createReader(), all = [], pending = 0, ended = false;
        function finish() { if ( ended && pending === 0 ) done( all ); }
        function read() {
            reader.readEntries( function ( entries ) {
                if ( !entries.length ) { ended = true; finish(); return; }
                for ( var i = 0; i < entries.length; i++ ) {
                    pending++;
                    walk( entries[i], prefix + entry.name + '/', function ( list ) { all = all.concat( list ); pending--; finish(); } );
                }
                read();
            }, function () { ended = true; finish(); } );
        }
        read();
    }

    function uploader( form ) {
        var url = form.getAttribute( 'data-ezodf-upload' ), maxFiles = parseInt( form.getAttribute( 'data-ezodf-max-files' ), 10 ) || 500;
        if ( !url || !window.fetch || !window.FormData || !window.XMLHttpRequest || !window.Promise )
            return;
        var chosen = [], rows = {}, list = form.querySelector( '[data-ezodf-list="selection"]' ), box = form.querySelector( '[data-ezodf-selection]' );
        var nojs = form.querySelectorAll( '[data-ezodf-nojs]' );
        for ( var n = 0; n < nojs.length; n++ )
            nojs[n].hidden = true;
        function render() {
            while ( list.firstChild )
                list.removeChild( list.firstChild );
            rows = {};
            var total = 0;
            for ( var i = 0; i < chosen.length; i++ ) {
                var tr = document.createElement( 'tr' ), a = document.createElement( 'td' ), b = document.createElement( 'td' ), c = document.createElement( 'td' );
                a.className = 'a4-ezodf-path'; a.textContent = chosen[i].path;
                b.className = 'a4-num'; b.textContent = size( chosen[i].file.size );
                tr.appendChild( a ); tr.appendChild( b ); tr.appendChild( c ); list.appendChild( tr );
                rows[i] = c;
                total += chosen[i].file.size;
            }
            box.hidden = chosen.length === 0;
            text( form, 'selection_summary', chosen.length + ' · ' + size( total ) );
        }
        function add( items ) {
            for ( var i = 0; i < items.length && chosen.length < maxFiles; i++ ) {
                var name = items[i].path.split( '/' ).pop();
                if ( name.charAt( 0 ) === '.' || name === 'Thumbs.db' || name === 'desktop.ini' )
                    continue;
                chosen.push( items[i] );
            }
            render();
        }
        var picks = form.querySelectorAll( '[data-ezodf-pick]' );
        for ( var p = 0; p < picks.length; p++ ) {
            picks[p].addEventListener( 'change', function ( e ) {
                var files = e.target.files, items = [];
                for ( var i = 0; i < files.length; i++ )
                    items.push( { file: files[i], path: files[i].webkitRelativePath || files[i].name } );
                add( items );
                e.target.value = '';
            } );
        }
        var drop = form.querySelector( '[data-ezodf-drop]' );
        if ( drop ) {
            drop.addEventListener( 'dragover', function ( e ) { e.preventDefault(); drop.classList.add( 'is-over' ); } );
            drop.addEventListener( 'dragleave', function () { drop.classList.remove( 'is-over' ); } );
            drop.addEventListener( 'drop', function ( e ) {
                e.preventDefault();
                drop.classList.remove( 'is-over' );
                var items = e.dataTransfer.items, files = e.dataTransfer.files;
                if ( items && items.length && items[0].webkitGetAsEntry ) {
                    for ( var i = 0; i < items.length; i++ ) {
                        var entry = items[i].webkitGetAsEntry();
                        if ( entry )
                            walk( entry, '', add );
                    }
                } else {
                    var plain = [];
                    for ( var j = 0; j < files.length; j++ )
                        plain.push( { file: files[j], path: files[j].name } );
                    add( plain );
                }
            } );
        }
        var clear = form.querySelector( '[data-ezodf-clear]' );
        if ( clear )
            clear.addEventListener( 'click', function () { chosen = []; render(); } );

        function post( fields, file, path, onProgress ) {
            return new Promise( function ( resolve, reject ) {
                var fd = new FormData(), xhr = new XMLHttpRequest(), t = form.querySelector( 'input[name="ezxform_token"]' );
                if ( t )
                    fd.append( 'ezxform_token', t.value );
                for ( var k in fields )
                    if ( Object.prototype.hasOwnProperty.call( fields, k ) )
                        fd.append( k, fields[k] );
                if ( file )
                    fd.append( 'File', file, path.split( '/' ).pop() );
                xhr.open( 'POST', url );
                xhr.setRequestHeader( 'Accept', 'application/json' );
                if ( onProgress && xhr.upload )
                    xhr.upload.onprogress = function ( e ) { if ( e.lengthComputable ) onProgress( e.loaded / e.total ); };
                xhr.onload = function () {
                    var data = null;
                    try { data = JSON.parse( xhr.responseText ); } catch ( err ) { data = { error: 'HTTP ' + xhr.status }; }
                    if ( xhr.status === 200 && data && !data.error ) resolve( data ); else reject( new Error( data && data.error ? data.error : 'HTTP ' + xhr.status ) );
                };
                xhr.onerror = function () { reject( new Error( 'network' ) ); };
                xhr.send( fd );
            } );
        }
        form.addEventListener( 'submit', function ( e ) {
            var by = e.submitter;
            if ( !by || by.name !== 'StartImportButton' )
                return;
            var urls = form.querySelector( 'textarea[name="URLs"]' ).value.trim();
            if ( !chosen.length && !urls )
                return; // the server says what is missing
            e.preventDefault();
            by.disabled = true;
            var m = form.querySelector( '[data-ezodf-meter]' );
            if ( m ) m.hidden = false;
            var fields = { Action: 'create', NodeID: ( form.querySelector( 'input[name="NodeID"]' ) || {} ).value || '' };
            [ 'Tree', 'Replace', 'OtherFiles', 'AIAssist' ].forEach( function ( name ) {
                var el = form.querySelector( 'input[name="' + name + '"]' );
                if ( el && el.checked ) fields[name] = '1';
            } );
            [ 'Language', 'Title' ].forEach( function ( name ) {
                var el = form.querySelector( '[name="' + name + '"]' );
                if ( el ) fields[name] = el.value;
            } );
            var total = chosen.length, failed = 0;
            text( form, 'upload_status', '…' );
            post( fields ).then( function ( created ) {
                var id = created.batch, i = 0;
                function next() {
                    if ( i >= total )
                        return Promise.resolve();
                    var k = i++, item = chosen[k];
                    rows[k].textContent = '↑';
                    return post( { Action: 'file', BatchID: id, Path: item.path }, item.file, item.path, function ( f ) {
                        meter( form, Math.round( ( ( k + f ) / Math.max( 1, total ) ) * 100 ) );
                    } ).then( function () { rows[k].textContent = '✓'; }, function ( err ) { failed++; rows[k].textContent = err.message; } )
                      .then( function () { text( form, 'upload_status', ( k + 1 ) + ' / ' + total ); return next(); } );
                }
                return next().then( function () {
                    return urls ? post( { Action: 'urls', BatchID: id, URLs: urls } ) : null;
                } ).then( function () {
                    return post( { Action: 'start', BatchID: id } );
                } ).then( function ( started ) {
                    window.location.href = started.url;
                } );
            } ).catch( function ( err ) {
                text( form, 'upload_status', err.message + ( failed ? ' (' + failed + ')' : '' ) );
                by.disabled = false;
            } );
        } );
    }

    // ---- the batch page while it runs ----------------------------------------------------------------------

    function batch( root ) {
        var url = root.getAttribute( 'data-ezodf-batch' ), misses = 0;
        if ( !url || !window.fetch )
            return;
        function tick() {
            window.fetch( url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } } )
                .then( function ( r ) { if ( !r.ok ) throw new Error( r.status ); return r.json(); } )
                .then( function ( s ) {
                    misses = 0;
                    var known = root.querySelectorAll( '[data-ezodf-item]' ).length;
                    [ 'total', 'done', 'failed', 'skipped' ].forEach( function ( k ) { text( root, 'count_' + k, String( s.counts[k] ) ); } );
                    meter( root, s.percent );
                    for ( var i = 0; i < s.items.length; i++ ) {
                        var tr = root.querySelector( '[data-ezodf-item="' + s.items[i].id + '"]' );
                        var badge = tr && tr.querySelector( '[data-ezodf-item-state]' );
                        if ( badge && badge.textContent !== s.items[i].state_label ) {
                            badge.textContent = s.items[i].state_label;
                            badge.className = 'a4-badge' + ( s.items[i].state === 'done' ? ' is-ok' : s.items[i].state === 'failed' ? ' is-bad' : s.items[i].state === 'running' ? ' is-warn' : '' );
                        }
                    }
                    // an unpacked ZIP added documents, and an ended batch shows its links: load the page again
                    if ( !s.busy || s.items.length !== known ) {
                        window.location.reload();
                        return;
                    }
                    window.setTimeout( tick, 2000 );
                } )
                .catch( function () { if ( ++misses < 10 ) window.setTimeout( tick, 4000 ); } );
        }
        window.setTimeout( tick, 1500 );
    }

    function start() {
        var roots = document.querySelectorAll( '[data-ezodf-live]' );
        for ( var i = 0; i < roots.length; i++ )
            live( roots[i] );
        var forms = document.querySelectorAll( 'form[data-ezodf-upload]' );
        for ( var f = 0; f < forms.length; f++ )
            uploader( forms[f] );
        var batches = document.querySelectorAll( '[data-ezodf-batch]' );
        for ( var b = 0; b < batches.length; b++ )
            batch( batches[b] );
        confirmForms();
    }

    if ( document.readyState === 'loading' )
        document.addEventListener( 'DOMContentLoaded', start );
    else
        start();
}( window, document ));
