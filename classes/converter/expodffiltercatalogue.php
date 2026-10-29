<?php
/**
 * File containing the expOdfFilterCatalogue class.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/**
 * The import and export filters of a LibreOffice installation, read from its share/registry/*.xcd files (the type
 * detection's Filters and Types), so the converter page lists what the site really has, and the format table of
 * ezodf (what becomes what) is checked against it. Cached per installation and modification time in
 * var/<site>/ezodf/server/filters-<hash>.json.
 */
class expOdfFilterCatalogue
{
    const OOR = 'http://openoffice.org/2001/registry';

    /**
     * The families of ezodf and the document services of LibreOffice they come from.
     */
    const SERVICES = array(
        'com.sun.star.text.TextDocument' => 'text',
        'com.sun.star.text.WebDocument' => 'text',
        'com.sun.star.text.GlobalDocument' => 'text',
        'com.sun.star.sheet.SpreadsheetDocument' => 'spreadsheet',
        'com.sun.star.presentation.PresentationDocument' => 'presentation',
        'com.sun.star.drawing.DrawingDocument' => 'drawing',
        'com.sun.star.formula.FormulaProperties' => 'formula',
    );

    /**
     * The formats of the legacy OpenOffice.org 1.x and StarOffice line (marked "legacy" in the lists).
     */
    const LEGACY = array( 'sxw', 'stw', 'sxg', 'sdw', 'sxc', 'stc', 'sdc', 'sxi', 'sti', 'sdd', 'sxd', 'std', 'sda', 'sxm', 'sxs', 'vor', 'sgl' );

    /**
     * @param expOdfInstallation $installation
     * @return array list of array( 'name', 'family', 'import' => bool, 'export' => bool, 'extensions' => string[],
     *               'ui' => string, 'legacy' => bool )
     */
    public static function forInstallation( expOdfInstallation $installation )
    {
        $files = glob( $installation->root . '/share/registry/*.xcd' ) ?: array();
        $stamp = '';
        foreach ( $files as $f )
            $stamp .= $f . filemtime( $f );
        $cache = expOdfPaths::base() . '/server/filters-' . md5( $stamp ) . '.json';
        $data = expOdfPaths::readJson( $cache );
        if ( $data && isset( $data['filters'] ) )
            return $data['filters'];
        $filters = self::parse( $files );
        expOdfPaths::writeJson( $cache, array( 'version' => $installation->version, 'filters' => $filters ) );
        return $filters;
    }

    /**
     * @param string[] $files xcd files
     * @return array see forInstallation()
     */
    public static function parse( array $files )
    {
        $types = array();
        $filters = array();
        foreach ( $files as $file )
        {
            $dom = new DOMDocument();
            if ( !@$dom->load( $file, LIBXML_NONET | LIBXML_COMPACT | LIBXML_PARSEHUGE ) )
                continue;
            $xpath = new DOMXPath( $dom );
            $xpath->registerNamespace( 'oor', self::OOR );
            foreach ( array( 'Types' => &$types, 'Filters' => &$filters ) as $kind => &$target )
            {
                foreach ( $xpath->query( "//node[@oor:name='$kind']/node" ) as $node )
                {
                    $props = array();
                    foreach ( $xpath->query( 'prop', $node ) as $prop )
                    {
                        $value = $xpath->query( 'value', $prop );
                        $props[$prop->getAttributeNS( self::OOR, 'name' )] = $value->length ? trim( $value->item( 0 )->textContent ) : '';
                    }
                    $target[$node->getAttributeNS( self::OOR, 'name' )] = $props;
                }
            }
            unset( $target );
        }
        $out = array();
        foreach ( $filters as $name => $p )
        {
            $flags = isset( $p['Flags'] ) ? preg_split( '/\s+/', $p['Flags'] ) : array();
            $import = in_array( 'IMPORT', $flags, true );
            $export = in_array( 'EXPORT', $flags, true );
            if ( !$import && !$export )
                continue;
            $type = isset( $p['Type'] ) && isset( $types[$p['Type']] ) ? $types[$p['Type']] : array();
            $ext = isset( $type['Extensions'] ) && $type['Extensions'] !== '' ? preg_split( '/\s+/', $type['Extensions'] ) : array();
            $ext = array_values( array_filter( $ext, function ( $e ) { return $e !== '*' && $e !== ''; } ) );
            $service = isset( $p['DocumentService'] ) ? $p['DocumentService'] : '';
            $family = isset( self::SERVICES[$service] ) ? self::SERVICES[$service] : 'other';
            $out[] = array( 'name' => $name, 'family' => $family, 'import' => $import, 'export' => $export, 'extensions' => $ext,
                            'ui' => isset( $p['UIName'] ) ? $p['UIName'] : '', 'mime' => isset( $type['MediaType'] ) ? $type['MediaType'] : '',
                            'legacy' => (bool)array_intersect( $ext, self::LEGACY ) );
        }
        usort( $out, function ( $a, $b ) { return array( $a['family'], $a['name'] ) <=> array( $b['family'], $b['name'] ); } );
        return $out;
    }

    /** @return array extension => list of families an installation can import it as */
    public static function importExtensions( array $filters )
    {
        $out = array();
        foreach ( $filters as $f )
            if ( $f['import'] && $f['family'] !== 'other' && $f['family'] !== 'formula' )
                foreach ( $f['extensions'] as $ext )
                    $out[strtolower( $ext )][$f['family']] = true;
        ksort( $out );
        return array_map( 'array_keys', $out );
    }
}
