<?php
/**
 * ezodf/converter (policy ezodf/converter): the Document converter page. Every change is a POST (ezformtoken adds
 * its token to the forms); long work starts as a background job and the page shows it live.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */

/** @var eZModule $Module */
$Module = $Params['Module'];
$http = eZHTTPTool::instance();
$isPost = strtoupper( (string)eZSys::serverVariable( 'REQUEST_METHOD', true ) ) === 'POST';
if ( $isPost && $http->hasPostVariable( 'ConverterAction' ) )
{
    $result = expOdfConverterAdmin::action( (string)$http->postVariable( 'ConverterAction' ), $http );
    $http->setSessionVariable( 'EzodfConverterFlash', $result );
    return $Module->redirectTo( '/ezodf/converter' );
}
$flash = $http->hasSessionVariable( 'EzodfConverterFlash' ) ? $http->sessionVariable( 'EzodfConverterFlash' ) : null;
$http->removeSessionVariable( 'EzodfConverterFlash' );

$tpl = eZTemplate::factory();
$tpl->setVariable( 'flash', $flash );
$tpl->setVariable( 'c', expOdfConverterAdmin::data() );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:ezodf/converter.tpl' );
$Result['navigation_part'] = 'ezsetupnavigationpart';
$Result['left_menu'] = 'design:ezodf/parts/menu.tpl';
$Result['path'] = array( array( 'url' => false, 'text' => ezpI18n::tr( 'extension/ezodf/converter', 'Documents' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/ezodf/converter', 'Document converter' ) ) );
