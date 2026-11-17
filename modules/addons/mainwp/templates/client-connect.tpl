{* SPDX-License-Identifier: AGPL-3.0-or-later
   MainWP for WHMCS: the client page that connects a site, by an approval on the client's own site or with an administrator login. Only escaped variables, if and foreach. *}
<div class="care-connect">
{if !$available}
<div class="alert alert-info">This page is not available. If you expected to connect a WordPress site here, please contact support.</div>
{elseif $outcomeOnly}
<h2>{$name|escape}</h2>
{if $address}<p><strong>{$address|escape}</strong></p>{/if}
{if $redirect}<p><a class="btn btn-primary" href="{$redirect|escape}">Continue</a></p>{/if}
{if $message}<p>{$message|escape}</p>{/if}
{if $note}<div class="alert alert-info">{$note|escape}</div>{/if}
{if !$redirect}<p><a class="btn btn-default btn-secondary" href="clientarea.php">Go to your client area</a></p>{/if}
{else}
<h2>{if $waiting}Connect your WordPress site{else}Your WordPress site{/if}</h2>
{if $address}<p><strong>{$address|escape}</strong></p>{/if}
{if !$error}<p>{$message|escape}</p>{/if}
{if $lastSync}<p class="text-muted small">Last synchronised: {$lastSync|escape}</p>{/if}
{if $invoices}<p><a class="btn btn-primary" href="{$invoices|escape}">View your invoices</a></p>{/if}
{if $note}<div class="alert alert-info">{$note|escape}</div>{/if}
{if $notice}<div class="alert alert-info">{$notice|escape}</div>{/if}
{if $error}<div class="alert alert-danger">{$error|escape}</div>{/if}
{if $redirect}<p><a class="btn btn-primary" href="{$redirect|escape}">Continue to your site to approve the connection</a></p>{/if}
{if $continue}<p><a class="btn btn-primary" href="{$continue|escape}">Continue to your site to approve the connection</a></p>{/if}
{if $form}
{if $keyReason}<div class="alert alert-warning">{$keyReason|escape}</div>{/if}
{if $wordfence && $keyRoute}<p class="small">Your site runs Wordfence. It will report the account we create as an administrator "created outside of WordPress". That alert is expected.</p>{/if}
<form method="post" action="{$formAddress|escape}" autocomplete="off">
<input type="hidden" name="token" value="{$token|escape}">
<div class="form-group mb-3">
<label for="care-address">Site address</label>
<input type="text" class="form-control" id="care-address" name="address" value="{$fieldAddress|escape}">
<small class="form-text help-block text-muted">The address of the WordPress site on this hosting service, a subdomain or a folder under it.</small>
</div>
{if $keyRoute}
<h3>Approve the connection on your own site</h3>
<p class="small">You sign in on your own site and approve the connection there, so your password is never given to us. With your approval we install a plugin on your site that lets us look after it, and create one administrator account for care; its name begins with "wpcare". That account stays on your site when care ends; you can delete it then under Users in your WordPress dashboard. If your site's login page has its own address, sign in to your site first.</p>
<button type="submit" name="action" value="approve" class="btn btn-primary">Approve on my site</button>
<h3 class="mt-4">Or connect with an administrator username and password</h3>
{/if}
<div class="form-group mb-3">
<label for="care-username">Administrator username or email</label>
<input type="text" class="form-control" id="care-username" name="username" value="" autocomplete="username">
</div>
<div class="form-group mb-3">
<label for="care-password">Administrator password</label>
<input type="password" name="password" class="form-control" id="care-password" value="" autocomplete="new-password">
</div>
{if $careAccount}<p class="small">We log in to your site once with this login, install a plugin that lets us look after your site, and connect it. We then create one administrator account for care, whose name begins with "wpcare", and delete the login you gave us. That account stays on your site when care ends; you can delete it then under Users in your WordPress dashboard. A login captcha or two-factor login on your site stops this, and a login limiter may lock us out for a while; if your site has one of these, please contact support.</p>{else}<p class="small">We log in to your site once with this login, install a plugin that lets us look after your site, and connect it; we then delete the login you gave us. A login captcha or two-factor login on your site stops this, and a login limiter may lock us out for a while; if your site has one of these, please contact support.</p>{/if}
{if $wordfence && !$keyRoute}<p class="small">Your site runs Wordfence. It will report the account we create as an administrator "created outside of WordPress". That alert is expected.</p>{/if}
<button type="submit" name="action" value="connect" class="btn btn-primary">Connect my site</button>
</form>
{/if}
{/if}
</div>
