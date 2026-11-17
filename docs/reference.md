# MainWP for WHMCS: reference

This is the module's behaviour in full, for staff and developers. The [README](../README.md) covers requirements, installing, upgrading and removing.

MainWP for WHMCS is a WHMCS provisioning module (`modules/servers/mainwp`) with an add-on module beside it (`modules/addons/mainwp`). WHMCS bills the care service, a product add-on that the host names and sells as its own; the module provisions, connects, suspends and removes each care add-on's site on one MainWP dashboard through MainWP's REST API, and once a day checks that what is billed is what is connected.

## Names

- **MainWP for WHMCS** is the module. Staff read this name in WHMCS's admin area (the Addons menu, the module's page, its emails and its log lines). Clients never read it.
- **The service** is the host's care product add-on. Clients read its name, as it is set in WHMCS (the product add-on's name), read each time and never stored by the module. Where no single care add-on is at hand, the module uses the name of the only care add-on when there is exactly one, and otherwise the words "Website Care".
- **MainWP** is never named to a client. A client reads that "a plugin that lets us look after your site" is installed. With MainWP's paid White Label extension the plugin carries the host's brand on the client's site; without it the site lists it as "MainWP Child". The module's words are true either way.
- **wpcare** begins the login of every care account the module makes on a client's site. It is how the module recognises its own accounts; only an account whose login begins so is ever removed by the module.

## Which add-ons are care

A product add-on is a care add-on when its Module Settings in WHMCS name MainWP for WHMCS, and by nothing else. Set Addon Type "Independent Product" and "Automatically setup the addon as soon as an order is placed": WHMCS then calls the module at checkout, the module waits for the invoice to be paid, and care is set up at payment with no staff click. On an install that keeps manual acceptance, care waits for a staff click on every order. No server or server group is needed. The Settings tab lists the care add-ons by name, each linked to its page in WHMCS. A care site whose add-on no longer names the module is reported by the daily check and is not removed.

A care add-on must sit on a hosting service that has a domain. The site is that domain, a subdomain of it, or a folder under it. A care add-on whose service has no domain is not provisioned: staff are told why in the log and in the daily digest, and the client reads that the service has no domain.

## Provisioning

A care add-on is **billed** when it is Active on an Active hosting service and has no unpaid invoice (Unpaid, Payment Pending or Collections; a Draft invoice does not count, since the client can neither see nor pay it). Billing is read from WHMCS each time and never stored.

When a care add-on is billed (WHMCS's `CreateAccount`, the events `AddonActivated`, `AddonActivation` and `InvoicePaid`, or the daily check), the module finds the WHMCS client on the dashboard by the note "WHMCS client N" (N is the WHMCS client's number), or creates it with the client's name and email and that note, and records the care site as Provisioned at the service's domain. Requests for one client are handled one at a time, so two at the same moment never create the client twice; a request that waits more than ten seconds fails and is tried again by the daily check, or when the invoice is paid.

**A customer whose email is already a client on the dashboard.** When the dashboard refuses to create the client because the email address is taken, the module takes the single dashboard client with the same email (letter case ignored) whose note names no other WHMCS client, files the site under it, does not change its note, and says so in the log. When more than one dashboard client has that email, or the one that has it names another WHMCS client in its note or already holds a care site of another WHMCS client, the failure says what staff can do; when no dashboard client has that email, the dashboard's own refusal is logged as it is.

A `CreateAccount` that does not reach the dashboard is logged, and the add-on is set up in WHMCS all the same; the daily check provisions its care site once the dashboard answers. One dashboard serves one WHMCS: the module knows its clients by their WHMCS number and its sites by the numbers the dashboard gave them.

## Connecting a site

The client opens "Connect your site" from the care panel on their hosting service's page in the client area (`index.php?m=mainwp&addon=<n>`, where `<n>` is the number of the client's ordered add-on, the service add-on's ID in WHMCS, not the product add-on's). The page asks for the site's address once, in one form, with both ways to connect below it: approving a key on the site, and an administrator username and password. Staff connect a site with a username and password from Connect on the Sites tab (the Connect Site form); the care panel on the client's service page in the admin area links to it.

**Before either way.** The address must be the hosting service's domain, or a subdomain or folder under it, and must be https://. The module reads the site's public REST index, without any login.

- An address that only redirects to another host (its "www." form, say) is followed when the target is an https:// address under the same hosting service; both ways then use the address the site really answers at, and no login or key is sent to the first one. Any other redirect cannot be connected, and the client is asked to contact support.
- An address on the host of an address the site's index reports as its own, but that is neither of them, is refused before anything is sent or stored, and the client is told the address to enter instead. The scheme, the host's letter case, a leading "www." and a trailing slash do not count; a folder with no WordPress of its own reads the main site's index. A site whose index reports only another host as its own (a domain alias served without a redirect) is connected at the address typed. An index that names no address, or cannot be read, changes nothing.
- **One site, one care add-on.** A site that another care add-on of the same client already covers (one that is connected, suspended or being connected) is refused with a plain sentence on the client page and on the staff Connect Site form, also when the address redirects to it; the daily check reports it and lets go of what a failed connect kept for its retries.
- The approve way is offered when the index shows WordPress's approval screen for application passwords on the site itself, over HTTPS, and WHMCS's System URL is an https:// address. Otherwise only the username-and-password way is offered, with the reason when it is about the client's site (application passwords switched off, by Wordfence where its REST namespace shows; an HTTP login or a firewall in front of the site; an address that is not https://). A reason that is about the host's own set-up (no Care Account Email, a System URL that is not https://) is not shown to the client; staff read it on the module's page.

While the add-on or its hosting service is not Active, the client page and the staff form connect nothing; the client's panel says that care is suspended, or not active, and the staff panel says why the connect form is not there. While a connection runs, the pages say so and offer nothing to press.

### By approving a key

1. The client presses "Approve on my site". The module sends their browser to the site's own approval screen, with a return address under WHMCS's System URL that carries a one-time token. Only the token's hash is kept, for 24 hours; the site is Connecting meanwhile.
2. The client signs in on their own site (two-factor login and captchas included) and approves. WordPress makes an application password, the key, and sends the browser back to the module with the site's address, the login name and the key.
3. The return works from the token alone: a client whose WHMCS session has lapsed is not lost. The key is used only when the token is known, unused and in time, the address that came back is the site the approval was started for, and care is still active. A key approved on any other site is never sent anywhere. A key that comes back and is not used is deleted on the site. The browser is then sent on to an address without the key.
4. With the key as HTTP Basic authentication, sent to the site itself and nowhere else, the module checks that the approving account is an administrator, makes the care account (a login beginning "wpcare", a generated password, the Care Account Email; when another account on the site already has that address, the address with the care login as its plus tag, such as `care+wpcareab12cd34@example.com`), and installs and activates MainWP Child.
5. The dashboard connects the site under the client as the care account. The care account's login becomes the stored login, encrypted; the site's other administrators are recorded (see below); and the key is deleted on the site as the last step.

A failure after the key came back ends in Failed with the reason in plain words and puts the site on the retry list, with the key and the care account kept for the retry; a care account made before a refused plugin install is removed again. An approver who is not an administrator ends it at once, with the key deleted and nothing to retry. An approval that is declined, or not given within 24 hours, ends in Failed with the reason; nothing is retried, unless an earlier username-and-password attempt left a stored login, which stays on the retry list. When the retries end, or a new attempt replaces the old one, the care account and the key of the old attempt are removed on the site where they were made; what could not be removed there is named for the client. After a successful connection the care account stays on the site, also when care ends; the client is told so before connecting, and how to delete it.

### With a username and password

1. The module reads the site's home page for the REST root and opens `wp-login.php`; it stops before sending the password if the page shows a captcha, a login lockout or a code field, or is not WordPress's login page.
2. It logs in once with WordPress's own login form (never twice: login limiters count every try).
3. It checks through the REST API that the account is an administrator, installs and activates MainWP Child through `POST /wp/v2/plugins` with the REST nonce, and logs the session out.
4. The dashboard connects the site under the client as that administrator.
5. The dashboard makes the care account on the site (`POST users/create`: a login beginning "wpcare", a generated password, the Care Account Email, and no password mailed), is pointed at it, and syncs.
6. The care account's login is kept encrypted as the stored login, the login typed is deleted, the site's other administrators are recorded, and the site shows as Connected with its last sync.

When the site refuses the care account (its login or its address is taken), a new login is tried once more with the address plus-tagged with it. When the care account cannot be made, or the dashboard cannot reach the site as it, the dashboard is pointed back at the administrator typed: the site is Connected as that administrator, the login typed is deleted all the same, and the log says why there is no care account.

A host that prepared the site on the server (MainWP Child installed and switched on, a care account made there) types that care account's login and password in the Connect Site form. The dashboard is then asked to connect the site as it, and the module does not log in to the site at all; only when the dashboard refuses does the module log in with that login, install and switch on MainWP Child, and ask the dashboard again. That care account is the stored login, and no other account is made.

A failure ends in Failed with the reason in plain words (site unreachable, not https, an address that only redirects to another host, login page not found, captcha, lockout, two-factor login, login refused, not an administrator, plugin install forbidden or failed, the dashboard's refusal) and puts the site on the retry list: the daily check tries again with the login typed, which is kept encrypted only for those retries and deleted when they end. A suspended add-on is not retried, so a login kept for it stays while it is suspended. An address typed with http:// is not retried, and the login given for it is not kept; nor is one for an address that only redirects. A retry first asks the dashboard whether it already holds the site, so that an answer lost on the way does not fail it for a week. A site found connected on the dashboard after a failed connect is recorded as Connected and the login typed is deleted. A fault between the module and the dashboard (the dashboard unreachable, its API key refused, an answer that is not MainWP's, a site the dashboard already holds at that address) is told to the client only as a fault on our side that is tried again; the full reason is in the log, for staff. Using this form while an approval is out ends that approval; a key approved afterwards is deleted on the site. A login staff save while a connection runs is not used and not kept, and the log says so.

### The site's other administrators

The logins of every administrator that is not a care account, as MainWP lists them right after the site is connected (`GET users?websites=<id>&roles=administrator`), in its order and at most ten; names only, never a password. They are recorded whenever the module connects a site, by either way, takes one over or mends it through one of them. When MainWP cannot list them, the record stays as it was, with at least the administrator typed or the approving account. The log gives only how many there are.

## Suspending, resuming and removing

WHMCS calls the module when it creates, suspends, unsuspends or terminates the care add-on (its cron for an overdue invoice, a payment, or staff pressing the add-on's Module Commands). A status changed by hand or through WHMCS's API calls no module; the module hears that change through WHMCS's events instead. Either way it acts on the dashboard only. It changes no status, price or date in WHMCS, and it never deletes a dashboard client.

| What happens in WHMCS | Event | What the module does |
|---|---|---|
| WHMCS creates the care add-on | `CreateAccount` | Provisions the care site when the add-on is billed; otherwise waits for payment. |
| WHMCS suspends the care add-on (overdue, or staff press Suspend) | `SuspendAccount` | Sets MainWP's suspended flag on the site; if the dashboard cannot be reached, WHMCS keeps the add-on Active and the suspension waits in its Module Queue. |
| WHMCS unsuspends it | `UnsuspendAccount` | Clears the flag when the hosting service is Active; if the dashboard cannot be reached, WHMCS keeps the add-on Suspended and the unsuspension waits in its Module Queue. |
| WHMCS terminates it | `TerminateAccount` | Removes the site from the dashboard and deletes the stored login; if the dashboard cannot be reached, WHMCS keeps the add-on's status and the termination waits in its Module Queue. |
| The status is set by hand or through the API to Suspended | `AddonSuspended` | Sets MainWP's suspended flag on the site: its automatic updates and uptime checks stop. Nothing changes on the site. |
| The status is set by hand or through the API to Active again | `AddonActivated`, `AddonUnsuspended` (asked again on `InvoicePaid`) | Clears the flag, when the hosting service is Active too. |
| The status is set by hand or through the API to Terminated or Cancelled, or the add-on is deleted | `AddonTerminated`, `AddonCancelled`, `AddonDeleted` | Removes the site from the dashboard and deletes the stored login and what is left of a retry. |
| The hosting service is suspended by its module | `AfterModuleSuspend` | Suspends every care site under the service. |
| The hosting service is unsuspended by its module | `AfterModuleUnsuspend` | Resumes the sites whose own care add-on is Active. |
| The hosting service is terminated by its module | `AfterModuleTerminate` | Removes every care site under the service. |

While the add-on module is deactivated, Create, Suspend, Unsuspend and Terminate answer "The add-on module MainWP for WHMCS is not active.", so WHMCS keeps the add-on's status and, for its own automated runs, holds the command in its Module Queue.

The service's module events are acted on only when they are about the service itself (`addonId` 0); the same events fire for another add-on's own module commands. A status set by hand on the hosting service, and a service that is cancelled or deleted, fire nothing the module can hear. An event that does not say which service or add-on it is for changes nothing and is written to the log with the outcome "failed", so that a suspension or an ending that may not have reached a care site does not pass unseen.

A site that was never connected has nothing to suspend on the dashboard. What a connect has open for it ends instead: an approval the client was sent to give stops working, and a key held for retries is used one last time to remove the care account made with it and to delete itself. A login kept from a password attempt stays until the care site is removed. A suspension first asks the dashboard whether it holds the site after all (a connect whose answer was lost), and suspends it there if it does.

A site that MainWP has flagged as staging is removed as MainWP removes it, without its plugin being switched off, and the log says so. When MainWP answers a removal with an error from the site, the module asks the dashboard whether it still holds the site and marks it Removed when it does not; MainWP's plugin then stays active on the site. A site the dashboard no longer holds is marked Removed all the same; only MainWP's own answer that it does not know the site counts for that, and any other "not found" (a dashboard address that points elsewhere, MainWP switched off) fails the removal and keeps the site and its login. When the dashboard cannot be reached, or another request works on the add-on for more than twenty seconds, the event's work is not done: the site keeps its state and the log has the event with the outcome "failed".

**What stays behind when care ends.** The module removes the site from the dashboard and deletes the stored login. MainWP switches its plugin off on the client's site and leaves its files there; the care administrator account stays on the client's site; the dashboard client stays on the dashboard. The client is told before connecting that the care account stays, and how to delete it.

In dry run each event is logged with what it would do, and nothing is sent. WHMCS still changes the add-on's status when it calls the module in dry run; switching to Live does not apply those changes to the dashboard afterwards. The first daily check after the switch reports every site that is out of step.

## The daily check

At the end of WHMCS's daily cron (`DailyCronJob`) the module sets every care add-on WHMCS holds against its own record and against what the dashboard holds. "Check Now" on the Sites tab runs it once more. One run at a time.

It acts on four things, and on nothing else:

- **A billed add-on with no care site** is provisioned.
- **A failed connect on the retry list** is tried again, once a day and seven times, with the stored login or the held key, for an add-on that is Active on an Active hosting service. A retry is due by the date, not to the second. After the seventh failure the retries end in the same run: the stored login is deleted, what a key attempt left on the site is removed, and the client is asked to connect again. A retry that fails because of the dashboard itself (unreachable, its API key refused) is not counted, and no further retry or mend is made in that run.
- **A connected site the dashboard reports as disconnected** is mended, stopping at the first step that works: a sync (enough when MainWP Child was switched off and is on again); MainWP's own reconnect; the site's other administrators on record, when the care account is gone: the dashboard is pointed at each in turn and synced, and once one connects, the care account is made again under its own login with the Care Account Email, and the dashboard is pointed at it; then, once per disconnection, the stored login: the module logs in to the site, switches MainWP Child on again and syncs. When that does not mend it and the stored login is of no use (refused, not an administrator, a captcha, two-factor login, the login page gone, or none stored), the site becomes Failed and the client is asked to connect again; anything else may pass by itself, so the site stays Connected and is looked at again the next day. When the client then connects again, the dashboard's own site is pointed at the new administrator and synced; no second site is added. A site that does not answer in time is reported on its own line and does not hold up the others.
- **A site staff connected by hand** on the dashboard, under the client and at the same address as a care site that still waits to be connected, is taken over, when exactly one waiting care site matches.

Everything else it finds is reported, not corrected: nothing is suspended, resumed or removed by the check.

- billed and not connected (waiting for the client, or failed with the reason and where the retries stand);
- a care add-on whose hosting service has no domain;
- a site that a second care add-on is also for;
- suspended, ended or not active in WHMCS while the dashboard still holds the site unsuspended, and active in WHMCS while the dashboard holds it suspended (judged by the dashboard's own row);
- a care site whose add-on has ended, is gone from WHMCS or no longer names MainWP for WHMCS on its Module Settings, and that was not removed;
- a site the dashboard no longer holds. A site that was connected goes on the retry list when a login is stored, and is added again; without one the client is asked to connect again. When none of the module's sites is seen on the dashboard, and several are not found or one is found there at another address, the dashboard address or the API key is taken to be wrong: that is reported as a dashboard fault and nothing is changed;
- a dashboard site that is at another address than the care site it is on record for (it is not treated as that care site's);
- a site under a dashboard client the module made (its note says "WHMCS client" and the client's number) that no care add-on covers; sites under no client, or under a client made by hand on the dashboard, are left out;
- a key that could not be deleted on a site for a week, and a service event that did not say which service it was for.

When the dashboard cannot be read at the start (unreachable, key refused, not MainWP's answer), nothing is acted on: the report says so and lists what the module's own records show. In dry run the dashboard and the sites are not called at all, and the check reports from its own records only.

**Failed commands.** When a module command of a care add-on fails (Suspend, Unsuspend or Terminate, say), WHMCS keeps the add-on's status and holds the command in its Module Queue. The check lists each such command that is still open, whatever its action word, with the error and how often it was tried, first on its list; it reads the Module Queue and never changes it. Suspend, Unsuspend and Terminate are named by WHMCS's button words; any other command by the word WHMCS stored (its first 40 characters). A failed Create is not listed: the daily check provisions the add-on once it is billed. The Sites tab shows a failed command beside the site's other finding.

**Free care add-ons.** A care add-on that is Active in WHMCS and set to free (the billing cycle "Free Account", or a recurring amount of 0.00) is listed after the failed commands, with the care site's state (or "Active in WHMCS" when it has no care site or a removed one) and the words "set to free in WHMCS". It is provisioned and cared for as any other.

**The daily digest.** After a run from the cron the module emails what it found, as the Daily Digest setting says: Off, When something is new (the default) or Every day. When something is new, it is sent when the run has a line that the cron run before it did not have; the same list on the next day is not sent again. Every day, it is sent after every cron run, and a quiet night says "nothing to report". While it is Off, the cron runs still count what they find: when it is switched on again, it sends what is new from then on. Never more than one digest goes out on one day: a second cron run that day sends nothing, and when it had something to send it logs why. A run from the page's button sends no email and does not count as the run before; so is what a digest that could not be sent would have told still news for the next cron run. The first cron run after the mode is changed counts every line as new. In dry run the digest is sent too, marked as such. Each line about an add-on names it; under New and under Still open the failed commands come first, then the free care add-ons. When a failed command is listed, the digest ends with a link to WHMCS's Module Queue. WHMCS documents no way for a module to send to an address of its own choosing, so the digest goes out with WHMCS's `SendAdminEmail`, type "system", to the administrators whose role receives system emails; the Settings tab names them.

The last result is kept in one settings row, which holds at most 100 lines, what is new first; the page and the email say how many more were found. With more than 3,000 lines at once, the next run can no longer tell which are new and counts none as new.

## The admin page

Addons > MainWP for WHMCS (`addonmodules.php?module=mainwp`) has three tabs, each with its own address: Sites (`&tab=sites`), Settings (`&tab=settings`) and Log (`&tab=log`). Only the admin roles ticked under Configure on System Settings > Addon Modules may open it. In dry run every tab says so and links to the settings.

The Sites and Log tabs are lists as WHMCS's own are: a Search/Filter strip, "N Records Found, Page X of Y" with Jump to Page, headings that sort, and page links, with as many records to a page as WHMCS shows on its own lists (General Settings, "Records to Display per Page"). Every view of a list is an address (`&search=`, `&status=` or `&outcome=`, `&orderby=`, `&order=`, `&page=`).

**Sites** lists every care site, each billed add-on that has no care site yet, and each site the last check found on the dashboard under a dashboard client the module made without a care add-on, with what has an issue first. A site's status is the module's state by its own name (Provisioned, Connecting, Connected, Failed, Suspended, Removed), "Not provisioned" for a billed add-on with no care site yet, and MainWP's word "Disconnected" for a connected site the dashboard reported as disconnected at the last check; the key under the list says what each means. The site links to its service in WHMCS and the client to their summary. Connect opens the Connect Site form for a site that waits to be connected: the one place staff connect a site. "Open in MainWP" opens the site on the dashboard. "Check Now" runs the daily check once more and comes back to the list as it was shown; "Open MainWP" opens the dashboard. A check made in dry run is marked "(dry run)" beside its time.

**Settings** holds the settings below, with Save Changes and Test Connection side by side: both save the form and then test the saved address and key against the dashboard, in either mode, so what is tested is always what the form shows. The test reads two numbers (the dashboard's clients and sites) and changes nothing. The tab also lists the care add-ons and names the administrators who receive the daily digest. Without a Care Account Email it warns that no care account can be made.

**Log** lists the module's log, newest first, with the outcome of each entry. Under it, Log Retention is a whole number of months, and "Clear Log" asks once and then deletes every entry but one that says who cleared it.

**The staff panel.** On the client's service page in the admin area, each care add-on shows its status (with "Dry run" in dry run), the site's address, the last sync, the issue the last daily check found, the next retry, the last connect attempt, and a link to the Connect Site form while the site waits.

**The client's panel.** In the sidebar of the service's page in the client area (`ClientAreaPrimarySidebar`), between WHMCS's "Service Details Overview" and "Service Details Actions" panels when the theme has them, a panel headed with the care add-on's name shows each care add-on's address and state in plain words, with "Connect your site" while the site waits. It is shown to the account's owner, to a user with the "manageproducts" permission on the account, and to an admin logged in as the client. In dry run no client sees the panel or the connect page.

## Settings in detail

The settings are stored where WHMCS keeps add-on module settings (`tbladdonmodules`, module `mainwp`). WHMCS deletes them when the module is deactivated, so they are entered again after it is activated again. The Configure form under System Settings > Addon Modules holds only the access list and "Delete module data on deactivate".

- **Mode**: Dry Run (log what would be done, send nothing) or Live. A fresh install starts in Dry Run. Live cannot be saved without an API key and a Care Account Email. Dry run is for staff: no client sees the connect prompt or page.
- **MainWP Dashboard URL**: the https:// address of the MainWP dashboard (MainWP accepts API keys only over HTTPS, and the module refuses an http:// address itself). The module knows its sites on the dashboard by the numbers that dashboard gave them: do not point it at another dashboard while care sites are on record. Changing the address to another host asks for the API key again, so the saved key is never sent to another host.
- **API Key**: made on the dashboard under API Access, Add API Keys, with both permissions (Read, and Write & Delete), and copied there with the button beside "API key (Bearer token)" before it is saved: MainWP does not show it again. The form refuses anything that has not the form of a key (two runs of letters and digits joined by "=="). Stored encrypted with WHMCS's own encryption and never shown again: a saved key is a greyed field of asterisks marked "Saved", and Change opens an empty field for a new one. The open field is a plain text field, so what is pasted can be seen and no browser fills a password into it.
- **Care Account Email**: one email address, given to the care account on every site; use an address you receive mail at, because WordPress mails it (a password notice, for one). A site where another account already holds it gets the address with the care login as its plus tag, so the mailbox has to accept plus addresses. It is checked as an email address and stored in lower case.
- **Daily Digest**: when the daily check's email goes out: Off, When something is new, or Every day. It goes to the administrators whose role receives WHMCS's system emails (System Settings > Administrator Roles). Never more than one a day.
- **Log Retention** (on the Log tab): a whole number of months from 0 to 600. The cron's daily check deletes entries older than that, by calendar months, and logs how many went; 0, the default, keeps every entry.

## Security in detail

The module never logs or shows a password, an API key, a key a client approved or a token, and log text carries no file paths. Every request it makes goes over HTTPS with the certificate checked and no redirect followed, with a time limit, and reads at most 2 MB of any answer.

The logins the module keeps are stored encrypted with WHMCS's encryption and read back only by the module for a retry or a mend: a connected site's care account, until the site is removed; and the login a client or staff typed, only while a failed connect is retried (or its add-on is suspended), since a connect that works, or a site found connected on the dashboard, replaces it with the care account or deletes it. Anyone holding the WHMCS database and its configuration file can decrypt them, as with the passwords WHMCS itself stores for hosting services. A site's other administrators are kept by login name only, with no password, in the care site's record.

On the approve route the key travels in the address of the return request, as WordPress sends it. It is therefore in the WHMCS web server's access log and in the browser's history. The module uses it at once, deletes it on the site as the last step of a connection, deletes it on the site when it is not used, and answers the return with a redirect to an address without it, with no cache and no Referer. While a failed connection is retried (up to a week) the key is kept encrypted beside the token's hash; a key that could not be deleted on a connected site is tried again by the daily check and given up, and logged, after a week. A key is only ever sent to the site it was approved on.

One request at a time works on an add-on (a MySQL named lock), so a retry, a client's new attempt and staff cannot cross each other.

A care account stays on the client's site as an administrator after care ends; its password is deleted from WHMCS when the site is removed, but an older backup of the WHMCS database still holds it.

Client pages and staff forms check WHMCS's CSRF token on every POST; the client page's return address works without a session, from the one-time token alone.

## Data

- `mod_mainwp_sites`: one row per care add-on: the site's address, its state, its dashboard client and site numbers, retries, the last sync and error, the stored login (encrypted) and the other administrators' login names.
- `mod_mainwp_tokens`: the approvals: the token's hash, its expiry, and while a failed connection is retried the key, encrypted.
- `mod_mainwp_log`: the log: time, who, action, target, outcome, whether in dry run, and the detail in plain words.

Activation creates the three tables. Activation over tables kept from an earlier version runs the upgrade steps, and WHMCS runs them on an upgrade when System Settings > Addon Modules is opened; each change is recorded in WHMCS's activity log, and a step already applied is skipped. Deactivating keeps the tables unless "Delete module data on deactivate" is ticked under Configure first. Deactivating changes nothing on the dashboard or on any site.

## Development

```bash
composer install
composer test
composer stan
```

`modules/addons/mainwp/lib/Engine/` is the care engine: plain PHP with no WHMCS call, loadable on its own through `lib/Engine/Load.php`. `modules/servers/mainwp/mainwp.php` is the provisioning module: its metadata and four functions that hand WHMCS's call to `lib/Provisioning.php`, with nothing loaded at file level. Everything else in `lib/` is the WHMCS layer. Tests run the engine against fakes and the WHMCS layer against WHMCS stubs (`tests/Support/FakeWhmcs.php`) and an in-memory repository; PHPStan checks at the PHP 8.2 floor against `tools/whmcs-stubs.php`.

`tools/release.sh VERSION` builds the release zip and its checksum from the tag `vVERSION`. It refuses when the tag is missing, the working tree has uncommitted changes, `Module::VERSION` at the tag differs, `CHANGELOG.md` at the tag has no dated heading for the version, or a PHP file does not parse. The zip is the same on every machine. Pushing a tag `v*` to GitHub runs the same script and attaches the zip and its `.sha256` to a GitHub release.

`tests/integration/CapsuleRepositoryTest.php` runs `CapsuleRepository` against a real MySQL or MariaDB through Illuminate's Capsule Manager standing in for `WHMCS\Database\Capsule`; it is skipped unless the connection is given. Point it at an empty throwaway database whose name contains "test" (it refuses a database that already holds any table it creates, and drops what it created), with PHP's `pdo_mysql` loaded:

```bash
MAINWP_TEST_DB_HOST=127.0.0.1 MAINWP_TEST_DB_PORT=3306 MAINWP_TEST_DB_NAME=mainwp_test \
MAINWP_TEST_DB_USER=root MAINWP_TEST_DB_PASSWORD=... vendor/bin/phpunit --testsuite integration
```

A container is enough: `docker run -d --name mainwp-itest-db -p 127.0.0.1:3306:3306 -e MARIADB_ROOT_PASSWORD=... -e MARIADB_DATABASE=mainwp_test mariadb:11`.
