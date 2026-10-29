<?php
/**
 * File containing the expOdfReaders class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * Reads documents without LibreOffice and turns them into a safe HTML fragment plus the images it holds:
 *
 *   $result = expOdfReaders::toHtml( '/path/report.docx', 'docx', $workDir );
 *   $result['html']    a body fragment (see below)
 *   $result['images']  key => absolute path of an image file written below $workDir ("image1.png" => ...)
 *   $result['title']   the document's title ('' when it has none)
 *   $result['meta']    author, keywords (string[]), created, modified (unix time or 0), description, language,
 *                      pages (int), truncated (bool: a spreadsheet was cut at the limits)
 *
 * The readers: Word (docx, docm, dotx), HTML (html, htm, xhtml), Markdown (md, markdown), plain text (txt, text),
 * spreadsheets (ods, fods, xlsx, xlsm, csv, tsv: a heading and a table per sheet), presentations (odp, fodp, pptx,
 * ppsx: a heading per slide, its text, its pictures and its speaker notes as a quotation) and PDF (pdf, the text
 * pdftotext of the poppler utilities finds, when that tool is installed).
 *
 * The HTML holds only h1-h6, p, br, strong, em, u, s, sub, sup, a[href] (http, https and mailto), ul, ol, li,
 * table, thead, tbody, tr, th, td (colspan, rowspan), img[src][alt], blockquote, pre, code and hr. Every other
 * element is dropped (script, style, iframe, forms ...) or replaced by its content, every text is escaped and no
 * other attribute survives. An <img src> is always a key of 'images', never an address: remote pictures are not
 * fetched (their alternative text stays). sanitize() is the same filter for HTML from elsewhere.
 *
 * ZIP based formats are opened with ZipArchive and refused when eZOOImport::zipEntriesProblem() finds an entry
 * that is not safe (a path out of the package, a link, too many entries). Entries are read into memory with
 * limits (64 MB each, 256 MB together), never extracted; only images are written, under names of the reader's
 * own. XML is parsed with LIBXML_NONET and without document type declarations, so no entity is expanded and
 * nothing is loaded from the network. A relationship target that leaves the package is ignored.
 *
 * Failures throw expOdfConverterException with the code unsupported, convert or too_large.
 */
class expOdfReaders
{
    const NS_W = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    const NS_R = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    const NS_WP = 'http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    const NS_A = 'http://schemas.openxmlformats.org/drawingml/2006/main';
    const NS_V = 'urn:schemas-microsoft-com:vml';
    const NS_O = 'urn:schemas-microsoft-com:office:office';
    const NS_MC = 'http://schemas.openxmlformats.org/markup-compatibility/2006';
    const NS_REL = 'http://schemas.openxmlformats.org/package/2006/relationships';
    const NS_CP = 'http://schemas.openxmlformats.org/package/2006/metadata/core-properties';
    const NS_DC = 'http://purl.org/dc/elements/1.1/';
    const NS_DCTERMS = 'http://purl.org/dc/terms/';
    const NS_EP = 'http://schemas.openxmlformats.org/officeDocument/2006/extended-properties';
    const NS_S = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    const NS_P = 'http://schemas.openxmlformats.org/presentationml/2006/main';

    const NS_OFFICE = 'urn:oasis:names:tc:opendocument:xmlns:office:1.0';
    const NS_TEXT = 'urn:oasis:names:tc:opendocument:xmlns:text:1.0';
    const NS_STYLE = 'urn:oasis:names:tc:opendocument:xmlns:style:1.0';
    const NS_TABLE = 'urn:oasis:names:tc:opendocument:xmlns:table:1.0';
    const NS_DRAW = 'urn:oasis:names:tc:opendocument:xmlns:drawing:1.0';
    const NS_PRESENTATION = 'urn:oasis:names:tc:opendocument:xmlns:presentation:1.0';
    const NS_SVG = 'urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0';
    const NS_FO = 'urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0';
    const NS_META = 'urn:oasis:names:tc:opendocument:xmlns:meta:1.0';
    const NS_XLINK = 'http://www.w3.org/1999/xlink';

    /** Limits */
    const MAX_ENTRY = 67108864;        // 64 MB per package entry or plain text file
    const MAX_TOTAL = 268435456;       // 256 MB for everything read from one package, and per document
    const MAX_DATA_IMAGE = 10485760;   // 10 MB per data: image of an HTML or Markdown document
    const MAX_IMAGES = 1000;
    const MAX_SHEETS = 50;
    const MAX_ROWS = 5000;
    const MAX_COLUMNS = 200;
    const MAX_SLIDES = 1000;
    const MAX_COVERED = 200000;        // cells marked as covered by merged cells, per sheet

    /** Formatting flags of a text run */
    const F_BOLD = 1;
    const F_ITALIC = 2;
    const F_UNDERLINE = 4;
    const F_STRIKE = 8;
    const F_SUP = 16;
    const F_SUB = 32;

    /** @var string[] the extensions read without any external tool */
    protected static $native = array( 'docx', 'docm', 'dotx', 'html', 'htm', 'xhtml', 'md', 'markdown', 'txt', 'text',
                                      'ods', 'fods', 'xlsx', 'xlsm', 'csv', 'tsv', 'odp', 'fodp', 'pptx', 'ppsx' );

    /** @var array elements dropped with everything in them */
    protected static $dropTags = array(
        'script' => 1, 'style' => 1, 'head' => 1, 'title' => 1, 'meta' => 1, 'link' => 1, 'noscript' => 1,
        'template' => 1, 'iframe' => 1, 'frame' => 1, 'frameset' => 1, 'noframes' => 1, 'object' => 1, 'embed' => 1,
        'applet' => 1, 'svg' => 1, 'math' => 1, 'canvas' => 1, 'audio' => 1, 'video' => 1, 'source' => 1,
        'track' => 1, 'map' => 1, 'area' => 1, 'input' => 1, 'select' => 1, 'textarea' => 1, 'button' => 1,
        'option' => 1, 'optgroup' => 1, 'datalist' => 1, 'output' => 1, 'param' => 1, 'base' => 1, 'dialog' => 1,
        'portal' => 1, 'slot' => 1,
    );

    /** @var array elements renamed to an element of the subset */
    protected static $tagMap = array(
        'b' => 'strong', 'i' => 'em', 'ins' => 'u', 'strike' => 's', 'del' => 's', 'tt' => 'code', 'kbd' => 'code',
        'samp' => 'code', 'var' => 'em', 'cite' => 'em', 'dfn' => 'em', 'tfoot' => 'tbody', 'dt' => 'p',
        'dd' => 'p', 'caption' => 'p', 'figcaption' => 'p', 'summary' => 'p', 'address' => 'p', 'listing' => 'pre',
        'xmp' => 'pre', 'plaintext' => 'pre', 'dir' => 'ul', 'menu' => 'ul',
    );

    /** @var array the elements of the subset (a, img, br, hr and pre are handled on their own) */
    protected static $allowed = array(
        'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'p' => 1, 'strong' => 1, 'em' => 1,
        'u' => 1, 's' => 1, 'sub' => 1, 'sup' => 1, 'ul' => 1, 'ol' => 1, 'li' => 1, 'table' => 1, 'thead' => 1,
        'tbody' => 1, 'tr' => 1, 'th' => 1, 'td' => 1, 'blockquote' => 1, 'code' => 1,
    );

    /** @var array unknown elements that end a line of text when they are unwrapped */
    protected static $blockish = array(
        'div' => 1, 'section' => 1, 'article' => 1, 'main' => 1, 'header' => 1, 'footer' => 1, 'nav' => 1,
        'aside' => 1, 'figure' => 1, 'details' => 1, 'dl' => 1, 'form' => 1, 'fieldset' => 1, 'legend' => 1,
        'hgroup' => 1, 'center' => 1, 'body' => 1, 'html' => 1, 'search' => 1,
    );

    /** @var array inline nodes of the cleaned tree */
    protected static $inline = array(
        '#text' => 1, 'strong' => 1, 'em' => 1, 'u' => 1, 's' => 1, 'sub' => 1, 'sup' => 1, 'a' => 1, 'code' => 1,
        'br' => 1, 'img' => 1,
    );

    /** @var string[] classes of the kernel's Markdown renderer whose elements are decoration, not text */
    protected static $kernelMarkdownDropClasses = array( 'a4-help-anchor', 'a4-help-fnback', 'a4-sr-only', 'a4-icon',
                                                         'a4-help-task-box', 'a4-help-tile' );

    /** @var array strict OOXML namespaces => their transitional names */
    protected static $strictNamespaces = array(
        'http://purl.oclc.org/ooxml/wordprocessingml/main' => self::NS_W,
        'http://purl.oclc.org/ooxml/officeDocument/relationships' => self::NS_R,
        'http://purl.oclc.org/ooxml/drawingml/main' => self::NS_A,
        'http://purl.oclc.org/ooxml/drawingml/wordprocessingDrawing' => self::NS_WP,
        'http://purl.oclc.org/ooxml/spreadsheetml/main' => self::NS_S,
        'http://purl.oclc.org/ooxml/presentationml/main' => self::NS_P,
    );

    /** @var array tokens of the Markdown inline converter, see mdInline() */
    protected static $mdTokens = array();

    /** @var array reference link definitions of the Markdown document being converted */
    protected static $mdRefs = array();

    /** @var string */
    protected $dir;
    /** @var array */
    protected $options;
    /** @var array key => path */
    protected $images = array();
    /** @var array sha1 => key */
    protected $imageHashes = array();
    /** @var int */
    protected $imageCount = 0;
    /** @var array */
    protected $meta;
    /** @var string */
    protected $title = '';
    /** @var ZipArchive|null */
    protected $zip = null;
    /** @var int bytes read from the package so far */
    protected $unpacked = 0;

    /** @var array relationships of the part being read: id => array( type, target, external ) */
    protected $rels = array();
    /** @var array Word styles: id => array( name, based, outline, numId, ilvl, rPr ) */
    protected $styles = array();
    /** @var array Word numbering: 'abstract' => id => ilvl => format, 'num' => numId => array( abstract, overrides ) */
    protected $numbering = array( 'abstract' => array(), 'num' => array() );
    /** @var array footnotes and endnotes: kind => array( 'nodes' => id => element, 'rels' => relationships ) */
    protected $noteParts = array();
    /** @var array "kind:id" => number of the notes referred to, in order */
    protected $noteOrder = array();
    /** @var array OpenDocument automatic styles: name => formatting flags */
    protected $odfStyleFlags = array();
    /** @var array OpenDocument list styles: name => 'ul' | 'ol' */
    protected $odfListTags = array();

    /**
     * @return string[] the extensions these readers read: pdf only when pdftotext is installed
     */
    public static function extensions()
    {
        $list = self::$native;
        if ( self::pdfTool() !== '' )
            $list[] = 'pdf';
        return $list;
    }

    /**
     * @param string $ext an extension (with or without the dot, any case)
     * @return bool whether toHtml() reads it
     */
    public static function reads( $ext )
    {
        return in_array( self::normalizeExtension( $ext ), self::extensions(), true );
    }

    /**
     * Reads a document.
     *
     * @param string $file the document
     * @param string $ext its format (the extension of its original name)
     * @param string $dir the directory the images are written to (created with mode 0700 when missing)
     * @param array $options name (the original file name), max_bytes (the largest document read, default
     *                       256 MB), markdown ('auto': the kernel's renderer when it exists, 'own': the reader's
     *                       own converter), seconds (the time pdftotext may take, default 120)
     * @return array( 'html' => string, 'images' => array( key => path ), 'title' => string, 'meta' => array )
     * @throws expOdfConverterException
     */
    public static function toHtml( $file, $ext, $dir, array $options = array() )
    {
        $ext = self::normalizeExtension( $ext );
        if ( $ext !== 'pdf' && !in_array( $ext, self::$native, true ) )
            throw new expOdfConverterException( 'unsupported', "ezodf has no reader for .$ext files" );
        if ( !is_string( $file ) || !is_file( $file ) || !is_readable( $file ) )
            throw new expOdfConverterException( 'convert', 'the document cannot be read' );
        $max = isset( $options['max_bytes'] ) ? (int)$options['max_bytes'] : self::MAX_TOTAL;
        if ( filesize( $file ) > $max )
            throw new expOdfConverterException( 'too_large', sprintf( 'the document is larger than %d MB', max( 1, round( $max / 1048576 ) ) ) );
        $reader = new self( $dir, $options );
        return $reader->read( $file, $ext );
    }

    /**
     * Filters HTML from anywhere to the subset toHtml() returns. An <img> stays only when its src is one of
     * $imageKeys; otherwise its alternative text does.
     *
     * @param string $html
     * @param string[] $imageKeys
     * @return string
     */
    public static function sanitize( $html, array $imageKeys = array() )
    {
        $keys = array();
        foreach ( $imageKeys as $key )
            $keys[(string)$key] = true;
        $resolver = function ( $src ) use ( $keys ) {
            return isset( $keys[$src] ) ? $src : null;
        };
        return self::cleanHtml( (string)$html, $resolver );
    }

    /**
     * Parses an HTML fragment (UTF-8) and returns its body element. Nothing is loaded from the network.
     *
     * @param string $html
     * @return DOMElement
     */
    public static function loadHtmlFragment( $html )
    {
        $doc = self::loadHtmlDocument( '<!DOCTYPE html><html><head></head><body>' . (string)$html . '</body></html>' );
        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
        if ( !$body )
        {
            $body = $doc->createElement( 'body' );
            $doc->appendChild( $body );
        }
        return $body;
    }

    /**
     * Converts Markdown with the reader's own converter (a CommonMark subset with GitHub tables and
     * strikethrough). The result still needs sanitize(): raw HTML in the document is escaped, but links and
     * images are emitted as written.
     *
     * @param string $markdown without front matter
     * @return string
     */
    public static function markdownToHtml( $markdown )
    {
        $md = str_replace( array( "\r\n", "\r", "\x1A" ), array( "\n", "\n", '' ), (string)$markdown );
        $lines = explode( "\n", $md );
        foreach ( $lines as $i => $line )
            $lines[$i] = self::expandLeadingTabs( $line );
        self::$mdRefs = array();
        $kept = array();
        $fence = null;
        foreach ( $lines as $line )
        {
            if ( preg_match( '/^ {0,3}(`{3,}|~{3,})/', $line, $m ) )
            {
                if ( $fence === null )
                    $fence = $m[1][0];
                elseif ( $m[1][0] === $fence )
                    $fence = null;
            }
            elseif ( $fence === null && preg_match( '/^ {0,3}\[([^\]]+)\]:\s*<?(\S+?)>?(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*$/', $line, $m ) )
            {
                self::$mdRefs[strtolower( trim( $m[1] ) )] = $m[2];
                continue;
            }
            $kept[] = $line;
        }
        return self::mdBlocks( $kept );
    }

    /**
     * @param string $dir
     * @param array $options
     * @throws expOdfConverterException
     */
    protected function __construct( $dir, array $options )
    {
        $dir = rtrim( (string)$dir, '/' );
        if ( $dir === '' )
            throw new expOdfConverterException( 'convert', 'no work directory for the images' );
        if ( is_link( $dir ) )
            throw new expOdfConverterException( 'convert', 'the work directory is a link' );
        if ( !is_dir( $dir ) && !@mkdir( $dir, 0700, true ) && !is_dir( $dir ) )
            throw new expOdfConverterException( 'convert', 'the work directory cannot be created' );
        $this->dir = $dir;
        $this->options = $options;
        $this->meta = array( 'author' => '', 'keywords' => array(), 'created' => 0, 'modified' => 0, 'description' => '',
                             'language' => '', 'pages' => 0, 'truncated' => false );
    }

    /**
     * @param string $file
     * @param string $ext
     * @return array
     */
    protected function read( $file, $ext )
    {
        switch ( $ext )
        {
            case 'docx':
            case 'docm':
            case 'dotx':
                $html = $this->readDocx( $file );
                break;
            case 'html':
            case 'htm':
            case 'xhtml':
                $html = $this->readHtml( $file );
                break;
            case 'md':
            case 'markdown':
                $html = $this->readMarkdown( $file );
                break;
            case 'txt':
            case 'text':
                $html = $this->readText( $file );
                break;
            case 'ods':
                $html = $this->readOds( $file, false );
                break;
            case 'fods':
                $html = $this->readOds( $file, true );
                break;
            case 'xlsx':
            case 'xlsm':
                $html = $this->readXlsx( $file );
                break;
            case 'csv':
            case 'tsv':
                $html = $this->readCsv( $file, $ext );
                break;
            case 'odp':
                $html = $this->readOdp( $file, false );
                break;
            case 'fodp':
                $html = $this->readOdp( $file, true );
                break;
            case 'pptx':
            case 'ppsx':
                $html = $this->readPptx( $file );
                break;
            case 'pdf':
                $html = $this->readPdf( $file );
                break;
            default:
                throw new expOdfConverterException( 'unsupported', "ezodf has no reader for .$ext files" );
        }

        $images = $this->images;
        $html = self::cleanHtml( $html, function ( $src ) use ( $images ) {
            return isset( $images[$src] ) ? $src : null;
        } );

        // only the images the HTML shows
        $used = array();
        if ( preg_match_all( '/<img src="([^"]*)"/', $html, $m ) )
        {
            foreach ( $m[1] as $key )
            {
                $key = html_entity_decode( $key, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                if ( isset( $this->images[$key] ) )
                    $used[$key] = $this->images[$key];
            }
        }

        $title = trim( preg_replace( '/\s+/u', ' ', (string)$this->title ) );
        if ( $title === '' && preg_match( '#^\s*<h1>(.*?)</h1>#s', $html, $m ) )
            $title = trim( html_entity_decode( strip_tags( $m[1] ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );

        return array( 'html' => $html, 'images' => $used, 'title' => $title, 'meta' => $this->meta );
    }

    /* ------------------------------------------------------------------------------------------------------
     * Word
     * ------------------------------------------------------------------------------------------------------ */

    /**
     * @param string $file
     * @return string HTML
     */
    protected function readDocx( $file )
    {
        $this->openZip( $file );
        try
        {
            $package = $this->rels( '' );
            $main = $this->relByType( $package, '/officeDocument' );
            if ( $main === null )
                $main = 'word/document.xml';
            $doc = $this->ooxml( $main );
            $this->rels = $this->rels( $main );

            $stylesPart = $this->relByType( $this->rels, '/styles' );
            if ( $stylesPart !== null && ( $styles = $this->ooxml( $stylesPart, true ) ) )
                $this->docxLoadStyles( $styles );
            $numberingPart = $this->relByType( $this->rels, '/numbering' );
            if ( $numberingPart !== null && ( $numbering = $this->ooxml( $numberingPart, true ) ) )
                $this->docxLoadNumbering( $numbering );
            foreach ( array( 'f' => array( '/footnotes', 'footnote' ), 'e' => array( '/endnotes', 'endnote' ) ) as $kind => $spec )
            {
                $part = $this->relByType( $this->rels, $spec[0] );
                if ( $part === null || !( $notes = $this->ooxml( $part, true ) ) )
                    continue;
                $nodes = array();
                foreach ( $notes->getElementsByTagNameNS( self::NS_W, $spec[1] ) as $note )
                    $nodes[$note->getAttributeNS( self::NS_W, 'id' )] = $note;
                $this->noteParts[$kind] = array( 'nodes' => $nodes, 'rels' => $this->rels( $part ) );
            }

            $body = self::child( $doc->documentElement, self::NS_W, 'body' );
            $html = $body ? $this->docxBlocks( $body ) : '';
            $html .= $this->docxNotes();
            $this->ooxmlMeta( $package );
            return $html;
        }
        finally
        {
            $this->closeZip();
        }
    }

    protected function docxLoadStyles( DOMDocument $doc )
    {
        foreach ( $doc->getElementsByTagNameNS( self::NS_W, 'style' ) as $style )
        {
            $id = $style->getAttributeNS( self::NS_W, 'styleId' );
            if ( $id === '' )
                continue;
            $name = self::child( $style, self::NS_W, 'name' );
            $based = self::child( $style, self::NS_W, 'basedOn' );
            $pPr = self::child( $style, self::NS_W, 'pPr' );
            $outline = $pPr ? self::child( $pPr, self::NS_W, 'outlineLvl' ) : null;
            $numPr = $pPr ? self::child( $pPr, self::NS_W, 'numPr' ) : null;
            $numId = $numPr ? self::child( $numPr, self::NS_W, 'numId' ) : null;
            $ilvl = $numPr ? self::child( $numPr, self::NS_W, 'ilvl' ) : null;
            $this->styles[$id] = array(
                'name' => $name ? strtolower( trim( $name->getAttributeNS( self::NS_W, 'val' ) ) ) : '',
                'based' => $based ? $based->getAttributeNS( self::NS_W, 'val' ) : '',
                'outline' => $outline ? $outline->getAttributeNS( self::NS_W, 'val' ) : '',
                'numId' => $numId ? $numId->getAttributeNS( self::NS_W, 'val' ) : '',
                'ilvl' => $ilvl ? $ilvl->getAttributeNS( self::NS_W, 'val' ) : '',
                'rPr' => self::child( $style, self::NS_W, 'rPr' ),
            );
        }
    }

    protected function docxLoadNumbering( DOMDocument $doc )
    {
        foreach ( $doc->getElementsByTagNameNS( self::NS_W, 'abstractNum' ) as $abstract )
        {
            $levels = array();
            foreach ( self::children( $abstract, self::NS_W, 'lvl' ) as $lvl )
            {
                $fmt = self::child( $lvl, self::NS_W, 'numFmt' );
                $levels[(int)$lvl->getAttributeNS( self::NS_W, 'ilvl' )] = $fmt ? $fmt->getAttributeNS( self::NS_W, 'val' ) : 'decimal';
            }
            $this->numbering['abstract'][$abstract->getAttributeNS( self::NS_W, 'abstractNumId' )] = $levels;
        }
        foreach ( $doc->getElementsByTagNameNS( self::NS_W, 'num' ) as $num )
        {
            $abstract = self::child( $num, self::NS_W, 'abstractNumId' );
            $overrides = array();
            foreach ( self::children( $num, self::NS_W, 'lvlOverride' ) as $override )
            {
                $lvl = self::child( $override, self::NS_W, 'lvl' );
                $fmt = $lvl ? self::child( $lvl, self::NS_W, 'numFmt' ) : null;
                if ( $fmt )
                    $overrides[(int)$override->getAttributeNS( self::NS_W, 'ilvl' )] = $fmt->getAttributeNS( self::NS_W, 'val' );
            }
            $this->numbering['num'][$num->getAttributeNS( self::NS_W, 'numId' )] = array(
                'abstract' => $abstract ? $abstract->getAttributeNS( self::NS_W, 'val' ) : '',
                'overrides' => $overrides,
            );
        }
    }

    /**
     * @return string|null the number format of a list level (bullet, decimal, none ...), null when unknown
     */
    protected function docxNumFormat( $numId, $ilvl )
    {
        if ( !$this->numbering['num'] && !$this->numbering['abstract'] )
            return 'bullet';
        if ( !isset( $this->numbering['num'][$numId] ) )
            return null;
        $num = $this->numbering['num'][$numId];
        if ( isset( $num['overrides'][$ilvl] ) )
            return $num['overrides'][$ilvl];
        if ( isset( $this->numbering['abstract'][$num['abstract']][$ilvl] ) )
            return $this->numbering['abstract'][$num['abstract']][$ilvl];
        if ( isset( $this->numbering['abstract'][$num['abstract']][0] ) )
            return $this->numbering['abstract'][$num['abstract']][0];
        return 'decimal';
    }

    /** @return int the heading level (1-6) of a paragraph style, 0 when it is none */
    protected function docxStyleHeading( $id, $depth = 0 )
    {
        if ( $id === '' || $depth > 10 )
            return 0;
        $style = isset( $this->styles[$id] ) ? $this->styles[$id] : null;
        foreach ( array( $id, $style ? $style['name'] : '' ) as $candidate )
        {
            if ( preg_match( '/^heading\s*([1-9])$/i', $candidate, $m ) )
                return min( 6, (int)$m[1] );
            if ( strtolower( $candidate ) === 'title' )
                return 1;
        }
        if ( !$style )
            return 0;
        if ( $style['outline'] !== '' && is_numeric( $style['outline'] ) && (int)$style['outline'] < 9 )
            return min( 6, (int)$style['outline'] + 1 );
        return $style['based'] !== '' ? $this->docxStyleHeading( $style['based'], $depth + 1 ) : 0;
    }

    /** @return string 'quote', 'pre' or '' for a paragraph style */
    protected function docxStyleKind( $id, $depth = 0 )
    {
        if ( $id === '' || $depth > 10 || !isset( $this->styles[$id] ) )
            return '';
        $name = $this->styles[$id]['name'];
        if ( preg_match( '/^(quote|intense quote|block text|quotations)$/', $name ) )
            return 'quote';
        if ( preg_match( '/^(html preformatted|plain text|preformatted text|source text|code)$/', $name ) )
            return 'pre';
        return $this->docxStyleKind( $this->styles[$id]['based'], $depth + 1 );
    }

    /** @return int the formatting flags of a style and the styles it is based on */
    protected function docxStyleFlags( $id, $depth = 0 )
    {
        if ( $id === '' || $depth > 10 || !isset( $this->styles[$id] ) )
            return 0;
        $base = $this->docxStyleFlags( $this->styles[$id]['based'], $depth + 1 );
        return self::docxApplyRpr( $this->styles[$id]['rPr'], $base );
    }

    /** @return array( numId, ilvl ) of a style's numbering, '' when it has none */
    protected function docxStyleNumbering( $id, $depth = 0 )
    {
        if ( $id === '' || $depth > 10 || !isset( $this->styles[$id] ) )
            return array( '', '' );
        if ( $this->styles[$id]['numId'] !== '' )
            return array( $this->styles[$id]['numId'], $this->styles[$id]['ilvl'] );
        return $this->docxStyleNumbering( $this->styles[$id]['based'], $depth + 1 );
    }

    /** @return int $flags changed by the run properties $rPr */
    protected static function docxApplyRpr( $rPr, $flags )
    {
        if ( !$rPr instanceof DOMElement )
            return $flags;
        foreach ( self::children( $rPr, self::NS_W ) as $property )
        {
            switch ( $property->localName )
            {
                case 'b':
                    $flags = self::onOff( $property ) ? $flags | self::F_BOLD : $flags & ~self::F_BOLD;
                    break;
                case 'i':
                    $flags = self::onOff( $property ) ? $flags | self::F_ITALIC : $flags & ~self::F_ITALIC;
                    break;
                case 'u':
                    $value = strtolower( $property->getAttributeNS( self::NS_W, 'val' ) );
                    $flags = $value !== 'none' && $value !== '0' ? $flags | self::F_UNDERLINE : $flags & ~self::F_UNDERLINE;
                    break;
                case 'strike':
                case 'dstrike':
                    $flags = self::onOff( $property ) ? $flags | self::F_STRIKE : $flags & ~self::F_STRIKE;
                    break;
                case 'vertAlign':
                    $value = $property->getAttributeNS( self::NS_W, 'val' );
                    $flags &= ~( self::F_SUP | self::F_SUB );
                    if ( $value === 'superscript' )
                        $flags |= self::F_SUP;
                    elseif ( $value === 'subscript' )
                        $flags |= self::F_SUB;
                    break;
            }
        }
        return $flags;
    }

    /** @return bool the value of an on/off property of Word */
    protected static function onOff( DOMElement $property )
    {
        $value = strtolower( $property->getAttributeNS( self::NS_W, 'val' ) );
        return !in_array( $value, array( '0', 'false', 'off', 'none' ), true );
    }

    /** @return DOMElement[] the paragraphs and tables of a container, through content controls and revisions */
    protected function docxFlatten( DOMElement $container )
    {
        $nodes = array();
        foreach ( self::children( $container ) as $child )
        {
            if ( $child->namespaceURI !== self::NS_W )
                continue;
            switch ( $child->localName )
            {
                case 'p':
                case 'tbl':
                    $nodes[] = $child;
                    break;
                case 'sdt':
                    $content = self::child( $child, self::NS_W, 'sdtContent' );
                    if ( $content )
                        $nodes = array_merge( $nodes, $this->docxFlatten( $content ) );
                    break;
                case 'customXml':
                case 'ins':
                case 'moveTo':
                    $nodes = array_merge( $nodes, $this->docxFlatten( $child ) );
                    break;
            }
        }
        return $nodes;
    }

    /**
     * @param DOMElement $container w:body, w:tc, w:footnote ...
     * @return string HTML blocks
     */
    protected function docxBlocks( DOMElement $container )
    {
        $out = '';
        $items = array();
        $quote = '';
        $pre = array();
        foreach ( $this->docxFlatten( $container ) as $node )
        {
            if ( $node->localName === 'tbl' )
            {
                $out .= self::renderList( $items ) . self::wrapQuote( $quote ) . self::wrapPre( $pre );
                $items = array();
                $out .= $this->docxTable( $node );
                continue;
            }
            $p = $this->docxParagraph( $node );
            if ( $p['list'] !== null )
            {
                $out .= self::wrapQuote( $quote ) . self::wrapPre( $pre );
                if ( self::nonBlank( $p['html'] ) )
                    $items[] = $p['list'] + array( 'html' => $p['html'] );
                continue;
            }
            $out .= self::renderList( $items );
            $items = array();
            if ( $p['kind'] === 'pre' )
            {
                $out .= self::wrapQuote( $quote );
                $pre[] = html_entity_decode( strip_tags( str_replace( '<br>', "\n", $p['html'] ) ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
                continue;
            }
            $out .= self::wrapPre( $pre );
            if ( $p['kind'] === 'quote' )
            {
                if ( self::nonBlank( $p['html'] ) )
                    $quote .= '<p>' . $p['html'] . '</p>';
                continue;
            }
            $out .= self::wrapQuote( $quote );
            if ( !self::nonBlank( $p['html'] ) )
                continue;
            $out .= $p['level'] ? "<h{$p['level']}>{$p['html']}</h{$p['level']}>" : '<p>' . $p['html'] . '</p>';
        }
        $out .= self::renderList( $items ) . self::wrapQuote( $quote ) . self::wrapPre( $pre );
        return $out;
    }

    /** @return string the quotation of $html, and empties it */
    protected static function wrapQuote( &$html )
    {
        $out = $html !== '' ? '<blockquote>' . $html . '</blockquote>' : '';
        $html = '';
        return $out;
    }

    /** @return string the preformatted block of $lines, and empties them */
    protected static function wrapPre( array &$lines )
    {
        $out = $lines ? '<pre>' . self::e( implode( "\n", $lines ) ) . '</pre>' : '';
        $lines = array();
        return $out;
    }

    /**
     * @return array( 'level' => heading level or 0, 'kind' => '' | quote | pre, 'list' => null or
     *                array( key, level, tag ), 'html' => inline HTML )
     */
    protected function docxParagraph( DOMElement $p )
    {
        $pPr = self::child( $p, self::NS_W, 'pPr' );
        $styleElement = $pPr ? self::child( $pPr, self::NS_W, 'pStyle' ) : null;
        $styleId = $styleElement ? $styleElement->getAttributeNS( self::NS_W, 'val' ) : '';
        $level = $this->docxStyleHeading( $styleId );
        if ( !$level && $pPr && ( $outline = self::child( $pPr, self::NS_W, 'outlineLvl' ) ) )
        {
            $value = $outline->getAttributeNS( self::NS_W, 'val' );
            if ( is_numeric( $value ) && (int)$value < 6 )
                $level = (int)$value + 1;
        }
        $kind = $level ? '' : $this->docxStyleKind( $styleId );

        $list = null;
        if ( !$level )
        {
            list( $numId, $ilvl ) = $this->docxStyleNumbering( $styleId );
            $numPr = $pPr ? self::child( $pPr, self::NS_W, 'numPr' ) : null;
            if ( $numPr )
            {
                $own = self::child( $numPr, self::NS_W, 'numId' );
                $ownLevel = self::child( $numPr, self::NS_W, 'ilvl' );
                if ( $own )
                    $numId = $own->getAttributeNS( self::NS_W, 'val' );
                if ( $ownLevel )
                    $ilvl = $ownLevel->getAttributeNS( self::NS_W, 'val' );
            }
            if ( $numId !== '' && $numId !== '0' )
            {
                $format = $this->docxNumFormat( $numId, (int)$ilvl );
                if ( $format !== null && $format !== 'none' )
                    $list = array( 'key' => 'w' . $numId, 'level' => (int)$ilvl, 'tag' => $format === 'bullet' ? 'ul' : 'ol' );
            }
        }

        $flags = $level ? 0 : $this->docxStyleFlags( $styleId );
        $segments = array();
        $this->docxRuns( $p, $flags, null, $segments );
        return array( 'level' => $level, 'kind' => $kind, 'list' => $list, 'html' => self::segmentsToHtml( $segments ) );
    }

    /**
     * Collects the text segments of the runs below $parent.
     */
    protected function docxRuns( DOMElement $parent, $flags, $href, array &$segments )
    {
        foreach ( self::children( $parent ) as $child )
        {
            if ( $child->namespaceURI === self::NS_MC && $child->localName === 'AlternateContent' )
            {
                $choice = self::alternateContent( $child, false );
                if ( $choice )
                    $this->docxRuns( $choice, $flags, $href, $segments );
                continue;
            }
            if ( $child->namespaceURI !== self::NS_W )
                continue;
            switch ( $child->localName )
            {
                case 'r':
                    $this->docxRun( $child, $flags, $href, $segments );
                    break;
                case 'hyperlink':
                    $target = $href;
                    $id = $child->getAttributeNS( self::NS_R, 'id' );
                    if ( $id !== '' && isset( $this->rels[$id] ) && $this->rels[$id]['external'] )
                    {
                        $safe = self::safeHref( $this->rels[$id]['target'] );
                        if ( $safe !== null )
                            $target = $safe;
                    }
                    $this->docxRuns( $child, $flags, $target, $segments );
                    break;
                case 'sdt':
                    $content = self::child( $child, self::NS_W, 'sdtContent' );
                    if ( $content )
                        $this->docxRuns( $content, $flags, $href, $segments );
                    break;
                case 'ins':
                case 'moveTo':
                case 'smartTag':
                case 'customXml':
                case 'fldSimple':
                case 'dir':
                case 'bdo':
                    $this->docxRuns( $child, $flags, $href, $segments );
                    break;
            }
        }
    }

    protected function docxRun( DOMElement $run, $flags, $href, array &$segments )
    {
        $rPr = self::child( $run, self::NS_W, 'rPr' );
        if ( $rPr )
        {
            $runStyle = self::child( $rPr, self::NS_W, 'rStyle' );
            if ( $runStyle )
                $flags |= $this->docxStyleFlags( $runStyle->getAttributeNS( self::NS_W, 'val' ) );
            $flags = self::docxApplyRpr( $rPr, $flags );
        }
        $this->docxRunContent( $run, $flags, $href, $segments );
    }

    protected function docxRunContent( DOMElement $run, $flags, $href, array &$segments )
    {
        foreach ( self::children( $run ) as $child )
        {
            if ( $child->namespaceURI === self::NS_MC && $child->localName === 'AlternateContent' )
            {
                $choice = self::alternateContent( $child, false );
                if ( $choice )
                    $this->docxRunContent( $choice, $flags, $href, $segments );
                continue;
            }
            if ( $child->namespaceURI !== self::NS_W )
                continue;
            switch ( $child->localName )
            {
                case 't':
                    $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => $child->textContent );
                    break;
                case 'tab':
                    $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => ' ' );
                    break;
                case 'br':
                    $type = $child->getAttributeNS( self::NS_W, 'type' );
                    if ( $type !== 'page' && $type !== 'column' )
                        $segments[] = array( 'f' => 0, 'h' => $href, 'raw' => '<br>' );
                    break;
                case 'cr':
                    $segments[] = array( 'f' => 0, 'h' => $href, 'raw' => '<br>' );
                    break;
                case 'noBreakHyphen':
                    $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => '-' );
                    break;
                case 'drawing':
                    $html = $this->docxDrawing( $child );
                    if ( $html !== '' )
                        $segments[] = array( 'f' => 0, 'h' => $href, 'raw' => $html );
                    break;
                case 'pict':
                case 'object':
                    $html = $this->docxVml( $child );
                    if ( $html !== '' )
                        $segments[] = array( 'f' => 0, 'h' => $href, 'raw' => $html );
                    break;
                case 'footnoteReference':
                case 'endnoteReference':
                    $number = $this->noteNumber( $child->localName === 'footnoteReference' ? 'f' : 'e', $child->getAttributeNS( self::NS_W, 'id' ) );
                    if ( $number )
                        $segments[] = array( 'f' => 0, 'h' => null, 'raw' => '<sup>' . $number . '</sup>' );
                    break;
            }
        }
    }

    /** @return string an <img> for a DrawingML picture, its alternative text, or '' */
    protected function docxDrawing( DOMElement $drawing )
    {
        $docPr = $drawing->getElementsByTagNameNS( self::NS_WP, 'docPr' )->item( 0 );
        $alt = '';
        if ( $docPr )
        {
            $alt = trim( $docPr->getAttribute( 'descr' ) );
            if ( $alt === '' )
                $alt = trim( $docPr->getAttribute( 'title' ) );
        }
        $blip = $drawing->getElementsByTagNameNS( self::NS_A, 'blip' )->item( 0 );
        $key = $blip ? $this->imageFromRel( $blip->getAttributeNS( self::NS_R, 'embed' ) ) : null;
        return self::imgHtml( $key, $alt );
    }

    /** @return string an <img> for a VML picture (older documents), its alternative text, or '' */
    protected function docxVml( DOMElement $pict )
    {
        $data = $pict->getElementsByTagNameNS( self::NS_V, 'imagedata' )->item( 0 );
        if ( !$data )
            return '';
        $alt = trim( $data->getAttributeNS( self::NS_O, 'title' ) );
        $shape = $data->parentNode;
        if ( $alt === '' && $shape instanceof DOMElement )
            $alt = trim( $shape->getAttribute( 'alt' ) );
        return self::imgHtml( $this->imageFromRel( $data->getAttributeNS( self::NS_R, 'id' ) ), $alt );
    }

    /** @return string */
    protected static function imgHtml( $key, $alt )
    {
        if ( $key !== null )
            return '<img src="' . self::e( $key ) . '" alt="' . self::e( $alt ) . '">';
        return $alt !== '' ? self::e( $alt ) : '';
    }

    /** @return string|null the key of the image a relationship of the current part points to */
    protected function imageFromRel( $id )
    {
        if ( $id === '' || !isset( $this->rels[$id] ) || $this->rels[$id]['external'] || $this->rels[$id]['target'] === null )
            return null;
        return $this->addImage( $this->entry( $this->rels[$id]['target'] ) );
    }

    /** @return string HTML table */
    protected function docxTable( DOMElement $table )
    {
        $rows = array();
        $headRows = 0;
        $inHead = true;
        $origins = array();
        foreach ( self::children( $table, self::NS_W, 'tr' ) as $tr )
        {
            $trPr = self::child( $tr, self::NS_W, 'trPr' );
            $header = $trPr && ( $flag = self::child( $trPr, self::NS_W, 'tblHeader' ) ) && self::onOff( $flag );
            if ( !$header )
                $inHead = false;
            elseif ( $inHead )
                $headRows++;
            $column = 0;
            if ( $trPr && ( $before = self::child( $trPr, self::NS_W, 'gridBefore' ) ) )
                $column += max( 0, (int)$before->getAttributeNS( self::NS_W, 'val' ) );
            $cells = array();
            $cellNodes = array();
            foreach ( self::children( $tr, self::NS_W ) as $child )
            {
                if ( $child->localName === 'tc' )
                    $cellNodes[] = $child;
                elseif ( $child->localName === 'sdt' && ( $content = self::child( $child, self::NS_W, 'sdtContent' ) ) )
                    $cellNodes = array_merge( $cellNodes, self::children( $content, self::NS_W, 'tc' ) );
            }
            foreach ( $cellNodes as $tc )
            {
                $tcPr = self::child( $tc, self::NS_W, 'tcPr' );
                $span = 1;
                $merge = null;
                if ( $tcPr )
                {
                    $gridSpan = self::child( $tcPr, self::NS_W, 'gridSpan' );
                    if ( $gridSpan )
                        $span = max( 1, min( 1000, (int)$gridSpan->getAttributeNS( self::NS_W, 'val' ) ) );
                    $merge = self::child( $tcPr, self::NS_W, 'vMerge' );
                }
                if ( $merge && strtolower( $merge->getAttributeNS( self::NS_W, 'val' ) ) !== 'restart' )
                {
                    if ( isset( $origins[$column] ) )
                    {
                        list( $r, $k ) = $origins[$column];
                        $rows[$r][$k]['rs']++;
                    }
                    $column += $span;
                    continue;
                }
                $cells[] = array( 'html' => self::unwrapSingleParagraph( $this->docxBlocks( $tc ) ), 'cs' => $span, 'rs' => 1 );
                if ( $merge )
                    $origins[$column] = array( count( $rows ), count( $cells ) - 1 );
                else
                    unset( $origins[$column] );
                $column += $span;
            }
            $rows[] = $cells;
        }
        if ( !$rows )
            return '';
        $html = '<table>';
        foreach ( $rows as $index => $cells )
        {
            if ( $index === 0 && $headRows > 0 )
                $html .= '<thead>';
            if ( $index === $headRows )
                $html .= '<tbody>';
            $tag = $index < $headRows ? 'th' : 'td';
            $html .= '<tr>';
            foreach ( $cells as $cell )
            {
                $html .= '<' . $tag . ( $cell['cs'] > 1 ? ' colspan="' . $cell['cs'] . '"' : '' )
                       . ( $cell['rs'] > 1 ? ' rowspan="' . $cell['rs'] . '"' : '' ) . '>' . $cell['html'] . '</' . $tag . '>';
            }
            $html .= '</tr>';
            if ( $index === $headRows - 1 )
                $html .= '</thead>';
        }
        if ( $headRows < count( $rows ) )
            $html .= '</tbody>';
        return $html . '</table>';
    }

    /** @return int the number a footnote or endnote is shown with, 0 for a separator */
    protected function noteNumber( $kind, $id )
    {
        if ( $id === '' || !isset( $this->noteParts[$kind]['nodes'][$id] ) )
            return 0;
        $type = $this->noteParts[$kind]['nodes'][$id]->getAttributeNS( self::NS_W, 'type' );
        if ( $type !== '' && $type !== 'normal' )
            return 0;
        $key = $kind . ':' . $id;
        if ( !isset( $this->noteOrder[$key] ) )
            $this->noteOrder[$key] = count( $this->noteOrder ) + 1;
        return $this->noteOrder[$key];
    }

    /** @return string the notes referred to, as a final ordered list */
    protected function docxNotes()
    {
        if ( !$this->noteOrder )
            return '';
        $items = '';
        $saved = $this->rels;
        for ( $i = 0; $i < count( $this->noteOrder ) && $i < 10000; $i++ )
        {
            $keys = array_keys( $this->noteOrder );
            list( $kind, $id ) = explode( ':', $keys[$i], 2 );
            $this->rels = $this->noteParts[$kind]['rels'];
            $items .= '<li>' . self::unwrapSingleParagraph( $this->docxBlocks( $this->noteParts[$kind]['nodes'][$id] ) ) . '</li>';
        }
        $this->rels = $saved;
        return '<hr><ol>' . $items . '</ol>';
    }

    /** Title, author ... of an Office Open XML package (docProps/core.xml and docProps/app.xml). */
    protected function ooxmlMeta( array $package )
    {
        $corePart = $this->relByType( $package, '/core-properties' );
        $core = $this->ooxml( $corePart !== null ? $corePart : 'docProps/core.xml', true );
        if ( $core )
        {
            $root = $core->documentElement;
            $this->title = self::nsText( $root, self::NS_DC, 'title' );
            $this->meta['author'] = self::nsText( $root, self::NS_DC, 'creator' );
            $this->meta['keywords'] = self::splitKeywords( self::nsText( $root, self::NS_CP, 'keywords' ) );
            $this->meta['description'] = self::nsText( $root, self::NS_DC, 'description' );
            if ( $this->meta['description'] === '' )
                $this->meta['description'] = self::nsText( $root, self::NS_DC, 'subject' );
            $this->meta['language'] = self::nsText( $root, self::NS_DC, 'language' );
            $this->meta['created'] = self::timestamp( self::nsText( $root, self::NS_DCTERMS, 'created' ) );
            $this->meta['modified'] = self::timestamp( self::nsText( $root, self::NS_DCTERMS, 'modified' ) );
        }
        $appPart = $this->relByType( $package, '/extended-properties' );
        $app = $this->ooxml( $appPart !== null ? $appPart : 'docProps/app.xml', true );
        if ( $app )
        {
            foreach ( array( 'Pages', 'Slides' ) as $name )
            {
                $value = self::nsText( $app->documentElement, self::NS_EP, $name );
                if ( $value !== '' && ctype_digit( $value ) )
                {
                    $this->meta['pages'] = (int)$value;
                    break;
                }
            }
        }
    }

    /* ------------------------------------------------------------------------------------------------------
     * Spreadsheets
     * ------------------------------------------------------------------------------------------------------ */

    /**
     * @param string $file
     * @param bool $flat FODS (one XML file) instead of ODS
     * @return string HTML
     */
    protected function readOds( $file, $flat )
    {
        if ( $flat )
        {
            $content = self::parseXml( $this->readFile( $file ), 'spreadsheet' );
            $metaDoc = $content;
        }
        else
        {
            $this->openZip( $file );
            try
            {
                $bytes = $this->entry( 'content.xml' );
                if ( $bytes === null )
                    throw new expOdfConverterException( 'convert', 'the spreadsheet has no content.xml' );
                $content = self::parseXml( $bytes, 'spreadsheet' );
                $metaBytes = $this->entry( 'meta.xml' );
                $metaDoc = $metaBytes !== null ? self::parseXml( $metaBytes, 'metadata' ) : null;
            }
            finally
            {
                $this->closeZip();
            }
        }
        $spreadsheet = $content->getElementsByTagNameNS( self::NS_OFFICE, 'spreadsheet' )->item( 0 );
        $sheets = $spreadsheet ? $this->odsSheets( $spreadsheet ) : array();
        if ( $metaDoc )
            $this->odfMeta( $metaDoc );
        if ( $this->title === '' && $sheets )
            $this->title = $sheets[0]['name'];
        return $this->renderSheets( $sheets );
    }

    /** @return array the sheets of an OpenDocument spreadsheet */
    protected function odsSheets( DOMElement $spreadsheet )
    {
        $sheets = array();
        foreach ( self::children( $spreadsheet, self::NS_TABLE, 'table' ) as $table )
        {
            if ( count( $sheets ) >= self::MAX_SHEETS )
            {
                $this->meta['truncated'] = true;
                break;
            }
            $rows = array();
            $pending = 0;
            $stop = false;
            $this->odsWalkRows( $table, $rows, $pending, $stop );
            $name = trim( $table->getAttributeNS( self::NS_TABLE, 'name' ) );
            $sheets[] = array( 'name' => $name !== '' ? $name : 'Sheet ' . ( count( $sheets ) + 1 ), 'rows' => $rows );
        }
        return $sheets;
    }

    protected function odsWalkRows( DOMElement $parent, array &$rows, &$pending, &$stop )
    {
        foreach ( self::children( $parent, self::NS_TABLE ) as $child )
        {
            if ( $stop )
                return;
            switch ( $child->localName )
            {
                case 'table-row':
                    $this->odsRow( $child, $rows, $pending, $stop );
                    break;
                case 'table-header-rows':
                case 'table-row-group':
                case 'table-rows':
                    $this->odsWalkRows( $child, $rows, $pending, $stop );
                    break;
            }
        }
    }

    protected function odsRow( DOMElement $row, array &$rows, &$pending, &$stop )
    {
        $repeat = max( 1, (int)$row->getAttributeNS( self::NS_TABLE, 'number-rows-repeated' ) );
        $cells = $this->odsCells( $row );
        if ( !$cells )
        {
            // empty rows count only when something follows them: trailing ones (often a million) are dropped
            $pending += $repeat;
            return;
        }
        while ( $pending > 0 )
        {
            if ( count( $rows ) >= self::MAX_ROWS )
            {
                $this->meta['truncated'] = true;
                $stop = true;
                return;
            }
            $rows[] = array();
            $pending--;
        }
        for ( $i = 0; $i < $repeat; $i++ )
        {
            if ( count( $rows ) >= self::MAX_ROWS )
            {
                $this->meta['truncated'] = true;
                $stop = true;
                return;
            }
            $rows[] = $cells;
        }
    }

    /** @return array column => cell of a row, without its empty cells */
    protected function odsCells( DOMElement $row )
    {
        $cells = array();
        $column = 0;
        foreach ( self::children( $row, self::NS_TABLE ) as $cell )
        {
            if ( $cell->localName !== 'table-cell' && $cell->localName !== 'covered-table-cell' )
                continue;
            $repeat = max( 1, (int)$cell->getAttributeNS( self::NS_TABLE, 'number-columns-repeated' ) );
            $covered = $cell->localName === 'covered-table-cell';
            $value = $covered ? null : $this->odsCellValue( $cell );
            if ( $value === null && !$covered )
            {
                $column += $repeat;
                continue;
            }
            for ( $i = 0; $i < $repeat; $i++ )
            {
                if ( $column >= self::MAX_COLUMNS )
                {
                    $this->meta['truncated'] = true;
                    return $cells;
                }
                $cells[$column] = $covered ? array( 'covered' => true ) : $value;
                $column++;
            }
        }
        return $cells;
    }

    /** @return array|null array( v, t, cs, rs ) of a cell, null when it is empty */
    protected function odsCellValue( DOMElement $cell )
    {
        $type = $cell->getAttributeNS( self::NS_OFFICE, 'value-type' );
        $lines = array();
        foreach ( self::children( $cell, self::NS_TEXT ) as $child )
        {
            if ( $child->localName === 'p' || $child->localName === 'h' )
                $lines[] = self::odfText( $child );
            elseif ( $child->localName === 'list' )
                $lines[] = trim( self::odfText( $child ) );
        }
        $text = trim( implode( "\n", $lines ) );
        if ( $text === '' )
        {
            foreach ( array( 'value', 'date-value', 'time-value', 'boolean-value', 'string-value' ) as $attribute )
            {
                $value = $cell->getAttributeNS( self::NS_OFFICE, $attribute );
                if ( $value !== '' )
                {
                    $text = $value;
                    break;
                }
            }
        }
        if ( $text === '' )
            return null;
        $kind = ( $type === '' || $type === 'string' ) ? 's' : ( $type === 'boolean' ? 'b' : 'n' );
        return array( 'v' => $text, 't' => $kind,
                      'cs' => max( 1, (int)$cell->getAttributeNS( self::NS_TABLE, 'number-columns-spanned' ) ),
                      'rs' => max( 1, (int)$cell->getAttributeNS( self::NS_TABLE, 'number-rows-spanned' ) ) );
    }

    /**
     * @param string $file
     * @return string HTML
     */
    protected function readXlsx( $file )
    {
        $this->openZip( $file );
        try
        {
            $package = $this->rels( '' );
            $workbookPart = $this->relByType( $package, '/officeDocument' );
            if ( $workbookPart === null )
                $workbookPart = 'xl/workbook.xml';
            $workbook = $this->ooxml( $workbookPart );
            $workbookRels = $this->rels( $workbookPart );

            $shared = array();
            $sharedPart = $this->relByType( $workbookRels, '/sharedStrings' );
            if ( $sharedPart !== null && ( $sst = $this->ooxml( $sharedPart, true ) ) )
            {
                foreach ( self::children( $sst->documentElement, self::NS_S, 'si' ) as $si )
                    $shared[] = self::xlsxRichText( $si );
            }

            $sheets = array();
            foreach ( $workbook->getElementsByTagNameNS( self::NS_S, 'sheet' ) as $sheet )
            {
                if ( $sheet->getAttribute( 'state' ) === 'veryHidden' )
                    continue;
                if ( count( $sheets ) >= self::MAX_SHEETS )
                {
                    $this->meta['truncated'] = true;
                    break;
                }
                $id = $sheet->getAttributeNS( self::NS_R, 'id' );
                if ( !isset( $workbookRels[$id] ) || $workbookRels[$id]['external'] || $workbookRels[$id]['target'] === null )
                    continue;
                $doc = $this->ooxml( $workbookRels[$id]['target'], true );
                if ( !$doc )
                    continue;
                $name = trim( $sheet->getAttribute( 'name' ) );
                $sheets[] = array( 'name' => $name !== '' ? $name : 'Sheet ' . ( count( $sheets ) + 1 ),
                                   'rows' => $this->xlsxRows( $doc, $shared ) );
            }
            $this->ooxmlMeta( $package );
        }
        finally
        {
            $this->closeZip();
        }
        if ( $this->title === '' && $sheets )
            $this->title = $sheets[0]['name'];
        return $this->renderSheets( $sheets );
    }

    /** @return string the text of a shared or inline string (without phonetic runs) */
    protected static function xlsxRichText( DOMElement $si )
    {
        $text = '';
        foreach ( self::children( $si, self::NS_S ) as $child )
        {
            if ( $child->localName === 't' )
                $text .= $child->textContent;
            elseif ( $child->localName === 'r' )
            {
                foreach ( self::children( $child, self::NS_S, 't' ) as $t )
                    $text .= $t->textContent;
            }
        }
        return $text;
    }

    /** @return array the rows of a worksheet */
    protected function xlsxRows( DOMDocument $doc, array $shared )
    {
        $rows = array();
        $data = $doc->getElementsByTagNameNS( self::NS_S, 'sheetData' )->item( 0 );
        if ( !$data )
            return $rows;
        $rowIndex = -1;
        foreach ( self::children( $data, self::NS_S, 'row' ) as $row )
        {
            $r = $row->getAttribute( 'r' );
            $rowIndex = ( $r !== '' && ctype_digit( $r ) ) ? (int)$r - 1 : $rowIndex + 1;
            if ( $rowIndex >= self::MAX_ROWS )
            {
                $this->meta['truncated'] = true;
                break;
            }
            $column = -1;
            foreach ( self::children( $row, self::NS_S, 'c' ) as $cell )
            {
                $ref = $cell->getAttribute( 'r' );
                $column = $ref !== '' ? self::columnIndex( $ref ) : $column + 1;
                if ( $column >= self::MAX_COLUMNS )
                {
                    $this->meta['truncated'] = true;
                    continue;
                }
                $value = self::xlsxValue( $cell, $shared );
                if ( $value !== null )
                    $rows[$rowIndex][$column] = $value;
            }
        }
        // merged cells
        $covered = 0;
        foreach ( $doc->getElementsByTagNameNS( self::NS_S, 'mergeCell' ) as $merge )
        {
            $parts = explode( ':', strtoupper( $merge->getAttribute( 'ref' ) ) );
            if ( count( $parts ) !== 2 || !preg_match( '/^([A-Z]+)(\d+)$/', $parts[0], $a ) || !preg_match( '/^([A-Z]+)(\d+)$/', $parts[1], $b ) )
                continue;
            $c1 = self::columnIndex( $parts[0] );
            $c2 = min( self::columnIndex( $parts[1] ), self::MAX_COLUMNS - 1 );
            $r1 = (int)$a[2] - 1;
            $r2 = min( (int)$b[2] - 1, self::MAX_ROWS - 1 );
            if ( $r1 < 0 || $c1 < 0 || $r1 >= self::MAX_ROWS || $c1 >= self::MAX_COLUMNS || $r2 < $r1 || $c2 < $c1 )
                continue;
            $origin = isset( $rows[$r1][$c1] ) && isset( $rows[$r1][$c1]['v'] ) ? $rows[$r1][$c1] : array( 'v' => '', 't' => 's' );
            $origin['cs'] = $c2 - $c1 + 1;
            $origin['rs'] = $r2 - $r1 + 1;
            $rows[$r1][$c1] = $origin;
            for ( $r = $r1; $r <= $r2; $r++ )
            {
                for ( $c = $c1; $c <= $c2; $c++ )
                {
                    if ( $r === $r1 && $c === $c1 )
                        continue;
                    if ( ++$covered > self::MAX_COVERED )
                        break 3;
                    $rows[$r][$c] = array( 'covered' => true );
                }
            }
        }
        return $rows;
    }

    /** @return array|null array( v, t ) of a worksheet cell, null when it is empty */
    protected static function xlsxValue( DOMElement $cell, array $shared )
    {
        $type = $cell->getAttribute( 't' );
        $v = self::child( $cell, self::NS_S, 'v' );
        $value = $v ? $v->textContent : null;
        switch ( $type )
        {
            case 's':
                $text = $value !== null && isset( $shared[(int)$value] ) ? $shared[(int)$value] : '';
                $kind = 's';
                break;
            case 'inlineStr':
                $is = self::child( $cell, self::NS_S, 'is' );
                $text = $is ? self::xlsxRichText( $is ) : '';
                $kind = 's';
                break;
            case 'b':
                if ( $value === null || $value === '' )
                    return null;
                $text = $value === '1' || strtolower( $value ) === 'true' ? 'TRUE' : 'FALSE';
                $kind = 'b';
                break;
            case 'str':
            case 'e':
                $text = (string)$value;
                $kind = 's';
                break;
            default:
                if ( $value === null || trim( $value ) === '' )
                    return null;
                $text = self::numberText( trim( $value ) );
                $kind = 'n';
        }
        if ( trim( $text ) === '' )
            return null;
        return array( 'v' => $text, 't' => $kind );
    }

    /** @return string a stored number as it is shown, without floating point noise */
    protected static function numberText( $value )
    {
        if ( preg_match( '/^-?\d{1,15}$/', $value ) || !is_numeric( $value ) )
            return $value;
        return sprintf( '%.15G', (float)$value );
    }

    /** @return int the 0 based column of a cell reference such as "AB12" */
    protected static function columnIndex( $reference )
    {
        if ( !preg_match( '/^\$?([A-Za-z]{1,3})/', $reference, $m ) )
            return -1;
        $index = 0;
        foreach ( str_split( strtoupper( $m[1] ) ) as $letter )
            $index = $index * 26 + ( ord( $letter ) - 64 );
        return $index - 1;
    }

    /**
     * @param string $file
     * @param string $ext csv or tsv
     * @return string HTML
     */
    protected function readCsv( $file, $ext )
    {
        $text = self::toUtf8( $this->readFile( $file, self::MAX_ENTRY ) );
        $delimiter = self::detectDelimiter( $text, $ext === 'tsv' ? "\t" : ',' );
        $handle = fopen( 'php://memory', 'w+b' );
        fwrite( $handle, $text );
        rewind( $handle );
        $rows = array();
        $index = 0;
        while ( ( $fields = fgetcsv( $handle, null, $delimiter, '"', '' ) ) !== false )
        {
            if ( $index >= self::MAX_ROWS )
            {
                $this->meta['truncated'] = true;
                break;
            }
            $cells = array();
            foreach ( $fields as $column => $field )
            {
                if ( $column >= self::MAX_COLUMNS )
                {
                    $this->meta['truncated'] = true;
                    break;
                }
                $field = (string)$field;
                if ( trim( $field ) === '' )
                    continue;
                $cells[$column] = array( 'v' => $field, 't' => is_numeric( trim( $field ) ) ? 'n' : 's' );
            }
            $rows[$index++] = $cells;
        }
        fclose( $handle );
        $name = pathinfo( $this->originalName( $file ), PATHINFO_FILENAME );
        $this->title = $name;
        return $this->renderSheets( array( array( 'name' => $name !== '' ? $name : 'Sheet 1', 'rows' => $rows ) ) );
    }

    /** @return string the delimiter of a CSV text: comma, semicolon, tab or pipe */
    protected static function detectDelimiter( $text, $default )
    {
        $sample = preg_replace( '/"(?:[^"]|"")*"/', '""', substr( $text, 0, 65536 ) );
        $lines = array_slice( array_values( array_filter( explode( "\n", (string)$sample ), function ( $line ) {
            return trim( $line ) !== '';
        } ) ), 0, 50 );
        $best = $default;
        $bestScore = 0;
        foreach ( array( ',', ';', "\t", '|' ) as $candidate )
        {
            $counts = array();
            foreach ( $lines as $line )
                $counts[] = substr_count( $line, $candidate );
            $frequent = array_count_values( array_filter( $counts ) );
            if ( !$frequent )
                continue;
            arsort( $frequent );
            $mode = key( $frequent );
            $score = current( $frequent ) * 100 + $mode;
            if ( $score > $bestScore || ( $score === $bestScore && $candidate === $default ) )
            {
                $best = $candidate;
                $bestScore = $score;
            }
        }
        return $best;
    }

    /**
     * @param array $sheets array( 'name' => ..., 'rows' => row => column => cell )
     * @return string a heading and a table per sheet
     */
    protected function renderSheets( array $sheets )
    {
        $html = '';
        foreach ( $sheets as $sheet )
        {
            $html .= '<h2>' . self::e( $sheet['name'] ) . '</h2>';
            $width = 0;
            $last = -1;
            foreach ( $sheet['rows'] as $r => $cells )
            {
                foreach ( $cells as $c => $cell )
                {
                    if ( isset( $cell['v'] ) && $cell['v'] !== '' )
                    {
                        $width = max( $width, $c + 1 );
                        $last = max( $last, $r );
                    }
                }
            }
            if ( $width === 0 )
                continue;

            $first = isset( $sheet['rows'][0] ) ? $sheet['rows'][0] : array();
            $nonEmpty = 0;
            $header = $last >= 1;
            for ( $c = 0; $c < $width && $header; $c++ )
            {
                if ( isset( $first[$c]['v'] ) && $first[$c]['v'] !== '' )
                {
                    $nonEmpty++;
                    if ( $first[$c]['t'] !== 's' || is_numeric( trim( $first[$c]['v'] ) ) )
                        $header = false;
                }
            }
            $header = $header && $nonEmpty > 0;

            $html .= '<table>';
            for ( $r = 0; $r <= $last; $r++ )
            {
                if ( $r === 0 )
                    $html .= $header ? '<thead>' : '<tbody>';
                $tag = ( $header && $r === 0 ) ? 'th' : 'td';
                $cells = isset( $sheet['rows'][$r] ) ? $sheet['rows'][$r] : array();
                $html .= '<tr>';
                for ( $c = 0; $c < $width; $c++ )
                {
                    $cell = isset( $cells[$c] ) ? $cells[$c] : null;
                    if ( $cell !== null && !empty( $cell['covered'] ) )
                        continue;
                    $attributes = '';
                    if ( $cell !== null )
                    {
                        $colspan = min( isset( $cell['cs'] ) ? $cell['cs'] : 1, $width - $c );
                        $rowspan = min( isset( $cell['rs'] ) ? $cell['rs'] : 1, $last - $r + 1 );
                        if ( $colspan > 1 )
                            $attributes .= ' colspan="' . $colspan . '"';
                        if ( $rowspan > 1 )
                            $attributes .= ' rowspan="' . $rowspan . '"';
                    }
                    $text = $cell !== null && isset( $cell['v'] ) ? str_replace( "\n", '<br>', self::e( $cell['v'] ) ) : '';
                    $html .= '<' . $tag . $attributes . '>' . $text . '</' . $tag . '>';
                }
                $html .= '</tr>';
                if ( $r === 0 && $header )
                    $html .= '</thead><tbody>';
            }
            $html .= '</tbody></table>';
        }
        return $html;
    }

    /* ------------------------------------------------------------------------------------------------------
     * Presentations
     * ------------------------------------------------------------------------------------------------------ */

    /**
     * @param string $file
     * @param bool $flat FODP (one XML file) instead of ODP
     * @return string HTML
     */
    protected function readOdp( $file, $flat )
    {
        if ( $flat )
        {
            $content = self::parseXml( $this->readFile( $file ), 'presentation' );
            $metaDoc = $content;
        }
        else
        {
            $this->openZip( $file );
            $bytes = $this->entry( 'content.xml' );
            if ( $bytes === null )
            {
                $this->closeZip();
                throw new expOdfConverterException( 'convert', 'the presentation has no content.xml' );
            }
            $content = self::parseXml( $bytes, 'presentation' );
            $metaBytes = $this->entry( 'meta.xml' );
            $metaDoc = $metaBytes !== null ? self::parseXml( $metaBytes, 'metadata' ) : null;
        }
        try
        {
            $this->odfLoadStyles( $content );
            $presentation = $content->getElementsByTagNameNS( self::NS_OFFICE, 'presentation' )->item( 0 );
            $html = '';
            $firstTitle = '';
            $count = 0;
            if ( $presentation )
            {
                foreach ( self::children( $presentation, self::NS_DRAW, 'page' ) as $page )
                {
                    if ( $count >= self::MAX_SLIDES )
                    {
                        $this->meta['truncated'] = true;
                        break;
                    }
                    $count++;
                    $title = '';
                    $body = '';
                    $this->odpShapes( $page, $title, $body );
                    $notes = '';
                    $notesElement = self::child( $page, self::NS_PRESENTATION, 'notes' );
                    if ( $notesElement )
                    {
                        foreach ( $notesElement->getElementsByTagNameNS( self::NS_DRAW, 'frame' ) as $frame )
                        {
                            if ( $frame->getAttributeNS( self::NS_PRESENTATION, 'class' ) !== 'notes' )
                                continue;
                            $box = self::child( $frame, self::NS_DRAW, 'text-box' );
                            if ( $box )
                                $notes .= $this->odfBlocks( $box );
                        }
                    }
                    if ( $firstTitle === '' )
                        $firstTitle = $title;
                    $html .= '<h2>' . self::e( $title !== '' ? $title : 'Slide ' . $count ) . '</h2>' . $body
                           . ( self::nonBlank( $notes ) ? '<blockquote>' . $notes . '</blockquote>' : '' );
                }
            }
        }
        finally
        {
            $this->closeZip();
        }
        if ( $metaDoc )
            $this->odfMeta( $metaDoc );
        $this->meta['pages'] = $count;
        if ( $this->title === '' )
            $this->title = $firstTitle;
        return $html;
    }

    /** Collects the title and the content of the shapes of a slide (or of a group). */
    protected function odpShapes( DOMElement $container, &$title, &$body )
    {
        foreach ( self::children( $container, self::NS_DRAW ) as $shape )
        {
            switch ( $shape->localName )
            {
                case 'frame':
                    $class = $shape->getAttributeNS( self::NS_PRESENTATION, 'class' );
                    if ( in_array( $class, array( 'page-number', 'footer', 'date-time', 'header', 'handout', 'page' ), true ) )
                        break;
                    $box = self::child( $shape, self::NS_DRAW, 'text-box' );
                    if ( $box && $class === 'title' && $title === '' )
                    {
                        $title = trim( preg_replace( '/\s+/u', ' ', self::odfText( $box ) ) );
                        if ( $title !== '' )
                            break;
                    }
                    if ( $box )
                        $body .= $this->odfBlocks( $box );
                    $image = self::child( $shape, self::NS_DRAW, 'image' );
                    if ( $image && !$box )
                    {
                        $img = $this->odfImage( $image, self::frameAlt( $shape ) );
                        if ( $img !== '' )
                            $body .= '<p>' . $img . '</p>';
                    }
                    $table = self::child( $shape, self::NS_TABLE, 'table' );
                    if ( $table )
                        $body .= $this->odfTable( $table );
                    break;
                case 'g':
                    $this->odpShapes( $shape, $title, $body );
                    break;
                case 'custom-shape':
                case 'rect':
                case 'ellipse':
                case 'circle':
                case 'polygon':
                case 'polyline':
                case 'regular-polygon':
                case 'path':
                case 'caption':
                    $body .= $this->odfBlocks( $shape );
                    break;
            }
        }
    }

    /**
     * @param string $file
     * @return string HTML
     */
    protected function readPptx( $file )
    {
        $this->openZip( $file );
        try
        {
            $package = $this->rels( '' );
            $presentationPart = $this->relByType( $package, '/officeDocument' );
            if ( $presentationPart === null )
                $presentationPart = 'ppt/presentation.xml';
            $presentation = $this->ooxml( $presentationPart );
            $presentationRels = $this->rels( $presentationPart );
            $slides = array();
            foreach ( $presentation->getElementsByTagNameNS( self::NS_P, 'sldId' ) as $slide )
            {
                $id = $slide->getAttributeNS( self::NS_R, 'id' );
                if ( isset( $presentationRels[$id] ) && !$presentationRels[$id]['external'] && $presentationRels[$id]['target'] !== null )
                    $slides[] = $presentationRels[$id]['target'];
            }
            $html = '';
            $firstTitle = '';
            foreach ( $slides as $index => $part )
            {
                if ( $index >= self::MAX_SLIDES )
                {
                    $this->meta['truncated'] = true;
                    break;
                }
                $doc = $this->ooxml( $part, true );
                if ( !$doc )
                    continue;
                $this->rels = $this->rels( $part );
                $title = '';
                $body = '';
                $tree = $doc->getElementsByTagNameNS( self::NS_P, 'spTree' )->item( 0 );
                if ( $tree )
                    $this->pptxShapes( $tree, $title, $body );
                $notes = '';
                $notesPart = $this->relByType( $this->rels, '/notesSlide' );
                if ( $notesPart !== null && ( $notesDoc = $this->ooxml( $notesPart, true ) ) )
                {
                    $this->rels = $this->rels( $notesPart );
                    $notes = $this->pptxNotes( $notesDoc );
                }
                if ( $firstTitle === '' )
                    $firstTitle = $title;
                $html .= '<h2>' . self::e( $title !== '' ? $title : 'Slide ' . ( $index + 1 ) ) . '</h2>' . $body
                       . ( self::nonBlank( $notes ) ? '<blockquote>' . $notes . '</blockquote>' : '' );
            }
            $this->ooxmlMeta( $package );
            if ( !$this->meta['pages'] )
                $this->meta['pages'] = count( $slides );
        }
        finally
        {
            $this->closeZip();
        }
        if ( $this->title === '' )
            $this->title = $firstTitle;
        return $html;
    }

    /** Collects the title and the content of the shapes of a slide (or of a group). */
    protected function pptxShapes( DOMElement $container, &$title, &$body )
    {
        foreach ( self::children( $container ) as $shape )
        {
            if ( $shape->namespaceURI === self::NS_MC && $shape->localName === 'AlternateContent' )
            {
                $choice = self::alternateContent( $shape, true );
                if ( $choice )
                    $this->pptxShapes( $choice, $title, $body );
                continue;
            }
            if ( $shape->namespaceURI !== self::NS_P )
                continue;
            switch ( $shape->localName )
            {
                case 'sp':
                    $placeholder = $shape->getElementsByTagNameNS( self::NS_P, 'ph' )->item( 0 );
                    $type = $placeholder ? $placeholder->getAttribute( 'type' ) : null;
                    $text = self::child( $shape, self::NS_P, 'txBody' );
                    if ( !$text )
                        break;
                    if ( $type === 'title' || $type === 'ctrTitle' )
                    {
                        $plain = self::pptxPlainText( $text );
                        if ( $title === '' )
                            $title = $plain;
                        elseif ( $plain !== '' )
                            $body .= '<p>' . self::e( $plain ) . '</p>';
                        break;
                    }
                    if ( in_array( $type, array( 'sldNum', 'dt', 'ftr', 'hdr', 'sldImg' ), true ) )
                        break;
                    $isBody = $placeholder !== null && in_array( (string)$type, array( '', 'body', 'obj' ), true );
                    $body .= $this->pptxTextBody( $text, $isBody );
                    break;
                case 'pic':
                    $properties = $shape->getElementsByTagNameNS( self::NS_P, 'cNvPr' )->item( 0 );
                    $alt = $properties ? trim( $properties->getAttribute( 'descr' ) ) : '';
                    if ( $alt === '' && $properties )
                        $alt = trim( $properties->getAttribute( 'title' ) );
                    $blip = $shape->getElementsByTagNameNS( self::NS_A, 'blip' )->item( 0 );
                    $img = self::imgHtml( $blip ? $this->imageFromRel( $blip->getAttributeNS( self::NS_R, 'embed' ) ) : null, $alt );
                    if ( $img !== '' )
                        $body .= '<p>' . $img . '</p>';
                    break;
                case 'grpSp':
                    $this->pptxShapes( $shape, $title, $body );
                    break;
                case 'graphicFrame':
                    $table = $shape->getElementsByTagNameNS( self::NS_A, 'tbl' )->item( 0 );
                    if ( $table )
                        $body .= $this->pptxTable( $table );
                    break;
            }
        }
    }

    /** @return string the text of a text body, its paragraphs joined by spaces */
    protected static function pptxPlainText( DOMElement $text )
    {
        $parts = array();
        foreach ( self::children( $text, self::NS_A, 'p' ) as $p )
        {
            $line = '';
            foreach ( $p->getElementsByTagNameNS( self::NS_A, 't' ) as $t )
                $line .= $t->textContent;
            if ( trim( $line ) !== '' )
                $parts[] = trim( $line );
        }
        return trim( preg_replace( '/\s+/u', ' ', implode( ' ', $parts ) ) );
    }

    /**
     * @param DOMElement $text p:txBody or a:txBody
     * @param bool $isBody a body placeholder: its paragraphs are bulleted unless they say otherwise
     * @return string HTML
     */
    protected function pptxTextBody( DOMElement $text, $isBody )
    {
        $paragraphs = self::children( $text, self::NS_A, 'p' );
        $filled = 0;
        foreach ( $paragraphs as $p )
        {
            if ( trim( $p->textContent ) !== '' )
                $filled++;
        }
        $out = '';
        $items = array();
        foreach ( $paragraphs as $p )
        {
            $pPr = self::child( $p, self::NS_A, 'pPr' );
            $level = $pPr ? max( 0, min( 8, (int)$pPr->getAttribute( 'lvl' ) ) ) : 0;
            $bullet = null;
            if ( $pPr )
            {
                if ( self::child( $pPr, self::NS_A, 'buNone' ) )
                    $bullet = 'none';
                elseif ( self::child( $pPr, self::NS_A, 'buAutoNum' ) )
                    $bullet = 'ol';
                elseif ( self::child( $pPr, self::NS_A, 'buChar' ) || self::child( $pPr, self::NS_A, 'buBlip' ) )
                    $bullet = 'ul';
            }
            if ( $bullet === null )
                $bullet = ( $isBody && $filled > 1 ) ? 'ul' : 'none';
            $segments = array();
            foreach ( self::children( $p, self::NS_A ) as $child )
            {
                if ( $child->localName === 'br' )
                {
                    $segments[] = array( 'f' => 0, 'h' => null, 'raw' => '<br>' );
                    continue;
                }
                if ( $child->localName !== 'r' && $child->localName !== 'fld' )
                    continue;
                $rPr = self::child( $child, self::NS_A, 'rPr' );
                $flags = 0;
                $href = null;
                if ( $rPr )
                {
                    if ( in_array( $rPr->getAttribute( 'b' ), array( '1', 'true' ), true ) )
                        $flags |= self::F_BOLD;
                    if ( in_array( $rPr->getAttribute( 'i' ), array( '1', 'true' ), true ) )
                        $flags |= self::F_ITALIC;
                    $underline = $rPr->getAttribute( 'u' );
                    if ( $underline !== '' && $underline !== 'none' )
                        $flags |= self::F_UNDERLINE;
                    $strike = $rPr->getAttribute( 'strike' );
                    if ( $strike !== '' && $strike !== 'noStrike' )
                        $flags |= self::F_STRIKE;
                    $baseline = (int)$rPr->getAttribute( 'baseline' );
                    if ( $baseline > 0 )
                        $flags |= self::F_SUP;
                    elseif ( $baseline < 0 )
                        $flags |= self::F_SUB;
                    $link = self::child( $rPr, self::NS_A, 'hlinkClick' );
                    if ( $link )
                    {
                        $id = $link->getAttributeNS( self::NS_R, 'id' );
                        if ( $id !== '' && isset( $this->rels[$id] ) && $this->rels[$id]['external'] )
                            $href = self::safeHref( $this->rels[$id]['target'] );
                    }
                }
                $t = self::child( $child, self::NS_A, 't' );
                if ( $t )
                    $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => $t->textContent );
            }
            $html = self::segmentsToHtml( $segments );
            if ( !self::nonBlank( $html ) )
                continue;
            if ( $bullet !== 'none' )
            {
                $items[] = array( 'key' => 'a', 'level' => $level, 'tag' => $bullet, 'html' => $html );
                continue;
            }
            $out .= self::renderList( $items ) . '<p>' . $html . '</p>';
            $items = array();
        }
        return $out . self::renderList( $items );
    }

    /** @return string the speaker notes of a notes slide */
    protected function pptxNotes( DOMDocument $doc )
    {
        $html = '';
        foreach ( $doc->getElementsByTagNameNS( self::NS_P, 'sp' ) as $shape )
        {
            $placeholder = $shape->getElementsByTagNameNS( self::NS_P, 'ph' )->item( 0 );
            if ( !$placeholder || $placeholder->getAttribute( 'type' ) !== 'body' )
                continue;
            $text = self::child( $shape, self::NS_P, 'txBody' );
            if ( $text )
                $html .= $this->pptxTextBody( $text, false );
        }
        return $html;
    }

    /** @return string HTML table of a DrawingML table */
    protected function pptxTable( DOMElement $table )
    {
        $properties = self::child( $table, self::NS_A, 'tblPr' );
        $firstRow = $properties && in_array( $properties->getAttribute( 'firstRow' ), array( '1', 'true' ), true );
        $rows = '';
        $index = 0;
        foreach ( self::children( $table, self::NS_A, 'tr' ) as $tr )
        {
            $tag = ( $firstRow && $index === 0 ) ? 'th' : 'td';
            $cells = '';
            foreach ( self::children( $tr, self::NS_A, 'tc' ) as $tc )
            {
                if ( in_array( $tc->getAttribute( 'hMerge' ), array( '1', 'true' ), true ) || in_array( $tc->getAttribute( 'vMerge' ), array( '1', 'true' ), true ) )
                    continue;
                $text = self::child( $tc, self::NS_A, 'txBody' );
                $content = $text ? self::unwrapSingleParagraph( $this->pptxTextBody( $text, false ) ) : '';
                $colspan = (int)$tc->getAttribute( 'gridSpan' );
                $rowspan = (int)$tc->getAttribute( 'rowSpan' );
                $cells .= '<' . $tag . ( $colspan > 1 ? ' colspan="' . $colspan . '"' : '' ) . ( $rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '' ) . '>'
                        . $content . '</' . $tag . '>';
            }
            $row = '<tr>' . $cells . '</tr>';
            $rows .= ( $tag === 'th' ) ? '<thead>' . $row . '</thead><tbody>' : $row;
            if ( $index === 0 && $tag !== 'th' )
                $rows = '<tbody>' . $rows;
            $index++;
        }
        return $index ? '<table>' . $rows . '</tbody></table>' : '';
    }

    /* ------------------------------------------------------------------------------------------------------
     * OpenDocument text helpers (presentations, spreadsheets)
     * ------------------------------------------------------------------------------------------------------ */

    /** Reads the formatting and list kinds of the automatic and common styles of an OpenDocument file. */
    protected function odfLoadStyles( DOMDocument $doc )
    {
        foreach ( array( 'automatic-styles', 'styles' ) as $container )
        {
            foreach ( $doc->getElementsByTagNameNS( self::NS_OFFICE, $container ) as $styles )
            {
                foreach ( self::children( $styles ) as $style )
                {
                    $name = $style->getAttributeNS( self::NS_STYLE, 'name' );
                    if ( $name === '' )
                        continue;
                    if ( $style->namespaceURI === self::NS_STYLE && $style->localName === 'style' )
                    {
                        $properties = self::child( $style, self::NS_STYLE, 'text-properties' );
                        $this->odfStyleFlags[$name] = $properties ? self::odfTextFlags( $properties ) : 0;
                    }
                    elseif ( $style->namespaceURI === self::NS_TEXT && $style->localName === 'list-style' )
                    {
                        $first = self::children( $style );
                        $this->odfListTags[$name] = ( $first && $first[0]->localName === 'list-level-style-number' ) ? 'ol' : 'ul';
                    }
                }
            }
        }
    }

    /** @return int formatting flags of style:text-properties */
    protected static function odfTextFlags( DOMElement $properties )
    {
        $flags = 0;
        $weight = $properties->getAttributeNS( self::NS_FO, 'font-weight' );
        if ( $weight === 'bold' || ( is_numeric( $weight ) && (int)$weight >= 600 ) )
            $flags |= self::F_BOLD;
        $style = $properties->getAttributeNS( self::NS_FO, 'font-style' );
        if ( $style === 'italic' || $style === 'oblique' )
            $flags |= self::F_ITALIC;
        $underline = $properties->getAttributeNS( self::NS_STYLE, 'text-underline-style' );
        if ( $underline !== '' && $underline !== 'none' )
            $flags |= self::F_UNDERLINE;
        $strike = $properties->getAttributeNS( self::NS_STYLE, 'text-line-through-style' );
        if ( $strike !== '' && $strike !== 'none' )
            $flags |= self::F_STRIKE;
        $position = trim( $properties->getAttributeNS( self::NS_STYLE, 'text-position' ) );
        if ( $position !== '' )
        {
            if ( strpos( $position, 'super' ) === 0 || ( is_numeric( rtrim( strtok( $position, ' ' ), '%' ) ) && (float)$position > 0 ) )
                $flags |= self::F_SUP;
            elseif ( strpos( $position, 'sub' ) === 0 || ( is_numeric( rtrim( strtok( $position, ' ' ), '%' ) ) && (float)$position < 0 ) )
                $flags |= self::F_SUB;
        }
        return $flags;
    }

    /** @return string plain text of an OpenDocument element (spaces, tabs and line breaks expanded) */
    protected static function odfText( DOMNode $node )
    {
        $text = '';
        foreach ( $node->childNodes as $child )
        {
            if ( $child instanceof DOMText )
            {
                $text .= $child->data;
                continue;
            }
            if ( !$child instanceof DOMElement )
                continue;
            if ( $child->namespaceURI === self::NS_TEXT )
            {
                switch ( $child->localName )
                {
                    case 's':
                        $text .= str_repeat( ' ', max( 1, min( 1000, (int)$child->getAttributeNS( self::NS_TEXT, 'c' ) ) ) );
                        continue 2;
                    case 'tab':
                        $text .= ' ';
                        continue 2;
                    case 'line-break':
                        $text .= "\n";
                        continue 2;
                    case 'note':
                    case 'tracked-changes':
                        continue 2;
                    case 'p':
                    case 'h':
                        $text .= ( $text !== '' ? "\n" : '' ) . self::odfText( $child );
                        continue 2;
                }
            }
            if ( $child->namespaceURI === self::NS_OFFICE && $child->localName === 'annotation' )
                continue;
            if ( $child->namespaceURI === self::NS_DRAW )
                continue;
            $text .= self::odfText( $child );
        }
        return $text;
    }

    /** @return string HTML blocks of the paragraphs, headings, lists and tables in an OpenDocument container */
    protected function odfBlocks( DOMElement $container )
    {
        $html = '';
        foreach ( self::children( $container ) as $child )
        {
            if ( $child->namespaceURI === self::NS_TEXT )
            {
                switch ( $child->localName )
                {
                    case 'p':
                        $inline = $this->odfInline( $child );
                        if ( self::nonBlank( $inline ) )
                            $html .= '<p>' . $inline . '</p>';
                        break;
                    case 'h':
                        $inline = $this->odfInline( $child );
                        if ( self::nonBlank( $inline ) )
                            $html .= '<h3>' . $inline . '</h3>';
                        break;
                    case 'list':
                        $html .= $this->odfList( $child );
                        break;
                    case 'section':
                        $html .= $this->odfBlocks( $child );
                        break;
                }
            }
            elseif ( $child->namespaceURI === self::NS_TABLE && $child->localName === 'table' )
                $html .= $this->odfTable( $child );
        }
        return $html;
    }

    /** @return string HTML list of an OpenDocument list */
    protected function odfList( DOMElement $list, $depth = 0 )
    {
        if ( $depth > 20 )
            return '';
        $name = $list->getAttributeNS( self::NS_TEXT, 'style-name' );
        $tag = isset( $this->odfListTags[$name] ) ? $this->odfListTags[$name] : 'ul';
        $items = '';
        foreach ( self::children( $list, self::NS_TEXT ) as $item )
        {
            if ( $item->localName !== 'list-item' && $item->localName !== 'list-header' )
                continue;
            $lines = array();
            $nested = '';
            foreach ( self::children( $item, self::NS_TEXT ) as $child )
            {
                if ( $child->localName === 'p' || $child->localName === 'h' )
                {
                    $inline = $this->odfInline( $child );
                    if ( self::nonBlank( $inline ) )
                        $lines[] = $inline;
                }
                elseif ( $child->localName === 'list' )
                    $nested .= $this->odfList( $child, $depth + 1 );
            }
            if ( $lines || $nested !== '' )
                $items .= '<li>' . implode( '<br>', $lines ) . $nested . '</li>';
        }
        return $items !== '' ? "<$tag>$items</$tag>" : '';
    }

    /** @return string inline HTML of an OpenDocument paragraph */
    protected function odfInline( DOMElement $paragraph )
    {
        $name = $paragraph->getAttributeNS( self::NS_TEXT, 'style-name' );
        $segments = array();
        $this->odfSegments( $paragraph, isset( $this->odfStyleFlags[$name] ) ? $this->odfStyleFlags[$name] : 0, null, $segments );
        return self::segmentsToHtml( $segments );
    }

    protected function odfSegments( DOMNode $node, $flags, $href, array &$segments, $depth = 0 )
    {
        if ( $depth > 50 )
            return;
        foreach ( $node->childNodes as $child )
        {
            if ( $child instanceof DOMText )
            {
                $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => $child->data );
                continue;
            }
            if ( !$child instanceof DOMElement )
                continue;
            if ( $child->namespaceURI === self::NS_TEXT )
            {
                switch ( $child->localName )
                {
                    case 'span':
                        $name = $child->getAttributeNS( self::NS_TEXT, 'style-name' );
                        $this->odfSegments( $child, $flags | ( isset( $this->odfStyleFlags[$name] ) ? $this->odfStyleFlags[$name] : 0 ), $href, $segments, $depth + 1 );
                        continue 2;
                    case 'a':
                        $target = self::safeHref( $child->getAttributeNS( self::NS_XLINK, 'href' ) );
                        $this->odfSegments( $child, $flags, $target !== null ? $target : $href, $segments, $depth + 1 );
                        continue 2;
                    case 's':
                        $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => str_repeat( ' ', max( 1, min( 1000, (int)$child->getAttributeNS( self::NS_TEXT, 'c' ) ) ) ) );
                        continue 2;
                    case 'tab':
                        $segments[] = array( 'f' => $flags, 'h' => $href, 'text' => ' ' );
                        continue 2;
                    case 'line-break':
                        $segments[] = array( 'f' => 0, 'h' => $href, 'raw' => '<br>' );
                        continue 2;
                    case 'note':
                    case 'bookmark':
                    case 'bookmark-start':
                    case 'bookmark-end':
                    case 'soft-page-break':
                    case 'tracked-changes':
                    case 'change':
                    case 'change-start':
                    case 'change-end':
                        continue 2;
                }
                $this->odfSegments( $child, $flags, $href, $segments, $depth + 1 );
            }
            elseif ( $child->namespaceURI === self::NS_DRAW && $child->localName === 'frame' )
            {
                $image = self::child( $child, self::NS_DRAW, 'image' );
                if ( $image )
                {
                    $img = $this->odfImage( $image, self::frameAlt( $child ) );
                    if ( $img !== '' )
                        $segments[] = array( 'f' => 0, 'h' => null, 'raw' => $img );
                }
            }
        }
    }

    /** @return string the alternative text of a draw:frame */
    protected static function frameAlt( DOMElement $frame )
    {
        foreach ( array( 'desc', 'title' ) as $name )
        {
            $element = self::child( $frame, self::NS_SVG, $name );
            if ( $element && trim( $element->textContent ) !== '' )
                return trim( $element->textContent );
        }
        return '';
    }

    /** @return string an <img> for a draw:image (in the package or embedded as base64), its alt text or '' */
    protected function odfImage( DOMElement $image, $alt )
    {
        $bytes = null;
        $href = $image->getAttributeNS( self::NS_XLINK, 'href' );
        if ( $href !== '' && $this->zip && !preg_match( '/^[a-z][a-z0-9+.-]*:/i', $href ) )
        {
            $part = self::normalizePart( rawurldecode( ltrim( $href, './' ) === $href ? $href : preg_replace( '#^\./#', '', $href ) ) );
            if ( $part !== null )
                $bytes = $this->entry( $part );
        }
        else
        {
            $binary = self::child( $image, self::NS_OFFICE, 'binary-data' );
            if ( $binary && strlen( $binary->textContent ) <= self::MAX_ENTRY * 4 / 3 + 1024 )
            {
                $decoded = base64_decode( preg_replace( '/\s+/', '', $binary->textContent ), true );
                $bytes = $decoded === false ? null : $decoded;
            }
        }
        return self::imgHtml( $bytes !== null ? $this->addImage( $bytes ) : null, $alt );
    }

    /** @return string HTML table of an OpenDocument table (in a slide) */
    protected function odfTable( DOMElement $table )
    {
        $rows = array();
        $this->odfTableRows( $table, false, $rows );
        if ( !$rows )
            return '';
        $html = '<table>';
        $head = false;
        foreach ( $rows as $index => $row )
        {
            if ( $index === 0 && $row['head'] )
            {
                $html .= '<thead>';
                $head = true;
            }
            if ( $head && !$row['head'] )
            {
                $html .= '</thead><tbody>';
                $head = false;
            }
            elseif ( $index === 0 )
                $html .= '<tbody>';
            $html .= '<tr>' . $row['html'] . '</tr>';
        }
        return $html . ( $head ? '</thead>' : '</tbody>' ) . '</table>';
    }

    protected function odfTableRows( DOMElement $parent, $head, array &$rows )
    {
        foreach ( self::children( $parent, self::NS_TABLE ) as $child )
        {
            if ( count( $rows ) >= self::MAX_ROWS )
                return;
            switch ( $child->localName )
            {
                case 'table-header-rows':
                    $this->odfTableRows( $child, true, $rows );
                    break;
                case 'table-row-group':
                case 'table-rows':
                    $this->odfTableRows( $child, $head, $rows );
                    break;
                case 'table-row':
                    $tag = $head ? 'th' : 'td';
                    $cells = '';
                    $count = 0;
                    $filled = false;
                    foreach ( self::children( $child, self::NS_TABLE, 'table-cell' ) as $cell )
                    {
                        $content = self::unwrapSingleParagraph( $this->odfBlocks( $cell ) );
                        $repeat = min( max( 1, (int)$cell->getAttributeNS( self::NS_TABLE, 'number-columns-repeated' ) ), self::MAX_COLUMNS - $count );
                        $colspan = (int)$cell->getAttributeNS( self::NS_TABLE, 'number-columns-spanned' );
                        $rowspan = (int)$cell->getAttributeNS( self::NS_TABLE, 'number-rows-spanned' );
                        $filled = $filled || self::nonBlank( $content );
                        for ( $i = 0; $i < $repeat; $i++ )
                        {
                            $cells .= '<' . $tag . ( $colspan > 1 ? ' colspan="' . $colspan . '"' : '' ) . ( $rowspan > 1 ? ' rowspan="' . $rowspan . '"' : '' ) . '>'
                                    . $content . '</' . $tag . '>';
                            $count++;
                        }
                        if ( $count >= self::MAX_COLUMNS )
                            break;
                    }
                    $repeatRows = $filled ? min( max( 1, (int)$child->getAttributeNS( self::NS_TABLE, 'number-rows-repeated' ) ), self::MAX_ROWS - count( $rows ) ) : 1;
                    if ( $cells !== '' )
                    {
                        for ( $i = 0; $i < $repeatRows; $i++ )
                            $rows[] = array( 'head' => $head, 'html' => $cells, 'filled' => $filled );
                    }
                    break;
            }
        }
        // empty rows at the end are dropped
        while ( $rows && !$rows[count( $rows ) - 1]['filled'] )
            array_pop( $rows );
    }

    /** Title, author ... of an OpenDocument file (meta.xml, or office:meta of a flat file). */
    protected function odfMeta( DOMDocument $doc )
    {
        $meta = $doc->getElementsByTagNameNS( self::NS_OFFICE, 'meta' )->item( 0 );
        if ( !$meta )
            return;
        $this->title = self::nsText( $meta, self::NS_DC, 'title' );
        $this->meta['author'] = self::nsText( $meta, self::NS_DC, 'creator' );
        if ( $this->meta['author'] === '' )
            $this->meta['author'] = self::nsText( $meta, self::NS_META, 'initial-creator' );
        $keywords = array();
        foreach ( $meta->getElementsByTagNameNS( self::NS_META, 'keyword' ) as $keyword )
            $keywords = array_merge( $keywords, self::splitKeywords( $keyword->textContent ) );
        $this->meta['keywords'] = array_values( array_unique( $keywords ) );
        $this->meta['description'] = self::nsText( $meta, self::NS_DC, 'description' );
        if ( $this->meta['description'] === '' )
            $this->meta['description'] = self::nsText( $meta, self::NS_DC, 'subject' );
        $this->meta['language'] = self::nsText( $meta, self::NS_DC, 'language' );
        $this->meta['created'] = self::timestamp( self::nsText( $meta, self::NS_META, 'creation-date' ) );
        $this->meta['modified'] = self::timestamp( self::nsText( $meta, self::NS_DC, 'date' ) );
        $statistic = $meta->getElementsByTagNameNS( self::NS_META, 'document-statistic' )->item( 0 );
        if ( $statistic && ctype_digit( $statistic->getAttributeNS( self::NS_META, 'page-count' ) ) )
            $this->meta['pages'] = (int)$statistic->getAttributeNS( self::NS_META, 'page-count' );
    }

    /* ------------------------------------------------------------------------------------------------------
     * PDF, HTML, Markdown, text
     * ------------------------------------------------------------------------------------------------------ */

    /** @return string the path of pdftotext, '' when it is missing */
    protected static function pdfTool()
    {
        return class_exists( 'expOdfSandbox' ) ? expOdfSandbox::tool( 'pdftotext' ) : '';
    }

    /**
     * The text of a PDF through pdftotext (poppler). The text goes to a file in the work directory: the
     * sandbox keeps only the last megabyte of a command's output.
     *
     * @param string $file
     * @return string HTML
     */
    protected function readPdf( $file )
    {
        $tool = self::pdfTool();
        if ( $tool === '' )
            throw new expOdfConverterException( 'unsupported', 'reading PDF files needs the poppler utilities (pdftotext) or LibreOffice' );
        $path = realpath( $file );
        if ( $path === false )
            throw new expOdfConverterException( 'convert', 'the document cannot be read' );
        $out = $this->dir . '/pdftext-' . bin2hex( random_bytes( 8 ) ) . '.txt';
        $seconds = isset( $this->options['seconds'] ) ? max( 1, (int)$this->options['seconds'] ) : 120;
        $result = expOdfSandbox::run( array( $tool, '-enc', 'UTF-8', '-eol', 'unix', $path, $out ), $seconds );
        if ( !empty( $result['timedout'] ) )
        {
            @unlink( $out );
            throw new expOdfConverterException( 'convert', sprintf( 'pdftotext did not finish within %d seconds', $seconds ) );
        }
        if ( $result['code'] !== 0 || !is_file( $out ) || is_link( $out ) )
        {
            @unlink( $out );
            throw new expOdfConverterException( 'convert', 'pdftotext cannot read the PDF (it may be damaged or encrypted)' );
        }
        if ( filesize( $out ) > self::MAX_ENTRY )
        {
            @unlink( $out );
            throw new expOdfConverterException( 'too_large', 'the text of the PDF is larger than 64 MB' );
        }
        $text = (string)file_get_contents( $out );
        @unlink( $out );
        $text = self::toUtf8( $text );

        $pages = explode( "\f", $text );
        if ( $pages && trim( end( $pages ) ) === '' )
            array_pop( $pages );
        $html = '';
        $firstLine = '';
        foreach ( $pages as $page )
        {
            foreach ( preg_split( '/\n[ \t]*\n+/', $page ) as $paragraph )
            {
                $lines = array_values( array_filter( array_map( 'trim', explode( "\n", $paragraph ) ), 'strlen' ) );
                if ( !$lines )
                    continue;
                if ( $firstLine === '' )
                    $firstLine = $lines[0];
                $html .= '<p>' . self::e( implode( ' ', $lines ) ) . '</p>';
            }
        }

        $info = array();
        $pdfinfo = expOdfSandbox::tool( 'pdfinfo' );
        if ( $pdfinfo !== '' )
        {
            $result = expOdfSandbox::run( array( $pdfinfo, '-enc', 'UTF-8', $path ), 30 );
            if ( $result['code'] === 0 )
            {
                foreach ( explode( "\n", self::toUtf8( $result['stdout'] ) ) as $line )
                {
                    if ( preg_match( '/^([A-Za-z ]+):\s*(.*)$/', $line, $m ) )
                        $info[trim( $m[1] )] = trim( $m[2] );
                }
            }
        }
        $this->title = isset( $info['Title'] ) && $info['Title'] !== '' ? $info['Title'] : mb_substr( $firstLine, 0, 200 );
        $this->meta['author'] = isset( $info['Author'] ) ? $info['Author'] : '';
        $this->meta['keywords'] = isset( $info['Keywords'] ) ? self::splitKeywords( $info['Keywords'] ) : array();
        $this->meta['description'] = isset( $info['Subject'] ) ? $info['Subject'] : '';
        $this->meta['created'] = isset( $info['CreationDate'] ) ? self::timestamp( $info['CreationDate'] ) : 0;
        $this->meta['modified'] = isset( $info['ModDate'] ) ? self::timestamp( $info['ModDate'] ) : 0;
        $this->meta['pages'] = isset( $info['Pages'] ) && ctype_digit( $info['Pages'] ) ? (int)$info['Pages'] : count( $pages );
        return $html;
    }

    /**
     * @param string $file
     * @return string HTML
     */
    protected function readHtml( $file )
    {
        $doc = self::loadHtmlDocument( self::htmlToUtf8( $this->readFile( $file, self::MAX_ENTRY ) ) );
        $title = $doc->getElementsByTagName( 'title' )->item( 0 );
        $this->title = $title ? trim( preg_replace( '/\s+/u', ' ', $title->textContent ) ) : '';
        foreach ( $doc->getElementsByTagName( 'meta' ) as $meta )
        {
            $name = strtolower( trim( $meta->getAttribute( 'name' ) ) );
            $content = trim( $meta->getAttribute( 'content' ) );
            if ( $content === '' )
                continue;
            if ( $name === 'author' || $name === 'dc.creator' )
                $this->meta['author'] = $content;
            elseif ( $name === 'keywords' || $name === 'dc.subject' )
                $this->meta['keywords'] = self::splitKeywords( $content );
            elseif ( $name === 'description' || $name === 'dc.description' )
                $this->meta['description'] = $content;
            elseif ( $name === 'language' || $name === 'dc.language' )
                $this->meta['language'] = $content;
        }
        $root = $doc->documentElement;
        if ( $this->meta['language'] === '' && $root )
            $this->meta['language'] = trim( $root->getAttribute( 'lang' ) !== '' ? $root->getAttribute( 'lang' ) : $root->getAttribute( 'xml:lang' ) );
        $body = $doc->getElementsByTagName( 'body' )->item( 0 );
        if ( !$body )
            $body = $root;
        if ( !$body )
            return '';
        return self::cleanNode( $body, function ( $src ) {
            return $this->dataImage( $src );
        } );
    }

    /**
     * @param string $file
     * @return string HTML
     */
    protected function readMarkdown( $file )
    {
        $text = str_replace( array( "\r\n", "\r" ), "\n", self::toUtf8( $this->readFile( $file, self::MAX_ENTRY ) ) );
        list( $front, $body ) = self::frontMatter( $text );
        foreach ( $front as $key => $value )
        {
            $plain = is_array( $value ) ? implode( ', ', $value ) : $value;
            switch ( strtolower( $key ) )
            {
                case 'title':
                    $this->title = $plain;
                    break;
                case 'description':
                case 'summary':
                    $this->meta['description'] = $plain;
                    break;
                case 'keywords':
                case 'tags':
                    $this->meta['keywords'] = is_array( $value ) ? array_values( array_filter( array_map( 'trim', $value ), 'strlen' ) ) : self::splitKeywords( $value );
                    break;
                case 'author':
                    $this->meta['author'] = $plain;
                    break;
                case 'date':
                case 'created':
                    $this->meta['created'] = self::timestamp( $plain );
                    break;
                case 'modified':
                case 'updated':
                case 'lastmod':
                    $this->meta['modified'] = self::timestamp( $plain );
                    break;
                case 'lang':
                case 'language':
                    $this->meta['language'] = $plain;
                    break;
            }
        }
        $resolver = function ( $src ) {
            return $this->dataImage( $src );
        };
        $engine = isset( $this->options['markdown'] ) ? $this->options['markdown'] : 'auto';
        if ( $engine !== 'own' && class_exists( 'expHelpMarkdownRenderer' ) )
        {
            try
            {
                $none = function () {
                    return null;
                };
                $renderer = new expHelpMarkdownRenderer( array( 'link_resolver' => $none, 'image_resolver' => $none,
                                                                'translate' => function ( $text ) {
                                                                    return $text;
                                                                } ) );
                $result = $renderer->render( $body );
                if ( isset( $result['html'] ) && is_string( $result['html'] ) )
                    return self::cleanHtml( $result['html'], $resolver, self::$kernelMarkdownDropClasses );
            }
            catch ( Throwable $e )
            {
                // the reader's own converter below
            }
        }
        return self::cleanHtml( self::markdownToHtml( $body ), $resolver );
    }

    /**
     * @param string $file
     * @return string HTML
     */
    protected function readText( $file )
    {
        $text = trim( str_replace( array( "\r\n", "\r" ), "\n", self::toUtf8( $this->readFile( $file, self::MAX_ENTRY ) ) ) );
        $html = '';
        foreach ( preg_split( '/\n[ \t]*\n+/', $text ) as $paragraph )
        {
            $lines = array_map( 'rtrim', explode( "\n", $paragraph ) );
            if ( trim( implode( '', $lines ) ) === '' )
                continue;
            $html .= '<p>' . implode( '<br>', array_map( array( __CLASS__, 'e' ), $lines ) ) . '</p>';
        }
        return $html;
    }

    /** @return string|null the key of the image a data: address holds (PNG, JPEG, GIF or WebP up to 10 MB) */
    protected function dataImage( $src )
    {
        $src = trim( (string)$src );
        if ( !preg_match( '#^data:image/(png|jpeg|jpg|gif|webp)((?:;[^,;]*)*),#i', $src, $m ) )
            return null;
        $payload = substr( $src, strlen( $m[0] ) );
        if ( stripos( $m[2], ';base64' ) !== false )
        {
            if ( strlen( $payload ) > self::MAX_DATA_IMAGE * 4 / 3 + 4096 )
                return null;
            $bytes = base64_decode( preg_replace( '/\s+/', '', $payload ), true );
        }
        else
            $bytes = rawurldecode( $payload );
        if ( $bytes === false || strlen( $bytes ) > self::MAX_DATA_IMAGE )
            return null;
        return $this->addImage( $bytes );
    }

    /** @return array( array( key => string|string[] ), string the rest ) of a document with front matter */
    protected static function frontMatter( $text )
    {
        $text = preg_replace( '/^\x{FEFF}/u', '', $text );
        if ( !preg_match( '/^---[ \t]*\n(.*?)\n(?:---|\.\.\.)[ \t]*(?:\n|$)/s', $text, $m ) || !preg_match( '/^[A-Za-z_][\w-]*\s*:/m', $m[1] ) )
            return array( array(), $text );
        $values = array();
        $key = null;
        foreach ( explode( "\n", $m[1] ) as $line )
        {
            if ( $key !== null && preg_match( '/^\s+-\s+(.*)$/', $line, $item ) )
            {
                if ( !is_array( $values[$key] ) )
                    $values[$key] = $values[$key] === '' ? array() : array( $values[$key] );
                $values[$key][] = self::unquote( $item[1] );
                continue;
            }
            if ( !preg_match( '/^([A-Za-z_][\w-]*)\s*:\s*(.*)$/', $line, $pair ) )
                continue;
            $key = $pair[1];
            $value = trim( $pair[2] );
            if ( preg_match( '/^\[(.*)\]$/', $value, $list ) )
                $values[$key] = array_values( array_filter( array_map( array( __CLASS__, 'unquote' ), explode( ',', $list[1] ) ), 'strlen' ) );
            else
                $values[$key] = self::unquote( $value );
        }
        return array( $values, (string)substr( $text, strlen( $m[0] ) ) );
    }

    /** @return string a YAML scalar without its quotes */
    protected static function unquote( $value )
    {
        $value = trim( (string)$value );
        if ( strlen( $value ) >= 2 && ( ( $value[0] === '"' && substr( $value, -1 ) === '"' ) || ( $value[0] === "'" && substr( $value, -1 ) === "'" ) ) )
            $value = substr( $value, 1, -1 );
        return trim( $value );
    }

    /* ------------------------------------------------------------------------------------------------------
     * Markdown (the reader's own converter)
     * ------------------------------------------------------------------------------------------------------ */

    /** @return string the line with its leading tabs expanded to the next multiple of four columns */
    protected static function expandLeadingTabs( $line )
    {
        if ( strpos( $line, "\t" ) === false )
            return $line;
        if ( !preg_match( '/^[ \t]+/', $line, $m ) )
            return $line;
        $width = 0;
        foreach ( str_split( $m[0] ) as $char )
            $width = $char === "\t" ? ( intdiv( $width, 4 ) + 1 ) * 4 : $width + 1;
        return str_repeat( ' ', $width ) . substr( $line, strlen( $m[0] ) );
    }

    /** @return int the indentation of a line in columns */
    protected static function indentOf( $line )
    {
        return strlen( $line ) - strlen( ltrim( $line, ' ' ) );
    }

    /** @return bool whether a line starts a block that ends a paragraph */
    protected static function mdBlockStart( $line )
    {
        return (bool)preg_match( '/^ {0,3}(#{1,6}(?:[ \t]|$)|`{3,}|~{3,}|>|([-*_])(?:[ \t]*\2){2,}[ \t]*$|[-+*][ \t]+\S|1[.)][ \t]+\S)/', $line );
    }

    /** @return string HTML of Markdown block lines */
    protected static function mdBlocks( array $lines, $depth = 0 )
    {
        if ( $depth > 30 )
            return '<p>' . self::e( implode( ' ', $lines ) ) . '</p>';
        $html = '';
        $paragraph = array();
        $count = count( $lines );
        $i = 0;
        while ( $i < $count )
        {
            $line = $lines[$i];
            if ( trim( $line ) === '' )
            {
                $html .= self::mdParagraph( $paragraph );
                $i++;
                continue;
            }
            // fenced code
            if ( preg_match( '/^( {0,3})(`{3,}|~{3,})(.*)$/', $line, $m ) && ( $m[2][0] !== '`' || strpos( $m[3], '`' ) === false ) )
            {
                $html .= self::mdParagraph( $paragraph );
                $fence = $m[2];
                $indent = strlen( $m[1] );
                $code = array();
                $i++;
                while ( $i < $count && !preg_match( '/^ {0,3}' . preg_quote( $fence[0], '/' ) . '{' . strlen( $fence ) . ',}[ \t]*$/', $lines[$i] ) )
                {
                    $code[] = preg_replace( '/^ {0,' . $indent . '}/', '', $lines[$i] );
                    $i++;
                }
                $i++;
                $html .= '<pre>' . self::e( implode( "\n", $code ) ) . '</pre>';
                continue;
            }
            // indented code
            if ( !$paragraph && self::indentOf( $line ) >= 4 )
            {
                $code = array();
                while ( $i < $count && ( self::indentOf( $lines[$i] ) >= 4 || trim( $lines[$i] ) === '' ) )
                {
                    $code[] = (string)substr( $lines[$i], 4 );
                    $i++;
                }
                while ( $code && trim( end( $code ) ) === '' )
                    array_pop( $code );
                $html .= '<pre>' . self::e( implode( "\n", $code ) ) . '</pre>';
                continue;
            }
            // ATX heading
            if ( preg_match( '/^ {0,3}(#{1,6})(?:[ \t]+(.*?))?(?:[ \t]+#+)?[ \t]*$/', $line, $m ) )
            {
                $html .= self::mdParagraph( $paragraph );
                $level = strlen( $m[1] );
                $html .= "<h$level>" . self::mdInline( isset( $m[2] ) ? trim( $m[2] ) : '' ) . "</h$level>";
                $i++;
                continue;
            }
            // setext heading
            if ( $paragraph && preg_match( '/^ {0,3}(=+|-+)[ \t]*$/', $line, $m ) )
            {
                $level = $m[1][0] === '=' ? 1 : 2;
                $html .= "<h$level>" . self::mdInline( trim( implode( "\n", $paragraph ) ) ) . "</h$level>";
                $paragraph = array();
                $i++;
                continue;
            }
            // thematic break
            if ( preg_match( '/^ {0,3}([-*_])(?:[ \t]*\1){2,}[ \t]*$/', $line ) )
            {
                $html .= self::mdParagraph( $paragraph ) . '<hr>';
                $i++;
                continue;
            }
            // block quote
            if ( preg_match( '/^ {0,3}>/', $line ) )
            {
                $html .= self::mdParagraph( $paragraph );
                $quote = array();
                while ( $i < $count )
                {
                    if ( preg_match( '/^ {0,3}> ?(.*)$/', $lines[$i], $m ) )
                        $quote[] = $m[1];
                    elseif ( trim( $lines[$i] ) !== '' && $quote && trim( end( $quote ) ) !== '' && !self::mdBlockStart( $lines[$i] ) )
                        $quote[] = $lines[$i];
                    else
                        break;
                    $i++;
                }
                $html .= '<blockquote>' . self::mdBlocks( $quote, $depth + 1 ) . '</blockquote>';
                continue;
            }
            // list
            if ( preg_match( '/^( {0,3})([-+*]|(\d{1,9})[.)])([ \t]+|$)/', $line, $m )
                 && ( !$paragraph || ( trim( substr( $line, strlen( $m[0] ) ) ) !== '' && ( !isset( $m[3] ) || $m[3] === '' || $m[3] === '1' ) ) ) )
            {
                $html .= self::mdParagraph( $paragraph );
                list( $list, $i ) = self::mdList( $lines, $i, $depth );
                $html .= $list;
                continue;
            }
            // table
            if ( !$paragraph && strpos( $line, '|' ) !== false && $i + 1 < $count && self::mdTableDelimiter( $lines[$i + 1] ) )
            {
                list( $table, $i ) = self::mdTable( $lines, $i );
                $html .= $table;
                continue;
            }
            $paragraph[] = $line;
            $i++;
        }
        return $html . self::mdParagraph( $paragraph );
    }

    /** @return string the paragraph of the collected lines, and empties them */
    protected static function mdParagraph( array &$lines )
    {
        if ( !$lines )
            return '';
        $text = trim( implode( "\n", $lines ) );
        $lines = array();
        return $text !== '' ? '<p>' . self::mdInline( $text ) . '</p>' : '';
    }

    /** @return array( string HTML, int the next line ) of the list that starts at line $i */
    protected static function mdList( array $lines, $i, $depth )
    {
        $pattern = '/^( {0,3})([-+*]|(\d{1,9})([.)]))([ \t]+|$)(.*)$/';
        preg_match( $pattern, $lines[$i], $first );
        $ordered = isset( $first[3] ) && $first[3] !== '';
        $marker = $ordered ? $first[4] : $first[2];
        $count = count( $lines );
        $items = array();
        $current = null;
        $tight = true;
        $blank = false;
        while ( $i < $count )
        {
            $line = $lines[$i];
            if ( preg_match( $pattern, $line, $m ) && strlen( $m[1] ) < ( $current ? $current['indent'] : 4 ) )
            {
                $isOrdered = isset( $m[3] ) && $m[3] !== '';
                if ( $isOrdered !== $ordered || ( $isOrdered ? $m[4] : $m[2] ) !== $marker )
                    break;
                if ( $current )
                {
                    $items[] = $current;
                    if ( $blank )
                        $tight = false;
                }
                $markerWidth = strlen( $m[1] ) + strlen( $m[2] );
                $space = strlen( $m[5] );
                $indent = ( $space === 0 || $space > 4 ) ? $markerWidth + 1 : $markerWidth + $space;
                $current = array( 'indent' => $indent, 'lines' => array( $space > 4 ? substr( $m[5], 1 ) . $m[6] : $m[6] ) );
                $blank = false;
                $i++;
                continue;
            }
            if ( trim( $line ) === '' )
            {
                $next = $i + 1;
                while ( $next < $count && trim( $lines[$next] ) === '' )
                    $next++;
                if ( $next >= $count )
                    break;
                $continues = self::indentOf( $lines[$next] ) >= $current['indent']
                           || ( preg_match( $pattern, $lines[$next], $n ) && strlen( $n[1] ) < $current['indent']
                                && ( ( isset( $n[3] ) && $n[3] !== '' ) === $ordered ) && ( ( $ordered ? $n[4] : $n[2] ) === $marker ) );
                if ( !$continues )
                    break;
                $current['lines'][] = '';
                $blank = true;
                $i++;
                continue;
            }
            if ( self::indentOf( $line ) >= $current['indent'] )
            {
                if ( $blank )
                    $tight = false;
                $current['lines'][] = (string)substr( $line, $current['indent'] );
                $blank = false;
                $i++;
                continue;
            }
            if ( !$blank && !self::mdBlockStart( $line ) && trim( end( $current['lines'] ) ) !== '' )
            {
                $current['lines'][] = $line;
                $i++;
                continue;
            }
            break;
        }
        if ( $current )
            $items[] = $current;
        $tag = $ordered ? 'ol' : 'ul';
        $html = "<$tag>";
        foreach ( $items as $item )
        {
            $inner = self::mdBlocks( $item['lines'], $depth + 1 );
            if ( $tight && strpos( $inner, '<p>' ) === 0 && ( $end = strpos( $inner, '</p>' ) ) !== false )
                $inner = substr( $inner, 3, $end - 3 ) . substr( $inner, $end + 4 );
            $html .= '<li>' . $inner . '</li>';
        }
        return array( $html . "</$tag>", $i );
    }

    /** @return bool whether a line is the delimiter row of a table */
    protected static function mdTableDelimiter( $line )
    {
        return (bool)preg_match( '/^ {0,3}\|?[ \t]*:?-+:?[ \t]*(\|[ \t]*:?-+:?[ \t]*)*\|?[ \t]*$/', $line ) && strpos( $line, '-' ) !== false;
    }

    /** @return string[] the cells of a table row */
    protected static function mdTableRow( $line )
    {
        $line = trim( $line );
        if ( $line !== '' && $line[0] === '|' )
            $line = substr( $line, 1 );
        if ( substr( $line, -1 ) === '|' && substr( $line, -2 ) !== '\\|' )
            $line = substr( $line, 0, -1 );
        $cells = array();
        foreach ( preg_split( '/(?<!\\\\)\|/', $line ) as $cell )
            $cells[] = trim( str_replace( '\\|', '|', $cell ) );
        return $cells;
    }

    /** @return array( string HTML, int the next line ) of the table that starts at line $i */
    protected static function mdTable( array $lines, $i )
    {
        $header = self::mdTableRow( $lines[$i] );
        $columns = count( $header );
        $i += 2;
        $html = '<table><thead><tr>';
        foreach ( $header as $cell )
            $html .= '<th>' . self::mdInline( $cell ) . '</th>';
        $html .= '</tr></thead><tbody>';
        $count = count( $lines );
        while ( $i < $count && trim( $lines[$i] ) !== '' && !self::mdBlockStart( $lines[$i] ) )
        {
            $cells = array_slice( array_pad( self::mdTableRow( $lines[$i] ), $columns, '' ), 0, $columns );
            $html .= '<tr>';
            foreach ( $cells as $cell )
                $html .= '<td>' . self::mdInline( $cell ) . '</td>';
            $html .= '</tr>';
            $i++;
        }
        return array( $html . '</tbody></table>', $i );
    }

    /** @return string a placeholder for HTML the inline converter must not touch again */
    protected static function mdToken( $html )
    {
        self::$mdTokens[] = $html;
        return "\x1A" . ( count( self::$mdTokens ) - 1 ) . "\x1A";
    }

    /**
     * @param string $text Markdown inline text
     * @param bool $top false for the text of a link (its placeholders are restored by the outer call)
     * @return string HTML
     */
    protected static function mdInline( $text, $top = true )
    {
        if ( $top )
            self::$mdTokens = array();
        $text = (string)$text;
        // code spans
        $text = preg_replace_callback( '/(?<!`)(`+)(?!`)(.+?)(?<!`)\1(?!`)/s', function ( $m ) {
            $code = str_replace( "\n", ' ', $m[2] );
            if ( strlen( $code ) > 2 && $code[0] === ' ' && substr( $code, -1 ) === ' ' && trim( $code ) !== '' )
                $code = substr( $code, 1, -1 );
            return self::mdToken( '<code>' . self::e( $code ) . '</code>' );
        }, $text );
        // backslash escapes
        $text = preg_replace_callback( '/\\\\([!"#$%&\'()*+,\-.\/:;<=>?@\[\\\\\]^_`{|}~])/', function ( $m ) {
            return self::mdToken( self::e( $m[1] ) );
        }, $text );
        // hard line breaks
        $text = preg_replace_callback( '/(?: {2,}|\\\\)\n/', function () {
            return self::mdToken( '<br>' );
        }, $text );
        // autolinks
        $text = preg_replace_callback( '/<((?:https?:\/\/|mailto:)[^\s<>]+)>/i', function ( $m ) {
            $href = self::safeHref( $m[1] );
            return self::mdToken( $href !== null ? '<a href="' . self::e( $href ) . '">' . self::e( $m[1] ) . '</a>' : self::e( $m[1] ) );
        }, $text );
        $bracket = '((?:[^\[\]]|\[(?:[^\[\]]|\[[^\[\]]*\])*\])*)';
        $destination = '\(\s*<?([^\s)>]*)>?(?:\s+(?:"[^"]*"|\'[^\']*\'|\([^)]*\)))?\s*\)';
        // inline links (before images: the text of a link may be an image)
        $text = preg_replace_callback( '/(?<!!)\[' . $bracket . '\]' . $destination . '/', function ( $m ) {
            return self::mdLink( $m[1], $m[2] );
        }, $text );
        // images: the sanitizer keeps only those it can resolve (data: addresses), else the alternative text
        $text = preg_replace_callback( '/!\[' . $bracket . '\]' . $destination . '/', function ( $m ) {
            return self::mdToken( '<img src="' . self::e( $m[2] ) . '" alt="' . self::e( self::mdPlain( $m[1] ) ) . '">' );
        }, $text );
        $text = preg_replace_callback( '/!\[' . $bracket . '\](?:\[([^\]]*)\])?/', function ( $m ) {
            $key = strtolower( trim( isset( $m[2] ) && $m[2] !== '' ? $m[2] : $m[1] ) );
            if ( !isset( self::$mdRefs[$key] ) )
                return $m[0];
            return self::mdToken( '<img src="' . self::e( self::$mdRefs[$key] ) . '" alt="' . self::e( self::mdPlain( $m[1] ) ) . '">' );
        }, $text );
        // reference links
        $text = preg_replace_callback( '/(?<!!)\[' . $bracket . '\](?:\[([^\]]*)\])?/', function ( $m ) {
            $key = strtolower( trim( isset( $m[2] ) && $m[2] !== '' ? $m[2] : $m[1] ) );
            if ( !isset( self::$mdRefs[$key] ) )
                return $m[0];
            return self::mdLink( $m[1], self::$mdRefs[$key] );
        }, $text );
        // bare addresses
        $text = preg_replace_callback( '/(?<![\w\/"\'=@])(https?:\/\/[^\s<>\x1A]*[^\s<>\x1A.,;:!?)\]\'"*_~])/i', function ( $m ) {
            $href = self::safeHref( $m[1] );
            return self::mdToken( $href !== null ? '<a href="' . self::e( $href ) . '">' . self::e( $m[1] ) . '</a>' : self::e( $m[1] ) );
        }, $text );

        $text = self::e( $text );
        $text = preg_replace( '/~~(?=\S)(.+?)(?<=\S)~~/s', '<s>$1</s>', $text );
        $text = preg_replace( '/(\*\*|__)(?=\S)(.+?)(?<=\S)\1/s', '<strong>$2</strong>', $text );
        $text = preg_replace( '/(?<!\*)\*(?=[^\s*])(.+?)(?<=[^\s*])\*(?!\*)/s', '<em>$1</em>', $text );
        $text = preg_replace( '/(?<![A-Za-z0-9_])_(?=[^\s_])(.+?)(?<=[^\s_])_(?![A-Za-z0-9_])/s', '<em>$1</em>', $text );

        if ( !$top )
            return $text;
        for ( $pass = 0; $pass < 20 && strpos( $text, "\x1A" ) !== false; $pass++ )
        {
            $text = preg_replace_callback( '/\x1A(\d+)\x1A/', function ( $m ) {
                return isset( self::$mdTokens[(int)$m[1]] ) ? self::$mdTokens[(int)$m[1]] : '';
            }, $text );
        }
        return str_replace( "\x1A", '', $text );
    }

    /** @return string a placeholder for a link (text only when the address is not allowed) */
    protected static function mdLink( $label, $href )
    {
        $inner = self::mdInline( $label, false );
        $safe = self::safeHref( trim( $href ) );
        return self::mdToken( $safe !== null ? '<a href="' . self::e( $safe ) . '">' . $inner . '</a>' : $inner );
    }

    /** @return string the plain text of Markdown inline text (for an alternative text) */
    protected static function mdPlain( $text )
    {
        return trim( preg_replace( array( '/!?\[([^\]]*)\]\([^)]*\)/', '/[*_`~]/' ), array( '$1', '' ), $text ) );
    }

    /* ------------------------------------------------------------------------------------------------------
     * The HTML filter
     * ------------------------------------------------------------------------------------------------------ */

    /**
     * @param string $html
     * @param callable $resolver( string $src ) => image key or null
     * @param string[] $dropClasses elements with one of these classes are dropped
     * @return string
     */
    protected static function cleanHtml( $html, $resolver, array $dropClasses = array() )
    {
        return self::cleanNode( self::loadHtmlFragment( $html ), $resolver, $dropClasses );
    }

    /** @return string the subset HTML of the content of $root */
    protected static function cleanNode( DOMNode $root, $resolver, array $dropClasses = array() )
    {
        $tree = self::tree( $root, $resolver, array_flip( $dropClasses ), 0 );
        return self::serialize( self::normBlocks( $tree, 'root' ) );
    }

    /**
     * @param string $html UTF-8
     * @return DOMDocument
     */
    protected static function loadHtmlDocument( $html )
    {
        $html = (string)$html;
        if ( !preg_match( '//u', $html ) )
            $html = mb_convert_encoding( $html, 'UTF-8', 'UTF-8' );
        $html = preg_replace( '/<meta\b[^>]*charset[^>]*>/i', '', $html );
        // every character above ASCII as a character reference: the parser's idea of the charset no longer matters
        $html = mb_encode_numericentity( $html, array( 0x80, 0x10FFFF, 0, 0x1FFFFF ), 'UTF-8' );
        $doc = new DOMDocument( '1.0', 'UTF-8' );
        $flags = LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT;
        if ( strlen( $html ) > 10485760 )
            $flags |= LIBXML_PARSEHUGE;
        $previous = libxml_use_internal_errors( true );
        $ok = $doc->loadHTML( $html, $flags );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( !$ok )
            throw new expOdfConverterException( 'convert', 'the HTML cannot be parsed' );
        return $doc;
    }

    /** @return bool whether an element is not shown (hidden, aria-hidden, display:none or a dropped class) */
    protected static function isHidden( DOMElement $element, array $dropClasses )
    {
        if ( $element->hasAttribute( 'hidden' ) || strtolower( trim( $element->getAttribute( 'aria-hidden' ) ) ) === 'true' )
            return true;
        $style = $element->getAttribute( 'style' );
        if ( $style !== '' && preg_match( '/display\s*:\s*none|visibility\s*:\s*hidden/i', $style ) )
            return true;
        if ( $dropClasses && $element->hasAttribute( 'class' ) )
        {
            foreach ( preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ) ) as $class )
            {
                if ( isset( $dropClasses[$class] ) )
                    return true;
            }
        }
        return false;
    }

    /** @return array the cleaned tree of the children of $node */
    protected static function tree( DOMNode $node, $resolver, array $dropClasses, $depth )
    {
        $out = array();
        if ( $depth > 200 )
            return $out;
        foreach ( $node->childNodes as $child )
        {
            if ( $child instanceof DOMText )
            {
                self::pushText( $out, preg_replace( '/[ \t\n\r\f]+/', ' ', $child->data ) );
                continue;
            }
            if ( !$child instanceof DOMElement )
                continue;
            $name = strtolower( $child->localName !== null ? $child->localName : $child->nodeName );
            if ( isset( self::$dropTags[$name] ) || self::isHidden( $child, $dropClasses ) )
                continue;
            if ( isset( self::$tagMap[$name] ) )
                $name = self::$tagMap[$name];
            switch ( $name )
            {
                case 'pre':
                    $out[] = array( 't' => 'pre', 'a' => array(), 'c' => array( array( 't' => '#text', 'v' => self::preText( $child ) ) ) );
                    continue 2;
                case 'br':
                case 'hr':
                    $out[] = array( 't' => $name, 'a' => array(), 'c' => array() );
                    continue 2;
                case 'img':
                    $alt = trim( preg_replace( '/\s+/u', ' ', $child->getAttribute( 'alt' ) ) );
                    $key = $resolver( trim( $child->getAttribute( 'src' ) ) );
                    if ( is_string( $key ) && $key !== '' )
                        $out[] = array( 't' => 'img', 'a' => array( 'src' => $key, 'alt' => $alt ), 'c' => array() );
                    elseif ( $alt !== '' )
                        self::pushText( $out, ' ' . $alt . ' ' );
                    continue 2;
            }
            $children = self::tree( $child, $resolver, $dropClasses, $depth + 1 );
            if ( $name === 'a' )
            {
                $href = self::safeHref( $child->getAttribute( 'href' ) );
                if ( $href !== null )
                {
                    $out[] = array( 't' => 'a', 'a' => array( 'href' => $href ), 'c' => $children );
                    continue;
                }
            }
            elseif ( isset( self::$allowed[$name] ) )
            {
                $attributes = array();
                if ( $name === 'td' || $name === 'th' )
                {
                    foreach ( array( 'colspan', 'rowspan' ) as $attribute )
                    {
                        $value = (int)$child->getAttribute( $attribute );
                        if ( $value > 1 )
                            $attributes[$attribute] = min( $value, 1000 );
                    }
                }
                $out[] = array( 't' => $name, 'a' => $attributes, 'c' => $children );
                continue;
            }
            // anything else: its content takes its place
            $block = isset( self::$blockish[$name] );
            if ( $block )
                $out[] = array( 't' => '#break' );
            foreach ( $children as $grandchild )
            {
                if ( $grandchild['t'] === '#text' )
                    self::pushText( $out, $grandchild['v'] );
                else
                    $out[] = $grandchild;
            }
            if ( $block )
                $out[] = array( 't' => '#break' );
        }
        return $out;
    }

    /** @return string the text of a <pre>, its line breaks kept */
    protected static function preText( DOMNode $node )
    {
        $text = '';
        foreach ( $node->childNodes as $child )
        {
            if ( $child instanceof DOMText )
                $text .= $child->data;
            elseif ( $child instanceof DOMElement )
            {
                $name = strtolower( $child->localName );
                if ( isset( self::$dropTags[$name] ) )
                    continue;
                $text .= $name === 'br' ? "\n" : self::preText( $child );
            }
        }
        return $text;
    }

    protected static function pushText( array &$nodes, $text )
    {
        if ( $text === '' || $text === null )
            return;
        $last = count( $nodes ) - 1;
        if ( $last >= 0 && $nodes[$last]['t'] === '#text' )
            $nodes[$last]['v'] .= $text;
        else
            $nodes[] = array( 't' => '#text', 'v' => $text );
    }

    /**
     * @param array $nodes
     * @param string $context root, quote, li or cell
     * @return array blocks (li and cell: inline nodes when the content is a single line of text)
     */
    protected static function normBlocks( array $nodes, $context )
    {
        $out = array();
        $run = array();
        $implicit = array();
        foreach ( $nodes as $node )
        {
            if ( isset( self::$inline[$node['t']] ) )
            {
                $run[] = $node;
                continue;
            }
            self::flushRun( $out, $run, $implicit );
            if ( $node['t'] === '#break' )
                continue;
            foreach ( self::normBlock( $node ) as $block )
                $out[] = $block;
        }
        self::flushRun( $out, $run, $implicit );
        if ( ( $context === 'li' || $context === 'cell' ) && $out && isset( $implicit[0] ) )
        {
            $first = $out[0]['c'];
            array_shift( $out );
            if ( !$out || !isset( $implicit[1] ) )
                return array_merge( $first, $out );
            array_unshift( $out, array( 't' => 'p', 'a' => array(), 'c' => $first ) );
        }
        return $out;
    }

    protected static function flushRun( array &$out, array &$run, array &$implicit )
    {
        if ( !$run )
            return;
        $inline = self::trimInline( self::normInline( $run, false ) );
        $run = array();
        if ( self::hasContent( $inline ) )
        {
            $implicit[count( $out )] = true;
            $out[] = array( 't' => 'p', 'a' => array(), 'c' => $inline );
        }
    }

    /** @return array the blocks a block node becomes */
    protected static function normBlock( array $node )
    {
        $t = $node['t'];
        switch ( $t )
        {
            case 'h1':
            case 'h2':
            case 'h3':
            case 'h4':
            case 'h5':
            case 'h6':
            case 'p':
                $content = self::trimInline( self::normInline( $node['c'], false ) );
                return self::hasContent( $content ) ? array( array( 't' => $t, 'a' => array(), 'c' => $content ) ) : array();
            case 'pre':
                $text = isset( $node['c'][0]['v'] ) ? $node['c'][0]['v'] : '';
                $text = preg_replace( '/^\r?\n/', '', str_replace( "\r\n", "\n", $text ) );
                $text = rtrim( $text );
                return trim( $text ) !== '' ? array( array( 't' => 'pre', 'a' => array(), 'c' => array( array( 't' => '#text', 'v' => $text ) ) ) ) : array();
            case 'hr':
                return array( $node );
            case 'blockquote':
                $content = self::normBlocks( $node['c'], 'quote' );
                return $content ? array( array( 't' => 'blockquote', 'a' => array(), 'c' => $content ) ) : array();
            case 'ul':
            case 'ol':
                $items = array();
                foreach ( $node['c'] as $child )
                {
                    if ( $child['t'] === 'li' )
                    {
                        $items[] = array( 't' => 'li', 'a' => array(), 'c' => self::normBlocks( $child['c'], 'li' ) );
                    }
                    elseif ( $child['t'] === 'ul' || $child['t'] === 'ol' )
                    {
                        $sub = self::normBlock( $child );
                        if ( !$sub )
                            continue;
                        if ( $items )
                            $items[count( $items ) - 1]['c'] = array_merge( $items[count( $items ) - 1]['c'], $sub );
                        else
                            $items[] = array( 't' => 'li', 'a' => array(), 'c' => $sub );
                    }
                    elseif ( $child['t'] === '#break' || ( $child['t'] === '#text' && trim( $child['v'] ) === '' ) )
                        continue;
                    else
                        $items[] = array( 't' => 'li', 'a' => array(), 'c' => self::normBlocks( array( $child ), 'li' ) );
                }
                $items = array_values( array_filter( $items, function ( $item ) {
                    return (bool)$item['c'];
                } ) );
                return $items ? array( array( 't' => $t, 'a' => array(), 'c' => $items ) ) : array();
            case 'table':
                return self::normTable( $node );
            case 'li':
            case 'thead':
            case 'tbody':
            case 'tr':
            case 'td':
            case 'th':
                return self::normBlocks( $node['c'], 'quote' );
        }
        return array();
    }

    /** @return array a table with a thead and/or tbody of rows of cells, and what stood before it */
    protected static function normTable( array $node )
    {
        $head = array();
        $body = array();
        $before = array();
        self::tableRows( $node['c'], false, $head, $body, $before );
        if ( !$head && !$body )
            return $before;
        if ( !$head && $body )
        {
            $allHeader = true;
            foreach ( $body[0]['c'] as $cell )
            {
                if ( $cell['t'] !== 'th' )
                    $allHeader = false;
            }
            if ( $allHeader )
                $head[] = array_shift( $body );
        }
        $children = array();
        if ( $head )
            $children[] = array( 't' => 'thead', 'a' => array(), 'c' => $head );
        if ( $body )
            $children[] = array( 't' => 'tbody', 'a' => array(), 'c' => $body );
        $before[] = array( 't' => 'table', 'a' => array(), 'c' => $children );
        return $before;
    }

    protected static function tableRows( array $nodes, $inHead, array &$head, array &$body, array &$before )
    {
        foreach ( $nodes as $child )
        {
            switch ( $child['t'] )
            {
                case 'thead':
                    self::tableRows( $child['c'], true, $head, $body, $before );
                    break;
                case 'tbody':
                    self::tableRows( $child['c'], false, $head, $body, $before );
                    break;
                case 'tr':
                    $cells = array();
                    foreach ( $child['c'] as $cell )
                    {
                        if ( $cell['t'] === 'td' || $cell['t'] === 'th' )
                            $cells[] = array( 't' => $cell['t'], 'a' => $cell['a'], 'c' => self::normBlocks( $cell['c'], 'cell' ) );
                    }
                    if ( !$cells )
                        break;
                    if ( $inHead )
                        $head[] = array( 't' => 'tr', 'a' => array(), 'c' => $cells );
                    else
                        $body[] = array( 't' => 'tr', 'a' => array(), 'c' => $cells );
                    break;
                case 'td':
                case 'th':
                    // a cell without a row
                    $body[] = array( 't' => 'tr', 'a' => array(), 'c' => array( array( 't' => $child['t'], 'a' => $child['a'], 'c' => self::normBlocks( $child['c'], 'cell' ) ) ) );
                    break;
                case 'p':
                    foreach ( self::normBlock( $child ) as $block )
                        $before[] = $block;
                    break;
            }
        }
    }

    /** @return array inline nodes: blocks inside are flattened to their text */
    protected static function normInline( array $nodes, $inLink )
    {
        $out = array();
        foreach ( $nodes as $node )
        {
            $t = $node['t'];
            switch ( $t )
            {
                case '#text':
                    self::pushText( $out, $node['v'] );
                    break;
                case 'br':
                case 'img':
                    $out[] = $node;
                    break;
                case '#break':
                case 'hr':
                    self::pushText( $out, ' ' );
                    break;
                case 'a':
                    $content = self::normInline( $node['c'], true );
                    if ( $inLink )
                    {
                        foreach ( $content as $c )
                            $c['t'] === '#text' ? self::pushText( $out, $c['v'] ) : $out[] = $c;
                    }
                    elseif ( self::hasContent( $content ) )
                        $out[] = array( 't' => 'a', 'a' => $node['a'], 'c' => $content );
                    break;
                case 'strong':
                case 'em':
                case 'u':
                case 's':
                case 'sub':
                case 'sup':
                case 'code':
                    $content = self::normInline( $node['c'], $inLink );
                    if ( self::hasContent( $content ) )
                        $out[] = array( 't' => $t, 'a' => array(), 'c' => $content );
                    elseif ( $content )
                        self::pushText( $out, ' ' );
                    break;
                case 'pre':
                    $lines = explode( "\n", isset( $node['c'][0]['v'] ) ? $node['c'][0]['v'] : '' );
                    if ( $out )
                        $out[] = array( 't' => 'br', 'a' => array(), 'c' => array() );
                    foreach ( $lines as $index => $line )
                    {
                        if ( $index )
                            $out[] = array( 't' => 'br', 'a' => array(), 'c' => array() );
                        self::pushText( $out, $line );
                    }
                    break;
                default:
                    // a block inside a line of text: its content, after a space
                    $content = self::normInline( $node['c'], $inLink );
                    if ( self::hasContent( $content ) )
                    {
                        if ( $out )
                            self::pushText( $out, ' ' );
                        foreach ( $content as $c )
                            $c['t'] === '#text' ? self::pushText( $out, $c['v'] ) : $out[] = $c;
                    }
            }
        }
        return $out;
    }

    /** @return array inline nodes without leading and trailing white space and line breaks */
    protected static function trimInline( array $nodes )
    {
        self::trimEdge( $nodes, true );
        self::trimEdge( $nodes, false );
        // spaces doubled where two texts met
        foreach ( $nodes as $i => $node )
        {
            if ( $node['t'] === '#text' )
                $nodes[$i]['v'] = preg_replace( '/ {2,}/', ' ', $node['v'] );
        }
        return $nodes;
    }

    protected static function trimEdge( array &$nodes, $start )
    {
        while ( $nodes )
        {
            $index = $start ? 0 : count( $nodes ) - 1;
            $node = &$nodes[$index];
            if ( $node['t'] === '#text' )
            {
                $node['v'] = $start ? ltrim( $node['v'], ' ' ) : rtrim( $node['v'], ' ' );
                if ( $node['v'] === '' )
                {
                    unset( $node );
                    array_splice( $nodes, $index, 1 );
                    continue;
                }
                unset( $node );
                return;
            }
            if ( $node['t'] === 'br' )
            {
                unset( $node );
                array_splice( $nodes, $index, 1 );
                continue;
            }
            if ( $node['t'] === 'img' )
            {
                unset( $node );
                return;
            }
            self::trimEdge( $node['c'], $start );
            if ( !self::hasContent( $node['c'] ) )
            {
                unset( $node );
                array_splice( $nodes, $index, 1 );
                continue;
            }
            unset( $node );
            return;
        }
    }

    /** @return bool whether nodes hold text other than white space, an image or a block */
    protected static function hasContent( array $nodes )
    {
        foreach ( $nodes as $node )
        {
            switch ( $node['t'] )
            {
                case '#text':
                    if ( trim( $node['v'] ) !== '' )
                        return true;
                    break;
                case 'img':
                case 'hr':
                case 'pre':
                case 'table':
                case 'ul':
                case 'ol':
                    return true;
                case 'br':
                case '#break':
                    break;
                default:
                    if ( isset( $node['c'] ) && self::hasContent( $node['c'] ) )
                        return true;
            }
        }
        return false;
    }

    /** @return string HTML of cleaned nodes */
    protected static function serialize( array $nodes )
    {
        static $blocks = array( 'h1' => 1, 'h2' => 1, 'h3' => 1, 'h4' => 1, 'h5' => 1, 'h6' => 1, 'p' => 1, 'ul' => 1,
                                'ol' => 1, 'li' => 1, 'table' => 1, 'thead' => 1, 'tbody' => 1, 'tr' => 1,
                                'blockquote' => 1 );
        static $containers = array( 'ul' => 1, 'ol' => 1, 'table' => 1, 'thead' => 1, 'tbody' => 1, 'tr' => 1, 'blockquote' => 1 );
        $html = '';
        foreach ( $nodes as $node )
        {
            switch ( $node['t'] )
            {
                case '#text':
                    $html .= self::e( $node['v'] );
                    break;
                case '#break':
                    break;
                case 'br':
                    $html .= '<br>';
                    break;
                case 'hr':
                    $html .= "<hr>\n";
                    break;
                case 'img':
                    $html .= '<img src="' . self::e( $node['a']['src'] ) . '" alt="' . self::e( $node['a']['alt'] ) . '">';
                    break;
                case 'pre':
                    $html .= '<pre>' . self::e( $node['c'][0]['v'] ) . "</pre>\n";
                    break;
                default:
                    $attributes = '';
                    foreach ( $node['a'] as $name => $value )
                        $attributes .= ' ' . $name . '="' . self::e( (string)$value ) . '"';
                    $html .= '<' . $node['t'] . $attributes . '>' . ( isset( $containers[$node['t']] ) ? "\n" : '' )
                           . self::serialize( $node['c'] ) . '</' . $node['t'] . '>' . ( isset( $blocks[$node['t']] ) ? "\n" : '' );
            }
        }
        return $html;
    }

    /**
     * @param string $href
     * @return string|null the address when it is http, https or mailto, else null
     */
    public static function safeHref( $href )
    {
        $href = trim( preg_replace( '/[\x00-\x20\x7F]+/', ' ', (string)$href ) );
        if ( $href === '' || strlen( $href ) > 4096 || strpos( $href, ' ' ) !== false )
        {
            $href = str_replace( ' ', '%20', $href );
            if ( $href === '' || strlen( $href ) > 4096 )
                return null;
        }
        if ( preg_match( '#^https?://[^/?\#\s]#i', $href ) || preg_match( '#^mailto:[^\s]#i', $href ) )
            return $href;
        return null;
    }

    /* ------------------------------------------------------------------------------------------------------
     * Shared helpers
     * ------------------------------------------------------------------------------------------------------ */

    /**
     * @param array $items array( key, level, tag, html ) of consecutive list paragraphs
     * @return string nested HTML lists
     */
    protected static function renderList( array $items )
    {
        $html = '';
        $stack = array();
        foreach ( $items as $item )
        {
            $level = max( 0, min( 8, (int)$item['level'] ) );
            if ( $level > count( $stack ) )
                $level = count( $stack );
            while ( count( $stack ) > $level + 1 )
            {
                $closed = array_pop( $stack );
                $html .= '</li></' . $closed['tag'] . '>';
            }
            $identity = array( 'tag' => $item['tag'], 'key' => $level === 0 ? $item['key'] : '' );
            if ( count( $stack ) === $level + 1 )
            {
                if ( end( $stack ) == $identity )
                {
                    $html .= '</li><li>' . $item['html'];
                    continue;
                }
                $closed = array_pop( $stack );
                $html .= '</li></' . $closed['tag'] . '>';
            }
            $html .= '<' . $identity['tag'] . '><li>' . $item['html'];
            $stack[] = $identity;
        }
        while ( $stack )
        {
            $closed = array_pop( $stack );
            $html .= '</li></' . $closed['tag'] . '>';
        }
        return $html;
    }

    /**
     * @param array $segments array( 'f' => flags, 'h' => href or null, 'text' => string | 'raw' => html )
     * @return string inline HTML
     */
    protected static function segmentsToHtml( array $segments )
    {
        $html = '';
        $count = count( $segments );
        $i = 0;
        while ( $i < $count )
        {
            $href = $segments[$i]['h'];
            $inner = '';
            while ( $i < $count && $segments[$i]['h'] === $href )
            {
                $flags = $segments[$i]['f'];
                $content = '';
                $raw = false;
                while ( $i < $count && $segments[$i]['h'] === $href && $segments[$i]['f'] === $flags )
                {
                    if ( isset( $segments[$i]['raw'] ) )
                    {
                        $content .= $segments[$i]['raw'];
                        $raw = true;
                    }
                    else
                        $content .= self::e( $segments[$i]['text'] );
                    $i++;
                }
                $inner .= ( $flags && ( $raw || trim( $content ) !== '' ) ) ? self::wrapFlags( $content, $flags ) : $content;
            }
            $html .= ( $href !== null && trim( strip_tags( $inner ) ) !== '' ) ? '<a href="' . self::e( $href ) . '">' . $inner . '</a>' : $inner;
        }
        return $html;
    }

    /** @return string $html inside the elements of the formatting flags */
    protected static function wrapFlags( $html, $flags )
    {
        $open = '';
        $close = '';
        foreach ( array( self::F_BOLD => 'strong', self::F_ITALIC => 'em', self::F_UNDERLINE => 'u', self::F_STRIKE => 's',
                         self::F_SUP => 'sup', self::F_SUB => 'sub' ) as $flag => $tag )
        {
            if ( $flags & $flag )
            {
                $open .= "<$tag>";
                $close = "</$tag>" . $close;
            }
        }
        return $open . $html . $close;
    }

    /** @return string the content of $html when it is a single paragraph, else $html */
    protected static function unwrapSingleParagraph( $html )
    {
        $html = trim( $html );
        if ( substr_count( $html, '<p>' ) === 1 && preg_match( '#^<p>(.*)</p>$#s', $html, $m ) )
            return $m[1];
        return $html;
    }

    /** @return bool whether inline HTML holds text or an image */
    protected static function nonBlank( $html )
    {
        return stripos( $html, '<img' ) !== false
            || trim( html_entity_decode( strip_tags( $html ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ), " \t\n\r\0\x0B\xC2\xA0" ) !== '';
    }

    /** @return string */
    protected static function e( $text )
    {
        return htmlspecialchars( (string)$text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8' );
    }

    /** @return string the text of the first descendant $ns:$name of $parent, trimmed */
    protected static function nsText( DOMElement $parent, $ns, $name )
    {
        $element = $parent->getElementsByTagNameNS( $ns, $name )->item( 0 );
        return $element ? trim( $element->textContent ) : '';
    }

    /** @return string[] keywords separated by commas or semicolons */
    protected static function splitKeywords( $text )
    {
        return array_values( array_filter( array_map( 'trim', preg_split( '/[,;]/', (string)$text ) ), 'strlen' ) );
    }

    /** @return int a unix time, 0 when the date cannot be read */
    protected static function timestamp( $text )
    {
        $text = trim( (string)$text );
        if ( $text === '' )
            return 0;
        $time = strtotime( $text );
        return $time === false ? 0 : $time;
    }

    /** @return string the extension, lower case, without dots */
    protected static function normalizeExtension( $ext )
    {
        return strtolower( trim( (string)$ext, ". \t\n" ) );
    }

    /** @return string the original file name (options name, else the file's own) */
    protected function originalName( $file )
    {
        return isset( $this->options['name'] ) && $this->options['name'] !== '' ? basename( (string)$this->options['name'] ) : basename( $file );
    }

    /** @return string text bytes as UTF-8 (BOM, UTF-16, UTF-8, else Windows-1252), without control characters */
    protected static function toUtf8( $bytes )
    {
        $bytes = (string)$bytes;
        if ( strncmp( $bytes, "\xEF\xBB\xBF", 3 ) === 0 )
            $text = substr( $bytes, 3 );
        elseif ( strncmp( $bytes, "\xFF\xFE", 2 ) === 0 )
            $text = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16LE' );
        elseif ( strncmp( $bytes, "\xFE\xFF", 2 ) === 0 )
            $text = mb_convert_encoding( substr( $bytes, 2 ), 'UTF-8', 'UTF-16BE' );
        elseif ( preg_match( '//u', $bytes ) )
            $text = $bytes;
        else
            $text = mb_convert_encoding( $bytes, 'UTF-8', 'Windows-1252' );
        return preg_replace( '/[\x00-\x08\x0B\x0E-\x1F\x7F]/', '', $text );
    }

    /** @return string HTML bytes as UTF-8, by BOM, declared charset or content */
    protected static function htmlToUtf8( $bytes )
    {
        if ( strncmp( $bytes, "\xEF\xBB\xBF", 3 ) === 0 || strncmp( $bytes, "\xFF\xFE", 2 ) === 0 || strncmp( $bytes, "\xFE\xFF", 2 ) === 0 )
            return self::toUtf8( $bytes );
        $head = substr( $bytes, 0, 4096 );
        $charset = '';
        if ( preg_match( '/<meta[^>]+charset\s*=\s*["\']?\s*([A-Za-z0-9_.:-]+)/i', $head, $m ) || preg_match( '/<\?xml[^>]+encoding\s*=\s*["\']([A-Za-z0-9_.:-]+)/i', $head, $m ) )
            $charset = strtoupper( $m[1] );
        if ( $charset !== '' && $charset !== 'UTF-8' && $charset !== 'UTF8' )
        {
            try
            {
                $converted = mb_convert_encoding( $bytes, 'UTF-8', $charset === 'ISO-8859-1' || $charset === 'US-ASCII' ? 'Windows-1252' : $charset );
                if ( is_string( $converted ) )
                    return $converted;
            }
            catch ( ValueError $e )
            {
                // an unknown charset: detected below
            }
        }
        return self::toUtf8( $bytes );
    }

    /** @return string the bytes of a file, up to $max */
    protected function readFile( $file, $max = self::MAX_ENTRY )
    {
        $size = filesize( $file );
        if ( $size === false || $size > $max )
            throw new expOdfConverterException( 'too_large', sprintf( 'the document is larger than %d MB', round( $max / 1048576 ) ) );
        $bytes = file_get_contents( $file );
        if ( $bytes === false )
            throw new expOdfConverterException( 'convert', 'the document cannot be read' );
        return $bytes;
    }

    /**
     * Writes an image (PNG, JPEG, GIF or WebP; anything else is skipped) under a name of its own.
     *
     * @param string|null $bytes
     * @return string|null its key
     */
    protected function addImage( $bytes )
    {
        if ( !is_string( $bytes ) || $bytes === '' || strlen( $bytes ) > self::MAX_ENTRY || count( $this->images ) >= self::MAX_IMAGES )
            return null;
        $hash = sha1( $bytes );
        if ( isset( $this->imageHashes[$hash] ) )
            return $this->imageHashes[$hash];
        $info = @getimagesizefromstring( $bytes );
        $types = array( IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp' );
        if ( $info === false || !isset( $types[$info[2]] ) )
            return null;
        for ( $attempt = 0; $attempt < 100; $attempt++ )
        {
            $this->imageCount++;
            $name = 'image' . $this->imageCount . '.' . $types[$info[2]];
            $path = $this->dir . '/' . $name;
            $handle = @fopen( $path, 'xb' );
            if ( !$handle )
                continue;
            $written = fwrite( $handle, $bytes );
            fclose( $handle );
            if ( $written !== strlen( $bytes ) )
            {
                @unlink( $path );
                return null;
            }
            @chmod( $path, 0600 );
            $this->images[$name] = $path;
            $this->imageHashes[$hash] = $name;
            return $name;
        }
        return null;
    }

    /* ------------------------------------------------------------------------------------------------------
     * Packages and XML
     * ------------------------------------------------------------------------------------------------------ */

    /** Opens a ZIP package after checking its entries. */
    protected function openZip( $file )
    {
        if ( !class_exists( 'ZipArchive' ) )
            throw new expOdfConverterException( 'unsupported', 'the PHP zip extension is needed to read this format' );
        $zip = new ZipArchive();
        if ( $zip->open( $file, ZipArchive::RDONLY ) !== true )
            throw new expOdfConverterException( 'convert', 'the document is not a ZIP package' );
        $problem = class_exists( 'eZOOImport' ) ? eZOOImport::zipEntriesProblem( $zip ) : self::zipProblem( $zip );
        if ( $problem !== null )
        {
            $zip->close();
            throw new expOdfConverterException( 'convert', 'the package has entries that cannot be read safely (' . $problem['code'] . ')' );
        }
        $this->zip = $zip;
        $this->unpacked = 0;
    }

    protected function closeZip()
    {
        if ( $this->zip )
            $this->zip->close();
        $this->zip = null;
    }

    /** @return array|null the check of eZOOImport::zipEntriesProblem(), for when that class is not loaded */
    protected static function zipProblem( ZipArchive $zip )
    {
        if ( $zip->numFiles > 10000 )
            return array( 'code' => 'too_many_entries', 'path' => '' );
        for ( $i = 0; $i < $zip->numFiles; $i++ )
        {
            $path = $zip->getNameIndex( $i, ZipArchive::FL_UNCHANGED );
            if ( !is_string( $path ) || $path === '' || preg_match( '/[\x00-\x1F\x7F\\\\]/', $path ) || $path[0] === '/'
                 || preg_match( '/^[A-Za-z]:/', $path ) || in_array( '..', explode( '/', $path ), true ) )
                return array( 'code' => 'unsafe_path', 'path' => (string)$path );
            $opsys = 0;
            $attributes = 0;
            if ( $zip->getExternalAttributesIndex( $i, $opsys, $attributes ) && $opsys === ZipArchive::OPSYS_UNIX )
            {
                $type = ( $attributes >> 16 ) & 0170000;
                if ( $type !== 0 && $type !== 0100000 && $type !== 0040000 )
                    return array( 'code' => 'unsafe_type', 'path' => (string)$path );
            }
        }
        return null;
    }

    /**
     * @param string $name a part of the open package
     * @return string|null its bytes, null when it does not exist
     * @throws expOdfConverterException too_large
     */
    protected function entry( $name )
    {
        $name = self::normalizePart( (string)$name );
        if ( $name === null || $name === '' || !$this->zip )
            return null;
        $index = $this->zip->locateName( $name );
        if ( $index === false )
            $index = $this->zip->locateName( $name, ZipArchive::FL_NOCASE );
        if ( $index === false )
            return null;
        $stat = $this->zip->statIndex( $index );
        if ( !$stat || substr( $stat['name'], -1 ) === '/' )
            return null;
        if ( $stat['size'] > self::MAX_ENTRY )
            throw new expOdfConverterException( 'too_large', 'an entry of the package is larger than 64 MB' );
        $stream = method_exists( $this->zip, 'getStreamIndex' ) ? $this->zip->getStreamIndex( $index ) : $this->zip->getStream( $stat['name'] );
        if ( !$stream )
            return null;
        $data = '';
        try
        {
            while ( !feof( $stream ) )
            {
                $chunk = fread( $stream, 65536 );
                if ( $chunk === false || ( $chunk === '' && !feof( $stream ) ) )
                    break;
                $data .= $chunk;
                $this->unpacked += strlen( $chunk );
                if ( strlen( $data ) > self::MAX_ENTRY )
                    throw new expOdfConverterException( 'too_large', 'an entry of the package is larger than 64 MB' );
                if ( $this->unpacked > self::MAX_TOTAL )
                    throw new expOdfConverterException( 'too_large', 'the package unpacks to more than 256 MB' );
            }
        }
        finally
        {
            fclose( $stream );
        }
        return $data;
    }

    /** @return DOMDocument|null an XML part of an Office Open XML package (strict namespaces mapped) */
    protected function ooxml( $part, $optional = false )
    {
        $bytes = $this->entry( $part );
        if ( $bytes === null )
        {
            if ( $optional )
                return null;
            throw new expOdfConverterException( 'convert', 'the package has no ' . basename( $part ) );
        }
        $bytes = strtr( $bytes, self::$strictNamespaces );
        if ( $optional )
        {
            try
            {
                return self::parseXml( $bytes, basename( $part ) );
            }
            catch ( expOdfConverterException $e )
            {
                return null;
            }
        }
        return self::parseXml( $bytes, basename( $part ) );
    }

    /**
     * @param string $xml
     * @param string $what for the message
     * @return DOMDocument
     */
    protected static function parseXml( $xml, $what = 'document' )
    {
        if ( !is_string( $xml ) || trim( $xml ) === '' )
            throw new expOdfConverterException( 'convert', "the $what is empty" );
        if ( stripos( $xml, '<!DOCTYPE' ) !== false || stripos( $xml, '<!ENTITY' ) !== false )
            throw new expOdfConverterException( 'convert', "the $what has a document type declaration" );
        $doc = new DOMDocument();
        $flags = LIBXML_NONET | LIBXML_COMPACT;
        if ( strlen( $xml ) > 10485760 )
            $flags |= LIBXML_PARSEHUGE;
        $previous = libxml_use_internal_errors( true );
        $ok = $doc->loadXML( $xml, $flags );
        libxml_clear_errors();
        libxml_use_internal_errors( $previous );
        if ( !$ok || !$doc->documentElement )
            throw new expOdfConverterException( 'convert', "the $what is not well-formed XML" );
        return $doc;
    }

    /**
     * @param string $part a part of the package, '' for the package itself
     * @return array id => array( 'type', 'target' (a part, an address when external, null when it leaves the
     *               package), 'external' )
     */
    protected function rels( $part )
    {
        $slash = strrpos( $part, '/' );
        $path = $part === '' ? '_rels/.rels'
              : ( $slash === false ? '_rels/' . $part . '.rels' : substr( $part, 0, $slash ) . '/_rels/' . substr( $part, $slash + 1 ) . '.rels' );
        $doc = $this->ooxml( $path, true );
        $rels = array();
        if ( !$doc )
            return $rels;
        foreach ( $doc->getElementsByTagNameNS( self::NS_REL, 'Relationship' ) as $rel )
        {
            $external = strtolower( $rel->getAttribute( 'TargetMode' ) ) === 'external';
            $target = $rel->getAttribute( 'Target' );
            $rels[$rel->getAttribute( 'Id' )] = array(
                'type' => $rel->getAttribute( 'Type' ),
                'target' => $external ? $target : self::resolveTarget( $part, $target ),
                'external' => $external,
            );
        }
        return $rels;
    }

    /** @return string|null the target of the first internal relationship whose type ends with $suffix */
    protected function relByType( array $rels, $suffix )
    {
        foreach ( $rels as $rel )
        {
            if ( !$rel['external'] && $rel['target'] !== null && substr( $rel['type'], -strlen( $suffix ) ) === $suffix )
                return $rel['target'];
        }
        return null;
    }

    /** @return string|null a relationship target as a part name, null when it leaves the package */
    protected static function resolveTarget( $base, $target )
    {
        $target = (string)$target;
        if ( $target === '' || preg_match( '/^[a-z][a-z0-9+.-]*:/i', $target ) )
            return null;
        $target = rawurldecode( preg_replace( '/[#?].*$/', '', $target ) );
        if ( $target !== '' && $target[0] === '/' )
            return self::normalizePart( $target );
        $slash = strrpos( (string)$base, '/' );
        $directory = $slash === false ? '' : substr( $base, 0, $slash );
        return self::normalizePart( ( $directory !== '' ? $directory . '/' : '' ) . $target );
    }

    /** @return string|null a part name without ".", ".." and leading slashes, null when it climbs out */
    protected static function normalizePart( $path )
    {
        if ( preg_match( '/[\x00-\x1F\x7F\\\\]/', $path ) )
            return null;
        $parts = array();
        foreach ( explode( '/', $path ) as $segment )
        {
            if ( $segment === '' || $segment === '.' )
                continue;
            if ( $segment === '..' )
            {
                if ( !$parts )
                    return null;
                array_pop( $parts );
                continue;
            }
            $parts[] = $segment;
        }
        return implode( '/', $parts );
    }

    /** @return DOMElement|null the mc:Choice (or mc:Fallback when preferred or alone) of mc:AlternateContent */
    protected static function alternateContent( DOMElement $alternate, $preferFallback )
    {
        $choice = self::child( $alternate, self::NS_MC, 'Choice' );
        $fallback = self::child( $alternate, self::NS_MC, 'Fallback' );
        if ( $preferFallback )
            return $fallback ? $fallback : $choice;
        return $choice ? $choice : $fallback;
    }

    /** @return DOMElement|null the first child element $ns:$name */
    protected static function child( DOMNode $parent, $ns, $name )
    {
        foreach ( $parent->childNodes as $child )
        {
            if ( $child instanceof DOMElement && $child->localName === $name && $child->namespaceURI === $ns )
                return $child;
        }
        return null;
    }

    /** @return DOMElement[] the child elements (of namespace $ns and name $name when given) */
    protected static function children( DOMNode $parent, $ns = null, $name = null )
    {
        $list = array();
        foreach ( $parent->childNodes as $child )
        {
            if ( $child instanceof DOMElement && ( $ns === null || $child->namespaceURI === $ns ) && ( $name === null || $child->localName === $name ) )
                $list[] = $child;
        }
        return $list;
    }
}
