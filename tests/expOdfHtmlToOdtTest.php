<?php
/**
 * expOdfHtmlToOdt: the ODT it writes is read back by eZOOImport's own extraction and DOM handling
 * (extractDocument(), handleNode()), without a database.
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

class expOdfHtmlToOdtTest extends TestCase
{
    const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var string */
    protected $workDir;

    protected function setUp(): void
    {
        if ( !class_exists( 'ZipArchive' ) )
            $this->markTestSkipped( 'The zip extension is needed.' );
        $this->workDir = sys_get_temp_dir() . '/ezodf-htmltoodt-' . bin2hex( random_bytes( 8 ) );
        mkdir( $this->workDir, 0700, true );
    }

    protected function tearDown(): void
    {
        if ( $this->workDir !== null )
            eZOOImport::removeWorkDirectory( $this->workDir );
    }

    protected function image()
    {
        $file = $this->workDir . '/image1.png';
        file_put_contents( $file, base64_decode( self::PNG ) );
        return array( 'image1.png' => $file );
    }

    protected function sampleHtml()
    {
        return "<h1>Main title</h1>\n<p>Plain <strong>bold</strong> <em>italic</em> <strong><em>both</em></strong> <u>under</u> x<sup>2</sup>"
            . ' <a href="https://example.com/a?b=1&amp;c=2">a link</a> &lt;embed object_id=\'1\'/&gt;<br>next line</p>'
            . '<h3>Deeper</h3><ul><li>one<ul><li>nested</li></ul></li><li>two</li></ul><ol><li>first</li></ol>'
            . '<table><thead><tr><th>Name</th><th>Value</th></tr></thead><tbody><tr><td colspan="2">Wide</td></tr>'
            . '<tr><td>a</td><td><p>b1</p><p>b2</p></td></tr></tbody></table>'
            . "<pre>if (a &lt; b)\n    return 1;</pre><blockquote><p>Quoted one</p><p>Quoted two</p></blockquote>"
            . '<p>Picture: <img src="image1.png" alt="A dot"></p><hr><p>The end</p>';
    }

    /**
     * Writes the HTML, extracts it as eZOOImport does and runs every body node through handleNode().
     *
     * @return array( string the eZXML body, eZOOImport, DOMDocument content.xml )
     */
    protected function import( $html, array $images = array(), $title = '' )
    {
        $run = bin2hex( random_bytes( 4 ) );
        $odt = $this->workDir . '/out/' . $run . '.odt';
        $this->assertSame( $odt, expOdfHtmlToOdt::write( $html, $images, $odt, $title ) );
        $import = new eZOOImport();
        $dir = $this->workDir . '/import-' . $run;
        $this->assertTrue( $import->extractDocument( $odt, $dir ), $import->getErrorMessage() );
        $import->ImportDir = $dir . '/';
        $dom = new DOMDocument();
        $this->assertTrue( $dom->load( $dir . '/content.xml', LIBXML_NONET ) );
        $import->AutomaticStyles = $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_OFFICE, 'automatic-styles' )->item( 0 )->childNodes;
        $level = 1;
        $xml = '';
        foreach ( $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_OFFICE, 'text' )->item( 0 )->childNodes as $node )
            $xml .= $import->handleNode( $node, $level );
        $xml .= str_repeat( '</section>', max( 0, $level - 1 ) );
        $document = new DOMDocument();
        $this->assertTrue( $document->loadXML( "<section xmlns:image='http://ez.no/namespaces/ezpublish3/image/' xmlns:xhtml='http://ez.no/namespaces/ezpublish3/xhtml/' xmlns:custom='http://ez.no/namespaces/ezpublish3/custom/'><section>$xml</section></section>" ),
                           "well-formed eZXML: $xml" );
        return array( $xml, $import, $dom );
    }

    public function testThePackageLayout()
    {
        $odt = expOdfHtmlToOdt::write( $this->sampleHtml(), $this->image(), $this->workDir . '/out/doc.odt', 'Main title' );
        $zip = new ZipArchive();
        $this->assertTrue( $zip->open( $odt ) === true );
        $this->assertSame( 'mimetype', $zip->getNameIndex( 0 ) );
        $stat = $zip->statIndex( 0 );
        $this->assertSame( ZipArchive::CM_STORE, $stat['comp_method'] );
        $this->assertSame( 'application/vnd.oasis.opendocument.text', $zip->getFromName( 'mimetype' ) );
        foreach ( array( 'content.xml', 'styles.xml', 'meta.xml', 'META-INF/manifest.xml' ) as $part )
        {
            $doc = new DOMDocument();
            $this->assertTrue( $doc->loadXML( $zip->getFromName( $part ) ), $part );
        }
        $this->assertSame( base64_decode( self::PNG ), $zip->getFromName( 'Pictures/image1.png' ) );
        $manifest = $zip->getFromName( 'META-INF/manifest.xml' );
        $this->assertStringContainsString( 'manifest:full-path="Pictures/image1.png" manifest:media-type="image/png"', $manifest );
        $this->assertNull( eZOOImport::zipEntriesProblem( $zip ) );
        $this->assertStringContainsString( '<dc:title>Main title</dc:title>', $zip->getFromName( 'meta.xml' ) );
        $zip->close();
    }

    public function testEZOOImportReadsWhatIsWritten()
    {
        list( $xml, $import, $dom ) = $this->import( $this->sampleHtml(), $this->image(), 'Main title' );

        $this->assertStringStartsWith( '<header>Main title</header>', $xml, 'the HTML starts with an h1: the title is not added again' );
        $this->assertSame( 1, substr_count( $xml, 'Main title' ) );
        $this->assertStringContainsString( '<paragraph>Plain <strong>bold</strong> <emphasize>italic</emphasize> <strong><emphasize>both</emphasize></strong> under x', $xml );
        $this->assertStringContainsString( "<custom name='sup'>2</custom>", $xml );
        $this->assertStringContainsString( "<link href='https://example.com/a?b=1&amp;c=2'>a link</link>", $xml );
        $this->assertStringContainsString( '&lt;embed object_id=&apos;1&apos;/&gt;', $xml, 'text stays text' );
        // eZOOImport makes <line>s of the nodes next to a text:line-break only
        $this->assertStringContainsString( '<line> &lt;embed object_id=&apos;1&apos;/&gt;</line><line>next line</line>', $xml );
        $this->assertStringContainsString( '<section><section><header>Deeper</header>', $xml );
        $this->assertStringContainsString( '<ul><li><paragraph>one</paragraph>', $xml );
        $this->assertStringContainsString( '<ul><li><paragraph>nested</paragraph>', $xml );
        $this->assertStringContainsString( '<li><paragraph>two</paragraph>', $xml );
        $this->assertStringContainsString( '<ol><li><paragraph>first</paragraph>', $xml );
        $this->assertMatchesRegularExpression( '#<th\s*><paragraph>Name</paragraph>\s*</th><th\s*><paragraph>Value</paragraph>#', $xml );
        $this->assertStringContainsString( "xhtml:colspan='2'", $xml );
        $this->assertMatchesRegularExpression( '#<td\s*><paragraph>b1</paragraph>\s*<paragraph>b2</paragraph>#', $xml );
        $this->assertMatchesRegularExpression( "#<paragraph><literal\s*>if \(a &lt; b\)\n    return 1;\n</literal>#", $xml );
        $this->assertStringContainsString( "<custom name='quote' ><paragraph>Quoted one</paragraph>\n<paragraph>Quoted two</paragraph>", $xml );
        $this->assertMatchesRegularExpression( "#<paragraph>Picture: <embed object_id='ezodf-image-0' align='center' size='(original|small|medium|large)' /></paragraph>#", $xml );
        $this->assertSame( 'A dot', $import->PendingImages['ezodf-image-0']['name'] );
        $this->assertFileExists( $import->PendingImages['ezodf-image-0']['href'] );
        $this->assertStringContainsString( '<paragraph>The end</paragraph>', $xml );

        // what eZOOImport would refuse or misread is never written
        $this->assertSame( 0, $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_TEXT, 'section' )->length );
        $this->assertSame( 1, $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_OFFICE, 'automatic-styles' )->length );
        foreach ( $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_OFFICE, 'text' )->item( 0 )->childNodes as $node )
            $this->assertSame( XML_ELEMENT_NODE, $node->nodeType, 'no text between the body elements' );
    }

    public function testTheTitleBecomesTheFirstHeadingWhenThereIsNoH1()
    {
        list( $xml ) = $this->import( '<h2>Chapter</h2><p>Text</p>', array(), 'Document title' );
        $this->assertStringStartsWith( '<header>Document title</header>', $xml );
        $this->assertStringContainsString( '<section><header>Chapter</header>', $xml );

        list( $xml ) = $this->import( '<p>Only text</p>' );
        $this->assertSame( "<paragraph>Only text</paragraph>\n", $xml );
    }

    public function testAnUnknownOrHostileImageIsNotPackaged()
    {
        $notImage = $this->workDir . '/fake.png';
        file_put_contents( $notImage, '<?php echo 1;' );
        list( $xml, $import ) = $this->import( '<p><img src="fake.png" alt="Fake"> <img src="missing.png" alt="Missing"> <img src="https://example.com/x.png" alt="Remote"></p>',
                                              array( 'fake.png' => $notImage ) );
        $this->assertSame( "<paragraph>Fake Missing Remote</paragraph>\n", $xml );
        $this->assertSame( array(), $import->PendingImages );
    }

    public function testTheSampleDocxRoundTrip()
    {
        $result = expOdfReaders::toHtml( dirname( __DIR__ ) . '/share/samples/sample.docx', 'docx', $this->workDir . '/read' );
        list( $xml ) = $this->import( $result['html'], $result['images'], $result['title'] );
        $this->assertStringStartsWith( '<header>Document converter test</header>', $xml );
        $this->assertStringContainsString( '<strong>bold</strong>', $xml );
        $this->assertStringContainsString( '<emphasize>italic</emphasize>', $xml );
        $this->assertStringContainsString( '<li><paragraph>First point</paragraph>', $xml );
        $this->assertStringContainsString( '<header>A table</header>', $xml );
        $this->assertStringContainsString( '<paragraph>Format</paragraph>', $xml );
    }

    public function testASpreadsheetRoundTrip()
    {
        $result = expOdfReaders::toHtml( dirname( __DIR__ ) . '/share/samples/sample.xlsx', 'xlsx', $this->workDir . '/read' );
        list( $xml ) = $this->import( $result['html'], $result['images'], $result['title'] );
        $this->assertStringStartsWith( '<header>Formats</header>', $xml );
        $this->assertStringContainsString( '<section><header>Formats</header>', $xml );
        $this->assertMatchesRegularExpression( '#<th\s*><paragraph>Format</paragraph>#', $xml );
        $this->assertMatchesRegularExpression( '#<td\s*><paragraph>DOCX</paragraph>#', $xml );
    }
}
