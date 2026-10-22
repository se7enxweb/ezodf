<?php
/**
 * expOdfReaders: documents to the HTML subset without LibreOffice. The bundled samples (share/samples) and
 * fixtures built here with ZipArchive. Kernel-free and without a database.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/../classes/converter/expodfconverterexception.php';
require_once __DIR__ . '/../classes/converter/expodfsandbox.php';
require_once __DIR__ . '/../classes/readers/expodfreaders.php';
require_once __DIR__ . '/../classes/readers/expodfhtmltoodt.php';

class expOdfReadersTest extends TestCase
{
    /** a 1 x 1 PNG */
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';

    /** @var string */
    protected $workDir;

    protected function setUp(): void
    {
        if ( !class_exists( 'ZipArchive' ) )
            $this->markTestSkipped( 'The zip extension is needed.' );
        $this->workDir = sys_get_temp_dir() . '/ezodf-readers-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->workDir . '/in', 0700, true );
    }

    protected function tearDown(): void
    {
        if ( $this->workDir !== null )
            eZOOImport::removeWorkDirectory( $this->workDir );
    }

    protected function samples()
    {
        return dirname( __DIR__ ) . '/share/samples/';
    }

    /** @param array $entries name => content */
    protected function zip( $name, array $entries )
    {
        $file = $this->workDir . '/in/' . $name;
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $file, ZipArchive::CREATE | ZipArchive::OVERWRITE ) === true );
        foreach ( $entries as $entry => $content )
            $zip->addFromString( $entry, $content );
        $zip->close();
        return $file;
    }

    protected function file( $name, $content )
    {
        $file = $this->workDir . '/in/' . $name;
        file_put_contents( $file, $content );
        return $file;
    }

    protected function read( $file, $ext, array $options = array() )
    {
        return expOdfReaders::toHtml( $file, $ext, $this->workDir . '/out', $options );
    }

    /** Every HTML the readers return uses only the subset and no attribute outside it. */
    protected function assertSubset( $html )
    {
        $allowed = array( 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'br', 'strong', 'em', 'u', 's', 'sub', 'sup', 'a', 'ul', 'ol', 'li',
                          'table', 'thead', 'tbody', 'tr', 'th', 'td', 'img', 'blockquote', 'pre', 'code', 'hr' );
        preg_match_all( '#<([a-z0-9]+)((?:\s+[a-z-]+="[^"]*")*)\s*/?>#i', $html, $m, PREG_SET_ORDER );
        foreach ( $m as $tag )
        {
            $this->assertContains( strtolower( $tag[1] ), $allowed, "element <{$tag[1]}> in $html" );
            preg_match_all( '/\s([a-z-]+)="/i', $tag[2], $attributes );
            foreach ( $attributes[1] as $attribute )
                $this->assertContains( $attribute, array( 'href', 'src', 'alt', 'colspan', 'rowspan' ), "attribute $attribute in $html" );
        }
        $this->assertDoesNotMatchRegularExpression( '/<(script|style|iframe)|\son\w+=|javascript:/i', $html );
    }

    /** @return string a DOCX package with a heading, formatting, a nested list, a table, a link, an image, a footnote */
    protected function docxFixture( array $extra = array() )
    {
        $document = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<w:document ' . self::W . ' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"'
            . ' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            . ' xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><w:body>'
            . '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Sample heading</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:pStyle w:val="Subhead"/></w:pPr><w:r><w:t>Second level</w:t></w:r></w:p>'
            . '<w:p><w:r><w:t xml:space="preserve">Plain, </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>bold</w:t></w:r>'
            . '<w:r><w:t xml:space="preserve">, </w:t></w:r><w:r><w:rPr><w:i/></w:rPr><w:t>italic</w:t></w:r>'
            . '<w:r><w:t xml:space="preserve">, </w:t></w:r><w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>underlined</w:t></w:r>'
            . '<w:r><w:t xml:space="preserve"> and </w:t></w:r><w:hyperlink r:id="rIdLink"><w:r><w:t>a link</w:t></w:r></w:hyperlink>'
            . '<w:r><w:t xml:space="preserve"> &lt;script&gt;</w:t></w:r><w:r><w:footnoteReference w:id="1"/></w:r>'
            . '<w:r><w:br/><w:t>second line</w:t></w:r><w:r><w:fldChar w:fldCharType="begin"/></w:r><w:r><w:instrText>PAGE</w:instrText></w:r></w:p>'
            . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Bullet one</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Nested point</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="1"/></w:numPr></w:pPr><w:r><w:t>Bullet two</w:t></w:r></w:p>'
            . '<w:p><w:pPr><w:numPr><w:ilvl w:val="0"/><w:numId w:val="2"/></w:numPr></w:pPr><w:r><w:t>Step one</w:t></w:r></w:p>'
            . '<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr><w:tc><w:p><w:r><w:t>Name</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Value</w:t></w:r></w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:tcPr><w:gridSpan w:val="2"/></w:tcPr><w:p><w:r><w:t>Wide cell</w:t></w:r></w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:tcPr><w:vMerge w:val="restart"/></w:tcPr><w:p><w:r><w:t>Tall cell</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>b1</w:t></w:r></w:p></w:tc></w:tr>'
            . '<w:tr><w:tc><w:tcPr><w:vMerge/></w:tcPr><w:p/></w:tc><w:tc><w:p><w:r><w:t>b2</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            . '<w:p><w:r><w:drawing><wp:inline><wp:docPr id="1" name="Picture 1" descr="A small dot"/>'
            . '<a:graphic><a:graphicData><pic:pic><pic:blipFill><a:blip r:embed="rIdImage"/></pic:blipFill></pic:pic></a:graphicData></a:graphic>'
            . '</wp:inline></w:drawing></w:r></w:p>'
            . '<w:p><w:pPr><w:pStyle w:val="Quote"/></w:pPr><w:r><w:t>Quoted words</w:t></w:r></w:p>'
            . '<w:sectPr/></w:body></w:document>';
        $styles = '<?xml version="1.0" encoding="UTF-8"?><w:styles ' . self::W . '>'
            . '<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Subhead"><w:name w:val="Subhead"/><w:pPr><w:outlineLvl w:val="1"/></w:pPr></w:style>'
            . '<w:style w:type="paragraph" w:styleId="Quote"><w:name w:val="Quote"/></w:style></w:styles>';
        $numbering = '<?xml version="1.0" encoding="UTF-8"?><w:numbering ' . self::W . '>'
            . '<w:abstractNum w:abstractNumId="10"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl><w:lvl w:ilvl="1"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'
            . '<w:abstractNum w:abstractNumId="20"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl></w:abstractNum>'
            . '<w:num w:numId="1"><w:abstractNumId w:val="10"/></w:num><w:num w:numId="2"><w:abstractNumId w:val="20"/></w:num></w:numbering>';
        $footnotes = '<?xml version="1.0" encoding="UTF-8"?><w:footnotes ' . self::W . '>'
            . '<w:footnote w:type="separator" w:id="-1"><w:p><w:r><w:separator/></w:r></w:p></w:footnote>'
            . '<w:footnote w:id="1"><w:p><w:r><w:footnoteRef/></w:r><w:r><w:t>The note text.</w:t></w:r></w:p></w:footnote></w:footnotes>';
        $rels = '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '<Relationship Id="rIdNumbering" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/numbering" Target="numbering.xml"/>'
            . '<Relationship Id="rIdNotes" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footnotes" Target="footnotes.xml"/>'
            . '<Relationship Id="rIdLink" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.com/page" TargetMode="External"/>'
            . '<Relationship Id="rIdImage" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="'
            . ( isset( $extra['imageTarget'] ) ? $extra['imageTarget'] : 'media/image1.png' ) . '"/></Relationships>';
        $entries = array(
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'word/document.xml' => $document,
            'word/styles.xml' => $styles,
            'word/numbering.xml' => $numbering,
            'word/footnotes.xml' => $footnotes,
            'word/_rels/document.xml.rels' => $rels,
            'word/media/image1.png' => base64_decode( self::PNG ),
            'docProps/core.xml' => '<?xml version="1.0"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties"'
                . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/"><dc:title>Sample report</dc:title>'
                . '<cp:keywords>alpha, beta; gamma</cp:keywords><dc:language>en-US</dc:language><dc:description>A test document</dc:description>'
                . '<dcterms:created>2026-01-02T03:04:05Z</dcterms:created></cp:coreProperties>',
        );
        return $this->zip( 'fixture.docx', array_merge( $entries, isset( $extra['entries'] ) ? $extra['entries'] : array() ) );
    }

    public function testExtensionsAndReads()
    {
        $extensions = expOdfReaders::extensions();
        foreach ( array( 'docx', 'docm', 'dotx', 'html', 'htm', 'xhtml', 'md', 'markdown', 'txt', 'text', 'ods', 'fods', 'xlsx', 'xlsm',
                         'csv', 'tsv', 'odp', 'fodp', 'pptx', 'ppsx' ) as $ext )
        {
            $this->assertContains( $ext, $extensions );
            $this->assertTrue( expOdfReaders::reads( strtoupper( $ext ) ) );
        }
        $this->assertSame( expOdfSandbox::tool( 'pdftotext' ) !== '', expOdfReaders::reads( '.pdf' ) );
        $this->assertFalse( expOdfReaders::reads( 'doc' ) );
        $this->assertFalse( expOdfReaders::reads( 'exe' ) );
    }

    public function testAnUnknownFormatIsUnsupported()
    {
        try
        {
            $this->read( $this->file( 'x.doc', 'x' ), 'doc' );
            $this->fail( 'no exception' );
        }
        catch ( expOdfConverterException $e )
        {
            $this->assertSame( 'unsupported', $e->code() );
        }
    }

    public function testATooLargeDocumentIsRefused()
    {
        try
        {
            $this->read( $this->file( 'big.txt', str_repeat( 'a', 2048 ) ), 'txt', array( 'max_bytes' => 1024 ) );
            $this->fail( 'no exception' );
        }
        catch ( expOdfConverterException $e )
        {
            $this->assertSame( 'too_large', $e->code() );
        }
    }

    public function testDocxFixture()
    {
        $result = $this->read( $this->docxFixture(), 'docx' );
        $html = $result['html'];
        $this->assertSubset( $html );
        $this->assertStringStartsWith( '<h1>Sample heading</h1>', $html );
        $this->assertStringContainsString( '<h2>Second level</h2>', $html );
        $this->assertStringContainsString( 'Plain, <strong>bold</strong>, <em>italic</em>, <u>underlined</u> and <a href="https://example.com/page">a link</a> &lt;script&gt;<sup>1</sup><br>second line</p>', $html );
        $this->assertStringNotContainsString( 'PAGE', $html, 'field codes are not text' );
        $this->assertMatchesRegularExpression( '#<ul>\s*<li>Bullet one<ul>\s*<li>Nested point</li>\s*</ul>\s*</li>\s*<li>Bullet two</li>\s*</ul>\s*<ol>\s*<li>Step one</li>#', $html );
        $this->assertMatchesRegularExpression( '#<thead>\s*<tr>\s*<th>Name</th>\s*<th>Value</th>#', $html );
        $this->assertStringContainsString( '<td colspan="2">Wide cell</td>', $html );
        $this->assertStringContainsString( '<td rowspan="2">Tall cell</td>', $html );
        $this->assertStringContainsString( '<img src="image1.png" alt="A small dot">', $html );
        $this->assertStringContainsString( "<blockquote>\n<p>Quoted words</p>", $html );
        $this->assertMatchesRegularExpression( '#<hr>\s*<ol>\s*<li>The note text.</li>#', $html );

        $this->assertSame( array( 'image1.png' ), array_keys( $result['images'] ) );
        $this->assertSame( $this->workDir . '/out/image1.png', $result['images']['image1.png'] );
        $this->assertSame( base64_decode( self::PNG ), file_get_contents( $result['images']['image1.png'] ) );
        $this->assertSame( 'Sample report', $result['title'] );
        $this->assertSame( array( 'alpha', 'beta', 'gamma' ), $result['meta']['keywords'] );
        $this->assertSame( 'en-US', $result['meta']['language'] );
        $this->assertSame( 'A test document', $result['meta']['description'] );
        $this->assertSame( gmmktime( 3, 4, 5, 1, 2, 2026 ), $result['meta']['created'] );
    }

    public function testSampleDocx()
    {
        $result = $this->read( $this->samples() . 'sample.docx', 'docx' );
        $html = $result['html'];
        $this->assertSubset( $html );
        $this->assertStringStartsWith( '<h1>Document converter test</h1>', $html );
        $this->assertStringContainsString( '<strong>bold</strong>', $html );
        $this->assertStringContainsString( '<em>italic</em>', $html );
        $this->assertMatchesRegularExpression( '#<ul>\s*<li>First point</li>\s*<li>Second point</li>#', $html );
        $this->assertStringContainsString( '<h2>A table</h2>', $html );
        $this->assertStringContainsString( '<td>Format</td>', $html );
        $this->assertSame( 'Document converter test', $result['title'], 'no title in core.xml: the leading h1' );
    }

    public function testARelationshipOutOfThePackageIsIgnored()
    {
        $result = $this->read( $this->docxFixture( array( 'imageTarget' => '../../../etc/passwd' ) ), 'docx' );
        $this->assertSame( array(), $result['images'] );
        $this->assertStringContainsString( 'A small dot', $result['html'], 'the alternative text stays' );
        $this->assertStringNotContainsString( '<img', $result['html'] );
    }

    public function testAHostileZipIsRefusedAndNothingIsWritten()
    {
        $file = $this->docxFixture( array( 'entries' => array( '../evil.txt' => 'x' ) ) );
        try
        {
            $this->read( $file, 'docx' );
            $this->fail( 'no exception' );
        }
        catch ( expOdfConverterException $e )
        {
            $this->assertSame( 'convert', $e->code() );
            $this->assertStringContainsString( 'unsafe_path', $e->getMessage() );
        }
        $this->assertFileDoesNotExist( $this->workDir . '/evil.txt' );
        $this->assertSame( array( '.', '..' ), scandir( $this->workDir . '/out' ) );
    }

    public function testADoctypeInAPartIsRefused()
    {
        $file = $this->zip( 'entity.docx', array(
            'word/document.xml' => '<?xml version="1.0"?><!DOCTYPE x [<!ENTITY e SYSTEM "file:///etc/passwd">]><w:document ' . self::W . '><w:body><w:p><w:r><w:t>&e;</w:t></w:r></w:p></w:body></w:document>',
        ) );
        try
        {
            $this->read( $file, 'docx' );
            $this->fail( 'no exception' );
        }
        catch ( expOdfConverterException $e )
        {
            $this->assertSame( 'convert', $e->code() );
        }
    }

    public function testNotAZip()
    {
        try
        {
            $this->read( $this->file( 'plain.docx', 'just text' ), 'docx' );
            $this->fail( 'no exception' );
        }
        catch ( expOdfConverterException $e )
        {
            $this->assertSame( 'convert', $e->code() );
        }
    }

    public function testXlsxWithTwoSheets()
    {
        $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $file = $this->zip( 'book.xlsx', array(
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook ' . $ns . '><sheets><sheet name="Prices" sheetId="1" r:id="rId1"/>'
                . '<sheet name="Notes" sheetId="2" r:id="rId2"/><sheet name="Secret" sheetId="3" state="veryHidden" r:id="rId3"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
                . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="/xl/worksheets/sheet2.xml"/>'
                . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
                . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/></Relationships>',
            'xl/sharedStrings.xml' => '<?xml version="1.0"?><sst ' . $ns . '><si><t>Item</t></si><si><t>Price</t></si><si><r><t>Ap</t></r><r><t>ple</t></r></si></sst>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData>'
                . '<row r="1"><c r="A1" t="s"><v>0</v></c><c r="B1" t="s"><v>1</v></c><c r="C1" t="inlineStr"><is><t>Fresh</t></is></c></row>'
                . '<row r="2"><c r="A2" t="s"><v>2</v></c><c r="B2"><v>1.5</v></c><c r="C2" t="b"><v>1</v></c></row>'
                . '<row r="4"><c r="A4" t="str"><v>&lt;b&gt;x</v></c><c r="B4"><v>0.30000000000000004</v></c></row>'
                . '</sheetData><mergeCells count="1"><mergeCell ref="A4:A4"/></mergeCells></worksheet>',
            'xl/worksheets/sheet2.xml' => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData><row r="1"><c r="B1"><v>42</v></c></row>'
                . '<row r="2"><c r="A2" t="inlineStr"><is><t>Merged</t></is></c></row></sheetData>'
                . '<mergeCells count="1"><mergeCell ref="A2:B2"/></mergeCells></worksheet>',
            'xl/worksheets/sheet3.xml' => '<?xml version="1.0"?><worksheet ' . $ns . '><sheetData><row r="1"><c r="A1" t="inlineStr"><is><t>hidden</t></is></c></row></sheetData></worksheet>',
        ) );
        $result = $this->read( $file, 'xlsx' );
        $html = $result['html'];
        $this->assertSubset( $html );
        $this->assertMatchesRegularExpression( '#^<h2>Prices</h2>\s*<table>\s*<thead>\s*<tr>\s*<th>Item</th>\s*<th>Price</th>\s*<th>Fresh</th>#', $html );
        $this->assertMatchesRegularExpression( '#<td>Apple</td>\s*<td>1.5</td>\s*<td>TRUE</td>#', $html );
        $this->assertMatchesRegularExpression( '#<tr>\s*<td></td>\s*<td></td>\s*<td></td>\s*</tr>#', $html, 'the empty row 3 stays' );
        $this->assertStringContainsString( '<td>&lt;b&gt;x</td>', $html );
        $this->assertStringContainsString( '<td>0.3</td>', $html );
        $this->assertStringContainsString( '<h2>Notes</h2>', $html );
        $this->assertMatchesRegularExpression( '#<tbody>\s*<tr>\s*<td></td>\s*<td>42</td>\s*</tr>\s*<tr>\s*<td colspan="2">Merged</td>\s*</tr>#', $html, 'a number row is no header' );
        $this->assertStringNotContainsString( 'hidden', $html );
        $this->assertSame( 'Prices', $result['title'] );
        $this->assertFalse( $result['meta']['truncated'] );
    }

    public function testSampleXlsxAndOds()
    {
        foreach ( array( 'xlsx', 'ods' ) as $ext )
        {
            $result = $this->read( $this->samples() . 'sample.' . $ext, $ext );
            $this->assertSubset( $result['html'] );
            $this->assertMatchesRegularExpression( '#^<h2>Formats</h2>\s*<table>\s*<thead>\s*<tr>\s*<th>Format</th>\s*<th>Family</th>#', $result['html'], $ext );
            $this->assertStringContainsString( '<td>DOCX</td>', $result['html'], $ext );
            $this->assertStringContainsString( '<td>Spreadsheet</td>', $result['html'], $ext );
            $this->assertSame( 'Formats', $result['title'], $ext );
        }
    }

    public function testFodsRepeatsAreExpandedAndTrailingEmptiesDropped()
    {
        $fods = '<?xml version="1.0" encoding="UTF-8"?><office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:table="urn:oasis:names:tc:opendocument:xmlns:table:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:meta="urn:oasis:names:tc:opendocument:xmlns:meta:1.0" office:mimetype="application/vnd.oasis.opendocument.spreadsheet">'
            . '<office:meta><dc:title>Flat sheet</dc:title><meta:keyword>one</meta:keyword><meta:keyword>two</meta:keyword></office:meta>'
            . '<office:body><office:spreadsheet><table:table table:name="Data">'
            . '<table:table-row><table:table-cell office:value-type="string"><text:p>A</text:p></table:table-cell>'
            . '<table:table-cell office:value-type="string" table:number-columns-repeated="2"><text:p>B</text:p></table:table-cell>'
            . '<table:table-cell table:number-columns-repeated="16381"/></table:table-row>'
            . '<table:table-row table:number-rows-repeated="2"><table:table-cell office:value-type="float" office:value="7"><text:p>7</text:p></table:table-cell>'
            . '<table:table-cell table:number-columns-repeated="16383"/></table:table-row>'
            . '<table:table-row table:number-rows-repeated="1048573"><table:table-cell table:number-columns-repeated="16384"/></table:table-row>'
            . '</table:table></office:spreadsheet></office:body></office:document>';
        $result = $this->read( $this->file( 'flat.fods', $fods ), 'fods' );
        $html = $result['html'];
        $this->assertMatchesRegularExpression( '#<th>A</th>\s*<th>B</th>\s*<th>B</th>\s*</tr>#', $html );
        $this->assertSame( 2, substr_count( $html, '<td>7</td>' ) );
        $this->assertSame( 3, substr_count( $html, '<tr>' ) );
        $this->assertSame( 'Flat sheet', $result['title'] );
        $this->assertSame( array( 'one', 'two' ), $result['meta']['keywords'] );
    }

    public function testCsvDelimiterAndEncoding()
    {
        $csv = mb_convert_encoding( "Name;City;Count\nCaf\u{E9} One;\"Paris; Center\";3\nSecond;Lyon;4\n", 'Windows-1252', 'UTF-8' );
        $result = $this->read( $this->file( 'list.csv', $csv ), 'csv', array( 'name' => 'visits_2026.csv' ) );
        $html = $result['html'];
        $this->assertStringStartsWith( '<h2>visits_2026</h2>', $html );
        $this->assertMatchesRegularExpression( '#<th>Name</th>\s*<th>City</th>\s*<th>Count</th>#', $html );
        $this->assertMatchesRegularExpression( "#<td>Caf\u{E9} One</td>\s*<td>Paris; Center</td>\s*<td>3</td>#u", $html );

        $tsv = "a\tb\n1\t2\n";
        $result = $this->read( $this->file( 'x.tsv', $tsv ), 'tsv' );
        $this->assertMatchesRegularExpression( '#<th>a</th>\s*<th>b</th>.*<td>1</td>\s*<td>2</td>#s', $result['html'] );

        $pipe = "x|y\n5|6\n7|8\n";
        $result = $this->read( $this->file( 'p.csv', $pipe ), 'csv' );
        $this->assertMatchesRegularExpression( '#<td>5</td>\s*<td>6</td>#', $result['html'] );
    }

    public function testCsvIsTruncatedAtTheRowLimit()
    {
        $result = $this->read( $this->file( 'long.csv', str_repeat( "1,2\n", expOdfReaders::MAX_ROWS + 10 ) ), 'csv' );
        $this->assertTrue( $result['meta']['truncated'] );
        $this->assertSame( expOdfReaders::MAX_ROWS, substr_count( $result['html'], '<tr>' ) );
    }

    public function testSamplePresentations()
    {
        foreach ( array( 'pptx', 'odp' ) as $ext )
        {
            $result = $this->read( $this->samples() . 'sample.' . $ext, $ext );
            $html = $result['html'];
            $this->assertSubset( $html );
            $this->assertStringStartsWith( '<h2>Slide 1</h2>', $html, $ext );
            $this->assertStringContainsString( '<p>Converter test slides</p>', $html, $ext );
            $this->assertStringContainsString( '<h2>Slide 2</h2>', $html, $ext );
            $this->assertStringContainsString( '<p>Text of the second slide</p>', $html, $ext );
            $this->assertSame( 2, $result['meta']['pages'], $ext );
        }
    }

    public function testPptxTitleBulletsNotesAndPicture()
    {
        $a = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
        $relNs = 'xmlns="http://schemas.openxmlformats.org/package/2006/relationships"';
        $type = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships/';
        $file = $this->zip( 'deck.pptx', array(
            '_rels/.rels' => '<?xml version="1.0"?><Relationships ' . $relNs . '><Relationship Id="rId1" Type="' . $type . 'officeDocument" Target="ppt/presentation.xml"/></Relationships>',
            'ppt/presentation.xml' => '<?xml version="1.0"?><p:presentation ' . $a . '><p:sldIdLst><p:sldId id="257" r:id="rId8"/><p:sldId id="256" r:id="rId7"/></p:sldIdLst></p:presentation>',
            'ppt/_rels/presentation.xml.rels' => '<?xml version="1.0"?><Relationships ' . $relNs . '><Relationship Id="rId7" Type="' . $type . 'slide" Target="slides/slide1.xml"/>'
                . '<Relationship Id="rId8" Type="' . $type . 'slide" Target="slides/slide2.xml"/></Relationships>',
            'ppt/slides/slide2.xml' => '<?xml version="1.0"?><p:sld ' . $a . '><p:cSld><p:spTree>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Title"/><p:cNvSpPr/><p:nvPr><p:ph type="title"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>Quarterly plan</a:t></a:r></a:p></p:txBody></p:sp>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Body"/><p:cNvSpPr/><p:nvPr><p:ph idx="1"/></p:nvPr></p:nvSpPr><p:txBody>'
                . '<a:p><a:r><a:rPr b="1"/><a:t>Grow</a:t></a:r></a:p><a:p><a:pPr lvl="1"/><a:r><a:t>Detail</a:t></a:r></a:p>'
                . '<a:p><a:r><a:rPr><a:hlinkClick r:id="rIdL"/></a:rPr><a:t>Read more</a:t></a:r></a:p></p:txBody></p:sp>'
                . '<p:pic><p:nvPicPr><p:cNvPr id="4" name="Pic" descr="Chart"/><p:cNvPicPr/><p:nvPr/></p:nvPicPr><p:blipFill><a:blip r:embed="rIdI"/></p:blipFill></p:pic>'
                . '</p:spTree></p:cSld></p:sld>',
            'ppt/slides/_rels/slide2.xml.rels' => '<?xml version="1.0"?><Relationships ' . $relNs . '>'
                . '<Relationship Id="rIdL" Type="' . $type . 'hyperlink" Target="https://example.com/more" TargetMode="External"/>'
                . '<Relationship Id="rIdI" Type="' . $type . 'image" Target="../media/image1.png"/>'
                . '<Relationship Id="rIdN" Type="' . $type . 'notesSlide" Target="../notesSlides/notesSlide1.xml"/></Relationships>',
            'ppt/media/image1.png' => base64_decode( self::PNG ),
            'ppt/notesSlides/notesSlide1.xml' => '<?xml version="1.0"?><p:notes ' . $a . '><p:cSld><p:spTree>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="2" name="Img"/><p:cNvSpPr/><p:nvPr><p:ph type="sldImg"/></p:nvPr></p:nvSpPr></p:sp>'
                . '<p:sp><p:nvSpPr><p:cNvPr id="3" name="Notes"/><p:cNvSpPr/><p:nvPr><p:ph type="body" idx="1"/></p:nvPr></p:nvSpPr><p:txBody><a:p><a:r><a:t>Say hello first.</a:t></a:r></a:p></p:txBody></p:sp>'
                . '</p:spTree></p:cSld></p:notes>',
            'ppt/slides/slide1.xml' => '<?xml version="1.0"?><p:sld ' . $a . '><p:cSld><p:spTree><p:sp><p:nvSpPr><p:cNvPr id="2" name="T"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
                . '<p:txBody><a:p><a:r><a:t>Closing words</a:t></a:r></a:p></p:txBody></p:sp></p:spTree></p:cSld></p:sld>',
        ) );
        $result = $this->read( $file, 'pptx' );
        $html = $result['html'];
        $this->assertSubset( $html );
        $this->assertStringStartsWith( '<h2>Quarterly plan</h2>', $html, 'the order of sldIdLst' );
        $this->assertMatchesRegularExpression( '#<ul>\s*<li><strong>Grow</strong><ul>\s*<li>Detail</li>\s*</ul>\s*</li>\s*<li><a href="https://example.com/more">Read more</a></li>#', $html );
        $this->assertStringContainsString( '<img src="image1.png" alt="Chart">', $html );
        $this->assertMatchesRegularExpression( '#<blockquote>\s*<p>Say hello first.</p>\s*</blockquote>\s*<h2>Slide 2</h2>\s*<p>Closing words</p>#', $html );
        $this->assertSame( 'Quarterly plan', $result['title'] );
    }

    public function testFodpTitleAndNotes()
    {
        $fodp = '<?xml version="1.0" encoding="UTF-8"?><office:document xmlns:office="urn:oasis:names:tc:opendocument:xmlns:office:1.0"'
            . ' xmlns:draw="urn:oasis:names:tc:opendocument:xmlns:drawing:1.0" xmlns:text="urn:oasis:names:tc:opendocument:xmlns:text:1.0"'
            . ' xmlns:presentation="urn:oasis:names:tc:opendocument:xmlns:presentation:1.0" xmlns:style="urn:oasis:names:tc:opendocument:xmlns:style:1.0"'
            . ' xmlns:fo="urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0" xmlns:svg="urn:oasis:names:tc:opendocument:xmlns:svg-compatible:1.0">'
            . '<office:automatic-styles><style:style style:name="T1" style:family="text"><style:text-properties fo:font-style="italic"/></style:style>'
            . '<text:list-style style:name="L1"><text:list-level-style-number text:level="1"/></text:list-style></office:automatic-styles>'
            . '<office:body><office:presentation><draw:page draw:name="page1">'
            . '<draw:frame presentation:class="title"><draw:text-box><text:p>Welcome</text:p></draw:text-box></draw:frame>'
            . '<draw:frame presentation:class="outline"><draw:text-box><text:list text:style-name="L1"><text:list-item><text:p>First <text:span text:style-name="T1">idea</text:span></text:p></text:list-item></text:list></draw:text-box></draw:frame>'
            . '<draw:frame svg:width="1cm"><draw:image><office:binary-data>' . self::PNG . '</office:binary-data></draw:image><svg:desc>Logo</svg:desc></draw:frame>'
            . '<presentation:notes><draw:frame presentation:class="notes"><draw:text-box><text:p>Speak slowly.</text:p></draw:text-box></draw:frame></presentation:notes>'
            . '</draw:page></office:presentation></office:body></office:document>';
        $result = $this->read( $this->file( 'flat.fodp', $fodp ), 'fodp' );
        $html = $result['html'];
        $this->assertSubset( $html );
        $this->assertMatchesRegularExpression( '#^<h2>Welcome</h2>\s*<ol>\s*<li>First <em>idea</em></li>\s*</ol>\s*<p><img src="image1.png" alt="Logo"></p>\s*<blockquote>\s*<p>Speak slowly.</p>#', $html );
        $this->assertSame( 'Welcome', $result['title'] );
    }

    public function testHtmlIsSanitized()
    {
        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Page title</title><meta name="keywords" content="k1, k2">'
            . '<style>p{color:red}</style><script>alert(1)</script></head><body onload="x()">'
            . '<div class="wrap"><h1 onclick="steal()">Head &amp; more</h1><p>Text <b>bold</b> <i>it</i> <span style="color:red">span</span>'
            . ' <a href="javascript:alert(1)">bad link</a> <a href="https://example.com/" target="_blank" onclick="x()">good link</a>'
            . ' <a href="mailto:info@example.com">mail</a></p>'
            . '<script>document.write("x")</script><iframe src="https://example.com/frame"></iframe>'
            . '<img src="https://example.com/remote.png" alt="Remote picture"> <img src="data:image/png;base64,' . self::PNG . '" alt="Inline dot">'
            . '<img src="data:image/svg+xml;base64,PHN2Zy8+" alt="Vector">'
            . '<ul><li>one<ul><li>two</li></ul></li></ul><table><tr><th>H</th></tr><tr><td colspan="2" style="x">C</td></tr></table>'
            . '<pre>  code &lt;x&gt;' . "\n" . '    more</pre><blockquote>Quote text</blockquote><form><input value="secret"><button>Go</button></form>'
            . 'Loose text<hr></div><p hidden>Hidden text</p><p aria-hidden="true">Decor</p></body></html>';
        $result = $this->read( $this->file( 'page.html', $html ), 'html' );
        $out = $result['html'];
        $this->assertSubset( $out );
        $this->assertStringNotContainsString( 'alert', $out );
        $this->assertStringNotContainsString( 'secret', $out );
        $this->assertStringNotContainsString( 'Hidden text', $out );
        $this->assertStringNotContainsString( 'Decor', $out );
        $this->assertStringNotContainsString( 'remote.png', $out );
        $this->assertStringStartsWith( '<h1>Head &amp; more</h1>', $out );
        $this->assertStringContainsString( 'Text <strong>bold</strong> <em>it</em> span bad link <a href="https://example.com/">good link</a> <a href="mailto:info@example.com">mail</a>', $out );
        $this->assertStringContainsString( 'Remote picture <img src="image1.png" alt="Inline dot"> Vector', $out );
        $this->assertMatchesRegularExpression( '#<ul>\s*<li>one<ul>\s*<li>two</li>#', $out );
        $this->assertMatchesRegularExpression( '#<thead>\s*<tr>\s*<th>H</th>\s*</tr>\s*</thead>\s*<tbody>\s*<tr>\s*<td colspan="2">C</td>#', $out );
        $this->assertStringContainsString( "<pre>  code &lt;x&gt;\n    more</pre>", $out );
        $this->assertMatchesRegularExpression( '#<blockquote>\s*<p>Quote text</p>\s*</blockquote>#', $out );
        $this->assertStringContainsString( '<p>Loose text</p>', $out );
        $this->assertSame( array( 'image1.png' ), array_keys( $result['images'] ) );
        $this->assertSame( 'Page title', $result['title'] );
        $this->assertSame( array( 'k1', 'k2' ), $result['meta']['keywords'] );
        $this->assertSame( 'en', $result['meta']['language'] );
    }

    public function testHtmlInWindows1252()
    {
        $html = mb_convert_encoding( "<html><head><meta charset=\"windows-1252\"></head><body><p>Gr\u{FC}\u{DF}e \u{20AC}</p></body></html>", 'Windows-1252', 'UTF-8' );
        $result = $this->read( $this->file( 'old.htm', $html ), 'htm' );
        $this->assertStringContainsString( "<p>Gr\u{FC}\u{DF}e \u{20AC}</p>", $result['html'] );
    }

    protected function markdownSample()
    {
        return "---\ntitle: \"Release notes\"\ndescription: What changed\nkeywords: [notes, release]\nauthor: Team\n---\n"
            . "# Overview\n\nSome *emphasis*, **strong**, `code <x>` and a [link](https://example.com/a \"t\").\n"
            . "Bad [link](javascript:alert(1)) here.\n\n"
            . "Setext heading\n--------------\n\n"
            . "- one\n- two\n  - nested\n- three\n\n"
            . "1. first\n2. second\n\n"
            . "> quoted\n> text\n\n"
            . "```php\n<?php echo 1;\n```\n\n"
            . "| A | B |\n|---|:-:|\n| 1 | 2 |\n\n"
            . "![Local image](images/x.png) ![Remote](https://example.com/x.png)\n\n"
            . "<script>alert(2)</script>\n\n***\n";
    }

    public function testMarkdownWithTheOwnConverter()
    {
        $result = $this->read( $this->file( 'notes.md', $this->markdownSample() ), 'md', array( 'markdown' => 'own' ) );
        $this->assertMarkdown( $result );
        $this->assertStringContainsString( '<h1>Overview</h1>', $result['html'] );
        $this->assertStringContainsString( 'Some <em>emphasis</em>, <strong>strong</strong>, <code>code &lt;x&gt;</code> and a <a href="https://example.com/a">link</a>.', $result['html'] );
        $this->assertStringContainsString( '<h2>Setext heading</h2>', $result['html'] );
        $this->assertStringContainsString( '<pre>&lt;?php echo 1;</pre>', $result['html'] );
    }

    public function testMarkdownWithTheKernelRendererWhenItExists()
    {
        if ( !class_exists( 'expHelpMarkdownRenderer' ) )
            $this->markTestSkipped( 'The kernel has no Markdown renderer here.' );
        $result = $this->read( $this->file( 'notes.md', $this->markdownSample() ), 'md' );
        $this->assertMarkdown( $result );
        $this->assertStringNotContainsString( '#</', $result['html'], 'no heading anchors' );
    }

    protected function assertMarkdown( array $result )
    {
        $html = $result['html'];
        $this->assertSubset( $html );
        $this->assertStringNotContainsString( 'alert(1)', strip_tags( $html ) === $html ? '' : $html );
        $this->assertStringNotContainsString( '<script', $html );
        $this->assertMatchesRegularExpression( '#<h1>Overview</h1>#', $html );
        $this->assertMatchesRegularExpression( '#<ul>\s*<li>one</li>\s*<li>two<ul>\s*<li>nested</li>\s*</ul>\s*</li>\s*<li>three</li>\s*</ul>#', $html );
        $this->assertMatchesRegularExpression( '#<ol>\s*<li>first</li>\s*<li>second</li>\s*</ol>#', $html );
        $this->assertMatchesRegularExpression( '#<blockquote>\s*<p>quoted\s+text</p>#', $html );
        $this->assertMatchesRegularExpression( '#<th>A</th>\s*<th>B</th>.*<td>1</td>\s*<td>2</td>#s', $html );
        $this->assertStringContainsString( 'Local image', $html );
        $this->assertStringNotContainsString( 'images/x.png', $html );
        $this->assertStringNotContainsString( 'example.com/x.png', $html );
        $this->assertStringNotContainsString( 'href="javascript', $html );
        $this->assertStringContainsString( '<hr>', $html );
        $this->assertSame( 'Release notes', $result['title'] );
        $this->assertSame( 'What changed', $result['meta']['description'] );
        $this->assertSame( array( 'notes', 'release' ), $result['meta']['keywords'] );
        $this->assertSame( 'Team', $result['meta']['author'] );
    }

    public function testText()
    {
        $result = $this->read( $this->file( 'a.txt', "First line\nsame paragraph <b>\n\n\nSecond paragraph\r\n" ), 'txt' );
        $this->assertSame( "<p>First line<br>same paragraph &lt;b&gt;</p>\n<p>Second paragraph</p>\n", $result['html'] );
    }

    public function testPdf()
    {
        if ( expOdfSandbox::tool( 'pdftotext' ) === '' )
            $this->markTestSkipped( 'pdftotext is not installed.' );
        $result = $this->read( $this->file( 'doc.pdf', self::pdf( array( 'Hello first page', 'Text on page two' ), 'Sample PDF' ) ), 'pdf' );
        $this->assertStringContainsString( '<p>Hello first page</p>', $result['html'] );
        $this->assertStringContainsString( '<p>Text on page two</p>', $result['html'] );
        $this->assertSame( 2, $result['meta']['pages'] );
        if ( expOdfSandbox::tool( 'pdfinfo' ) !== '' )
            $this->assertSame( 'Sample PDF', $result['title'] );
        $this->assertSame( array(), array_values( array_diff( scandir( $this->workDir . '/out' ), array( '.', '..' ) ) ), 'the text file is removed' );
    }

    /** @return string a minimal PDF with one line of text per page */
    protected static function pdf( array $pages, $title )
    {
        $objects = array();
        $kids = array();
        $count = count( $pages );
        foreach ( $pages as $i => $text )
            $kids[] = ( 4 + $i * 2 ) . ' 0 R';
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids [' . implode( ' ', $kids ) . '] /Count ' . $count . ' >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        foreach ( $pages as $i => $text )
        {
            $stream = 'BT /F1 18 Tf 72 720 Td (' . $text . ') Tj ET';
            $objects[4 + $i * 2] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ' . ( 5 + $i * 2 ) . ' 0 R >>';
            $objects[5 + $i * 2] = '<< /Length ' . strlen( $stream ) . " >>\nstream\n" . $stream . "\nendstream";
        }
        $info = 4 + $count * 2;
        $objects[$info] = '<< /Title (' . $title . ') >>';
        $pdf = "%PDF-1.4\n";
        $offsets = array();
        foreach ( $objects as $number => $body )
        {
            $offsets[$number] = strlen( $pdf );
            $pdf .= "$number 0 obj\n$body\nendobj\n";
        }
        $xref = strlen( $pdf );
        $pdf .= 'xref' . "\n0 " . ( $info + 1 ) . "\n0000000000 65535 f \n";
        for ( $n = 1; $n <= $info; $n++ )
            $pdf .= sprintf( "%010d 00000 n \n", $offsets[$n] );
        return $pdf . "trailer\n<< /Size " . ( $info + 1 ) . " /Root 1 0 R /Info $info 0 R >>\nstartxref\n$xref\n%%EOF\n";
    }

    public function testSanitizeKeepsTheSubsetOnly()
    {
        $html = expOdfReaders::sanitize( '<p>a<p>b</p><svg><script>x</script></svg><img src="known.png" alt="K"><img src="other.png" alt="O">'
            . '<a href="https://example.com/"><a href="http://example.org/">nested</a></a><table><tr><td rowspan="3" onmouseover="x">c</td></tr></table>'
            . '<h2><ul><li>flattened</li></ul></h2>', array( 'known.png' ) );
        $this->assertSubset( $html );
        $this->assertStringContainsString( '<p>a</p>', $html );
        $this->assertStringContainsString( '<img src="known.png" alt="K"> O', $html );
        $this->assertSame( 1, substr_count( $html, '<a ' ), 'links are not nested' );
        $this->assertStringContainsString( '<td rowspan="3">c</td>', $html );
        $this->assertStringNotContainsString( 'other.png', $html );
        $this->assertSame( $html, expOdfReaders::sanitize( $html, array( 'known.png' ) ), 'the filter is idempotent' );
    }
}
