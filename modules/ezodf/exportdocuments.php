<?php
/**
 * ezodf/export (policy ezodf/export): items out as documents.
 *   ezodf/export                                   the page: choose an item
 *   ezodf/export/(node_id)/<n>                      the page of an item: its formats, its folder as a ZIP
 *   ezodf/export/(node_id)/<n>/(export_type)/<f>    the download (also a POST of NodeID and ExportType, as the
 *                                                   context menu of 6.x sends it; PDF and Word keep their meaning)
 *   POST SubtreeButton, Format                      the item and everything below it as a ZIP, in the background
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$t = 'extension/ezodf/export';
$isPost = strtoupper( (string)eZSys::serverVariable( 'REQUEST_METHOD', true ) ) === 'POST';

if ( $http->hasPostVariable( 'BrowseActionName' ) && $http->postVariable( 'BrowseActionName' ) === 'EzodfExportSource' )
{
    $selected = eZContentBrowse::result( 'EzodfExportSource' );
    $nodeID = is_array( $selected ) && $selected ? (int)$selected[0] : 0;
    return $Module->redirectTo( '/ezodf/export' . ( $nodeID ? '/(node_id)/' . $nodeID : '' ) );
}
if ( $isPost && $http->hasPostVariable( 'ChooseSourceButton' ) )
{
    eZContentBrowse::browse( array( 'action_name' => 'EzodfExportSource', 'description_template' => 'design:ezodf/browse_place.tpl',
                                    'content' => array(), 'from_page' => '/ezodf/export', 'cancel_page' => '/ezodf/export' ), $Module );
    return;
}

$nodeID = isset( $Params['NodeID'] ) && is_numeric( $Params['NodeID'] ) ? (int)$Params['NodeID'] : 0;
if ( $http->hasPostVariable( 'NodeID' ) )
    $nodeID = (int)$http->postVariable( 'NodeID' );
$format = isset( $Params['ExportType'] ) ? expOdfExporter::format( $Params['ExportType'] ) : '';
if ( $http->hasPostVariable( 'ExportType' ) )
    $format = expOdfExporter::format( $http->postVariable( 'ExportType' ) );
$node = $nodeID ? eZContentObjectTreeNode::fetch( $nodeID ) : null;
$error = '';
if ( $nodeID && ( !$node || !$node->canRead() ) )
{
    $error = ezpI18n::tr( $t, 'The item does not exist or you may not read it.' );
    $node = null;
}

if ( $node && $isPost && $http->hasPostVariable( 'SubtreeButton' ) )
{
    $f = expOdfExporter::format( $http->postVariable( 'Format' ) );
    if ( $f === '' || !isset( expOdfExporter::available()[$f] ) )
        $http->setSessionVariable( 'EzodfExportFlash', array( 'kind' => 'bad', 'message' => ezpI18n::tr( $t, 'This format cannot be written here.' ) ) );
    else
    {
        expOdfExporter::startSubtree( $node, $f );
        $http->setSessionVariable( 'EzodfExportFlash', array( 'kind' => 'ok', 'message' => ezpI18n::tr( $t, 'The export has started in the background; its ZIP appears below when it is ready.' ) ) );
    }
    return $Module->redirectTo( '/ezodf/export/(node_id)/' . $nodeID );
}

// the context menu of 6.x posts a NodeID alone for the OpenDocument download
if ( $node && $isPost && $format === '' && $http->hasPostVariable( 'NodeID' ) )
    $format = 'odt';
if ( $node && $format !== '' )
{
    if ( !isset( expOdfExporter::available()[$format] ) )
        $error = ezpI18n::tr( $t, 'Writing %format needs LibreOffice: install it on the Document converter page.', null, array( '%format' => strtoupper( $format ) ) );
    else
    {
        try
        {
            expOdfExporter::send( expOdfExporter::export( $node, $format ) );
        }
        catch ( expOdfConverterException $e )
        {
            $error = ezpI18n::tr( $t, 'The item could not be exported: %message', null, array( '%message' => $e->getMessage() ) );
        }
    }
}

$flash = $http->hasSessionVariable( 'EzodfExportFlash' ) ? $http->sessionVariable( 'EzodfExportFlash' ) : null;
$http->removeSessionVariable( 'EzodfExportFlash' );
$exports = expOdfExporter::listExports();
$busy = false;
foreach ( $exports as $e )
    if ( in_array( $e['state'], array( 'queued', 'running' ), true ) )
        $busy = true;
$tpl = eZTemplate::factory();
$tpl->setVariable( 'node', $node );
$tpl->setVariable( 'error', $error );
$tpl->setVariable( 'flash', $flash );
$tpl->setVariable( 'formats', expOdfExporter::available() );
$tpl->setVariable( 'all_formats', expOdfExporter::FORMATS );
$tpl->setVariable( 'exports', $exports );
$tpl->setVariable( 'busy', $busy );
$tpl->setVariable( 'can_converter', expOdfDocumentsAdmin::can( 'converter' ) );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:ezodf/export_documents.tpl' );
$Result['navigation_part'] = 'ezsetupnavigationpart';
$Result['left_menu'] = 'design:ezodf/parts/menu.tpl';
$Result['path'] = array( array( 'url' => false, 'text' => ezpI18n::tr( 'extension/ezodf/converter', 'Documents' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( $t, 'Export documents' ) ) );
