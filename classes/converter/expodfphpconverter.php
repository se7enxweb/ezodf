<?php
/**
 * File containing the expOdfPhpConverter class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The last fallback of the converter: PHP only, no LibreOffice. It turns a document into an ODT text that ezodf's
 * proven ODT parser (eZOOImport) imports, so every way into content is the same one:
 *
 *   odt, ott           the package as it is (a template's media type set to text)
 *   fodt               the flat XML document packed as content.xml
 *   sxw, stw           OpenOffice.org 1.x Writer: the same element names under the 1.x namespaces, rewritten to
 *                      OpenDocument (body wrapped in office:text, headings' text:level to text:outline-level,
 *                      ordered and unordered lists to text:list, style:properties to text and paragraph properties,
 *                      image references without their leading #)
 *   and the formats of the readers in expOdfReaders (DOCX, HTML, Markdown, text, spreadsheets, presentations, PDF
 *   text), which go through HTML and expOdfHtmlToOdt
 *
 * Export is ODT only here (the object's own writer, eZOOConverter); DOCX, PDF and the rest need LibreOffice.
 */
class expOdfPhpConverter
{
    const NS = array(
        'http://openoffice.org/2000/office' => 'urn:oasis:names:tc:opendocument:xmlns:office:1.0',
        'http://openoffice.org/2000/style' => 'urn:oasis:names:tc:opendocument:xmlns:style:1.0',
        'http://openoffice.org/2000/text' => 'urn:oasis:names:tc:opendocument:xmlns:text:1.0',
        'http://openoffice.org/2000/table' => 'urn:oasis:names:tc:opendocument:xmlns:table:1.0',
        'http://openoffice.org/2000/drawing' => 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0',
        'http://openoffice.org/2000/datastyle' => 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0',
        'http://openoffice.org/2000/meta' => 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0',
        'http://openoffice.org/2000/chart' => 'urn:oasis:names:tc:opendocument:xmlns:chart:1.0',
        'http://openoffice.org/2000/form' => 'urn:oasis:names:tc:opendocument:xmlns:form:1.0',
        'http://openoffice.org/2000/presentation' => 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0',
        'http://openoffice.org/2000/script' => 'urn:oasis:names:tc:opendocument:xmlns:script:1.0',
        'http://openoffice.org/2000/number' => 'urn:oasis:names:tc:opendocument:xmlns:datastyle:1.0',
        'http://www.w3.org/1999/XSL/Format' => 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0',
        'http://www.w3.org/2000/svg' => 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0',
    );

    /** @return string[] the extensions it reads (to ODT) */
    public static function extensions()
    {
        $own = array( 'odt', 'ott', 'fodt', 'sxw', 'stw' );
        return array_values( array_unique( array_merge( $own, class_exists( 'expOdfReaders' ) ? expOdfReaders::extensions() : array() ) ) );
    }

    /** @return bool */
    public static function can( $ext, $target )
    {
        return $target === 'odt' && in_array( $ext, self::extensions(), true );
    }

    /**
     * @return string the ODT file it wrote into $dir
     * @throws expOdfConverterException
     */
    public static function convert( $file, $ext, $target, $dir )
    {
        if ( !self::can( $ext, $target ) )
            throw new expOdfConverterException( 'unsupported', "the PHP converter cannot turn .$ext into .$target" );
        $out = $dir . '/out.odt';
        switch ( $ext )
        {
            case 'odt':
                copy( $file, $out );
                return $out;
            case 'ott':
                self::repack( $file, $out, function ( $name, $data ) { return $name === 'mimetype' ? 'application/vnd.oasis.opendocument.text' : $data; } );
                return $out;
            case 'fodt':
                $xml = file_get_contents( $file );
                self::assertXml( $xml );
                self::writeOdt( $out, $xml, array() );
                return $out;
            case 'sxw':
            case 'stw':
                self::fromOpenOffice1( $file, $out );
                return $out;
        }
        $html = expOdfReaders::toHtml( $file, $ext, $dir );
        $family = expOdfConverter::family( $ext );
        expOdfHtmlToOdt::write( $html['html'], $html['images'], $out, in_array( $family, array( 'spreadsheet', 'presentation', 'drawing' ), true ) ? '' : $html['title'] );
        return $out;
    }

    /** OpenOffice.org 1.x Writer to OpenDocument text. */
    public static function fromOpenOffice1( $file, $out )
    {
        $zip = new ZipArchive();
        if ( $zip->open( $file ) !== true )
            throw new expOdfConverterException( 'convert', 'the OpenOffice.org 1.x file is not a ZIP package' );
        if ( eZOOImport::zipEntriesProblem( $zip ) )
            throw new expOdfConverterException( 'convert', 'the OpenOffice.org 1.x package has entries that cannot be read safely' );
        $content = $zip->getFromName( 'content.xml' );
        if ( $content === false || strlen( $content ) > 67108864 )
        {
            $zip->close();
            throw new expOdfConverterException( 'convert', 'the OpenOffice.org 1.x package has no content.xml' );
        }
        $pictures = array();
        $total = 0;
        for ( $i = 0; $i < $zip->numFiles; $i++ )
        {
            $name = $zip->getNameIndex( $i );
            if ( strpos( $name, 'Pictures/' ) === 0 && substr( $name, -1 ) !== '/' && strpos( $name, '..' ) === false )
            {
                $data = $zip->getFromIndex( $i );
                $total += strlen( (string)$data );
                if ( $total > 268435456 )
                    break;
                $pictures[$name] = $data;
            }
        }
        $zip->close();
        $xml = self::rewriteOpenOffice1( $content );
        self::writeOdt( $out, $xml, $pictures );
    }

    /** @return string content.xml in OpenDocument namespaces */
    public static function rewriteOpenOffice1( $xml )
    {
        // the 1.x DTD reference is not loaded (no network, no entity expansion)
        $xml = preg_replace( '/<!DOCTYPE[^>]*>/i', '', (string)$xml, 1 );
        $src = new DOMDocument();
        if ( !@$src->loadXML( $xml, LIBXML_NONET | LIBXML_PARSEHUGE ) )
            throw new expOdfConverterException( 'convert', 'the content of the OpenOffice.org 1.x document is not valid XML' );
        $office = self::NS['http://openoffice.org/2000/office'];
        $dst = new DOMDocument( '1.0', 'UTF-8' );
        $root = $dst->createElementNS( $office, 'office:document-content' );
        $root->setAttributeNS( $office, 'office:version', '1.2' );
        $dst->appendChild( $root );
        foreach ( $src->documentElement->childNodes as $child )
        {
            if ( !$child instanceof DOMElement )
                continue;
            $copy = self::copyNode( $dst, $child );
            if ( $child->localName === 'body' )
            {
                // OpenDocument wraps the text in office:text
                $text = $dst->createElementNS( $office, 'office:text' );
                while ( $copy->firstChild )
                    $text->appendChild( $copy->firstChild );
                $copy->appendChild( $text );
            }
            $root->appendChild( $copy );
        }
        return $dst->saveXML();
    }

    protected static function mapNs( $uri )
    {
        return isset( self::NS[$uri] ) ? self::NS[$uri] : $uri;
    }

    protected static function copyNode( DOMDocument $dst, DOMNode $node )
    {
        if ( $node instanceof DOMText )
            return $dst->createTextNode( $node->data );
        if ( !$node instanceof DOMElement )
            return $dst->createTextNode( '' );
        $ns = self::mapNs( $node->namespaceURI );
        $local = $node->localName;
        $prefix = $node->prefix;
        $textNs = self::NS['http://openoffice.org/2000/text'];
        $styleNs = self::NS['http://openoffice.org/2000/style'];
        if ( $ns === $textNs && ( $local === 'ordered-list' || $local === 'unordered-list' ) )
            $local = 'list';
        $el = $ns ? $dst->createElementNS( $ns, ( $prefix ? $prefix . ':' : '' ) . $local ) : $dst->createElement( $local );
        foreach ( $node->attributes as $attr )
        {
            $ans = self::mapNs( $attr->namespaceURI );
            $aname = $attr->localName;
            $value = $attr->value;
            if ( $ans === $textNs && $aname === 'level' && $ns === $textNs && $local === 'h' )
                $aname = 'outline-level';
            if ( $attr->namespaceURI === 'http://www.w3.org/1999/xlink' && $aname === 'href' && strpos( $value, '#Pictures/' ) === 0 )
                $value = substr( $value, 1 );
            if ( $ans )
                $el->setAttributeNS( $ans, ( $attr->prefix ? $attr->prefix . ':' : '' ) . $aname, $value );
            else
                $el->setAttribute( $aname, $value );
        }
        if ( $ns === $styleNs && $local === 'properties' )
        {
            // 1.x keeps every property on one element; the ODT parser reads the text properties (bold, italic)
            $text = $dst->createElementNS( $styleNs, 'style:text-properties' );
            $para = $dst->createElementNS( $styleNs, 'style:paragraph-properties' );
            foreach ( $el->attributes as $attr )
            {
                $text->setAttributeNS( $attr->namespaceURI, $attr->nodeName, $attr->value );
                $para->setAttributeNS( $attr->namespaceURI, $attr->nodeName, $attr->value );
            }
            $frag = $dst->createDocumentFragment();
            $frag->appendChild( $para );
            $frag->appendChild( $text );
            return $frag;
        }
        foreach ( $node->childNodes as $child )
            $el->appendChild( self::copyNode( $dst, $child ) );
        return $el;
    }

    /** Writes an ODT package with content.xml and pictures. */
    public static function writeOdt( $out, $contentXml, array $pictures )
    {
        $zip = new ZipArchive();
        if ( $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true )
            throw new expOdfConverterException( 'convert', 'the ODT file cannot be written' );
        $zip->addFromString( 'mimetype', 'application/vnd.oasis.opendocument.text' );
        if ( method_exists( $zip, 'setCompressionName' ) )
            $zip->setCompressionName( 'mimetype', ZipArchive::CM_STORE );
        $zip->addFromString( 'content.xml', $contentXml );
        $manifest = '<?xml version="1.0" encoding="UTF-8"?><manifest:manifest xmlns:manifest="urn:oasis:names:tc:opendocument:xmlns:manifest:1.0" manifest:version="1.2">'
                  . '<manifest:file-entry manifest:full-path="/" manifest:media-type="application/vnd.oasis.opendocument.text"/>'
                  . '<manifest:file-entry manifest:full-path="content.xml" manifest:media-type="text/xml"/>';
        foreach ( $pictures as $name => $data )
        {
            $zip->addFromString( $name, $data );
            $manifest .= '<manifest:file-entry manifest:full-path="' . htmlspecialchars( $name, ENT_XML1 ) . '" manifest:media-type=""/>';
        }
        $zip->addFromString( 'META-INF/manifest.xml', $manifest . '</manifest:manifest>' );
        $zip->close();
    }

    /** Copies a ZIP package entry by entry through $filter( name, data ). */
    protected static function repack( $file, $out, $filter )
    {
        $in = new ZipArchive();
        if ( $in->open( $file ) !== true || eZOOImport::zipEntriesProblem( $in ) )
            throw new expOdfConverterException( 'convert', 'the document is not a ZIP package that can be read safely' );
        $zip = new ZipArchive();
        $zip->open( $out, ZipArchive::CREATE | ZipArchive::OVERWRITE );
        for ( $i = 0; $i < $in->numFiles; $i++ )
        {
            $name = $in->getNameIndex( $i );
            if ( substr( $name, -1 ) === '/' )
                continue;
            $zip->addFromString( $name, $filter( $name, $in->getFromIndex( $i ) ) );
        }
        $in->close();
        $zip->close();
    }

    protected static function assertXml( $xml )
    {
        $dom = new DOMDocument();
        if ( !@$dom->loadXML( (string)$xml, LIBXML_NONET | LIBXML_PARSEHUGE ) )
            throw new expOdfConverterException( 'convert', 'the flat OpenDocument file is not valid XML' );
    }
}
