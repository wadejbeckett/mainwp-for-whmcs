<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;

/**
 * The care engine: the commands that act on the dashboard and on client sites, taking plain data.
 * It knows no billing system. In dry-run it logs what each command would do and touches neither the
 * dashboard, nor a site, nor a stored care site.
 */
final class CareEngine
{
    /**
     * The fixed id of this module on the client's own site, where they approve the connection
     * (WordPress requires a UUID). What they approve is named for the service (Storage::serviceName).
     */
    public const APP_ID = '7f1c2b8e-4d1a-4c47-9a53-2f6c0e8d1b10';

    /** How many of a site's other administrators are recorded. */
    private const OTHER_ADMINS = 10;

    /** How a care login typed reaches the site, for a dry run's "would" line. */
    private const ONLY_IF_REFUSED = ', logging in to the site to install MainWP\'s plugin only if the dashboard refuses';

    /** How long a client has to approve; a site stays Connecting no longer than this without them. */
    public const APPROVAL_HOURS = 24;

    /** How long a lapsed approval is still recognised, and a key that cannot be deleted is still tried. */
    private const KEEP_DAYS = 7;

    /** WordPress's application passwords are 24 letters and digits; it shows them in groups with spaces. */
    private const KEY_LENGTH = 24;

    /** The states from which a site can be connected, by either route. */
    private const CONNECTABLE = [State::Provisioned, State::Failed, State::Connecting];

    private const KEYS_OFF = 'Application passwords are switched off on your site (a security plugin or a setting does this), so the connection cannot be approved there. Switch them on, or connect with an administrator username and password instead.';
    private const KEYS_OFF_WORDFENCE = 'Wordfence has switched application passwords off on your site, so the connection cannot be approved there. Switch them on in Wordfence\'s options, or connect with an administrator username and password instead.';
    private const KEY_ROUTE_OFF = 'Approving the connection on your site is not available at the moment. Connect with an administrator username and password instead, or contact support.';
    private const NOT_APPROVED_IN_TIME = 'The connection was not approved on your site within 24 hours, so the link has lapsed. Start again when you are ready. If your site\'s login page has its own address, sign in to your site first and then start again.';
    private const NOT_APPROVED = 'The connection was not approved on your site, so nothing was changed there. You can start again at any time.';
    private const APPROVAL_DISABLED = 'Your site did not let this account approve the connection: application passwords are switched off for the account, or a separate HTTP login sits in front of the site\'s dashboard. Approve with another administrator account, or connect with an administrator username and password instead.';
    private const CUT_OFF = 'The connection was interrupted before it finished.';
    /** The end of a stored reason that the retry list will work on; a page that adds its own words about retrying leaves them out after it. */
    public const RETRY_PROMISE = ' It is tried again by itself.';
    private const INTERRUPTED = self::CUT_OFF . self::RETRY_PROMISE;
    private const OUR_SIDE = 'We could not finish connecting your site because of a fault on our side.' . self::RETRY_PROMISE;
    private const SITE_TOO_SLOW = 'Your site did not answer in time.' . self::RETRY_PROMISE;
    private const EARLIER_CONNECTION = 'Your site still holds an earlier care connection, which has to be cleared before it can be connected again. Please contact support.';
    /**
     * What the client reads in place of a reason whose words name MainWP (the dashboard's own words
     * about the site, or a reason stored by a version before 0.5.0): the client knows the service,
     * not the tool behind it. Staff read the words in the log. The client's panel shows it as it is.
     */
    public const NOT_CONNECTED = 'Your site could not be connected.';
    /** How the reason begins for a site that was connected and whose connection then broke or was lost: no connect of the client's failed. */
    public const NO_LONGER_CONNECTED = 'Your site is no longer connected to care.';
    private const CONNECT_AGAIN = ' Connect it again.';
    /** Failures of a login to the site after which trying the stored login again is pointless: the client has to connect anew. */
    private const LOGIN_OF_NO_USE = [SiteFailure::LOGIN_REFUSED, SiteFailure::NOT_ADMIN, SiteFailure::CAPTCHA, SiteFailure::TWO_FACTOR, SiteFailure::LOGIN_NOT_FOUND];
    /** Why a care add-on on a hosting service without a domain is not provisioned. */
    public const NO_DOMAIN = 'its hosting service has no domain, so no site can be connected';
    private const BUSY = 'Your site is being connected right now. Try again in a minute.';

    /** Seconds a command that WHMCS's own events drive waits for another request to finish with the add-on, and a return for its turn. */
    private const EVENT_WAIT = 20;
    private const RETURN_WAIT = 10;
    /** Seconds a provision waits for another request that is setting up the same client on the dashboard. */
    private const CLIENT_WAIT = 10;

    private readonly Secrets $secrets;

    /**
     * @param string $careEmail the email address the care account on every site is given (the Care
     *                          Account Email); without it no care account can be made and the key route is not offered
     */
    public function __construct(
        private readonly Dashboard $dashboard,
        private readonly Site $site,
        private readonly Storage $storage,
        private readonly Clock $clock,
        private readonly bool $dryRun,
        ?Secrets $secrets = null,
        private readonly string $careEmail = '',
    ) {
        $this->secrets = $secrets ?? new RandomSecrets();
    }

    /**
     * Puts the client on the dashboard (found by the note "WHMCS client N", or created) and records
     * the care site as Provisioned at $siteUrl. An add-on whose hosting service has no domain ($siteUrl
     * empty) is not provisioned and waits: no site could ever be connected at no address.
     */
    public function provision(int $addonRowId, int $serviceId, Client $client, string $siteUrl, string $actor): Outcome
    {
        if (trim($siteUrl) === '') {
            $existing = $this->storage->site($addonRowId);
            if ($existing !== null && $existing->state !== State::Removed) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is already provisioned.');
            }
            $this->log($actor, 'provision', 'add-on ' . $addonRowId, 'waiting', 'not provisioned: ' . self::NO_DOMAIN);
            return new Outcome(false, 'Add-on ' . $addonRowId . ' is not provisioned: ' . self::NO_DOMAIN . '.');
        }
        $plan = 'build WHMCS client ' . $client->id . ' on the dashboard and record ' . $siteUrl . ' as Provisioned';
        if ($this->dryRun) {
            return $this->wouldRun('provision', $addonRowId, $plan, $actor);
        }
        $existing = $this->storage->site($addonRowId);
        if ($existing !== null && $existing->state !== State::Removed) {
            return new Outcome(true, 'Add-on ' . $addonRowId . ' is already provisioned.');
        }
        // One provision per WHMCS client at a time: two requests for one new client would each create it on the dashboard.
        if (!$this->storage->lockClient($client->id, self::CLIENT_WAIT)) {
            $this->log($actor, 'provision', 'add-on ' . $addonRowId, 'failed', 'another request was setting up WHMCS client ' . $client->id . ' on the dashboard; nothing was done; it is tried again at the next daily check');
            return new Outcome(false, 'Add-on ' . $addonRowId . ' could not be provisioned: another request is setting up the same client. It is tried again at the next daily check.');
        }
        try {
            // Read again: another request may have provisioned the add-on while this one waited its turn.
            $existing = $this->storage->site($addonRowId);
            if ($existing !== null && $existing->state !== State::Removed) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is already provisioned.');
            }
            return $this->attempt('provision', $addonRowId, $actor, 'could not be provisioned', function () use ($addonRowId, $serviceId, $client, $siteUrl, $actor): Outcome {
                $byEmail = false;
                $dashboardClientId = $this->dashboard->findClient($client->id);
                if ($dashboardClientId === null) {
                    try {
                        $dashboardClientId = $this->dashboard->createClient($client->id, $client->name, $client->email);
                    } catch (DashboardFailure $e) {
                        if ($e->kind !== DashboardFailure::REFUSED) {
                            throw $e;
                        }
                        $dashboardClientId = $this->clientByEmail($client, $e);
                        $byEmail = true;
                    }
                }
                $this->storage->saveSite(new CareSite($addonRowId, $serviceId, $client->id, $siteUrl, State::Provisioned, $dashboardClientId));
                $this->log($actor, 'provision', 'add-on ' . $addonRowId, 'done', 'dashboard client ' . $dashboardClientId . ($byEmail ? ' (found by its email; its note was not changed)' : '') . ', ' . $siteUrl . ' Provisioned');
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is provisioned.');
            });
        } finally {
            $this->storage->unlockClient($client->id);
        }
    }

    /**
     * The dashboard refused to create the client: a client made by hand may already have its email.
     * The one dashboard client with the same email (trimmed, letter case ignored) whose note names
     * no WHMCS client, or this same one, is taken as it is; its note is not changed. Any other finding
     * keeps the failure, with what staff can do and without the email address. MainWP's own words
     * are not relied on: any refusal leads here, and with no client of that email it stands as it is.
     */
    private function clientByEmail(Client $client, DashboardFailure $refusal): int
    {
        $email = strtolower(trim($client->email));
        if ($email === '') {
            throw $refusal;
        }
        try {
            $all = $this->dashboard->clients();
        } catch (\Throwable) {
            throw $refusal;
        }
        $same = array_values(array_filter($all, static fn (DashboardClient $c): bool => strtolower(trim($c->email)) === $email));
        if ($same === []) {
            throw $refusal;
        }
        $note = 'Write "WHMCS client ' . $client->id . '" in the note of the right dashboard client';
        $again = '; it is tried again at the next daily check.';
        if (count($same) > 1) {
            $ids = array_map(static fn (DashboardClient $c): string => (string) $c->id, $same);
            $last = array_pop($ids);
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard already has ' . count($same) . ' clients with this customer\'s email (dashboard clients ' . implode(', ', $ids) . ' and ' . $last . '). ' . $note . ', or change the email of the others there' . $again);
        }
        $found = $same[0];
        if ($found->billingClientId !== null && $found->billingClientId !== $client->id) {
            throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard already has a client with this customer\'s email (dashboard client ' . $found->id . ', whose note names WHMCS client ' . $found->billingClientId . '). ' . $note . ', or change its email there' . $again);
        }
        // The note is not written, so the same dashboard client would pass again for another WHMCS client with the same email.
        foreach ($this->storage->sites() as $other) {
            if ($other->state !== State::Removed && $other->dashboardClientId === $found->id && $other->clientId !== $client->id) {
                throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard already has a client with this customer\'s email (dashboard client ' . $found->id . '), and care add-on ' . $other->addonRowId . ' of WHMCS client ' . $other->clientId . ' is filed under it. ' . $note . ', or change its email there' . $again);
            }
        }
        return $found->id;
    }

    /**
     * Connects a site as $login on the password route: the site logs in once, installs and activates
     * MainWP's plugin and logs out; the dashboard connects the site as that administrator under the
     * client. The dashboard then makes the care account on the site and is pointed at it; the care
     * account becomes the stored login and the login typed is deleted, also when the care account
     * could not be made. A care login typed (the host made the care account on the site) is given to
     * the dashboard first, with no login to the site. While the connect runs, and while a failed one
     * is retried, the login typed is kept encrypted. A failure leaves the care site
     * Failed with the reason in plain words and puts it on the retry list. An approval the client was sent to
     * give lapses, and what an earlier key attempt left on the site is removed first.
     * $siteUrl, when given, is the address typed for the site; the caller has checked it with SiteAddress.
     * $carried is the note of an attempt the caller made just before for the same request (at an address
     * that only redirected): what that attempt could not remove on the site, so that it is not lost.
     */
    public function connect(int $addonRowId, Login $login, string $actor, ?string $siteUrl = null, string $carried = ''): Outcome
    {
        $site = $this->storage->site($addonRowId);
        $refusal = self::notConnectable($site, $addonRowId);
        if ($site === null || $refusal !== null) {
            return new Outcome(false, (string) $refusal);
        }
        $plan = Secrets::isCareLogin($login->username)
            ? 'connect ' . ($siteUrl ?? $site->siteUrl) . ' to the dashboard as ' . $login->username . self::ONLY_IF_REFUSED
            : 'install MainWP\'s plugin on ' . ($siteUrl ?? $site->siteUrl) . ' as ' . $login->username . ' and connect it to the dashboard' . $this->careStep($login);
        if ($this->dryRun) {
            return $this->wouldRun('connect', $addonRowId, $plan, $actor);
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $login, $actor, $siteUrl, $carried): Outcome {
            // Read again: another request may have finished with the site while this one waited its turn.
            $stored = $this->storage->site($addonRowId);
            $refusal = self::notConnectable($stored, $addonRowId);
            if ($stored === null || $refusal !== null) {
                return new Outcome(false, (string) $refusal);
            }
            if ($this->finishedByTheDashboard($stored, $actor)) {
                $typed = $siteUrl !== null ? $stored->at($siteUrl) : $stored;
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is connected.', trim(($typed->siteUrl !== $stored->siteUrl ? self::alreadyConnected($stored, $typed) : '') . ' ' . trim($carried)));
            }
            // What the key route left is removed at the address the key was made for, never at a newly typed one.
            $left = ($carried !== '' ? ' ' . trim($carried) : '') . $this->leaveKeyRoute($stored, $actor);
            $site = ($siteUrl !== null ? $stored->at($siteUrl) : $stored)->inFlight($this->tomorrow());
            $this->storage->saveSite($site);
            $this->storage->saveLogin($addonRowId, $login);
            return $this->runConnect($site, $login, $actor, false, $left);
        }, static fn (): Outcome => new Outcome(false, self::busy($addonRowId)));
    }

    /**
     * One retry of a failed connect, as the daily check runs it; the attempt is counted, unless it
     * fails because of the dashboard itself. On the key route it runs with the key held for it,
     * otherwise with the stored login. How many retries are made is the daily
     * check's, and endRetries() ends them. $dueOn, a date (Y-m-d), makes the retry run only when it
     * is due on that day; the date counts, not the time, because the daily run does not reach a
     * site at the same second each night.
     */
    public function retryConnect(int $addonRowId, string $actor, ?string $dueOn = null): Outcome
    {
        $nothing = new Outcome(false, 'Add-on ' . $addonRowId . ' has no failed connect to retry.');
        $noLogin = new Outcome(false, 'Add-on ' . $addonRowId . ' has no stored login to retry with.');
        $site = $this->storage->site($addonRowId);
        if ($site === null || $site->state !== State::Failed || $site->dashboardClientId === null) {
            return $nothing;
        }
        if ($this->dryRun) {
            $login = $this->storage->heldKey($addonRowId) === null ? $this->storage->login($addonRowId) : null;
            if ($login === null && $this->storage->heldKey($addonRowId) === null) {
                return $noLogin;
            }
            return $this->wouldRun('connect', $addonRowId, 'retry connecting ' . $site->siteUrl . ($login === null ? ' with the approved key' : (Secrets::isCareLogin($login->username) ? ' to the dashboard as ' . $login->username . ' with the stored login' . self::ONLY_IF_REFUSED : ' as ' . $login->username . ' with the stored login' . $this->careStep($login))), $actor);
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $actor, $nothing, $noLogin, $dueOn): Outcome {
            // Read again: a person's own attempt may have run while this retry waited its turn.
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->state !== State::Failed || $site->dashboardClientId === null) {
                return $nothing;
            }
            if ($dueOn !== null && !self::dueOn($site, $dueOn)) {
                // Another run made today's attempt while this one waited its turn.
                return new Outcome(false, 'Add-on ' . $addonRowId . ' has no retry due.');
            }
            $held = $this->storage->heldKey($addonRowId);
            $login = $held === null ? $this->storage->login($addonRowId) : null;
            if ($held === null && $login === null) {
                if ($site->retryAfter !== null) {
                    // On the retry list with nothing to retry with (a login that can no longer be read): off the list.
                    $this->storage->saveSite($site->failed(self::unretried($site->lastError) . ' What was kept for the retry can no longer be read, so it is not tried again: connect the site again.'));
                    $this->log($actor, 'connect', 'add-on ' . $addonRowId, 'ended', 'nothing is left to retry ' . $site->siteUrl . ' with; the client is prompted again');
                }
                return $noLogin;
            }
            $site = $site->retried();
            $this->storage->saveSite($site);
            if ($held !== null) {
                return $this->keyConnect($site, $held, $actor, true);
            }
            return $this->runConnect($site, $login, $actor, true);
        }, static fn (): Outcome => new Outcome(false, self::busy($addonRowId)));
    }

    /**
     * Ends the retries of a failed connect: what a key attempt left on the site (the care account,
     * the key) is removed there, the stored login is deleted, and the site stays Failed with its
     * reason and nothing to retry, so the client is prompted again. What could not be removed on the
     * site is named in the reason.
     */
    public function endRetries(int $addonRowId, string $actor): Outcome
    {
        $site = $this->storage->site($addonRowId);
        if ($site === null || $site->state !== State::Failed) {
            return new Outcome(false, 'Add-on ' . $addonRowId . ' has no failed connect to end.');
        }
        if ($this->dryRun) {
            return $this->wouldRun('connect', $addonRowId, 'stop retrying ' . $site->siteUrl . ', remove what the attempts left on the site and delete the stored login', $actor);
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $actor): Outcome {
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->state !== State::Failed) {
                return new Outcome(false, 'Add-on ' . $addonRowId . ' has no failed connect to end.');
            }
            if ($this->finishedByTheDashboard($site, $actor)) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is connected.');
            }
            $left = $this->leaveKeyRoute($site, $actor);
            $this->storage->deleteLogin($addonRowId);
            $this->storage->saveSite($site->failed(self::unretried($site->lastError) . $left));
            $this->log($actor, 'connect', 'add-on ' . $addonRowId, 'ended', 'no more retries for ' . $site->siteUrl . '; the stored login and the key are deleted' . ($left !== '' ? ', but not everything could be removed on the site' : ''));
            return new Outcome(true, 'Add-on ' . $addonRowId . ' is no longer retried.', trim($left));
        }, static fn (): Outcome => new Outcome(false, self::busy($addonRowId)));
    }

    /**
     * The password route's work, and the care account once the site is connected (a retry that
     * finds the site already on the dashboard makes it too). A failure leaves the care site Failed with the reason and on the
     * retry list; an address that only redirects to another host, or a plain http:// one, is Failed
     * without a retry, since the same address cannot work tomorrow either, and the login given for it is not kept.
     *
     * @param string $left what the key route left on the site that could not be removed, for the client
     */
    private function runConnect(CareSite $site, Login $login, string $actor, bool $retry, string $left = ''): Outcome
    {
        $id = $site->addonRowId;
        $retryAfter = $this->tomorrow();
        $connectAs = $login;
        $hostMade = Secrets::isCareLogin($login->username);
        $answerLate = false;
        try {
            $siteId = $retry ? $this->adopt($site) : null;
            $adopted = $siteId !== null;
            if ($siteId === null && $hostMade) {
                $siteId = $this->holdAsCareAccount($site, $login, $answerLate);
            } elseif ($siteId === null) {
                $name = $this->site->installPlugin($site->siteUrl, $login);
                $connectAs = $name === $login->username ? $login : new Login($name, $login->password);
                if ($connectAs !== $login) {
                    $this->storage->saveLogin($id, $connectAs);
                }
                $siteId = $this->holdOnDashboard($site, $connectAs, $answerLate);
            }
        } catch (\Throwable $e) {
            $slow = $this->siteTimedOut($e);
            $reason = $slow ? self::SITE_TOO_SLOW : self::clientReason($e, $site->siteUrl, $login->password);
            $movedTo = $e instanceof SiteFailure && $e->kind === SiteFailure::MOVED ? $e->movedTo : null;
            $forClient = $left !== '' ? '. For the client:' . $left : '';
            $fault = !$slow && self::isDashboardFault($e);
            if ($e instanceof SiteFailure && ($e->kind === SiteFailure::MOVED || $e->kind === SiteFailure::NOT_HTTPS)) {
                // The login was not sent to an address that only redirects, or to a plain http:// one, and nothing will retry it there: it is not kept.
                $this->storage->deleteLogin($id);
                $this->storage->saveSite($site->failed($reason . $left));
                $this->log($actor, 'connect', 'add-on ' . $id, 'failed', self::why($e, $login->password) . ' (not retried, and the login is not kept)' . $forClient);
            } elseif ($e instanceof DashboardFailure && $e->kind === DashboardFailure::EARLIER_CONNECTION) {
                // No retry can clear what MainWP's plugin holds on the site.
                $this->storage->saveSite($site->failed($reason . $left));
                $this->log($actor, 'connect', 'add-on ' . $id, 'failed', self::why($e, $login->password) . ' (not retried)' . $forClient);
            } else {
                $this->storage->saveSite(($retry && $fault ? $site->uncounted() : $site)->failedWithRetry($reason . $left, $retryAfter));
                $this->log($actor, 'connect', 'add-on ' . $id, 'failed', self::why($e, $login->password) . ' (on the retry list: retry after ' . $retryAfter . ($retry && $fault ? '; not counted as a retry' : '') . ')' . $forClient);
            }
            return new Outcome(false, 'Add-on ' . $id . ' could not be connected: ' . $reason, trim($left), true, $movedTo, $fault);
        }
        if ($hostMade) {
            $how = ' with the care account ' . $login->username;
        } else {
            // The login typed connected the site; the care account takes its place, and the login typed is not kept.
            $care = $this->careEmail === '' ? null : $this->giveCareAccount($site, $siteId, $connectAs->username);
            if ($care instanceof Login) {
                $how = ' with the care account ' . $care->username;
            } else {
                $this->storage->deleteLogin($id);
                $how = ' as ' . $connectAs->username . ($care === null ? '; no care account was made because no Care Account Email is set' : '; the care account could not be made: ' . $care);
            }
        }
        $this->storage->saveSite($this->recordOtherAdmins($site->connectedAs($siteId, $this->now()), $hostMade ? [] : [$connectAs->username]));
        $this->log($actor, 'connect', 'add-on ' . $id, 'done', $site->siteUrl . ' connected as dashboard site ' . $siteId . $how . ($adopted ? ' (the dashboard already held it)' : '') . ($answerLate ? ' (the dashboard\'s answer did not come in time; it holds the site)' : '') . ($left !== '' ? '. For the client:' . $left : ''));
        return new Outcome(true, 'Add-on ' . $id . ' is connected.', trim($left), true);
    }

    /**
     * Which route to offer for the add-on's site (or for $siteUrl, an address typed for it): the key
     * route when the site's public REST index shows an approval screen on the site itself, otherwise
     * the password route with the reason in plain words. No login is used. In dry-run the site is
     * not asked at all: the key route is offered wherever the module's own settings allow it.
     */
    public function routeOffer(int $addonRowId, ?string $siteUrl = null): RouteOffer
    {
        $url = $siteUrl ?? $this->storage->site($addonRowId)?->siteUrl;
        if ($this->careEmail === '' || $url === null || $url === '') {
            return new RouteOffer(false, self::KEY_ROUTE_OFF);
        }
        if ($this->dryRun) {
            return new RouteOffer(true, '');
        }
        try {
            $index = $this->site->index($url);
        } catch (\Throwable $e) {
            // Only the site's own sentence is the client's to read, as for a failed connect (clientReason).
            $reason = LogText::clean($e->getMessage());
            return new RouteOffer(false, $e instanceof SiteFailure && !self::namesTheTool($reason, $url) ? $reason : self::KEY_ROUTE_OFF, false, null, $e instanceof SiteFailure ? $e->movedTo : null);
        }
        $elsewhere = $index->answersElsewhere($url);
        if ($elsewhere !== null) {
            // A folder with no WordPress of its own reads the main site's index: neither route is for this address.
            return new RouteOffer(false, 'The WordPress site at ' . $url . ' answers at ' . $elsewhere . '.', $index->wordfence, null, null, $elsewhere);
        }
        if ($index->approvalUrl === null) {
            return new RouteOffer(false, $index->wordfence ? self::KEYS_OFF_WORDFENCE : self::KEYS_OFF, $index->wordfence);
        }
        return new RouteOffer(true, '', $index->wordfence, $index->approvalUrl);
    }

    /**
     * The address the WordPress site at $url reports as its own when it is not $url (SiteIndex), read
     * from its public index with no login; null when it is, when the index names no address or cannot
     * be read (the connect then finds out what is wrong), and in dry-run, where the site is not asked.
     * When $url only sends visitors on to an https:// address under $domain (SiteAddress::movedWithin),
     * the index is read there once, so that a folder behind a redirect is refused before a connect
     * stores anything.
     */
    public function answersElsewhere(string $url, ?string $domain = null): ?string
    {
        if ($this->dryRun) {
            return null;
        }
        $at = $url;
        try {
            try {
                $index = $this->site->index($at);
            } catch (SiteFailure $e) {
                $canonical = $domain !== null && $e->movedTo !== null ? SiteAddress::movedWithin($e->movedTo, $url, $domain) : null;
                if ($canonical === null) {
                    return null;
                }
                $at = $canonical;
                $index = $this->site->index($at);
            }
            return $index->answersElsewhere($at);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The key route's first step: the client is sent to the approval screen of their own site, where
     * they sign in themselves and approve. The site returns their browser to $successUrl (or, when
     * they decline, to $rejectUrl); in both, "{token}" is replaced by a one-time token of which only
     * the hash is kept, for 24 hours. The care site is Connecting until the browser comes back or
     * the token lapses. An earlier approval of the add-on stops working, and what an earlier key
     * attempt left on the site is removed first.
     */
    public function startApproval(int $addonRowId, string $successUrl, string $rejectUrl, string $actor, ?string $siteUrl = null): ApprovalStart
    {
        $site = $this->storage->site($addonRowId);
        $refusal = self::notConnectable($site, $addonRowId);
        if ($site === null || $refusal !== null) {
            return new ApprovalStart(false, (string) $refusal);
        }
        foreach ([$successUrl, $rejectUrl] as $address) {
            if (!str_starts_with(strtolower($address), 'https://') || !str_contains($address, '{token}')) {
                return new ApprovalStart(false, 'The address the site sends the client back to must be an https:// address with the token in it: the key is never sent over plain HTTP. Nothing was sent.');
            }
        }
        if ($this->dryRun) {
            $plan = 'send the client to the approval screen of ' . ($siteUrl ?? $site->siteUrl) . ' and wait up to ' . self::APPROVAL_HOURS . ' hours for the key';
            $this->log($actor, 'approve', 'add-on ' . $addonRowId, 'would run', 'would ' . $plan);
            return new ApprovalStart(true, 'Dry run: would ' . $plan . '.');
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $successUrl, $rejectUrl, $actor, $siteUrl): ApprovalStart {
            $stored = $this->storage->site($addonRowId);
            $refusal = self::notConnectable($stored, $addonRowId);
            if ($stored === null || $refusal !== null) {
                return new ApprovalStart(false, (string) $refusal);
            }
            $site = $siteUrl !== null ? $stored->at($siteUrl) : $stored;
            $offer = $this->routeOffer($addonRowId, $site->siteUrl);
            if (!$offer->keyRoute || $offer->approvalUrl === null) {
                return new ApprovalStart(false, $offer->reason, null, '', $offer->movedTo, $offer->answersAt);
            }
            if ($this->finishedByTheDashboard($stored, $actor)) {
                return new ApprovalStart(true, self::alreadyConnected($stored, $site));
            }
            // What an earlier key attempt left is removed at the address that key was made for.
            $left = $this->leaveKeyRoute($stored, $actor);
            if ($site->siteUrl !== $stored->siteUrl) {
                // A login kept from an earlier password attempt was given for the old address: it is not kept for this one.
                $this->storage->deleteLogin($addonRowId);
            }
            $now = $this->now();
            $token = $this->secrets->token();
            $expires = $this->clock->now()->modify('+' . self::APPROVAL_HOURS . ' hours')->format('Y-m-d H:i:s');
            $this->storage->saveApproval(hash('sha256', $token), $addonRowId, $expires);
            $this->storage->saveSite($site->connecting());
            $this->log($actor, 'approve', 'add-on ' . $addonRowId, 'started', 'the client was sent to the approval screen of ' . $site->siteUrl . '; the link lapses at ' . $expires);
            // Each approval names its own key: WordPress versions from 5.7 on refused a second key of one name
            // for a user (7.1.4 no longer does), and a key left by an earlier approval must never block this one.
            $query = http_build_query([
                'app_name' => $this->storage->serviceName($addonRowId) . ' (' . $now . ')',
                'app_id' => self::APP_ID,
                'success_url' => str_replace('{token}', $token, $successUrl),
                'reject_url' => str_replace('{token}', $token, $rejectUrl),
            ], '', '&', PHP_QUERY_RFC3986);
            return new ApprovalStart(true, 'The client is sent to ' . $site->siteUrl . ' to approve the connection.', $offer->approvalUrl . (str_contains($offer->approvalUrl, '?') ? '&' : '?') . $query, trim($left));
        }, static fn (): ApprovalStart => new ApprovalStart(false, self::BUSY));
    }

    /**
     * The client's browser came back from their site with a key: the site's own address, the login
     * name of the account that approved, and the application password. The key is used only when
     * the token is known, unused and in time, the address is the one the approval was started for
     * (a key approved on any other site is never sent anywhere), and the site still waits to be
     * connected; $refuse, when given, is why the caller's billing system does not allow it. A key
     * that comes back and is not used is deleted on the site, so that none is left behind.
     */
    public function completeApproval(string $token, string $returnedSiteUrl, string $userLogin, string $key, string $actor, ?string $refuse = null): ApprovalReturn
    {
        $hash = hash('sha256', $token);
        $approval = $this->storage->approval($hash);
        $site = $approval === null ? null : $this->storage->site($approval->addonRowId);
        if ($approval === null || $site === null) {
            return new ApprovalReturn(ApprovalReturn::UNKNOWN, 'This link is not valid.', null, true);
        }
        $id = $site->addonRowId;
        $key = (string) preg_replace('/[^A-Za-z0-9]/', '', $key);
        $userLogin = trim($userLogin);
        if (strlen($key) !== self::KEY_LENGTH || $userLogin === '' || strlen($userLogin) > 60) {
            return new ApprovalReturn(ApprovalReturn::INVALID, 'What came back from the site is not a key the module can use.', $id);
        }
        if (!self::sameSite($returnedSiteUrl, $site->siteUrl)) {
            return $this->fromAnotherSite($site, $returnedSiteUrl, $actor);
        }
        $login = new Login($userLogin, $key);
        if ($this->dryRun) {
            $this->log($actor, 'approve', 'add-on ' . $id, 'would run', 'would use the key that came back to connect ' . $site->siteUrl . ' and then delete it on the site');
            return new ApprovalReturn(ApprovalReturn::DRY_RUN, 'Dry run: the key that came back was not used and is still on the site.', $id, true);
        }
        return $this->exclusively($id, function () use ($hash, $id, $returnedSiteUrl, $login, $actor, $refuse): ApprovalReturn {
            // Read again: this return may have waited while another request changed the site or its approvals.
            $approval = $this->storage->approval($hash);
            $site = $this->storage->site($id);
            if ($approval === null || $site === null) {
                return new ApprovalReturn(ApprovalReturn::UNKNOWN, 'This link is not valid.', null, true);
            }
            if (!self::sameSite($returnedSiteUrl, $site->siteUrl)) {
                return $this->fromAnotherSite($site, $returnedSiteUrl, $actor);
            }
            if ($approval->used) {
                return $this->usedBefore($approval, $site, $login, $actor);
            }
            $now = $this->now();
            $lapsed = $approval->lapsed($now);
            if ($refuse !== null || $lapsed || $site->state !== State::Connecting || $site->dashboardClientId === null) {
                // Care that is not active is said first: a lapsed link asks the client to start again, which they cannot do then.
                $why = $refuse !== null ? 'care is not active for the site' : ($lapsed ? 'its link had lapsed' : 'the site is not waiting to be connected');
                $left = !$this->discard($site, $login, $actor, $why);
                if ($lapsed && $site->state === State::Connecting) {
                    $this->expire($site, $actor);
                }
                return $refuse === null && $lapsed
                    ? new ApprovalReturn(ApprovalReturn::LAPSED, 'This link has lapsed.', $id, $left)
                    : new ApprovalReturn(ApprovalReturn::REFUSED, $refuse ?? 'This site is not waiting to be connected.', $id, $left);
            }
            $held = new HeldKey($login, new Login($this->secrets->careLogin(), $this->secrets->password()));
            if (!$this->storage->claimApproval($hash, $held, $now)) {
                // Another request brought a key on this link at the same moment and got it first.
                return $this->usedBefore($this->storage->approval($hash) ?? $approval, $site, $login, $actor);
            }
            $this->storage->deleteLogin($id);
            $site = $site->attempting();
            $this->storage->saveSite($site);
            $outcome = $this->keyConnect($site, $held, $actor, false);
            return new ApprovalReturn($outcome->ok ? ApprovalReturn::CONNECTED : ApprovalReturn::FAILED, $outcome->message, $id);
        }, function () use ($hash, $site, $login, $actor, $id): ApprovalReturn {
            // Still busy after the wait. When the request that is busy is this same return (a reload
            // while the first one still connects the site), its key is in use and is left alone.
            $claimed = $this->storage->approval($hash);
            if ($claimed !== null && $claimed->used && $claimed->keyHash !== null && hash_equals($claimed->keyHash, hash('sha256', $login->password))) {
                return new ApprovalReturn(ApprovalReturn::RUNNING, self::BUSY, $id);
            }
            // Otherwise the browser is sent on without the key, so the key is deleted rather than left.
            $left = !$this->discard($site, $login, $actor, 'another request was working on the site');
            return new ApprovalReturn(ApprovalReturn::BUSY, self::BUSY, $id, $left);
        }, self::RETURN_WAIT);
    }

    /** A key that came back from another site than the one the approval was started for: never used, never sent anywhere. */
    private function fromAnotherSite(CareSite $site, string $returnedSiteUrl, string $actor): ApprovalReturn
    {
        $this->log($actor, 'approve', 'add-on ' . $site->addonRowId, 'refused', 'a key came back from ' . self::host($returnedSiteUrl) . ', but the approval was started for ' . self::host($site->siteUrl) . '; it was not used and not sent anywhere');
        return new ApprovalReturn(ApprovalReturn::MISMATCH, 'The connection was approved on another site than the one this link was made for, so nothing was used.', $site->addonRowId);
    }

    /**
     * A key on a link that was used before. The same key again is the same return arriving twice
     * and changes nothing; any other key is a second one made on the site, and is deleted there.
     */
    private function usedBefore(Approval $approval, CareSite $site, Login $key, string $actor): ApprovalReturn
    {
        $again = $approval->keyHash !== null && hash_equals($approval->keyHash, hash('sha256', $key->password));
        return new ApprovalReturn(ApprovalReturn::USED, 'This link was already used.', $site->addonRowId, !$again && !$this->discard($site, $key, $actor, 'its link was already used'));
    }

    /**
     * The client's browser came back from their site without a key: they declined, or ($disabled)
     * the site would not let the account approve. A waiting site becomes Failed with the reason; a
     * link already used or lapsed changes nothing.
     */
    public function rejectApproval(string $token, string $actor, bool $disabled = false): ApprovalReturn
    {
        $hash = hash('sha256', $token);
        $approval = $this->storage->approval($hash);
        $site = $approval === null ? null : $this->storage->site($approval->addonRowId);
        if ($approval === null || $site === null) {
            return new ApprovalReturn(ApprovalReturn::UNKNOWN, 'This link is not valid.');
        }
        $id = $site->addonRowId;
        if ($this->dryRun) {
            return new ApprovalReturn(ApprovalReturn::DRY_RUN, 'Dry run: nothing was changed.', $id);
        }
        return $this->exclusively($id, function () use ($hash, $id, $disabled, $actor): ApprovalReturn {
            $approval = $this->storage->approval($hash);
            $site = $this->storage->site($id);
            if ($approval === null || $site === null) {
                return new ApprovalReturn(ApprovalReturn::UNKNOWN, 'This link is not valid.');
            }
            if ($approval->used) {
                return new ApprovalReturn(ApprovalReturn::USED, 'This link was already used.', $id);
            }
            if ($approval->lapsed($this->now()) || $site->state !== State::Connecting) {
                return new ApprovalReturn(ApprovalReturn::LAPSED, 'This link has lapsed.', $id);
            }
            $reason = $disabled ? self::APPROVAL_DISABLED : self::NOT_APPROVED;
            $this->storage->lapseApprovals($id, $this->now());
            $this->storage->saveSite($this->notConnected($site, $reason));
            $this->log($actor, 'approve', 'add-on ' . $id, 'rejected', $disabled ? 'the site did not let the account approve the connection' : 'the client declined on ' . $site->siteUrl);
            return new ApprovalReturn(ApprovalReturn::REJECTED, $reason, $id);
        }, static fn (): ApprovalReturn => new ApprovalReturn(ApprovalReturn::RUNNING, self::BUSY, $id), self::RETURN_WAIT);
    }

    /**
     * Ends a Connecting state that nothing will end by itself: the approval was not given in time
     * (Failed with the reason), or a connection was cut off halfway (Failed and on the retry list,
     * with the key or the login kept for it). True when the care site changed.
     */
    public function expireApproval(int $addonRowId, string $actor): bool
    {
        $site = $this->storage->site($addonRowId);
        if ($this->dryRun || $site === null || $site->state !== State::Connecting) {
            return false;
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $actor): bool {
            $site = $this->storage->site($addonRowId);
            return $site !== null && $site->state === State::Connecting && $this->expire($site, $actor);
        }, static fn (): bool => false);
    }

    /** expireApproval()'s work, for a Connecting site, while this request holds the add-on. */
    private function expire(CareSite $site, string $actor): bool
    {
        $id = $site->addonRowId;
        $now = $this->now();
        // Nothing else runs on the add-on now, so a key that was claimed, or a password attempt that
        // armed its retry before it started, belongs to a request that died.
        if ($this->storage->heldKey($id) !== null || $site->retryAfter !== null) {
            $retryAfter = $site->retryAfter ?? $this->tomorrow();
            $this->storage->saveSite($site->failedWithRetry(self::INTERRUPTED, $retryAfter));
            $this->log($actor, 'connect', 'add-on ' . $id, 'failed', 'the connection of ' . $site->siteUrl . ' was cut off before it finished (on the retry list: retry after ' . $retryAfter . ')');
            return true;
        }
        $latest = $this->storage->latestApproval($id);
        if ($latest !== null && !$latest->used && !$latest->lapsed($now)) {
            return false;
        }
        $this->storage->lapseApprovals($id, $now);
        $this->storage->saveSite($this->notConnected($site, self::NOT_APPROVED_IN_TIME));
        $this->log($actor, 'approve', 'add-on ' . $id, 'expired', 'no key came back from ' . $site->siteUrl . ' within ' . self::APPROVAL_HOURS . ' hours');
        return true;
    }

    /** expireApproval() for every Connecting site, and approvals that lapsed more than a week ago are forgotten. Returns how many sites changed. */
    public function expireApprovals(string $actor): int
    {
        if ($this->dryRun) {
            return 0;
        }
        $changed = 0;
        foreach ($this->storage->sites() as $site) {
            if ($site->state === State::Connecting && $this->expireApproval($site->addonRowId, $actor)) {
                $changed++;
            }
        }
        $this->storage->purgeApprovals($this->clock->now()->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s'));
        return $changed;
    }

    /**
     * Deletes, on the site, every key that is still held although its site is connected: the site
     * could not be reached when the connection was made. After a week the key is given up and the
     * log says the client must delete it. Returns how many keys were deleted.
     */
    public function tidyKeys(string $actor): int
    {
        if ($this->dryRun) {
            return 0;
        }
        $deleted = 0;
        foreach ($this->storage->sites() as $site) {
            if ($site->state === State::Failed || $site->state === State::Connecting) {
                continue; // the retry list and the approval own their key
            }
            $held = $this->storage->heldKey($site->addonRowId);
            if ($held === null) {
                continue;
            }
            $id = $site->addonRowId;
            $deleted += $this->exclusively($id, function () use ($id, $actor): int {
                // Read again: the site may have been removed, or its key dealt with, while the tidy waited its turn.
                $site = $this->storage->site($id);
                $held = $this->storage->heldKey($id);
                if ($site === null || $held === null || $site->state === State::Failed || $site->state === State::Connecting) {
                    return 0;
                }
                if ($this->deleteHeldKey($site, $held, $actor)) {
                    return 1;
                }
                $latest = $this->storage->latestApproval($site->addonRowId);
                if ($latest === null || $latest->expiresAt < $this->clock->now()->modify('-' . self::KEEP_DAYS . ' days')->format('Y-m-d H:i:s')) {
                    $this->storage->releaseKey($site->addonRowId);
                    $this->log($actor, 'key', 'add-on ' . $site->addonRowId, 'given up', 'the approved key could not be deleted on ' . $site->siteUrl . ' for a week; ask the client to delete the application password whose name begins "' . $this->storage->serviceName($site->addonRowId) . '" under Users, Profile, on the account ' . $held->key->username);
                }
                return 0;
            }, static fn (): int => 0);
        }
        return $deleted;
    }

    /**
     * Sets MainWP's suspended flag on the site: its automatic updates and uptime checks stop; nothing
     * changes on the site. A site that never reached the dashboard has nothing to suspend there:
     * what a connect has open for it ends instead (an approval not yet given, a key held for the
     * retries), so that neither outlives the suspension.
     */
    public function suspend(int $addonRowId, string $actor): Outcome
    {
        $ready = function () use ($addonRowId): Outcome|CareSite {
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->state === State::Removed) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' has no care site.');
            }
            if ($site->state === State::Suspended) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is already suspended.');
            }
            // Provisioned: nothing was ever sent anywhere for the site.
            return $site->state === State::Provisioned ? new Outcome(true, 'Add-on ' . $addonRowId . ' has no site on the dashboard to suspend.') : $site;
        };
        $site = $ready();
        if ($site instanceof Outcome) {
            return $site;
        }
        if ($this->dryRun) {
            return $this->wouldRun('suspend', $addonRowId, $site->dashboardSiteId !== null
                ? sprintf('suspend dashboard site %d (%s)', $site->dashboardSiteId, $site->siteUrl)
                : 'end what connecting ' . $site->siteUrl . ' has open (the site is not on the dashboard, as far as the module knows)', $actor);
        }
        return $this->exclusively($addonRowId, function () use ($ready, $addonRowId, $actor): Outcome {
            $site = $ready();
            if ($site instanceof Outcome) {
                return $site;
            }
            $siteId = $site->dashboardSiteId;
            if ($siteId === null) {
                // A connect was tried: the dashboard may hold the site although its answer never arrived.
                try {
                    $siteId = $this->adopt($site);
                } catch (\Throwable $e) {
                    $this->log($actor, 'suspend', 'add-on ' . $addonRowId, 'failed', 'the dashboard could not say whether it holds ' . $site->siteUrl . ': ' . self::why($e));
                    return new Outcome(false, 'Add-on ' . $addonRowId . ' could not be suspended: ' . LogText::clean($e->getMessage()));
                }
                if ($siteId === null) {
                    return $this->endOpenConnect($site, $actor);
                }
                $site = $this->recordAdopted($site, $siteId, $actor);
            }
            return $this->attempt('suspend', $addonRowId, $actor, 'could not be suspended', function () use ($site, $siteId, $actor): Outcome {
                $this->dashboard->suspendSite($siteId);
                // A site that was Failed (its connection broke) keeps its reason, so that a resume does not show it as connected.
                $this->storage->saveSite($site->withState(State::Suspended, $site->state === State::Failed ? $site->lastError : null));
                $this->log($actor, 'suspend', 'add-on ' . $site->addonRowId, 'done', 'dashboard site ' . $siteId . ' (' . $site->siteUrl . ') suspended');
                return new Outcome(true, 'Add-on ' . $site->addonRowId . ' is suspended.');
            });
        }, fn (): Outcome => $this->busyEvent('suspend', $addonRowId, $actor), self::EVENT_WAIT);
    }

    /**
     * Clears MainWP's suspended flag on a suspended site. A site that is not suspended is left
     * alone: the event that asks for this fires on every return to Active.
     */
    public function resume(int $addonRowId, string $actor): Outcome
    {
        $ready = function () use ($addonRowId): Outcome|CareSite {
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->state === State::Removed) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' has no care site.');
            }
            return $site->state !== State::Suspended || $site->dashboardSiteId === null ? new Outcome(true, 'Add-on ' . $addonRowId . ' is not suspended.') : $site;
        };
        $site = $ready();
        if ($site instanceof Outcome) {
            return $site;
        }
        if ($this->dryRun) {
            return $this->wouldRun('resume', $addonRowId, sprintf('resume dashboard site %d (%s)', (int) $site->dashboardSiteId, $site->siteUrl), $actor);
        }
        return $this->exclusively($addonRowId, function () use ($ready, $addonRowId, $actor): Outcome {
            $site = $ready();
            if ($site instanceof Outcome) {
                return $site;
            }
            $siteId = (int) $site->dashboardSiteId;
            return $this->attempt('resume', $addonRowId, $actor, 'could not be resumed', function () use ($site, $siteId, $actor): Outcome {
                $this->dashboard->resumeSite($siteId);
                $this->storage->saveSite($site->lastError !== null ? $site->failed($site->lastError) : $site->withState(State::Connected));
                $this->log($actor, 'resume', 'add-on ' . $site->addonRowId, 'done', 'dashboard site ' . $siteId . ' (' . $site->siteUrl . ') resumed');
                return new Outcome(true, 'Add-on ' . $site->addonRowId . ' is resumed.');
            });
        }, fn (): Outcome => $this->busyEvent('resume', $addonRowId, $actor), self::EVENT_WAIT);
    }

    /**
     * Mends a Connected site that the dashboard reports as disconnected, stopping at the first step
     * after which the dashboard reaches the site again (the order the bench's sixth run showed to
     * work): a sync, which is enough when MainWP's plugin was only switched off and is on again;
     * MainWP's own reconnect, which mends lost keys; the site's other administrators on record, when
     * the care account is gone (the dashboard pointed at each and synced, and the care account made
     * again once one connects); then, once per disconnection, the stored login: log in to the site,
     * switch MainWP's plugin on again, sync. When it is not mended and the
     * stored login is of no use (refused, not an administrator, a captcha, two-factor login, the
     * login page gone, or none stored) the site becomes Failed and the client is asked to connect
     * again. Anything else may pass by itself: the site stays Connected and is looked at again the
     * next day. What the dashboard says about the fault is for staff, in the log and the outcome.
     */
    public function mend(int $addonRowId, string $actor): Outcome
    {
        $ready = function () use ($addonRowId): Outcome|CareSite {
            $site = $this->storage->site($addonRowId);
            return $site !== null && $site->state === State::Connected && $site->dashboardSiteId !== null ? $site : new Outcome(true, 'Add-on ' . $addonRowId . ' has no connected site to mend.');
        };
        $site = $ready();
        if ($site instanceof Outcome) {
            return $site;
        }
        if ($this->dryRun) {
            return $this->wouldRun('mend', $addonRowId, sprintf('mend the connection of dashboard site %d (%s)', (int) $site->dashboardSiteId, $site->siteUrl), $actor);
        }
        return $this->exclusively($addonRowId, function () use ($ready, $addonRowId, $actor): Outcome {
            $site = $ready();
            if ($site instanceof Outcome) {
                return $site;
            }
            $id = (int) $site->dashboardSiteId;
            $what = 'dashboard site ' . $id . ' (' . $site->siteUrl . ')';
            $says = '';      // the dashboard's own reason, for staff
            $useless = null; // why the stored login is of no use, when it is
            $trouble = '';   // what else stood in the way
            try {
                if ($this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($id))) {
                    return $this->mended($site, $actor, 'a sync');
                }
                try {
                    $this->dashboard->reconnectSite($id);
                } catch (DashboardFailure $e) {
                    if ($e->kind !== DashboardFailure::REFUSED) {
                        throw $e;
                    }
                }
                $row = $this->dashboard->site($id);
                if (!$row->isDisconnected()) {
                    return $this->mended($site, $actor, 'MainWP\'s reconnect');
                }
                $says = $row->syncErrors;
                $login = $this->storage->login($addonRowId);
                $other = $this->connectAsAnother($site, $login);
                if ($other !== null) {
                    return $this->mendedByAnother($site, $other, $login, $actor);
                }
                if ($login === null) {
                    $useless = 'no login is stored';
                } elseif ($site->retryCount > 0) {
                    $trouble = 'the stored login was already tried for this disconnection';
                } else {
                    // Once per disconnection: a login a day could lock the account out.
                    $site = $site->retried();
                    $this->storage->saveSite($site);
                    try {
                        $this->site->installPlugin($site->siteUrl, $login);
                        if ($this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($id))) {
                            return $this->mended($site, $actor, 'switching MainWP\'s plugin on again with the stored login');
                        }
                        $trouble = 'the stored login worked and MainWP\'s plugin is on, but the dashboard still cannot reach the site';
                    } catch (SiteFailure $failure) {
                        $text = rtrim(LogText::clean($failure->getMessage(), $login->password), '.');
                        if (in_array($failure->kind, self::LOGIN_OF_NO_USE, true)) {
                            $useless = 'the stored login is of no use: ' . $text;
                        } else {
                            $trouble = 'the stored login could not be tried: ' . $text;
                        }
                    }
                }
            } catch (DashboardFailure $e) {
                if ($this->siteTimedOut($e)) {
                    $this->log($actor, 'mend', 'add-on ' . $addonRowId, 'failed', $what . ' is disconnected and could not be mended (the site did not answer in time: ' . self::why($e) . '); it is looked at again tomorrow.');
                    return new Outcome(false, 'disconnected on the dashboard and could not be mended (the site did not answer in time).', '', true);
                }
                $this->log($actor, 'mend', 'add-on ' . $addonRowId, 'failed', $what . ': ' . self::why($e));
                return new Outcome(false, 'Add-on ' . $addonRowId . ' could not be mended: ' . LogText::clean($e->getMessage()), '', true, null, true);
            }
            $said = $says !== '' ? ' The dashboard says: ' . $says . '.' : '';
            if ($useless !== null) {
                $this->storage->saveSite($site->failed(self::NO_LONGER_CONNECTED . self::CONNECT_AGAIN));
                $this->log($actor, 'mend', 'add-on ' . $addonRowId, 'failed', $what . ' is disconnected and could not be mended (' . $useless . '); the client is asked to connect again.' . $said);
                return new Outcome(false, 'disconnected on the dashboard and could not be mended (' . $useless . '); the client is asked to connect again.' . $said, '', true);
            }
            $this->log($actor, 'mend', 'add-on ' . $addonRowId, 'failed', $what . ' is disconnected and could not be mended (' . $trouble . '); it is looked at again tomorrow.' . $said);
            return new Outcome(false, 'disconnected on the dashboard and could not be mended (' . $trouble . ').' . $said, '', true);
        }, static fn (): Outcome => new Outcome(false, self::busy($addonRowId)));
    }

    /**
     * Points the dashboard at each of the site's other administrators on record, in order, with a
     * sync after each, and returns the first that leaves the site connected. No login to the site is
     * involved, so this runs on every mend and is not counted. When none connects, the dashboard is
     * pointed back at the stored login's name (when one is stored), for the stored login's step.
     */
    private function connectAsAnother(CareSite $site, ?Login $stored): ?string
    {
        $id = (int) $site->dashboardSiteId;
        $others = $site->otherAdmins ?? [];
        try {
            foreach ($others as $name) {
                try {
                    $this->dashboard->setSiteAdmin($id, $name);
                } catch (DashboardFailure $e) {
                    if ($e->kind !== DashboardFailure::REFUSED) {
                        throw $e;
                    }
                    continue;
                }
                if ($this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($id))) {
                    return $name;
                }
            }
        } catch (\Throwable $e) {
            // The dashboard failed midway: it is not left pointed at another administrator while the
            // stored login names the care account, or a later sync would pass for a mend without it.
            if ($stored !== null) {
                try {
                    $this->dashboard->setSiteAdmin($id, $stored->username);
                } catch (\Throwable) {
                    // Best effort: the dashboard is failing already.
                }
            }
            throw $e;
        }
        if ($others !== [] && $stored !== null) {
            $this->dashboard->setSiteAdmin($id, $stored->username);
        }
        return null;
    }

    /**
     * The dashboard reaches the site again as another administrator. When the stored login is a care
     * login its account is gone (or no longer an administrator): it is made again under the same
     * name with the plain address, and a refusal leads to a new login with the plus-tagged address.
     * When it cannot be made again, the site is mended all the same and the stored login is deleted.
     */
    private function mendedByAnother(CareSite $site, string $other, ?Login $stored, string $actor): Outcome
    {
        $how = 'pointing the dashboard at another administrator (' . $other . ')';
        if ($stored !== null && Secrets::isCareLogin($stored->username)) {
            if ($this->backAtCareAccount((int) $site->dashboardSiteId, $other, $stored->username)) {
                return $this->mended($site, $actor, $how . ' and back at the care account', true);
            }
            $care = $this->careEmail === '' ? 'no Care Account Email is set' : $this->giveCareAccount($site, (int) $site->dashboardSiteId, $other, $stored->username);
            if ($care instanceof Login) {
                $how .= ' and making the care account again';
            } else {
                $this->storage->deleteLogin($site->addonRowId);
                $how .= '; the care account could not be made again: ' . $care;
            }
        }
        return $this->mended($site, $actor, $how, true);
    }

    /**
     * The disconnection may have been passing and the care account still an administrator of the
     * site: then the dashboard, which reaches the site again as $other, is pointed back at it and
     * synced, and no second care account is made. When it does not connect as the care account
     * (gone, or no longer an administrator), the dashboard is pointed at $other again.
     */
    private function backAtCareAccount(int $id, string $other, string $care): bool
    {
        try {
            if (!in_array($care, $this->dashboard->administrators($id), true)) {
                return false;
            }
        } catch (\Throwable) {
            return false;
        }
        $this->dashboard->setSiteAdmin($id, $care);
        if ($this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($id))) {
            return true;
        }
        $this->dashboard->setSiteAdmin($id, $other);
        $this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($id));
        return false;
    }

    private function mended(CareSite $site, string $actor, string $how, bool $recordOthers = false): Outcome
    {
        $connected = $site->connectedAs((int) $site->dashboardSiteId, $this->now());
        $this->storage->saveSite($recordOthers ? $this->recordOtherAdmins($connected) : $connected);
        $this->log($actor, 'mend', 'add-on ' . $site->addonRowId, 'done', 'dashboard site ' . $site->dashboardSiteId . ' (' . $site->siteUrl . ') was disconnected and is connected again by ' . $how);
        return new Outcome(true, 'Add-on ' . $site->addonRowId . ' is connected again.', '', true);
    }

    /**
     * Takes over a site the dashboard already holds, connected, for a care site that still waits to
     * be connected: staff connected it by hand on the dashboard (SPEC.md, Connecting). Nothing is
     * changed on the dashboard. What a key attempt left open ends: its approval, and its key on the site.
     */
    public function adoptSite(int $addonRowId, int $dashboardSiteId, string $actor): Outcome
    {
        if ($this->dryRun) {
            return $this->wouldRun('connect', $addonRowId, 'take over dashboard site ' . $dashboardSiteId . ', which is already connected', $actor);
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $dashboardSiteId, $actor): Outcome {
            $site = $this->storage->site($addonRowId);
            if ($site === null || !in_array($site->state, [State::Provisioned, State::Failed], true) || $site->dashboardSiteId !== null || $site->dashboardClientId === null) {
                return new Outcome(false, 'Add-on ' . $addonRowId . ' is not waiting to be connected.');
            }
            $this->storage->lapseApprovals($addonRowId, $this->now());
            $this->recordAdopted($site, $dashboardSiteId, $actor);
            return new Outcome(true, 'Add-on ' . $addonRowId . ' is connected.', '', true);
        }, static fn (): Outcome => new Outcome(false, self::busy($addonRowId)));
    }

    /**
     * The dashboard was seen to hold the care site's own dashboard site, connected: the module's
     * record is brought in line. A Connected site gets the dashboard's last sync; a site whose record
     * said Failed (its connection broke, and has since been mended by hand or by itself) or Suspended
     * is Connected again, so that the client is not asked to connect a site that is connected.
     * Nothing is sent anywhere.
     */
    public function seenOnDashboard(int $addonRowId, DashboardSite $row, string $actor): void
    {
        if ($this->dryRun || !$row->isConnected()) {
            return;
        }
        $this->exclusively($addonRowId, function () use ($addonRowId, $row, $actor): bool {
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->dashboardSiteId !== $row->id) {
                return false;
            }
            $synced = $this->localTime($row->lastSync) ?? $site->lastSync ?? $this->now();
            if ($site->state === State::Connected) {
                if ($site->lastSync !== $synced || $site->retryCount !== 0) {
                    $this->storage->saveSite($site->connectedAs($row->id, $synced));
                }
                return true;
            }
            if ($site->state === State::Failed || $site->state === State::Suspended) {
                $this->storage->saveSite($site->connectedAs($row->id, $synced));
                $this->log($actor, 'connect', 'add-on ' . $addonRowId, 'done', $site->siteUrl . ' is connected on the dashboard (dashboard site ' . $row->id . '); the module\'s record said ' . ucfirst($site->state->value) . ' and is brought in line');
            }
            return true;
        }, static fn (): bool => false);
    }

    /**
     * The dashboard no longer holds the care site's dashboard site (someone removed it there). It is
     * asked once more, for this one site, before anything changes. A site that was connected goes on
     * the retry list when a login is stored, and the retry adds it again without asking the client
     *; without one the client is asked to connect again. A site that was not connected
     * on record (its connection had broken, or an approval is out) only lets go of the dead site:
     * what it waits for stays, and a login that was found of no use is not tried again.
     */
    public function lostFromDashboard(int $addonRowId, string $actor): Outcome
    {
        if ($this->dryRun) {
            return new Outcome(true, 'Dry run: nothing was asked or changed.');
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $actor): Outcome {
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->dashboardSiteId === null) {
                return new Outcome(false, 'Add-on ' . $addonRowId . ' has no dashboard site on record.');
            }
            $siteId = $site->dashboardSiteId;
            if (!$this->goneFromTheDashboard($siteId)) {
                return new Outcome(false, 'The dashboard still holds dashboard site ' . $siteId . ', or could not say.');
            }
            if (!in_array($site->state, [State::Connected, State::Suspended], true)) {
                $this->storage->saveSite($site->offDashboard());
                $this->log($actor, 'connect', 'add-on ' . $addonRowId, 'done', 'the dashboard no longer holds dashboard site ' . $siteId . ' (' . $site->siteUrl . '); the record lets go of it');
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is no longer on the dashboard.', '', true);
            }
            $retry = $this->storage->login($addonRowId) !== null;
            $reason = self::NO_LONGER_CONNECTED . ($retry ? self::RETRY_PROMISE : self::CONNECT_AGAIN);
            $site = $site->offDashboard()->attempting(); // the retries start at the first: a count left by a mend is not a retry made
            $this->storage->saveSite($retry ? $site->failedWithRetry($reason, $this->tomorrow()) : $site->failed($reason));
            $this->log($actor, 'connect', 'add-on ' . $addonRowId, 'failed', 'the dashboard no longer holds dashboard site ' . $siteId . ' (' . $site->siteUrl . ')' . ($retry ? '; it is on the retry list and is added again with the stored login' : '; the client is asked to connect again'));
            return new Outcome(true, 'Add-on ' . $addonRowId . ' is no longer on the dashboard.', '', true);
        }, static fn (): Outcome => new Outcome(false, self::busy($addonRowId)));
    }

    /** A time the dashboard gave (UTC, with or without its zone) in the clock's own zone, as the module stores times; null when there is none or it cannot be read. */
    private function localTime(?string $utc): ?string
    {
        if ($utc === null || trim($utc) === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($utc, new \DateTimeZone('UTC')))->setTimezone($this->clock->now()->getTimezone())->format('Y-m-d H:i:s');
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * Takes the site off the dashboard (MainWP switches its plugin off on the site and leaves the
     * files; a site flagged as staging it drops as it is, and the log says so) and deletes the
     * stored login and what is left of a retry. A site the dashboard no longer holds is marked
     * Removed all the same. A site that never reached the dashboard is only marked Removed, and what
     * a key attempt left on it (the care account, the key) is removed. A key still held for a
     * connected site is deleted there; its care account stays, as the client was told.
     */
    public function remove(int $addonRowId, string $actor): Outcome
    {
        $site = $this->storage->site($addonRowId);
        if ($site === null || $site->state === State::Removed) {
            return new Outcome(true, 'Add-on ' . $addonRowId . ' has no care site.');
        }
        if ($this->dryRun) {
            return $this->wouldRun('remove', $addonRowId, $site->dashboardSiteId === null
                ? 'mark ' . $site->siteUrl . ' Removed and delete the stored login'
                : sprintf('remove dashboard site %d (%s) and delete the stored login', $site->dashboardSiteId, $site->siteUrl), $actor);
        }
        return $this->exclusively($addonRowId, function () use ($addonRowId, $actor): Outcome {
            $site = $this->storage->site($addonRowId);
            if ($site === null || $site->state === State::Removed) {
                return new Outcome(true, 'Add-on ' . $addonRowId . ' has no care site.');
            }
            $siteId = $site->dashboardSiteId;
            if ($siteId === null && $site->state !== State::Provisioned) {
                // A connect was tried: the dashboard may hold the site although its answer never arrived.
                try {
                    $siteId = $this->adopt($site);
                } catch (\Throwable $e) {
                    // Nothing is torn down on a guess: the removal fails and is tried again.
                    $reason = LogText::clean($e->getMessage());
                    $this->log($actor, 'remove', 'add-on ' . $addonRowId, 'failed', 'the dashboard could not say whether it holds ' . $site->siteUrl . ': ' . self::why($e));
                    return new Outcome(false, 'Add-on ' . $addonRowId . ' could not be removed: ' . $reason);
                }
            }
            if ($siteId === null) {
                $left = $this->leaveKeyRoute($site, $actor);
                $this->storage->deleteLogin($addonRowId);
                $this->storage->saveSite($site->removed());
                $this->log($actor, 'remove', 'add-on ' . $addonRowId, 'done', $site->siteUrl . ' was never on the dashboard; marked Removed');
                return new Outcome(true, 'Add-on ' . $addonRowId . ' is removed.', trim($left));
            }
            $left = $this->leaveKeyRoute($site, $actor, true);
            return $this->attempt('remove', $addonRowId, $actor, 'could not be removed', function () use ($site, $siteId, $left, $actor): Outcome {
                $how = $this->removeFromDashboard($siteId);
                $this->storage->deleteLogin($site->addonRowId);
                $this->storage->saveSite($site->removed());
                $this->log($actor, 'remove', 'add-on ' . $site->addonRowId, 'done', 'dashboard site ' . $siteId . ' (' . $site->siteUrl . ') ' . $how);
                return new Outcome(true, 'Add-on ' . $site->addonRowId . ' is removed.', trim($left));
            });
        }, fn (): Outcome => $this->busyEvent('remove', $addonRowId, $actor), self::EVENT_WAIT);
    }

    /**
     * Takes the site off the dashboard and says how that went, for the log. MainWP switches its
     * plugin off on the site and says so; a site it has flagged as staging it drops as it is, with
     * other words. A site the dashboard no longer holds is as removed as it can be. When MainWP
     * answers with an error from the site (the connecting administrator is gone, say) it has dropped
     * the site all the same (seen on a test bench): that is not taken at its word but asked.
     */
    private function removeFromDashboard(int $siteId): string
    {
        try {
            $said = $this->dashboard->removeSite($siteId);
        } catch (DashboardFailure $e) {
            if ($e->kind === DashboardFailure::NOT_FOUND) {
                return 'was no longer on the dashboard';
            }
            if ($this->siteTimedOut($e)) {
                throw new DashboardFailure(DashboardFailure::TIMED_OUT, 'The site did not answer in time while the dashboard removed dashboard site ' . $siteId . '.');
            }
            if ($e->kind !== DashboardFailure::REFUSED || !$this->goneFromTheDashboard($siteId)) {
                throw $e;
            }
            return 'removed from the dashboard; MainWP could not switch its plugin off on the site: ' . self::why($e);
        }
        return str_contains($said, 'has been disabled') ? 'removed' : 'removed; MainWP did not switch its plugin off on the site, as for a site it has flagged as staging, and says: "' . LogText::clean($said) . '"';
    }

    /** Does the dashboard say it has no such site? False when it holds it, and when it cannot say. */
    private function goneFromTheDashboard(int $siteId): bool
    {
        try {
            $this->dashboard->site($siteId);
        } catch (\Throwable $e) {
            return $e instanceof DashboardFailure && $e->kind === DashboardFailure::NOT_FOUND;
        }
        return false;
    }

    /**
     * Care was suspended for a site that is not on the dashboard, so there is nothing to suspend
     * there. What a connect has open ends instead: an approval the client was sent to give stops
     * working and the site waits to be connected again; a key held for the retries is used one
     * last time to remove the care account made with it and to delete itself. A login kept from a
     * password attempt stays, for when care is active again.
     */
    private function endOpenConnect(CareSite $site, string $actor): Outcome
    {
        $id = $site->addonRowId;
        $nothing = 'Add-on ' . $id . ' has no site on the dashboard to suspend.';
        if ($this->storage->heldKey($id) === null) {
            if ($site->state === State::Connecting && $site->retryAfter === null) {
                $this->storage->lapseApprovals($id, $this->now());
                $this->storage->saveSite($site->withState(State::Provisioned));
                $this->log($actor, 'suspend', 'add-on ' . $id, 'done', $site->siteUrl . ' is not on the dashboard; the approval the client was sent to give was ended');
            } elseif ($site->state === State::Connecting) {
                $this->expire($site, $actor); // a password attempt that was cut off halfway: Failed, its login kept for the retry
            }
            return new Outcome(true, $nothing);
        }
        $left = $this->leaveKeyRoute($site, $actor);
        $reason = self::unretried($site->state === State::Connecting ? self::CUT_OFF : $site->lastError) . $left;
        $this->storage->saveSite($this->notConnected($site, $reason));
        $this->log($actor, 'suspend', 'add-on ' . $id, 'done', $site->siteUrl . ' is not on the dashboard; what the key attempt left on the site was removed' . ($left !== '' ? ', as far as the site allowed,' : '') . ' and it is no longer retried');
        return new Outcome(true, $nothing, trim($left));
    }

    /** An event that still finds the add-on taken by another request after its wait: said in the log, because nothing else would say it. */
    private function busyEvent(string $action, int $addonRowId, string $actor): Outcome
    {
        $this->log($actor, $action, 'add-on ' . $addonRowId, 'failed', 'another request was working on the add-on for more than ' . self::EVENT_WAIT . ' seconds; nothing was done');
        return new Outcome(false, self::busy($addonRowId));
    }

    /**
     * Asks the dashboard how many clients and sites it holds, to show that the address and the key
     * work. It reads two numbers and changes nothing, so it runs in dry-run too: a key is proved
     * before the mode is switched.
     */
    public function testConnection(string $dashboardUrl, string $actor): Outcome
    {
        try {
            $counts = $this->dashboard->counts();
        } catch (\Throwable $e) {
            $reason = LogText::clean($e->getMessage());
            $this->log($actor, 'test connection', 'dashboard', 'failed', $reason);
            return new Outcome(false, $reason);
        }
        $plural = static fn (int $n, string $word): string => $n . ' ' . $word . ($n === 1 ? '' : 's');
        $message = 'Connected to ' . $dashboardUrl . ': ' . $plural($counts['clients'], 'client') . ', ' . $plural($counts['sites'], 'site') . '.';
        $this->log($actor, 'test connection', 'dashboard', 'done', $message);
        return new Outcome(true, $message);
    }

    /**
     * The key route's work with a key that was claimed for the add-on: the site makes the care
     * account and installs the plugin, the dashboard connects as the care account, the care login
     * becomes the stored login, the site's other administrators are recorded and the
     * key is deleted on the site. A failure leaves
     * the care site Failed with the reason and on the retry list, the key and the care account kept
     * for it; only an approver who is no administrator ends it at once, with the key deleted.
     */
    private function keyConnect(CareSite $site, HeldKey $held, string $actor, bool $retry): Outcome
    {
        $id = $site->addonRowId;
        $retryAfter = $this->tomorrow();
        $answerLate = false;
        try {
            $care = $held->care;
            $siteId = $retry ? $this->adopt($site) : null;
            $adopted = $siteId !== null;
            if ($siteId === null) {
                try {
                    $name = $this->site->installPluginWithKey($site->siteUrl, $held->key, $care, $this->careEmail, $this->storage->serviceName($id));
                    $care = $name === $care->username ? $care : new Login($name, $care->password);
                } catch (SiteFailure $refused) {
                    // On a retry the care account of the attempt before may still be there with the plugin
                    // active: when only the key is no longer accepted, the dashboard can still connect as it.
                    if (!$retry || $refused->kind !== SiteFailure::KEY_REFUSED) {
                        throw $refused;
                    }
                    try {
                        $siteId = $this->holdOnDashboard($site, $care, $answerLate);
                    } catch (\Throwable) {
                        throw $refused;
                    }
                }
                $siteId ??= $this->holdOnDashboard($site, $care, $answerLate);
            }
        } catch (\Throwable $e) {
            $secrets = [$held->key->password, $held->care->password];
            $slow = $this->siteTimedOut($e);
            $reason = $slow ? self::SITE_TOO_SLOW : self::clientReason($e, $site->siteUrl, ...$secrets);
            $fault = !$slow && self::isDashboardFault($e);
            if ($e instanceof DashboardFailure && $e->kind === DashboardFailure::EARLIER_CONNECTION) {
                // No retry can clear what MainWP's plugin holds on the site: what this attempt made there is taken away again.
                $left = $this->leaveKeyRoute($site, $actor);
                $this->storage->saveSite($site->failed($reason . $left));
                $this->log($actor, 'connect', 'add-on ' . $id, 'failed', self::why($e, ...$secrets) . ' (not retried)' . ($left !== '' ? '. For the client:' . $left : ''));
                return new Outcome(false, 'Add-on ' . $id . ' could not be connected: ' . $reason, trim($left), true);
            }
            if ($e instanceof SiteFailure && $e->kind === SiteFailure::NOT_ADMIN) {
                $deleted = $this->deleteHeldKey($site, $held, $actor);
                $this->storage->releaseKey($id);
                $keyLeft = $deleted ? '' : $this->keyLeft($id);
                $reason .= ' Approve the connection again while signed in as an administrator.' . $keyLeft;
                $this->storage->saveSite($site->failed($reason));
                $this->log($actor, 'connect', 'add-on ' . $id, 'failed', self::why($e, ...$secrets) . ' (not retried)' . ($deleted ? '' : '. For the client:' . $keyLeft));
                return new Outcome(false, 'Add-on ' . $id . ' could not be connected: ' . $reason, '', true);
            }
            $this->storage->saveSite(($retry && $fault ? $site->uncounted() : $site)->failedWithRetry($reason, $retryAfter));
            $this->log($actor, 'connect', 'add-on ' . $id, 'failed', self::why($e, ...$secrets) . ' (on the retry list: retry after ' . $retryAfter . ', with the approved key' . ($retry && $fault ? '; not counted as a retry' : '') . ')');
            return new Outcome(false, 'Add-on ' . $id . ' could not be connected: ' . $reason, '', true, null, $fault);
        }
        $this->storage->saveLogin($id, $care);
        $this->storage->saveSite($this->recordOtherAdmins($site->connectedAs($siteId, $this->now()), [$held->key->username]));
        $this->log($actor, 'connect', 'add-on ' . $id, 'done', $site->siteUrl . ' connected as dashboard site ' . $siteId . ' with the care account ' . $care->username . ($adopted ? ' (the dashboard already held it)' : '') . ($answerLate ? ' (the dashboard\'s answer did not come in time; it holds the site)' : ''));
        $this->deleteHeldKey($site, $held, $actor);
        return new Outcome(true, 'Add-on ' . $id . ' is connected.', '', true);
    }

    /** Deletes the held key on the site and forgets it. When the site cannot be reached now the key stays held, and false is returned. */
    private function deleteHeldKey(CareSite $site, HeldKey $held, string $actor): bool
    {
        try {
            $this->site->deleteKey($site->siteUrl, $held->key);
        } catch (\Throwable $e) {
            $this->log($actor, 'key', 'add-on ' . $site->addonRowId, 'failed', 'the approved key could not be deleted on ' . $site->siteUrl . ': ' . self::why($e, $held->key->password, $held->care->password));
            return false;
        }
        $this->storage->releaseKey($site->addonRowId);
        $this->log($actor, 'key', 'add-on ' . $site->addonRowId, 'done', 'the approved key was deleted on ' . $site->siteUrl);
        return true;
    }

    /** Deletes, on the site, a key that came back but will not be used. False when it could not be deleted. */
    private function discard(CareSite $site, Login $key, string $actor, string $why): bool
    {
        try {
            $this->site->deleteKey($site->siteUrl, $key);
        } catch (\Throwable $e) {
            $this->log($actor, 'key', 'add-on ' . $site->addonRowId, 'failed', 'a key came back but ' . $why . '; it could not be deleted on ' . $site->siteUrl . ': ' . self::why($e, $key->password));
            return false;
        }
        $this->log($actor, 'key', 'add-on ' . $site->addonRowId, 'done', 'a key came back but ' . $why . '; it was deleted on ' . $site->siteUrl);
        return true;
    }

    /**
     * Ends whatever the key route has open for the site: an approval not yet given lapses, and a
     * held key is used one last time to remove the care account made with it (unless the site is
     * connected as that account, $keepAccount) and to delete itself. Returns, in plain words for
     * the client, what could not be removed on the site; '' when nothing was left.
     */
    private function leaveKeyRoute(CareSite $site, string $actor, bool $keepAccount = false): string
    {
        $id = $site->addonRowId;
        $this->storage->lapseApprovals($id, $this->now());
        $held = $this->storage->heldKey($id);
        if ($held === null) {
            return '';
        }
        $left = '';
        $secrets = [$held->key->password, $held->care->password];
        if (!$keepAccount) {
            try {
                $this->site->removeAccount($site->siteUrl, $held->key, $held->care->username);
                $this->log($actor, 'care account', 'add-on ' . $id, 'done', 'the account ' . $held->care->username . ' made for care is no longer on ' . $site->siteUrl);
            } catch (\Throwable $e) {
                $this->log($actor, 'care account', 'add-on ' . $id, 'failed', 'the account ' . $held->care->username . ' made for care could not be removed on ' . $site->siteUrl . ': ' . self::why($e, ...$secrets));
                $left .= ' The account ' . $held->care->username . ' made for care may still be on your site: delete it there under Users.';
            }
        }
        try {
            $this->site->deleteKey($site->siteUrl, $held->key);
            $this->log($actor, 'key', 'add-on ' . $id, 'done', 'the approved key was deleted on ' . $site->siteUrl);
        } catch (\Throwable $e) {
            $this->log($actor, 'key', 'add-on ' . $id, 'failed', 'the approved key could not be deleted on ' . $site->siteUrl . ': ' . self::why($e, ...$secrets));
            $left .= $this->keyLeft($id);
        }
        $this->storage->releaseKey($id);
        return $left;
    }

    /**
     * What the client is told of an application password that may still be on their site: named as
     * they approved it, for the service of the add-on row.
     */
    private function keyLeft(int $addonRowId): string
    {
        return ' An application password whose name begins "' . $this->storage->serviceName($addonRowId) . '" may still be on your site: delete it there under Users, Profile, Application Passwords, on the account that approved the connection.';
    }

    /**
     * The care account's first and last name, from which WordPress makes its display name: the
     * service's name split at its last space ("Site Care Plus" is "Site Care" and "Plus"); one word
     * is a first name with no last name.
     *
     * @return array{string, string}
     */
    private static function firstAndLast(string $name): array
    {
        $name = trim($name);
        $space = strrpos($name, ' ');
        return $space === false ? [$name, ''] : [rtrim(substr($name, 0, $space)), substr($name, $space + 1)];
    }

    /** What the client is told when an earlier attempt turns out to have connected the site: which address is connected, and that another one they typed was not used. */
    private static function alreadyConnected(CareSite $stored, CareSite $typed): string
    {
        return 'Your site is already connected at ' . $stored->siteUrl . '.' . ($typed->siteUrl !== $stored->siteUrl ? ' ' . $typed->siteUrl . ' was not used: one care add-on covers one site. To move care to another address, contact support.' : '');
    }

    /** Why the add-on's site cannot be connected now, or null when it can. */
    private static function notConnectable(?CareSite $site, int $addonRowId): ?string
    {
        if ($site === null || $site->state === State::Removed || $site->dashboardClientId === null) {
            return 'Add-on ' . $addonRowId . ' has no care site to connect: it is not provisioned.';
        }
        return in_array($site->state, self::CONNECTABLE, true) ? null : 'Add-on ' . $addonRowId . ' is already ' . $site->state->value . '.';
    }

    /**
     * A key is still held for the site and the dashboard already holds the site, connected, under
     * the client: an earlier key attempt did connect it and only its end was lost. The connection
     * is finished here (the care login stored, the key deleted) instead of being torn down by a new
     * attempt. False when there is nothing of the kind, or the dashboard cannot say.
     */
    private function finishedByTheDashboard(CareSite $site, string $actor): bool
    {
        if ($this->storage->heldKey($site->addonRowId) === null) {
            return false;
        }
        try {
            $siteId = $this->adopt($site);
        } catch (\Throwable) {
            return false;
        }
        if ($siteId === null) {
            return false;
        }
        $this->recordAdopted($site, $siteId, $actor);
        return true;
    }

    /**
     * Records a connection the dashboard turned out to hold: the care site is Connected with the
     * dashboard's site id and the site's other administrators are recorded. When a key was held, the
     * care account the dashboard connected as becomes the stored login and the key is deleted on the site;
     * otherwise a login typed for a failed connect (not a care login) is deleted, since nothing retries it now.
     */
    private function recordAdopted(CareSite $site, int $siteId, string $actor): CareSite
    {
        $id = $site->addonRowId;
        $held = $this->storage->heldKey($id);
        $typed = '';
        if ($held !== null) {
            $this->storage->saveLogin($id, $held->care);
        } else {
            $login = $this->storage->login($id);
            if ($login !== null && !Secrets::isCareLogin($login->username)) {
                // A login typed for a failed connect is kept only while that connect is retried.
                $this->storage->deleteLogin($id);
                $typed = '; the login typed is deleted';
            }
        }
        $connected = $this->recordOtherAdmins($site->connectedAs($siteId, $this->now()), $held !== null ? [$held->key->username] : []);
        $this->storage->saveSite($connected);
        $this->log($actor, 'connect', 'add-on ' . $id, 'done', $site->siteUrl . ' connected as dashboard site ' . $siteId . ($held !== null ? ' with the care account ' . $held->care->username : '') . ' (the dashboard already held it)' . $typed);
        if ($held !== null) {
            $this->deleteHeldKey($connected, $held, $actor);
        }
        return $connected;
    }

    /** A failure's reason for the log: cleaned of secrets and paths, with what only staff need to know added. */
    private static function why(\Throwable $e, string ...$secrets): string
    {
        return LogText::clean($e->getMessage(), ...$secrets) . ($e instanceof SiteFailure && $e->staffNote !== '' ? ' [' . $e->staffNote . ']' : '');
    }

    /**
     * The reason a client reads for a connect that failed. A fault between the module and the
     * dashboard (unreachable, the API key refused, an answer that is not MainWP's, a site the
     * dashboard already holds) is ours: the client is told that and no more, and its words, which
     * name what only staff see, go to the log. So is an error of the module itself, whose words are
     * PHP's or the database's. What the site said, and what the dashboard says about the site itself
     * (it refused the login it was given), stays, unless the words name MainWP: the client knows the
     * service, not the tool behind it, so they are told only that the site could not be connected.
     */
    private static function clientReason(\Throwable $e, string $siteUrl, string ...$secrets): string
    {
        if ($e instanceof DashboardFailure && $e->kind === DashboardFailure::EARLIER_CONNECTION) {
            return self::EARLIER_CONNECTION;
        }
        if ($e instanceof DashboardFailure ? $e->kind !== DashboardFailure::REFUSED : !$e instanceof SiteFailure) {
            return self::OUR_SIDE;
        }
        $words = LogText::clean($e->getMessage(), ...$secrets);
        return self::namesTheTool($words, $siteUrl) ? self::NOT_CONNECTED : $words;
    }

    /**
     * A care site's stored reason as its client may read it. The words are checked again where they
     * are shown, because a row may keep a reason from a version before 0.5.0, which stored the
     * dashboard's own words and named MainWP's plugin: such a reason is replaced by a plain sentence.
     * Staff read what is stored.
     */
    public static function reasonForClient(?string $stored, string $siteUrl): ?string
    {
        return $stored !== null && self::namesTheTool($stored, $siteUrl) ? self::NOT_CONNECTED : $stored;
    }

    /**
     * Do the words name MainWP (its plugin, its dashboard, the module's own classes and tables)? No
     * client reads such words. MainWP writes its name as one word everywhere, so that word is looked
     * for, in any case. The address of the client's own site does not count, in any of the forms it
     * is written in: with or without https://, "www.", its port or its folder.
     */
    private static function namesTheTool(string $words, string $siteUrl): bool
    {
        $address = rtrim((string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', trim($siteUrl)), '/');
        $host = (string) preg_replace('~[/?#].*$~', '', $address);
        $name = (string) preg_replace('~:\d+$~', '', $host);
        $bare = (string) preg_replace('~^www\.~i', '', $name);
        $own = array_values(array_filter([$address, $host, 'www.' . $bare, $name, $bare], static fn (string $form): bool => $form !== '' && $form !== 'www.'));
        return stripos(str_ireplace($own, '', $words), 'mainwp') !== false;
    }

    /** Did it fail because of the dashboard itself (unreachable, its key refused, not MainWP's answer), and not because of what MainWP says about the site? */
    private static function isDashboardFault(\Throwable $e): bool
    {
        return $e instanceof DashboardFailure && !in_array($e->kind, [DashboardFailure::REFUSED, DashboardFailure::ALREADY_HELD, DashboardFailure::EARLIER_CONNECTION], true);
    }

    /**
     * Did the dashboard take a call and wait in vain for the client's site it reaches out to? Only a
     * call that timed out after the dashboard took it is asked about: the dashboard is then asked for
     * something that reaches no site, and when it answers that, the time ran out on the site.
     */
    private function siteTimedOut(\Throwable $e): bool
    {
        if (!$e instanceof DashboardFailure || $e->kind !== DashboardFailure::TIMED_OUT) {
            return false;
        }
        try {
            $this->dashboard->counts();
        } catch (\Throwable) {
            return false;
        }
        return true;
    }

    /** Is the site's retry due on that day (Y-m-d)? By the date, not the time. */
    private static function dueOn(CareSite $site, string $day): bool
    {
        return $site->retryAfter !== null && substr($site->retryAfter, 0, 10) <= $day;
    }

    /**
     * Has the dashboard hold the site, connected as $as, and returns its dashboard site id. A site
     * the dashboard still holds from a connection that broke is pointed at that administrator and
     * synced, and MainWP's own reconnect is tried when the sync does not mend it: no password is
     * needed for that and nothing is removed (seen on a test bench). When the dashboard no longer has
     * that site, or never had one, the site is added. $answerLate is set when the dashboard's answer
     * to that add did not come and the site was then found on it, connected: the caller says so in its log line.
     */
    private function holdOnDashboard(CareSite $site, Login $as, bool &$answerLate = false): int
    {
        $old = $site->dashboardSiteId;
        if ($old !== null) {
            try {
                $this->dashboard->setSiteAdmin($old, $as->username);
                if ($this->mendedBy(fn () => $this->dashboard->syncSite($old))) {
                    return $old;
                }
                $this->dashboard->reconnectSite($old);
                $row = $this->dashboard->site($old);
                if ($row->isDisconnected()) {
                    throw new DashboardFailure(DashboardFailure::REFUSED, 'The dashboard still cannot reach the site after reconnecting it: ' . $row->syncErrors . '.');
                }
                return $old;
            } catch (DashboardFailure $e) {
                if ($e->kind === DashboardFailure::REFUSED) {
                    // MainWP says it cannot mend the site it holds: neither a retry nor another login changes that.
                    throw new DashboardFailure(DashboardFailure::EARLIER_CONNECTION, 'The dashboard could not mend its site ' . $old . ' for ' . $site->siteUrl . ': ' . rtrim($e->getMessage(), '.') . '. Remove that site on the dashboard; if a new connect is then refused, clear the old connection on the site (Settings, MainWP Child, Disconnect).');
                }
                if ($e->kind !== DashboardFailure::NOT_FOUND) {
                    throw $e;
                }
                // The dashboard no longer holds that site: it is added like any other.
            }
        }
        try {
            return $this->dashboard->addSite((int) $site->dashboardClientId, $site->siteUrl, $as);
        } catch (DashboardFailure $e) {
            // No answer came: the dashboard may have gone on and connected the site all the same (live, 11
            // November 2026: with a dozen sites on it, MainWP's sites/add ran for over 30 seconds and had
            // connected the site within ten). When it holds the site by now, that is the answer.
            if ($e->kind !== DashboardFailure::UNREACHABLE && $e->kind !== DashboardFailure::TIMED_OUT) {
                throw $e;
            }
            try {
                $held = $this->adopt($site);
            } catch (\Throwable) {
                throw $e;
            }
            if ($held === null) {
                throw $e;
            }
            $answerLate = true;
            return $held;
        }
    }

    /**
     * A care login was typed: the host made the care account on the site and switched MainWP's
     * plugin on there, so the dashboard is asked first, with no login to the site. Only when the
     * dashboard refuses is the site logged in to with that login, the plugin installed and switched
     * on, and the dashboard asked again.
     */
    private function holdAsCareAccount(CareSite $site, Login $care, bool &$answerLate = false): int
    {
        try {
            return $this->holdOnDashboard($site, $care, $answerLate);
        } catch (DashboardFailure $e) {
            // EARLIER_CONNECTION is the dashboard's refusal for a site it still holds: switching the
            // plugin on may let it mend that site; if not, the second ask says so again.
            if ($e->kind !== DashboardFailure::REFUSED && $e->kind !== DashboardFailure::EARLIER_CONNECTION) {
                throw $e;
            }
        }
        $this->site->installPlugin($site->siteUrl, $care);
        return $this->holdOnDashboard($site, $care, $answerLate);
    }

    /**
     * Gives a connected site its care account: the dashboard, connected as the administrator $as,
     * makes the account on the site (a new care login, or $sameLogin when one is made again under
     * the name it had), is pointed at it and syncs. When the site refuses the account (its login or
     * its address is taken; the site's words are not read, they come in its own language) a newly
     * drawn login is tried once more with the address plus-tagged with it. When the sync leaves the
     * site connected the care account is the stored login and is returned. Otherwise the dashboard is
     * pointed back at $as, no care login is kept, and the reason is returned for the log, cleaned of
     * every password.
     */
    private function giveCareAccount(CareSite $site, int $siteId, string $as, ?string $sameLogin = null): Login|string
    {
        $care = new Login($sameLogin ?? $this->secrets->careLogin(), $this->secrets->password());
        $passwords = [$care->password];
        $pointed = false;
        [$first, $last] = self::firstAndLast($this->storage->serviceName($site->addonRowId));
        try {
            try {
                $this->dashboard->createAdministrator($siteId, $care, $this->careEmail, $first, $last);
            } catch (DashboardFailure $refused) {
                if ($refused->kind !== DashboardFailure::REFUSED) {
                    throw $refused;
                }
                $care = new Login($this->secrets->careLogin(), $this->secrets->password());
                $passwords[] = $care->password;
                $this->dashboard->createAdministrator($siteId, $care, Secrets::plusTagged($this->careEmail, $care->username), $first, $last);
            }
            $pointed = true;
            $this->dashboard->setSiteAdmin($siteId, $care->username);
            if (!$this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($siteId))) {
                throw new DashboardFailure(DashboardFailure::REFUSED, 'the dashboard could not reach the site as ' . $care->username);
            }
        } catch (\Throwable $e) {
            if ($pointed) {
                try {
                    $this->dashboard->setSiteAdmin($siteId, $as);
                    $this->mendedBy(fn (): DashboardSite => $this->dashboard->syncSite($siteId));
                } catch (\Throwable) {
                    // Best effort: the daily check mends a site the dashboard cannot reach.
                }
            }
            return LogText::clean($e->getMessage(), ...$passwords);
        }
        $this->storage->saveLogin($site->addonRowId, $care);
        return $care;
    }

    /**
     * The site's other administrators, as the dashboard lists them now: every login that is not a
     * care login, in MainWP's order, at most ten. When the dashboard cannot list them the record is
     * left as it was, with $atLeast (an administrator known to be on the site) put first.
     *
     * @param list<string> $atLeast
     */
    private function recordOtherAdmins(CareSite $site, array $atLeast = []): CareSite
    {
        $others = static fn (array $logins): array => array_slice(array_values(array_unique(array_filter($logins, static fn (string $login): bool => !Secrets::isCareLogin($login)))), 0, self::OTHER_ADMINS);
        try {
            $logins = $this->dashboard->administrators((int) $site->dashboardSiteId);
        } catch (\Throwable) {
            return $atLeast === [] ? $site : $site->withOtherAdmins($others(array_merge($atLeast, $site->otherAdmins ?? [])));
        }
        return $site->withOtherAdmins($others($logins));
    }

    /**
     * Runs a sync and says whether the dashboard now reaches the site. MainWP answers a sync that
     * failed with a refusal that gives no reason (seen on a test bench): that is "not mended", not a fault.
     *
     * @param callable(): DashboardSite $sync
     */
    private function mendedBy(callable $sync): bool
    {
        try {
            return !$sync()->isDisconnected();
        } catch (DashboardFailure $e) {
            if ($e->kind !== DashboardFailure::REFUSED) {
                throw $e;
            }
            return false;
        }
    }

    /** The reason of a failure that nothing will try again: without the promise that something will. */
    private static function unretried(?string $reason): string
    {
        return str_replace(self::RETRY_PROMISE, '', $reason ?? 'The site could not be connected.');
    }

    /** A site that leaves Connecting without a key: Failed with the reason; an earlier password attempt's stored login stays on the retry list. */
    private function notConnected(CareSite $site, string $reason): CareSite
    {
        return $this->storage->login($site->addonRowId) !== null ? $site->failedWithRetry($reason, $this->tomorrow()) : $site->failed($reason);
    }

    /**
     * A connected site the dashboard already holds at this address under the client: an earlier attempt
     * whose answer was lost. A dashboard site that another care site records is that add-on's and is
     * never taken (two add-ons of one client at one address), as in the daily check's taking over.
     */
    private function adopt(CareSite $site): ?int
    {
        $address = self::address($site->siteUrl);
        $taken = [];
        foreach ($this->storage->sites() as $other) {
            if ($other->addonRowId !== $site->addonRowId && $other->dashboardSiteId !== null) {
                $taken[$other->dashboardSiteId] = true;
            }
        }
        foreach ($this->dashboard->sites() as $held) {
            if (!isset($taken[$held->id]) && $held->clientId === $site->dashboardClientId && $held->isConnected() && self::address($held->url) === $address) {
                return $held->id;
            }
        }
        return null;
    }

    private static function address(string $url): string
    {
        return rtrim(strtolower(trim($url)), '/');
    }

    /** Both are https:// addresses on one host, with or without "www."; WordPress may live in a folder, so the path is not compared. */
    private static function sameSite(string $returned, string $stored): bool
    {
        return str_starts_with(strtolower(trim($returned)), 'https://') && str_starts_with(strtolower(trim($stored)), 'https://')
            && self::host($returned) !== '' && self::host($returned) === self::host($stored);
    }

    private static function host(string $url): string
    {
        return (string) preg_replace('/^www\./', '', strtolower((string) parse_url(trim($url), PHP_URL_HOST)));
    }

    /**
     * Runs one live command; a failure from the dashboard or the site is logged and returned as a
     * failed outcome, its text cleaned of file paths.
     *
     * @param callable(): Outcome $work
     */
    private function attempt(string $action, int $addonRowId, string $actor, string $failure, callable $work): Outcome
    {
        try {
            return $work();
        } catch (\Throwable $e) {
            $this->log($actor, $action, 'add-on ' . $addonRowId, 'failed', self::why($e));
            return new Outcome(false, 'Add-on ' . $addonRowId . ' ' . $failure . ': ' . LogText::clean($e->getMessage()));
        }
    }

    /**
     * Runs $work while no other request works on the add-on; when one still does after
     * $waitSeconds, $busy answers instead.
     *
     * @template T
     * @param callable(): T $work
     * @param callable(): T $busy
     * @return T
     */
    private function exclusively(int $addonRowId, callable $work, callable $busy, int $waitSeconds = 0): mixed
    {
        if (!$this->storage->lock($addonRowId, $waitSeconds)) {
            return $busy();
        }
        try {
            return $work();
        } finally {
            $this->storage->unlock($addonRowId);
        }
    }

    private static function busy(int $addonRowId): string
    {
        return 'Add-on ' . $addonRowId . ' is being connected right now; try again in a minute.';
    }

    /** The care account step of a password connect, for a dry run's "would" line: none for a care login typed, or without a Care Account Email. */
    private function careStep(Login $login): string
    {
        return $this->careEmail === '' || Secrets::isCareLogin($login->username) ? '' : ', then make the care account';
    }

    private function wouldRun(string $action, int $addonRowId, string $plan, string $actor): Outcome
    {
        $this->log($actor, $action, 'add-on ' . $addonRowId, 'would run', 'would ' . $plan);
        return new Outcome(true, 'Dry run: would ' . $plan . '.');
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d H:i:s');
    }

    private function tomorrow(): string
    {
        return $this->clock->now()->modify('+1 day')->format('Y-m-d H:i:s');
    }

    private function log(string $actor, string $action, string $target, string $outcome, string $detail): void
    {
        $this->storage->log(new LogEntry($this->now(), $actor, $action, $target, $outcome, $this->dryRun, $detail));
    }
}
