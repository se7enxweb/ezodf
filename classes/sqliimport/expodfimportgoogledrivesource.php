<?php
/**
 * File containing the expOdfImportGoogleDriveSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * "Documents in a Google Drive folder" of 7x SQLI Import: the documents of a Drive folder (in My Drive or a shared
 * drive) and of its sub folders down to a depth.
 *
 * Once per run the folder is listed with the Drive API v3 (files.list, 200 per page, every page) and every file whose
 * extension is a document extension of ezodf is downloaded (alt=media); Google Docs, Sheets and Slides are exported
 * as .docx, .xlsx and .pptx (files.export; Google limits an export to 10 MB). Other Google files (forms, drawings,
 * shortcuts) are left out. With the option folders every folder is a record of its own before its documents.
 *
 * Authentication: a service account. Create one in the Google Cloud console, enable the Drive API, create a JSON key
 * and share the folder with the service account's address (read access is enough). The credentials option holds the
 * NAME of a secret (expImportSecrets) whose value is that JSON key; the source signs a JWT with its private key
 * (RS256, scope drive.readonly) and exchanges it at https://oauth2.googleapis.com/token for an access token. The
 * token_uri of the key is not used: the token address is fixed. The key and the token never appear in a message.
 *
 * Limits: the address policy of 7x SQLI Import (expOdfImportHttp; the two Google API hosts are fixed names and
 * trusted), [SourceSettings] MaxSourceMegabytes per answer, [ImportSettings] StreamTimeout per request, depth 1 to 10.
 *
 * One record: one document with the fields of a document record and drive_id, mime (the Drive type), modified
 * (modifiedTime), md5 (md5Checksum; empty for Google Docs), source_url (https://drive.google.com/file/d/<id>/view)
 * and id (the Drive file id); path is the path of names below the folder. A file that cannot be downloaded is a
 * failed row; the others go on.
 */
class expOdfImportGoogleDriveSource extends expOdfImportDocumentSource
{
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    const API = 'https://www.googleapis.com/drive/v3';
    const SCOPE = 'https://www.googleapis.com/auth/drive.readonly';
    const FOLDER_MIME = 'application/vnd.google-apps.folder';

    /** @var array the Google file types that are exported: Drive type => array( extension, export type ) */
    const EXPORTS = array(
        'application/vnd.google-apps.document' => array( 'docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' ),
        'application/vnd.google-apps.spreadsheet' => array( 'xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' ),
        'application/vnd.google-apps.presentation' => array( 'pptx', 'application/vnd.openxmlformats-officedocument.presentationml.presentation' ),
    );

    public static function identifier()
    {
        return 'google_drive';
    }

    public static function label()
    {
        return 'Documents in a Google Drive folder';
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
        return 'The documents of a Google Drive folder and of its sub folders down to a depth, read with a service account '
             . 'the folder is shared with: Word, OpenDocument, PDF and other document files, and Google Docs, Sheets and '
             . 'Slides exported as Word, Excel and PowerPoint files. Every document becomes a record with its title, summary, '
             . 'body as HTML with its images and its Drive id and link. The JSON key of the service account is a secret in the settings.';
    }

    public static function schema()
    {
        return array(
            'folder_id' => array( 'type' => 'string', 'label' => 'Folder ID', 'required' => true, 'max' => 200, 'pattern' => '/^[A-Za-z0-9_-]+$/',
                                  'help' => 'The last part of the folder\'s address: https://drive.google.com/drive/folders/<folder ID>' ),
            'credentials' => array( 'type' => 'secret', 'label' => 'Service account key (name of a secret)', 'required' => true,
                                    'help' => 'The name of a secret whose value is the JSON key of a service account; share the folder with that account' ),
            'depth' => array( 'type' => 'int', 'label' => 'Depth', 'default' => 3, 'min' => 1, 'max' => 10,
                              'help' => '1: only the folder itself; 2: the folder and its sub folders ...' ),
            'folders' => array( 'type' => 'bool', 'label' => 'Folders as records', 'default' => true,
                                'help' => 'Every folder is a record (kind folder) before its documents' ),
            'include_google_docs' => array( 'type' => 'bool', 'label' => 'Google Docs, Sheets and Slides', 'default' => true,
                                            'help' => 'Exported as .docx, .xlsx and .pptx (at most 10 MB each, a limit of Google)' ),
        ) + expImportAbstractSource::commonSchema();
    }

    public static function preset()
    {
        $p = parent::preset();
        $p['match'] = array( 'type' => 'remote_id', 'pattern' => 'gdrive-{id}' );
        $p['location']['parent_prefix'] = 'gdrive-';
        return $p;
    }

    /** @return string[] the API hosts (fixed names, trusted by the address policy) */
    protected static function hosts()
    {
        return array( 'oauth2.googleapis.com', 'www.googleapis.com' );
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
     * @throws expImportSourceException authentication or listing failed (the key masked)
     */
    public static function documents( array $options, $dir )
    {
        $ref = isset( $options['credentials'] ) ? trim( (string)$options['credentials'] ) : '';
        $hide = array();
        try
        {
            $folder = isset( $options['folder_id'] ) ? trim( (string)$options['folder_id'] ) : '';
            if ( !preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $folder ) )
                throw new expImportSourceException( 'Google Drive: the folder ID must be letters, digits, - and _' );
            $json = expImportSecrets::required( $ref, 'Google Drive service account key' );
            $hide[] = $json;
            $key = json_decode( $json, true );
            if ( !is_array( $key ) || empty( $key['client_email'] ) || empty( $key['private_key'] ) )
                throw new expImportSourceException( 'Google Drive: the secret is not the JSON key of a service account (client_email, private_key)' );
            $hide[] = (string)$key['private_key'];
            $token = self::accessToken( $key );
            $hide[] = $token;
            $headers = array( 'Authorization' => 'Bearer ' . $token );
            $depth = max( 1, min( 10, isset( $options['depth'] ) ? (int)$options['depth'] : 3 ) );
            $docs = !isset( $options['include_google_docs'] ) || !empty( $options['include_google_docs'] );
            $exts = static::documentExtensions();
            $queue = array( array( $folder, '', 1 ) );
            $seen = array();
            while ( $queue )
            {
                list( $id, $prefix, $level ) = array_shift( $queue );
                if ( isset( $seen[$id] ) )
                    continue;
                $seen[$id] = true;
                $names = array();
                $subfolders = array();
                foreach ( self::listFolder( $id, $headers ) as $file )
                {
                    $fid = isset( $file['id'] ) ? (string)$file['id'] : '';
                    $mime = isset( $file['mimeType'] ) ? (string)$file['mimeType'] : '';
                    if ( !preg_match( '/^[A-Za-z0-9_-]{1,200}$/', $fid ) )
                        continue;
                    $name = expOdfImportHttp::segment( isset( $file['name'] ) ? (string)$file['name'] : $fid );
                    if ( $mime === self::FOLDER_MIME )
                    {
                        if ( $level < $depth )
                            $subfolders[] = array( $fid, $prefix . self::unique( $name, $fid, $names ) . '/', $level + 1 );
                        continue;
                    }
                    $export = null;
                    if ( isset( self::EXPORTS[$mime] ) )
                    {
                        if ( !$docs )
                            continue;
                        $export = self::EXPORTS[$mime];
                        if ( expOdfImportHttp::extensionOf( $name ) !== $export[0] )
                            $name .= '.' . $export[0];
                    }
                    elseif ( strpos( $mime, 'application/vnd.google-apps.' ) === 0 )
                        continue;
                    if ( !in_array( expOdfImportHttp::extensionOf( $name ), $exts, true ) )
                        continue;
                    $path = $prefix . self::unique( $name, $fid, $names );
                    $extra = array( 'drive_id' => $fid, 'mime' => $mime,
                                    'modified' => isset( $file['modifiedTime'] ) ? (string)$file['modifiedTime'] : '',
                                    'md5' => isset( $file['md5Checksum'] ) ? (string)$file['md5Checksum'] : '',
                                    'source_url' => 'https://drive.google.com/file/d/' . $fid . '/view', 'id' => $fid );
                    $url = $export ? self::API . '/files/' . $fid . '/export?mimeType=' . rawurlencode( $export[1] )
                                   : self::API . '/files/' . $fid . '?alt=media&supportsAllDrives=true';
                    try
                    {
                        $local = expOdfImportHttp::downloadFile( $dir, $fid, basename( $path ) );
                        $r = expOdfImportHttp::request( 'GET', $url, $headers, null, $local, static::hosts() );
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

    /** @return string a name not used yet in its folder (Drive allows the same name twice): name-<id>.ext */
    protected static function unique( $name, $id, array &$names )
    {
        $key = strtolower( $name );
        if ( isset( $names[$key] ) )
        {
            $ext = pathinfo( $name, PATHINFO_EXTENSION );
            $name = pathinfo( $name, PATHINFO_FILENAME ) . '-' . substr( $id, 0, 12 ) . ( $ext !== '' ? '.' . $ext : '' );
            $key = strtolower( $name );
        }
        $names[$key] = true;
        return $name;
    }

    /**
     * Every file of one folder, page by page.
     *
     * @return array list of Drive file resources
     * @throws expImportSourceException
     */
    protected static function listFolder( $id, array $headers )
    {
        $out = array();
        $pageToken = '';
        for ( $page = 0; $page < 1000; $page++ )
        {
            $query = array(
                'q' => "'" . $id . "' in parents and trashed=false",
                'fields' => 'nextPageToken,files(id,name,mimeType,modifiedTime,size,md5Checksum)',
                'pageSize' => 200, 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
            );
            if ( $pageToken !== '' )
                $query['pageToken'] = $pageToken;
            $data = self::api( self::API . '/files?' . http_build_query( $query, '', '&', PHP_QUERY_RFC3986 ), $headers );
            foreach ( isset( $data['files'] ) && is_array( $data['files'] ) ? $data['files'] : array() as $file )
                if ( is_array( $file ) )
                    $out[] = $file;
            $pageToken = isset( $data['nextPageToken'] ) ? (string)$data['nextPageToken'] : '';
            if ( $pageToken === '' )
                break;
        }
        return $out;
    }

    /** @return array a JSON answer of the API */
    protected static function api( $url, array $headers )
    {
        try
        {
            return expOdfImportHttp::json( 'GET', $url, $headers, null, static::hosts() );
        }
        catch ( expImportSourceException $e )
        {
            throw new expImportSourceException( 'Google Drive: ' . $e->getMessage() );
        }
    }

    /**
     * An access token for a service account key.
     *
     * @param array $key the JSON key (client_email, private_key)
     * @return string
     * @throws expImportSourceException
     */
    public static function accessToken( array $key )
    {
        $body = http_build_query( array( 'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer', 'assertion' => self::jwt( $key, time() ) ), '', '&' );
        try
        {
            $data = expOdfImportHttp::json( 'POST', self::TOKEN_URL, array( 'Content-Type' => 'application/x-www-form-urlencoded' ), $body, static::hosts() );
        }
        catch ( expImportSourceException $e )
        {
            throw new expImportSourceException( 'Google Drive: the service account could not sign in (' . $e->getMessage() . ')' );
        }
        if ( empty( $data['access_token'] ) || !is_string( $data['access_token'] ) )
            throw new expImportSourceException( 'Google Drive: the token answer has no access token' );
        return $data['access_token'];
    }

    /**
     * The signed JWT of a service account (RS256).
     *
     * @param array $key client_email, private_key
     * @param int $now
     * @return string
     * @throws expImportSourceException
     */
    public static function jwt( array $key, $now )
    {
        if ( !function_exists( 'openssl_sign' ) )
            throw new expImportSourceException( 'Google Drive: the PHP extension openssl is needed' );
        $header = array( 'alg' => 'RS256', 'typ' => 'JWT' );
        $claims = array( 'iss' => (string)$key['client_email'], 'scope' => self::SCOPE, 'aud' => self::TOKEN_URL,
                         'iat' => (int)$now, 'exp' => (int)$now + 3600 );
        $input = self::base64url( json_encode( $header ) ) . '.' . self::base64url( json_encode( $claims, JSON_UNESCAPED_SLASHES ) );
        $pkey = @openssl_pkey_get_private( (string)$key['private_key'] );
        if ( !$pkey )
            throw new expImportSourceException( 'Google Drive: the private key of the service account cannot be read' );
        $signature = '';
        if ( !openssl_sign( $input, $signature, $pkey, OPENSSL_ALGO_SHA256 ) )
            throw new expImportSourceException( 'Google Drive: the JWT cannot be signed' );
        return $input . '.' . self::base64url( $signature );
    }

    /** @return string base64url without padding */
    public static function base64url( $data )
    {
        return rtrim( strtr( base64_encode( (string)$data ), '+/', '-_' ), '=' );
    }
}
