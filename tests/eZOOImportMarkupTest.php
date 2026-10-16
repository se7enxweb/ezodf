<?php
/**
 * The import builds eZXMLText markup as a string. Only #text nodes were escaped: the text of a span (bold,
 * italic), of a sequence, date or creator field, a link's address and text and an inline custom style's name
 * went in raw, so a span holding "<embed object_id='123'/>" became a real embed of object 123. Kernel-free.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

class eZOOImportMarkupTest extends TestCase
{
    const NS = " xmlns:office='urn:oasis:names:tc:opendocument:xmlns:office:1.0'
                 xmlns:text='urn:oasis:names:tc:opendocument:xmlns:text:1.0'
                 xmlns:style='urn:oasis:names:tc:opendocument:xmlns:style:1.0'
                 xmlns:fo='urn:oasis:names:tc:opendocument:xmlns:xsl-fo-compatible:1.0'
                 xmlns:xlink='http://www.w3.org/1999/xlink'";

    private function paragraph( $inner, $styles = '' )
    {
        $dom = new DOMDocument();
        $this->assertTrue( $dom->loadXML( "<office:document-content" . self::NS . "><office:automatic-styles>$styles</office:automatic-styles><text:p>$inner</text:p></office:document-content>" ) );
        $import = new eZOOImport();
        $styleList = $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_OFFICE, 'automatic-styles' )->item( 0 );
        $import->AutomaticStyles = $styleList->childNodes;
        $out = '';
        foreach ( $dom->getElementsByTagNameNS( eZOOImport::NAMESPACE_TEXT, 'p' )->item( 0 )->childNodes as $child )
        {
            $out .= $import->handleInlineNode( $child );
        }
        return $out;
    }

    private function assertNoInjectedTag( $markup )
    {
        $dom = new DOMDocument();
        $this->assertTrue( $dom->loadXML( "<paragraph>$markup</paragraph>" ), "well formed: $markup" );
        $this->assertSame( 0, $dom->getElementsByTagName( 'embed' )->length, $markup );
    }

    public function testTheTextOfABoldSpanStaysText()
    {
        $styles = "<style:style style:name='T1'><style:text-properties fo:font-weight='bold'/></style:style>";
        $markup = $this->paragraph( "<text:span text:style-name='T1'>&lt;embed object_id='123'/&gt;</text:span>", $styles );
        $this->assertNoInjectedTag( $markup );
        $this->assertStringContainsString( '<strong>&lt;embed object_id=&apos;123&apos;/&gt;</strong>', $markup );
    }

    public function testFieldsAndLinksStayText()
    {
        foreach ( array( "<text:sequence>&lt;embed object_id='1'/&gt;</text:sequence>",
                         "<text:date>&lt;embed object_id='2'/&gt;</text:date>",
                         "<text:initial-creator>&lt;embed object_id='3'/&gt;</text:initial-creator>",
                         "<text:a xlink:href=\"x'&gt;&lt;embed object_id='4'/&gt;&lt;link href='y\">&lt;embed object_id='5'/&gt;</text:a>",
                         "<text:span text:style-name=\"eZCustominline_20_x'&gt;&lt;embed object_id='6'/&gt;&lt;custom name='y\">t</text:span>" ) as $inner )
        {
            $this->assertNoInjectedTag( $this->paragraph( $inner ) );
        }
    }

    public function testPlainTextIsStillEscaped()
    {
        $this->assertSame( 'a &amp; b &lt;c&gt; &quot;d&quot; &apos;e&apos;', $this->paragraph( "a &amp; b &lt;c&gt; \"d\" 'e'" ) );
    }
}
