<?php
/**
 * File containing the expOdfRemoteServer class.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * A conversion service elsewhere, set on the Document converter page (converter.remote_url, remote_kind, an
 * optional user and password for HTTP basic authentication):
 *
 *   unoserver   the XML-RPC interface of unoserver (https://github.com/unoconv/unoserver, port 2003 by default):
 *               convert(inpath, indata, outpath, convert_to, filtername, filter_options, update_index, infiltername)
 *               with the document as data; any target format.
 *   gotenberg   Gotenberg's LibreOffice route (POST <url>/forms/libreoffice/convert, multipart "files"): PDF only.
 *
 * The address is the administrator's own server, so private addresses are allowed here (unlike document URLs, see
 * expOdfRemoteFetcher); only http and https are.
 */
class expOdfRemoteServer
{
    /**
     * @return array( 'seconds' => float, 'version' => string )
     * @throws expOdfConverterException
     */
    public static function convert( $file, $ext, $target, $outFile, array $options = array() )
    {
        $url = rtrim( expOdfSettings::get( 'converter.remote_url' ), '/' );
        if ( $url === '' )
            throw new expOdfConverterException( 'remote', 'no remote server is set' );
        $start = microtime( true );
        $kind = expOdfSettings::get( 'converter.remote_kind' );
        if ( $kind === 'gotenberg' )
        {
            if ( $target !== 'pdf' )
                throw new expOdfConverterException( 'unsupported', 'Gotenberg converts to PDF only' );
            $body = self::request( $url . '/forms/libreoffice/convert', array( 'files' => new CURLFile( $file, 'application/octet-stream', 'document.' . ( $ext ?: 'bin' ) ) ) );
            if ( substr( $body, 0, 5 ) !== '%PDF-' )
                throw new expOdfConverterException( 'remote', 'Gotenberg did not answer with a PDF' );
            file_put_contents( $outFile, $body );
            return array( 'seconds' => round( microtime( true ) - $start, 3 ), 'version' => 'Gotenberg' );
        }
        $data = file_get_contents( $file );
        $xml = self::xmlrpcCall( 'convert', array(
            null, array( 'base64' => $data ), null, $target,
            isset( $options['filter'] ) && $options['filter'] !== '' ? (string)$options['filter'] : null,
            array(), false,
            isset( $options['infilter'] ) && $options['infilter'] !== '' ? (string)$options['infilter'] : null ) );
        $answer = self::request( $url . '/RPC2', $xml, 'text/xml' );
        $value = self::xmlrpcResult( $answer );
        if ( !is_string( $value ) || $value === '' )
            throw new expOdfConverterException( 'remote', 'unoserver returned no document' );
        file_put_contents( $outFile, $value );
        return array( 'seconds' => round( microtime( true ) - $start, 3 ), 'version' => 'unoserver' );
    }

    /**
     * Whether the remote server answers, and what it says it is.
     * @return array( 'ok' => bool, 'message' => string )
     */
    public static function test()
    {
        $url = rtrim( expOdfSettings::get( 'converter.remote_url' ), '/' );
        if ( $url === '' )
            return array( 'ok' => false, 'message' => 'No remote server is set.' );
        try
        {
            if ( expOdfSettings::get( 'converter.remote_kind' ) === 'gotenberg' )
            {
                $body = self::request( $url . '/health', null );
                $health = json_decode( $body, true );
                $ok = is_array( $health ) && isset( $health['status'] ) && $health['status'] === 'up';
                return array( 'ok' => $ok, 'message' => $ok ? 'Gotenberg answers: status up.' : 'Gotenberg answered, but not with status up.' );
            }
            $value = self::xmlrpcResult( self::request( $url . '/RPC2', self::xmlrpcCall( 'info', array() ), 'text/xml' ) );
            $version = is_array( $value ) && isset( $value['unoserver'] ) ? $value['unoserver'] : '';
            return array( 'ok' => true, 'message' => 'unoserver answers' . ( $version !== '' ? ' (version ' . $version . ')' : '' ) . '.' );
        }
        catch ( expOdfConverterException $e )
        {
            return array( 'ok' => false, 'message' => $e->getMessage() );
        }
    }

    /** @return string the body of a POST (or GET when $body is null) */
    protected static function request( $url, $body, $contentType = null )
    {
        $scheme = strtolower( (string)parse_url( $url, PHP_URL_SCHEME ) );
        if ( !in_array( $scheme, array( 'http', 'https' ), true ) )
            throw new expOdfConverterException( 'remote', 'the remote server needs an http or https address' );
        $ch = curl_init( $url );
        $timeout = expOdfSettings::get( 'converter.timeout' );
        $opts = array( CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10,
                       CURLOPT_TIMEOUT => $timeout + 15, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                       CURLOPT_USERAGENT => 'Exponential ezodf/7.0' );
        if ( $body !== null )
        {
            $opts[CURLOPT_POST] = true;
            $opts[CURLOPT_POSTFIELDS] = $body;
            if ( $contentType )
                $opts[CURLOPT_HTTPHEADER] = array( 'Content-Type: ' . $contentType );
        }
        $user = expOdfSettings::get( 'converter.remote_username' );
        if ( $user !== '' )
        {
            $opts[CURLOPT_HTTPAUTH] = CURLAUTH_BASIC;
            $opts[CURLOPT_USERPWD] = $user . ':' . expOdfSettings::get( 'converter.remote_password' );
        }
        curl_setopt_array( $ch, $opts );
        $answer = curl_exec( $ch );
        $status = (int)curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $error = curl_error( $ch );
        curl_close( $ch );
        if ( $answer === false )
            throw new expOdfConverterException( 'remote', 'the remote server cannot be reached (' . $error . ')' );
        if ( $status >= 400 )
            throw new expOdfConverterException( 'remote', "the remote server answered with HTTP $status" );
        return (string)$answer;
    }

    // ---- a minimal XML-RPC (no extension needed) --------------------------------------------------------------

    public static function xmlrpcCall( $method, array $params )
    {
        $xml = '<?xml version="1.0"?><methodCall><methodName>' . htmlspecialchars( $method, ENT_XML1 ) . '</methodName><params>';
        foreach ( $params as $p )
            $xml .= '<param>' . self::xmlrpcValue( $p ) . '</param>';
        return $xml . '</params></methodCall>';
    }

    protected static function xmlrpcValue( $v )
    {
        if ( $v === null )
            return '<value><nil/></value>';
        if ( is_bool( $v ) )
            return '<value><boolean>' . ( $v ? 1 : 0 ) . '</boolean></value>';
        if ( is_int( $v ) )
            return '<value><int>' . $v . '</int></value>';
        if ( is_array( $v ) && isset( $v['base64'] ) && count( $v ) === 1 )
            return '<value><base64>' . base64_encode( $v['base64'] ) . '</base64></value>';
        if ( is_array( $v ) )
        {
            if ( $v === array() || array_keys( $v ) === range( 0, count( $v ) - 1 ) )
            {
                $out = '<value><array><data>';
                foreach ( $v as $item )
                    $out .= self::xmlrpcValue( $item );
                return $out . '</data></array></value>';
            }
            $out = '<value><struct>';
            foreach ( $v as $k => $item )
                $out .= '<member><name>' . htmlspecialchars( (string)$k, ENT_XML1 ) . '</name>' . self::xmlrpcValue( $item ) . '</member>';
            return $out . '</struct></value>';
        }
        return '<value><string>' . htmlspecialchars( (string)$v, ENT_XML1 ) . '</string></value>';
    }

    public static function xmlrpcResult( $xml )
    {
        $dom = new DOMDocument();
        if ( !@$dom->loadXML( $xml, LIBXML_NONET | LIBXML_PARSEHUGE ) )
            throw new expOdfConverterException( 'remote', 'the remote server did not answer with XML-RPC' );
        $xpath = new DOMXPath( $dom );
        $fault = $xpath->query( '/methodResponse/fault/value' );
        if ( $fault->length )
        {
            $f = self::xmlrpcParse( $fault->item( 0 ) );
            throw new expOdfConverterException( 'remote', 'the remote server reported: ' . ( is_array( $f ) && isset( $f['faultString'] ) ? substr( (string)$f['faultString'], 0, 300 ) : 'an error' ) );
        }
        $value = $xpath->query( '/methodResponse/params/param/value' );
        return $value->length ? self::xmlrpcParse( $value->item( 0 ) ) : null;
    }

    protected static function xmlrpcParse( DOMElement $value )
    {
        $child = null;
        foreach ( $value->childNodes as $n )
            if ( $n instanceof DOMElement )
            {
                $child = $n;
                break;
            }
        if ( !$child )
            return $value->textContent;
        switch ( $child->nodeName )
        {
            case 'base64':
                return base64_decode( $child->textContent );
            case 'int':
            case 'i4':
            case 'i8':
                return (int)$child->textContent;
            case 'boolean':
                return $child->textContent === '1';
            case 'double':
                return (float)$child->textContent;
            case 'nil':
                return null;
            case 'array':
                $out = array();
                foreach ( $child->getElementsByTagName( 'data' )->item( 0 )->childNodes as $n )
                    if ( $n instanceof DOMElement && $n->nodeName === 'value' )
                        $out[] = self::xmlrpcParse( $n );
                return $out;
            case 'struct':
                $out = array();
                foreach ( $child->childNodes as $member )
                {
                    if ( !$member instanceof DOMElement )
                        continue;
                    $name = $member->getElementsByTagName( 'name' )->item( 0 );
                    $val = $member->getElementsByTagName( 'value' )->item( 0 );
                    if ( $name && $val )
                        $out[$name->textContent] = self::xmlrpcParse( $val );
                }
                return $out;
        }
        return $child->textContent;
    }
}
