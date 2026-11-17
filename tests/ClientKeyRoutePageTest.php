<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use WHMCS\Authentication\CurrentUser;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\SiteIndex;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\Tests\Engine\FixedSecrets;
use MainWpForWhmcs\Tests\Engine\RecordingSite;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\SiteFixtureHttp;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * The module's client page on the key route: which route is offered, the button that
 * sends the client to their own site, and the address that site sends their browser back to.
 * The return requests are the redirects recorded on the bench (approve-redirect*.json).
 */
final class ClientKeyRoutePageTest extends TestCase
{
    private const SITE = 'https://clientsite.example';
    private const KEY = 'BenchKey0123456789ABCDEF';

    private MemoryRepository $repo;
    private RecordingSite $site;
    private FixtureHttp $http;
    private FixedSecrets $secrets;
    /** @var list<string> */
    private array $redirects = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/mainwp/mainwp.php';
    }

    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'access' => '1', 'care_email' => 'wordpress@care.example.com', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->addonModules = [16 => 'mainwp'];
        $this->repo->adminRoles = [1 => 1];
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[67] = ['addon_row_id' => 67, 'service_id' => 1176, 'client_id' => 540, 'site_url' => self::SITE, 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null];
        $repo = $this->repo;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        $this->site = new RecordingSite();
        $site = $this->site;
        Module::$siteFactory = static fn (): RecordingSite => $site;
        $this->http = (new FixtureHttp())->on('POST', 'sites/add', 'sites-add-with-client');
        $http = $this->http;
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        $this->secrets = new FixedSecrets();
        $secrets = $this->secrets;
        Module::$secretsFactory = static fn (): FixedSecrets => $secrets;
        $this->redirects = [];
        Module::$redirector = function (string $url): void {
            $this->redirects[] = $url;
        };
        \WHMCS\Session::$values['uid'] = 540;
        $_GET = ['m' => 'mainwp', 'addon' => '67'];
        $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        Module::$repositoryFactory = null;
        Module::$siteFactory = null;
        Module::$httpFactory = null;
        Module::$secretsFactory = null;
        Module::$redirector = null;
        $_GET = $_POST = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * @param array<string, string>|null $get
     * @return array{page: array<string, mixed>, html: string}
     */
    private function open(?array $get = null): array
    {
        if ($get !== null) {
            $_GET = $get;
        }
        $page = \mainwp_clientarea([]);
        return ['page' => $page, 'html' => Template::render($page['templatefile'] . '.tpl', $page['vars'])];
    }

    /** A form on the add-on's own page is posted. @param array<string, string> $post */
    private function post(array $post): string
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $post + ['token' => 'test-token'];
        $html = $this->open(['m' => 'mainwp', 'addon' => '67'])['html'];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_POST = [];
        return $html;
    }

    /** Presses "Approve on my site" and returns the one-time token of the return address. */
    private function approve(): string
    {
        $this->post(['action' => 'approve', 'address' => self::SITE]);
        self::assertCount(1, $this->redirects, 'the client is sent to their site');
        $this->redirects = [];
        $this->site->calls = [];
        return $this->secrets->tokens[array_key_last($this->secrets->tokens)];
    }

    /**
     * The return request as the client's site makes the browser send it: the query of a redirect
     * recorded on the bench, with this approval's token in place of the recorded one.
     *
     * @param array<string, string> $change
     * @return array<string, string>
     */
    private function recordedReturn(string $fixture, string $token, array $change = []): array
    {
        $location = SiteFixtureHttp::fixture($fixture)->header('location');
        parse_str((string) parse_url((string) $location, PHP_URL_QUERY), $query);
        self::assertSame('mainwp', $query['m'] ?? null, 'the site sends the browser back to the module\'s own page');
        // Recorded on 10 November 2026 with the query names of that version; replayed with today's, which the site is now given.
        $declined = isset($query['mainwp_rejected']);
        unset($query['mainwp_return'], $query['mainwp_rejected']);
        $query['care_return'] = $token;
        if ($declined) {
            $query['care_rejected'] = '1';
        }
        if (isset($query['site_url'])) {
            $query['site_url'] = self::SITE;
        }
        /** @var array<string, string> $query */
        return array_merge($query, $change);
    }

    /** Opens the address a redirect went to. @return array{page: array<string, mixed>, html: string} */
    private function follow(string $redirect): array
    {
        self::assertStringStartsWith('https://billing.example.com/index.php?', $redirect);
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $this->open($query);
    }

    private function everythingKept(): string
    {
        return json_encode([$this->repo->log, $this->repo->careSites, $this->repo->approvals, $this->redirects, WhmcsCalls::$activity], JSON_THROW_ON_ERROR);
    }

    public function testTheKeyRouteIsOfferedFirstWithWhatItLeavesOnTheSiteAndTheFormBelow(): void
    {
        ['page' => $page, 'html' => $html] = $this->open();

        self::assertTrue($page['requirelogin']);
        self::assertSame(['index ' . self::SITE], $this->site->calls, 'read from the site\'s public index, with no login');
        self::assertStringContainsString('name="action" value="approve"', $html);
        self::assertStringContainsString('Approve on my site', $html);
        self::assertStringContainsString('your password is never given to us', $html);
        self::assertStringContainsString('With your approval we install a plugin on your site that lets us look after it, and create one administrator account for care', $html, 'the plugin by what it does, never by the name of the tool behind it');
        self::assertStringContainsString('its name begins with "wpcare"', $html);
        self::assertStringContainsString('stays on your site when care ends', $html);
        self::assertStringContainsString('delete it then under Users', $html);
        self::assertStringNotContainsString('Wordfence', $html);
        self::assertLessThan(strpos($html, 'name="username"'), strpos($html, 'Approve on my site'), 'the password form is the fallback, below');
    }

    public function testBothWaysAreOneFormThatAsksForTheSiteAddressOnce(): void
    {
        $html = $this->open()['html'];

        self::assertSame(1, substr_count($html, '<form'), 'one form');
        self::assertSame(1, substr_count($html, 'name="address"'), 'the address is asked for once');
        self::assertSame(1, substr_count($html, 'The address of the WordPress site on this hosting service, a subdomain or a folder under it.'), 'with its help once');
        self::assertSame(1, substr_count($html, 'name="token" value="test-token"'));
        self::assertStringContainsString('<form method="post" action="index.php?m=mainwp&amp;addon=67" autocomplete="off">', $html);
        self::assertStringContainsString('<button type="submit" name="action" value="approve" class="btn btn-primary">Approve on my site</button>', $html);
        self::assertStringContainsString('<button type="submit" name="action" value="connect" class="btn btn-primary">Connect my site</button>', $html);
        self::assertStringNotContainsString('type="hidden" name="action"', $html, 'the button pressed says which way');
        $order = array_map(static fn (string $needle): int|false => strpos($html, $needle), ['name="address"', '<h3>Approve the connection on your own site</h3>', 'your password is never given to us', 'Approve on my site</button>', '<h3 class="mt-4">Or connect with an administrator username and password</h3>', 'name="username"', 'name="password"', 'We log in to your site once', 'Connect my site</button>']);
        self::assertNotContains(false, $order);
        $sorted = $order;
        sort($sorted);
        self::assertSame($sorted, $order, 'the address at the top, then the approval, then the password way');
        $this->labelsPointAtTheirFields($html);
    }

    public function testThePasswordWayAloneAsksForTheAddressOnceToo(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null);
        $html = $this->open()['html'];

        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertSame([1, 1], [substr_count($html, '<form'), substr_count($html, 'name="address"')]);
        self::assertStringContainsString('<button type="submit" name="action" value="connect" class="btn btn-primary">Connect my site</button>', $html);
        $this->labelsPointAtTheirFields($html);
    }

    /** Every label names the id of a field on the page, and no id is used twice. */
    private function labelsPointAtTheirFields(string $html): void
    {
        preg_match_all('/<label for="([^"]+)"/', $html, $labels);
        preg_match_all('/ id="([^"]+)"/', $html, $ids);
        self::assertSame(['care-address', 'care-username', 'care-password'], $labels[1]);
        self::assertSame(array_unique($ids[1]), $ids[1], 'no id twice');
        foreach ($labels[1] as $for) {
            self::assertMatchesRegularExpression('/<input [^>]*id="' . preg_quote($for, '/') . '"/', $html);
        }
    }

    public function testWhereWordfenceRunsTheClientIsToldWhatAlertToExpect(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', self::SITE . '/wp-admin/authorize-application.php', true);

        $html = $this->open()['html'];

        self::assertStringContainsString('Approve on my site', $html);
        self::assertStringContainsString('Your site runs Wordfence', $html);
        self::assertStringContainsString('"created outside of WordPress"', $html);
        self::assertSame(1, substr_count($html, 'Your site runs Wordfence'), 'once, for both forms');
        self::assertLessThan(strpos($html, '<h3>Approve the connection on your own site</h3>'), strpos($html, 'Your site runs Wordfence'), 'above both forms, since both make the care account');
    }

    public function testASiteThatOffersNoKeyGetsThePasswordFormWithTheReason(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null, true);

        $html = $this->open()['html'];

        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringContainsString('Wordfence has switched application passwords off on your site', $html);
        self::assertStringContainsString('name="username"', $html);
        self::assertGreaterThan(strpos($html, 'name="username"'), strpos($html, 'Your site runs Wordfence. It will report the account we create as an administrator "created outside of WordPress". That alert is expected.'), 'the password route makes the care account too, so the alert is said with its form');
    }

    public function testASiteThatCannotBeReadGetsThePasswordFormWithTheSitesReason(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached at clientsite.example (Connection timed out). Check the address and try again.');

        $html = $this->open()['html'];

        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringContainsString('The site could not be reached at clientsite.example', $html);
        self::assertStringContainsString('name="username"', $html);
    }

    public function testASiteThatMovedToItsWwwFormIsReadThereAndThatAddressIsOffered(): void
    {
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://www.clientsite.example');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $html = $this->open()['html'];

        self::assertSame(['index ' . self::SITE, 'index https://www.clientsite.example'], $site->calls);
        self::assertStringContainsString('Your site sends visitors on to www.clientsite.example, so that address is used.', $html);
        self::assertStringContainsString('name="address" value="https://www.clientsite.example"', $html);
        self::assertStringContainsString('Approve on my site', $html);
        self::assertSame(self::SITE, $this->repo->careSites[67]['site_url'], 'nothing is stored until the client presses a button');
    }

    public function testAMoveToAnAddressOutsideTheServiceIsNotFollowed(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to elsewhere.example. Enter that address as the site address and try again.', 'https://elsewhere.example');

        $html = $this->open()['html'];

        self::assertSame(['index ' . self::SITE], $this->site->calls);
        self::assertStringContainsString('sends visitors on to elsewhere.example, which is not an https:// address on this hosting service, so it cannot be connected here', $html);
        self::assertStringNotContainsString('Use that address', $html, 'the client is not told to type an address the form would refuse');
        self::assertStringNotContainsString('Approve on my site', $html);

        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example. Use that address as the site address.', 'http://www.clientsite.example');
        self::assertStringContainsString('which is not an https:// address on this hosting service', $this->open()['html'], 'a plain http target is not followed either');
    }

    public function testWithoutACareEmailOrAnHttpsSystemUrlOnlyThePasswordFormIsOfferedWithNoWarning(): void
    {
        // Both are about the host's own set-up, which staff read on the module's page: the client has nothing to do about it.
        $this->repo->settings['care_email'] = '';
        $html = $this->open()['html'];
        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringNotContainsString('alert', $html);
        self::assertStringNotContainsString('not available at the moment', $html);
        self::assertStringContainsString('name="username"', $html);
        self::assertSame([], $this->site->calls, 'the site is not asked for a route the module cannot run');

        $this->repo->settings['care_email'] = 'wordpress@care.example.com';
        $this->repo->systemUrl = 'http://billing.example.com';
        $html = $this->open()['html'];
        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringNotContainsString('alert', $html);
        self::assertStringContainsString('name="username"', $html);
        self::assertSame([], $this->site->calls);
    }

    public function testApprovingASiteAnotherCareAddOnOfTheClientCoversIsRefusedBeforeAnythingIsSent(): void
    {
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://www.clientsite.example/', 'dashboard_client_id' => 1, 'dashboard_site_id' => 5, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null];
        $this->site->calls = [];

        $html = $this->post(['action' => 'approve', 'address' => 'clientsite.example']);

        self::assertStringContainsString('<div class="alert alert-danger">This site is already covered by another care add-on of yours. Enter the address of another site on this service, or contact support.</div>', $html);
        self::assertSame([[], [], []], [$this->redirects, $this->repo->approvals, $this->repo->log]);
        self::assertSame([], array_values(array_filter($this->site->calls, static fn (string $c): bool => !str_starts_with($c, 'index '))), 'nothing is sent to the site');
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
        self::assertStringContainsString('id="care-address" name="address" value="clientsite.example"', $html, 'what was typed stays to be corrected');

        $this->repo->careSites[68]['state'] = 'removed';
        $this->post(['action' => 'approve', 'address' => 'clientsite.example']);
        self::assertCount(1, $this->redirects, 'a Removed care site covers nothing');
    }

    public function testPressingApproveSendsTheBrowserToTheSitesOwnApprovalScreen(): void
    {
        $this->post(['action' => 'approve', 'address' => 'clientsite.example']);

        self::assertCount(1, $this->redirects);
        self::assertStringStartsWith(self::SITE . '/wp-admin/authorize-application.php?', $this->redirects[0]);
        parse_str((string) parse_url($this->redirects[0], PHP_URL_QUERY), $query);
        $token = $this->secrets->tokens[0];
        self::assertSame('https://billing.example.com/index.php?m=mainwp&care_return=' . $token, $query['success_url']);
        self::assertSame('https://billing.example.com/index.php?m=mainwp&care_return=' . $token . '&care_rejected=1', $query['reject_url']);
        self::assertStringStartsWith('WordPress Care (', (string) $query['app_name']);
        self::assertSame('connecting', $this->repo->careSites[67]['state']);
        self::assertSame(hash('sha256', $token), $this->repo->approvals[0]['token_hash']);
        self::assertStringNotContainsString($token, json_encode([$this->repo->log, $this->repo->approvals], JSON_THROW_ON_ERROR), 'the token is in the address the browser is sent to and nowhere else');
        self::assertSame('client:540', $this->repo->log[array_key_last($this->repo->log)]['actor']);
    }

    public function testAnAddressOutsideTheServiceIsNotApproved(): void
    {
        $html = $this->post(['action' => 'approve', 'address' => 'https://example.com']);

        self::assertStringContainsString('not part of this hosting service', $html);
        self::assertSame([[], []], [$this->redirects, $this->repo->approvals]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
    }

    /** The site's index, wherever it is read, reports the main address as its own: a folder under it holds no WordPress. */
    private function aSiteThatAnswersAtTheMainAddress(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', self::SITE . '/wp-admin/authorize-application.php', false, self::SITE, self::SITE);
    }

    public function testAFolderWithNoWordPressOfItsOwnIsNotApprovedAndTheClientIsToldWhichAddressToEnter(): void
    {
        // Before the fix, the approval started on the main site and the folder was stored.
        $this->aSiteThatAnswersAtTheMainAddress();

        $html = $this->post(['action' => 'approve', 'address' => 'https://clientsite.example/nothing-here']);

        self::assertStringContainsString('The WordPress site at https://clientsite.example/nothing-here answers at https://clientsite.example, so please enter that address instead.', $html);
        self::assertSame([[], [], []], [$this->redirects, $this->repo->approvals, $this->repo->log]);
        self::assertSame([self::SITE, 'provisioned'], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state']]);
    }

    public function testAFolderWithNoWordPressOfItsOwnIsNotConnectedWithAPasswordAndTheLoginIsNotSent(): void
    {
        $this->aSiteThatAnswersAtTheMainAddress();

        $html = $this->post(['action' => 'connect', 'address' => 'clientsite.example/nothing-here', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('The WordPress site at https://clientsite.example/nothing-here answers at https://clientsite.example, so please enter that address instead.', $html);
        self::assertSame([], array_values(array_filter($this->site->calls, static fn (string $c): bool => !str_starts_with($c, 'index '))), 'only the public index was read');
        self::assertSame([self::SITE, 'provisioned'], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state']]);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertSame([[], []], [$this->http->sent, $this->repo->log]);
        self::assertStringNotContainsString('Secret1', $html);
    }

    public function testASiteThatReportsAnotherHostIsStillOfferedItsApprovalAsBefore(): void
    {
        // A domain alias served without a redirect: WordPress names another host as its own. The check
        // refuses a folder of the typed host only, so such a site is handled as it was before.
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', self::SITE . '/wp-admin/authorize-application.php', false, 'https://elsewhere.example', 'https://elsewhere.example');

        $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertCount(1, $this->redirects, 'the approval starts');
        self::assertSame([self::SITE, 'connecting'], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state']]);
    }

    public function testAWordPressInAFolderStillConnects(): void
    {
        $this->site->index = new SiteIndex(self::SITE . '/blog/wp-json/', self::SITE . '/blog/wp-admin/authorize-application.php', false, self::SITE . '/blog', self::SITE . '/blog');

        $this->post(['action' => 'approve', 'address' => 'clientsite.example/blog']);

        self::assertCount(1, $this->redirects);
        self::assertSame(self::SITE . '/blog', $this->repo->careSites[67]['site_url']);
    }

    public function testAStoredAddressThatIsNotTheSitesOwnIsSaidOnTheFreshPage(): void
    {
        // As left by 0.2.0 on 13 November 2026: the folder stored as the site.
        $this->repo->careSites[67]['site_url'] = self::SITE . '/nothing-here';
        $this->aSiteThatAnswersAtTheMainAddress();

        $html = $this->open()['html'];

        self::assertStringContainsString('The WordPress site at https://clientsite.example/nothing-here answers at https://clientsite.example, so please enter that address instead.', $html);
        self::assertStringContainsString('Approve on my site', $html, 'the site there offers the approval: the client corrects the address without giving us a password');
        self::assertStringContainsString('name="action" value="connect"', $html);
        self::assertSame(1, substr_count($html, 'so please enter that address instead'));
        self::assertSame([], $this->redirects);

        $html = $this->post(['action' => 'approve', 'address' => self::SITE . '/nothing-here']);
        self::assertSame(1, substr_count($html, 'so please enter that address instead'), 'said once when the stored address is posted again');
        self::assertSame([], $this->redirects);

        $this->post(['action' => 'approve', 'address' => self::SITE]);
        self::assertCount(1, $this->redirects, 'the corrected address is approved');
        self::assertSame(self::SITE, $this->repo->careSites[67]['site_url']);
    }

    public function testAFolderBehindARedirectToTheWwwFormIsRefusedBeforeAnythingIsStored(): void
    {
        // Both reviews of 0.2.1: the bare domain only redirected, so the folder was connected (and stored) before it was checked.
        $site = new \MainWpForWhmcs\Tests\Engine\WwwMainSite();
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $html = $this->post(['action' => 'connect', 'address' => 'clientsite.example/nothing-here', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('The WordPress site at https://clientsite.example/nothing-here answers at https://www.clientsite.example, so please enter that address instead.', $html);
        self::assertSame([], array_values(array_filter($site->calls, static fn (string $c): bool => !str_starts_with($c, 'index '))), 'no login was sent');
        self::assertSame([self::SITE, 'provisioned', null], [$this->repo->careSites[67]['site_url'], $this->repo->careSites[67]['state'], $this->repo->careSites[67]['last_error']]);
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
        self::assertSame([], $this->repo->log);
        self::assertStringContainsString('<p><strong>https://www.clientsite.example</strong></p>', $html, 'as a fresh load shows the stored address, which only redirects');
        self::assertStringContainsString('id="care-address" name="address" value="clientsite.example/nothing-here"', $html);
    }

    public function testAfterARefusedAddressThePageIsWholeAndOnlyTheFieldTypedInKeepsIt(): void
    {
        // Before the fix, the refused address headed the page and the approval form was gone until a reload.
        foreach (['approve', 'connect'] as $action) {
            $html = $this->post(['action' => $action, 'address' => 'https://example.com', 'username' => 'owner', 'password' => 'Secret1']);

            self::assertStringContainsString('That address is not part of this hosting service', $html, $action);
            self::assertStringContainsString('<p><strong>' . self::SITE . '</strong></p>', $html, $action . ': the stored address heads the page');
            self::assertStringNotContainsString('<strong>https://example.com</strong>', $html, $action);
            self::assertStringContainsString('Approve on my site', $html, $action . ': both routes, as on a fresh load');
            self::assertStringContainsString('name="action" value="connect"', $html, $action);
            self::assertStringContainsString('id="care-address" name="address" value="https://example.com"', $html, $action . ': the client can correct what they typed');
            self::assertSame(1, substr_count($html, 'name="address"'), $action);
        }
        self::assertSame([[], 'provisioned'], [$this->redirects, $this->repo->careSites[67]['state']]);
    }

    public function testAfterAnAddressRefusedAsNotTheSitesOwnThePageIsWholeToo(): void
    {
        $this->aSiteThatAnswersAtTheMainAddress();

        $html = $this->post(['action' => 'approve', 'address' => 'clientsite.example/nothing-here']);

        self::assertStringContainsString('<p><strong>' . self::SITE . '</strong></p>', $html);
        self::assertStringContainsString('Approve on my site', $html);
        self::assertStringContainsString('id="care-address" name="address" value="clientsite.example/nothing-here"', $html);
        self::assertSame(1, substr_count($html, 'name="address"'));
    }

    public function testThePageIsHeadedConnectOnlyWhileTheSiteWaitsToBeConnected(): void
    {
        self::assertStringContainsString('<h2>Connect your WordPress site</h2>', $this->open()['html']);

        // Before the fix, a connected site's page was headed "Connect your WordPress site".
        $this->repo->careSites[67]['state'] = 'connected';
        $html = $this->open()['html'];
        self::assertStringContainsString('<h2>Your WordPress site</h2>', $html);
        self::assertStringNotContainsString('Connect your WordPress site', $html);
    }

    public function testAnotherClientOrAUserWithoutThePermissionCannotStartAnApproval(): void
    {
        \WHMCS\Session::$values['uid'] = 600;
        self::assertStringContainsString('not available', $this->post(['action' => 'approve', 'address' => self::SITE]));

        \WHMCS\Session::$values['uid'] = 540;
        CurrentUser::$owner = false;
        CurrentUser::$permissions = ['products'];
        self::assertStringContainsString('not available', $this->post(['action' => 'approve', 'address' => self::SITE]));

        self::assertSame([[], [], []], [$this->redirects, $this->repo->approvals, $this->site->calls]);
    }

    public function testTheRecordedReturnConnectsTheSiteAndTheBrowserLeavesTheAddressThatHoldsTheKey(): void
    {
        $token = $this->approve();

        ['page' => $page] = $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertFalse($page['requirelogin'], 'the return works from the token alone');
        self::assertSame(['installPluginWithKey ' . self::SITE . ' owner wpcarek7m2x9ab wordpress@care.example.com', 'deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame(self::KEY, $this->site->keyed[0]['key']->password);
        self::assertSame('wpcarek7m2x9ab', $this->http->sent[0]['form']['admin'], 'the dashboard connects as the care account');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame(['https://billing.example.com/index.php?m=mainwp&addon=67&care_note=connected'], $this->redirects, 'a signed-in client lands on the add-on\'s own page');
        self::assertStringNotContainsString(self::KEY, $this->everythingKept());
        self::assertStringNotContainsString(FixedSecrets::CARE_PASSWORD, $this->everythingKept());
        self::assertStringNotContainsString($token, $this->everythingKept());

        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertStringNotContainsString('Approve on my site', $html);
    }

    public function testWhatWhmcsHtmlEncodesInTheReturnAddressIsDecodedOnce(): void
    {
        $token = $this->approve();
        $query = $this->recordedReturn('approve-redirect', $token, ['user_login' => "O'Brien & Co"]);

        // WHMCS HTML-encodes request input before a module sees it.
        $this->open(array_map(static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES), $query));

        self::assertSame("O'Brien & Co", $this->site->keyed[0]['key']->username, 'the login name reaches the site as WordPress sent it');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testAReturnThatFindsTheSiteBusySaysTheApprovalWasNotUsed(): void
    {
        $token = $this->approve();
        $this->repo->lockedElsewhere = ['addon:67'];

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertStringContainsString('care_note=busy', $this->redirects[0]);
        $this->repo->lockedElsewhere = [];
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('We were busy with your site when your approval came back, so it was not used.', $html);
        self::assertStringNotContainsString('Reload', $html, 'a reload cannot bring the key back');
    }

    public function testWhatAnEarlierAttemptLeftOnTheSiteIsShownBeforeTheClientIsSentToApproveAgain(): void
    {
        $token = $this->approve();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->open($this->recordedReturn('approve-redirect', $token));
        $this->site->refuses = null;
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->redirects = [];

        $html = $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertSame([], $this->redirects, 'the browser is not sent away before the client has read it');
        self::assertStringContainsString('An application password whose name begins &quot;WordPress Care&quot; may still be on your site', $html);
        self::assertStringContainsString('Continue to your site to approve the connection', $html);
        self::assertStringContainsString('href="' . self::SITE . '/wp-admin/authorize-application.php?app_name=', $html);
        self::assertSame('connecting', $this->repo->careSites[67]['state']);
    }

    public function testWhatAnEarlierAttemptLeftIsShownAfterAPasswordConnectToo(): void
    {
        $token = $this->approve();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->open($this->recordedReturn('approve-redirect', $token));
        $this->site->refuses = null;
        $this->site->keyStays = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertStringContainsString('An application password whose name begins &quot;WordPress Care&quot; may still be on your site', $html);
    }


    public function testApprovingForAnAddressThatMovedToItsWwwFormStartsTheApprovalThere(): void
    {
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://www.clientsite.example');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertCount(1, $this->redirects);
        self::assertStringStartsWith('https://www.clientsite.example/wp-admin/authorize-application.php?', $this->redirects[0]);
        self::assertSame('https://www.clientsite.example', $this->repo->careSites[67]['site_url'], 'the approval, and the key that comes back, belong to the address the site really answers at');
    }

    public function testEnterAfterTypingALoginConnectsWithItAndDoesNotSendTheClientToApprove(): void
    {
        // A browser sends the form's first submit button when Enter is pressed in a field: that is "Approve on my site".
        $html = $this->post(['action' => 'approve', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame([], array_values(array_filter($this->redirects, static fn (string $to): bool => str_contains($to, 'authorize-application.php'))), 'nobody is sent to the approval screen');
        self::assertContains('installPlugin ' . self::SITE . ' owner', $this->site->calls, 'the login typed is used');
        self::assertStringNotContainsString('Secret1', $html);

        // With no password typed it is the approval, as before.
        $this->setUp();
        $this->post(['action' => 'approve', 'address' => self::SITE, 'username' => 'owner', 'password' => '']);
        self::assertCount(1, $this->redirects);
        self::assertStringContainsString('authorize-application.php', $this->redirects[0]);
    }

    public function testAClientIsNeverToldOfADryRun(): void
    {
        // The mode was switched to Dry Run while an approval was out: the client reads only that it was not used.
        $token = $this->approve();
        $this->repo->settings['dry_run'] = 'on';
        \WHMCS\Session::$values = [];
        CurrentUser::$loggedOut = true;

        $this->open($this->recordedReturn('approve-redirect', $token));
        $html = $this->follow($this->redirects[0])['html'];

        self::assertStringNotContainsStringIgnoringCase('dry run', $html);
        self::assertStringContainsString('The approval was not used.', $html);
    }

    public function testAnAddressThatMovesToASiteAnotherCareAddOnCoversIsNotFollowedThere(): void
    {
        // The address typed only redirects, and where it leads another care add-on of the client already covers the site.
        $this->repo->addons[68] = new AddonRow(68, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->careSites[68] = ['addon_row_id' => 68, 'service_id' => 1176, 'client_id' => 540, 'site_url' => 'https://clientsite.example/shop', 'dashboard_client_id' => 1, 'dashboard_site_id' => 5, 'state' => 'connected', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null, 'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00'];
        $covered = '<div class="alert alert-danger">This site is already covered by another care add-on of yours. Enter the address of another site on this service, or contact support.</div>';

        foreach (['approve' => [], 'connect' => ['username' => 'owner', 'password' => 'Secret1']] as $action => $login) {
            $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://clientsite.example/shop');
            Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;
            $this->redirects = [];

            $html = $this->post(['action' => $action, 'address' => self::SITE] + $login);

            self::assertStringContainsString($covered, $html, $action);
            self::assertSame([], $this->redirects, $action . ': nobody is sent to the site');
            self::assertSame([], $this->repo->approvals, $action . ': no approval is started there');
            self::assertSame([], array_values(array_filter($site->calls, static fn (string $c): bool => !str_starts_with($c, 'index ') && !str_ends_with($c, ' ' . self::SITE . ' owner'))), $action . ': nothing is installed at the covered address');
            self::assertSame(self::SITE, $this->repo->careSites[67]['site_url'], $action . ': the care site keeps its own address');
            self::assertStringNotContainsString('Secret1', $html);
            self::assertSame(1, substr_count($html, 'class="alert alert-'), $action . ': one alert, and no second one about where the address leads');
        }
    }

    public function testApprovingForAnAddressThatMovedOutsideTheServiceSaysItCannotBeConnectedHere(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to elsewhere.example.', 'https://elsewhere.example');

        $html = $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertSame([], $this->redirects);
        self::assertStringContainsString('sends visitors on to elsewhere.example, which is not an https:// address on this hosting service, so it cannot be connected here', $html);
        self::assertSame(1, substr_count($html, 'so it cannot be connected here'), 'said once: not as the error and again as the reason the key route is not offered');
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
    }

    public function testAPasswordConnectForAnAddressThatMovedToItsWwwFormConnectsThereAndSendsThePasswordNowhereElse(): void
    {
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://www.clientsite.example');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        // The index is read where the address leads before anything is stored (a folder behind the redirect is refused there).
        self::assertSame(['index ' . self::SITE, 'index https://www.clientsite.example', 'installPlugin ' . self::SITE . ' owner', 'index https://www.clientsite.example', 'installPlugin https://www.clientsite.example owner'], $site->calls, 'each address\'s public index is read before a login goes to it');
        self::assertSame(['connected', 'https://www.clientsite.example'], [$this->repo->careSites[67]['state'], $this->repo->careSites[67]['site_url']]);
        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertStringContainsString('https://www.clientsite.example', $html);
    }

    public function testAPasswordConnectForAnAddressThatMovedOutsideTheServicePromisesNoRetry(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to elsewhere.example.', 'https://elsewhere.example');

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('which is not an https:// address on this hosting service, so it cannot be connected here', $html);
        self::assertStringNotContainsString('We will try again', $html, 'the same address cannot work tomorrow either');
        self::assertNull($this->repo->careSites[67]['retry_after']);
    }

    public function testAPasswordConnectForAPlainHttpAddressKeepsNoLoginAndPromisesNoRetry(): void
    {
        $this->site->refuses = new SiteFailure(SiteFailure::NOT_HTTPS, 'The site address must be an https:// address: the login is never sent over plain HTTP. Switch the site to HTTPS, or contact support.');

        $html = $this->post(['action' => 'connect', 'address' => 'http://clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('The site address must be an https:// address', $html);
        self::assertStringNotContainsString('We will try again', $html, 'nothing can retry a plain http:// address');
        self::assertNull($this->repo->careSites[67]['retry_after']);
        self::assertNull($this->repo->careSites[67]['login_encrypted'] ?? null, 'the login typed is not kept');
    }

    public function testAPasswordAttemptThatFindsTheSiteBusySaysSoAndPromisesNothing(): void
    {
        $this->repo->careSites[67] = ['state' => 'failed', 'last_error' => 'An old reason from last week.', 'retry_after' => '2026-11-11 00:00:00'] + $this->repo->careSites[67];
        $this->repo->lockedElsewhere = ['addon:67'];

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('Your site is being connected right now. Try again in a minute.', $html);
        self::assertStringNotContainsString('We will try again once a day', $html, 'the login just typed was not kept, so no retry is promised for it');
        self::assertSame(['index ' . self::SITE, 'index ' . self::SITE], $this->site->calls, 'nothing was tried on the site; only its public index was read, before the login and for the page');
        self::assertArrayNotHasKey('login_encrypted', $this->repo->careSites[67]);
    }

    public function testWhileAConnectionRunsThePageAndThePanelsSaySoAndOfferNothingToPress(): void
    {
        // A password attempt is running in another request: Connecting with its retry armed, and the add-on taken.
        $this->repo->careSites[67] = ['state' => 'connecting', 'retry_after' => '2026-11-11 12:00:00'] + $this->repo->careSites[67];
        $this->repo->lockedElsewhere = ['addon:67'];

        $html = $this->open(['m' => 'mainwp', 'addon' => '67'])['html'];
        self::assertStringContainsString('We are connecting your site now.', $html);
        self::assertStringNotContainsString('Waiting for the connection to be approved', $html, 'nobody was asked to approve anything');
        self::assertStringNotContainsString('<form', $html);

        self::assertStringContainsString('We are connecting your site now.', \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176));
        \WHMCS\Session::$values['adminid'] = 1;
        $staff = implode('', Hooks::adminServiceFields(['id' => 1176]));
        self::assertStringContainsString('<span class="label pending">Connecting</span> (a connection is running)<br>', $staff);
        self::assertSame('connecting', $this->repo->careSites[67]['state'], 'a running connection is not ended by someone looking');
    }

    public function testAFollowedMoveDoesNotLoseWhatTheFirstAttemptCouldNotRemove(): void
    {
        $token = $this->approve();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->open($this->recordedReturn('approve-redirect', $token));
        // The site has since moved to its www form; what the key attempt left cannot be removed (MovedSite refuses both).
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://www.clientsite.example');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertStringContainsString('An application password whose name begins &quot;WordPress Care&quot; may still be on your site', $html, 'found by the first attempt, shown after the second');
        $connect = array_values(array_filter($this->repo->log, static fn (array $r): bool => $r['action'] === 'connect' && $r['outcome'] === 'done'));
        self::assertStringContainsString('For the client:', (string) end($connect)['detail']);
    }

    /** A key attempt failed and its key is held; neither the care account nor the key can be removed on the site now. */
    private function aHeldKeyThatCannotBeRemoved(): void
    {
        $token = $this->approve();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->open($this->recordedReturn('approve-redirect', $token));
        $this->site->accountStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');
    }

    public function testWhatCouldNotBeRemovedIsShownAlsoWhenTheAddressLeadsOutOfTheService(): void
    {
        $this->aHeldKeyThatCannotBeRemoved();
        $this->site->refuses = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to elsewhere.example.', 'https://elsewhere.example');

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('which is not an https:// address on this hosting service, so it cannot be connected here', $html);
        self::assertSame(1, substr_count($html, 'The account wpcarek7m2x9ab made for care may still be on your site'), 'on this page, not only on the next one');
        self::assertSame(1, substr_count($html, 'An application password whose name begins &quot;WordPress Care&quot; may still be on your site'));
        self::assertNull($this->repo->careSites[67]['login_encrypted'] ?? null, 'the login typed for an address that only redirects is not kept');
    }

    public function testWhatCouldNotBeRemovedIsShownAlsoWhenTheAddressKeepsMoving(): void
    {
        $this->aHeldKeyThatCannotBeRemoved();
        $this->site->refuses = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('keeps sending visitors on to another address', $html);
        self::assertSame(1, substr_count($html, 'The account wpcarek7m2x9ab made for care may still be on your site'), 'carried from the first attempt and shown once');
    }

    public function testWhatTheFirstAttemptCouldNotRemoveIsShownWhenTheSecondFindsTheSiteBusy(): void
    {
        $this->aHeldKeyThatCannotBeRemoved();
        $site = $this->site;
        $repo = $this->repo;
        $this->site->refuses = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');
        // Another request takes the add-on between the attempt at the first address and the one at the second.
        $this->site->during = static function () use ($repo): void {
            $repo->lockedElsewhere = ['addon:67'];
        };

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertCount(1, array_filter($site->calls, static fn (string $c): bool => str_starts_with($c, 'installPlugin ')), 'the second attempt did not run');
        self::assertStringContainsString('Your site is being connected right now. Try again in a minute.', $html);
        self::assertSame(1, substr_count($html, 'The account wpcarek7m2x9ab made for care may still be on your site'));
    }

    public function testTheOutcomePageEndsAConnectionThatDiedInsteadOfSayingItRunsForEver(): void
    {
        $token = $this->approve();
        // The return claimed the link and its request then died: the key is held, the site still Connecting, nothing runs.
        $this->repo->approvals[0]['used_at'] = '2026-11-10 11:00:00';
        $this->repo->approvals[0]['key_hash'] = hash('sha256', self::KEY);
        $this->repo->approvals[0]['key_encrypted'] = (new WhmcsEncrypter())->encrypt('{"user":"owner","key":"' . self::KEY . '","care_user":"wpcarek7m2x9ab","care_password":"x"}');
        \WHMCS\Session::$values = [];
        CurrentUser::$loggedOut = true;

        $html = $this->open(['m' => 'mainwp', 'care_result' => hash('sha256', $token)])['html'];

        self::assertStringNotContainsString('We are connecting your site now', $html);
        self::assertStringContainsString('The connection was interrupted before it finished. It is tried again by itself.', $html);
        self::assertSame('failed', $this->repo->careSites[67]['state']);
        self::assertNotNull($this->repo->careSites[67]['retry_after']);
    }

    public function testStaffFollowingAMoveKeepWhatTheFirstAttemptCouldNotRemoveInTheLogLineTheySee(): void
    {
        $token = $this->approve();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'Your site did not accept the connection you approved.');
        $this->open($this->recordedReturn('approve-redirect', $token));
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://www.clientsite.example');
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;

        \MainWpForWhmcs\StaffConnect::attempt($this->repo, 1176, 1, 67, ['address' => 'clientsite.example', 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame(['connected', 'https://www.clientsite.example'], [$this->repo->careSites[67]['state'], $this->repo->careSites[67]['site_url']]);
        $row = $this->repo->log[array_key_last($this->repo->log)];
        self::assertSame(['connect', 'done', 'admin:1'], [$row['action'], $row['outcome'], $row['actor']]);
        self::assertStringContainsString('For the client: The account wpcarek7m2x9ab made for care may still be on your site', (string) $row['detail']);
        self::assertStringNotContainsString('Secret1', json_encode($this->repo->log, JSON_THROW_ON_ERROR));
    }

    public function testThePageOfAnAddressThatKeepsMovingSaysSoOnceAndDoesNotCallItOutsideTheService(): void
    {
        // Wherever it is asked, the site only sends visitors on to its www form: the www form included.
        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $page = $this->open(['m' => 'mainwp', 'addon' => '67']);

        self::assertSame(['index ' . self::SITE, 'index https://www.clientsite.example'], $this->site->calls, 'a move is followed once');
        self::assertStringContainsString('keeps sending visitors on to another address, so it cannot be connected here', $page['html']);
        self::assertStringNotContainsString('which is not an https:// address on this hosting service', $page['html']);
        self::assertFalse($page['page']['vars']['keyRoute']);
        self::assertSame(self::SITE, $page['page']['vars']['address'], 'the address shown stays the stored one');
    }

    public function testAReasonTheKeyRouteIsNotOfferedIsNotRepeatedUnderTheErrorThatSaysTheSame(): void
    {
        // The approve button was pressed on a page loaded before the site switched application passwords off.
        $this->site->index = new SiteIndex(self::SITE . '/wp-json/', null);

        $html = $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertSame([], $this->redirects);
        self::assertSame(1, substr_count($html, 'class="alert alert-danger"'));
        self::assertSame(0, substr_count($html, 'class="alert alert-warning"'), 'the same sentence is not shown a second time');
        self::assertStringContainsString('name="action" value="connect"', $html, 'the password form is still offered');
    }

    /** The address sends visitors on to its www form, and that one on to a third address under the service, where the site answers. */
    private function aSiteBehindTwoRedirects(): \MainWpForWhmcs\Tests\Engine\MovedSite
    {
        $site = new \MainWpForWhmcs\Tests\Engine\MovedSite('https://shop.clientsite.example', [self::SITE => 'https://www.clientsite.example', 'https://www.clientsite.example' => 'https://shop.clientsite.example']);
        Module::$siteFactory = static fn (): \MainWpForWhmcs\Engine\Site => $site;
        return $site;
    }

    public function testAPageThatSaysTheSiteCannotBeConnectedDoesNotOfferToConnectItAtYetAnotherAddress(): void
    {
        $site = $this->aSiteBehindTwoRedirects();

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('keeps sending visitors on to another address, so it cannot be connected here. Please contact support.', $html);
        self::assertStringNotContainsString('so that address is used', $html, 'the page does not contradict itself');
        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertStringContainsString('name="address" value="' . self::SITE . '"', $html, 'the form keeps the address the client typed');
        self::assertSame(['index ' . self::SITE, 'index https://www.clientsite.example', 'installPlugin ' . self::SITE . ' owner', 'index https://www.clientsite.example', 'index https://shop.clientsite.example', 'installPlugin https://www.clientsite.example owner'], $site->calls, 'and the site is not read again for an offer');
    }

    public function testAnApprovalThatCannotStartBecauseTheAddressKeepsMovingOffersNoOtherAddressEither(): void
    {
        $site = $this->aSiteBehindTwoRedirects();

        $html = $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertSame([], $this->redirects);
        self::assertSame(1, substr_count($html, 'keeps sending visitors on to another address, so it cannot be connected here'));
        self::assertStringNotContainsString('so that address is used', $html);
        self::assertStringNotContainsString('Approve on my site', $html);
        self::assertSame(['index ' . self::SITE, 'index https://www.clientsite.example', 'index ' . self::SITE, 'index https://www.clientsite.example'], $site->calls, 'the approval\'s reading, then the fresh page\'s, which finds the same');
    }

    public function testApprovingForAnAddressThatKeepsMovingStartsNothing(): void
    {
        $this->site->index = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $html = $this->post(['action' => 'approve', 'address' => self::SITE]);

        self::assertSame([], $this->redirects);
        self::assertSame(1, substr_count($html, 'keeps sending visitors on to another address, so it cannot be connected here'), 'said once');
        self::assertStringNotContainsString('sends visitors on to www.clientsite.example.', $html, 'not the bare fact, which tells the client nothing they can do');
        self::assertSame([], $this->repo->approvals, 'no link was made');
    }

    public function testAnAddressThatKeepsMovingWithinTheServiceIsNotSaidToLeadOutOfIt(): void
    {
        // Every address under the service only sends visitors on to the www form, the www form included.
        $this->site->refuses = new SiteFailure(SiteFailure::MOVED, 'The site at clientsite.example sends visitors on to www.clientsite.example.', 'https://www.clientsite.example');

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame(['installPlugin ' . self::SITE . ' owner', 'installPlugin https://www.clientsite.example owner'], array_values(array_filter($this->site->calls, static fn (string $c): bool => str_starts_with($c, 'installPlugin'))), 'a move is followed once');
        self::assertStringContainsString('keeps sending visitors on to another address, so it cannot be connected here', $html);
        self::assertStringNotContainsString('which is not an https:// address on this hosting service', $html, 'that would be untrue: the target is on the service');
    }

    public function testAReloadWhileTheSiteIsStillBeingConnectedCarriesNoNoteThatOutlivesIt(): void
    {
        $token = $this->approve();
        // The first return has claimed the link and is still connecting; this is its reload.
        $this->repo->approvals[0]['used_at'] = '2026-11-10 11:00:00';
        $this->repo->approvals[0]['key_hash'] = hash('sha256', self::KEY);
        $this->repo->approvals[0]['key_encrypted'] = (new WhmcsEncrypter())->encrypt('{"user":"owner","key":"' . self::KEY . '","care_user":"wpcarek7m2x9ab","care_password":"x"}');
        $this->repo->lockedElsewhere = ['addon:67'];

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['https://billing.example.com/index.php?m=mainwp&addon=67'], $this->redirects, 'no note in the address: the page itself says what is happening, and stops saying it when it is over');
        self::assertSame([], $this->site->calls);
        self::assertStringContainsString('We are connecting your site now.', $this->follow($this->redirects[0])['html']);

        \WHMCS\Session::$values = [];
        CurrentUser::$loggedOut = true;
        $this->redirects = [];
        $this->open($this->recordedReturn('approve-redirect', $token));
        self::assertStringContainsString('We are connecting your site now.', $this->follow($this->redirects[0])['html'], 'the same for a browser without a session');
    }

    public function testABusyReturnWhoseKeyCouldNotBeDeletedTellsTheClientToDeleteIt(): void
    {
        $token = $this->approve();
        $this->repo->lockedElsewhere = ['addon:67'];
        $this->site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertStringContainsString('care_note=busy&care_key=left', $this->redirects[0]);
        $this->repo->lockedElsewhere = [];
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('We were busy with your site', $html);
        self::assertStringContainsString('may still be on your site', $html);
    }

    public function testAKeyThatArrivesInGroupsWithSpacesConnectsToo(): void
    {
        $token = $this->approve();

        $this->open($this->recordedReturn('approve-redirect-browser', $token));

        self::assertSame(self::KEY, $this->site->keyed[0]['key']->password, 'a browser with JavaScript returns the key as WordPress displays it');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testAClientWhoseSessionLapsedIsNotLostAndSeesHowItEnded(): void
    {
        $token = $this->approve();
        \WHMCS\Session::$values = [];
        CurrentUser::$loggedOut = true;

        ['page' => $page] = $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertFalse($page['requirelogin']);
        self::assertSame('connected', $this->repo->careSites[67]['state']);
        self::assertSame(['https://billing.example.com/index.php?m=mainwp&care_result=' . hash('sha256', $token) . '&care_note=connected'], $this->redirects);
        self::assertSame('client', $this->repo->log[array_key_last($this->repo->log)]['actor']);

        ['page' => $page, 'html' => $html] = $this->follow($this->redirects[0]);
        self::assertFalse($page['requirelogin'], 'the outcome needs no session either');
        self::assertStringContainsString(self::SITE, $html);
        self::assertStringContainsString('Your site is connected and cared for.', $html);
        self::assertStringNotContainsString('<form', $html, 'nothing can be done from here without signing in');
        self::assertStringContainsString('clientarea.php', $html);
    }

    public function testTheOutcomePageDoesNotShowAReasonThatNamesMainWpEither(): void
    {
        $token = $this->approve();
        // The add-on is gone from the billing system and the site keeps a reason stored by version 0.4.2.
        unset($this->repo->addons[67]);
        $this->repo->careSites[67]['state'] = 'failed';
        $this->repo->careSites[67]['last_error'] = 'The dashboard refused to add the site: No MainWP Child plugin detected. First, install and activate the plugin and add your site to your MainWP Dashboard afterward.';

        $html = $this->open(['m' => 'mainwp', 'care_result' => hash('sha256', $token)])['html'];

        self::assertStringContainsString('Your site could not be connected.', $html);
        self::assertStringNotContainsStringIgnoringCase('mainwp', str_replace('index.php?m=mainwp', '', $html));
    }

    public function testAnOutcomeAddressOfAnEarlierVersionStillShowsHowTheReturnEnded(): void
    {
        // A browser that was sent on by version 0.4.2 just before the update, or reloads that page after it.
        $token = $this->approve();
        \WHMCS\Session::$values = [];
        CurrentUser::$loggedOut = true;
        $this->open($this->recordedReturn('approve-redirect', $token));

        ['page' => $page, 'html' => $html] = $this->open(['m' => 'mainwp', 'mainwp_result' => hash('sha256', $token), 'mainwp_note' => 'connected']);

        self::assertFalse($page['requirelogin']);
        self::assertStringContainsString('Your site is connected and cared for.', $html);

        $html = $this->open(['m' => 'mainwp', 'mainwp_result' => 'none', 'mainwp_note' => 'error', 'mainwp_key' => 'left'])['html'];
        self::assertStringContainsString('Something went wrong on our side', $html);
        self::assertStringContainsString('An application password whose name begins', $html);
    }

    public function testTodaysNameWinsOverAnEarlierOneAndTheEarlierOneIsDropped(): void
    {
        self::assertSame(['m' => 'mainwp', 'care_return' => 'a'], \MainWpForWhmcs\ClientPage::query(['m' => 'mainwp', 'care_return' => 'a', 'mainwp_return' => 'b']));
        self::assertSame(['care_return' => 'b', 'care_rejected' => '1'], \MainWpForWhmcs\ClientPage::query(['mainwp_return' => 'b', 'mainwp_rejected' => '1']));
        self::assertSame(['care_result' => 'h', 'care_note' => 'busy', 'care_key' => 'left'], \MainWpForWhmcs\ClientPage::query(['mainwp_result' => 'h', 'mainwp_note' => 'busy', 'mainwp_key' => 'left']));
    }

    public function testAnOutcomeThatIsNotKnownShowsNoSite(): void
    {
        \WHMCS\Session::$values = [];
        foreach ([str_repeat('0', 64), 'none', '../../etc', ''] as $hash) {
            $html = $this->open(['m' => 'mainwp', 'care_result' => $hash, 'care_note' => 'unknown'])['html'];
            self::assertStringNotContainsString('clientsite', $html);
            self::assertStringContainsString('This link is not valid', $html);
        }
        self::assertStringNotContainsString('alert(1)', $this->open(['m' => 'mainwp', 'care_result' => 'none', 'care_note' => '<script>alert(1)</script>'])['html'], 'only the notes the module knows are shown');
    }

    public function testAnApprovalStartedBeforeTheUpdateComesBackWithItsEarlierNamesAndIsStillTaken(): void
    {
        // An approval link is good for 24 hours, so one started under version 0.4.2 may come back after the update:
        // the recorded returns, with the query names of that version, unchanged.
        foreach (['approve-redirect' => ['connected', 'care_note=connected'], 'approve-reject-redirect' => ['failed', 'care_note=rejected']] as $fixture => [$state, $note]) {
            $this->setUp();
            $token = $this->approve();
            parse_str((string) parse_url((string) SiteFixtureHttp::fixture($fixture)->header('location'), PHP_URL_QUERY), $query);
            self::assertArrayHasKey('mainwp_return', $query, $fixture);
            self::assertArrayNotHasKey('care_return', $query, $fixture);
            $query['mainwp_return'] = $token;
            if (isset($query['site_url'])) {
                $query['site_url'] = self::SITE;
            }

            /** @var array<string, string> $query */
            ['page' => $page] = $this->open($query);

            self::assertFalse($page['requirelogin'], $fixture);
            self::assertSame($state, $this->repo->careSites[67]['state'], $fixture);
            self::assertStringContainsString($note, $this->redirects[0], $fixture);
            self::assertStringNotContainsStringIgnoringCase('mainwp_', $this->redirects[0], 'the browser is sent on with today\'s names');
        }
    }

    public function testADeclineOnTheSiteShowsThatNothingWasChanged(): void
    {
        $token = $this->approve();

        $this->open($this->recordedReturn('approve-reject-redirect', $token));

        self::assertSame([], $this->site->calls);
        self::assertSame('failed', $this->repo->careSites[67]['state']);
        self::assertNull($this->repo->careSites[67]['retry_after']);
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('was not approved on your site, so nothing was changed there', $html);
        self::assertStringContainsString('Approve on my site', $html, 'the client can start again');
    }

    public function testASiteThatWouldNotLetTheAccountApproveSaysSo(): void
    {
        $token = $this->approve();

        // Not recorded as a redirect: WordPress's own "Go Back" link on the page that refuses the account,
        // add_query_arg('error', 'disabled', $reject_url) in wp-admin/authorize-application.php.
        $this->open($this->recordedReturn('approve-reject-redirect', $token, ['error' => 'disabled']));

        self::assertStringContainsString('application passwords are switched off for the account', (string) $this->repo->careSites[67]['last_error']);
    }

    public function testAReturnOnALinkThatLapsedDeletesTheKeyOnTheSiteAndSaysSo(): void
    {
        $token = $this->approve();
        $this->repo->approvals[0]['expires_at'] = '2026-01-01 00:00:00';

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'the key is not used and not left on the site');
        self::assertSame([], $this->http->sent);
        self::assertSame('failed', $this->repo->careSites[67]['state']);
        self::assertStringContainsString('care_note=lapsed', $this->redirects[0]);
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('This link had lapsed', $html);
        self::assertStringContainsString('within 24 hours', $html);
    }

    public function testAKeyApprovedOnAnotherSiteIsNotUsedAndTheClientIsToldToDeleteIt(): void
    {
        $token = $this->approve();

        $this->open($this->recordedReturn('approve-redirect', $token, ['site_url' => 'https://victim.example']));

        self::assertSame([[], []], [$this->site->calls, $this->http->sent], 'the key is sent nowhere');
        self::assertSame('connecting', $this->repo->careSites[67]['state']);
        self::assertStringEndsWith('care_note=mismatch', $this->redirects[0]);
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('approved on a different site', $html);
        self::assertStringContainsString('was made on the site where you approved; delete it there', $html, 'the key is on that other site, not on the one this page is about');
        self::assertStringNotContainsString('may still be on your site', $html);
        self::assertStringNotContainsString(self::KEY, $html . $this->everythingKept());
    }

    public function testAReturnForCareThatIsNoLongerActiveIsRefusedAndItsKeyDeleted(): void
    {
        $token = $this->approve();
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Suspended', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 1);

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame([], $this->http->sent, 'a suspended add-on is not connected by an approval that was still out');
        self::assertStringContainsString('care_note=refused', $this->redirects[0]);
        self::assertStringContainsString('care_result=', $this->redirects[0], 'the add-on\'s page is not offered for care that is not active');
    }

    public function testAReturnWhileTheHostingServiceIsSuspendedIsRefusedAndItsKeyDeleted(): void
    {
        $token = $this->approve();
        // The service's module suspended it while the approval was out; WHMCS leaves the add-on Active.
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertSame([], $this->http->sent, 'no site is connected under a suspended hosting service');
        self::assertStringContainsString('care_note=refused', $this->redirects[0]);
        self::assertStringContainsString('care_result=', $this->redirects[0], 'the add-on\'s own page is not available either');
    }

    public function testAnApprovalThatComesBackAfterCareWasSuspendedIsNotToldToConnectOrToStartAgain(): void
    {
        $token = $this->approve();
        // The hosting service is suspended by its module while the approval is out; the dashboard does not hold the site.
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->http->on('GET', 'sites?page=1&per_page=20', 'sites-list-empty');
        Hooks::serviceEvent('AfterModuleSuspend', ['params' => ['serviceid' => 1176, 'userid' => 540, 'pid' => 116, 'serverid' => 5, 'addonId' => 0, 'status' => 'Active']]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls);
        self::assertStringContainsString('care_result=', $this->redirects[0]);
        self::assertStringContainsString('care_note=refused', $this->redirects[0], 'care that is not active, not a link that lapsed');
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('Care is suspended.', $html);
        self::assertStringNotContainsString('connect your site', $html, 'the client cannot connect while care is suspended');
        self::assertStringNotContainsString('start again', $html);
    }

    public function testAFaultOnOurSideIsNotExplainedToTheClientInStaffWords(): void
    {
        // The dashboard address in the settings answers without MainWP's REST API.
        $http = (new FixtureHttp())->on('POST', 'sites/add', new \MainWpForWhmcs\Engine\HttpResponse(404, '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}'));
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('We could not finish connecting your site because of a fault on our side. It is tried again by itself.', $html);
        self::assertStringNotContainsString('settings', $html, 'a client cannot see or change the module\'s settings');
        self::assertStringNotContainsString('dash.example.com', $html, 'and is not shown where the dashboard lives');
        self::assertStringNotContainsString('correct it below', $html, 'there is nothing for the client to correct');
        self::assertStringNotContainsString('We will try again once a day', $html, 'the retry is said once');
        $panel = \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176);
        self::assertStringContainsString('a fault on our side', $panel);
        self::assertStringNotContainsString('dash.example.com', $panel);
        $failed = array_values(array_filter($this->repo->log, static fn (array $r): bool => $r['action'] === 'connect' && $r['outcome'] === 'failed'));
        self::assertStringContainsString('does not answer as MainWP', (string) end($failed)['detail'], 'staff read the real reason in the log');
    }

    public function testAFaultOnOurSideIsSaidOnceAlsoWhenSomethingOfAnEarlierAttemptIsLeftOnTheSite(): void
    {
        $this->aHeldKeyThatCannotBeRemoved();
        $this->site->refuses = null;
        $http = (new FixtureHttp())->on('GET', 'sites?page=1&per_page=20', 'sites-list-empty')
            ->on('POST', 'sites/add', new \MainWpForWhmcs\Engine\HttpResponse(404, '{"code":"rest_no_route","message":"No route was found matching the URL and request method.","data":{"status":404}}'));
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertSame(1, substr_count($html, 'It is tried again by itself.'));
        self::assertStringContainsString('The account wpcarek7m2x9ab made for care may still be on your site', $html, 'what could not be removed is still said');
        self::assertStringNotContainsString('correct it below', $html, 'the login was not at fault');
        self::assertStringNotContainsString('We will try again once a day', $html);
    }

    public function testASiteTheDashboardAlreadyHoldsIsNotBlamedOnTheLoginTheClientTyped(): void
    {
        // Staff added the site on the dashboard by hand, or it is filed under another client there.
        $http = (new FixtureHttp())->on('POST', 'sites/add', 'sites-add-already-there');
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('We could not finish connecting your site because of a fault on our side. It is tried again by itself.', $html);
        self::assertStringNotContainsString('MainWP Dashboard', $html, 'the client has no dashboard and is not told about one');
        self::assertStringNotContainsString('already', $html);
        self::assertStringNotContainsString('correct it below', $html);
        $failed = array_values(array_filter($this->repo->log, static fn (array $r): bool => $r['action'] === 'connect' && $r['outcome'] === 'failed'));
        self::assertStringContainsString('it already holds a site at that address (dashboard site 1)', (string) end($failed)['detail'], 'staff read what to do about it');
        self::assertNotNull($this->repo->careSites[67]['retry_after'], 'the retry asks the dashboard first and takes the site over once it is filed under the client');
    }

    public function testARefusalMainWpHidesIsNotShownToTheClient(): void
    {
        // MainWP Child on the site still holds an earlier connection; MainWP hides its words (bench, sixth run).
        $http = (new FixtureHttp())->on('POST', 'sites/add', 'sites-add-after-dead-remove');
        Module::$httpFactory = static fn (): FixtureHttp => $http;

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('Your site still holds an earlier care connection, which has to be cleared before it can be connected again. Please contact support.', $html);
        self::assertStringNotContainsString('We will try again', $html, 'no retry can clear what is on the site');
        self::assertNull($this->repo->careSites[67]['retry_after']);
        self::assertStringNotContainsString('response content hidden', $html);
        self::assertStringNotContainsString('MainWP Child, Disconnect', $html, 'what staff do about it is in the log');
        $failed = array_values(array_filter($this->repo->log, static fn (array $r): bool => $r['action'] === 'connect' && $r['outcome'] === 'failed'));
        self::assertStringContainsString('clear it on the site (Settings, MainWP Child, Disconnect)', (string) end($failed)['detail']);
    }

    public function testTheConnectPageOffersNothingToConnectWhileTheHostingServiceIsSuspended(): void
    {
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Suspended', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);

        $html = $this->open()['html'];
        self::assertStringContainsString('Care is suspended.', $html);
        self::assertStringNotContainsString('<form', $html);
        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('Care is suspended.', $html);
        self::assertStringNotContainsString('<form', $html);
        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
    }

    public function testALinkThatIsNotKnownChangesNothing(): void
    {
        $this->approve();

        $this->open($this->recordedReturn('approve-redirect', str_repeat('f', 64)));

        self::assertSame([[], []], [$this->site->calls, $this->http->sent]);
        self::assertStringContainsString('care_note=unknown', $this->redirects[0]);
        self::assertSame('connecting', $this->repo->careSites[67]['state']);
    }

    public function testAFailedConnectionShowsItsReasonAndBothWaysToTryAgain(): void
    {
        $token = $this->approve();
        $this->site->refuses = new SiteFailure(SiteFailure::KEY_REFUSED, 'The site did not accept the key that was approved.');

        $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame('failed', $this->repo->careSites[67]['state']);
        self::assertNotNull($this->repo->careSites[67]['retry_after'], 'on the retry list');
        self::assertStringNotContainsString(self::KEY, (string) $this->repo->approvals[0]['key_encrypted'], 'the key is kept for the retry, encrypted');
        $html = $this->follow($this->redirects[0])['html'];
        self::assertStringContainsString('Connecting your site failed: The site did not accept the key that was approved.', $html);
        self::assertStringContainsString('Approve on my site', $html);
        self::assertStringContainsString('name="username"', $html);
    }

    public function testWhenHandlingAReturnFailsTheBrowserStillLeavesTheAddressThatHoldsTheKey(): void
    {
        $token = $this->approve();
        Module::$siteFactory = static function (): never {
            throw new \RuntimeException('boom at /var/www/whmcs/modules/addons/mainwp/lib/x.php:12');
        };

        ['page' => $page] = $this->open($this->recordedReturn('approve-redirect', $token));

        self::assertSame(['index.php?m=mainwp&care_result=none&care_note=error'], $this->redirects);
        self::assertFalse($page['requirelogin']);
        self::assertStringNotContainsString('/var/www', implode(' ', WhmcsCalls::$activity));
        Module::$siteFactory = null;
        self::assertStringContainsString('Something went wrong on our side', $this->open(['m' => 'mainwp', 'care_result' => 'none', 'care_note' => 'error'])['html']);
    }

    public function testAnApprovalNeverGivenIsEndedWhenTheClientOpensThePageAgain(): void
    {
        $this->approve();
        $this->repo->approvals[0]['expires_at'] = '2026-01-01 00:00:00';

        $html = $this->open(['m' => 'mainwp', 'addon' => '67'])['html'];

        self::assertSame('failed', $this->repo->careSites[67]['state']);
        self::assertStringContainsString('was not approved on your site within 24 hours', $html);
        self::assertStringContainsString('Approve on my site', $html);
    }

    public function testWhileAnApprovalIsOutThePageAndThePanelSayWaitingAndTheClientCanStartAgain(): void
    {
        $this->approve();

        $html = $this->open(['m' => 'mainwp', 'addon' => '67'])['html'];
        self::assertStringContainsString('Waiting for the connection to be approved on your site.', $html);
        self::assertStringContainsString('Approve on my site', $html);
        self::assertStringContainsString('name="username"', $html);

        $panel = \MainWpForWhmcs\Tests\Support\ClientSidebar::panel(1176);
        self::assertStringContainsString('Waiting for the connection to be approved on your site.', $panel);
        self::assertStringContainsString('index.php?m=mainwp&amp;addon=67', $panel);
        \WHMCS\Session::$values['adminid'] = 1;
        self::assertStringContainsString('<span class="label pending">Connecting</span> (waiting for the client to approve on their site)<br>', implode('', Hooks::adminServiceFields(['id' => 1176])));
    }

    public function testThePanelsEndAnApprovalNeverGivenToo(): void
    {
        $this->approve();
        $this->repo->approvals[0]['expires_at'] = '2026-01-01 00:00:00';

        \WHMCS\Session::$values['adminid'] = 1;
        $fields = implode('', Hooks::adminServiceFields(['id' => 1176]));

        self::assertStringContainsString('Failed', $fields);
        self::assertSame('failed', $this->repo->careSites[67]['state']);
    }

    public function testThePasswordFormTakesOverFromAnApprovalThatIsOut(): void
    {
        $token = $this->approve();

        $html = $this->post(['action' => 'connect', 'address' => self::SITE, 'username' => 'owner', 'password' => 'Secret1']);

        self::assertStringContainsString('Your site is connected', $html);
        self::assertSame(['index ' . self::SITE, 'installPlugin ' . self::SITE . ' owner'], $this->site->calls);
        $this->site->calls = [];
        $this->redirects = [];
        $this->open($this->recordedReturn('approve-redirect', $token));
        self::assertSame(['deleteKey ' . self::SITE . ' owner'], $this->site->calls, 'a key approved afterwards is deleted on the site');
        self::assertSame('connected', $this->repo->careSites[67]['state']);
    }

    public function testInDryRunNoClientSeesEitherRouteAndNothingIsSent(): void
    {
        $this->repo->settings['dry_run'] = 'on';
        $this->repo->settings['test_client_id'] = '540'; // as an earlier version stored it: no longer read
        $html = $this->open()['html'];
        self::assertStringContainsString('This page is not available.', $html);
        self::assertStringNotContainsString('Approve on my site', $html);

        $html = $this->post(['action' => 'approve', 'address' => self::SITE]);
        self::assertStringNotContainsString('Dry run', $html);
        self::assertSame([[], [], [], []], [$this->site->calls, $this->redirects, $this->repo->approvals, $this->http->sent], 'no client is sent to approve a key the module would not then delete');
        self::assertSame('provisioned', $this->repo->careSites[67]['state']);
    }

    public function testTheModulePageSaysWhenTheKeyRouteCannotBeOffered(): void
    {
        \WHMCS\Session::$values['adminid'] = 1;
        $render = fn (): string => \MainWpForWhmcs\Admin\Page::render($this->repo, new WhmcsEncrypter(), 1, 'addonmodules.php?module=mainwp', 'GET', [], 't');

        self::assertStringNotContainsString('Clients can connect', $render());

        $this->repo->settings['care_email'] = '';
        self::assertStringContainsString('until a Care Account Email is entered under Settings', $render());

        $this->repo->settings['care_email'] = 'wordpress@care.example.com';
        $this->repo->systemUrl = 'http://billing.example.com/';
        self::assertStringContainsString('until WHMCS&#039;s System URL (General Settings) starts with https://', $render());
    }
}
