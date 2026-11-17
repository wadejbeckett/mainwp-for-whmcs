{* SPDX-License-Identifier: AGPL-3.0-or-later
   MainWP for WHMCS: the body of the client's panel in the sidebar of the service's product details page; the sidebar draws its frame and heading. Only escaped variables, if and foreach. *}
{foreach $items as $item}
<div class="care-site mb-2">
{if $item.address}<p class="text-break"><strong>{$item.address|escape}</strong></p>{/if}
<p>{$item.message|escape}</p>
{if $item.connectLink}<p><a class="btn btn-primary btn-sm" href="{$item.connectLink|escape}">Connect your site</a></p>{/if}
{if $item.lastSync}<p class="text-muted small">Last synchronised: {$item.lastSync|escape}</p>{/if}
</div>
{/foreach}
