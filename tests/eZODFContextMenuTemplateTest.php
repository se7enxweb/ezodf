<?php
/**
 * The OpenOffice.org entry of the content tree and sub-items context menus is declared in data-menu-* attributes
 * (ezpopupmenu.js), without a script element or an event handler attribute, so it also works under a Content
 * Security Policy without unsafe-inline. Reads the templates only.
 *
 * @license GNU General Public License v2.0
 */

use PHPUnit\Framework\TestCase;

class eZODFContextMenuTemplateTest extends TestCase
{
    private function template( $name )
    {
        $file = dirname( __DIR__ ) . '/design/standard/templates/node/' . $name;
        $this->assertFileExists( $file );
        return file_get_contents( $file );
    }

    public function testNoTemplateHasAScriptOrAnEventHandlerAttribute()
    {
        foreach ( array( 'oocontextmenu.tpl', 'oosubitemscontextmenu.tpl', 'oocontextsubmenu.tpl' ) as $name )
        {
            $source = $this->template( $name );
            $this->assertDoesNotMatchRegularExpression( '/<script\b/i', $source, $name );
            $this->assertDoesNotMatchRegularExpression( '/\son[a-z]+\s*=/i', $source, $name );
            $this->assertDoesNotMatchRegularExpression( '/\sstyle\s*=/i', $source, $name );
        }
    }

    public function testTheEntriesOpenTheDeclaredSubmenu()
    {
        $this->assertMatchesRegularExpression( '/id="menu-openoffice"[^>]*data-menu-submenu="OpenOffice"/', $this->template( 'oocontextmenu.tpl' ) );
        $this->assertMatchesRegularExpression( '/id="child-menu-openoffice"[^>]*data-menu-submenu="OpenOffice"/', $this->template( 'oosubitemscontextmenu.tpl' ) );
    }

    public function testEverySubmenuItemSubmitsAFormTheContextMenuDeclares()
    {
        $submenu = $this->template( 'oocontextsubmenu.tpl' );
        $this->assertMatchesRegularExpression( '/<div class="popupmenu" id="OpenOffice" data-menu-depth="1">/', $submenu );
        $this->assertSame( 5, preg_match_all( '/data-menu-form="([a-z-]+)"/', $submenu, $forms ) );
        $menu = $this->template( 'oocontextmenu.tpl' );
        foreach ( $forms[1] as $form )
        {
            $this->assertStringContainsString( 'id="' . $form . '"', $menu, $form );
        }
    }
}
