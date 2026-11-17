# MainWP for WHMCS

The language of the WHMCS module that puts each paying client's WordPress site on a MainWP dashboard and keeps it in step with billing. The host sells care in WHMCS under its own name; WHMCS bills, the module provisions and connects, the dashboard is where the care work is done, and the client's site is where it lands.

## Language

### The parts and their names

**MainWP for WHMCS**:
The module itself, in its two WHMCS parts with the one system name `mainwp`: the provisioning module that WHMCS calls to create, suspend, unsuspend and terminate care (`modules/servers/mainwp`), and the add-on module that carries its pages, settings, tables, hooks and daily check (`modules/addons/mainwp`). The software between WHMCS and the dashboard. Staff see this name in WHMCS's admin area (the Addons menu, its page, its emails and log lines); clients never see it.
_Avoid_: the service (that is what the host sells), the add-on (WHMCS has two kinds: see Care add-on), the plugin

**The service**:
The care a host sells: the host keeps a client's WordPress site maintained for a recurring fee. It is the host's care product add-on in WHMCS, under the host's own name, and that name is what a client reads in the client area. Where no single care add-on is at hand, the name of the only care add-on, or else "Website Care".
_Avoid_: the module, managed WordPress, MainWP (for what the client buys)

**White label**:
An optional MainWP Pro extension by which a host shows its own name in place of MainWP's for MainWP's plugin on a client's site. Without it the site lists the plugin as "MainWP Child".

**WHMCS**:
The billing system. It holds the clients, their hosting services, their care add-ons and their invoices, and it alone decides who is billed and sets every status; the module reads that, answers WHMCS's calls, and never changes a status, a price or a date itself.

**Care add-on**:
A care product add-on that a client holds on a hosting service in WHMCS: one site's care, billed by WHMCS. A product add-on counts as care when its Module Settings in WHMCS name MainWP for WHMCS, and by nothing else. One site has at most one care add-on.
_Avoid_: the add-on (alone), the module, subscription

**Hosting service**:
The WHMCS service a care add-on is attached to. It must have a domain, and that domain decides which site addresses the care add-on may connect: the domain, a subdomain of it, or a folder under it.

**Care site**:
The module's own record of one care add-on's site: its address, its state (Provisioned, Connecting, Connected, Suspended, Failed, Removed) and its place on the dashboard.
_Avoid_: site (alone, for the record), client site (for the record)

**Site**:
The client's WordPress site that care is for. It is the client's own: nothing but MainWP's own plugin and the care account is added to it.

**Dashboard**:
The host's MainWP dashboard that the module is connected to: where staff and MainWP's own automation do the care work. One dashboard serves one WHMCS.
_Avoid_: MainWP (alone, for the dashboard)

**Dashboard client**:
A client as the dashboard holds it, built by the module from the WHMCS client and marked with that client's WHMCS number. One per WHMCS client, whatever the number of sites.

**Dashboard site**:
A site as the dashboard holds it, known to the module by the number the dashboard gave it.

**MainWP Child**:
MainWP's own plugin on a client's site, through which the dashboard works on the site. A client reads "a plugin that lets us look after your site" for it, and on their own site either "MainWP Child" or, with the white label, the host's name: nothing the module shows a client names MainWP.
_Avoid_: the care plugin

### States and routes

**Status**:
What staff read for a site on the module's pages: the state by its own name, "Not provisioned" for a billed care add-on that has no care site yet, and MainWP's own word "Disconnected" for a connected site the dashboard reported as disconnected at the last daily check. Shown as one of WHMCS's own status labels; the key under the Sites list says what each means.
_Avoid_: state (for what a page shows), standing (how the add-on stands in WHMCS)

**Provisioned**:
Care is set up for the client's site and is being billed, and the module has built the client on the dashboard. The site itself is not on the dashboard yet: MainWP adds a site only by connecting to it.

**Connected**:
MainWP's plugin is installed and active on the site, the site is on the dashboard under its client, and the dashboard synchronises with it.
_Avoid_: provisioned (for this state), added, linked

**Default plugins**:
MainWP's own plugins exactly as MainWP ships them. The module puts nothing written or modified by its makers on a client's site.
_Avoid_: custom plugin, fork

**Connecting**:
A connect attempt is under way or waiting for the client's approval on their own site. It ends with the approval: Failed with the reason when the approval lapses or is declined, and on the retry list when a connection was cut off halfway.

**Suspended**:
The site is on the dashboard with MainWP's suspended flag: its automatic updates and uptime checks stop, and nothing changes on the site. A site that never reached the dashboard is not Suspended when care is: it keeps waiting to be connected, and nothing connects it until care is active again.

**Removed**:
The module took the site off the dashboard; MainWP switched its plugin off on the site and left the files (a site MainWP has flagged as staging it removes as it is). The stored login and anything left of a retry go with it.

**Failed**:
The last connect attempt failed; the reason is stored in plain words with whether a retry is possible.

**Billed**:
A care add-on that is Active on an Active hosting service and has no unpaid invoice. Read from WHMCS each time, never stored.

**Free care add-on**:
A care add-on that WHMCS charges nothing for: its billing cycle is "Free Account", or its recurring amount is nothing. It is cared for as any other, and while it is Active the daily digest names it, so that staff see care that brings in nothing.
_Avoid_: unbilled, free care (for the service), complimentary

**Care account**:
The administrator account the dashboard connects as, one per connected site, made by the module on either route (or by the host on the server), so that nobody's own login is kept once a site is connected. Its login begins "wpcare" (for "WordPress care"), by which the module knows its own accounts; its email is the Care Account Email, the same on every site, or that address with the care login as its plus tag where another account already holds it. It stays on the site after removal; one made on the key route for a connection that never succeeded is removed again.
_Avoid_: service account

**Other administrators**:
The logins of the site's other administrators (not a care account), as MainWP listed them when the site was connected. The dashboard is pointed at one of them when the care account is gone. Names only; no password.

**Password route**:
Connecting a site with an administrator username and password typed by the client or staff: the module logs in once with WordPress's own login, installs MainWP's plugin and logs out, the dashboard connects as that administrator, and the care account then takes its place. The login typed is kept only while a failed connect is retried; once the site is connected it is not the stored login. A care login typed (the host made the care account) is given to the dashboard without a login to the site.
Also called the username-and-password way in the README.
_Avoid_: credentials route, login route

**Retry list**:
The failed connects the daily check tries again, with the stored login or, on the key route, with the key held for it: a care site in Failed with a time after which it is retried. A retry is due by the date; seven are made, one a day, and a failure of the dashboard itself is not counted.

**Daily check**:
The run at the end of WHMCS's daily cron that sets every care add-on against the module's record and the dashboard: it provisions, retries, mends and takes over, and reports every other mismatch without correcting it.

**Daily digest**:
The email, at most one on any day, in which the module tells staff what about care needs a person after the daily check.
_Avoid_: report email, daily report, notification

**Standing**:
How a care add-on stands in WHMCS, as the daily check reads it: active (Active on an Active hosting service), suspended (the add-on or its service is Suspended), ended (Terminated, Cancelled or Fraud, or gone), or pending (anything else). Ended wins over suspended.

**Mend**:
To make the dashboard reach a connected site again after its connection broke, without the client: a sync, MainWP's own reconnect, another administrator on record (with the care account made again), then the stored login once per disconnection.
_Avoid_: reconnect (MainWP's own word for one of the steps)

**Key route**:
Connecting a site with a key the client approves on their own WordPress site: the client signs in there themselves, the module never sees their password, and the dashboard connects as the care account the module makes with the key.
Also called the approve route in the README (the client presses "Approve on my site").
_Avoid_: application-password route, approval route, OAuth

**Key**:
The application password WordPress makes when the client approves the connection on their own site. The module uses it at once and deletes it on the site; while a failed connection is retried it is held encrypted.
_Avoid_: token (the approval's one-time token), API key (the dashboard's)

**Approval**:
What the module waits for after it sent a client to their own site: a one-time token in the return address, of which only the hash is kept, for 24 hours. It lapses when its time runs out or a newer one replaces it; it is used when a key comes back on it.
