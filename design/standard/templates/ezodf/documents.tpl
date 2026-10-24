{* ezodf/import: Import documents. Files, a whole folder (its tree becomes folders), ZIP archives and URLs go into a
   target folder as a batch of background jobs. With JavaScript (ezodf_admin.js, data-ezodf-upload) the files are
   uploaded one by one with progress; without it the form sends them at once. Input: $node (the target or null),
   $error, $flash, $d (expOdfDocumentsAdmin::pageData()). *}
{def $t = 'extension/ezodf/import'}
<div class="context-block a4-page a4-ezodf">
<div class="box-header"><div class="box-ml">
<h1 class="context-title">{'Import documents'|i18n( $t )}</h1>
</div></div>
<div class="box-bc"><div class="box-ml"><div class="box-content">

<p class="a4-intro">{'Word, OpenDocument, older OpenOffice.org and StarOffice files, RTF, PDF, Markdown, spreadsheets and presentations become articles; a folder you choose keeps its tree as folders; a ZIP archive is unpacked the same way. Every document is converted and published in the background, so you can leave the page.'|i18n( $t )}</p>

{if and( is_set( $flash ), $flash )}<p class="a4-notice is-{$flash.kind|wash}" role="status">{$flash.message|wash}</p>{/if}
{if $error}<p class="a4-notice is-bad" role="alert">{$error|wash}</p>{/if}
{if $d.php_only}
<p class="a4-notice is-warn">{'No LibreOffice is set up, so only these formats can be imported: %list.'|i18n( $t,, hash( '%list', $d.php_formats|implode( ', ' ) ) )}{if $d.can_converter} <a href={'/ezodf/converter'|ezurl}>{'Install LibreOffice on the Document converter page.'|i18n( $t )}</a>{/if}</p>
{/if}
{if $d.cron.ok|not}
<p class="a4-notice is-warn">{'Background jobs are not running on this server right now, so the documents wait until they do.'|i18n( $t )}{if $d.can_converter} <a href={'/ezodf/converter'|ezurl}>{'Run them from the Document converter page.'|i18n( $t )}</a>{/if}</p>
{/if}

<form class="a4-form" method="post" action={'/ezodf/import'|ezurl} enctype="multipart/form-data"
      data-ezodf-upload="{'/ezodf/upload'|ezurl( 'no' )}" data-ezodf-max-files="{$d.max_files}" data-ezodf-max-mb="{$d.max_mb}">
{if $node}<input type="hidden" name="NodeID" value="{$node.node_id}" />{/if}

<section class="a4-card" aria-labelledby="ezodf-target">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-target"><span class="a4-icon a4-bi-folder" aria-hidden="true"></span> {'Where'|i18n( $t )}</h2></div>
<div class="a4-card-body">
{if $node}
<p>{'Into'|i18n( $t )} <a href={$node.url_alias|ezurl}><strong>{$node.name|wash}</strong></a> <span class="a4-meta">{$node.path_identification_string|wash}</span></p>
{else}
<p class="a4-help">{'Choose the folder the documents go into.'|i18n( $t )}</p>
{/if}
<button type="submit" class="a4-btn" name="ChooseTargetButton" value="1"><span class="a4-icon a4-bi-folder" aria-hidden="true"></span> {if $node}{'Choose another folder'|i18n( $t )}{else}{'Choose a folder'|i18n( $t )}{/if}</button>
</div>
</section>

<section class="a4-card" aria-labelledby="ezodf-what">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-what"><span class="a4-icon a4-bi-upload" aria-hidden="true"></span> {'What'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<div class="a4-ezodf-drop" data-ezodf-drop>
<p class="a4-ezodf-drop-text"><span class="a4-icon a4-bi-files" aria-hidden="true"></span> {'Drop files or folders here, or choose them:'|i18n( $t )}</p>
<div class="a4-toolbar a4-ezodf-pickers">
<label class="a4-btn" for="ezodf-files"><span class="a4-icon a4-bi-file-earmark" aria-hidden="true"></span> {'Files or ZIP archives…'|i18n( $t )}</label>
<input class="a4-sr-only" type="file" id="ezodf-files" name="Files[]" multiple="multiple" accept="{$d.accept|wash}" data-ezodf-pick="files" />
<label class="a4-btn" for="ezodf-folder"><span class="a4-icon a4-bi-folder" aria-hidden="true"></span> {'A folder…'|i18n( $t )}</label>
<input class="a4-sr-only" type="file" id="ezodf-folder" name="Folder[]" multiple="multiple" webkitdirectory="webkitdirectory" directory="directory" data-ezodf-pick="folder" />
</div>
<p class="a4-help">{'Up to %files files and %mb MB in one import; one document up to %doc MB.'|i18n( $t,, hash( '%files', $d.max_files, '%mb', $d.max_mb, '%doc', $d.max_doc_mb ) )}
<span data-ezodf-nojs>{'Without JavaScript at most %n files are sent at once.'|i18n( $t,, hash( '%n', $d.max_uploads ) )}</span></p>
</div>
<div class="a4-ezodf-selection" data-ezodf-selection hidden="hidden">
<p class="a4-meta" data-ezodf-field="selection_summary"></p>
<div class="a4-table-wrap" tabindex="0" role="region" aria-label="{'Chosen files'|i18n( $t )|wash}">
<table class="a4-table is-compact"><thead><tr><th>{'File'|i18n( $t )}</th><th class="a4-num">{'Size'|i18n( $t )}</th><th>{'State'|i18n( $t )}</th></tr></thead>
<tbody data-ezodf-list="selection"></tbody></table>
</div>
<button type="button" class="a4-btn a4-btn-small" data-ezodf-clear>{'Clear the selection'|i18n( $t )}</button>
</div>
<div class="a4-field a4-field-wide">
<label for="ezodf-urls">{'Documents from the web (one address per line)'|i18n( $t )}</label>
<textarea class="a4-input a4-ezodf-urls" id="ezodf-urls" name="URLs" rows="3" placeholder="https://example.org/report.pdf"></textarea>
<span class="a4-help">{'Only public http and https addresses; each one is downloaded by its background job.'|i18n( $t )}</span>
</div>
</div>
</section>

<section class="a4-card" aria-labelledby="ezodf-how">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-how"><span class="a4-icon a4-bi-sliders" aria-hidden="true"></span> {'How'|i18n( $t )}</h2></div>
<div class="a4-card-body a4-ezodf-fields">
<fieldset class="a4-field a4-ezodf-wide"><legend>{'Options'|i18n( $t )}</legend>
<label class="a4-check"><input type="checkbox" name="Tree" value="1" checked="checked" /> {'Folders become folders (the tree of a chosen folder or a ZIP archive)'|i18n( $t )}</label>
<label class="a4-check"><input type="checkbox" name="Replace" value="1" /> {'Update what was imported before from the same file and place (a new version instead of a second item)'|i18n( $t )}</label>
<label class="a4-check"><input type="checkbox" name="OtherFiles" value="1" checked="checked" /> {'Import images and other files as image and file items'|i18n( $t )}</label>
{if $d.ai}<label class="a4-check"><input type="checkbox" name="AIAssist" value="1" /> {'Suggest a summary, keywords and image texts with AI (you confirm them on the batch page)'|i18n( $t )}</label>{/if}
</fieldset>
{if gt( $d.languages|count, 1 )}
<div class="a4-field"><label for="ezodf-lang">{'Language'|i18n( $t )}</label>
<select class="a4-input" id="ezodf-lang" name="Language">{foreach $d.languages as $l}<option value="{$l.locale|wash}">{$l.name|wash}</option>{/foreach}</select></div>
{/if}
<div class="a4-field"><label for="ezodf-title">{'Name of this import (optional)'|i18n( $t )}</label>
<input class="a4-input" type="text" id="ezodf-title" name="Title" maxlength="120" /></div>
</div>
</section>

<div class="a4-toolbar">
<button type="submit" class="a4-btn a4-btn-primary a4-btn-large" name="StartImportButton" value="1" data-ezodf-start{if or( $node|not, $error )} disabled="disabled"{/if}><span class="a4-icon a4-bi-play-fill" aria-hidden="true"></span> {'Import'|i18n( $t )}</button>
<p class="a4-meta" role="status" aria-live="polite" data-ezodf-field="upload_status"></p>
</div>
<div class="a4-meter a4-ezodf-meter" data-ezodf-meter hidden="hidden" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="{'Upload'|i18n( $t )|wash}"><span class="a4-pct-0"></span></div>
</form>

<details class="a4-fold a4-ezodf-section">
<summary>{'Formats'|i18n( $t )}{if $d.converter} ({$d.converter|wash}{if $d.server}, {'conversion server running'|i18n( $t )}{/if}){/if}</summary>
<dl class="a4-facts">
{foreach $d.formats as $family => $list}
<dt>{if eq( $family, 'text' )}{'Text'|i18n( $t )}{elseif eq( $family, 'spreadsheet' )}{'Spreadsheets (a table per sheet)'|i18n( $t )}{elseif eq( $family, 'presentation' )}{'Presentations (a section per slide)'|i18n( $t )}{elseif eq( $family, 'pdf' )}{'PDF (its text)'|i18n( $t )}{elseif eq( $family, 'markup' )}{'Markdown'|i18n( $t )}{else}{$family|wash}{/if}</dt>
<dd class="a4-ezodf-exts">{$list|implode( ', ' )|wash}</dd>
{/foreach}
</dl>
</details>

{if $d.recent|count}
<section class="a4-section a4-ezodf-section" aria-labelledby="ezodf-recent">
<div class="a4-section-head"><h2 class="a4-h2" id="ezodf-recent">{'Recent imports'|i18n( $t )}</h2> <a href={'/ezodf/runs'|ezurl}>{'All imports'|i18n( $t )}</a></div>
{include uri='design:ezodf/parts/runs_table.tpl' runs=$d.recent}
</section>
{/if}


{* the other import and export tools of the transfer registry (Exponential 6.0.15 and later; nothing on an older kernel) *}
{if ezini_hasvariable( 'Service_transfer.registry', 'Class', 'kernelservices.ini' )}{include uri='design:transfer/looking_for.tpl' tool='transfer.ezodf'}{/if}

</div></div></div>
</div>
{undef $t}
