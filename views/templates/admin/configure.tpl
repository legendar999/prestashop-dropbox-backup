{*
 * akvabackup — BO configuration page
 * Legacy Bootstrap panel markup consistent with the PS9 BO theme. No external assets, no emojis.
 * English source strings; translations ship in translations/<iso>.php (Slovenian included).
 *
 * @author  Akva Modules
 * @license AFL-3.0
 *}
<div class="akvabackup-config">

	{* ============================================================ 1. STATUS *}
	<div class="panel">
		<div class="panel-heading">
			<i class="icon-dashboard"></i> {l s='Status' mod='akvabackup'}
		</div>
		<div class="panel-body">

		<div class="row akvabackup-status-row">
			<div class="col-lg-6">
				<p>
					<strong>{l s='Dropbox connection:' mod='akvabackup'}</strong>
					{if $connected}
						<span class="label label-success"><i class="icon-check"></i> {l s='Connected' mod='akvabackup'}</span>
					{else}
						<span class="label label-danger"><i class="icon-remove"></i> {l s='Not connected' mod='akvabackup'}</span>
					{/if}
				</p>
				<p>
					<strong>{l s='Module:' mod='akvabackup'}</strong>
					{if $enabled}
						<span class="label label-success">{l s='Enabled' mod='akvabackup'}</span>
					{else}
						<span class="label label-default">{l s='Disabled' mod='akvabackup'}</span>
					{/if}
				</p>
				<p>
					<strong>{l s='Last successful run:' mod='akvabackup'}</strong>
					{if $last_ok}{$last_ok|escape:'html':'UTF-8'}{else}<span class="text-muted">{l s='Never yet' mod='akvabackup'}</span>{/if}
				</p>
			</div>

			<div class="col-lg-6">
				<h4 class="akvabackup-subtitle">{l s='Dropbox space usage' mod='akvabackup'}</h4>
				{if $space.ok && $space.allocated > 0}
					<div class="progress akvabackup-space">
						<div class="progress-bar progress-bar-info" role="progressbar" style="width:{$space.pct|intval}%">
							{$space.pct|intval}%
						</div>
					</div>
					<p class="text-muted">
						{$space.used_h|escape:'html':'UTF-8'} / {$space.allocated_h|escape:'html':'UTF-8'}
					</p>
				{elseif $space.ok}
					<p class="text-muted">{l s='Used:' mod='akvabackup'} {$space.used_h|escape:'html':'UTF-8'}</p>
				{else}
					<p class="text-muted">{l s='Space information is currently unavailable.' mod='akvabackup'}</p>
				{/if}
			</div>
		</div>

		<hr>

		<h4 class="akvabackup-subtitle">{l s='Last 15 runs' mod='akvabackup'}</h4>
		{if $runs|@count}
			<div class="table-responsive akvabackup-table-wrap">
			<table class="table akvabackup-runs">
				<thead>
					<tr>
						<th>#</th>
						<th>{l s='Type' mod='akvabackup'}</th>
						<th>{l s='State' mod='akvabackup'}</th>
						<th>{l s='Size' mod='akvabackup'}</th>
						<th>{l s='Duration' mod='akvabackup'}</th>
						<th>{l s='Started' mod='akvabackup'}</th>
						<th>{l s='Error' mod='akvabackup'}</th>
					</tr>
				</thead>
				<tbody>
					{foreach from=$runs item=run}
						<tr>
							<td>{$run.id|intval}</td>
							<td>{$run.type|escape:'html':'UTF-8'}</td>
							<td>
								{if $run.state == 'done'}
									<span class="label label-success">{$run.state|escape:'html':'UTF-8'}</span>
								{elseif $run.state == 'error'}
									<span class="label label-danger">{$run.state|escape:'html':'UTF-8'}</span>
								{else}
									<span class="label label-info">{$run.state|escape:'html':'UTF-8'}</span>
								{/if}
							</td>
							<td>{$run.size_h|escape:'html':'UTF-8'}</td>
							<td>{$run.duration_h|escape:'html':'UTF-8'}</td>
							<td>{$run.date|escape:'html':'UTF-8'}</td>
							<td class="akvabackup-err">{if $run.error}<span class="text-danger" title="{$run.error|escape:'html':'UTF-8'}">{$run.error|escape:'html':'UTF-8'}</span>{else}-{/if}</td>
						</tr>
					{/foreach}
				</tbody>
			</table>
			</div>
		{else}
			<p class="text-muted">{l s='No runs yet.' mod='akvabackup'}</p>
		{/if}

		<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" class="akvabackup-runnow">
			<button type="submit" name="submitAkvabackupRunNow" class="btn btn-primary">
				<i class="icon-play"></i> {l s='Run now' mod='akvabackup'}
			</button>
			<span class="help-block akvabackup-inline-help">
				{l s='Creates a manual run; scheduled cron ticks will carry it step by step to completion.' mod='akvabackup'}
			</span>
		</form>

		<hr>

		<div class="form-group">
			<label>{l s='Cron URL (for the scheduler)' mod='akvabackup'}</label>
			<input type="text" class="form-control akvabackup-cron-url" readonly onclick="this.select();" value="{$cron_url|escape:'html':'UTF-8'}">
			<p class="help-block">{l s='Call this URL every 5 minutes during the nightly window. It contains a secret token — do not share it.' mod='akvabackup'}</p>
		</div>

		</div>
	</div>

	{* ============================================================ 2. DROPBOX CONNECTION *}
	<div class="panel">
		<div class="panel-heading">
			<i class="icon-cloud"></i> {l s='Dropbox connection' mod='akvabackup'}
		</div>
		<div class="panel-body">

		<ol class="akvabackup-steps">
			<li>{l s='Create an app at dropbox.com/developers (Scoped access, App folder).' mod='akvabackup'}</li>
			<li>{l s='In the Permissions tab enable: files.content.write, files.content.read, files.metadata.read, account_info.read.' mod='akvabackup'}</li>
			<li>{l s='Enter the App key and App secret below and save.' mod='akvabackup'}</li>
			<li>{l s='Click "Open Dropbox authorization", approve access and paste the returned code.' mod='akvabackup'}</li>
		</ol>

		<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" class="form-horizontal">
			<div class="form-group">
				<label class="control-label col-lg-3">{l s='App key' mod='akvabackup'}</label>
				<div class="col-lg-6">
					<input type="text" name="AKVABACKUP_APP_KEY" class="form-control" value="{$app_key|escape:'html':'UTF-8'}" autocomplete="off">
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-lg-3">{l s='App secret' mod='akvabackup'}</label>
				<div class="col-lg-6">
					<input type="password" name="AKVABACKUP_APP_SECRET" class="form-control" value="" autocomplete="off" placeholder="{if $app_secret_set}{l s='(saved — leave blank to keep)' mod='akvabackup'}{/if}">
				</div>
			</div>
			<div class="form-group">
				<div class="col-lg-9 col-lg-offset-3">
					<button type="submit" name="submitAkvabackupApp" class="btn btn-default">
						<i class="icon-save"></i> {l s='Save app' mod='akvabackup'}
					</button>
				</div>
			</div>
		</form>

		{if $authorize_url}
			<hr>
			<p>
				<a href="{$authorize_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener" class="btn btn-default">
					<i class="icon-external-link"></i> {l s='Open Dropbox authorization' mod='akvabackup'}
				</a>
			</p>
			{if !$connected}
				<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" class="form-horizontal">
					<div class="form-group">
						<label class="control-label col-lg-3">{l s='Authorization code' mod='akvabackup'}</label>
						<div class="col-lg-6">
							<input type="text" name="AKVABACKUP_AUTH_CODE" class="form-control" value="" autocomplete="off">
						</div>
					</div>
					<div class="form-group">
						<div class="col-lg-9 col-lg-offset-3">
							<button type="submit" name="submitAkvabackupConnect" class="btn btn-primary">
								<i class="icon-link"></i> {l s='Connect' mod='akvabackup'}
							</button>
						</div>
					</div>
				</form>
			{/if}
		{/if}

		{if $connected}
			<hr>
			<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" onsubmit="return confirm('{l s='Really disconnect from Dropbox?' mod='akvabackup' js=1}');">
				<button type="submit" name="submitAkvabackupDisconnect" class="btn btn-default">
					<i class="icon-unlink"></i> {l s='Disconnect' mod='akvabackup'}
				</button>
			</form>
		{/if}

		</div>
	</div>

	{* ============================================================ 3. SETTINGS *}
	<div class="panel">
		<div class="panel-heading">
			<i class="icon-cogs"></i> {l s='Settings' mod='akvabackup'}
		</div>
		<div class="panel-body">

		<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" class="form-horizontal">
			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Module enabled' mod='akvabackup'}</label>
				<div class="col-lg-9">
					<span class="switch prestashop-switch fixed-width-lg">
						<input type="radio" name="AKVABACKUP_ENABLED" id="akvabackup_enabled_on" value="1" {if $enabled}checked="checked"{/if}>
						<label for="akvabackup_enabled_on">{l s='Yes' mod='akvabackup'}</label>
						<input type="radio" name="AKVABACKUP_ENABLED" id="akvabackup_enabled_off" value="0" {if !$enabled}checked="checked"{/if}>
						<label for="akvabackup_enabled_off">{l s='No' mod='akvabackup'}</label>
						<a class="slide-button btn"></a>
					</span>
					<p class="help-block">{l s='Master switch. A manual run executes even when disabled.' mod='akvabackup'}</p>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Start hour (0-23)' mod='akvabackup'}</label>
				<div class="col-lg-2">
					<select name="AKVABACKUP_HOUR" class="form-control">
						{section name=h start=0 loop=24}
							<option value="{$smarty.section.h.index}" {if $smarty.section.h.index == $hour}selected="selected"{/if}>{$smarty.section.h.index}</option>
						{/section}
					</select>
					<p class="help-block">{l s='The daily run may start after this local hour.' mod='akvabackup'}</p>
				</div>
			</div>

			<hr>

			<h4 class="akvabackup-subtitle">{l s='What gets backed up' mod='akvabackup'}</h4>
			<div class="alert alert-info">
				{l s='The backup captures the ENTIRE PrestaShop installation, i.e. all multistore shops at once (shared database and shared files) — individual shops cannot be selected.' mod='akvabackup'}
			</div>
			<p class="help-block">
				{l s='Everything is enabled by default. Disabling any component means a restore from such a backup will NOT be complete — disable only if you know exactly why. Changes take effect from the next run; do not change them while a nightly run is in progress, as that would produce a mixed backup.' mod='akvabackup'}
			</p>

			{foreach from=$components item=comp}
				<div class="form-group">
					<label class="control-label col-lg-3">{$comp.label|escape:'html':'UTF-8'}</label>
					<div class="col-lg-9">
						<span class="switch prestashop-switch fixed-width-lg">
							<input type="radio" name="{$comp.key|escape:'html':'UTF-8'}" id="{$comp.key|lower}_on" value="1" {if $comp.on}checked="checked"{/if}>
							<label for="{$comp.key|lower}_on">{l s='Yes' mod='akvabackup'}</label>
							<input type="radio" name="{$comp.key|escape:'html':'UTF-8'}" id="{$comp.key|lower}_off" value="0" {if !$comp.on}checked="checked"{/if}>
							<label for="{$comp.key|lower}_off">{l s='No' mod='akvabackup'}</label>
							<a class="slide-button btn"></a>
						</span>
						<p class="help-block">{$comp.help|escape:'html':'UTF-8'}</p>
					</div>
				</div>
			{/foreach}

			<p class="help-block">
				{l s='Other files (PrestaShop core, configuration files, translations ...) are always included. Fine-grained exceptions can be added below under "Excluded files".' mod='akvabackup'}
			</p>

			<hr>

			<h4 class="akvabackup-subtitle">{l s='Backup retention on Dropbox (GFS rotation)' mod='akvabackup'}</h4>
			<p class="help-block">
				{l s='How many old backups stay stored on Dropbox. The three tiers add up: every daily backup of the last N days is kept, on top of that the first backup of each week for the last N weeks, and the first backup of each month for the last N months. Everything older is deleted automatically at the end of each successful run. The default 14 / 8 / 6 means: every day for the last two weeks, then one per week for about two months, then one per month for half a year. A value of 0 disables that tier.' mod='akvabackup'}
			</p>

			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Retention — daily backups' mod='akvabackup'}</label>
				<div class="col-lg-2">
					<input type="number" min="0" name="AKVABACKUP_RET_DAILY" class="form-control" value="{$ret_daily|intval}">
				</div>
				<div class="col-lg-7">
					<p class="help-block akvabackup-ret-help">{l s='The last N nightly backups, each kept individually. 14 = every day for the last two weeks.' mod='akvabackup'}</p>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Retention — weekly backups' mod='akvabackup'}</label>
				<div class="col-lg-2">
					<input type="number" min="0" name="AKVABACKUP_RET_WEEKLY" class="form-control" value="{$ret_weekly|intval}">
				</div>
				<div class="col-lg-7">
					<p class="help-block akvabackup-ret-help">{l s='The first backup of each week, for the last N weeks. 8 = one weekly point going back about two months.' mod='akvabackup'}</p>
				</div>
			</div>
			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Retention — monthly backups' mod='akvabackup'}</label>
				<div class="col-lg-2">
					<input type="number" min="0" name="AKVABACKUP_RET_MONTHLY" class="form-control" value="{$ret_monthly|intval}">
				</div>
				<div class="col-lg-7">
					<p class="help-block akvabackup-ret-help">{l s='The first backup of each month, for the last N months. 6 = one monthly point going back half a year.' mod='akvabackup'}</p>
				</div>
			</div>

			<hr>

			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Excluded files (glob, one per line)' mod='akvabackup'}</label>
				<div class="col-lg-6">
					<textarea name="AKVABACKUP_EXCLUDES" class="form-control" rows="6">{$excludes|escape:'html':'UTF-8'}</textarea>
					<p class="help-block">{l s='Relative to the PrestaShop root.' mod='akvabackup'}</p>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Tables without rows (name without prefix)' mod='akvabackup'}</label>
				<div class="col-lg-6">
					<textarea name="AKVABACKUP_DB_EXCLUDES" class="form-control" rows="4">{$db_excludes|escape:'html':'UTF-8'}</textarea>
					<p class="help-block">{l s='The structure of these tables is saved, their rows are not.' mod='akvabackup'}</p>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Alert email for failures' mod='akvabackup'}</label>
				<div class="col-lg-6">
					<input type="email" name="AKVABACKUP_ALERT_EMAIL" class="form-control" value="{$alert_email|escape:'html':'UTF-8'}">
					<p class="help-block">{l s='Empty = the default shop email.' mod='akvabackup'}</p>
				</div>
			</div>

			<div class="form-group">
				<label class="control-label col-lg-3">{l s='Work budget per tick (seconds)' mod='akvabackup'}</label>
				<div class="col-lg-2">
					<input type="number" min="10" max="90" name="AKVABACKUP_TICK_BUDGET" class="form-control" value="{$tick_budget|intval}">
					<p class="help-block">{l s='At most 90 seconds (stays under proxy/CDN timeouts).' mod='akvabackup'}</p>
				</div>
			</div>

			<div class="form-group">
				<div class="col-lg-9 col-lg-offset-3">
					<button type="submit" name="submitAkvabackupSettings" class="btn btn-primary">
						<i class="icon-save"></i> {l s='Save' mod='akvabackup'}
					</button>
				</div>
			</div>
		</form>

		</div>
	</div>

	{* ============================================================ 4. ENCRYPTION KEY *}
	<div class="panel">
		<div class="panel-heading">
			<i class="icon-key"></i> {l s='Encryption key' mod='akvabackup'}
		</div>
		<div class="panel-body">

		{if $revealed_hex}
			<div class="alert alert-danger">
				<i class="icon-warning"></i>
				{l s='This is your encryption key. It is shown ONLY ONCE. Losing the key makes your backups unusable. Store it OUTSIDE this server.' mod='akvabackup'}
			</div>
			<div class="form-group">
				<label>{l s='Key (hex)' mod='akvabackup'}</label>
				<textarea class="form-control akvabackup-key" rows="2" readonly onclick="this.select();">{$revealed_hex|escape:'html':'UTF-8'}</textarea>
			</div>
			<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}">
				<button type="submit" name="submitAkvabackupKeyDownload" class="btn btn-default">
					<i class="icon-download"></i> {l s='Download .key file' mod='akvabackup'}
				</button>
			</form>
		{elseif !$key_shown}
			<p>{l s='The encryption key has not been revealed yet. It will be shown only once, with a file download offered.' mod='akvabackup'}</p>
			<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" onsubmit="return confirm('{l s='The key will be shown only once. Reveal it now?' mod='akvabackup' js=1}');">
				<button type="submit" name="submitAkvabackupReveal" class="btn btn-warning">
					<i class="icon-eye"></i> {l s='Reveal key (one time)' mod='akvabackup'}
				</button>
			</form>
		{else}
			<div class="alert alert-info">
				<i class="icon-info-circle"></i>
				{l s='The key was already revealed. Only the file download remains available.' mod='akvabackup'}
			</div>
			<form method="post" action="{$akvabackup_form_url|escape:'html':'UTF-8'}" onsubmit="return confirm('{l s='Download the encryption key? Keep the file safe, outside this server.' mod='akvabackup' js=1}');">
				<button type="submit" name="submitAkvabackupKeyDownload" class="btn btn-default">
					<i class="icon-download"></i> {l s='Download .key file' mod='akvabackup'}
				</button>
			</form>
		{/if}

		</div>
	</div>

	{* ============================================================ 5. RESTORE *}
	<div class="panel">
		<div class="panel-heading">
			<i class="icon-refresh"></i> {l s='Restore' mod='akvabackup'}
		</div>
		<div class="panel-body">

		<p>{l s='How to restore from a Dropbox backup:' mod='akvabackup'}</p>
		<ol class="akvabackup-steps">
			<li>{l s='Download the entire run folder from Dropbox (named YYYY-MM-DD_<id>).' mod='akvabackup'}</li>
			<li>{l s='Decrypt every file with the bundled tool:' mod='akvabackup'}
				<pre class="akvabackup-cmd">php tools/decrypt.php &lt;hexkey&gt; db.sql.gz.enc db.sql.gz</pre>
			</li>
			<li>{l s='Extract the volumes over a fresh PrestaShop root.' mod='akvabackup'}</li>
			<li>{l s='Import db.sql.gz into the database (mysql / Adminer).' mod='akvabackup'}</li>
			<li>{l s='Clear the cache (var/cache) and review any theme customizations.' mod='akvabackup'}</li>
		</ol>
		<p class="help-block">{l s='The meta.json.enc file in the run folder (encrypted like everything else) lists the volumes with checksums, version and date.' mod='akvabackup'}</p>

		</div>
	</div>

</div>
