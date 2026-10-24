{* ezodf/converter: the Document converter. Status, the LibreOffice installation (one-click install, the installed one,
   update, remove), the conversion server, test conversions, a remote server, the limits, the formats, the log and
   the cleanup. Shared admin components only (.a4-*), the rest in stylesheets/ezodf.css (.a4-ezodf-*); the live
   parts are updated by javascript/ezodf_admin.js from ezodf/converterstatus. Input: $c (expOdfConverterAdmin::data()),
   $flash. *}
{def $here = '/ezodf/converter'
     $t = 'extension/ezodf/converter'}
<div class="context-block a4-page a4-ezodf"{if $c.busy} data-ezodf-live="{'/ezodf/converterstatus'|ezurl( 'no' )}"{/if}>
<div class="box-header"><div class="box-ml">
<h1 class="context-title">{'Document converter'|i18n( $t )}</h1>
</div></div>
<div class="box-bc"><div class="box-ml"><div class="box-content">

<p class="a4-intro">{'Documents in other formats (Word, older OpenOffice.org and StarOffice files, spreadsheets, presentations, PDF) are converted with LibreOffice, which runs on this server in a sandbox without network access. Without LibreOffice, ezodf reads OpenDocument, OpenOffice.org 1.x text and a few more formats itself.'|i18n( $t )}</p>

{if and( is_set( $flash ), $flash )}<p class="a4-notice is-{$flash.kind|wash}" role="status">{$flash.message|wash}</p>{/if}

{if $c.writable|not}
<p class="a4-notice is-warn">{'The configuration is managed by the deployment (site.ini [ConfigurationSettings] ManagedByDeployment): this page shows the converter, but nothing can be changed here.'|i18n( $t )}</p>
{/if}

{if $c.cron.ok|not}
<div class="a4-notice is-warn" role="status">
<p class="a4-notice-title">{'Background jobs are not running'|i18n( $t )}</p>
<p>{if $c.cron.last}{'The cron job that runs the job queue last ran %minutes minutes ago.'|i18n( $t,, hash( '%minutes', $c.cron.minutes ) )}{else}{'No cron job has run the job queue yet.'|i18n( $t )}{/if}
{'Installing LibreOffice, starting the conversion server and the test conversions wait for it. Run them now from here, and ask your hosting to run runcronjobs.php every minute (Help: Document converter).'|i18n( $t )}</p>
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="runpending" />
<button type="submit" class="a4-btn a4-btn-primary"><span class="a4-icon a4-bi-play-fill" aria-hidden="true"></span> {'Run pending jobs now'|i18n( $t )}{if $c.cron.pending} ({$c.cron.pending}){/if}</button></form>
</div>
{/if}

<ul class="a4-figures" aria-label="{'Status'|i18n( $t )|wash}">
<li class="a4-figure{if $c.active} is-ok{else} is-warn{/if}"><strong>{if $c.active}{$c.active.version|wash}{else}{'none'|i18n( $t )}{/if}</strong>
<span>{if $c.active}{if eq( $c.active.kind, 'downloaded' )}{'Installed from this page'|i18n( $t )}{else}{'Installed on the system'|i18n( $t )}{/if}{else}{'LibreOffice'|i18n( $t )}{/if}</span></li>
<li class="a4-figure{if $c.server.running} is-ok{elseif $c.server.enabled} is-bad{/if}"><strong data-ezodf-field="server_state">{if $c.server.running}{'running'|i18n( $t )}{elseif $c.server.enabled}{'not running'|i18n( $t )}{else}{'off'|i18n( $t )}{/if}</strong><span>{'Conversion server'|i18n( $t )}</span></li>
<li class="a4-figure{if $c.test.total}{if eq( $c.test.passed, $c.test.total )} is-ok{else} is-warn{/if}{/if}"><strong>{if $c.test.total}{$c.test.passed}/{$c.test.total}{else}–{/if}</strong><span>{'Last test'|i18n( $t )}{if $c.test.time} · {$c.test.time|l10n( 'shortdatetime' )}{/if}</span></li>
<li class="a4-figure{if $c.sandbox.isolated} is-ok{else} is-warn{/if}"><strong>{if $c.sandbox.isolated}{'isolated'|i18n( $t )}{else}{'limits only'|i18n( $t )}{/if}</strong><span>{'Sandbox'|i18n( $t )}</span></li>
<li class="a4-figure"><strong>{$c.filter_count}</strong><span>{'Filters'|i18n( $t )}</span></li>
</ul>

<div class="a4-grid is-wide">

{* ---- the installation ---- *}
<section class="a4-card a4-ezodf-span" aria-labelledby="ezodf-inst">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-inst"><span class="a4-icon a4-bi-box-seam" aria-hidden="true"></span> {'LibreOffice'|i18n( $t )}</h2></div>
<div class="a4-card-body">
{if $c.installations|count}
<div class="a4-table-wrap" role="region" aria-labelledby="ezodf-inst" tabindex="0">
<table class="a4-table is-compact">
<thead><tr><th>{'Version'|i18n( $t )}</th><th>{'Where'|i18n( $t )}</th><th class="a4-ezodf-hide-narrow">{'Path'|i18n( $t )}</th><th>{'Server mode'|i18n( $t )}</th><th><span class="a4-sr-only">{'Actions'|i18n( $t )}</span></th></tr></thead>
<tbody>
{foreach $c.installations as $i}
<tr>
<td>{$i.version|wash}{if $i.active} <span class="a4-badge is-ok">{'active'|i18n( $t )}</span>{/if}</td>
<td>{if eq( $i.kind, 'downloaded' )}{'Installed from this page'|i18n( $t )}{if $i.installed}<br /><span class="a4-meta">{$i.installed|l10n( 'shortdate' )}{if $i.size} · {$i.size|si( 'byte', 'mebi' )}{/if}</span>{/if}{elseif eq( $i.kind, 'custom' )}{'Chosen path'|i18n( $t )}{else}{'System packages'|i18n( $t )}{/if}</td>
<td class="a4-ezodf-hide-narrow"><code class="a4-ezodf-path">{$i.root|wash}</code>{if $i.verified}<br /><span class="a4-meta">{$i.verified|wash}</span>{/if}</td>
<td>{if $i.python}<span class="a4-ok-text">{'yes'|i18n( $t )}</span>{else}<span class="a4-warn-text">{'no (no Python with uno)'|i18n( $t )}</span>{/if}</td>
<td class="a4-end">
{if and( $i.active|not, $c.writable )}
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="use" /><input type="hidden" name="Installation" value="{$i.id|wash}" />
<button type="submit" class="a4-btn a4-btn-small">{if eq( $i.kind, 'downloaded' )}{'Use this build'|i18n( $t )}{else}{'Use the installed one'|i18n( $t )}{/if}</button></form>
{/if}
{if and( eq( $i.kind, 'downloaded' ), $c.writable )}
<form method="post" action={$here|ezurl} class="a4-ezodf-inline" data-a4-confirm="{'Remove LibreOffice %version from this installation? The system packages are not touched.'|i18n( $t,, hash( '%version', $i.release ) )|wash}">
<input type="hidden" name="ConverterAction" value="remove" /><input type="hidden" name="Version" value="{$i.release|wash}" />
<button type="submit" class="a4-btn a4-btn-small a4-btn-outline-danger">{'Remove'|i18n( $t )}</button></form>
{/if}
</td>
</tr>
{/foreach}
</tbody></table>
</div>
{else}
<p class="a4-empty">{'No LibreOffice was found on this server. Install it with one click below: the official build is downloaded into this installation\'s var directory, checked against its published checksum and signature, and needs no administrator rights.'|i18n( $t )}</p>
{/if}

<div class="a4-ezodf-install" data-ezodf-install>
<h3 class="a4-h3">{if $c.installations|count}{'Install the newest version'|i18n( $t )}{else}{'One-click install'|i18n( $t )}{/if}</h3>
<p class="a4-help">{'The Document Foundation\'s build for Linux x86-64 (about 250 MB to download, 700 MB on disk), checked with SHA-256 and the LibreOffice build key before it is used. The installation runs in the background; you can leave this page.'|i18n( $t )}
{if $c.latest.version} {'Newest version: %version (checked %time).'|i18n( $t,, hash( '%version', $c.latest.version, '%time', $c.latest.checked|l10n( 'shortdatetime' ) ) )}{/if}
{if $c.update_available} <strong>{'An update is available.'|i18n( $t )}</strong>{/if}</p>
{if $c.requirements.ok|not}<p class="a4-notice is-warn">{foreach $c.requirements.problems as $p}{$p|wash} {/foreach}</p>{/if}
{if and( $c.install, eq( $c.install.state, 'running' ) )}
<div class="a4-ezodf-progress" role="status" aria-live="polite">
<p><strong data-ezodf-field="install_message">{$c.install.message|wash}</strong></p>
<div class="a4-meter a4-ezodf-meter" data-ezodf-meter role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" aria-label="{'Download'|i18n( $t )|wash}"><span class="a4-pct-0"></span></div>
<p class="a4-meta" data-ezodf-field="install_bytes"></p>
</div>
{elseif and( $c.install, eq( $c.install.state, 'failed' ) )}
<p class="a4-notice is-bad">{'The last installation failed: %message'|i18n( $t,, hash( '%message', $c.install.message ) )|wash}</p>
{elseif and( $c.install, eq( $c.install.state, 'done' ) )}
<p class="a4-notice is-ok">{$c.install.message|wash}</p>
{/if}
{if and( $c.install_job, eq( $c.install_job.state, 'queued' ) )}<p class="a4-meta" data-ezodf-field="install_job">{'Waiting for the job queue…'|i18n( $t )}</p>{/if}
{if $c.writable}
<div class="a4-toolbar">
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="{if $c.installations|count}update{else}install{/if}" />
<input type="hidden" name="Version" value="{$c.latest.version|wash}" />
<button type="submit" class="a4-btn a4-btn-primary" data-ezodf-install-button{if or( $c.requirements.ok|not, $c.busy )} disabled="disabled"{/if}><span class="a4-icon a4-bi-cloud-arrow-down" aria-hidden="true"></span> {if $c.update_available}{'Update to %version'|i18n( $t,, hash( '%version', $c.latest.version ) )}{elseif $c.installations|count}{'Install the newest version'|i18n( $t )}{else}{'Install LibreOffice'|i18n( $t )}{/if}</button></form>
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="check" />
<button type="submit" class="a4-btn">{'Check for a new version'|i18n( $t )}</button></form>
</div>
{/if}
{if and( $c.install, is_set( $c.install.log ) )}
<details class="a4-fold"><summary>{'Log of the last installation'|i18n( $t )}</summary>
<ol class="a4-plain a4-ezodf-log" data-ezodf-list="install_log">{foreach $c.install.log as $line}<li><code>{$line|wash}</code></li>{/foreach}</ol></details>
{/if}
</div>
</div>
</section>

{* ---- the conversion server ---- *}
<section class="a4-card" aria-labelledby="ezodf-server">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-server"><span class="a4-icon a4-bi-cpu" aria-hidden="true"></span> {'Conversion server'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<p class="a4-help">{'LibreOffice stays started and converts one document after another, which takes a fraction of a second instead of several seconds per document. It is restarted after a crash, a conversion that hangs, and every %n conversions. While it is off, LibreOffice is started for each document.'|i18n( $t,, hash( '%n', $c.settings['converter.restart_after'] ) )}</p>
<dl class="a4-facts">
<dt>{'State'|i18n( $t )}</dt><dd data-ezodf-field="server_detail">{if $c.server.running}<span class="a4-badge is-ok">{'running'|i18n( $t )}</span> {'%n process(es), since %time'|i18n( $t,, hash( '%n', $c.server.running_slots, '%time', $c.server.since|l10n( 'shortdatetime' ) ) )}{elseif $c.server.enabled}<span class="a4-badge is-bad">{'turned on, not running'|i18n( $t )}</span>{else}<span class="a4-badge">{'off'|i18n( $t )}</span>{/if}</dd>
{if $c.server.running}<dt>{'Conversions'|i18n( $t )}</dt><dd>{$c.server.conversions} ({'%n failed'|i18n( $t,, hash( '%n', $c.server.failures ) )})</dd><dt>{'LibreOffice'|i18n( $t )}</dt><dd>{$c.server.version|wash}</dd>{/if}
{if $c.server.last_error}<dt>{'Last error'|i18n( $t )}</dt><dd class="a4-bad-text">{$c.server.last_error|wash}</dd>{/if}
{if $c.server_last}<dt>{'Last action'|i18n( $t )}</dt><dd>{$c.server_last.action|wash}: {$c.server_last.message|wash} <span class="a4-meta">{$c.server_last.time|l10n( 'shortdatetime' )}</span></dd>{/if}
{if and( $c.server_job, eq( $c.server_job.state, 'failed' ) )}<dt>{'Job'|i18n( $t )}</dt><dd class="a4-bad-text">{$c.server_job.message|wash}</dd>{/if}
</dl>
{if $c.server_possible|not}<p class="a4-notice is-warn">{'The active LibreOffice has no Python with the uno module, so it cannot run as a server; conversions start it per document. Install the build from this page, or the system package libreoffice-pyuno.'|i18n( $t )}</p>{/if}
{if $c.writable}
<div class="a4-toolbar">
{if $c.server.enabled}
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="off" /><button type="submit" class="a4-btn"><span class="a4-icon a4-bi-power" aria-hidden="true"></span> {'Turn off'|i18n( $t )}</button></form>
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="restart" /><button type="submit" class="a4-btn"><span class="a4-icon a4-bi-arrow-clockwise" aria-hidden="true"></span> {'Restart'|i18n( $t )}</button></form>
{else}
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="on" /><button type="submit" class="a4-btn a4-btn-primary"{if or( $c.active|not, $c.server_possible|not )} disabled="disabled"{/if}><span class="a4-icon a4-bi-power" aria-hidden="true"></span> {'Turn on'|i18n( $t )}</button></form>
{/if}
</div>
{/if}
</div>
</section>

{* ---- test conversions ---- *}
<section class="a4-card" aria-labelledby="ezodf-test">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-test"><span class="a4-icon a4-bi-check2-square" aria-hidden="true"></span> {'Test conversion'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<p class="a4-help">{'Converts the sample documents that come with ezodf (Word, RTF, Excel, PowerPoint, OpenDocument and an OpenOffice.org 1.x file) with the converter as it is set up now.'|i18n( $t )}</p>
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="test" />
<button type="submit" class="a4-btn a4-btn-primary"{if $c.busy} disabled="disabled"{/if}><span class="a4-icon a4-bi-play-fill" aria-hidden="true"></span> {'Run the test conversions'|i18n( $t )}</button></form>
{if and( $c.test_job, or( eq( $c.test_job.state, 'queued' ), eq( $c.test_job.state, 'running' ) ) )}<p class="a4-meta" role="status" data-ezodf-field="test_job">{if eq( $c.test_job.state, 'queued' )}{'Waiting for the job queue…'|i18n( $t )}{else}{$c.test_job.progress|wash}{/if}</p>{/if}
{if $c.test.results|count}
<div class="a4-table-wrap" role="region" aria-labelledby="ezodf-test" tabindex="0">
<table class="a4-table is-compact a4-ezodf-results">
<thead><tr><th>{'Sample'|i18n( $t )}</th><th>{'To'|i18n( $t )}</th><th>{'Result'|i18n( $t )}</th><th class="a4-num">{'Seconds'|i18n( $t )}</th><th class="a4-ezodf-hide-narrow">{'Converter'|i18n( $t )}</th></tr></thead>
<tbody>
{foreach $c.test.results as $r}
<tr><td>{$r.sample|wash}</td><td>{$r.target|wash}</td>
<td>{if $r.ok}<a href={concat( '/ezodf/converterfile/', $r.file )|ezurl}><span class="a4-badge is-ok">{'OK'|i18n( $t )}</span> {$r.size|si( 'byte', 'auto' )}</a>{else}<span class="a4-badge is-bad">{'failed'|i18n( $t )}</span> <span class="a4-ezodf-error">{$r.error|wash}</span>{/if}</td>
<td class="a4-num">{if $r.ok}{$r.seconds|l10n( 'number' )}{/if}</td>
<td class="a4-ezodf-hide-narrow">{if $r.ok}{$r.backend|wash}{if $r.filter} · <code>{$r.filter|wash}</code>{/if}{/if}</td></tr>
{/foreach}
</tbody></table>
</div>
{/if}
</div>
</section>

{* ---- how documents are converted ---- *}
<section class="a4-card" aria-labelledby="ezodf-mode">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-mode"><span class="a4-icon a4-bi-signpost-split" aria-hidden="true"></span> {'How documents are converted'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<form method="post" action={$here|ezurl} class="a4-form"><input type="hidden" name="ConverterAction" value="mode" />
<fieldset class="a4-field"><legend>{'Converter'|i18n( $t )}</legend>
<div class="a4-radios">
{foreach hash( 'auto', 'Automatic: the remote server when one is set, else the conversion server when it runs, else LibreOffice per document, else PHP'|i18n( $t ),
               'server', 'The conversion server, else LibreOffice per document'|i18n( $t ),
               'cli', 'LibreOffice per document'|i18n( $t ),
               'remote', 'Only the remote server'|i18n( $t ),
               'php', 'Only PHP (OpenDocument, OpenOffice.org 1.x text and the PHP readers)'|i18n( $t ) ) as $value => $label}
<label class="a4-check"><input type="radio" name="Mode" value="{$value}"{if eq( $c.settings['converter.mode'], $value )} checked="checked"{/if}{if $c.writable|not} disabled="disabled"{/if} /> {$label|wash}</label>
{/foreach}
</div>
</fieldset>
<table class="a4-table is-compact"><tbody>
{foreach $c.backends as $name => $b}<tr><th scope="row">{$name|wash}</th><td>{if $b.available}<span class="a4-ok-text">{'available'|i18n( $t )}</span>{else}<span class="a4-muted">{'not available'|i18n( $t )}</span>{/if}</td><td>{$b.note|wash}</td></tr>{/foreach}
</tbody></table>
{if $c.writable}<div class="a4-toolbar"><button type="submit" class="a4-btn">{'Save'|i18n( $t )}</button></div>{/if}
</form>
</div>
</section>

{* ---- limits ---- *}
<section class="a4-card" aria-labelledby="ezodf-limits">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-limits"><span class="a4-icon a4-bi-sliders" aria-hidden="true"></span> {'Limits'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<form method="post" action={$here|ezurl} class="a4-form a4-ezodf-fields"><input type="hidden" name="ConverterAction" value="limits" />
{foreach array(
    hash( 'name', 'Timeout', 'key', 'converter.timeout', 'label', 'Time per document (seconds)'|i18n( $t ), 'min', 10, 'max', 900 ),
    hash( 'name', 'MaxMegabytes', 'key', 'converter.max_megabytes', 'label', 'Largest document (MB)'|i18n( $t ), 'min', 1, 'max', 1024 ),
    hash( 'name', 'MaxParallel', 'key', 'converter.max_parallel', 'label', 'Conversions at the same time'|i18n( $t ), 'min', 1, 'max', 8 ),
    hash( 'name', 'RestartAfter', 'key', 'converter.restart_after', 'label', 'Restart the server after (conversions)'|i18n( $t ), 'min', 1, 'max', 100000 ),
    hash( 'name', 'MemoryMegabytes', 'key', 'converter.memory_megabytes', 'label', 'Memory per LibreOffice (MB)'|i18n( $t ), 'min', 512, 'max', 32768 ),
    hash( 'name', 'KeepDays', 'key', 'retention.keep_days', 'label', 'Keep uploads and runs (days)'|i18n( $t ), 'min', 1, 'max', 3650 ) ) as $f}
<div class="a4-field"><label for="ezodf-{$f.name}">{$f.label|wash}</label>
<input class="a4-input" type="number" id="ezodf-{$f.name}" name="{$f.name}" min="{$f.min}" max="{$f.max}" value="{$c.settings[$f.key]|wash}"{if $c.writable|not} disabled="disabled"{/if} /></div>
{/foreach}
{if $c.writable}<div class="a4-toolbar"><button type="submit" class="a4-btn">{'Save the limits'|i18n( $t )}</button></div>{/if}
</form>
</div>
</section>

{* ---- remote server ---- *}
<section class="a4-card" aria-labelledby="ezodf-remote">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-remote"><span class="a4-icon a4-bi-plug" aria-hidden="true"></span> {'Use a remote server'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<p class="a4-help">{'An existing conversion service instead of LibreOffice on this server: unoserver (XML-RPC, every format) or Gotenberg (PDF only; other conversions fall back to this server).'|i18n( $t )}</p>
<form method="post" action={$here|ezurl} class="a4-form" autocomplete="off"><input type="hidden" name="ConverterAction" value="remote" />
<div class="a4-field"><label for="ezodf-remote-url">{'Address'|i18n( $t )}</label><input class="a4-input" type="url" id="ezodf-remote-url" name="RemoteURL" placeholder="http://127.0.0.1:2003" value="{$c.settings['converter.remote_url']|wash}"{if $c.writable|not} disabled="disabled"{/if} /></div>
<div class="a4-field"><label for="ezodf-remote-kind">{'Kind'|i18n( $t )}</label>
<select class="a4-input" id="ezodf-remote-kind" name="RemoteKind"{if $c.writable|not} disabled="disabled"{/if}>
<option value="unoserver"{if eq( $c.settings['converter.remote_kind'], 'unoserver' )} selected="selected"{/if}>unoserver</option>
<option value="gotenberg"{if eq( $c.settings['converter.remote_kind'], 'gotenberg' )} selected="selected"{/if}>Gotenberg</option></select></div>
<div class="a4-field"><label for="ezodf-remote-user">{'User (optional)'|i18n( $t )}</label><input class="a4-input" type="text" id="ezodf-remote-user" name="RemoteUsername" value="{$c.settings['converter.remote_username']|wash}"{if $c.writable|not} disabled="disabled"{/if} /></div>
<div class="a4-field"><label for="ezodf-remote-pass">{'Password (optional)'|i18n( $t )}</label><input class="a4-input" type="password" id="ezodf-remote-pass" name="RemotePassword" autocomplete="new-password" placeholder="{if $c.settings['converter.remote_password']}{'set, leave empty to keep it'|i18n( $t )|wash}{/if}"{if $c.writable|not} disabled="disabled"{/if} /></div>
{if $c.writable}
<div class="a4-toolbar">
<button type="submit" class="a4-btn">{'Save and test'|i18n( $t )}</button>
{if $c.settings['converter.remote_url']}<button type="submit" class="a4-btn a4-btn-outline-danger" name="RemoteForget" value="1">{'Stop using it'|i18n( $t )}</button>{/if}
</div>
{/if}
</form>
</div>
</section>

{* ---- formats ---- *}
<section class="a4-card a4-ezodf-span" aria-labelledby="ezodf-formats">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-formats"><span class="a4-icon a4-bi-files" aria-hidden="true"></span> {'Formats'|i18n( $t )}</h2></div>
<div class="a4-card-body">
{if $c.families|count}
<p class="a4-help">{'What the active LibreOffice reads and writes, from its own filter list (%n filters). Formats of OpenOffice.org 1.x and StarOffice are marked.'|i18n( $t,, hash( '%n', $c.filter_count ) )}</p>
<div class="a4-table-wrap" role="region" aria-labelledby="ezodf-formats" tabindex="0">
<table class="a4-table is-compact a4-ezodf-formats">
<thead><tr><th>{'Kind'|i18n( $t )}</th><th>{'Reads'|i18n( $t )}</th><th>{'Writes'|i18n( $t )}</th></tr></thead>
<tbody>
{foreach $c.families as $f}
<tr><th scope="row">{if eq( $f.family, 'text' )}{'Text'|i18n( $t )}{elseif eq( $f.family, 'spreadsheet' )}{'Spreadsheets'|i18n( $t )}{elseif eq( $f.family, 'presentation' )}{'Presentations'|i18n( $t )}{elseif eq( $f.family, 'drawing' )}{'Drawings'|i18n( $t )}{elseif eq( $f.family, 'formula' )}{'Formulas'|i18n( $t )}{else}{'Other'|i18n( $t )}{/if}</th>
<td class="a4-ezodf-exts">{$f.import|implode( ', ' )|wash}{if $f.legacy|count}<br /><span class="a4-meta">{'OpenOffice.org 1.x and StarOffice:'|i18n( $t )} {$f.legacy|implode( ', ' )|wash}</span>{/if}</td>
<td class="a4-ezodf-exts">{$f.export|implode( ', ' )|wash}</td></tr>
{/foreach}
</tbody></table>
</div>
{else}
<p class="a4-empty">{'Without LibreOffice ezodf reads %list into content.'|i18n( $t,, hash( '%list', $c.backends.php.note ) )}</p>
{/if}
</div>
</section>

{* ---- AI assist (expai, opt-in) ---- *}
<section class="a4-card" aria-labelledby="ezodf-ai">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-ai"><span class="a4-icon a4-bi-stars" aria-hidden="true"></span> {'AI assist'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<p class="a4-help">{'When an import asks for it, the AI suggests a summary for the intro and keywords for each imported document. The suggestions are shown on the page of the import and applied only when someone clicks Apply. Only the document\'s own text is sent, under the rules and the data policy of Exponential Intelligence.'|i18n( $t )}</p>
{if $c.ai_installed|not}
<p class="a4-meta">{'Exponential Intelligence (expai) is not installed.'|i18n( $t )}</p>
{else}
<form method="post" action={$here|ezurl} class="a4-form"><input type="hidden" name="ConverterAction" value="ai" />
<label class="a4-check"><input type="checkbox" name="AIEnabled" value="1"{if $c.settings['ai.enabled']} checked="checked"{/if}{if $c.writable|not} disabled="disabled"{/if} /> {'Offer AI suggestions on the import page'|i18n( $t )}</label>
{if and( $c.settings['ai.enabled'], $c.ai_available|not )}<p class="a4-meta">{'Turned on, but Exponential Intelligence is switched off for this site.'|i18n( $t )}</p>{/if}
{if $c.writable}<div class="a4-toolbar"><button type="submit" class="a4-btn">{'Save'|i18n( $t )}</button></div>{/if}
</form>
{/if}
</div>
</section>

{* ---- log ---- *}
<section class="a4-card" aria-labelledby="ezodf-log">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-log"><span class="a4-icon a4-bi-journal-text" aria-hidden="true"></span> {'Server log'|i18n( $t )}</h2></div>
<div class="a4-card-body">
{if $c.log|count}<ol class="a4-plain a4-ezodf-log">{foreach $c.log as $line}<li><code>{$line|wash}</code></li>{/foreach}</ol>{else}<p class="a4-empty">{'The conversion server has not written a log yet.'|i18n( $t )}</p>{/if}
</div>
</section>

{* ---- disk use and cleanup ---- *}
<section class="a4-card" aria-labelledby="ezodf-disk">
<div class="a4-card-head"><h2 class="a4-h2" id="ezodf-disk"><span class="a4-icon a4-bi-archive" aria-hidden="true"></span> {'Disk use'|i18n( $t )}</h2></div>
<div class="a4-card-body">
<table class="a4-table is-compact"><tbody>
<tr><th scope="row">{'LibreOffice builds'|i18n( $t )}</th><td class="a4-num">{$c.usage.libreoffice|si( 'byte', 'auto' )}</td></tr>
<tr><th scope="row">{'Uploaded documents'|i18n( $t )}</th><td class="a4-num">{$c.usage.batches|si( 'byte', 'auto' )}</td></tr>
<tr><th scope="row">{'Work files'|i18n( $t )}</th><td class="a4-num">{sum( $c.usage.work, $c.usage.spool )|si( 'byte', 'auto' )}</td></tr>
<tr><th scope="row">{'Test results and server profile'|i18n( $t )}</th><td class="a4-num">{sum( $c.usage.test, $c.usage.server )|si( 'byte', 'auto' )}</td></tr>
</tbody></table>
<p class="a4-help">{'Work files older than an hour and uploads older than %days days are removed by the cron job; %n item(s) could go now.'|i18n( $t,, hash( '%days', $c.settings['retention.keep_days'], '%n', $c.usage.pending.items ) )}</p>
<form method="post" action={$here|ezurl} class="a4-ezodf-inline"><input type="hidden" name="ConverterAction" value="cleanup" /><button type="submit" class="a4-btn">{'Clean up now'|i18n( $t )}</button></form>
</div>
</section>

</div>
</div></div></div>
</div>
{undef $here $t}
