<?php
/**
 * File containing the expOdfImportMailboxDocumentsSource class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * "Documents attached to e-mails (IMAP)" of 7x SQLI Import: the document attachments of the messages of an IMAP
 * mailbox (a submissions address, a press inbox that receives releases as Word or PDF files).
 *
 * Once per run the matching messages are read the way 7x SQLI Import's IMAP source reads them
 * (expImportImapSource: the same options, the same transport, the mailbox opened read-only unless the option
 * mark_seen, a server in a private network refused unless [SourceSettings] AllowPrivateHosts=enabled or it is listed
 * in [ImapSourceSettings] AllowedHosts[]). The MIME tree of every message is walked (forwarded messages included, at
 * most expImportMailMessage::MAX_DEPTH levels) and every attachment whose extension is a document extension of ezodf
 * is decoded into the run's downloads and converted into a document record (expOdfImportDocumentSource).
 *
 * Authentication: the user and the password of the mailbox; the password option holds the NAME of a secret
 * (expImportSecrets), never the password, and it is masked in every message.
 *
 * Limits: messages larger than [ImapSourceSettings] MaxMessageMegabytes are not read (a failed row says so); an
 * attachment larger than [SourceSettings] MaxSourceMegabytes is a failed row.
 *
 * One record: one attachment with the fields of a document record and message_id, subject, from (the sender's
 * address), date (ISO 8601), attachment_name and id (message_id/attachment name); path is
 * <a short hash of the message>/<attachment name>. The sender's name is not kept.
 */
class expOdfImportMailboxDocumentsSource extends expOdfImportDocumentSource
{
    /** @var expImportImapTransport|null a transport tests give instead of a server */
    public static $transport = null;

    public static function identifier()
    {
        return 'mailbox_documents';
    }

    public static function label()
    {
        return 'Documents attached to e-mails (IMAP)';
    }

    public static function extensions()
    {
        return array();
    }

    public static function group()
    {
        return 'other';
    }

    public static function origins()
    {
        return array( 'remote' );
    }

    public static function description()
    {
        return 'The documents attached to the messages of an IMAP mailbox (a submissions address, a press inbox): every '
             . 'Word, OpenDocument, PDF, RTF, spreadsheet or presentation attachment becomes a record with its title, summary '
             . 'and body as HTML with its images, and the message\'s ID, subject, sender address and date. The mailbox is read '
             . 'without changing it unless "Mark as seen" is ticked; the password is the name of a secret in the settings.';
    }

    public static function examples()
    {
        return array();
    }

    public static function schema()
    {
        $schema = expImportImapSource::schema();
        unset( $schema['attachments'], $schema['key'] );
        $schema['key'] = array( 'type' => 'string', 'label' => 'Key field', 'default' => 'id',
                                'help' => 'The field that names a record; id is the message ID and the attachment name' );
        return $schema;
    }

    public static function preset()
    {
        $p = parent::preset();
        unset( $p['location'] );
        $p['match'] = array( 'type' => 'remote_id', 'pattern' => 'mail-{id}' );
        return $p;
    }

    /**
     * Reads the attachments into the run's snapshot.
     *
     * @throws expImportSourceException
     */
    public static function fetch( array $options, $target, $limit )
    {
        static::writeSnapshot( static::documents( $options, dirname( $target ) . '/downloads' ), $target, $limit, false );
    }

    /**
     * The document attachments, as writeSnapshot() takes them.
     *
     * @param array $options
     * @param string $dir where the attachments go
     * @return Generator
     * @throws expImportSourceException
     */
    public static function documents( array $options, $dir )
    {
        $host = isset( $options['host'] ) ? trim( (string)$options['host'] ) : '';
        $refusal = expImportSourceFiles::refuseHost( $host, (array)expImport::setting( 'ImapSourceSettings', 'AllowedHosts', array() ) );
        if ( $refusal !== null )
            throw new expImportSourceException( 'IMAP: ' . $refusal . ' (or list it in sqliimport.ini [ImapSourceSettings] AllowedHosts[])' );
        return static::documentsWith( self::$transport ?: expImportImapSource::transport(), $options, $dir );
    }

    /**
     * documents() with a given transport.
     *
     * @param expImportImapTransport $transport
     * @param array $options
     * @param string $dir
     * @return Generator
     * @throws expImportSourceException (the password masked)
     */
    public static function documentsWith( expImportImapTransport $transport, array $options, $dir )
    {
        $ref = isset( $options['password'] ) ? trim( (string)$options['password'] ) : '';
        $password = '';
        try
        {
            $password = expImportSecrets::required( $ref, 'IMAP password' );
            $markSeen = !empty( $options['mark_seen'] );
            $mailbox = isset( $options['mailbox'] ) && trim( (string)$options['mailbox'] ) !== '' ? trim( (string)$options['mailbox'] ) : 'INBOX';
            $criteria = expImportImapClient::criteria( isset( $options['search'] ) ? (string)$options['search'] : 'ALL' );
            $days = isset( $options['days'] ) ? (int)$options['days'] : 0;
            if ( $days > 0 )
                $criteria .= ' SINCE ' . date( 'j-M-Y', expImport::now() - $days * 86400 );
            $maxMessage = max( 1, (int)expImport::setting( 'ImapSourceSettings', 'MaxMessageMegabytes', 10 ) ) * 1048576;
            $maxFile = expImportSourceFiles::maxBytes();
            $exts = static::documentExtensions();
            $transport->open( isset( $options['host'] ) ? trim( (string)$options['host'] ) : '', isset( $options['port'] ) ? (int)$options['port'] : 993,
                              isset( $options['encryption'] ) ? (string)$options['encryption'] : 'ssl',
                              isset( $options['user'] ) ? (string)$options['user'] : '', $password, $mailbox, !$markSeen );
            try
            {
                foreach ( $transport->search( $criteria ) as $uid )
                {
                    $uid = (int)$uid;
                    if ( $transport->size( $uid ) > $maxMessage )
                    {
                        yield array( 'path' => 'message-' . $uid, 'error' => "IMAP: message $uid is larger than [ImapSourceSettings] MaxMessageMegabytes; its attachments are not read" );
                        continue;
                    }
                    list( $headers, $body ) = expImportMailMessage::split( $transport->message( $uid, false ) );
                    $first = function ( $name ) use ( $headers ) { return isset( $headers[$name][0] ) ? (string)$headers[$name][0] : ''; };
                    $messageId = trim( $first( 'message-id' ), " <>\t" );
                    if ( $messageId === '' )
                        $messageId = 'uid-' . $uid;
                    $from = expImportMailMessage::addresses( $first( 'from' ) );
                    $extra = array( 'message_id' => $messageId, 'subject' => expImportMailMessage::decodeHeader( $first( 'subject' ) ),
                                    'from' => $from ? (string)$from[0]['address'] : '', 'date' => expImportMailMessage::date( $first( 'date' ) ) );
                    $folder = substr( sha1( $messageId ), 0, 12 );
                    $names = array();
                    foreach ( self::attachments( $headers, $body, 0 ) as $attachment )
                    {
                        $name = expOdfRemoteFetcher::safeName( $attachment['name'] );
                        if ( !in_array( expOdfImportHttp::extensionOf( $name ), $exts, true ) )
                            continue;
                        $base = $name;
                        for ( $i = 2; isset( $names[strtolower( $name )] ); $i++ )
                            $name = pathinfo( $base, PATHINFO_FILENAME ) . "-$i." . pathinfo( $base, PATHINFO_EXTENSION );
                        $names[strtolower( $name )] = true;
                        $path = $folder . '/' . $name;
                        $row = $extra + array( 'attachment_name' => $attachment['name'], 'id' => $messageId . '/' . $name );
                        if ( strlen( $attachment['data'] ) > $maxFile )
                        {
                            yield array( 'path' => $path, 'error' => $name . ': the attachment is larger than [SourceSettings] MaxSourceMegabytes' ) + $row;
                            continue;
                        }
                        $file = expOdfImportHttp::downloadFile( $dir . '/' . $folder, $path, $name );
                        if ( file_put_contents( $file, $attachment['data'] ) === false )
                        {
                            yield array( 'path' => $path, 'error' => $name . ': the attachment cannot be written' ) + $row;
                            continue;
                        }
                        yield array( 'file' => $file, 'path' => $path ) + $row;
                    }
                    if ( $markSeen )
                        $transport->markSeen( $uid );
                }
            }
            finally
            {
                $transport->close();
            }
        }
        catch ( expImportSourceException $e )
        {
            throw new expImportSourceException( expOdfImportHttp::mask( $e->getMessage(), array( $ref ), array( $password ) ) );
        }
    }

    /**
     * The attachments of a message or a part, decoded.
     *
     * @param array $headers lower-case name => list of values (expImportMailMessage::split())
     * @param string $body
     * @param int $depth
     * @return array list of array( name, mime, data )
     */
    public static function attachments( array $headers, $body, $depth )
    {
        $first = function ( $name ) use ( $headers ) { return isset( $headers[$name][0] ) ? (string)$headers[$name][0] : ''; };
        list( $type, $params ) = expImportMailMessage::parameters( $first( 'content-type' ) ?: 'text/plain; charset=us-ascii' );
        list( $disposition, $dparams ) = expImportMailMessage::parameters( $first( 'content-disposition' ) );
        if ( $depth >= expImportMailMessage::MAX_DEPTH )
            return array();
        if ( strpos( $type, 'multipart/' ) === 0 )
        {
            if ( !isset( $params['boundary'] ) || $params['boundary'] === '' )
                return array();
            $out = array();
            foreach ( self::multipart( $body, $params['boundary'] ) as $part )
            {
                list( $h, $b ) = expImportMailMessage::split( $part );
                $out = array_merge( $out, self::attachments( $h, $b, $depth + 1 ) );
            }
            return $out;
        }
        $name = isset( $dparams['filename'] ) ? $dparams['filename'] : ( isset( $params['name'] ) ? $params['name'] : '' );
        if ( $type === 'message/rfc822' && $name === '' )
        {
            // a forwarded message: its attachments count
            list( $h, $b ) = expImportMailMessage::split( expImportMailMessage::decodeBody( $body, $first( 'content-transfer-encoding' ) ) );
            return self::attachments( $h, $b, $depth + 1 );
        }
        if ( $name === '' )
            return array();
        return array( array( 'name' => basename( str_replace( '\\', '/', $name ) ), 'mime' => $type,
                             'data' => expImportMailMessage::decodeBody( $body, $first( 'content-transfer-encoding' ) ) ) );
    }

    /** @return string[] the parts of a multipart body (without preamble and epilogue) */
    protected static function multipart( $body, $boundary )
    {
        $out = array();
        $current = null;
        $open = '--' . $boundary;
        $close = '--' . $boundary . '--';
        foreach ( preg_split( '/\r?\n/', (string)$body ) as $line )
        {
            $bare = rtrim( $line, " \t" );
            if ( $bare === $close )
                break;
            if ( $bare === $open )
            {
                if ( $current !== null )
                    $out[] = implode( "\n", $current );
                $current = array();
                continue;
            }
            if ( $current !== null )
                $current[] = $line;
        }
        if ( $current !== null )
            $out[] = implode( "\n", $current );
        return array_values( array_filter( $out, function ( $p ) { return trim( $p ) !== ''; } ) );
    }
}
