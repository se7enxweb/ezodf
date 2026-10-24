{* ezodf/runs: the imports of documents, newest first. Input: $runs. *}
{def $t = 'extension/ezodf/import'}
<div class="context-block a4-page a4-ezodf">
<div class="box-header"><div class="box-ml"><h1 class="context-title">{'Document imports'|i18n( $t )}</h1></div></div>
<div class="box-bc"><div class="box-ml"><div class="box-content">
<div class="a4-toolbar"><a class="a4-btn a4-btn-primary" href={'/ezodf/import'|ezurl}><span class="a4-icon a4-bi-upload" aria-hidden="true"></span> {'Import documents'|i18n( $t )}</a></div>
{if $runs|count}
{include uri='design:ezodf/parts/runs_table.tpl' runs=$runs}
<p class="a4-help">{'Uploaded files and the lists of imports are removed after the days set on the Document converter page; the items made from them stay.'|i18n( $t )}</p>
{else}
<p class="a4-empty">{'No documents have been imported yet.'|i18n( $t )}</p>
{/if}
</div></div></div>
</div>
{undef $t}
