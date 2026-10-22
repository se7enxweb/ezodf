<?php
/**
 * ezodf/runs (policy ezodf/import): the imports of documents, newest first (the user's own; every user's with
 * ezodf/converter), with their counts and a link to each batch.
 *
 * @copyright Copyright (C) 1998 - 2026 7x & Exponential Foundation. All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package ezodf
 */
$tpl = eZTemplate::factory();
$tpl->setVariable( 'runs', expOdfDocumentsAdmin::runs() );
$Result = array();
$Result['content'] = $tpl->fetch( 'design:ezodf/runs.tpl' );
$Result['navigation_part'] = 'ezsetupnavigationpart';
$Result['left_menu'] = 'design:ezodf/parts/menu.tpl';
$Result['path'] = array( array( 'url' => false, 'text' => ezpI18n::tr( 'extension/ezodf/converter', 'Documents' ) ),
                         array( 'url' => false, 'text' => ezpI18n::tr( 'extension/ezodf/import', 'Document imports' ) ) );
