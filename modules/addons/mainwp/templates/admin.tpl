{* SPDX-License-Identifier: AGPL-3.0-or-later
   MainWP for WHMCS: the module's admin page. Three tabs drawn with WHMCS's own list and form markup (the classes, ids and data-page its admin themes and script know), so it looks and pages as WHMCS's own lists do. Only escaped variables, if and foreach. *}
<div class="mainwp-admin">
{if $denied}
<div class="alert alert-danger">Your admin role has no access to {$name|escape}. An administrator can grant it under System Settings &gt; Addon Modules &gt; Configure.</div>
{else}
{foreach $alerts as $alert}<div class="alert alert-{$alert.class|escape}">{$alert.text|escape}</div>
{/foreach}
{if $errors}<div class="alert alert-danger"><p>The settings were not saved:</p><ul>{foreach $errors as $error}<li>{$error|escape}</li>{/foreach}</ul></div>{/if}
{if $dryRun}<div class="alert alert-warning">Dry run: actions are only logged.{if $tab != "settings"} Switch to Live under <a href="{$tabLinks.settings|escape}">Settings</a>.{/if}</div>{/if}
<ul class="nav nav-tabs admin-tabs" role="tablist">
<li{if $tab == "sites"} class="active"{/if}><a href="{$tabLinks.sites|escape}">Sites</a></li>
<li{if $tab == "settings"} class="active"{/if}><a href="{$tabLinks.settings|escape}">Settings</a></li>
<li{if $tab == "log"} class="active"{/if}><a href="{$tabLinks.log|escape}">Log</a></li>
</ul>
<div class="tab-content admin-tabs">
<div class="tab-pane active">
{if $tab == "settings"}
<form method="post" action="{$tabLinks.settings|escape}" class="top-margin-10">
<input type="hidden" name="token" value="{$token|escape}">
<input type="hidden" name="action" value="save">
<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
<tr><td class="fieldlabel label-top" width="25%"><label for="mainwp-mode">Mode</label></td><td class="fieldarea"><select id="mainwp-mode" class="form-control input-400" name="mode"><option value="dry_run"{if $form.mode == "dry_run"} selected{/if}>Dry Run</option><option value="live"{if $form.mode == "live"} selected{/if}>Live</option></select><p class="help-block">Dry run logs each action without carrying it out.</p></td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-dashboard-url">MainWP Dashboard URL</label></td><td class="fieldarea"><input type="url" id="mainwp-dashboard-url" class="form-control input-400" name="dashboard_url" value="{$form.dashboard_url|escape}" placeholder="https://dashboard.example.com"></td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-api-key">API Key</label></td><td class="fieldarea">{if $key.open}<input type="text" name="api_key" value="" autocomplete="off" spellcheck="false" id="mainwp-api-key" class="form-control input-400{if $key.saved} input-inline{/if}">{if $key.saved} <a href="{$tabLinks.settings|escape}" class="btn btn-default">{$words.cancel|escape}</a>{/if}<p class="help-block">Made in MainWP under API Access, Add API Keys.</p>{else}<input type="text" value="********************************" id="mainwp-api-key" class="form-control input-400 input-inline" readonly disabled> <a href="{$key.changeLink|escape}" class="btn btn-default">Change</a><p class="help-block">Saved.</p>{/if}</td></tr>
<tr><td class="fieldlabel label-top"><label>Care Add-ons</label></td><td class="fieldarea">{if $addons}{foreach $addons as $addon}{if !$addon@first}, {/if}<a href="{$addon.link|escape}">{$addon.name|escape}</a>{/foreach}<p class="help-block">A site is cared for when its hosting service has one of these product add-ons.</p>{else}None yet. Choose MainWP for WHMCS as the module on a <a href="configaddons.php">product add-on's Module Settings</a>.{/if}</td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-care-email">Care Account Email</label></td><td class="fieldarea"><input type="text" id="mainwp-care-email" class="form-control input-400" name="care_email" value="{$form.care_email|escape}" placeholder="wordpress@example.com"><p class="help-block">The care account on every site gets this address.</p></td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-report">Daily Digest</label></td><td class="fieldarea"><select id="mainwp-report" class="form-control input-400" name="report_by_email"><option value="off"{if $form.report_by_email == "off"} selected{/if}>Off</option><option value="new"{if $form.report_by_email == "new"} selected{/if}>When something is new</option><option value="daily"{if $form.report_by_email == "daily"} selected{/if}>Every day</option></select><p class="help-block">{$reportTo|escape}</p></td></tr>
</table>
<div class="btn-container"><button type="submit" class="btn btn-primary">Save Changes</button> <button type="submit" class="btn btn-default">Test Connection</button></div>
</form>
{elseif $tab == "log"}
<ul class="nav nav-tabs admin-tabs admin-tabs--toggleable top-margin-10" role="tablist"><li{if $filter.open} class="active"{/if}><a class="tab-top" href="#mainwp-filter" role="tab" data-toggle="tab" aria-expanded="{if $filter.open}true{else}false{/if}">{$words.searchFilter|escape}</a></li></ul>
<div class="tab-content admin-tabs">
<div class="tab-pane{if $filter.open} active{/if}" id="mainwp-filter">
<form method="get" action="addonmodules.php">
{foreach $filter.hidden as $field}<input type="hidden" name="{$field.name|escape}" value="{$field.value|escape}">{/foreach}
<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
<tr><td width="15%" class="fieldlabel"><label for="mainwp-search">Search</label></td><td class="fieldarea"><input type="text" id="mainwp-search" name="search" value="{$filter.search|escape}" class="form-control input-300"></td><td width="15%" class="fieldlabel"><label for="mainwp-outcome">Outcome</label></td><td class="fieldarea"><select id="mainwp-outcome" name="outcome" class="form-control select-inline"><option value="">{$words.any|escape}</option>{foreach $filter.options as $option}<option value="{$option.value|escape}"{if $option.selected} selected{/if}>{$option.label|escape}</option>{/foreach}</select></td></tr>
</table>
<div class="btn-container"><button type="submit" class="btn btn-default">{$words.search|escape}</button>{if $filter.open} <a href="{$filter.resetLink|escape}" class="btn btn-default">Reset</a>{/if}</div>
</form>
</div>
</div>
{if $confirmClear}
<div class="alert alert-warning top-margin-10">
<form method="post" action="{$listLink|escape}">
<input type="hidden" name="token" value="{$token|escape}">
<p>{if $logTotal == 1}Delete the 1 log entry?{else}Delete all {$logTotal|escape} log entries?{/if} This cannot be undone.</p>
<button type="submit" name="action" value="clear_log_confirmed" class="btn btn-danger">Clear Log</button> <a href="{$listLink|escape}" class="btn btn-default">{$words.cancel|escape}</a>
</form>
</div>
{/if}
<form id="frmRecordsFound" method="get" action="addonmodules.php" class="top-margin-10">
{foreach $list.hidden as $field}<input type="hidden" name="{$field.name|escape}" value="{$field.value|escape}">{/foreach}
<input type="hidden" name="page" value="{$list.page|escape}">
<div class="row">
<div class="col-md-6 col-sm-12">{$list.summary|escape}</div>
<div class="col-md-6 col-sm-12 text-right">{if $list.pages > 1}{$list.jumpLabel|escape}: <div class="btn-group paging-dropdown" role="group"><button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span id="currentPage">{$list.page|escape}</span> <span class="caret"></span></button><ul class="dropdown-menu dropdown-menu-right">{foreach $list.jump as $jump}<li{if $jump.current} class="active"{/if}><a href="{$jump.link|escape}" data-page="{$jump.page|escape}">{$jump.page|escape}</a></li>{/foreach}</ul></div>{/if}</div>
</div>
</form>
<div class="tablebg">
<table id="sortabletbl1" class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
<tr><th><a href="{$list.sort.time.link|escape}">Time</a>{if $list.sort.time.direction} <img src="images/{$list.sort.time.direction|escape}.gif" class="absmiddle" alt="{$list.sort.time.direction|escape}">{/if}</th><th><a href="{$list.sort.by.link|escape}">By</a>{if $list.sort.by.direction} <img src="images/{$list.sort.by.direction|escape}.gif" class="absmiddle" alt="{$list.sort.by.direction|escape}">{/if}</th><th><a href="{$list.sort.action.link|escape}">Action</a>{if $list.sort.action.direction} <img src="images/{$list.sort.action.direction|escape}.gif" class="absmiddle" alt="{$list.sort.action.direction|escape}">{/if}</th><th><a href="{$list.sort.on.link|escape}">On</a>{if $list.sort.on.direction} <img src="images/{$list.sort.on.direction|escape}.gif" class="absmiddle" alt="{$list.sort.on.direction|escape}">{/if}</th><th><a href="{$list.sort.outcome.link|escape}">Outcome</a>{if $list.sort.outcome.direction} <img src="images/{$list.sort.outcome.direction|escape}.gif" class="absmiddle" alt="{$list.sort.outcome.direction|escape}">{/if}</th><th>Detail</th></tr>
{foreach $log as $row}
<tr><td class="text-nowrap">{$row.time|escape}</td><td class="text-nowrap">{$row.actor|escape}</td><td class="text-nowrap">{$row.action|escape}</td><td class="text-nowrap">{$row.target|escape}</td><td class="text-nowrap">{$row.outcome|escape}</td><td>{$row.detail|escape}</td></tr>
{foreachelse}
<tr><td colspan="6">{$words.noRecords|escape}</td></tr>
{/foreach}
</table>
</div>
{if $list.pages > 1}<div class="text-center"><ul class="pagination">{foreach $list.pager as $link}<li class="{if $link.disabled}disabled{/if}{if $link.current}active{/if}"><a href="{$link.link|escape}" class="page-selector{if $link.current} active{/if}" data-page="{$link.page|escape}">{$link.label|escape}</a></li>{/foreach}</ul></div>{/if}
{if $clearable}
<form method="post" action="{$listLink|escape}" class="form-inline top-margin-10">
<input type="hidden" name="token" value="{$token|escape}">
<label for="mainwp-retention">Log Retention</label> <input type="text" id="mainwp-retention" class="form-control input-100" name="log_retention_months" value="{$retention|escape}"> months <button type="submit" name="action" value="save_retention" class="btn btn-default">Save</button>{if $logTotal > 0} <button type="submit" name="action" value="clear_log" class="btn btn-default pull-right">Clear Log</button>{/if}
<p class="help-block">The daily check deletes older entries. 0 keeps every entry.</p>
</form>
{/if}
{elseif $connect}
<h2 class="top-margin-10">Connect Site</h2>
<form method="post" action="{$connect.action|escape}">
<input type="hidden" name="token" value="{$token|escape}">
<input type="hidden" name="action" value="connect">
<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
<tr><td class="fieldlabel label-top" width="25%"><label>Client</label></td><td class="fieldarea">{$connect.client|escape}</td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-connect-address">Site URL</label></td><td class="fieldarea"><input type="text" id="mainwp-connect-address" name="address" value="{$connect.address|escape}" class="form-control input-400"></td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-connect-username">Administrator Username</label></td><td class="fieldarea"><input type="text" id="mainwp-connect-username" name="site_username" value="{$connect.username|escape}" autocomplete="off" class="form-control input-400"></td></tr>
<tr><td class="fieldlabel label-top"><label for="mainwp-connect-password">Administrator Password</label></td><td class="fieldarea"><input type="password" id="mainwp-connect-password" name="site_password" value="" autocomplete="new-password" class="form-control input-400"></td></tr>
</table>
<div class="btn-container"><button type="submit" class="btn btn-primary">Connect Site</button> <a href="{$connect.cancel|escape}" class="btn btn-default">{$words.cancel|escape}</a></div>
</form>
{else}
<form method="post" action="{$listLink|escape}" class="top-margin-10">
<input type="hidden" name="token" value="{$token|escape}">
<div class="row">
<div class="col-sm-6"><p class="form-control-static">Last check: {if $check.at}{$check.at|escape}{if $check.dryRun} (dry run){/if}{else}{$words.never|escape}{/if}</p></div>
<div class="col-sm-6 text-right">{if $dashboardUrl}<a href="{$dashboardUrl|escape}" target="_blank" rel="noopener" class="btn btn-default">Open MainWP</a> {/if}<button type="submit" name="action" value="daily_check" class="btn btn-default">Check Now</button></div>
</div>
</form>
{if $check.fault}<div class="alert alert-danger top-margin-10">{$check.fault|escape}</div>{/if}
{if $others}<div class="alert alert-warning top-margin-10"><ul>{foreach $others as $line}<li>{$line|escape}</li>{/foreach}</ul></div>{/if}
<ul class="nav nav-tabs admin-tabs admin-tabs--toggleable top-margin-10" role="tablist"><li{if $filter.open} class="active"{/if}><a class="tab-top" href="#mainwp-filter" role="tab" data-toggle="tab" aria-expanded="{if $filter.open}true{else}false{/if}">{$words.searchFilter|escape}</a></li></ul>
<div class="tab-content admin-tabs">
<div class="tab-pane{if $filter.open} active{/if}" id="mainwp-filter">
<form method="get" action="addonmodules.php">
{foreach $filter.hidden as $field}<input type="hidden" name="{$field.name|escape}" value="{$field.value|escape}">{/foreach}
<table class="form" width="100%" border="0" cellspacing="2" cellpadding="3">
<tr><td width="15%" class="fieldlabel"><label for="mainwp-search">Site or Client</label></td><td class="fieldarea"><input type="text" id="mainwp-search" name="search" value="{$filter.search|escape}" class="form-control input-300"></td><td width="15%" class="fieldlabel"><label for="mainwp-status">Status</label></td><td class="fieldarea"><select id="mainwp-status" name="status" class="form-control select-inline"><option value="">{$words.any|escape}</option>{foreach $filter.options as $option}<option value="{$option.value|escape}"{if $option.selected} selected{/if}>{$option.label|escape}</option>{/foreach}</select></td></tr>
</table>
<div class="btn-container"><button type="submit" class="btn btn-default">{$words.search|escape}</button>{if $filter.open} <a href="{$filter.resetLink|escape}" class="btn btn-default">Reset</a>{/if}</div>
</form>
</div>
</div>
<form id="frmRecordsFound" method="get" action="addonmodules.php" class="top-margin-10">
{foreach $list.hidden as $field}<input type="hidden" name="{$field.name|escape}" value="{$field.value|escape}">{/foreach}
<input type="hidden" name="page" value="{$list.page|escape}">
<div class="row">
<div class="col-md-6 col-sm-12">{$list.summary|escape}</div>
<div class="col-md-6 col-sm-12 text-right">{if $list.pages > 1}{$list.jumpLabel|escape}: <div class="btn-group paging-dropdown" role="group"><button type="button" class="btn btn-default btn-xs dropdown-toggle" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false"><span id="currentPage">{$list.page|escape}</span> <span class="caret"></span></button><ul class="dropdown-menu dropdown-menu-right">{foreach $list.jump as $jump}<li{if $jump.current} class="active"{/if}><a href="{$jump.link|escape}" data-page="{$jump.page|escape}">{$jump.page|escape}</a></li>{/foreach}</ul></div>{/if}</div>
</div>
</form>
<div class="tablebg">
<table id="sortabletbl1" class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
<tr><th><a href="{$list.sort.site.link|escape}">Site</a>{if $list.sort.site.direction} <img src="images/{$list.sort.site.direction|escape}.gif" class="absmiddle" alt="{$list.sort.site.direction|escape}">{/if}</th><th><a href="{$list.sort.client.link|escape}">Client</a>{if $list.sort.client.direction} <img src="images/{$list.sort.client.direction|escape}.gif" class="absmiddle" alt="{$list.sort.client.direction|escape}">{/if}</th><th><a href="{$list.sort.status.link|escape}">Status</a>{if $list.sort.status.direction} <img src="images/{$list.sort.status.direction|escape}.gif" class="absmiddle" alt="{$list.sort.status.direction|escape}">{/if}</th><th><a href="{$list.sort.lastsync.link|escape}">Last Sync</a>{if $list.sort.lastsync.direction} <img src="images/{$list.sort.lastsync.direction|escape}.gif" class="absmiddle" alt="{$list.sort.lastsync.direction|escape}">{/if}</th><th><a href="{$list.sort.issue.link|escape}">Issue</a>{if $list.sort.issue.direction} <img src="images/{$list.sort.issue.direction|escape}.gif" class="absmiddle" alt="{$list.sort.issue.direction|escape}">{/if}</th><th></th></tr>
{foreach $sites as $row}
<tr><td class="text-nowrap">{if $row.siteLink}<a href="{$row.siteLink|escape}">{$row.site|escape}</a>{else}{$row.site|escape}{/if}</td><td class="text-nowrap">{if $row.clientLink}<a href="{$row.clientLink|escape}">{$row.client|escape}</a>{else}{$row.client|escape}{/if}</td><td class="text-nowrap">{if $row.status}<span class="label {$row.statusClass|escape}">{$row.status|escape}</span>{/if}</td><td class="text-nowrap">{$row.lastSync|escape}</td><td>{$row.issue|escape}</td><td class="text-right text-nowrap">{if $row.connectLink}<a href="{$row.connectLink|escape}" class="btn btn-default btn-sm">Connect</a>{/if}{if $row.dashboardLink} <a href="{$row.dashboardLink|escape}" target="_blank" rel="noopener" class="btn btn-default btn-sm">Open in MainWP</a>{/if}</td></tr>
{foreachelse}
<tr><td colspan="6">{if $filter.open}{$words.noRecords|escape}{else}No sites yet. A site appears when its care add-on is paid.{/if}</td></tr>
{/foreach}
</table>
</div>
{if $list.pages > 1}<div class="text-center"><ul class="pagination">{foreach $list.pager as $link}<li class="{if $link.disabled}disabled{/if}{if $link.current}active{/if}"><a href="{$link.link|escape}" class="page-selector{if $link.current} active{/if}" data-page="{$link.page|escape}">{$link.label|escape}</a></li>{/foreach}</ul></div>{/if}
{if $more}<p class="text-muted">{$more|escape}</p>{/if}
<div class="tablebg">
<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">
<tr><th width="170">Status</th><th>Meaning</th></tr>
{foreach $statusKey as $status}<tr><td class="text-nowrap"><span class="label {$status.class|escape}">{$status.label|escape}</span></td><td>{$status.means|escape}</td></tr>
{/foreach}
</table>
</div>
{/if}
</div>
</div>
{/if}
</div>
