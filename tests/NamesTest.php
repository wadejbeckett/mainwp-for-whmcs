<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\AddonRow;
use MainWpForWhmcs\Admin\Template;
use MainWpForWhmcs\Engine\CareEngine;
use MainWpForWhmcs\Engine\Client;
use MainWpForWhmcs\Engine\CurlHttp;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\Secrets;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Hooks;
use MainWpForWhmcs\Module;
use MainWpForWhmcs\RepositoryStorage;
use MainWpForWhmcs\Schema;
use MainWpForWhmcs\ServiceName;
use MainWpForWhmcs\Tests\Engine\FixedClock;
use MainWpForWhmcs\Tests\Engine\FixedSecrets;
use MainWpForWhmcs\Tests\Engine\MemoryStorage;
use MainWpForWhmcs\Tests\Engine\RecordingDashboard;
use MainWpForWhmcs\Tests\Engine\RecordingSite;
use MainWpForWhmcs\Tests\Support\ClientSidebar;
use MainWpForWhmcs\Tests\Support\FixtureHttp;
use MainWpForWhmcs\Tests\Support\MemoryRepository;
use MainWpForWhmcs\Tests\Support\WhmcsCalls;
use MainWpForWhmcs\WhmcsEncrypter;

require_once __DIR__ . '/Engine/Fakes.php';

/**
 * Which name belongs where (naming rule 1 of 0.6.0): the add-on module is "MainWP for WHMCS", which
 * only staff read, in WHMCS's admin area. The service a client bought is the host's own: the name of
 * its care product add-on in WHMCS, read live and never stored by the module. Where no single add-on
 * is at hand, the name of the only care add-on stands in, else the neutral "Website Care". A client
 * never reads MainWP's name, and the care account's login keeps its prefix "wpcare".
 */
final class NamesTest extends TestCase
{
    private const MODULE = __DIR__ . '/../modules/addons/mainwp';
    /** The provisioning module the same name, in WHMCS's other module folder. */
    private const SERVER = __DIR__ . '/../modules/servers/mainwp';
    /**
     * Not words a client reads: the plugin's folder on WordPress.org, which the site's REST API is asked for, and the
     * query names of versions before 0.5.0, with which an approval started then still comes back.
     */
    private const NOT_WORDS = ['mainwp-child', 'mainwp_return', 'mainwp_rejected', 'mainwp_result', 'mainwp_note', 'mainwp_key'];

    private const SITE = 'https://plus.example.com';
    private const KEY = 'BenchKey0123456789ABCDEF';

    private MemoryRepository $repo;
    private RecordingSite $site;
    private FixedSecrets $secrets;
    /** @var list<string> */
    private array $redirects = [];

    public static function setUpBeforeClass(): void
    {
        require_once __DIR__ . '/../modules/addons/mainwp/mainwp.php';
        require_once __DIR__ . '/../modules/servers/mainwp/mainwp.php';
    }

    /**
     * Two care add-ons: the stock one (16, "WordPress Care") and a second the host named "Site Care
     * Plus" (23). Row 67 of add-on 16 is on service 1176, row 68 of add-on 23 on service 1180; both
     * belong to client 540 and wait to be connected.
     */
    protected function setUp(): void
    {
        WhmcsCalls::reset();
        $this->repo = new MemoryRepository();
        Schema::install($this->repo);
        $this->repo->settings = ['dry_run' => '', 'care_email' => 'wordpress@care.example.com', 'dashboard_url' => 'https://dash.example.com', 'api_key' => (new WhmcsEncrypter())->encrypt('bench-key-0123456789')];
        $this->repo->productAddons = [16 => 'WordPress Care', 23 => 'Site Care Plus', 9 => 'Backups'];
        $this->repo->addonModules = [16 => 'mainwp', 23 => 'mainwp', 9 => ''];
        $this->repo->addons[67] = new AddonRow(67, 1176, 540, 16, 'Active', 'Active', 'clientsite.example', 'Demo Client', 'demo@example.com', 0);
        $this->repo->addons[68] = new AddonRow(68, 1180, 540, 23, 'Active', 'Active', 'plus.example.com', 'Demo Client', 'demo@example.com', 0);
        foreach ([67 => [1176, 'https://clientsite.example'], 68 => [1180, self::SITE]] as $id => [$service, $url]) {
            $this->repo->careSites[$id] = ['addon_row_id' => $id, 'service_id' => $service, 'client_id' => 540, 'site_url' => $url, 'dashboard_client_id' => 1, 'dashboard_site_id' => null, 'state' => 'provisioned', 'retry_count' => 0, 'last_sync' => null, 'last_error' => null, 'retry_after' => null];
        }
        $repo = $this->repo;
        Module::$repositoryFactory = static fn (): MemoryRepository => $repo;
        $this->site = new RecordingSite();
        $site = $this->site;
        Module::$siteFactory = static fn (): RecordingSite => $site;
        $http = (new FixtureHttp())->on('POST', 'sites/add', 'sites-add-with-client');
        Module::$httpFactory = static fn (): FixtureHttp => $http;
        $this->secrets = new FixedSecrets();
        $secrets = $this->secrets;
        Module::$secretsFactory = static fn (): FixedSecrets => $secrets;
        $this->redirects = [];
        Module::$redirector = function (string $url): void {
            $this->redirects[] = $url;
        };
        \WHMCS\Session::$values['uid'] = 540;
        $_GET = $_POST = [];
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
     * The client page as WHMCS shows it: its page data and the rendered template.
     *
     * @param array<string, string> $get
     * @param array<string, string>|null $post
     * @return array{page: array<string, mixed>, html: string}
     */
    private function open(array $get, ?array $post = null): array
    {
        $_GET = $get;
        $_POST = $post === null ? [] : $post + ['token' => 'test-token'];
        $_SERVER['REQUEST_METHOD'] = $post === null ? 'GET' : 'POST';
        $page = \mainwp_clientarea([]);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        return ['page' => $page, 'html' => Template::render($page['templatefile'] . '.tpl', $page['vars'])];
    }

    /** Opens the address a redirect went to. @return array{page: array<string, mixed>, html: string} */
    private function follow(string $redirect): array
    {
        parse_str((string) parse_url($redirect, PHP_URL_QUERY), $query);
        /** @var array<string, string> $query */
        return $this->open($query);
    }

    /** The client presses "Approve on my site" for row 68; returns the token in the return address. */
    private function approve(): string
    {
        $this->open(['m' => 'mainwp', 'addon' => '68'], ['action' => 'approve', 'address' => self::SITE]);
        self::assertCount(1, $this->redirects, 'the client is sent to their site');
        return $this->secrets->tokens[array_key_last($this->secrets->tokens)];
    }

    /** @return array<string, string> the return the client's site sends the browser back with */
    private static function back(string $token, string $siteUrl = self::SITE): array
    {
        return ['m' => 'mainwp', 'care_return' => $token, 'site_url' => $siteUrl, 'user_login' => 'owner', 'password' => self::KEY];
    }

    public function testStaffReadTheModulesNameInTheAdminArea(): void
    {
        self::assertSame('MainWP for WHMCS', Module::DISPLAY_NAME);
        self::assertSame('MainWP for WHMCS', \mainwp_config()['name'], 'the Addon Modules list and the Addons menu');
        self::assertSame(Module::DISPLAY_NAME, \mainwp_MetaData()['DisplayName'], 'the Module Name list on the product add-on\'s Module Settings');
    }

    /** The service's name is the host's own: no file that is installed names the publisher's service. */
    public function testNoShippedFileNamesAFixedService(): void
    {
        $found = [];
        foreach ([self::MODULE, self::SERVER] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = basename(dirname($folder)) . '/mainwp/' . substr($file->getPathname(), strlen($folder) + 1);
                $count = substr_count(strtolower($path . ' ' . (string) file_get_contents($file->getPathname())), 'wordpress care');
                if ($count > 0) {
                    $found[$path] = $count;
                }
            }
        }
        self::assertSame([], $found, 'files that name "WordPress Care", with how often');
        self::assertSame('Website Care', ServiceName::NEUTRAL);
    }

    public function testTheServiceIsNamedByTheCareAddonItself(): void
    {
        self::assertSame('Site Care Plus', ServiceName::of($this->repo, $this->repo->addonRow(68)));
        self::assertSame('WordPress Care', ServiceName::of($this->repo, $this->repo->addonRow(67)));
        $this->repo->productAddons[23] = 'Site Care &amp; More';
        self::assertSame('Site Care & More', ServiceName::of($this->repo, $this->repo->addonRow(68)), 'as WHMCS shows it, not as it stores it');
    }

    /** No care add-on, or several: the neutral words. Exactly one: its name. An add-on that is gone or has no name: the same. */
    public function testWhereNoSingleAddonIsAtHandTheFallbackStandsIn(): void
    {
        self::assertSame(ServiceName::NEUTRAL, ServiceName::fallback($this->repo), 'two care add-ons');
        $this->repo->addonModules = [16 => 'mainwp', 23 => '', 9 => ''];
        self::assertSame('WordPress Care', ServiceName::fallback($this->repo), 'one care add-on');
        $this->repo->addonModules = [23 => 'mainwp'];
        self::assertSame('Site Care Plus', ServiceName::fallback($this->repo));
        $this->repo->addonModules = [];
        self::assertSame(ServiceName::NEUTRAL, ServiceName::fallback($this->repo), 'no care add-on');
        self::assertSame(ServiceName::NEUTRAL, ServiceName::of($this->repo, null), 'no row');

        $this->repo->addonModules = [23 => 'mainwp'];
        self::assertSame('Site Care Plus', ServiceName::of($this->repo, new AddonRow(90, 1, 1, 77, 'Active', 'Active', '', '', '', 0)), 'an add-on WHMCS no longer holds');
        $this->repo->productAddons[16] = '  ';
        self::assertSame('Site Care Plus', ServiceName::of($this->repo, $this->repo->addonRow(67)), 'an add-on with no name');
        $this->repo->addonModules = [16 => 'mainwp'];
        self::assertSame(ServiceName::NEUTRAL, ServiceName::fallback($this->repo), 'the only care add-on has no name');
    }

    public function testAGroupOfRowsIsNamedOnlyByANameTheyShare(): void
    {
        $plus = $this->repo->addonRow(68);
        $stock = $this->repo->addonRow(67);
        self::assertNotNull($plus);
        self::assertNotNull($stock);
        self::assertSame('Site Care Plus', ServiceName::shared($this->repo, [$plus, $plus]));
        self::assertSame(ServiceName::NEUTRAL, ServiceName::shared($this->repo, [$plus, $stock]), 'two names never mix');
        self::assertSame(ServiceName::NEUTRAL, ServiceName::shared($this->repo, []));
        $this->repo->productAddons[23] = 'WordPress Care';
        self::assertSame('WordPress Care', ServiceName::shared($this->repo, [$plus, $stock]), 'two add-ons of one name');
    }

    /** What the client reads on the service page and on the connect page carries the name of the add-on it is for. */
    public function testAClientReadsTheCareAddonsOwnName(): void
    {
        $sidebar = ClientSidebar::of(1180);
        Hooks::clientSidebar($sidebar);
        $panel = $sidebar->getChild(ServiceName::NEUTRAL);
        self::assertNotNull($panel, 'the panel\'s key is the same neutral name whatever the add-on is called');
        self::assertSame('Site Care Plus', $panel->getLabel());

        ['page' => $page] = $this->open(['m' => 'mainwp', 'addon' => '68']);
        self::assertSame('Site Care Plus', $page['pagetitle']);
        self::assertSame(['index.php?m=mainwp' => 'Site Care Plus'], $page['breadcrumb']);
        ['page' => $page] = $this->open(['m' => 'mainwp', 'addon' => '67']);
        self::assertSame('WordPress Care', $page['pagetitle']);
        self::assertSame(['index.php?m=mainwp' => 'WordPress Care'], $page['breadcrumb']);

        $this->repo->addons[69] = new AddonRow(69, 1181, 540, 23, 'Active', 'Active', 'unpaid.example.com', 'Demo Client', 'demo@example.com', 1);
        self::assertStringContainsString('We can start looking after your site once the invoice for Site Care Plus is paid.', (string) ClientSidebar::panel(1181));
    }

    public function testANameWithMarkupCharactersIsEncodedWhereWhmcsPrintsItAsItIs(): void
    {
        // WHMCS's themes print a sidebar label, the page title, the breadcrumb and a staff field's label as they are
        // given; the module's own templates escape. A host may name its add-on with an ampersand or angle brackets.
        $this->repo->productAddons[23] = 'Care <Plus> & Co';

        $sidebar = ClientSidebar::of(1180);
        Hooks::clientSidebar($sidebar);
        self::assertSame('Care &lt;Plus&gt; &amp; Co', $sidebar->getChild(ServiceName::NEUTRAL)?->getLabel());

        ['page' => $page, 'html' => $html] = $this->open(['m' => 'mainwp', 'addon' => '68']);
        self::assertSame('Care &lt;Plus&gt; &amp; Co', $page['pagetitle']);
        self::assertSame(['index.php?m=mainwp' => 'Care &lt;Plus&gt; &amp; Co'], $page['breadcrumb']);
        self::assertStringNotContainsString('<Plus>', $html);
        self::assertStringNotContainsString('&amp;lt;', $html, 'encoded once, not twice, where the module\'s own template prints it');

        $this->repo->settings['access'] = '1';
        $this->repo->adminRoles = [1 => 1];
        \WHMCS\Session::$values['adminid'] = 1;
        self::assertSame(['Care &lt;Plus&gt; &amp; Co (add-on 68)'], array_keys(Hooks::adminServiceFields(['id' => 1180])));
    }

    /** Two care add-ons of different names on one service: the panel takes neither, and each staff field its own. */
    public function testTwoNamesNeverMix(): void
    {
        $this->repo->addons[70] = new AddonRow(70, 1180, 540, 16, 'Active', 'Active', 'plus.example.com', 'Demo Client', 'demo@example.com', 1);
        $sidebar = ClientSidebar::of(1180);
        Hooks::clientSidebar($sidebar);
        self::assertSame(ServiceName::NEUTRAL, $sidebar->getChild(ServiceName::NEUTRAL)?->getLabel());
        $html = (string) $sidebar->getChild(ServiceName::NEUTRAL)?->getBodyHtml();
        self::assertStringContainsString('once the invoice for WordPress Care is paid', $html, 'each sentence names its own add-on');
        self::assertStringNotContainsString('Site Care Plus', $html);

        self::assertSame(['Site Care Plus (add-on 68)', 'WordPress Care (add-on 70)'], array_keys(Hooks::adminServiceFields(['id' => 1180])));
    }

    /** The application password the client approves and the care account made with it are named for the add-on. */
    public function testTheKeyRouteNamesWhatItLeavesOnTheSiteForTheAddon(): void
    {
        $token = $this->approve();
        parse_str((string) parse_url($this->redirects[0], PHP_URL_QUERY), $query);
        self::assertStringStartsWith('Site Care Plus (', (string) $query['app_name']);
        $this->redirects = [];

        $this->open(self::back($token));

        self::assertSame('Site Care Plus', $this->site->keyed[0]['name'], 'the care account\'s display name on the site');
        self::assertSame('connected', $this->repo->careSites[68]['state']);
    }

    /** The outcome page and its notes, for a browser with or without a session, carry the name of the approval's add-on. */
    public function testTheOutcomeOfAReturnIsToldInTheAddonsName(): void
    {
        $token = $this->approve();
        $this->redirects = [];
        $this->repo->addons[68] = new AddonRow(68, 1180, 540, 23, 'Suspended', 'Active', 'plus.example.com', 'Demo Client', 'demo@example.com', 0);

        $this->open(self::back($token));
        self::assertCount(1, $this->redirects);
        ['page' => $page, 'html' => $html] = $this->follow($this->redirects[0]);

        self::assertSame('Site Care Plus', $page['pagetitle']);
        self::assertSame(['index.php?m=mainwp' => 'Site Care Plus'], $page['breadcrumb']);
        self::assertStringContainsString('<h2>Site Care Plus</h2>', $html);
        self::assertStringContainsString('Site Care Plus is not active for this site at the moment', $html);
        self::assertSame([], $this->site->keyed, 'the approval was not used');
    }

    public function testAKeyMadeOnAnotherSiteIsNamedForTheAddon(): void
    {
        $token = $this->approve();
        $this->redirects = [];

        $this->open(self::back($token, 'https://other.example.com'));
        self::assertCount(1, $this->redirects);
        $html = $this->follow($this->redirects[0])['html'];

        self::assertStringContainsString('An application password whose name begins &quot;Site Care Plus&quot; was made on the site where you approved', $html);
    }

    /** Where nothing names an add-on, the page takes the fallback. */
    public function testAPageForNoAddonTakesTheFallback(): void
    {
        ['page' => $page, 'html' => $html] = $this->open(['m' => 'mainwp']);
        self::assertSame(ServiceName::NEUTRAL, $page['pagetitle'], 'two care add-ons');
        self::assertStringContainsString('This page is not available', $html);

        $this->repo->addonModules = [23 => 'mainwp'];
        self::assertSame(['index.php?m=mainwp' => 'Site Care Plus'], $this->open(['m' => 'mainwp'])['page']['breadcrumb'], 'the only care add-on');
        self::assertSame(['index.php?m=mainwp' => 'Site Care Plus'], $this->open(['m' => 'mainwp', 'care_result' => str_repeat('0', 64)])['page']['breadcrumb'], 'an outcome no approval is known for');
    }

    /** The name is WHMCS's, read each time: renaming the add-on renames the next page, with nothing kept by the module. */
    public function testTheNameIsReadLive(): void
    {
        self::assertSame('Site Care Plus', $this->open(['m' => 'mainwp', 'addon' => '68'])['page']['pagetitle']);

        $this->repo->productAddons[23] = 'Premium Care';

        self::assertSame('Premium Care', $this->open(['m' => 'mainwp', 'addon' => '68'])['page']['pagetitle']);
        $sidebar = ClientSidebar::of(1180);
        Hooks::clientSidebar($sidebar);
        self::assertSame('Premium Care', $sidebar->getChild(ServiceName::NEUTRAL)?->getLabel());
        self::assertStringNotContainsString('Care', json_encode([$this->repo->settings, $this->repo->careSites], JSON_THROW_ON_ERROR), 'no name is stored');
        $storage = new RepositoryStorage($this->repo, new WhmcsEncrypter(), static fn (): string => '2026-11-16 10:00:00');
        self::assertSame('Premium Care', $storage->serviceName(68), 'the engine reads it through its storage');
        self::assertSame(ServiceName::NEUTRAL, $storage->serviceName(999), 'a row WHMCS no longer holds');
    }

    /**
     * The care account made through the dashboard takes the name as its first and last name, split at
     * the last space, from which WordPress makes the display name; one word is a first name alone.
     */
    public function testTheCareAccountIsNamedForTheAddon(): void
    {
        foreach (['Site Care Plus' => ['Site Care', 'Plus'], 'WordPress Care' => ['WordPress', 'Care'], 'Care' => ['Care', '']] as $name => $expected) {
            $dashboard = new RecordingDashboard();
            $storage = new MemoryStorage();
            $storage->serviceName = $name;
            $engine = new CareEngine($dashboard, new RecordingSite(), $storage, new FixedClock(), false, new FixedSecrets(), 'wordpress@care.example.com');
            $engine->provision(68, 1180, new Client(540, 'Demo Client', 'demo@example.com'), self::SITE, 'hook:AddonActivated');
            $dashboard->admins[101] = ['owner'];

            self::assertTrue($engine->connect(68, new Login('owner', 'Secret1'), 'client:540')->ok);
            self::assertSame($expected, [$dashboard->created[0]['firstName'], $dashboard->created[0]['lastName']], $name);
        }
    }

    /** What the engine tells the client and staff about a key it could not delete names the key as the client sees it. */
    public function testAKeyLeftOnTheSiteIsNamedForTheAddon(): void
    {
        $dashboard = new RecordingDashboard();
        $site = new RecordingSite();
        $storage = new MemoryStorage();
        $storage->serviceName = 'Site Care Plus';
        $secrets = new FixedSecrets();
        $engine = new CareEngine($dashboard, $site, $storage, new FixedClock(), false, $secrets, 'wordpress@care.example.com');
        $engine->provision(68, 1180, new Client(540, 'Demo Client', 'demo@example.com'), self::SITE, 'hook:AddonActivated');
        $start = $engine->startApproval(68, 'https://billing.example.com/index.php?m=mainwp&care_return={token}', 'https://billing.example.com/index.php?m=mainwp&care_return={token}&care_rejected=1', 'client:540');
        parse_str((string) parse_url((string) $start->url, PHP_URL_QUERY), $query);
        self::assertSame('Site Care Plus (2026-11-09 14:00:00)', $query['app_name']);
        $site->refuses = new SiteFailure(SiteFailure::NOT_ADMIN, 'The account that approved the connection is not an administrator of the site.');
        $site->keyStays = new SiteFailure(SiteFailure::UNREACHABLE, 'The site could not be reached.');

        $engine->completeApproval($secrets->tokens[0], self::SITE, 'owner', self::KEY, 'client:540');

        self::assertStringContainsString('An application password whose name begins "Site Care Plus" may still be on your site', (string) $storage->site(68)?->lastError);
        $entry = end($storage->log);
        self::assertNotFalse($entry);
        self::assertStringContainsString('application password whose name begins "Site Care Plus"', $entry->detail, 'staff read it in the log');
    }

    /** WHMCS knows the module as "mainwp": its folder, its functions, its tables and its two page addresses all take that one name. */
    public function testWhmcsKnowsTheModuleByOneName(): void
    {
        self::assertSame('mainwp', Module::NAME);
        self::assertFileExists(self::MODULE . '/mainwp.php');
        foreach (['config', 'activate', 'deactivate', 'upgrade', 'output', 'clientarea'] as $function) {
            self::assertTrue(function_exists('mainwp_' . $function), 'mainwp_' . $function . '()');
        }
        self::assertFileExists(self::SERVER . '/mainwp.php');
        foreach (['MetaData', 'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount'] as $function) {
            self::assertTrue(function_exists('mainwp_' . $function), 'mainwp_' . $function . '()');
        }
        self::assertSame(['mod_mainwp_sites', 'mod_mainwp_tokens', 'mod_mainwp_log'], Schema::TABLES);
        self::assertSame(['index.php?m=mainwp'], array_keys(\mainwp_clientarea([])['breadcrumb']));
    }

    /**
     * The module was first called by the service's name ("wpcare"). Nothing of that is left in what is
     * installed, with one
     * thing that is not a remnant: a care account's login on a client's site begins "wpcare" ("WordPress
     * care"), which is how the module knows its own accounts. It is written once, and said to the
     * client once with each connect form, since both routes make the care account.
     */
    public function testNothingOfTheEarlierNameIsLeftInTheModule(): void
    {
        self::assertSame('wpcare', Secrets::CARE_LOGIN_PREFIX);
        $said = ['addons/mainwp/lib/Engine/Secrets.php' => 1, 'addons/mainwp/templates/client-connect.tpl' => 2];
        $found = [];
        foreach ([self::MODULE, self::SERVER] as $folder) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($folder, \FilesystemIterator::SKIP_DOTS)) as $file) {
                $path = basename(dirname($folder)) . '/mainwp/' . substr($file->getPathname(), strlen($folder) + 1);
                $count = substr_count(strtolower($path), 'wpcare') + substr_count(strtolower((string) file_get_contents($file->getPathname())), 'wpcare');
                if ($count !== ($said[$path] ?? 0)) {
                    $found[$path] = $count;
                }
            }
        }
        self::assertSame([], $found, 'files that name "wpcare", with how often');
        self::assertDirectoryDoesNotExist(dirname(self::MODULE) . '/wpcare');
        self::assertDirectoryDoesNotExist(dirname(self::SERVER) . '/wpcare');
    }

    /**
     * Both modules are "mainwp", and PHP function names ignore case: the add-on's mainwp_clientarea is
     * also the provisioning module's mainwp_ClientArea, so the server file must never declare it.
     */
    public function testBothModulesLoadInOneProcess(): void
    {
        foreach (['config', 'activate', 'deactivate', 'upgrade', 'output', 'clientarea', 'MetaData', 'CreateAccount', 'SuspendAccount', 'UnsuspendAccount', 'TerminateAccount'] as $function) {
            self::assertTrue(function_exists('mainwp_' . $function), 'mainwp_' . $function . '()');
        }
        self::assertDoesNotMatchRegularExpression('/function\s+mainwp_clientarea\b/i', (string) file_get_contents(self::SERVER . '/mainwp.php'));
    }

    /**
     * WHMCS loads the server file in the cron's Overdue Suspensions and when it lists server modules: a
     * missing add-on folder must not be a fatal error there. Every require or include sits inside a
     * function, after an is_file check and inside a try. That the is_file check guards the load is shown
     * by ProvisioningModuleTest::testTheServerFileAnswersWhenTheAddonFolderIsBroken, which runs the file.
     */
    public function testServerFileRequiresNothingAtFileLevel(): void
    {
        $depth = 0;
        $inFunction = [];
        $inTry = [];
        $pendingFunction = false;
        $pendingTry = false;
        $checked = false;
        $loads = 0;
        foreach (\PhpToken::tokenize((string) file_get_contents(self::SERVER . '/mainwp.php')) as $token) {
            if ($token->is(T_FUNCTION)) {
                $pendingFunction = true;
                $checked = false;
            } elseif ($token->is(T_TRY)) {
                $pendingTry = true;
            } elseif ($token->text === '{' || $token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES])) {
                $depth++;
                $inFunction[$depth] = $pendingFunction || ($inFunction[$depth - 1] ?? false);
                $inTry[$depth] = $pendingTry || ($inTry[$depth - 1] ?? false);
                $pendingFunction = false;
                $pendingTry = false;
            } elseif ($token->text === '}') {
                unset($inFunction[$depth], $inTry[$depth]);
                $depth--;
            } elseif ($token->is(T_STRING) && strtolower($token->text) === 'is_file') {
                $checked = true;
            } elseif ($token->is([T_REQUIRE, T_REQUIRE_ONCE, T_INCLUDE, T_INCLUDE_ONCE])) {
                $loads++;
                self::assertTrue($inFunction[$depth] ?? false, 'a ' . $token->text . ' on line ' . $token->line . ' runs at file level');
                self::assertTrue($checked, 'the ' . $token->text . ' on line ' . $token->line . ' is not preceded by an is_file check');
                self::assertTrue($inTry[$depth] ?? false, 'the ' . $token->text . ' on line ' . $token->line . ' is not inside a try');
            }
        }
        self::assertSame(4, $loads, 'each of the four commands loads the library itself');
    }

    /**
     * "Care Account Email" replaced "Care Account Email Domain". The old setting's name
     * stays only in the upgrade step that moves an install off it.
     */
    public function testNothingOfTheReplacedSettingIsLeftOutsideItsUpgradeStep(): void
    {
        $found = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::MODULE, \FilesystemIterator::SKIP_DOTS)) as $file) {
            $path = substr($file->getPathname(), strlen(self::MODULE) + 1);
            $text = strtolower((string) file_get_contents($file->getPathname()));
            foreach (['care_email_domain', 'careemaildomain', 'email domain'] as $name) {
                if (str_contains($text, $name) && $path !== 'lib/Schema.php') {
                    $found[] = $path . ': ' . $name;
                }
            }
        }
        self::assertSame([], $found);
        self::assertStringContainsString('care_email_domain', (string) file_get_contents(self::MODULE . '/lib/Schema.php'), 'the upgrade step still knows the name it moves off');
    }

    /**
     * A client can read who called their site in its access log or in a security plugin's list of
     * visitors. There the caller gives the neutral words, with no version, whatever the host calls its
     * service; the dashboard, which staff run, is given the module's name.
     */
    public function testAClientsSiteIsCalledInNeutralWordsAndTheDashboardInTheModulesName(): void
    {
        self::assertSame('Website Care', (new CurlHttp(ServiceName::NEUTRAL))->userAgent, 'the caller says the name it is given');
        $wiring = (string) file_get_contents(self::MODULE . '/lib/Module.php');
        self::assertSame(1, substr_count($wiring, 'new Engine\\CurlHttp(ServiceName::NEUTRAL, $siteTimeout)'), 'a client\'s site');
        self::assertSame(1, substr_count($wiring, 'new Engine\\CurlHttp(self::DISPLAY_NAME)'), 'the dashboard');
        self::assertSame(2, substr_count($wiring, 'new Engine\\CurlHttp('), 'no third caller without a name');
        self::assertStringNotContainsString('for WHMCS', (string) file_get_contents(self::MODULE . '/lib/Engine/CurlHttp.php'), 'the caller has no name of its own');
        self::assertStringNotContainsString('CurlHttp', (string) file_get_contents(self::SERVER . '/mainwp.php'), 'the provisioning module calls nothing itself');
    }

    /**
     * No page or message a client reads names MainWP: the client reads that "a plugin that lets
     * us look after your site" is installed, which is true whether or not the host's White Label gives
     * the plugin the host's brand on the site.
     */
    public function testAClientNeverReadsTheNameOfTheToolBehindTheService(): void
    {
        $root = dirname(__DIR__) . '/modules/addons/mainwp/';
        // All that the client's two templates send to a browser, markup included; the page's own address comes from Module::NAME.
        foreach (['templates/client-connect.tpl', 'templates/client-panel.tpl'] as $file) {
            $sent = (string) preg_replace('/\\{\\*.*?\\*\\}/s', '', (string) file_get_contents($root . $file));
            self::assertStringNotContainsStringIgnoringCase('mainwp', $sent, $file);
        }
        $connect = (string) file_get_contents($root . 'templates/client-connect.tpl');
        self::assertSame(3, substr_count($connect, ' that lets us look after '), 'each of the three paragraphs that say what is installed');
        self::assertSame(3, substr_count($connect, 'install a plugin '));
        // Every string of the classes that build what a client reads, and of the class that talks to a client's own site.
        foreach (['lib/ClientPage.php' => 60, 'lib/Panels.php' => 20, 'lib/Engine/WordPressSite.php' => 40, 'lib/ServiceName.php' => 0] as $file => $atLeast) {
            $said = 0;
            foreach (token_get_all((string) file_get_contents($root . $file)) as $token) {
                if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                    $said++;
                    self::assertStringNotContainsStringIgnoringCase('mainwp', str_replace(self::NOT_WORDS, '', $token[1]), $file . ', line ' . $token[2]);
                }
            }
            self::assertGreaterThan($atLeast, $said, $file);
        }
        // The engine's fixed sentences: all but the staff's dry-run line are, or may become, a client's reason or note.
        foreach ((new \ReflectionClass(CareEngine::class))->getReflectionConstants() as $constant) {
            $value = $constant->getValue();
            if (is_string($value) && $constant->getName() !== 'ONLY_IF_REFUSED') {
                self::assertStringNotContainsStringIgnoringCase('mainwp', $value, 'CareEngine::' . $constant->getName());
            }
        }
        // The page's own query names: WordPress prints the return address on the client's approval screen, and the others show in the address bar.
        foreach ([\MainWpForWhmcs\ClientPage::Q_RETURN, \MainWpForWhmcs\ClientPage::Q_REJECTED, \MainWpForWhmcs\ClientPage::Q_RESULT, \MainWpForWhmcs\ClientPage::Q_NOTE, \MainWpForWhmcs\ClientPage::Q_KEY] as $name) {
            self::assertStringNotContainsStringIgnoringCase('mainwp', $name);
        }
        self::assertStringNotContainsStringIgnoringCase('mainwp', ServiceName::NEUTRAL, 'the key of the client\'s panel, which a theme may print');
    }

    public function testTheModulesNameIsNeverPutInFrontOfAClient(): void
    {
        foreach (['client-panel.tpl', 'client-connect.tpl'] as $template) {
            // What is rendered: a template's own comment is never sent to the browser.
            $shown = (string) preg_replace('/\{\*.*?\*\}/s', '', (string) file_get_contents(__DIR__ . '/../modules/addons/mainwp/templates/' . $template));
            self::assertStringNotContainsString('for WHMCS', $shown, $template);
        }
        foreach (['ClientPage.php', 'Panels.php', 'ServiceName.php'] as $file) {
            self::assertStringNotContainsString('DISPLAY_NAME', (string) file_get_contents(__DIR__ . '/../modules/addons/mainwp/lib/' . $file), $file . ' builds what clients read');
        }
    }

    /** Every template carries the licence, in a comment that is never sent to a browser. */
    public function testEveryTemplateCarriesItsLicence(): void
    {
        foreach (glob(self::MODULE . '/templates/*.tpl') ?: [] as $template) {
            self::assertMatchesRegularExpression('/^\{\*[^*]*SPDX-License-Identifier: AGPL-3\.0-or-later/', (string) file_get_contents($template), basename($template));
        }
        self::assertCount(4, glob(self::MODULE . '/templates/*.tpl') ?: []);
    }
}
