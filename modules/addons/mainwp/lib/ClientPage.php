<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

use MainWpForWhmcs\Engine\ApprovalReturn;
use MainWpForWhmcs\Engine\ApprovalStart;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\RouteOffer;
use MainWpForWhmcs\Engine\SiteAddress;
use MainWpForWhmcs\Engine\State;

/**
 * The module's client page.
 *
 * index.php?m=mainwp&addon=<add-on row>: the site's state and, while it waits to be connected, the
 * two routes: approving a key on the client's own site (offered first, when the site's public REST
 * index shows it can) and the administrator username-and-password form. Shown only to the client
 * who owns the service, or a user with the permission to manage its products, and never in dry run
 * (no care site is stored then, so there is nothing to connect). While the add-on is not Active on an Active hosting service the page
 * says only what the client's panel says, with the way to their invoices when one is unpaid.
 *
 * index.php?m=mainwp&care_return=<token>&...: where the client's site sends their browser back
 * with the key. It works from the one-time token alone, so a lapsed WHMCS session loses nothing,
 * and always ends in a redirect to an address without the key.
 *
 * index.php?m=mainwp&care_result=<token hash>: how a return ended, for a browser without a session.
 *
 * No password, key or token is ever shown back.
 */
final class ClientPage
{
    /** States in which the site waits for the client to connect it. */
    public const CONNECTABLE = [State::Provisioned, State::Failed, State::Connecting];

    /**
     * The page's own query names. None names the tool behind the service: WordPress prints the two
     * return addresses on the client's approval screen, and the others show in the address bar.
     */
    public const Q_RETURN = 'care_return';
    public const Q_REJECTED = 'care_rejected';
    public const Q_RESULT = 'care_result';
    public const Q_NOTE = 'care_note';
    public const Q_KEY = 'care_key';

    /**
     * The same names in versions before 0.5.0. A site that was given them then (a link is good for
     * 24 hours) still sends the browser back with them, and a browser may still be on an outcome
     * address of that version.
     */
    private const EARLIER = ['mainwp_return' => self::Q_RETURN, 'mainwp_rejected' => self::Q_REJECTED, 'mainwp_result' => self::Q_RESULT, 'mainwp_note' => self::Q_NOTE, 'mainwp_key' => self::Q_KEY];

    /**
     * How a return ended when the site's state does not say it, by the code that travels in the
     * redirect; $name is the service's name, by which the client knows what they approved.
     *
     * @return array<string, string>
     */
    private static function notes(string $name): array
    {
        return [
            ApprovalReturn::UNKNOWN => 'This link is not valid, or a newer one has replaced it. Sign in to your client area to start again.',
            ApprovalReturn::INVALID => 'What came back from your site was not a key that can be used. Sign in to your client area to start again.',
            ApprovalReturn::MISMATCH => 'The connection was approved on a different site than the one being connected, so nothing was used. An application password whose name begins "' . $name . '" was made on the site where you approved; delete it there under Users, Profile, Application Passwords.',
            ApprovalReturn::LAPSED => 'This link had lapsed, so the approval was not used. Sign in to your client area to start again.',
            ApprovalReturn::REFUSED => $name . ' is not active for this site at the moment, or the site is no longer waiting to be connected, so the approval was not used.',
            ApprovalReturn::BUSY => 'We were busy with your site when your approval came back, so it was not used. Please wait a minute and press "Approve on my site" again.',
            ApprovalReturn::DRY_RUN => 'The approval was not used.',
            'error' => 'Something went wrong on our side while the approval was handled. Please contact support.',
        ];
    }

    private static function keyLeft(string $name): string
    {
        return 'An application password whose name begins "' . $name . '" may still be on your site. Delete it there under Users, Profile, Application Passwords, on the account that approved the connection.';
    }
    private const KEY_ROUTE_OFF = 'Approving the connection on your site is not available at the moment. Connect with an administrator username and password instead, or contact support.';

    /** What the client reads, on the page and in the panel, for a care add-on whose hosting service has no domain. */
    public const NO_DOMAIN = 'This service has no domain, so a site cannot be connected here. Please contact support.';

    /** What the client reads for an address another care add-on of theirs already covers. */
    private const COVERED = 'This site is already covered by another care add-on of yours. Enter the address of another site on this service, or contact support.';

    /** Seconds the page waits for a site's public index before it offers the password form alone. */
    private const INDEX_TIMEOUT = 10;

    /** Every variable the template reads, with the value that shows nothing. */
    public const NOTHING = [
        'available' => false, 'outcomeOnly' => false, 'address' => '', 'message' => '', 'lastSync' => '', 'form' => false,
        'keyRoute' => false, 'keyReason' => '', 'wordfence' => false, 'careAccount' => false, 'error' => '', 'notice' => '', 'note' => '', 'redirect' => '', 'continue' => '',
        'token' => '', 'addonRowId' => 0, 'formAddress' => '', 'waiting' => false, 'fieldAddress' => '', 'invoices' => '', 'name' => '',
    ];

    /** Where the client finds their invoices in WHMCS's client area. */
    private const INVOICES = 'clientarea.php?action=invoices';

    /**
     * The template's variables. 'redirect', when set, is where the browser is to be sent; 'name' is
     * the service's name for the page's heading, title and breadcrumb: the add-on the page is for, or
     * the fallback where no add-on is at hand.
     *
     * @param array{clientId: int, permitted: bool}|null $viewer
     * @param array<array-key, mixed> $query already decoded once (WHMCS HTML-encodes input)
     * @param array<array-key, mixed> $post already decoded once
     * @return array<string, mixed>
     */
    public static function vars(Repository $repo, ?array $viewer, string $method, array $query, array $post, string $token): array
    {
        $query = self::query($query);
        $vars = match (true) {
            isset($query[self::Q_RETURN]) => self::returned($repo, $viewer, $query),
            isset($query[self::Q_RESULT]) => self::outcome($repo, $query),
            default => self::page($repo, $viewer, $method, $query, $post, $token),
        };
        if (($vars['name'] ?? '') === '') {
            $vars['name'] = ServiceName::fallback($repo);
        }
        return $vars;
    }

    /** The service's name where no add-on is at hand, as the page's title when the page itself could not be built. */
    public static function fallbackName(): string
    {
        try {
            return ServiceName::fallback(Module::repository());
        } catch (\Throwable) {
            return ServiceName::NEUTRAL;
        }
    }

    /**
     * The query under today's names: one that comes with the names of a version before 0.5.0 is
     * read as the same request.
     *
     * @param array<array-key, mixed> $query
     * @return array<array-key, mixed>
     */
    public static function query(array $query): array
    {
        foreach (self::EARLIER as $earlier => $name) {
            if (isset($query[$earlier]) && !isset($query[$name])) {
                $query[$name] = $query[$earlier];
            }
            unset($query[$earlier]);
        }
        return $query;
    }

    /**
     * Is this request the browser coming back from the client's site?
     *
     * @param array<array-key, mixed> $query
     */
    public static function returning(array $query): bool
    {
        return isset(self::query($query)[self::Q_RETURN]);
    }

    /**
     * The browser came back from the client's site: with the key, or declined. No session is needed.
     * The answer is always a redirect: to the add-on's own page for a viewer who may see it, else to
     * the outcome page, so that the key does not stay in the address bar.
     *
     * @param array{clientId: int, permitted: bool}|null $viewer
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     */
    private static function returned(Repository $repo, ?array $viewer, array $query): array
    {
        $settings = Settings::load($repo);
        $token = self::text($query[self::Q_RETURN] ?? null);
        $actor = $viewer !== null ? 'client:' . $viewer['clientId'] : 'client';
        $engine = Module::engine($repo, $settings, null, $actor);
        if (isset($query[self::Q_REJECTED])) {
            $return = $engine->rejectApproval($token, $actor, self::text($query['error'] ?? null) === 'disabled');
        } else {
            $return = $engine->completeApproval($token, self::text($query['site_url'] ?? null), self::text($query['user_login'] ?? null), self::text($query['password'] ?? null), $actor, self::notActive($repo, $settings, $token));
        }
        $to = ['m' => Module::NAME];
        if ($return->addonRowId !== null && self::mayOpen($repo, $settings, $viewer, $return->addonRowId)) {
            $to['addon'] = $return->addonRowId;
        } else {
            $to[self::Q_RESULT] = hash('sha256', $token);
        }
        if ($return->result !== ApprovalReturn::RUNNING) {
            // A connection still running is said by the page itself, for as long as it runs; a note in the address would outlive it.
            $to[self::Q_NOTE] = $return->result;
        }
        if ($return->keyLeft) {
            $to[self::Q_KEY] = 'left';
        }
        return ['available' => true, 'outcomeOnly' => true, 'redirect' => self::link($repo, $to)] + self::NOTHING;
    }

    /**
     * How a return ended, for a browser without a session: the site's address and state, and a note.
     * Found by the hash of the token, which only the browser that brought the token was given.
     *
     * @param array<array-key, mixed> $query
     * @return array<string, mixed>
     */
    private static function outcome(Repository $repo, array $query): array
    {
        $hash = self::text($query[self::Q_RESULT] ?? null);
        $approval = preg_match('/^[0-9a-f]{64}$/', $hash) === 1 ? $repo->approval($hash) : null;
        $addonRowId = $approval === null ? 0 : (int) self::text($approval['addon_row_id'] ?? null);
        $site = $approval === null ? null : $repo->careSite($addonRowId);
        if ($site !== null && self::text($site['state'] ?? null) === State::Connecting->value) {
            // As on the add-on's own page: a connection that was cut off halfway ends here, so the page does not say it runs for ever.
            Module::engine($repo, Settings::load($repo), null, 'client')->expireApproval($addonRowId, 'client');
            $site = $repo->careSite($addonRowId) ?? $site;
        }
        $name = $approval === null ? ServiceName::fallback($repo) : ServiceName::of($repo, $repo->addonRow($addonRowId));
        return [
            'available' => true,
            'outcomeOnly' => true,
            'address' => $site === null ? '' : self::text($site['site_url'] ?? null),
            'message' => $site === null ? '' : self::outcomeMessage($repo, $addonRowId, $site),
            'note' => self::note($query, $name),
            'name' => $name,
        ] + self::NOTHING;
    }

    /**
     * The state sentence of the outcome page: what the client's panel would say, so that care that
     * is not active is not told to connect. A row that is gone from the billing system leaves the
     * bare state.
     *
     * @param array<string, mixed> $site
     */
    private static function outcomeMessage(Repository $repo, int $addonRowId, array $site): string
    {
        $row = $repo->addonRow($addonRowId);
        $running = Panels::running($repo, $site);
        return Panels::reachable($repo, $addonRowId, $row !== null ? Panels::clientMessage($repo, $row, $site, $running) : Panels::stateMessage(State::tryFrom(self::text($site['state'] ?? null)), Panels::reason($site), $running));
    }

    /**
     * @param array{clientId: int, permitted: bool}|null $viewer
     * @param array<array-key, mixed> $query
     * @param array<array-key, mixed> $post
     * @return array<string, mixed>
     */
    private static function page(Repository $repo, ?array $viewer, string $method, array $query, array $post, string $token): array
    {
        $settings = Settings::load($repo);
        $addonRowId = self::id($query['addon'] ?? null);
        if ($viewer === null || !$viewer['permitted'] || $addonRowId === null || $settings->dryRun) {
            return self::NOTHING;
        }
        $row = $repo->addonRow($addonRowId);
        $site = $repo->careSite($addonRowId);
        if ($row === null || $row->clientId !== $viewer['clientId'] || !in_array($row->addonId, $settings->careAddonIds, true)) {
            return self::NOTHING;
        }
        $name = ServiceName::of($repo, $row);
        if (!$row->active()) {
            return ['name' => $name] + self::inactive($repo, $row, $site);
        }
        if (self::noDomain($row, $site)) {
            return ['available' => true, 'waiting' => true, 'message' => self::NO_DOMAIN, 'name' => $name] + self::NOTHING;
        }
        if ($site === null) {
            // Billed and not provisioned yet (the next daily check does it): what the client's panel says, and nothing to press.
            return ['available' => true, 'address' => $row->siteUrl(), 'message' => Panels::clientMessage($repo, $row, null), 'name' => $name] + self::NOTHING;
        }
        $actor = 'client:' . $viewer['clientId'];
        if (self::text($site['state'] ?? null) === State::Connecting->value) {
            // An approval never given, or a connection cut off halfway, ends here: the client gets the prompt again.
            Module::engine($repo, $settings, null, $actor)->expireApproval($addonRowId, $actor);
            $site = $repo->careSite($addonRowId) ?? $site;
        }
        $address = self::text($site['site_url'] ?? null);
        $error = '';
        $notice = '';
        $redirect = '';
        $continue = '';
        // A typed address that was refused (not on the service, only redirecting, not the site's own): the page stays
        // as a fresh load would show it, and only the field it was typed in keeps it, for the client to correct.
        $refused = false;
        $typed = '';
        // A password attempt at an address that only redirects, and that could not be followed: it ran and left the site
        // Failed there, so no other address is offered beside that.
        $stuck = false;
        // While another request is connecting the site there is nothing to press: its outcome is what counts.
        $action = $method === 'POST' && self::connectable($site) && !Panels::running($repo, $site) ? self::text($post['action'] ?? null) : '';
        if ($action === 'approve' && self::text($post['password'] ?? null) !== '') {
            // Enter pressed in a field sends the form's first button, which is the approval: a login that was typed is what the client meant.
            $action = 'connect';
        }
        if ($action === 'connect' || $action === 'approve') {
            $typed = self::text($post['address'] ?? null);
            $typed = $typed !== '' ? $typed : $address; // as the field held it
            $address = $typed;
            $url = SiteAddress::under($address, $row->domain);
            if ($url === null) {
                $error = 'That address is not part of this hosting service (' . $row->domain . '). Enter the address of a WordPress site on ' . $row->domain . ', or contact support.';
                $refused = true;
            } elseif (self::coveredBy($repo, $row, $url) !== null) {
                // One site, one care add-on: nothing is sent or stored.
                $error = self::COVERED;
                $refused = true;
            } elseif ($action === 'approve') {
                $address = $url;
                $back = Module::returnAddress($repo);
                if ($back === '') {
                    $error = self::KEY_ROUTE_OFF;
                } else {
                    $engine = Module::engine($repo, $settings, null, $actor, self::INDEX_TIMEOUT);
                    $start = $engine->startApproval($addonRowId, $back, $back . '&' . self::Q_REJECTED . '=1', $actor, $url);
                    if (!$start->ok && $start->movedTo !== null) {
                        // The address only redirects: the approval is started where the site really answers, or not at all.
                        $canonical = SiteAddress::movedWithin($start->movedTo, $url, $row->domain);
                        if ($canonical !== null && self::coveredBy($repo, $row, $canonical) !== null) {
                            // Where the address leads, another care add-on of the client already covers the site.
                            $start = new ApprovalStart(false, self::COVERED);
                            $refused = true;
                        } elseif ($canonical !== null) {
                            $address = $canonical;
                            $start = $engine->startApproval($addonRowId, $back, $back . '&' . self::Q_REJECTED . '=1', $actor, $canonical);
                        }
                        if (!$start->ok && $start->movedTo !== null) {
                            $start = new ApprovalStart(false, self::moved($address, $start->movedTo, $row->domain));
                            $refused = true;
                        }
                    }
                    if (!$start->ok && $start->answersAt !== null) {
                        // Nothing was started or stored: the site reports another address as its own.
                        $start = new ApprovalStart(false, self::answersAt($address, $start->answersAt));
                        $refused = true;
                    }
                    if ($start->ok && $start->url !== null && $start->note === '') {
                        $redirect = $start->url;
                    } elseif ($start->ok && $start->url !== null) {
                        // Something of an earlier attempt could not be removed on the site: the client reads that before they go.
                        $notice = $start->note;
                        $continue = $start->url;
                    } elseif ($start->ok) {
                        $notice = $start->message;
                    } else {
                        $error = $start->message;
                    }
                }
            } else {
                $address = $url;
                $username = trim(self::text($post['username'] ?? null));
                $password = self::text($post['password'] ?? null);
                $elsewhere = null;
                if ($username === '' || $password === '') {
                    $error = 'Enter the administrator username and password of the site.';
                } elseif (($elsewhere = self::notItsOwn($repo, $settings, $actor, $url, $row->domain)) !== null) {
                    // Nothing is sent or stored: the site reports another address as its own.
                    $error = $elsewhere;
                    $refused = true;
                } else {
                    $engine = Module::engine($repo, $settings, null, $actor);
                    $login = new Login($username, $password);
                    $outcome = $engine->connect($addonRowId, $login, $actor, $url);
                    $canonical = !$outcome->ok && $outcome->movedTo !== null ? SiteAddress::movedWithin($outcome->movedTo, $url, $row->domain) : null;
                    $left = '';
                    if ($canonical !== null) {
                        // The address only redirects, and the login was not sent to it: connect where the site really
                        // answers. What the first attempt could not remove on the site goes with it.
                        $address = $canonical;
                        $left = $outcome->note;
                        // Where the address leads, another care add-on of the client may already cover the site.
                        $elsewhere = self::coveredBy($repo, $row, $canonical) !== null ? self::COVERED : self::notItsOwn($repo, $settings, $actor, $canonical, $row->domain);
                        if ($elsewhere === null) {
                            $outcome = $engine->connect($addonRowId, $login, $actor, $canonical, $left);
                        }
                    }
                    $site = $repo->careSite($addonRowId) ?? $site;
                    if ($elsewhere !== null) {
                        $error = $elsewhere;
                        $notice = $left;
                        $refused = true;
                    } elseif ($outcome->ok) {
                        $notice = $outcome->note;
                    } elseif (!$outcome->attempted) {
                        // The login was not used and is not kept: another request had the site, or it is no longer waiting.
                        $error = self::connectable($site) ? 'Your site is being connected right now. Try again in a minute.' : '';
                        $notice = $left;
                    } elseif ($outcome->movedTo !== null) {
                        $error = self::moved($address, $outcome->movedTo, $row->domain);
                        $notice = $outcome->note;
                        $address = $url;
                        $stuck = true;
                    } else {
                        $reason = (string) Panels::reason($site);
                        // A reason that already says it is tried again (a fault on our side) leaves nothing for the client to correct,
                        // and one that is not on the retry list (nothing can be retried) gets no promise at all;
                        // what could not be removed on the site may follow that sentence in the reason.
                        $promised = str_contains($reason, trim(CareEngine::RETRY_PROMISE)) || self::text($site['retry_after'] ?? null) === '';
                        $error = ($reason !== '' ? $reason : 'Your site could not be connected.') . ($promised ? '' : ' We will try again once a day for a week with the login you gave; you can also correct it below and try now.');
                    }
                }
            }
        }
        $site = $repo->careSite($addonRowId) ?? $site;
        $state = State::tryFrom(self::text($site['state'] ?? null));
        $running = Panels::running($repo, $site);
        $form = self::connectable($site) && !$running;
        if ($refused || ($error === '' && $redirect === '' && $continue === '' && !$form)) {
            $address = self::text($site['site_url'] ?? null); // what is stored now, not what was typed
        }
        $offer = new RouteOffer(false, '');
        if ($form && $redirect === '' && $continue === '' && !$stuck) {
            [$offer, $address, $moved] = self::offer($repo, $settings, $actor, $addonRowId, $address, $row->domain);
            $notice = trim($notice . ' ' . ($moved === $error || $error === self::COVERED ? '' : $moved)); // not said twice, and not beside the refusal of a covered site
        }
        return [
            'available' => true,
            'waiting' => self::connectable($site),
            'address' => $address,
            // The one address field of the form: what was typed when it was refused, for the client to correct.
            'fieldAddress' => $refused ? $typed : $address,
            'message' => Panels::reachable($repo, $addonRowId, Panels::stateMessage($state, Panels::reason($site), $running)),
            'lastSync' => Admin\Listing::date(self::text($site['last_sync'] ?? null)),
            'form' => $form,
            'keyRoute' => $offer->keyRoute,
            // Why the key route is not offered; not said a second time when the error above already is that sentence.
            'keyReason' => $offer->keyRoute || $offer->reason === $error || $error === self::COVERED ? '' : $offer->reason,
            'wordfence' => $offer->wordfence,
            // Without a Care Account Email no care account is made, and the client is not told of one.
            'careAccount' => $settings->careEmail !== '',
            'error' => $error,
            'notice' => $notice,
            'note' => self::note($query, $name),
            'name' => $name,
            'redirect' => $redirect,
            'continue' => $continue,
            'token' => $token,
            'addonRowId' => $addonRowId,
            'formAddress' => 'index.php?m=' . Module::NAME . '&addon=' . $addonRowId,
        ] + self::NOTHING;
    }

    /**
     * Which route the site at $address is offered, read from its public index with a short wait.
     * When that address only sends visitors on to another host under the same service (the "www."
     * form, say), the offer is read there and that address is the one the forms are given.
     *
     * @return array{RouteOffer, string, string} the offer, the address for the forms, and a sentence when the address changed
     */
    private static function offer(Repository $repo, Settings $settings, string $actor, int $addonRowId, string $address, string $domain): array
    {
        $url = SiteAddress::under($address, $domain);
        if ($url === null) {
            return [new RouteOffer(false, ''), $address, ''];
        }
        if (Module::returnAddress($repo) === '' || $settings->careEmail === '') {
            // The host's own set-up rules the approval out (staff read why on the module's page): the client
            // is offered the password way with no warning, and the site is not asked.
            return [new RouteOffer(false, ''), $address, ''];
        }
        $engine = Module::engine($repo, $settings, null, $actor, self::INDEX_TIMEOUT);
        $offer = $engine->routeOffer($addonRowId, $url);
        if ($offer->answersAt !== null) {
            $sentence = self::answersAt($url, $offer->answersAt);
            $own = SiteAddress::under($offer->answersAt, $domain);
            $there = $own !== null ? $engine->routeOffer($addonRowId, $own) : null;
            if ($there !== null && $there->keyRoute && $there->answersAt === null) {
                // The stored address is not the site's own (as 0.2.0 could leave it): the approval is offered for the
                // address the site answers at, so that the client corrects it without giving us a password.
                return [$there, $address, $sentence];
            }
            return [new RouteOffer(false, $sentence), $address, ''];
        }
        if ($offer->movedTo === null) {
            return [$offer, $address, ''];
        }
        $canonical = SiteAddress::movedWithin($offer->movedTo, $url, $domain);
        if ($canonical !== null) {
            $there = $engine->routeOffer($addonRowId, $canonical);
            if ($there->answersAt !== null) {
                return [new RouteOffer(false, self::answersAt($canonical, $there->answersAt)), $address, ''];
            }
            if ($there->movedTo === null) {
                return [$there, $canonical, 'Your site sends visitors on to ' . (string) parse_url($canonical, PHP_URL_HOST) . ', so that address is used.'];
            }
            return [new RouteOffer(false, self::moved($canonical, $there->movedTo, $domain)), $address, ''];
        }
        return [new RouteOffer(false, self::moved($url, $offer->movedTo, $domain)), $address, ''];
    }

    /**
     * What the client is told when their site's address only redirects and that could not be followed:
     * the target is off the service or plain http, or (already followed once) the site keeps moving.
     * Either way nothing they can type will work.
     */
    private static function moved(string $from, string $movedTo, string $domain): string
    {
        if (str_starts_with(strtolower($movedTo), 'https://') && SiteAddress::under($movedTo, $domain) !== null) {
            return 'Your site\'s address keeps sending visitors on to another address, so it cannot be connected here. Please contact support.';
        }
        return 'The site at ' . (string) parse_url($from, PHP_URL_HOST) . ' sends visitors on to ' . (string) parse_url($movedTo, PHP_URL_HOST) . ', which is not an https:// address on this hosting service, so it cannot be connected here. Please contact support.';
    }

    /**
     * What the client is told when $url is on the host of the WordPress site but is not an address
     * that site reports as its own (a folder with no WordPress of its own reads the main site's
     * index): the address to enter instead. Staff read the same.
     */
    public static function answersAt(string $url, string $answersAt): string
    {
        return 'The WordPress site at ' . $url . ' answers at ' . $answersAt . ', so please enter that address instead.';
    }

    /**
     * Why $url is not connected with a login, read from the site's public index with a short wait
     * (where $url only redirects within the service, the index where it leads): answersAt()'s
     * sentence, or null when the site calls $url its own, names no address or could not be read.
     * The client's page and the staff Connect Site form both ask it before a login is sent.
     */
    public static function notItsOwn(Repository $repo, Settings $settings, string $actor, string $url, string $domain): ?string
    {
        $answersAt = Module::engine($repo, $settings, null, $actor, self::INDEX_TIMEOUT)->answersElsewhere($url, $domain);
        return $answersAt === null ? null : self::answersAt($url, $answersAt);
    }

    /**
     * Does the add-on's hosting service have no domain while its site waits to be connected (or has no
     * care site, since such an add-on is not provisioned)? Then nothing can be connected for it.
     *
     * @param array<string, mixed>|null $site
     */
    public static function noDomain(AddonRow $row, ?array $site): bool
    {
        return $row->siteUrl() === '' && ($site === null || self::connectable($site));
    }

    /**
     * The add-on row of another care site of the same client, not Removed, at the same address as
     * $url (SiteAddress::same()), or null: one site is covered by one care add-on.
     */
    public static function coveredBy(Repository $repo, AddonRow $row, string $url): ?int
    {
        foreach ($repo->careSites() as $other) {
            $id = (int) ($other['addon_row_id'] ?? 0);
            // A care add-on covers a site once it is connected, or is being connected: one that still waits covers nothing,
            // or two add-ons that start at the same default address would keep each other from it.
            if ($id !== $row->id && (int) ($other['client_id'] ?? 0) === $row->clientId && in_array($other['state'] ?? null, [State::Connecting->value, State::Connected->value, State::Suspended->value], true) && SiteAddress::same($url, self::text($other['site_url'] ?? null))) {
                return $id;
            }
        }
        return null;
    }

    /**
     * The page of an add-on its viewer may see but that is not Active on an Active hosting service:
     * the site's address and the sentence the client's panel shows, with the way to their invoices
     * when one is unpaid, and nothing to connect. An add-on the panel does not show is not shown here.
     *
     * @param array<string, mixed>|null $site
     * @return array<string, mixed>
     */
    private static function inactive(Repository $repo, AddonRow $row, ?array $site): array
    {
        if (!in_array($row->status, Panels::CLIENT_STATUSES, true)) {
            return self::NOTHING;
        }
        $address = $site !== null ? self::text($site['site_url'] ?? null) : '';
        return [
            'available' => true,
            'address' => $address !== '' ? $address : $row->siteUrl(),
            'message' => Panels::reachable($repo, $row->id, Panels::clientMessage($repo, $row, $site, Panels::running($repo, $site))),
            'lastSync' => $site !== null ? Admin\Listing::date(self::text($site['last_sync'] ?? null)) : '',
            'invoices' => $row->unpaidInvoices > 0 ? self::INVOICES : '',
        ] + self::NOTHING;
    }

    /** Why the billing system does not allow the return's add-on to be connected, or null when it does or the link is not known. */
    private static function notActive(Repository $repo, Settings $settings, string $token): ?string
    {
        $approval = $repo->approval(hash('sha256', $token));
        if ($approval === null) {
            return null;
        }
        $row = $repo->addonRow((int) self::text($approval['addon_row_id'] ?? null));
        return $row !== null && in_array($row->addonId, $settings->careAddonIds, true) && $row->active() ? null : ServiceName::of($repo, $row) . ' is not active for this site.';
    }

    /**
     * May this viewer open the add-on's own page? The same rule as the page itself.
     *
     * @param array{clientId: int, permitted: bool}|null $viewer
     */
    private static function mayOpen(Repository $repo, Settings $settings, ?array $viewer, int $addonRowId): bool
    {
        if ($viewer === null || !$viewer['permitted'] || $settings->dryRun) {
            return false;
        }
        $row = $repo->addonRow($addonRowId);
        return $row !== null && $row->clientId === $viewer['clientId'] && in_array($row->addonId, $settings->careAddonIds, true) && $row->active() && $repo->careSite($addonRowId) !== null;
    }

    /**
     * The note for the codes in the address, in the service's name; a code that is not known shows nothing.
     *
     * @param array<array-key, mixed> $query
     */
    private static function note(array $query, string $name): string
    {
        $note = self::notes($name)[self::text($query[self::Q_NOTE] ?? null)] ?? '';
        return trim($note . (self::text($query[self::Q_KEY] ?? null) === 'left' ? ' ' . self::keyLeft($name) : ''));
    }

    /** @param array<string, int|string> $params */
    private static function link(Repository $repo, array $params): string
    {
        $base = $repo->systemUrl();
        return ($base !== '' ? $base . '/' : '') . 'index.php?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /** @param array<string, mixed> $site */
    public static function connectable(array $site): bool
    {
        return in_array(State::tryFrom(self::text($site['state'] ?? null)), self::CONNECTABLE, true);
    }

    private static function id(mixed $value): ?int
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value > 0 ? (int) $value : null;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }
}
