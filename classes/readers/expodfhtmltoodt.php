<?php
/**
 * File containing the expOdfHtmlToOdt class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Writes the HTML of expOdfReaders as an ODT text that ezodf's ODT parser (eZOOImport) imports:
 *
 *   expOdfHtmlToOdt::write( $result['html'], $result['images'], $dir . '/out.odt', $result['title'] );
 *
 * The package: "mimetype" first and stored, content.xml, styles.xml, meta.xml, META-INF/manifest.xml and
 * Pictures/<name> for every image the text shows. The HTML goes through expOdfReaders::sanitize() first.
 *
 * What eZOOImport reads, and so what is written:
 *   - office:body/office:text, every child through handleNode(); no white space between the elements (a text
 *     node there is reported as an unsupported node) and exactly one office:automatic-styles.
 *   - text:h with text:outline-level becomes <header> in nested <section>s. The object name is NOT taken from a
 *     heading: eZOOImport names the object after the uploaded file (basename without extension, "_" as space,
 *     first letter upper case). A title passed here becomes the first level 1 heading of the body when the HTML
 *     does not start with an h1.
 *   - text:p becomes <paragraph>; its automatic style's fo:font-weight="bold" / fo:font-style="italic" makes the
 *     whole paragraph strong / emphasized (used for header cells outside the table's header rows).
 *   - text:span: only its automatic style (bold, italic) and the style name prefix "eZCustominline_20_<name>"
 *     (a custom inline tag: strike, sub and sup are defined in content.ini) count, and only the span's text:
 *     spans are never nested. Underline and code keep their look in the ODT but are plain text in Exponential.
 *   - text:a gives <link href> with its text only; text:line-break turns its neighbours into <line>s.
 *   - text:list / text:list-item: ordered or not by the first child of the list's text:list-style
 *     (list-level-style-number or -bullet). Every child of a list item becomes an item of its own, so an item
 *     is written as one text:p (always, even when empty, so a nested list never lands outside an item) plus
 *     the nested lists.
 *   - table:table with table:table-header-rows (<th>) and table:table-row, cells' number-columns-spanned as
 *     colspan; the cell's children go through handleNode() again. Row spans are not read (covered cells are
 *     written for the ODT itself).
 *   - draw:frame/draw:image xlink:href="Pictures/..." inside a paragraph becomes an embedded image object (its
 *     draw:name the object's name, svg:width in inches its size, the graphic style's horizontal-pos its
 *     alignment).
 *   - Paragraphs styled "Preformatted_20_Text" are collected into one <literal>, those styled
 *     "eZCustom_20_quote" into <custom name='quote'>. The collection ends only at a node that is not a text:p
 *     (one that is would have the collected text escaped twice), so an empty text:sequence-decls, which the
 *     parser skips, follows every such group.
 *   - text:section is matched against the class attributes of odf.ini; none is written.
 */
class expOdfHtmlToOdt
{
    const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    const NS_STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    const NS_DRAW = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    const NS_SVG = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';
    const NS_FO = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
    const NS_XLINK = 'http://www.w3.org/1999/xlink';
    const NS_META = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';
    const NS_DC = 'http://purl.org/dc/elements/1.1/';
    const NS_MANIFEST = 'urn:oasis:names:tc:opendocument:xmlns:manifest:1.0';
    const NS_XMLNS = 'http://www.w3.org/2000/xmlns/';

    const MIME = 'application/vnd.oasis.opendocument.text';
    const MAX_IMAGE = 67108864;
    const PAGE_INCHES = 6.0;

    const F_BOLD = 1;
    const F_ITALIC = 2;
    const F_UNDERLINE = 4;
    const F_STRIKE = 8;
    const F_SUP = 16;
    const F_SUB = 32;
    const F_CODE = 64;

    /** @var DOMDocument */
    protected $doc;
    /** @var DOMElement office:automatic-styles */
    protected $auto;
    /** @var array key => path of the images the HTML may show */
    protected $images;
    /** @var array picture name => path */
    protected $pictures = array();
    /** @var array picture name => media type */
    protected $pictureTypes = array();
    /** @var array image key => picture name */
    protected $pictureByKey = array();
    /** @var array automatic style names already written */
    protected $styles = array();
    /** @var int */
    protected $tables = 0;
    /** @var int */
    protected $frames = 0;

    /**
     * @param string $html the HTML subset of expOdfReaders
     * @param array $images key => path of the images <img src> refers to
     * @param string $outFile the ODT written (replaced when it exists)
     * @param string $title written as the first level 1 heading when the HTML does not start with an h1
     * @return string $outFile
     * @throws expOdfConverterException
     */
    public static function write( $html, array $images, $outFile, $title = '' )
    {
        if ( !class_exists( 'ZipArchive' ) )
            throw new expOdfConverterException( 'unsupported', 'the PHP zip extension is needed to write ODT files' );
        $parts = self::build( $html, $images, $title );
        $dir = dirname( $outFile );
        if ( !is_dir( $dir ) && !@mkdir( $dir, 0700, true ) && !is_dir( $dir ) )
            throw new expOdfConverterException( 'convert', 'the directory of the ODT file cannot be created' );
        if ( is_link( $outFile ) )
            throw new expOdfConverterException( 'convert', 'the ODT file is a link' );
        $zip = new ZipArchive();
        if ( $zip->open( $outFile, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true )
            throw new expOdfConverterException( 'convert', 'the ODT file cannot be written' );
        $zip->addFromString( 'mimetype', self::MIME );
        $zip->setCompressionName( 'mimetype', ZipArchive::CM_STORE );
        $zip->addFromString( 'content.xml', $parts['content'] );
        $zip->addFromString( 'styles.xml', $parts['styles'] );
        $zip->addFromString( 'meta.xml', $parts['meta'] );
        $zip->addFromString( 'META-INF/manifest.xml', $parts['manifest'] );
        foreach ( $parts['pictures'] as $name => $bytes )
            $zip->addFromString( 'Pictures/' . $name, $bytes );
        if ( !$zip->close() )
            throw new expOdfConverterException( 'convert', 'the ODT file cannot be written' );
        return $outFile;
    }

    /**
     * The parts of the package, without writing it.
     *
     * @return array( 'content', 'styles', 'meta', 'manifest' => XML strings, 'pictures' => name => bytes )
     */
    public static function build( $html, array $images, $title = '' )
    {
        $writer = new self( $images );
        return $writer->run( (string)$html, trim( preg_replace( '/\s+/u', ' ', (string)$title ) ) );
    }

    protected function __construct( array $images )
    {
        $this->images = $images;
    }

    protected function run( $html, $title )
    {
        $body = expOdfReaders::loadHtmlFragment( expOdfReaders::sanitize( $html, array_keys( $this->images ) ) );

        $this->doc = new DOMDocument( '1.0', 'UTF-8' );
        $root = $this->doc->createElementNS( self::NS_OFFICE, 'office:document-content' );
        $this->doc->appendChild( $root );
        foreach ( array( 'style' => self::NS_STYLE, 'text' => self::NS_TEXT, 'table' => self::NS_TABLE, 'draw' => self::NS_DRAW,
                         'fo' => self::NS_FO, 'xlink' => self::NS_XLINK, 'svg' => self::NS_SVG, 'dc' => self::NS_DC,
                         'meta' => self::NS_META ) as $prefix => $ns )
            $root->setAttributeNS( self::NS_XMLNS, 'xmlns:' . $prefix, $ns );
        $root->setAttributeNS( self::NS_OFFICE, 'office:version', '1.3' );

        $fonts = $this->el( self::NS_OFFICE, 'office:font-face-decls' );
        foreach ( array( 'Liberation Serif' => 'roman', 'Liberation Sans' => 'swiss', 'Liberation Mono' => 'modern' ) as $font => $family )
        {
            $face = $this->el( self::NS_STYLE, 'style:font-face', array( 'style:name' => $font, 'svg:font-family' => "'$font'",
                                                                          'style:font-family-generic' => $family ) );
            if ( $family === 'modern' )
                $face->setAttributeNS( self::NS_STYLE, 'style:font-pitch', 'fixed' );
            $fonts->appendChild( $face );
        }
        $root->appendChild( $fonts );
        $this->auto = $this->el( self::NS_OFFICE, 'office:automatic-styles' );
        $root->appendChild( $this->auto );
        $officeBody = $this->el( self::NS_OFFICE, 'office:body' );
        $root->appendChild( $officeBody );
        $text = $this->el( self::NS_OFFICE, 'office:text' );
        $officeBody->appendChild( $text );

        $first = null;
        foreach ( $body->childNodes as $child )
        {
            if ( $child instanceof DOMElement )
            {
                $first = $child;
                break;
            }
        }
        if ( $title !== '' && ( !$first || strtolower( $first->nodeName ) !== 'h1' ) )
        {
            $heading = $this->el( self::NS_TEXT, 'text:h', array( 'text:style-name' => 'Heading_20_1', 'text:outline-level' => '1' ) );
            $heading->appendChild( $this->doc->createTextNode( $title ) );
            $text->appendChild( $heading );
        }

        $this->blocks( $body, $text, 'body' );

        return array( 'content' => $this->doc->saveXML(), 'styles' => self::stylesXml(), 'meta' => self::metaXml( $title ),
                      'manifest' => $this->manifestXml(), 'pictures' => $this->pictureBytes() );
    }

    /** @return DOMElement a new element with attributes "prefix:name" => value */
    protected function el( $ns, $name, array $attributes = array() )
    {
        $element = $this->doc->createElementNS( $ns, $name );
        foreach ( $attributes as $attribute => $value )
        {
            $prefix = strstr( $attribute, ':', true );
            $map = array( 'office' => self::NS_OFFICE, 'text' => self::NS_TEXT, 'style' => self::NS_STYLE, 'table' => self::NS_TABLE,
                          'draw' => self::NS_DRAW, 'svg' => self::NS_SVG, 'fo' => self::NS_FO, 'xlink' => self::NS_XLINK );
            $element->setAttributeNS( $map[$prefix], $attribute, (string)$value );
        }
        return $element;
    }

    /** @return bool whether an HTML node is inline content */
    protected static function isInline( DOMNode $node )
    {
        if ( $node instanceof DOMText )
            return true;
        if ( !$node instanceof DOMElement )
            return false;
        return in_array( strtolower( $node->nodeName ), array( 'strong', 'em', 'u', 's', 'sub', 'sup', 'a', 'code', 'br', 'img' ), true );
    }

    /**
     * @param DOMNode $from an HTML container (body, td, th, li ...)
     * @param DOMElement $to office:text or table:table-cell
     * @param string $context body, cell (a td), head (a th outside the header rows) or header (a header row cell)
     */
    protected function blocks( DOMNode $from, DOMElement $to, $context )
    {
        $run = array();
        foreach ( $from->childNodes as $node )
        {
            if ( self::isInline( $node ) )
            {
                $run[] = $node;
                continue;
            }
            $this->flushRun( $run, $to, $context );
            if ( $node instanceof DOMElement )
                $this->block( $node, $to, $context );
        }
        $this->flushRun( $run, $to, $context );
    }

    protected function flushRun( array &$run, DOMElement $to, $context )
    {
        if ( !$run )
            return;
        $p = $this->paragraph( $context );
        foreach ( $run as $node )
            $this->inlineNode( $node, $p, 0, 'text' );
        $run = array();
        if ( trim( $p->textContent ) !== '' || $p->getElementsByTagNameNS( self::NS_DRAW, 'frame' )->length )
            $to->appendChild( $p );
    }

    /** @return DOMElement a text:p styled for its context */
    protected function paragraph( $context )
    {
        switch ( $context )
        {
            case 'cell':
                $style = 'Table_20_Contents';
                break;
            case 'header':
                $style = 'Table_20_Heading';
                break;
            case 'head':
                $style = $this->paragraphStyle( 'P_th', 'Table_20_Heading', self::F_BOLD );
                break;
            default:
                $style = 'Text_20_body';
        }
        return $this->el( self::NS_TEXT, 'text:p', array( 'text:style-name' => $style ) );
    }

    protected function block( DOMElement $node, DOMElement $to, $context )
    {
        $name = strtolower( $node->nodeName );
        switch ( $name )
        {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
                $level = (int)$name[1];
                if ( $context === 'body' )
                {
                    $element = $this->el( self::NS_TEXT, 'text:h', array( 'text:style-name' => 'Heading_20_' . $level, 'text:outline-level' => $level ) );
                    $this->inline( $node, $element, 0, 'heading' );
                }
                else
                {
                    // a heading in a cell would end the cell's section: a bold paragraph instead
                    $element = $this->el( self::NS_TEXT, 'text:p', array( 'text:style-name' => $this->paragraphStyle( 'P_bold', 'Table_20_Contents', self::F_BOLD ) ) );
                    $this->inline( $node, $element, 0, 'heading' );
                }
                $to->appendChild( $element );
                break;
            case 'p':
                $p = $this->paragraph( $context );
                $this->inline( $node, $p, 0, 'text' );
                $to->appendChild( $p );
                break;
            case 'ul':
            case 'ol':
                $list = $this->listElement( $node );
                if ( $list )
                    $to->appendChild( $list );
                break;
            case 'table':
                $this->table( $node, $to );
                break;
            case 'blockquote':
                $this->quote( $node, $to, $context );
                break;
            case 'pre':
                $this->pre( $node, $to );
                break;
            case 'hr':
                $to->appendChild( $this->el( self::NS_TEXT, 'text:p', array( 'text:style-name' => 'Horizontal_20_Line' ) ) );
                break;
            default:
                $this->blocks( $node, $to, $context );
        }
    }

    /** Ends a group of collected paragraphs (literal, quote) for eZOOImport, see the class description. */
    protected function separator( DOMElement $to )
    {
        $to->appendChild( $this->el( self::NS_TEXT, 'text:sequence-decls' ) );
    }

    protected function quote( DOMElement $quote, DOMElement $to, $context )
    {
        $open = false;
        foreach ( $quote->childNodes as $child )
        {
            if ( !$child instanceof DOMElement )
                continue;
            $name = strtolower( $child->nodeName );
            if ( $name === 'p' )
            {
                $p = $this->el( self::NS_TEXT, 'text:p', array( 'text:style-name' => 'eZCustom_20_quote' ) );
                $this->inline( $child, $p, 0, 'text' );
                $to->appendChild( $p );
                $open = true;
                continue;
            }
            if ( $open )
                $this->separator( $to );
            $open = false;
            if ( $name === 'blockquote' )
                $this->quote( $child, $to, $context );
            else
                $this->block( $child, $to, $context );
        }
        if ( $open )
            $this->separator( $to );
    }

    protected function pre( DOMElement $pre, DOMElement $to )
    {
        $text = rtrim( str_replace( "\r\n", "\n", $pre->textContent ), "\n" );
        foreach ( explode( "\n", $text ) as $line )
        {
            $p = $this->el( self::NS_TEXT, 'text:p', array( 'text:style-name' => 'Preformatted_20_Text' ) );
            $this->preLine( $p, self::expandTabs( $line ) );
            $to->appendChild( $p );
        }
        $this->separator( $to );
    }

    /** Appends a line of preformatted text, its spaces as text:s so that neither ODF nor eZOOImport drops them. */
    protected function preLine( DOMElement $p, $line )
    {
        preg_match_all( '/ +|[^ ]+/', $line, $m );
        $position = 0;
        foreach ( $m[0] as $piece )
        {
            if ( $piece[0] !== ' ' )
                $p->appendChild( $this->doc->createTextNode( $piece ) );
            else
            {
                $count = strlen( $piece );
                if ( $position > 0 )
                {
                    $p->appendChild( $this->doc->createTextNode( ' ' ) );
                    $count--;
                }
                for ( $i = 0; $i < $count; $i++ )
                    $p->appendChild( $this->el( self::NS_TEXT, 'text:s' ) );
            }
            $position += strlen( $piece );
        }
    }

    /** @return string the line with its tabs expanded to the next multiple of four columns */
    protected static function expandTabs( $line )
    {
        $out = '';
        foreach ( preg_split( '//u', $line, -1, PREG_SPLIT_NO_EMPTY ) as $char )
            $out .= $char === "\t" ? str_repeat( ' ', 4 - mb_strlen( $out ) % 4 ) : $char;
        return $out;
    }

    /** @return DOMElement|null the text:list of an HTML list */
    protected function listElement( DOMElement $list, $depth = 0 )
    {
        if ( $depth > 20 )
            return null;
        $tag = strtolower( $list->nodeName );
        $element = $this->el( self::NS_TEXT, 'text:list', array( 'text:style-name' => $this->listStyle( $tag ) ) );
        foreach ( $list->childNodes as $li )
        {
            if ( !$li instanceof DOMElement || strtolower( $li->nodeName ) !== 'li' )
                continue;
            $item = $this->el( self::NS_TEXT, 'text:list-item' );
            $p = $this->el( self::NS_TEXT, 'text:p', array( 'text:style-name' => 'Standard' ) );
            $item->appendChild( $p );
            $nested = array();
            $needBreak = false;
            $this->itemContent( $li, $p, $nested, $needBreak );
            self::trimParagraph( $p );
            foreach ( $nested as $sub )
            {
                $subList = $this->listElement( $sub, $depth + 1 );
                if ( $subList )
                    $item->appendChild( $subList );
            }
            $element->appendChild( $item );
        }
        return $element->hasChildNodes() ? $element : null;
    }

    /** Removes the white space at the start and the end of a paragraph (the HTML's line breaks between blocks). */
    protected static function trimParagraph( DOMElement $p )
    {
        while ( $p->firstChild instanceof DOMText && ltrim( $p->firstChild->data ) === '' )
            $p->removeChild( $p->firstChild );
        if ( $p->firstChild instanceof DOMText )
            $p->firstChild->data = ltrim( $p->firstChild->data );
        while ( $p->lastChild instanceof DOMText && rtrim( $p->lastChild->data ) === '' )
            $p->removeChild( $p->lastChild );
        if ( $p->lastChild instanceof DOMText )
            $p->lastChild->data = rtrim( $p->lastChild->data );
    }

    /** Puts the content of a list item into its one paragraph; nested lists are collected in $nested. */
    protected function itemContent( DOMNode $node, DOMElement $p, array &$nested, &$needBreak )
    {
        foreach ( $node->childNodes as $child )
        {
            if ( self::isInline( $child ) )
            {
                if ( $needBreak && ( !$child instanceof DOMText || trim( $child->data ) !== '' ) )
                {
                    $p->appendChild( $this->el( self::NS_TEXT, 'text:line-break' ) );
                    $needBreak = false;
                }
                $this->inlineNode( $child, $p, 0, 'text' );
                continue;
            }
            if ( !$child instanceof DOMElement )
                continue;
            $name = strtolower( $child->nodeName );
            if ( $name === 'ul' || $name === 'ol' )
            {
                $nested[] = $child;
                continue;
            }
            if ( $name === 'pre' )
            {
                foreach ( explode( "\n", rtrim( $child->textContent, "\n" ) ) as $line )
                {
                    if ( $p->hasChildNodes() )
                        $p->appendChild( $this->el( self::NS_TEXT, 'text:line-break' ) );
                    $p->appendChild( $this->doc->createTextNode( $line ) );
                }
                $needBreak = true;
                continue;
            }
            if ( $name === 'hr' )
                continue;
            if ( in_array( $name, array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'td', 'th' ), true ) && $p->hasChildNodes() )
                $needBreak = true;
            $this->itemContent( $child, $p, $nested, $needBreak );
            if ( in_array( $name, array( 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'tr' ), true ) )
                $needBreak = true;
        }
    }

    /** @return string the automatic list style of ul or ol (all levels of one kind) */
    protected function listStyle( $tag )
    {
        $name = $tag === 'ol' ? 'L_ol' : 'L_ul';
        if ( isset( $this->styles[$name] ) )
            return $name;
        $this->styles[$name] = true;
        $style = $this->el( self::NS_TEXT, 'text:list-style', array( 'style:name' => $name ) );
        for ( $level = 1; $level <= 10; $level++ )
        {
            if ( $tag === 'ol' )
                $levelStyle = $this->el( self::NS_TEXT, 'text:list-level-style-number', array( 'text:level' => $level, 'style:num-suffix' => '.', 'style:num-format' => '1' ) );
            else
                $levelStyle = $this->el( self::NS_TEXT, 'text:list-level-style-bullet', array( 'text:level' => $level, 'text:bullet-char' => $level % 2 ? "\u{2022}" : "\u{25E6}" ) );
            $properties = $this->el( self::NS_STYLE, 'style:list-level-properties', array( 'text:list-level-position-and-space-mode' => 'label-alignment' ) );
            $properties->appendChild( $this->el( self::NS_STYLE, 'style:list-level-label-alignment',
                                                 array( 'text:label-followed-by' => 'listtab', 'fo:text-indent' => '-0.25in',
                                                        'fo:margin-left' => sprintf( '%.2fin', 0.25 * ( $level + 1 ) ) ) ) );
            $levelStyle->appendChild( $properties );
            $style->appendChild( $levelStyle );
        }
        $this->auto->appendChild( $style );
        return $name;
    }

    /** @return string an automatic paragraph style with text formatting flags */
    protected function paragraphStyle( $name, $parent, $flags )
    {
        if ( !isset( $this->styles[$name] ) )
        {
            $this->styles[$name] = true;
            $style = $this->el( self::NS_STYLE, 'style:style', array( 'style:name' => $name, 'style:family' => 'paragraph', 'style:parent-style-name' => $parent ) );
            $style->appendChild( $this->textProperties( $flags ) );
            $this->auto->appendChild( $style );
        }
        return $name;
    }

    /** @return string the automatic text style of a set of formatting flags */
    protected function spanStyle( $flags )
    {
        $custom = null;
        if ( !( $flags & ( self::F_BOLD | self::F_ITALIC ) ) )
        {
            if ( $flags & self::F_SUP )
                $custom = 'sup';
            elseif ( $flags & self::F_SUB )
                $custom = 'sub';
            elseif ( $flags & self::F_STRIKE )
                $custom = 'strike';
        }
        $name = $custom !== null ? 'eZCustominline_20_' . $custom : 'T' . $flags;
        if ( !isset( $this->styles[$name] ) )
        {
            $this->styles[$name] = true;
            $style = $this->el( self::NS_STYLE, 'style:style', array( 'style:name' => $name, 'style:family' => 'text' ) );
            $style->appendChild( $this->textProperties( $flags ) );
            $this->auto->appendChild( $style );
        }
        return $name;
    }

    /** @return DOMElement style:text-properties for formatting flags (one element: eZOOImport reads the last child) */
    protected function textProperties( $flags )
    {
        $properties = $this->el( self::NS_STYLE, 'style:text-properties' );
        if ( $flags & self::F_BOLD )
        {
            foreach ( array( 'fo:font-weight', 'style:font-weight-asian', 'style:font-weight-complex' ) as $attribute )
                $properties->setAttributeNS( $attribute[0] === 'f' ? self::NS_FO : self::NS_STYLE, $attribute, 'bold' );
        }
        if ( $flags & self::F_ITALIC )
        {
            foreach ( array( 'fo:font-style', 'style:font-style-asian', 'style:font-style-complex' ) as $attribute )
                $properties->setAttributeNS( $attribute[0] === 'f' ? self::NS_FO : self::NS_STYLE, $attribute, 'italic' );
        }
        if ( $flags & self::F_UNDERLINE )
        {
            $properties->setAttributeNS( self::NS_STYLE, 'style:text-underline-style', 'solid' );
            $properties->setAttributeNS( self::NS_STYLE, 'style:text-underline-width', 'auto' );
            $properties->setAttributeNS( self::NS_STYLE, 'style:text-underline-color', 'font-color' );
        }
        if ( $flags & self::F_STRIKE )
            $properties->setAttributeNS( self::NS_STYLE, 'style:text-line-through-style', 'solid' );
        if ( $flags & self::F_SUP )
            $properties->setAttributeNS( self::NS_STYLE, 'style:text-position', 'super 58%' );
        elseif ( $flags & self::F_SUB )
            $properties->setAttributeNS( self::NS_STYLE, 'style:text-position', 'sub 58%' );
        if ( $flags & self::F_CODE )
            $properties->setAttributeNS( self::NS_STYLE, 'style:font-name', 'Liberation Mono' );
        return $properties;
    }

    /**
     * @param DOMNode $node HTML whose children are inline
     * @param DOMElement $to text:p, text:h or text:a
     * @param int $flags
     * @param string $mode text, heading (no images) or link (no images, no nested links)
     */
    protected function inline( DOMNode $node, DOMElement $to, $flags, $mode )
    {
        foreach ( $node->childNodes as $child )
            $this->inlineNode( $child, $to, $flags, $mode );
    }

    protected function inlineNode( DOMNode $node, DOMElement $to, $flags, $mode )
    {
        if ( $node instanceof DOMText )
        {
            $this->text( $to, preg_replace( '/[ \t\n\r\f]+/', ' ', $node->data ), $flags );
            return;
        }
        if ( !$node instanceof DOMElement )
            return;
        $map = array( 'strong' => self::F_BOLD, 'em' => self::F_ITALIC, 'u' => self::F_UNDERLINE, 's' => self::F_STRIKE,
                      'sup' => self::F_SUP, 'sub' => self::F_SUB, 'code' => self::F_CODE );
        $name = strtolower( $node->nodeName );
        if ( isset( $map[$name] ) )
        {
            $this->inline( $node, $to, $flags | $map[$name], $mode );
            return;
        }
        switch ( $name )
        {
            case 'br':
                $to->appendChild( $this->el( self::NS_TEXT, 'text:line-break' ) );
                return;
            case 'a':
                $href = expOdfReaders::safeHref( $node->getAttribute( 'href' ) );
                if ( $href === null || $mode === 'link' )
                {
                    $this->inline( $node, $to, $flags, $mode );
                    return;
                }
                $link = $this->el( self::NS_TEXT, 'text:a', array( 'xlink:type' => 'simple', 'xlink:href' => $href ) );
                $this->inline( $node, $link, $flags, 'link' );
                if ( $link->hasChildNodes() )
                    $to->appendChild( $link );
                return;
            case 'img':
                $alt = trim( $node->getAttribute( 'alt' ) );
                if ( $mode === 'text' && $this->frame( $node->getAttribute( 'src' ), $alt, $to ) )
                    return;
                $this->text( $to, $alt, $flags );
                return;
        }
        $this->inline( $node, $to, $flags, $mode );
    }

    /** Appends text, in a span when it is formatted (the span's text only: spans are never nested). */
    protected function text( DOMElement $to, $text, $flags )
    {
        if ( $text === '' || $text === null )
            return;
        if ( !$flags )
        {
            $to->appendChild( $this->doc->createTextNode( $text ) );
            return;
        }
        $style = $this->spanStyle( $flags );
        $last = $to->lastChild;
        if ( $last instanceof DOMElement && $last->localName === 'span' && $last->getAttributeNS( self::NS_TEXT, 'style-name' ) === $style )
        {
            $last->appendChild( $this->doc->createTextNode( $text ) );
            return;
        }
        $span = $this->el( self::NS_TEXT, 'text:span', array( 'text:style-name' => $style ) );
        $span->appendChild( $this->doc->createTextNode( $text ) );
        $to->appendChild( $span );
    }

    /** @return bool whether the image of key $key was placed as a frame into $to */
    protected function frame( $key, $alt, DOMElement $to )
    {
        if ( !isset( $this->images[$key] ) )
            return false;
        $name = $this->picture( $key );
        if ( $name === null )
            return false;
        $size = @getimagesize( $this->pictures[$name] );
        $width = $size ? max( 1, $size[0] ) / 96 : 1.0;
        $height = $size ? max( 1, $size[1] ) / 96 : 1.0;
        if ( $width > self::PAGE_INCHES )
        {
            $height = $height * self::PAGE_INCHES / $width;
            $width = self::PAGE_INCHES;
        }
        $this->frames++;
        $frame = $this->el( self::NS_DRAW, 'draw:frame', array(
            'draw:style-name' => $this->frameStyle(), 'draw:name' => $alt !== '' ? $alt : 'Image ' . $this->frames,
            'text:anchor-type' => 'as-char', 'svg:width' => sprintf( '%.3fin', $width ), 'svg:height' => sprintf( '%.3fin', $height ),
            'draw:z-index' => '0' ) );
        $frame->appendChild( $this->el( self::NS_DRAW, 'draw:image', array( 'xlink:href' => 'Pictures/' . $name, 'xlink:type' => 'simple',
                                                                             'xlink:show' => 'embed', 'xlink:actuate' => 'onLoad' ) ) );
        if ( $alt !== '' )
        {
            $description = $this->el( self::NS_SVG, 'svg:desc' );
            $description->appendChild( $this->doc->createTextNode( $alt ) );
            $frame->appendChild( $description );
        }
        $to->appendChild( $frame );
        return true;
    }

    /** @return string the automatic graphic style of the frames (one child: eZOOImport reads horizontal-pos) */
    protected function frameStyle()
    {
        if ( !isset( $this->styles['fr1'] ) )
        {
            $this->styles['fr1'] = true;
            $style = $this->el( self::NS_STYLE, 'style:style', array( 'style:name' => 'fr1', 'style:family' => 'graphic', 'style:parent-style-name' => 'Graphics' ) );
            $style->appendChild( $this->el( self::NS_STYLE, 'style:graphic-properties', array(
                'style:horizontal-pos' => 'center', 'style:horizontal-rel' => 'paragraph',
                'style:vertical-pos' => 'top', 'style:vertical-rel' => 'baseline' ) ) );
            $this->auto->appendChild( $style );
        }
        return 'fr1';
    }

    /** @return string|null the picture name of an image key, null when the file is no usable image */
    protected function picture( $key )
    {
        if ( isset( $this->pictureByKey[$key] ) )
            return $this->pictureByKey[$key];
        $path = (string)$this->images[$key];
        if ( !is_file( $path ) || is_link( $path ) || filesize( $path ) > self::MAX_IMAGE )
            return $this->pictureByKey[$key] = null;
        $info = @getimagesize( $path );
        $types = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
        if ( $info === false || !isset( $types[$info[2]] ) )
            return $this->pictureByKey[$key] = null;
        $base = preg_replace( '/[^A-Za-z0-9_-]+/', '_', pathinfo( basename( (string)$key ), PATHINFO_FILENAME ) );
        $base = trim( $base, '_' ) !== '' ? substr( $base, 0, 60 ) : 'image';
        $name = $base . '.' . $types[$info[2]];
        for ( $n = 2; isset( $this->pictures[$name] ); $n++ )
            $name = $base . '-' . $n . '.' . $types[$info[2]];
        $this->pictures[$name] = $path;
        $this->pictureTypes[$name] = $info['mime'];
        return $this->pictureByKey[$key] = $name;
    }

    /** @return array picture name => bytes */
    protected function pictureBytes()
    {
        $bytes = array();
        foreach ( $this->pictures as $name => $path )
        {
            $data = file_get_contents( $path );
            if ( $data === false )
                throw new expOdfConverterException( 'convert', 'an image of the document cannot be read' );
            $bytes[$name] = $data;
        }
        return $bytes;
    }

    /** Writes an HTML table as table:table. */
    protected function table( DOMElement $table, DOMElement $to )
    {
        $head = array();
        $body = array();
        foreach ( $table->childNodes as $section )
        {
            if ( !$section instanceof DOMElement )
                continue;
            $sectionName = strtolower( $section->nodeName );
            $rows = $sectionName === 'tr' ? array( $section ) : array();
            if ( $sectionName === 'thead' || $sectionName === 'tbody' )
            {
                foreach ( $section->childNodes as $row )
                {
                    if ( $row instanceof DOMElement && strtolower( $row->nodeName ) === 'tr' )
                        $rows[] = $row;
                }
            }
            foreach ( $rows as $row )
            {
                if ( $sectionName === 'thead' )
                    $head[] = $row;
                else
                    $body[] = $row;
            }
        }
        if ( !$head && !$body )
            return;
        $columns = 1;
        foreach ( array_merge( $head, $body ) as $row )
        {
            $width = 0;
            foreach ( $row->childNodes as $cell )
            {
                if ( $cell instanceof DOMElement )
                    $width += max( 1, (int)$cell->getAttribute( 'colspan' ) );
            }
            $columns = max( $columns, $width );
        }
        $this->tables++;
        $element = $this->el( self::NS_TABLE, 'table:table', array( 'table:name' => 'Table' . $this->tables ) );
        $element->appendChild( $this->el( self::NS_TABLE, 'table:table-column', array( 'table:number-columns-repeated' => $columns ) ) );
        $pending = array();
        if ( $head )
        {
            $headerRows = $this->el( self::NS_TABLE, 'table:table-header-rows' );
            foreach ( $head as $row )
                $headerRows->appendChild( $this->row( $row, 'header', $pending ) );
            $element->appendChild( $headerRows );
        }
        foreach ( $body as $row )
            $element->appendChild( $this->row( $row, 'body', $pending ) );
        $to->appendChild( $element );
    }

    /**
     * @param array $pending column => rows still covered by a row span above
     * @return DOMElement table:table-row
     */
    protected function row( DOMElement $row, $section, array &$pending )
    {
        $element = $this->el( self::NS_TABLE, 'table:table-row' );
        $column = 0;
        $covered = function () use ( &$pending, &$column, $element ) {
            while ( isset( $pending[$column] ) && $pending[$column] > 0 )
            {
                $element->appendChild( $this->el( self::NS_TABLE, 'table:covered-table-cell' ) );
                $pending[$column]--;
                $column++;
            }
        };
        foreach ( $row->childNodes as $cell )
        {
            if ( !$cell instanceof DOMElement )
                continue;
            $covered();
            $colspan = max( 1, min( 1000, (int)$cell->getAttribute( 'colspan' ) ) );
            $rowspan = max( 1, min( 1000, (int)$cell->getAttribute( 'rowspan' ) ) );
            $attributes = array( 'office:value-type' => 'string' );
            if ( $colspan > 1 )
                $attributes['table:number-columns-spanned'] = $colspan;
            if ( $rowspan > 1 )
                $attributes['table:number-rows-spanned'] = $rowspan;
            $tableCell = $this->el( self::NS_TABLE, 'table:table-cell', $attributes );
            $context = $section === 'header' ? 'header' : ( strtolower( $cell->nodeName ) === 'th' ? 'head' : 'cell' );
            $this->blocks( $cell, $tableCell, $context );
            if ( !$tableCell->hasChildNodes() )
                $tableCell->appendChild( $this->paragraph( $context ) );
            $element->appendChild( $tableCell );
            for ( $i = 0; $i < $colspan; $i++ )
            {
                if ( $rowspan > 1 )
                    $pending[$column + $i] = $rowspan - 1;
                if ( $i > 0 )
                    $element->appendChild( $this->el( self::NS_TABLE, 'table:covered-table-cell' ) );
            }
            $column += $colspan;
        }
        $covered();
        foreach ( $pending as $index => $count )
        {
            if ( $index >= $column && $count > 0 )
            {
                $element->appendChild( $this->el( self::NS_TABLE, 'table:covered-table-cell' ) );
                $pending[$index]--;
            }
        }
        return $element;
    }

    /** @return string styles.xml: the common styles the content refers to */
    protected static function stylesXml()
    {
        $headings = '';
        $sizes = array( 1 => '130%', 2 => '115%', 3 => '101%', 4 => '95%', 5 => '85%', 6 => '85%' );
        foreach ( $sizes as $level => $size )
        {
            $headings .= '<style:style style:name="Heading_20_' . $level . '" style:display-name="Heading ' . $level . '" style:family="paragraph"'
                       . ' style:parent-style-name="Heading" style:next-style-name="Text_20_body" style:default-outline-level="' . $level . '" style:class="text">'
                       . '<style:text-properties fo:font-size="' . $size . '" fo:font-weight="bold"/></style:style>';
        }
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<office:document-styles xmlns:office="' . self::NS_OFFICE . '" xmlns:style="' . self::NS_STYLE . '" xmlns:text="' . self::NS_TEXT . '"'
            . ' xmlns:table="' . self::NS_TABLE . '" xmlns:draw="' . self::NS_DRAW . '" xmlns:fo="' . self::NS_FO . '" xmlns:svg="' . self::NS_SVG . '"'
            . ' xmlns:xlink="' . self::NS_XLINK . '" office:version="1.3">'
            . '<office:font-face-decls>'
            . '<style:font-face style:name="Liberation Serif" svg:font-family="\'Liberation Serif\'" style:font-family-generic="roman" style:font-pitch="variable"/>'
            . '<style:font-face style:name="Liberation Sans" svg:font-family="\'Liberation Sans\'" style:font-family-generic="swiss" style:font-pitch="variable"/>'
            . '<style:font-face style:name="Liberation Mono" svg:font-family="\'Liberation Mono\'" style:font-family-generic="modern" style:font-pitch="fixed"/>'
            . '</office:font-face-decls>'
            . '<office:styles>'
            . '<style:default-style style:family="paragraph"><style:paragraph-properties fo:orphans="2" fo:widows="2"/>'
            . '<style:text-properties style:font-name="Liberation Serif" fo:font-size="12pt" fo:language="en" fo:country="US"/></style:default-style>'
            . '<style:style style:name="Standard" style:family="paragraph" style:class="text"/>'
            . '<style:style style:name="Heading" style:family="paragraph" style:parent-style-name="Standard" style:next-style-name="Text_20_body" style:class="text">'
            . '<style:paragraph-properties fo:margin-top="0.1665in" fo:margin-bottom="0.0835in" fo:keep-with-next="always"/>'
            . '<style:text-properties style:font-name="Liberation Sans" fo:font-size="14pt"/></style:style>'
            . $headings
            . '<style:style style:name="Text_20_body" style:display-name="Text body" style:family="paragraph" style:parent-style-name="Standard" style:class="text">'
            . '<style:paragraph-properties fo:margin-top="0in" fo:margin-bottom="0.0972in"/></style:style>'
            . '<style:style style:name="Preformatted_20_Text" style:display-name="Preformatted Text" style:family="paragraph" style:parent-style-name="Standard" style:class="html">'
            . '<style:paragraph-properties fo:margin-top="0in" fo:margin-bottom="0in"/>'
            . '<style:text-properties style:font-name="Liberation Mono" fo:font-size="10pt"/></style:style>'
            . '<style:style style:name="Quotations" style:family="paragraph" style:parent-style-name="Standard" style:class="html">'
            . '<style:paragraph-properties fo:margin-left="0.3937in" fo:margin-right="0.3937in" fo:margin-top="0in" fo:margin-bottom="0.1965in"/></style:style>'
            . '<style:style style:name="eZCustom_20_quote" style:display-name="eZCustom_20_quote" style:family="paragraph" style:parent-style-name="Quotations" style:class="text">'
            . '<style:text-properties fo:font-style="italic"/></style:style>'
            . '<style:style style:name="Table_20_Contents" style:display-name="Table Contents" style:family="paragraph" style:parent-style-name="Standard" style:class="extra"/>'
            . '<style:style style:name="Table_20_Heading" style:display-name="Table Heading" style:family="paragraph" style:parent-style-name="Table_20_Contents" style:class="extra">'
            . '<style:paragraph-properties fo:text-align="center"/><style:text-properties fo:font-weight="bold"/></style:style>'
            . '<style:style style:name="Horizontal_20_Line" style:display-name="Horizontal Line" style:family="paragraph" style:parent-style-name="Standard" style:class="html">'
            . '<style:paragraph-properties fo:margin-top="0in" fo:margin-bottom="0.1965in" fo:border-bottom="0.0008in solid #808080" fo:padding="0in"/>'
            . '<style:text-properties fo:font-size="6pt"/></style:style>'
            . '<style:style style:name="Graphics" style:family="graphic"><style:graphic-properties text:anchor-type="as-char" svg:y="0in" style:vertical-pos="top" style:vertical-rel="baseline"/></style:style>'
            . '</office:styles>'
            . '<office:automatic-styles><style:page-layout style:name="pm1"><style:page-layout-properties fo:page-width="8.5in" fo:page-height="11in"'
            . ' fo:margin-top="0.7874in" fo:margin-bottom="0.7874in" fo:margin-left="0.7874in" fo:margin-right="0.7874in"/></style:page-layout></office:automatic-styles>'
            . '<office:master-styles><style:master-page style:name="Standard" style:page-layout-name="pm1"/></office:master-styles>'
            . '</office:document-styles>';
    }

    /** @return string meta.xml */
    protected static function metaXml( $title )
    {
        $doc = new DOMDocument( '1.0', 'UTF-8' );
        $root = $doc->createElementNS( self::NS_OFFICE, 'office:document-meta' );
        $root->setAttributeNS( self::NS_XMLNS, 'xmlns:meta', self::NS_META );
        $root->setAttributeNS( self::NS_XMLNS, 'xmlns:dc', self::NS_DC );
        $root->setAttributeNS( self::NS_OFFICE, 'office:version', '1.3' );
        $doc->appendChild( $root );
        $meta = $doc->createElementNS( self::NS_OFFICE, 'office:meta' );
        $root->appendChild( $meta );
        $generator = $doc->createElementNS( self::NS_META, 'meta:generator' );
        $generator->appendChild( $doc->createTextNode( 'Exponential ezodf' ) );
        $meta->appendChild( $generator );
        if ( $title !== '' )
        {
            $element = $doc->createElementNS( self::NS_DC, 'dc:title' );
            $element->appendChild( $doc->createTextNode( $title ) );
            $meta->appendChild( $element );
        }
        $created = $doc->createElementNS( self::NS_META, 'meta:creation-date' );
        $created->appendChild( $doc->createTextNode( gmdate( 'Y-m-d\TH:i:s' ) ) );
        $meta->appendChild( $created );
        return $doc->saveXML();
    }

    /** @return string META-INF/manifest.xml */
    protected function manifestXml()
    {
        $doc = new DOMDocument( '1.0', 'UTF-8' );
        $root = $doc->createElementNS( self::NS_MANIFEST, 'manifest:manifest' );
        $root->setAttributeNS( self::NS_MANIFEST, 'manifest:version', '1.3' );
        $doc->appendChild( $root );
        $entries = array( '/' => self::MIME, 'content.xml' => 'text/xml', 'styles.xml' => 'text/xml', 'meta.xml' => 'text/xml' );
        foreach ( $this->pictureTypes as $name => $type )
            $entries['Pictures/' . $name] = $type;
        foreach ( $entries as $path => $type )
        {
            $entry = $doc->createElementNS( self::NS_MANIFEST, 'manifest:file-entry' );
            $entry->setAttributeNS( self::NS_MANIFEST, 'manifest:full-path', $path );
            $entry->setAttributeNS( self::NS_MANIFEST, 'manifest:media-type', $type );
            if ( $path === '/' )
                $entry->setAttributeNS( self::NS_MANIFEST, 'manifest:version', '1.3' );
            $root->appendChild( $entry );
        }
        return $doc->saveXML();
    }
}
