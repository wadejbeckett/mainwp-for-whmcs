<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Engine\Login;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\WordPressSite;
use MainWpForWhmcs\Tests\Support\SiteFixtureHttp;

/**
 * The site interface on the password route, driven by the exchanges recorded on the bench on
 * 10 November 2026 (tests/fixtures/wordpress/).
 */
final class WordPressSiteTest extends TestCase
{
    private const URL = 'https://mainwp-bench-site.test';
    private const PASSWORD = "it's \"a\" \\secret";

    private SiteFixtureHttp $http;

    protected function setUp(): void
    {
        $this->http = new SiteFixtureHttp();
    }

    private function install(string $username = 'owner'): string
    {
        return (new WordPressSite($this->http))->installPlugin(self::URL, new Login($username, self::PASSWORD));
    }

    private function failure(string $username = 'owner'): SiteFailure
    {
        try {
            $this->install($username);
        } catch (SiteFailure $failure) {
            $this->assertNoPasswordIn($failure->getMessage());
            return $failure;
        }
        self::fail('the route did not fail');
    }

    private function loginPage(string $page = 'login-page'): self
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-login.php', $page);
        return $this;
    }

    private function loggedIn(): self
    {
        $this->loginPage()->http->on('POST', '/wp-login.php', 'login-ok')
            ->on('GET', '/wp-admin/admin-ajax.php?action=rest-nonce', 'rest-nonce');
        return $this;
    }

    private function logout(): void
    {
        $this->http->on('GET', '/wp-login.php?action=logout', 'logout-confirm')
            ->on('GET', '/wp-login.php?action=logout&_wpnonce=abcdef0123', 'logout');
    }

    public function testPagesAreAskedForAsABrowserAsksAndRestRoutesAsJson(): void
    {
        // Bench, seventh run: to a client that accepts JSON, WordPress answers its "Do you really want to log out?"
        // page (and any other wp_die page) in JSON, and the logout link was not found in it: the session stayed open.
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install');
        $this->logout();

        $this->install('owner');

        $accept = [];
        foreach ($this->http->sent as $request) {
            $accept[(string) parse_url($request['url'], PHP_URL_PATH)][] = $request['headers']['Accept'] ?? '';
        }
        self::assertSame(['text/html', 'text/html', 'text/html', 'text/html'], $accept['/wp-login.php'], 'the login page, the login, the logout page and the logout');
        self::assertSame(['text/html'], $accept['/']);
        self::assertSame(['text/html'], $accept['/wp-admin/admin-ajax.php']);
        self::assertSame(['application/json'], $accept['/wp-json/wp/v2/users/me']);
        self::assertSame(['application/json', 'application/json'], $accept['/wp-json/wp/v2/plugins']);
    }

    public function testALogoutPageThatComesBackAsJsonStillEndsTheSession(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install')
            ->on('GET', '/wp-login.php?action=logout', 'logout-confirm-json')
            ->on('GET', '/wp-login.php?action=logout&_wpnonce=abcdef0123', 'logout');

        $this->install('owner');

        self::assertContains('GET /wp-login.php?action=logout&_wpnonce=abcdef0123', $this->http->requests(), 'the link is in the JSON\'s message');
    }

    private function assertNoPasswordIn(string $text): void
    {
        self::assertStringNotContainsString('secret', $text);
    }

    public function testTheWholeRouteLogsInOnceInstallsAndActivatesThePluginAndLogsOut(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install');
        $this->logout();

        self::assertSame('owner', $this->install('owner@site.bench.test'), 'the login name MainWP connects with, though the email was typed');

        self::assertSame([
            'GET /',
            'GET /wp-login.php',
            'POST /wp-login.php',
            'GET /wp-admin/admin-ajax.php?action=rest-nonce',
            'GET /wp-json/wp/v2/users/me?context=edit',
            'GET /wp-json/wp/v2/plugins?search=mainwp-child',
            'POST /wp-json/wp/v2/plugins',
            'GET /wp-login.php?action=logout',
            'GET /wp-login.php?action=logout&_wpnonce=abcdef0123',
        ], $this->http->requests());
        $sent = $this->http->sent;
        self::assertSame(['log' => 'owner@site.bench.test', 'pwd' => self::PASSWORD, 'redirect_to' => self::URL . '/wp-admin/', 'testcookie' => '1'], $sent[2]['form'], 'WordPress\'s own login form, sent once');
        self::assertStringContainsString('wordpress_test_cookie=', $sent[2]['headers']['Cookie'] ?? '');
        self::assertStringContainsString('wordpress_logged_in_c01618f55158f53ea7bf1fa8c15b67d7=', $sent[3]['headers']['Cookie'] ?? '');
        self::assertSame('0123abcd45', $sent[4]['headers']['X-WP-Nonce'] ?? null, 'the REST nonce');
        self::assertSame(['slug' => 'mainwp-child', 'status' => 'active'], $sent[6]['form']);
        self::assertSame('0123abcd45', $sent[6]['headers']['X-WP-Nonce'] ?? null);
        foreach ($sent as $i => $request) {
            $this->assertNoPasswordIn($request['url']);
            if ($i !== 2) {
                self::assertSame([], array_filter($request['form'], static fn (string $v): bool => str_contains($v, 'secret')), 'the password goes to the login form only');
            }
        }
    }

    public function testAnInstalledButInactivePluginIsActivated(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-inactive')
            ->on('POST', '/wp-json/wp/v2/plugins/mainwp-child/mainwp-child', 'plugin-activate');
        $this->logout();

        self::assertSame('owner', $this->install());
        self::assertSame(['status' => 'active'], $this->http->sent[6]['form']);
    }

    public function testAnActivePluginIsLeftAsItIs(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-active');
        $this->logout();

        self::assertSame('owner', $this->install());
        self::assertNotContains('POST /wp-json/wp/v2/plugins', $this->http->requests());
    }

    public function testAnInstallTheSiteDoesNotCompleteSaysThePluginCouldNotBeInstalled(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugins-search-absent'); // an answer that is not WordPress's "created"
        $this->logout();

        $failure = $this->failure();

        self::assertSame(SiteFailure::INSTALL_FAILED, $failure->kind);
        self::assertStringStartsWith('The plugin could not be installed on the site: ', $failure->getMessage());
        self::assertStringEndsWith('. Contact support.', $failure->getMessage());
    }

    public function testForbiddenFileChangesStopTheInstallInPlainWordsAndTheSessionIsLoggedOut(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install-forbidden');
        $this->logout();

        $failure = $this->failure();

        self::assertSame(SiteFailure::INSTALL_FORBIDDEN, $failure->kind);
        self::assertStringContainsString('does not allow plugins to be installed', $failure->getMessage());
        self::assertStringNotContainsString('MainWP', $failure->getMessage(), 'the client reads this sentence');
        self::assertContains('GET /wp-login.php?action=logout&_wpnonce=abcdef0123', $this->http->requests());
    }

    public function testAnAccountThatIsNotAnAdministratorIsRefused(): void
    {
        $this->loggedIn();
        $this->http->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me-editor');
        $this->logout();

        $failure = $this->failure('editor1');

        self::assertSame(SiteFailure::NOT_ADMIN, $failure->kind);
        self::assertStringContainsString('not an administrator', $failure->getMessage());
        self::assertNotContains('GET /wp-json/wp/v2/plugins?search=mainwp-child', $this->http->requests());
    }

    public function testAWrongPasswordIsRefusedAfterOneAttempt(): void
    {
        $this->loginPage()->http->on('POST', '/wp-login.php', 'login-wrong-password');

        $failure = $this->failure();

        self::assertSame(SiteFailure::LOGIN_REFUSED, $failure->kind);
        self::assertStringContainsString('username or password was not accepted', $failure->getMessage());
        self::assertSame(['GET /', 'GET /wp-login.php', 'POST /wp-login.php'], $this->http->requests(), 'one attempt, never repeated');
    }

    public function testAWrongPasswordOnASiteThatCountsAttemptsSaysSo(): void
    {
        $this->loginPage('loginizer-login-page')->http->on('POST', '/wp-login.php', 'loginizer-wrong-password');

        $failure = $this->failure();

        self::assertSame(SiteFailure::LOGIN_REFUSED, $failure->kind);
        self::assertStringContainsString('limits failed logins', $failure->getMessage());
    }

    public function testALockoutShownOnTheLoginPageStopsBeforeThePasswordIsSent(): void
    {
        $this->loginPage('loginizer-locked-page');

        $failure = $this->failure();

        self::assertSame(SiteFailure::LIMITER, $failure->kind);
        self::assertStringContainsString('locked out', $failure->getMessage());
        self::assertNotContains('POST /wp-login.php', $this->http->requests());
    }

    public function testALockoutAnsweredToTheLoginIsALimiter(): void
    {
        $this->loginPage('llar-locked-page')->http->on('POST', '/wp-login.php', 'llar-locked-post');

        self::assertSame(SiteFailure::LIMITER, $this->failure()->kind);
    }

    public function testAMovedLoginPageIsNotFound(): void
    {
        $this->http->on('GET', '/', 'home', 'home')->on('GET', '/wp-login.php', new HttpResponse(404, '<html>Page not found</html>'), new HttpResponse(302, '', ['location' => [self::URL . '/404/']]));

        self::assertSame(SiteFailure::LOGIN_NOT_FOUND, $this->failure()->kind);
        self::assertSame(SiteFailure::LOGIN_NOT_FOUND, $this->failure()->kind, 'a redirect away from the login page too');
    }

    public function testAnUnreachableSiteSaysSo(): void
    {
        $this->http->on('GET', '/', new \RuntimeException('Could not resolve host: mainwp-bench-site.test'));

        $failure = $this->failure();

        self::assertSame(SiteFailure::UNREACHABLE, $failure->kind);
        self::assertStringContainsString('could not be reached', $failure->getMessage());
    }

    public function testASiteThatTookTheCallAndDidNotAnswerInTimeSaysSo(): void
    {
        $this->http->on('GET', '/', new \MainWpForWhmcs\Engine\HttpTimeout('Operation timed out after 60001 milliseconds with 0 bytes received'));

        $failure = $this->failure();

        self::assertSame(SiteFailure::UNREACHABLE, $failure->kind);
        self::assertStringStartsWith('The site at mainwp-bench-site.test did not answer in time (Operation timed out after 60001 milliseconds with 0 bytes received).', $failure->getMessage());
        self::assertStringNotContainsString('could not be reached', $failure->getMessage());
    }

    public function testASiteWhoseAnswerIsTooLargeIsNotToldToCheckItsAddress(): void
    {
        $this->http->on('GET', '/', new \MainWpForWhmcs\Engine\HttpTooLarge('the answer was larger than 2 MB, so it was not read'));

        $failure = $this->failure();

        self::assertSame(SiteFailure::UNREACHABLE, $failure->kind, 'as any failed request');
        self::assertSame('The site at mainwp-bench-site.test sent an answer larger than 2 MB, so it was not read.', $failure->getMessage());
    }

    public function testAPlainHttpAddressIsRefusedBeforeAnythingIsSent(): void
    {
        try {
            (new WordPressSite($this->http))->installPlugin('http://mainwp-bench-site.test', new Login('owner', self::PASSWORD));
            self::fail('no failure');
        } catch (SiteFailure $failure) {
            self::assertSame(SiteFailure::NOT_HTTPS, $failure->kind);
        }
        self::assertSame([], $this->http->sent);
    }

    public function testAFirewallOrHttpLoginInFrontOfTheSiteIsBlocked(): void
    {
        $this->http->on('GET', '/', 'home', 'home')->on('GET', '/wp-login.php', new HttpResponse(401, 'Authorization Required', ['www-authenticate' => ['Basic realm="x"']]), new HttpResponse(403, 'Forbidden'));

        self::assertSame(SiteFailure::BLOCKED, $this->failure()->kind);
        self::assertSame(SiteFailure::BLOCKED, $this->failure()->kind);
    }

    public function testASiteThatSendsVisitorsToAnotherHostIsNotLoggedInToAndSaysWhichAddressToUse(): void
    {
        $this->http->on('GET', '/', new HttpResponse(301, '', ['location' => ['https://www.mainwp-bench-site.test/']]));

        $failure = $this->failure();

        self::assertSame(SiteFailure::MOVED, $failure->kind);
        self::assertSame('https://www.mainwp-bench-site.test', $failure->movedTo);
        self::assertCount(1, $this->http->sent, 'the password is never sent to an address that only redirects');
    }

    public function testThePublicRestIndexTellsWhetherTheKeyRouteIsOffered(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        $index = (new WordPressSite($this->http))->index(self::URL);

        self::assertSame(self::URL . '/wp-json/', $index->restRoot);
        self::assertSame(self::URL . '/wp-admin/authorize-application.php', $index->approvalUrl);
    }

    public function testTheRestIndexIsAskedOnlyForWhatIsReadOfIt(): void
    {
        // A site with many plugins (WooCommerce's routes among them) has a whole index well past CurlHttp::MAX_BODY.
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        $index = (new WordPressSite($this->http))->index(self::URL);

        self::assertSame(self::URL . '/wp-admin/authorize-application.php', $index->approvalUrl);
        self::assertSame(self::URL . '/wp-json/', $index->restRoot, 'the root itself is kept as found');

        $plain = new HttpResponse(200, '<html></html>', ['link' => ['<' . self::URL . '/?rest_route=/>; rel="https://api.w.org/"']]);
        $this->http->on('GET', '/', $plain)->on('GET', '/?rest_route=/&_fields=authentication,namespaces,home,url', 'rest-index');
        self::assertSame(self::URL . '/?rest_route=/', (new WordPressSite($this->http))->index(self::URL)->restRoot, 'a site with plain permalinks');
    }

    /** @return iterable<string, array{string}> */
    public static function captchaPages(): iterable
    {
        yield 'All-In-One Security\'s sum' => ['aios-captcha-login-page'];
        yield 'Advanced Google reCAPTCHA' => ['recaptcha-login-page'];
    }

    /** @dataProvider captchaPages */
    public function testACaptchaOnTheLoginPageStopsBeforeThePasswordIsSent(string $page): void
    {
        $this->loginPage($page);

        $failure = $this->failure();

        self::assertSame(SiteFailure::CAPTCHA, $failure->kind);
        self::assertStringContainsString('captcha', $failure->getMessage());
        self::assertNotContains('POST /wp-login.php', $this->http->requests());
    }

    public function testAllInOneSecuritysLockoutPageStopsBeforeThePasswordIsSent(): void
    {
        $this->loginPage('aios-locked-page');

        self::assertSame(SiteFailure::LIMITER, $this->failure()->kind, 'a 503 that says the address is blocked is the limiter, not a firewall');
        self::assertNotContains('POST /wp-login.php', $this->http->requests());
    }

    public function testLoginLimitersThatShowNothingBeforeTheLockoutLetTheLoginThrough(): void
    {
        $this->loginPage('llar-login-page')->http->on('POST', '/wp-login.php', 'llar-wrong-password');

        self::assertSame(SiteFailure::LOGIN_REFUSED, $this->failure()->kind);
    }

    public function testAPostAnsweredWithAllInOneSecuritysLockoutIsALimiter(): void
    {
        $this->loginPage()->http->on('POST', '/wp-login.php', 'aios-locked-post');

        self::assertSame(SiteFailure::LIMITER, $this->failure()->kind);
    }

    public function testASecondStepAfterThePasswordIsTwoFactorLogin(): void
    {
        $this->loginPage('two-factor-login-page')->http->on('POST', '/wp-login.php', 'two-factor-after-password');

        $failure = $this->failure();

        self::assertSame(SiteFailure::TWO_FACTOR, $failure->kind);
        self::assertStringContainsString('two-factor login', $failure->getMessage());
        self::assertSame(['GET /', 'GET /wp-login.php', 'POST /wp-login.php'], $this->http->requests(), 'no logout: no session was opened');
    }

    public function testALoginPageMovedByWpsHideLoginIsNotFound(): void
    {
        $this->loginPage('renamed-login-page');

        $failure = $this->failure();

        self::assertSame(SiteFailure::LOGIN_NOT_FOUND, $failure->kind);
        self::assertStringContainsString('login page was not found', $failure->getMessage());
    }

    public function testWordfenceWithItsDefaultsLetsThePasswordRouteThroughButOffersNoKey(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index-wordfence');
        self::assertNull((new WordPressSite($this->http))->index(self::URL)->approvalUrl);

        $this->loginPage('wordfence-login-page')->http->on('POST', '/wp-login.php', 'login-wrong-password');
        self::assertSame(SiteFailure::LOGIN_REFUSED, $this->failure()->kind, 'the login page alone shows nothing that stops the route');
    }
}
