{* ezodf/export: items out as documents. Input: $node (or null), $error, $flash, $formats (format => label, what can
   be written now), $all_formats, $exports (the user's folder exports), $busy, $can_converter. *}
{def $t = 'extension/ezodf/export'}
<div class="context-block a4-page a4-ezodf"{if $busy} data-ezodf-live="{'/ezodf/exportstatus'|ezurl( 'no' )}"{/if}>
<div class="box-header"><div class="box-ml"><h1 class="context-title">{'Export documents'|i18n( $t )}</h1></div></div>
<div class="box-bc"><div class="box-ml"><div class="box-content">
<p class="a4-intro">{'Download an item as a document to edit in a word processor or to pass on, or a folder with everything in it as a ZIP of documents.'|i18n( $t )}</p>
{if and( is_set( $flash ), $flash )}<p class="a4-notice is-{$flash.kind|wash}" role="status">{$flash.message|wash}</p>{/if}
{if $error}<p class="a4-notice is-bad" role="alert">{$error|wash}</p>{/if}
{if lt( $formats|count, 2 )}<p class="a4-notice is-warn">{'Without LibreOffice only OpenDocument text can be written.'|i18n( $t )}{if $can_converter} <a href={'/ezodf/converter'|ezurl}>{'Install LibreOffice on the Document converter page.'|i18n( $t )}</a>{/if}</p>{/if}

<section class="a4-card" aria-labelledby="ezodf-source">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-source"><span class="a4-icon a4-bi-file-earmark" aria-hidden="true"></span> {'Item'|i18n( $t )}</h2></div>
<div class="a4-card-body">
{if $node}<p><a href={$node.url_alias|ezurl}><strong>{$node.name|wash}</strong></a> <span class="a4-meta">{$node.class_name|wash} · {$node.path_identification_string|wash}</span></p>{/if}
<form method="post" action={'/ezodf/export'|ezurl} class="a4-ezodf-inline"><button type="submit" class="a4-btn" name="ChooseSourceButton" value="1"><span class="a4-icon a4-bi-search" aria-hidden="true"></span> {if $node}{'Choose another item'|i18n( $t )}{else}{'Choose an item'|i18n( $t )}{/if}</button></form>
</div>
</section>

{if $node}
<section class="a4-card" aria-labelledby="ezodf-one">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-one"><span class="a4-icon a4-bi-download" aria-hidden="true"></span> {'Download this item as'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<ul class="a4-plain a4-ezodf-formats-list">
{foreach $formats as $f => $label}
<li><a class="a4-btn{if or( eq( $f, 'docx' ), eq( $f, 'pdf' ), eq( $f, 'odt' ) )} a4-btn-primary{/if}" href={concat( '/ezodf/export/(node_id)/', $node.node_id, '/(export_type)/', $f )|ezurl} download="download"><span class="a4-icon {if eq( $f, 'pdf' )}a4-bi-file-earmark-pdf{else}a4-bi-file-earmark{/if}" aria-hidden="true"></span> {$label|wash}</a></li>
{/foreach}
</ul>
</div>
</section>

{if gt( $node.children_count, 0 )}
<section class="a4-card" aria-labelledby="ezodf-tree">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-tree"><span class="a4-icon a4-bi-archive" aria-hidden="true"></span> {'This folder and everything in it'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<p class="a4-help">{'One document per item with text, in the folders of the site, as a ZIP; written in the background (%n items below this one).'|i18n( $t,, hash( '%n', $node.children_count ) )}</p>
<form method="post" action={concat( '/ezodf/export/(node_id)/', $node.node_id )|ezurl} class="a4-form">
<div class="a4-field"><label for="ezodf-format">{'Format'|i18n( $t )}</label>
<select class="a4-input" id="ezodf-format" name="Format">{foreach $formats as $f => $label}<option value="{$f}"{if eq( $f, 'docx' )} selected="selected"{/if}>{$label|wash}</option>{/foreach}</select></div>
<div class="a4-toolbar"><button type="submit" class="a4-btn a4-btn-primary" name="SubtreeButton" value="1"><span class="a4-icon a4-bi-archive" aria-hidden="true"></span> {'Export as ZIP'|i18n( $t )}</button></div>
</form>
</div>
</section>
{/if}
{/if}

{if $exports|count}
<section class="a4-section a4-ezodf-section" aria-labelledby="ezodf-exports">
<div class="a4-section-head"><h2 class="a4-h2" id="ezodf-exports">{'Folder exports'|i18n( $t )}</h2></div>
<div class="a4-table-wrap" tabindex="0" role="region" aria-labelledby="ezodf-exports">
<table class="a4-table is-compact">
<thead><tr><th>{'Started'|i18n( $t )}</th><th>{'Folder'|i18n( $t )}</th><th>{'Format'|i18n( $t )}</th><th>{'State'|i18n( $t )}</th><th class="a4-num">{'Documents'|i18n( $t )}</th><th><span class="a4-sr-only">{'Download'|i18n( $t )}</span></th></tr></thead>
<tbody>
{foreach $exports as $e}
<tr>
<td class="a4-nowrap">{$e.created|l10n( 'shortdatetime' )}</td>
<td><a href={concat( '/ezodf/export/(node_id)/', $e.node_id )|ezurl}>{$e.name|wash}</a></td>
<td>{$e.format|upcase|wash}</td>
<td><span class="a4-badge{if eq( $e.state, 'done' )} is-ok{elseif eq( $e.state, 'failed' )} is-bad{else} is-warn{/if}">{if eq( $e.state, 'done' )}{'done'|i18n( $t )}{elseif eq( $e.state, 'failed' )}{'failed'|i18n( $t )}{elseif eq( $e.state, 'running' )}{'running'|i18n( $t )}{else}{'queued'|i18n( $t )}{/if}</span>{if is_set( $e.message )} <span class="a4-ezodf-error">{$e.message|wash}</span>{/if}</td>
<td class="a4-num">{$e.done}{if $e.failed} <span class="a4-bad-text">({'%n failed'|i18n( $t,, hash( '%n', $e.failed ) )})</span>{/if}</td>
<td class="a4-end">{if and( eq( $e.state, 'done' ), is_set( $e.file ) )}<a class="a4-btn a4-btn-small" href={concat( '/ezodf/exportfile/', $e.id )|ezurl}><span class="a4-icon a4-bi-download" aria-hidden="true"></span> ZIP{if is_set( $e.size )} · {$e.size|si( 'byte', 'auto' )}{/if}</a>{/if}</td>
</tr>
{/foreach}
</tbody></table>
</div>
</section>
{/if}
</div></div></div>
</div>
{undef $t}
