<?php
/**
 * File containing the expOdfImportWebdavSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * "Documents in a WebDAV or Nextcloud folder" of 7x SQLI Import: the documents of a folder on a WebDAV server
 * (Nextcloud, ownCloud, a SharePoint WebDAV share, Apache mod_dav ...), its sub folders down to a depth.
 *
 * Once per run the folder is listed with PROPFIND (Depth: 1, folder by folder, so a server that refuses Depth:
 * infinity works too) and every file whose extension is a document extension of ezodf is downloaded with GET and
 * converted into a document record (expOdfImportDocumentSource). With the option folders every folder is a record of
 * its own before its documents, so a mapping can rebuild the tree.
 *
 * Authentication: HTTP Basic with a user and a password; the password option holds the NAME of a secret
 * (expImportSecrets: sqliimport.ini [SecretSettings] Secrets[] or env:VARIABLE), never the password, and it is
 * masked in every message. Nextcloud and ownCloud: use an app password (Settings, Security).
 *
 * Limits: the address policy of 7x SQLI Import (expOdfImportHttp: http and https, no private networks unless
 * [SourceSettings] AllowPrivateHosts=enabled or the option trusted, the host resolved once and pinned, redirects
 * checked, [SourceSettings] MaxSourceMegabytes per answer, [ImportSettings] StreamTimeout per request). Only what
 * lies below the folder is read: an entry the server lists outside it (another path, another host, "..") is refused
 * and becomes a failed row. Depth 1 to 10.
 *
 * One record: one document with the fields of a document record and source_url (the address of the file, without
 * credentials), etag, modified (getlastmodified as ISO 8601) and id (the path below the folder); path is the path
 * below the folder. A file that cannot be downloaded is a failed row; the others go on.
 */
class expOdfImportWebdavSource extends expOdfImportDocumentSource
{
    const DAV_NS = 'DAV:';

    public static function identifier()
    {
        return 'webdav';
    }

    public static function label()
    {
        return 'Documents in a WebDAV or Nextcloud folder';
    }

    public static function extensions()
    {
        return array();
    }

    public static function group()
    {
        return 'content';
    }

    public static function origins()
    {
        return array( 'remote' );
    }

    public static function description()
    {
        return 'The documents of a folder on a WebDAV server such as Nextcloud or ownCloud, and of its sub folders down to '
             . 'a depth: every Word, OpenDocument, PDF, RTF, Markdown, spreadsheet or presentation file becomes a record '
             . 'with its title, summary, body as HTML with its images, and the file\'s address, ETag and date; every folder '
             . 'can be a record too, so a mapping can rebuild the tree. The password is the name of a secret in the settings.';
    }

    public static function examples()
    {
        return array(
            array( 'label' => 'The Documents folder of a Nextcloud user, three levels deep, the app password as the secret cloud_documents',
                   'options' => array( 'url' => 'https://cloud.example.org/remote.php/dav/files/editor/Documents/', 'user' => 'editor',
                                       'password' => 'cloud_documents', 'depth' => 3, 'folders' => true ) ),
        );
    }

    public static function schema()
    {
        return array(
            'url' => array( 'type' => 'url', 'label' => 'Folder address', 'required' => true,
                            'help' => 'The WebDAV address of the folder, e.g. https://cloud.example.org/remote.php/dav/files/<user>/Documents/' ),
            'user' => array( 'type' => 'string', 'label' => 'User', 'max' => 255,
                             'help' => 'Empty: the folder is read without signing in' ),
            'password' => array( 'type' => 'secret', 'label' => 'Password (name of a secret)',
                                 'help' => 'The name of a secret of sqliimport.ini [SecretSettings] Secrets[] or env:VARIABLE, never the password; Nextcloud: an app password' ),
            'depth' => array( 'type' => 'int', 'label' => 'Depth', 'default' => 3, 'min' => 1, 'max' => 10,
                              'help' => '1: only the folder itself; 2: the folder and its sub folders ...' ),
            'folders' => array( 'type' => 'bool', 'label' => 'Folders as records', 'default' => true,
                                'help' => 'Every folder is a record (kind folder) before its documents' ),
            'trusted' => array( 'type' => 'bool', 'label' => 'Trust this server', 'default' => false,
                                'help' => 'On: the host of the address may be in a private network (an intranet server); only for a server you run' ),
        ) + expImportAbstractSource::commonSchema();
    }

    public static function preset()
    {
        $p = parent::preset();
        $p['match'] = array( 'type' => 'remote_id', 'pattern' => 'webdav-{id}' );
        $p['location']['parent_prefix'] = 'webdav-';
        return $p;
    }

    /**
     * Reads the folder into the run's snapshot.
     *
     * @param array $options the validated options
     * @param string $target the snapshot
     * @param int $limit 0: all
     * @throws expImportSourceException
     */
    public static function fetch( array $options, $target, $limit )
    {
        static::writeSnapshot( static::documents( $options, dirname( $target ) . '/downloads' ), $target, $limit,
                               !isset( $options['folders'] ) || !empty( $options['folders'] ) );
    }

    /**
     * The documents of the folder, as writeSnapshot() takes them: array( file, path, + fields ) or
     * array( path, error, + fields ).
     *
     * @param array $options
     * @param string $dir where the downloads go
     * @return Generator
     * @throws expImportSourceException the folder cannot be listed (password masked)
     */
    public static function documents( array $options, $dir )
    {
        $ref = isset( $options['password'] ) ? trim( (string)$options['password'] ) : '';
        $user = isset( $options['user'] ) ? trim( (string)$options['user'] ) : '';
        $password = '';
        $auth = '';
        try
        {
            $start = self::start( isset( $options['url'] ) ? (string)$options['url'] : '' );
            if ( $user !== '' )
            {
                if ( $ref === '' )
                    throw new expImportSourceException( 'WebDAV: a user needs a password (the name of a secret)' );
                $password = expImportSecrets::required( $ref, 'WebDAV password' );
                $auth = base64_encode( $user . ':' . $password );
            }
            $headers = $auth !== '' ? array( 'Authorization' => 'Basic ' . $auth ) : array();
            $trusted = !empty( $options['trusted'] ) ? array( $start['host'] ) : array();
            $depth = max( 1, min( 10, isset( $options['depth'] ) ? (int)$options['depth'] : 3 ) );
            $exts = static::documentExtensions();
            $queue = array( array( '', 1 ) );
            $seen = array();
            while ( $queue )
            {
                list( $relative, $level ) = array_shift( $queue );
                if ( isset( $seen[$relative] ) )
                    continue;
                $seen[$relative] = true;
                $entries = self::listFolder( $start, $relative, $headers, $trusted );
                $folders = array();
                foreach ( $entries as $entry )
                {
                    if ( isset( $entry['refused'] ) )
                    {
                        yield array( 'path' => '_refused/' . expOdfImportHttp::segment( $entry['name'] ),
                                     'error' => 'WebDAV: the server listed an entry outside the folder; it is not read' );
                        continue;
                    }
                    // the folder itself, or an entry that is not in the folder listed
                    if ( $entry['path'] === $relative || ( $relative !== '' && strpos( $entry['path'], $relative . '/' ) !== 0 ) )
                        continue;
                    if ( $entry['collection'] )
                    {
                        if ( $level < $depth )
                            $folders[] = array( $entry['path'], $level + 1 );
                        continue;
                    }
                    if ( !in_array( expOdfImportHttp::extensionOf( $entry['path'] ), $exts, true ) )
                        continue;
                    $url = self::urlOf( $start, $entry['path'], false );
                    $extra = array( 'source_url' => $url, 'etag' => $entry['etag'], 'modified' => $entry['modified'], 'id' => $entry['path'] );
                    try
                    {
                        $file = expOdfImportHttp::downloadFile( $dir, $entry['path'], basename( $entry['path'] ) );
                        $r = expOdfImportHttp::request( 'GET', $url, $headers, null, $file, $trusted );
                        if ( $r['status'] !== 200 )
                        {
                            @unlink( $file );
                            throw new expImportSourceException( 'HTTP ' . $r['status'] );
                        }
                        yield array( 'file' => $file, 'path' => $entry['path'] ) + $extra;
                    }
                    catch ( expImportSourceException $e )
                    {
                        yield array( 'path' => $entry['path'],
                                     'error' => expOdfImportHttp::mask( $entry['path'] . ': ' . $e->getMessage(), array( $ref ), array( $password, $auth ) ) ) + $extra;
                    }
                }
                foreach ( $folders as $folder )
                    $queue[] = $folder;
            }
        }
        catch ( expImportSourceException $e )
        {
            throw new expImportSourceException( expOdfImportHttp::mask( $e->getMessage(), array( $ref ), array( $password, $auth ) ) );
        }
    }

    /**
     * The folder address taken apart.
     *
     * @param string $url
     * @return array( origin, scheme, host, port, path (decoded, ending in /) )
     * @throws expImportSourceException
     */
    public static function start( $url )
    {
        $p = parse_url( trim( (string)$url ) );
        if ( !$p || !isset( $p['scheme'], $p['host'] ) || !in_array( strtolower( $p['scheme'] ), array( 'http', 'https' ), true ) )
            throw new expImportSourceException( 'WebDAV: the folder address must be an http or https address' );
        if ( isset( $p['user'] ) || isset( $p['pass'] ) )
            throw new expImportSourceException( 'WebDAV: put the user and the password in the options, not in the address' );
        $path = rawurldecode( isset( $p['path'] ) ? $p['path'] : '/' );
        if ( !self::cleanPath( $path ) )
            throw new expImportSourceException( 'WebDAV: the folder address has a path that is not allowed' );
        $scheme = strtolower( $p['scheme'] );
        $host = strtolower( trim( $p['host'], '[]' ) );
        $port = isset( $p['port'] ) ? (int)$p['port'] : ( $scheme === 'https' ? 443 : 80 );
        return array( 'origin' => $scheme . '://' . ( strpos( $host, ':' ) !== false ? "[$host]" : $host ) . ( isset( $p['port'] ) ? ':' . $port : '' ),
                      'scheme' => $scheme, 'host' => $host, 'port' => $port, 'path' => rtrim( $path, '/' ) . '/' );
    }

    /** @return bool whether a decoded path has no ".", "..", backslash or control characters */
    protected static function cleanPath( $path )
    {
        if ( preg_match( '/[\x00-\x1F\x7F\\\\]/', $path ) )
            return false;
        foreach ( explode( '/', $path ) as $segment )
            if ( $segment === '.' || $segment === '..' )
                return false;
        return true;
    }

    /**
     * The address of a path below the folder.
     *
     * @param array $start
     * @param string $relative decoded, without a leading slash
     * @param bool $collection
     * @return string
     */
    public static function urlOf( array $start, $relative, $collection )
    {
        $full = $start['path'] . ltrim( (string)$relative, '/' );
        $encoded = implode( '/', array_map( 'rawurlencode', explode( '/', $full ) ) );
        if ( $collection && substr( $encoded, -1 ) !== '/' )
            $encoded .= '/';
        return $start['origin'] . $encoded;
    }

    /**
     * Lists one folder with PROPFIND Depth: 1.
     *
     * @return array list of array( path (below the start folder), collection, etag, modified ) or array( refused, name )
     * @throws expImportSourceException
     */
    protected static function listFolder( array $start, $relative, array $headers, array $trusted )
    {
        $url = self::urlOf( $start, $relative, true );
        $body = '<?xml version="1.0" encoding="utf-8"?>' . "\n"
              . '<d:propfind xmlns:d="DAV:"><d:prop><d:resourcetype/><d:getlastmodified/><d:getetag/><d:getcontentlength/>'
              . '<d:getcontenttype/></d:prop></d:propfind>';
        $r = expOdfImportHttp::request( 'PROPFIND', $url, $headers + array( 'Depth' => '1', 'Content-Type' => 'application/xml; charset=utf-8' ), $body, null, $trusted );
        if ( $r['status'] === 401 || $r['status'] === 403 )
            throw new expImportSourceException( 'WebDAV: the server refused the user and password for ' . expOdfImportHttp::display( $url ) . ' (HTTP ' . $r['status'] . ')' );
        if ( $r['status'] !== 207 )
            throw new expImportSourceException( 'WebDAV: ' . expOdfImportHttp::display( $url ) . ' cannot be listed (HTTP ' . $r['status'] . ')' );
        return self::parseMultistatus( (string)$r['body'], $start );
    }

    /**
     * The entries of a multistatus answer, below the start folder.
     *
     * @param string $xml
     * @param array $start
     * @return array see listFolder()
     * @throws expImportSourceException
     */
    public static function parseMultistatus( $xml, array $start )
    {
        if ( preg_match( '/<!DOCTYPE|<!ENTITY/i', (string)$xml ) )
            throw new expImportSourceException( 'WebDAV: the answer of the server has a DOCTYPE; it is not read' );
        $dom = new DOMDocument();
        if ( trim( (string)$xml ) === '' || !@$dom->loadXML( (string)$xml, LIBXML_NONET ) )
            throw new expImportSourceException( 'WebDAV: the answer of the server is not valid XML' );
        $xp = new DOMXPath( $dom );
        $xp->registerNamespace( 'd', self::DAV_NS );
        $out = array();
        foreach ( $xp->query( '/d:multistatus/d:response' ) as $response )
        {
            $hrefNode = $xp->query( 'd:href', $response );
            if ( !$hrefNode->length )
                continue;
            $relative = self::relativeOf( trim( $hrefNode->item( 0 )->textContent ), $start );
            if ( $relative === null )
            {
                $out[] = array( 'refused' => true, 'name' => basename( rawurldecode( (string)parse_url( trim( $hrefNode->item( 0 )->textContent ), PHP_URL_PATH ) ) ) );
                continue;
            }
            if ( $relative === '' )
                continue;
            $prop = null;
            foreach ( $xp->query( 'd:propstat', $response ) as $propstat )
            {
                $status = $xp->query( 'd:status', $propstat );
                if ( !$status->length || preg_match( '#\s200\s#', ' ' . $status->item( 0 )->textContent . ' ' ) )
                {
                    $p = $xp->query( 'd:prop', $propstat );
                    if ( $p->length )
                        $prop = $p->item( 0 );
                    break;
                }
            }
            $get = function ( $name ) use ( $xp, $prop ) {
                if ( !$prop )
                    return '';
                $n = $xp->query( 'd:' . $name, $prop );
                return $n->length ? trim( $n->item( 0 )->textContent ) : '';
            };
            $collection = $prop && $xp->query( 'd:resourcetype/d:collection', $prop )->length > 0;
            $modified = $get( 'getlastmodified' );
            $time = $modified !== '' ? strtotime( $modified ) : false;
            $out[] = array( 'path' => $relative, 'collection' => $collection, 'etag' => trim( $get( 'getetag' ), '"' ),
                            'modified' => $time ? date( 'c', $time ) : $modified );
        }
        usort( $out, function ( $a, $b ) {
            return strnatcasecmp( isset( $a['path'] ) ? $a['path'] : '', isset( $b['path'] ) ? $b['path'] : '' );
        } );
        return $out;
    }

    /**
     * The path of an href below the start folder: '' for the folder itself, null for anything outside it (another
     * host, another path, "..", control characters).
     *
     * @param string $href
     * @param array $start
     * @return string|null
     */
    public static function relativeOf( $href, array $start )
    {
        $href = (string)$href;
        if ( $href === '' )
            return null;
        if ( preg_match( '#^[a-z][a-z0-9+.-]*:#i', $href ) )
        {
            $p = parse_url( $href );
            if ( !$p || !isset( $p['scheme'], $p['host'] ) || strtolower( $p['scheme'] ) !== $start['scheme']
                 || strtolower( trim( $p['host'], '[]' ) ) !== $start['host']
                 || ( isset( $p['port'] ) ? (int)$p['port'] : ( $start['scheme'] === 'https' ? 443 : 80 ) ) !== $start['port'] )
                return null;
            $path = isset( $p['path'] ) ? $p['path'] : '/';
        }
        else
        {
            if ( substr( $href, 0, 1 ) !== '/' || substr( $href, 0, 2 ) === '//' )
                return null;
            $path = (string)parse_url( 'http://h' . $href, PHP_URL_PATH );
        }
        $path = rawurldecode( $path );
        if ( !self::cleanPath( $path ) || strpos( $path, '//' ) !== false )
            return null;
        if ( rtrim( $path, '/' ) === rtrim( $start['path'], '/' ) )
            return '';
        if ( strpos( $path, $start['path'] ) !== 0 )
            return null;
        $relative = trim( substr( $path, strlen( $start['path'] ) ), '/' );
        return $relative;
    }
}
