{* The imports of documents as a table. Input: $runs (expOdfDocumentsAdmin::runs()). *}
{def $t = 'extension/ezodf/import'}
<div class="a4-table-wrap" tabindex="0" role="region" aria-label="{'Imports of documents'|i18n( $t )|wash}">
<table class="a4-table is-compact">
<thead><tr><th>{'Started'|i18n( $t )}</th><th>{'Import'|i18n( $t )}</th><th>{'Into'|i18n( $t )}</th><th>{'State'|i18n( $t )}</th>
<th class="a4-num">{'Documents'|i18n( $t )}</th><th class="a4-num">{'Done'|i18n( $t )}</th><th class="a4-num">{'Failed'|i18n( $t )}</th><th class="a4-ezodf-hide-narrow">{'By'|i18n( $t )}</th></tr></thead>
<tbody>
{foreach $runs as $r}
<tr>
<td class="a4-nowrap"><a href={concat( '/ezodf/batch/', $r.id )|ezurl}>{$r.created|l10n( 'shortdatetime' )}</a></td>
<td>{if $r.title}{$r.title|wash}{else}<span class="a4-muted">{'(no name)'|i18n( $t )}</span>{/if}</td>
<td>{$r.parent_name|wash}</td>
<td><span class="a4-badge{if eq( $r.state, 'done' )} is-ok{elseif or( eq( $r.state, 'failed' ), eq( $r.state, 'done_with_errors' ) )} is-bad{elseif eq( $r.state, 'running' )} is-warn{/if}">{$r.state_label|wash}</span></td>
<td class="a4-num">{$r.counts.total}</td><td class="a4-num">{$r.counts.done}</td><td class="a4-num">{$r.counts.failed}</td>
<td class="a4-ezodf-hide-narrow">{$r.user|wash}</td>
</tr>
{/foreach}
</tbody></table>
</div>
{undef $t}
