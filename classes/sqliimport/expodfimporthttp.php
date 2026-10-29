<?php
/**
 * File containing the expOdfImportHttp class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The HTTP requests of the remote document sources of 7x SQLI Import (WebDAV and Nextcloud, Google Drive, OneDrive
 * and SharePoint), with the address policy of 7x SQLI Import:
 *
 *   - http and https only, no user name or password in the address
 *   - expImportSourceFiles::refuseUrl() must accept the address: a host of a private, loopback, link-local or
 *     reserved network only with [SourceSettings] AllowPrivateHosts=enabled or when the caller names it as trusted
 *   - the host name is resolved once and the connection pinned to the checked address (CURLOPT_RESOLVE), so a second
 *     DNS answer cannot point somewhere else
 *   - redirects are never followed automatically: at most three are followed by hand, each checked the same way; a
 *     redirect to another host drops the Authorization header
 *   - the body is streamed (into memory or a file) and stopped at expImportSourceFiles::maxBytes(); the whole request
 *     has [ImportSettings] StreamTimeout seconds
 *
 * Tests set expOdfImportHttp::$transport to a callable( $method, $url, array $headers, $body ) that returns
 * array( status, headers, body ) instead of a network request; the policy checks and the redirects still apply.
 */
class expOdfImportHttp
{
    /** The most redirects followed by hand. */
    const MAX_REDIRECTS = 3;

    /** @var callable|null a stand-in for the network (tests) */
    public static $transport = null;

    /**
     * One request.
     *
     * @param string $method GET, POST, PROPFIND ...
     * @param string $url
     * @param array $headers name => value
     * @param string|null $body
     * @param string|null $saveTo a file the body is written into (then 'body' is '')
     * @param string[] $trusted hosts allowed even in a private network (see expImportSourceFiles::refuseHost())
     * @return array( 'status' => int, 'headers' => array lower-case name => value, 'body' => string, 'file' => string|null, 'url' => the final address )
     * @throws expImportSourceException
     */
    public static function request( $method, $url, array $headers = array(), $body = null, $saveTo = null, array $trusted = array() )
    {
        $method = strtoupper( (string)$method );
        if ( !preg_match( '/^[A-Z]{3,12}$/', $method ) )
            throw new expImportSourceException( 'the request method is not valid' );
        $current = trim( (string)$url );
        for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ )
        {
            $ip = self::check( $current, $trusted );
            if ( self::$transport !== null )
                $response = self::fake( $method, $current, $headers, $body, $saveTo );
            else
                $response = self::curl( $method, $current, $ip, $headers, $body, $saveTo );
            $status = $response['status'];
            if ( in_array( $status, array( 301, 302, 303, 307, 308 ), true ) && isset( $response['headers']['location'] ) )
            {
                if ( $saveTo !== null && is_file( $saveTo ) )
                    @unlink( $saveTo );
                $next = self::absolute( $current, (string)$response['headers']['location'] );
                if ( strcasecmp( (string)parse_url( $next, PHP_URL_HOST ), (string)parse_url( $current, PHP_URL_HOST ) ) !== 0 )
                    $headers = self::withoutAuthorization( $headers );
                if ( $status === 303 )
                {
                    $method = 'GET';
                    $body = null;
                }
                $current = $next;
                continue;
            }
            $response['url'] = $current;
            return $response;
        }
        throw new expImportSourceException( 'the address ' . self::display( $url ) . ' redirects more than ' . self::MAX_REDIRECTS . ' times' );
    }

    /**
     * A request whose answer has to be 2xx JSON.
     *
     * @return array the decoded answer
     * @throws expImportSourceException
     */
    public static function json( $method, $url, array $headers = array(), $body = null, array $trusted = array() )
    {
        $r = self::request( $method, $url, $headers + array( 'Accept' => 'application/json' ), $body, null, $trusted );
        $data = json_decode( (string)$r['body'], true );
        if ( $r['status'] < 200 || $r['status'] > 299 )
            throw new expImportSourceException( self::display( $url ) . ' answered HTTP ' . $r['status'] . self::errorText( $data ) );
        if ( !is_array( $data ) )
            throw new expImportSourceException( self::display( $url ) . ' did not answer JSON' );
        return $data;
    }

    /**
     * Checks an address and resolves its host once.
     *
     * @return string the address the connection is pinned to ('' when the network is faked)
     * @throws expImportSourceException
     */
    public static function check( $url, array $trusted = array() )
    {
        $parts = parse_url( (string)$url );
        if ( !$parts || !isset( $parts['scheme'], $parts['host'] ) || !in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) )
            throw new expImportSourceException( 'only http and https addresses can be read' );
        if ( isset( $parts['user'] ) || isset( $parts['pass'] ) )
            throw new expImportSourceException( 'an address with a user name or password in it is not read: ' . self::display( $url ) );
        $refusal = expImportSourceFiles::refuseUrl( $url, $trusted );
        if ( $refusal !== null )
            throw new expImportSourceException( $refusal );
        if ( self::$transport !== null )
            return '';
        $host = trim( strtolower( $parts['host'] ), '[]' );
        if ( filter_var( $host, FILTER_VALIDATE_IP ) )
            return $host;
        $ips = (array)@gethostbynamel( $host );
        if ( !$ips )
            throw new expImportSourceException( "the host $host cannot be resolved" );
        $isTrusted = false;
        foreach ( $trusted as $t )
            if ( strcasecmp( trim( (string)$t, ' []' ), $host ) === 0 )
                $isTrusted = true;
        if ( !$isTrusted && expImport::setting( 'SourceSettings', 'AllowPrivateHosts', 'disabled' ) !== 'enabled' )
            foreach ( $ips as $ip )
                if ( expImportSourceFiles::refuseHost( $ip ) !== null )
                    throw new expImportSourceException( "the host $host is in a private or reserved network" );
        return $ips[0];
    }

    /** @return array the answer of the stand-in transport, with the size limit and the file applied */
    protected static function fake( $method, $url, array $headers, $body, $saveTo )
    {
        $r = call_user_func( self::$transport, $method, $url, $headers, $body );
        $status = isset( $r['status'] ) ? (int)$r['status'] : ( isset( $r[0] ) ? (int)$r[0] : 0 );
        $h = isset( $r['headers'] ) ? (array)$r['headers'] : ( isset( $r[1] ) ? (array)$r[1] : array() );
        $text = isset( $r['body'] ) ? (string)$r['body'] : ( isset( $r[2] ) ? (string)$r[2] : '' );
        if ( strlen( $text ) > expImportSourceFiles::maxBytes() )
            throw new expImportSourceException( 'the answer of ' . self::display( $url ) . ' is larger than [SourceSettings] MaxSourceMegabytes' );
        $headers = array();
        foreach ( $h as $name => $value )
            $headers[strtolower( (string)$name )] = (string)$value;
        if ( $saveTo !== null )
        {
            if ( file_put_contents( $saveTo, $text ) === false )
                throw new expImportSourceException( 'the download cannot be written' );
            $text = '';
        }
        return array( 'status' => $status, 'headers' => $headers, 'body' => $text, 'file' => $saveTo );
    }

    /** @return array the answer of a curl request pinned to $ip */
    protected static function curl( $method, $url, $ip, array $headers, $body, $saveTo )
    {
        if ( !function_exists( 'curl_init' ) )
            throw new expImportSourceException( 'the PHP extension curl is needed to read remote documents' );
        $parts = parse_url( $url );
        $scheme = strtolower( $parts['scheme'] );
        $host = trim( strtolower( $parts['host'] ), '[]' );
        $port = isset( $parts['port'] ) ? (int)$parts['port'] : ( $scheme === 'https' ? 443 : 80 );
        $max = expImportSourceFiles::maxBytes();
        $timeout = max( 5, (int)expImport::setting( 'ImportSettings', 'StreamTimeout', 50 ) );
        $out = null;
        if ( $saveTo !== null )
        {
            $out = @fopen( $saveTo, 'wb' );
            if ( !$out )
                throw new expImportSourceException( 'the download cannot be written' );
        }
        $buffer = '';
        $bytes = 0;
        $tooLarge = false;
        $responseHeaders = array();
        $lines = array();
        foreach ( $headers as $name => $value )
            $lines[] = $name . ': ' . str_replace( array( "\r", "\n" ), '', (string)$value );
        $ch = curl_init( $url );
        $options = array(
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT => min( 15, $timeout ),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_USERAGENT => 'Exponential ezodf/7.0 (document import)',
            CURLOPT_HEADERFUNCTION => function ( $ch, $line ) use ( &$responseHeaders ) {
                if ( preg_match( '#^HTTP/\S+\s+\d+#', $line ) )
                    $responseHeaders = array();
                $p = strpos( $line, ':' );
                if ( $p )
                    $responseHeaders[strtolower( trim( substr( $line, 0, $p ) ) )] = trim( substr( $line, $p + 1 ) );
                return strlen( $line );
            },
            CURLOPT_WRITEFUNCTION => function ( $ch, $chunk ) use ( &$buffer, &$bytes, &$tooLarge, $out, $max ) {
                $bytes += strlen( $chunk );
                if ( $bytes > $max )
                {
                    $tooLarge = true;
                    return 0;
                }
                if ( $out )
                    return (int)fwrite( $out, $chunk );
                $buffer .= $chunk;
                return strlen( $chunk );
            },
        );
        if ( $ip !== '' && !filter_var( $host, FILTER_VALIDATE_IP ) )
            $options[CURLOPT_RESOLVE] = array( "$host:$port:" . ( strpos( $ip, ':' ) !== false ? "[$ip]" : $ip ) );
        if ( $body !== null )
            $options[CURLOPT_POSTFIELDS] = (string)$body;
        elseif ( $method === 'HEAD' )
            $options[CURLOPT_NOBODY] = true;
        curl_setopt_array( $ch, $options );
        $ok = curl_exec( $ch );
        $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );
        if ( $out )
            fclose( $out );
        if ( $tooLarge )
        {
            if ( $saveTo !== null )
                @unlink( $saveTo );
            throw new expImportSourceException( 'the answer of ' . self::display( $url ) . ' is larger than [SourceSettings] MaxSourceMegabytes' );
        }
        if ( $ok === false )
        {
            if ( $saveTo !== null )
                @unlink( $saveTo );
            throw new expImportSourceException( self::display( $url ) . ' could not be read (' . ( $error !== '' ? $error : 'no answer' ) . ')' );
        }
        return array( 'status' => $status, 'headers' => $responseHeaders, 'body' => $saveTo !== null ? '' : $buffer, 'file' => $saveTo );
    }

    /** @return array the headers without Authorization (a redirect to another host, a pre-authenticated address) */
    public static function withoutAuthorization( array $headers )
    {
        foreach ( array_keys( $headers ) as $name )
            if ( strcasecmp( (string)$name, 'authorization' ) === 0 )
                unset( $headers[$name] );
        return $headers;
    }

    /** @return string an absolute address of a redirect target */
    public static function absolute( $base, $location )
    {
        $location = trim( (string)$location );
        if ( preg_match( '#^https?://#i', $location ) )
            return $location;
        $p = parse_url( (string)$base );
        $origin = $p['scheme'] . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' );
        if ( substr( $location, 0, 2 ) === '//' )
            return $p['scheme'] . ':' . $location;
        if ( substr( $location, 0, 1 ) === '/' )
            return $origin . $location;
        $path = isset( $p['path'] ) ? preg_replace( '#/[^/]*$#', '/', $p['path'] ) : '/';
        return $origin . $path . $location;
    }

    /**
     * An address for a message: without user, password, query and fragment (a pre-authenticated download address
     * carries its token in the query).
     *
     * @return string
     */
    public static function display( $url )
    {
        $p = parse_url( (string)$url );
        if ( !$p || !isset( $p['host'] ) )
            return '(an address that cannot be read)';
        return ( isset( $p['scheme'] ) ? $p['scheme'] : 'http' ) . '://' . $p['host'] . ( isset( $p['port'] ) ? ':' . $p['port'] : '' )
             . ( isset( $p['path'] ) ? $p['path'] : '/' );
    }

    /** @return string ": <the error a JSON API gave>", shortened, or '' */
    public static function errorText( $data )
    {
        if ( !is_array( $data ) )
            return '';
        $text = '';
        if ( isset( $data['error_description'] ) )
            $text = (string)$data['error_description'];
        elseif ( isset( $data['error']['message'] ) )
            $text = (string)$data['error']['message'];
        elseif ( isset( $data['error'] ) && is_string( $data['error'] ) )
            $text = $data['error'];
        $text = trim( preg_replace( '/\s+/', ' ', $text ) );
        if ( strlen( $text ) > 200 )
            $text = substr( $text, 0, 200 ) . '...';
        return $text !== '' ? ': ' . $text : '';
    }

    /**
     * A message with every secret replaced: by the names of the secrets (expImportSecrets::mask()) and the values
     * given (a password, a token, a private key).
     *
     * @param string $text
     * @param string[] $refs names of secrets
     * @param string[] $values values to hide
     * @return string
     */
    public static function mask( $text, array $refs, array $values = array() )
    {
        $text = expImportSecrets::mask( (string)$text, array_values( array_filter( $refs, 'strlen' ) ) );
        foreach ( $values as $value )
            if ( strlen( (string)$value ) >= 3 )
            {
                $text = str_replace( (string)$value, '[secret]', $text );
                $text = str_replace( base64_encode( (string)$value ), '[secret]', $text );
            }
        return $text;
    }

    /**
     * A name usable as one segment of a record path: no slashes, no "." or "..", no control characters.
     *
     * @param string $name
     * @return string
     */
    public static function segment( $name )
    {
        $name = trim( preg_replace( '/[\x00-\x1F\x7F]+/', '', str_replace( array( '/', '\\' ), '_', (string)$name ) ) );
        if ( $name === '' || $name === '.' || $name === '..' )
            return '_';
        return $name;
    }

    /**
     * A new file for a download below $dir: <hash>-<safe name>.
     *
     * @param string $dir
     * @param string $key what makes the download unique (a path, an id)
     * @param string $name
     * @return string
     */
    public static function downloadFile( $dir, $key, $name )
    {
        expOdfPaths::ensure( $dir );
        return rtrim( $dir, '/' ) . '/' . substr( sha1( (string)$key ), 0, 16 ) . '-' . expOdfRemoteFetcher::safeName( $name );
    }

    /** @return string the lower-case extension of a file name */
    public static function extensionOf( $name )
    {
        return strtolower( pathinfo( (string)$name, PATHINFO_EXTENSION ) );
    }
}
