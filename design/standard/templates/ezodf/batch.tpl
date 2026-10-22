{* ezodf/batch/<id>: one import of documents, each document with its state. While it runs, ezodf_admin.js updates
   the states from ezodf/batchstatus/<id> (data-ezodf-batch) and reloads the page when it has ended.
   Input: $b (expOdfDocumentsAdmin::batchData()), $flash. *}
{def $t = 'extension/ezodf/import'}
<div class="context-block a4-page a4-ezodf"{if $b.busy} data-ezodf-batch="{concat( '/ezodf/batchstatus/', $b.id )|ezurl( 'no' )}"{/if}>
<div class="box-header"><div class="box-ml"><h1 class="context-title">{if $b.options.title}{$b.options.title|wash}{else}{'Import of documents'|i18n( $t )}{/if}</h1></div></div>
<div class="box-bc"><div class="box-ml"><div class="box-content">
{if and( is_set( $flash ), $flash )}<p class="a4-notice is-{$flash.kind|wash}" role="status">{$flash.message|wash}</p>{/if}
{if and( $b.busy, $b.cron.ok|not )}<p class="a4-notice is-warn">{'Background jobs are not running on this server right now; the documents wait until they do.'|i18n( $t )}</p>{/if}

<ul class="a4-figures" aria-label="{'Progress'|i18n( $t )|wash}">
<li class="a4-figure{if eq( $b.state, 'done' )} is-ok{elseif or( eq( $b.state, 'failed' ), eq( $b.state, 'done_with_errors' ) )} is-bad{elseif $b.busy} is-warn{/if}"><strong data-ezodf-field="batch_state">{$b.state_label|wash}</strong><span>{'State'|i18n( $t )}</span></li>
<li class="a4-figure"><strong data-ezodf-field="count_total">{$b.counts.total}</strong><span>{'Documents'|i18n( $t )}</span></li>
<li class="a4-figure is-ok"><strong data-ezodf-field="count_done">{$b.counts.done}</strong><span>{'Done'|i18n( $t )}</span></li>
<li class="a4-figure{if $b.counts.failed} is-bad{/if}"><strong data-ezodf-field="count_failed">{$b.counts.failed}</strong><span>{'Failed'|i18n( $t )}</span></li>
<li class="a4-figure"><strong data-ezodf-field="count_skipped">{$b.counts.skipped}</strong><span>{'Skipped'|i18n( $t )}</span></li>
{if $b.folders}<li class="a4-figure"><strong>{$b.folders}</strong><span>{'Folders'|i18n( $t )}</span></li>{/if}
</ul>
<div class="a4-meter a4-ezodf-meter{if eq( $b.state, 'done' )} is-ok{/if}" data-ezodf-meter role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{$b.percent}" aria-label="{'Progress'|i18n( $t )|wash}"><span class="a4-pct-{$b.percent}"></span></div>

<dl class="a4-facts">
<dt>{'Into'|i18n( $t )}</dt><dd>{if $b.parent}<a href={$b.parent.url_alias|ezurl}>{$b.parent.name|wash}</a>{else}–{/if}</dd>
<dt>{'Started'|i18n( $t )}</dt><dd>{$b.created|l10n( 'shortdatetime' )}{if $b.user} · {$b.user|wash}{/if}</dd>
<dt>{'Options'|i18n( $t )}</dt><dd>{if $b.options.tree}{'folders become folders'|i18n( $t )}{else}{'all documents in one folder'|i18n( $t )}{/if}{if $b.options.replace}, {'update existing'|i18n( $t )}{/if}{if $b.options.other_files}, {'images and files too'|i18n( $t )}{/if}{if $b.options.language}, {$b.options.language|wash}{/if}</dd>
</dl>

<div class="a4-toolbar">
{if $b.can_retry}<form method="post" action={concat( '/ezodf/batch/', $b.id )|ezurl} class="a4-ezodf-inline"><button type="submit" class="a4-btn a4-btn-primary" name="RetryFailedButton" value="1"><span class="a4-icon a4-bi-arrow-clockwise" aria-hidden="true"></span> {'Retry the failed documents'|i18n( $t )}</button></form>{/if}
{if $b.busy}<form method="post" action={concat( '/ezodf/batch/', $b.id )|ezurl} class="a4-ezodf-inline" data-a4-confirm="{'Cancel the documents that have not started yet?'|i18n( $t )|wash}"><button type="submit" class="a4-btn a4-btn-outline-danger" name="CancelButton" value="1">{'Cancel'|i18n( $t )}</button></form>{/if}
<a class="a4-btn" href={concat( '/ezodf/import', cond( $b.parent, concat( '/(node_id)/', $b.parent.node_id ), '' ) )|ezurl}>{'Import more'|i18n( $t )}</a>
<a class="a4-btn" href={'/ezodf/runs'|ezurl}>{'All imports'|i18n( $t )}</a>
</div>

<div class="a4-table-wrap" tabindex="0" role="region" aria-label="{'Documents'|i18n( $t )|wash}">
<table class="a4-table is-compact a4-ezodf-items">
<thead><tr><th>{'Document'|i18n( $t )}</th><th class="a4-ezodf-hide-narrow">{'Folder'|i18n( $t )}</th><th>{'State'|i18n( $t )}</th><th>{'Result'|i18n( $t )}</th><th class="a4-num a4-ezodf-hide-narrow">{'Seconds'|i18n( $t )}</th><th><span class="a4-sr-only">{'Actions'|i18n( $t )}</span></th></tr></thead>
<tbody>
{foreach $b.items as $i}
<tr data-ezodf-item="{$i.id|wash}">
<td class="a4-ezodf-path">{if eq( $i.kind, 'url' )}<span class="a4-icon a4-bi-link-45deg" aria-hidden="true"></span> {/if}{if eq( $i.kind, 'zip' )}<span class="a4-icon a4-bi-archive" aria-hidden="true"></span> {/if}{$i.name|wash}{if $i.url}<br /><span class="a4-meta">{$i.url|wash}</span>{/if}</td>
<td class="a4-ezodf-hide-narrow a4-ezodf-path">{$i.folder|wash}</td>
<td><span class="a4-badge{if eq( $i.state, 'done' )} is-ok{elseif eq( $i.state, 'failed' )} is-bad{elseif eq( $i.state, 'running' )} is-warn{/if}" data-ezodf-item-state>{$i.state_label|wash}</span></td>
<td class="a4-ezodf-error" data-ezodf-item-message>{if and( $i.node_id, ne( $i.state, 'failed' ) )}<a href={concat( '/content/view/full/', $i.node_id )|ezurl}>{if $i.title}{$i.title|wash}{else}{'open'|i18n( $t )}{/if}</a>{if $i.class} <span class="a4-meta">{$i.class|wash}{if $i.backend} · {$i.backend|wash}{/if}</span>{/if}{if $i.message}<br />{/if}{/if}{$i.message|wash}</td>
<td class="a4-num a4-ezodf-hide-narrow">{if $i.seconds}{$i.seconds|l10n( 'number' )}{/if}</td>
<td class="a4-end">{if and( or( eq( $i.state, 'failed' ), eq( $i.state, 'cancelled' ) ), ne( $i.code, 'refused' ) )}<form method="post" action={concat( '/ezodf/batch/', $b.id )|ezurl} class="a4-ezodf-inline"><button type="submit" class="a4-btn a4-btn-small" name="RetryItem" value="{$i.id|wash}">{'Retry'|i18n( $t )}</button></form>{/if}</td>
</tr>
{/foreach}
</tbody></table>
</div>
</div></div></div>
</div>
{undef $t}
