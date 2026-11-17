{* SPDX-License-Identifier: AGPL-3.0-or-later
   MainWP for WHMCS: one care add-on on the admin service page. Only escaped variables, if and foreach. *}
<div class="mainwp-service">
{if $dryRun}<span class="label label-warning badge bg-warning">Dry run</span> {/if}<strong>Status:</strong> <span class="label {$stateClass|escape}">{$state|escape}</span>{if $stateNote} {$stateNote|escape}{/if}<br>
<strong>Site URL:</strong> {$address|escape}<br>
<strong>Last Sync:</strong> {$lastSync|escape}
{if $lastError}<br><strong>Issue:</strong> {$lastError|escape}{/if}
{if $retryAfter}<br><strong>Next Retry:</strong> {$retryAfter|escape}{/if}
{if $lastConnect}<br><strong>Last Connect Attempt:</strong> {$lastConnect|escape}{/if}
{if $noConnect}<br>{$noConnect|escape}{/if}
{if $connectLink}<br><a href="{$connectLink|escape}" class="btn btn-default btn-sm">Connect Site</a>{/if}
</div>
