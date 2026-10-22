{* The left menu of ezodf's pages (Documents): menu.ini [Leftmenu_ezodf] drawn by the administration's shared menu
   template, with its access checks and the current-page mark; then the other import and export tools of the
   transfer registry (Exponential 6.0.15 and later). *}
{include uri='design:parts/ini_menu.tpl' ini_section='Leftmenu_ezodf'
    i18n_hash=hash(
    'Documents',  'Documents'|i18n( 'extension/ezodf/menu' ),
    'import',     'Import documents'|i18n( 'extension/ezodf/menu' ),
    'converter',  'Document converter'|i18n( 'extension/ezodf/menu' ),
    'queue',      'Job queue'|i18n( 'extension/ezodf/menu' ),
)}
{if ezini_hasvariable( 'Service_transfer.registry', 'Class', 'kernelservices.ini' )}{include uri='design:transfer/left_menu.tpl' current='transfer.ezodf'}{/if}
{if ezini( 'ExtensionSettings', 'ActiveExtensions', 'site.ini' )|contains( 'ezjscore' )}{include uri='design:parts/left_width_control.tpl'}{/if}
