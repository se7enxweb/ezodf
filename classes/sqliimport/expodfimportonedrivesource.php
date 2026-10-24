<?php
/**
 * File containing the expOdfImportOneDriveSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * "Documents in a OneDrive or SharePoint folder" of 7x SQLI Import: the documents of a folder of a OneDrive for
 * Business drive or a SharePoint document library, and of its sub folders down to a depth, read with Microsoft Graph.
 *
 * Once per run the folder is listed (driveItem children, every page of @odata.nextLink) and every file whose
 * extension is a document extension of ezodf is downloaded from its @microsoft.graph.downloadUrl, a short-lived
 * pre-authenticated address: it is checked by the address policy like any other address and requested WITHOUT the
 * Authorization header. With the option folders every folder is a record of its own before its documents.
 *
 * Authentication: an app registration in Microsoft Entra ID with the application permission Files.Read.All (or
 * Sites.Selected for one site) and a client secret; the source signs in with the client credentials flow at
 * https://login.microsoftonline.com/<tenant>/oauth2/v2.0/token (scope https://graph.microsoft.com/.default). The
 * client_secret option holds the NAME of a secret (expImportSecrets), never the secret, and it is masked in every
 * message.
 *
 * The drive is one of users/<user principal name or id>/drive, sites/<site id>/drive or drives/<drive id>, checked
 * strictly (no other host, no path tricks); the folder is a path inside the drive (empty: its root).
 *
 * Limits: the address policy of 7x SQLI Import (expOdfImportHttp; the Graph and sign-in hosts are fixed names and
 * trusted, a download address is not), [SourceSettings] MaxSourceMegabytes per answer, [ImportSettings]
 * StreamTimeout per request, depth 1 to 10.
 *
 * One record: one document with the fields of a document record and item_id, etag (eTag), modified
 * (lastModifiedDateTime), source_url (webUrl) and id (the item id); path is the path below the folder. A file that
 * cannot be downloaded is a failed row; the others go on.
 */
class expOdfImportOneDriveSource extends expOdfImportDocumentSource
{
    const GRAPH = 'https://graph.microsoft.com/v1.0';
    const SCOPE = 'https://graph.microsoft.com/.default';

    /** The forms of a drive: users/<upn or id>/drive, sites/<site id>/drive, drives/<drive id> */
    const DRIVE_PATTERN = '#^(users/[A-Za-z0-9][A-Za-z0-9._@+-]{0,199}/drive|sites/[A-Za-z0-9][A-Za-z0-9.,_-]{0,299}/drive|drives/[A-Za-z0-9!_-]{1,200})$#';

    public static function identifier()
    {
        return 'onedrive';
    }

    public static function label()
    {
        return 'Documents in a OneDrive or SharePoint folder';
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
        return 'The documents of a folder of OneDrive for Business or of a SharePoint document library, and of its sub '
             . 'folders down to a depth, read with Microsoft Graph by an app registration: every Word, OpenDocument, PDF, '
             . 'spreadsheet or presentation file becomes a record with its title, summary, body as HTML with its images, '
             . 'and its item id, ETag, date and web address. The client secret is a secret in the settings.';
    }

    public static function schema()
    {
        return array(
            'tenant' => array( 'type' => 'string', 'label' => 'Tenant', 'required' => true, 'max' => 100, 'pattern' => '/^[A-Za-z0-9][A-Za-z0-9.-]*$/',
                               'help' => 'The directory (tenant) ID or a domain of the tenant, e.g. example.onmicrosoft.com' ),
            'client_id' => array( 'type' => 'string', 'label' => 'Application (client) ID', 'required' => true, 'max' => 100, 'pattern' => '/^[A-Za-z0-9-]+$/' ),
            'client_secret' => array( 'type' => 'secret', 'label' => 'Client secret (name of a secret)', 'required' => true,
                                      'help' => 'The name of a secret of sqliimport.ini [SecretSettings] Secrets[] or env:VARIABLE, never the client secret' ),
            'drive' => array( 'type' => 'string', 'label' => 'Drive', 'required' => true, 'max' => 320, 'pattern' => self::DRIVE_PATTERN,
                              'help' => 'users/<user principal name>/drive, sites/<site id>/drive or drives/<drive id>' ),
            'folder' => array( 'type' => 'string', 'label' => 'Folder', 'default' => '', 'max' => 400,
                               'help' => 'A path inside the drive, e.g. Documents/Press; empty: the root of the drive' ),
            'depth' => array( 'type' => 'int', 'label' => 'Depth', 'default' => 3, 'min' => 1, 'max' => 10,
                              'help' => '1: only the folder itself; 2: the folder and its sub folders ...' ),
            'folders' => array( 'type' => 'bool', 'label' => 'Folders as records', 'default' => true,
                                'help' => 'Every folder is a record (kind folder) before its documents' ),
        ) + expImportAbstractSource::commonSchema();
    }

    public static function preset()
    {
        $p = parent::preset();
        $p['match'] = array( 'type' => 'remote_id', 'pattern' => 'onedrive-{id}' );
        $p['location']['parent_prefix'] = 'onedrive-';
        return $p;
    }

    /** @return string[] the API hosts (fixed names, trusted by the address policy) */
    protected static function hosts()
    {
        return array( 'login.microsoftonline.com', 'graph.microsoft.com' );
    }

    /**
     * Reads the folder into the run's snapshot.
     *
     * @throws expImportSourceException
     */
    public static function fetch( array $options, $target, $limit )
    {
        static::writeSnapshot( static::documents( $options, dirname( $target ) . '/downloads' ), $target, $limit,
                               !isset( $options['folders'] ) || !empty( $options['folders'] ) );
    }

    /**
     * The documents of the folder, as writeSnapshot() takes them.
     *
     * @param array $options
     * @param string $dir where the downloads go
     * @return Generator
     * @throws expImportSourceException authentication or listing failed (the secret masked)
     */
    public static function documents( array $options, $dir )
    {
        $ref = isset( $options['client_secret'] ) ? trim( (string)$options['client_secret'] ) : '';
        $hide = array();
        try
        {
            $drive = self::drive( isset( $options['drive'] ) ? (string)$options['drive'] : '' );
            $segments = self::folderSegments( isset( $options['folder'] ) ? (string)$options['folder'] : '' );
            $tenant = isset( $options['tenant'] ) ? trim( (string)$options['tenant'] ) : '';
            $client = isset( $options['client_id'] ) ? trim( (string)$options['client_id'] ) : '';
            if ( !preg_match( '/^[A-Za-z0-9][A-Za-z0-9.-]{0,99}$/', $tenant ) || strpos( $tenant, '..' ) !== false )
                throw new expImportSourceException( 'OneDrive: the tenant must be a tenant ID or a domain' );
            if ( !preg_match( '/^[A-Za-z0-9-]{1,100}$/', $client ) )
                throw new expImportSourceException( 'OneDrive: the client ID must be letters, digits and -' );
            $secret = expImportSecrets::required( $ref, 'OneDrive client secret' );
            $hide[] = $secret;
            $hide[] = rawurlencode( $secret );
            $hide[] = urlencode( $secret );
            $token = self::accessToken( $tenant, $client, $secret );
            $hide[] = $token;
            $headers = array( 'Authorization' => 'Bearer ' . $token );
            $depth = max( 1, min( 10, isset( $options['depth'] ) ? (int)$options['depth'] : 3 ) );
            $exts = static::documentExtensions();
            $first = $segments
                   ? self::GRAPH . '/' . $drive . '/root:/' . implode( '/', array_map( 'rawurlencode', $segments ) ) . ':/children'
                   : self::GRAPH . '/' . $drive . '/root/children';
            $queue = array( array( $first, '', 1 ) );
            $seen = array();
            while ( $queue )
            {
                list( $url, $prefix, $level ) = array_shift( $queue );
                if ( isset( $seen[$url] ) )
                    continue;
                $seen[$url] = true;
                $subfolders = array();
                foreach ( self::children( $url, $headers ) as $item )
                {
                    $id = isset( $item['id'] ) ? (string)$item['id'] : '';
                    if ( !preg_match( '/^[A-Za-z0-9!_.-]{1,200}$/', $id ) )
                        continue;
                    $name = expOdfImportHttp::segment( isset( $item['name'] ) ? (string)$item['name'] : $id );
                    if ( isset( $item['folder'] ) )
                    {
                        if ( $level < $depth )
                            $subfolders[] = array( self::GRAPH . '/' . $drive . '/items/' . rawurlencode( $id ) . '/children', $prefix . $name . '/', $level + 1 );
                        continue;
                    }
                    if ( !isset( $item['file'] ) || !in_array( expOdfImportHttp::extensionOf( $name ), $exts, true ) )
                        continue;
                    $path = $prefix . $name;
                    $extra = array( 'item_id' => $id, 'etag' => isset( $item['eTag'] ) ? (string)$item['eTag'] : '',
                                    'modified' => isset( $item['lastModifiedDateTime'] ) ? (string)$item['lastModifiedDateTime'] : '',
                                    'source_url' => isset( $item['webUrl'] ) && preg_match( '#^https://#i', (string)$item['webUrl'] ) ? (string)$item['webUrl'] : '',
                                    'id' => $id );
                    try
                    {
                        $local = expOdfImportHttp::downloadFile( $dir, $id, $name );
                        if ( !empty( $item['@microsoft.graph.downloadUrl'] ) )
                        {
                            // a pre-authenticated address: checked like any address, never sent the token
                            $download = (string)$item['@microsoft.graph.downloadUrl'];
                            $r = expOdfImportHttp::request( 'GET', $download, array(), null, $local );
                        }
                        else
                            $r = expOdfImportHttp::request( 'GET', self::GRAPH . '/' . $drive . '/items/' . rawurlencode( $id ) . '/content', $headers, null, $local, static::hosts() );
                        if ( $r['status'] !== 200 )
                        {
                            @unlink( $local );
                            throw new expImportSourceException( 'HTTP ' . $r['status'] );
                        }
                        yield array( 'file' => $local, 'path' => $path ) + $extra;
                    }
                    catch ( expImportSourceException $e )
                    {
                        yield array( 'path' => $path, 'error' => expOdfImportHttp::mask( $path . ': ' . $e->getMessage(), array( $ref ), $hide ) ) + $extra;
                    }
                }
                foreach ( $subfolders as $sub )
                    $queue[] = $sub;
            }
        }
        catch ( expImportSourceException $e )
        {
            throw new expImportSourceException( expOdfImportHttp::mask( $e->getMessage(), array( $ref ), $hide ) );
        }
    }

    /**
     * The drive option, checked.
     *
     * @param string $drive
     * @return string
     * @throws expImportSourceException
     */
    public static function drive( $drive )
    {
        $drive = trim( (string)$drive );
        if ( !preg_match( self::DRIVE_PATTERN, $drive ) || strpos( $drive, '..' ) !== false )
            throw new expImportSourceException( 'OneDrive: the drive must be users/<user>/drive, sites/<site id>/drive or drives/<drive id>' );
        return $drive;
    }

    /**
     * The folder option as path segments.
     *
     * @param string $folder
     * @return string[]
     * @throws expImportSourceException
     */
    public static function folderSegments( $folder )
    {
        $out = array();
        foreach ( explode( '/', str_replace( '\\', '/', trim( (string)$folder ) ) ) as $segment )
        {
            $segment = trim( $segment );
            if ( $segment === '' )
                continue;
            if ( $segment === '.' || $segment === '..' || preg_match( '/[\x00-\x1F\x7F:#?%*<>|"]/', $segment ) )
                throw new expImportSourceException( 'OneDrive: the folder path has a part that is not allowed: ' . $segment );
            $out[] = $segment;
        }
        return $out;
    }

    /**
     * Every child of a folder, page by page (@odata.nextLink, only on the Graph host).
     *
     * @return array list of driveItems
     * @throws expImportSourceException
     */
    protected static function children( $url, array $headers )
    {
        $out = array();
        $next = $url . ( strpos( $url, '?' ) === false ? '?' : '&' ) . '$top=200';
        for ( $page = 0; $page < 1000 && $next !== ''; $page++ )
        {
            if ( strpos( $next, self::GRAPH . '/' ) !== 0 )
                throw new expImportSourceException( 'OneDrive: the next page is not an address of Microsoft Graph' );
            try
            {
                $data = expOdfImportHttp::json( 'GET', $next, $headers, null, static::hosts() );
            }
            catch ( expImportSourceException $e )
            {
                throw new expImportSourceException( 'OneDrive: ' . $e->getMessage() );
            }
            foreach ( isset( $data['value'] ) && is_array( $data['value'] ) ? $data['value'] : array() as $item )
                if ( is_array( $item ) )
                    $out[] = $item;
            $next = isset( $data['@odata.nextLink'] ) ? (string)$data['@odata.nextLink'] : '';
        }
        return $out;
    }

    /**
     * An access token of the client credentials flow.
     *
     * @return string
     * @throws expImportSourceException
     */
    public static function accessToken( $tenant, $clientId, $secret )
    {
        $body = http_build_query( array( 'client_id' => $clientId, 'client_secret' => $secret, 'scope' => self::SCOPE,
                                         'grant_type' => 'client_credentials' ), '', '&' );
        try
        {
            $data = expOdfImportHttp::json( 'POST', 'https://login.microsoftonline.com/' . rawurlencode( $tenant ) . '/oauth2/v2.0/token',
                                            array( 'Content-Type' => 'application/x-www-form-urlencoded' ), $body, static::hosts() );
        }
        catch ( expImportSourceException $e )
        {
            throw new expImportSourceException( 'OneDrive: the app could not sign in (' . $e->getMessage() . ')' );
        }
        if ( empty( $data['access_token'] ) || !is_string( $data['access_token'] ) )
            throw new expImportSourceException( 'OneDrive: the token answer has no access token' );
        return $data['access_token'];
    }
}
