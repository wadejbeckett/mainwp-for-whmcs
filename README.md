# MainWP for WHMCS

MainWP for WHMCS is a free WHMCS module for hosts who sell website care for WordPress. You sell care as a product add-on in WHMCS under your own name; when a client pays for it, the module puts the client and their WordPress site on your MainWP dashboard through MainWP's REST API, and from then on keeps the site in step with billing: an overdue invoice suspends care on the dashboard, payment resumes it, and ending the add-on removes the site. Once a day it checks that what WHMCS bills is what the dashboard holds, and emails your staff what needs a person. It needs only WHMCS, the free MainWP Dashboard plugin and your clients' WordPress sites.

It is two WHMCS modules that work together: a provisioning module (`modules/servers/mainwp`), which WHMCS calls to create, suspend, unsuspend and terminate care, and an add-on module (`modules/addons/mainwp`), which holds the settings, the staff pages, the client's connect page and the daily check.

The full behaviour, for staff and developers, is in [docs/reference.md](docs/reference.md), which is also in the release zip.

Contents:

- [Requirements](#requirements)
- [Quick start](#quick-start)
- [How it works](#how-it-works)
- [What your clients see](#what-your-clients-see)
- [What your staff see](#what-your-staff-see)
- [A sample welcome email](#a-sample-welcome-email)
- [Settings](#settings)
- [What data goes where](#what-data-goes-where)
- [Upgrading](#upgrading)
- [Removing](#removing)
- [When something goes wrong](#when-something-goes-wrong)
- [Security](#security)
- [Support](#support), [Licence](#licence), [Names and trademarks](#names-and-trademarks)

## Requirements

**WHMCS**

- WHMCS 9.1 (tested), with its daily cron job running.
- PHP 8.2, 8.3 or 8.4, with the extensions curl, mbstring, ctype, filter, hash and json.
- Outbound HTTPS from the WHMCS server to your MainWP dashboard and to your clients' sites.
- WHMCS's System URL on https:// for the approve route (a WordPress site sends an approved key back only over HTTPS).

**MainWP Dashboard**

- MainWP Dashboard 6.4 or newer: the free plugin. No MainWP Pro and no extension is needed.
- On an https:// address with a publicly trusted certificate.
- WordPress permalinks set to anything other than Plain, so that its REST API answers under `/wp-json/`.
- A web server that passes the Authorization header on to WordPress (the API key travels in it).
- A REST API key made on the dashboard under API Access, with both permissions: Read, and Write & Delete.
- One dashboard serves one WHMCS. Do not point two WHMCS installations at the same dashboard.

**Each client's site**

- WordPress 5.7 or newer, on https://.
- Its REST API reachable from the WHMCS server.
- An administrator account that can install plugins.
- For the approve route: application passwords switched on (WordPress has them on by default over HTTPS).
- The site is the domain of the hosting service the care add-on sits on, a subdomain of it, or a folder under it.

What commonly blocks a connection: a captcha on the login page, two-factor login on the account used, a login limiter, a firewall or an HTTP login in front of the site, and file changes switched off in WordPress (`DISALLOW_FILE_MODS`), which stops the plugin being installed. The approve route avoids the first three, because the client signs in on their own site.

**Not tested:** WordPress multisite.

**Client-area themes:** the client's panel and connect page are written with Bootstrap 4's classes and load no stylesheet of their own. WHMCS's Nexus and Twenty-One themes, and themes built on them, carry those classes; under Six (Bootstrap 3) the spacing is plainer.

**Language:** everything the module shows, to clients and to staff, is in English. It has no language files yet.

**Size:** measured on a test dashboard (MainWP Dashboard 6.4.2.1). With 600 clients and 600 sites the daily check took about 15 seconds, and setting up care for a new client about one second. With 2,000 sites of 40 plugins each the daily check took about 75 seconds, and with 5,000 sites about five and a half minutes. Nearly all of that time is the dashboard answering its own site list, which the module reads 20 sites at a time (the client list 100 at a time); PHP's memory stayed under 10 MB at every size. A check that finds more than 100 things to report lists the first 100 and counts the rest.

## Quick start

1. Download the release zip (`mainwp-for-whmcs-<version>.zip`) from the project's releases page, https://github.com/wadejbeckett/mainwp-for-whmcs/releases, and unpack it.
2. Copy `modules/addons/mainwp` into your WHMCS installation's `modules/addons/` folder, and `modules/servers/mainwp` into its `modules/servers/` folder.
3. In WHMCS's admin area, open System Settings > Addon Modules, find MainWP for WHMCS and press Activate. Activate it there, not through WHMCS's API: only the admin area registers the module's hooks. The module starts in dry-run mode.
4. On the same page press Configure, tick the admin roles that may open the module's page (your own at least), and Save Changes.
5. Create your care product add-on under System Settings > Product Addons, or open the one you have. Give it any name you like: your clients read that name. Set its price and the hosting products it is offered with.
6. On the add-on's Module Settings tab choose Module "MainWP for WHMCS", Addon Type "Independent Product" and "Automatically setup the addon as soon as an order is placed", and Save Changes. No server or server group is needed. This step alone makes it a care add-on.
7. Make the API key on your MainWP dashboard: API Access, Add API Keys, tick Read and Write & Delete, and copy the key with the button beside "API key (Bearer token)". MainWP shows it only once.
8. Open Addons > MainWP for WHMCS > Settings and enter:
   - the MainWP Dashboard URL (its https:// address);
   - the API Key;
   - the Care Account Email (see [Settings](#settings)).

   Press Save Changes. Saving also tests the connection: the page shows how many clients and sites the dashboard holds, or why it could not answer.
9. Leave the Mode on Dry Run for a while and watch the Sites and Log tabs. In dry run the module logs what it would do and changes nothing on the dashboard or on any site; the connection test and the daily digest still work, and no client sees the care panel or the connect page.
10. When the Sites and Log tabs show what you expect, set the Mode to Live and save. Every billed care add-on is then provisioned at the next daily check at the latest, and its client sees "Connect your site" on their service page.

The zip holds:

- `modules/addons/mainwp/`: the add-on module;
- `modules/servers/mainwp/`: the provisioning module;
- `README.md`, `CHANGELOG.md`, `docs/reference.md` and `LICENSE`.

The releases page also has a `.sha256` file beside each zip, so you can check the download.

Before you switch to Live:

- the connection test on the Settings tab shows the dashboard's counts;
- your care add-on is listed on the Settings tab;
- a Care Account Email is saved;
- WHMCS's System URL (System Settings > General Settings) is an https:// address, or clients are offered only the username-and-password way;
- the Sites tab lists the care add-ons you expect, and the Log tab shows what the module would have done.

## How it works

**Order and payment.** When a client orders the care add-on, WHMCS calls the module at checkout. The module waits until the invoice is paid; then it finds or creates the client on the dashboard and records a care site at the hosting service's domain. Nothing is added to the dashboard's site list yet: MainWP adds a site only by connecting to it, which needs the client.

- A care add-on must sit on a hosting service that has a domain. One whose service has no domain is not provisioned: the log and the daily digest tell staff why, and the client reads that the service has no domain.
- When the client's email address already belongs to a client on the dashboard, the module files the site under that dashboard client, as long as exactly one has that address (letter case ignored) and its note names no other WHMCS client. It leaves that client's note as it is and says so in the log. When several dashboard clients have that address, or the one that has it names another WHMCS client or already holds another WHMCS client's care site, the log says what staff can do; when none has it, the log keeps the dashboard's own refusal.
- Each site is cared for by one care add-on. A second care add-on of the same client, for a site that another of their care add-ons already covers, is refused when the client or staff connect it, and the daily check reports it.

**Connecting a site.** The client opens "Connect your site" in their client area, enters the site's address once, and chooses one of two ways below it.

- *Approve on my site.* The client is sent to their own WordPress site, signs in there as usual (captcha and two-factor login included) and approves the connection. WordPress gives the module a key for that one site. With it the module installs MainWP's plugin, creates one administrator account for care, and has the dashboard connect the site as that account. The key is then deleted on the site. The client's own password is never given to you or to the module.
- *Administrator username and password.* The client types an administrator login. The module logs in once with WordPress's own login form, installs MainWP's plugin and logs out; the dashboard connects the site, the module creates the care account, and the login typed is deleted.

Staff can connect a site with an administrator login from the Sites tab (Connect). A failed connect is tried again by the daily check, once a day for seven days, and the client is asked to connect again when the retries end.

**Suspend, unsuspend and terminate.**

- When WHMCS suspends the care add-on (an overdue invoice, or staff), the module sets MainWP's suspended flag on the site: its automatic updates and checks stop, and nothing changes on the site.
- When the invoice is paid and WHMCS unsuspends the add-on, the flag is cleared.
- When WHMCS terminates or cancels the add-on, the module removes the site from the dashboard (see [Removing](#removing) for what stays on the site).
- When the hosting service is suspended, unsuspended or terminated through its module (WHMCS's cron, or staff pressing its Module Commands), the module does the same for every care site under it; unsuspending resumes only the sites whose care add-on is Active.
- A care add-on's status changed by hand, or through WHMCS's API, is followed too. A hosting service's status changed by hand is not, and the daily check reports what is out of step.
- If the dashboard cannot be reached when WHMCS calls the module, WHMCS keeps the add-on's status and holds the command in its Module Queue, to be retried. A change made by hand, or by the hosting service's module, is logged as failed, and the daily check reports the site as out of step.
- The module never changes a status, a price or a date in WHMCS, and never deletes a client on the dashboard.

**The daily check.** At the end of WHMCS's daily cron the module sets every care add-on against its own records and against the dashboard. It provisions what is billed and not yet provisioned, retries failed connects, mends connected sites that the dashboard reports as disconnected, takes over a site staff connected by hand on the dashboard for a care site that waits, and reports everything else that is out of step without changing it. It then emails the daily digest to the administrators whose role receives WHMCS's system emails: by default only when something is new.

## What your clients see

- **A panel on their service page.** In the sidebar of the hosting service's page in the client area, a panel headed with your care add-on's name shows the site's address and its state in plain words, with a "Connect your site" button while the site waits to be connected.
- **The connect page** at `index.php?m=mainwp&addon=<n>`, where `<n>` is the number of the client's ordered add-on (the service add-on's ID in WHMCS, not the product add-on's). It is headed with your add-on's name.
- **Your name, not MainWP's.** Clients read your care add-on's name, and nothing the module shows them names MainWP or the module. The page tells them that "a plugin that lets us look after your site" is installed. Without MainWP's paid White Label extension, the client's own plugin list shows it as "MainWP Child"; with it, the plugin carries your brand.
- **On their site:** one plugin (MainWP Child) and one administrator account whose login begins "wpcare"; on the approve route also, until the site is connected, an application password named after your care add-on.

Who can see the panel and the page: the account's owner, a user of the account with the permission to manage its products, and an administrator logged in as the client. In dry run no client sees the care panel or the connect page.

## What your staff see

- **Addons > MainWP for WHMCS** has three tabs. **Sites** lists every care site with its status (Provisioned, Connecting, Connected, Disconnected, Failed, Suspended, Removed, or Not provisioned) and what the last daily check found, with Connect for a site that waits, "Open in MainWP", and "Check Now" to run the daily check at once. **Settings** holds the settings below. **Log** lists everything the module did.
- **The client's service page** in the admin area has a panel for each care add-on: its status, the site's address, the last sync, any issue, the next retry and the last connect attempt, with a link to connect the site.
- **The daily digest** comes by email to the administrators whose role receives WHMCS's system emails (System Settings > Administrator Roles); the Settings tab names them.
- The Sites and Log lists can be paged, sorted and filtered as WHMCS's own lists are, with WHMCS's own number of records per page.

## A sample welcome email

You can paste this into a new WHMCS email template of the type Product/Service (System Settings > Email Templates) and choose that template as the care add-on's Welcome Email. Change the words to suit your service. WHMCS sends it when the add-on is ordered, which is before the invoice is paid, and the panel shows its button only once the invoice is paid; choose it as the Welcome Email only once the module is Live, because in dry run clients see no panel.

```text
Dear {$client_first_name},

Thank you for ordering care for your WordPress site.

Once your invoice is paid, please connect your site: log in to our client area, open the hosting service the care is for, and press "Connect your site" in the care panel on that page.

You need an administrator login for your WordPress site. You can either approve the connection on your own site, or give us an administrator username and password once.

{$signature}
```

## Settings

All on the Settings tab of Addons > MainWP for WHMCS, except Log Retention, which is on the Log tab.

- **Mode**: Dry Run logs what would be done and changes nothing on the dashboard or on any site; Live acts. Live needs an API key and a Care Account Email.
- **MainWP Dashboard URL**: the dashboard's https:// address.
- **API Key**: the dashboard's REST API key, stored encrypted and never shown again; changing the dashboard address asks for it again.
- **Care Account Email**: the email address given to the care account on every client's site. Use an address you receive mail at, and one that accepts plus addresses (`name+tag@`).
- **Daily Digest**: Off, When something is new (the default), or Every day.
- **Log Retention**: how many months of log to keep; 0 keeps every entry.

The Settings tab also lists your care add-ons, each by name with a link to it.

## What data goes where

- **To your MainWP dashboard:** each client's name and email address, a note "WHMCS client N" (N is the client's number in WHMCS), each site's address, the login and password of the administrator account the dashboard connects as, and for the care account its login, password and email (your Care Account Email) with your care add-on's name as its first and last name.
- **On each client's site:** MainWP's plugin, and the care account, an administrator whose email is your Care Account Email; on the approve route, an application password named after your care add-on, deleted once the site is connected (kept, encrypted in WHMCS, for up to a week while a failed connection is retried).
- **In WHMCS's database:** the module's three tables (`mod_mainwp_sites`, `mod_mainwp_tokens`, `mod_mainwp_log`), its settings, the care account's login and, while a failed connect is retried, the login typed or the approved key, all encrypted with WHMCS's own encryption, the hashes of approval tokens, the names (only) of each site's other administrators, and a log of what the module did. No password, key or token is written to the log.

The daily digest, with client names and site addresses, goes by email to your administrators. Nothing is sent anywhere else.

## Upgrading

1. Back up the WHMCS database and the two module folders.
2. Move the old `modules/servers/mainwp` folder aside, then the old `modules/addons/mainwp`. Put the new `modules/addons/mainwp` in place first and the new `modules/servers/mainwp` last.
3. Open System Settings > Addon Modules in the admin area. WHMCS then runs the module's upgrade, which records each change in WHMCS's activity log.
4. Empty WHMCS's template cache (Utilities > System > System Cleanup, Empty Template Cache), so that the client pages show the new version.

## Removing

1. On each care product add-on's Module Settings, set the Module to None and save. Do this first, so that WHMCS does not call a module that is gone.
2. Under System Settings > Addon Modules, deactivate MainWP for WHMCS. To remove its tables too, tick "Delete module data on deactivate" under Configure before you deactivate. While the module is deactivated nothing is suspended, resumed or removed on the dashboard: its commands answer that the module is not active, and WHMCS holds them in its Module Queue.
3. Delete the two folders `modules/addons/mainwp` and `modules/servers/mainwp`.

What stays behind:

- **In WHMCS:** the module's three tables, unless "Delete module data on deactivate" was ticked; they hold the care accounts' logins, encrypted. To remove them afterwards, drop `mod_mainwp_sites`, `mod_mainwp_tokens` and `mod_mainwp_log`. WHMCS itself deletes the module's settings when it is deactivated (the dashboard address, the API key, the Care Account Email and the mode): enter them again if you activate the module again.
- **On the dashboard:** the dashboard clients and sites, as they are. Deactivating changes nothing there; remove them on the dashboard if you want them gone.
- **On each client's site:** the care administrator account and MainWP's plugin. When care ends for a site the same is true: the module removes the site from the dashboard, MainWP switches its plugin off on the site, and the plugin's files and the care account stay. The connect page tells the client before they connect that the care account stays when care ends and that they can delete it under Users in their WordPress dashboard; MainWP's plugin, switched off, can be deleted under Plugins. The dashboard client stays on the dashboard.

## When something goes wrong

Where to look:

- **The Sites tab** lists every care site with its status and what the last daily check found, what needs attention first.
- **The Log tab** has every action the module took, with its outcome and reason.
- **WHMCS's Module Queue** (Utilities > Module Queue) holds a suspend, unsuspend or terminate that failed; retry it there once the cause is fixed.
- **The daily digest** lists what needs a person, with failed commands first.

The five commonest causes:

1. **The dashboard refuses the API key.** Check the key has Read and Write & Delete, and that the web server in front of the dashboard passes the Authorization header on to WordPress.
2. **The dashboard does not answer as MainWP.** Check the address, that MainWP Dashboard is active there, and that its permalinks are not Plain.
3. **The client's login cannot be used.** A captcha, two-factor login or a login limiter stops the username-and-password way. Ask the client to use "Approve on my site", or an account without those.
4. **The approve route is not offered.** Application passwords are switched off on the site (often by a security plugin), or an HTTP login or firewall stands in front of it, or WHMCS's System URL is not https://.
5. **The plugin cannot be installed.** File changes are switched off on the site, or MainWP's plugin there still holds a connection to another dashboard: disconnect it on the site (Settings, MainWP Child) and connect again.

## Security

- The API key, and the care account's login, are stored encrypted with WHMCS's own encryption. Anyone holding both the WHMCS database and its configuration file can decrypt them, as with the passwords WHMCS stores for hosting services.
- A login a client or staff types is kept only while a failed connect is retried, then deleted.
- On the approve route the key is deleted on the site as soon as the connection is made. While a failed connection is retried (up to a week) it is kept encrypted, and it is only ever sent to the site it was approved on.
- The module never logs or shows a password, key or token, and every request goes over HTTPS with certificates checked.
- The care account stays on the client's site as an administrator after care ends. Its password is deleted from WHMCS when the site is removed, but an older backup of the WHMCS database still holds it.

## Support

Report problems and ask questions at the project's issues: https://github.com/wadejbeckett/mainwp-for-whmcs/issues

## Licence

MainWP for WHMCS is free software under the GNU Affero General Public License, version 3 or (at your option) any later version (AGPL-3.0-or-later). See [LICENSE](LICENSE).

Copyright (C) 2026 Noiz

## Names and trademarks

MainWP for WHMCS is not made or endorsed by MainWP or by WHMCS. MainWP is a registered trademark of Sick Marketing LLC. WHMCS is a trademark of WHMCS Limited. The names are used only to say what the module works with.
