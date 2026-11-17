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
 * The site interface on the key route, driven by the exchanges recorded on the bench on
 * 10 November 2026 (tests/fixtures/wordpress/key-*.json, http-auth-*.json).
 */
final class WordPressSiteKeyRouteTest extends TestCase
{
    private const URL = 'https://mainwp-bench-site.test';
    private const KEY = 'BenchKey0123456789ABCDEF';
    private const CARE_PASSWORD = 'CarePass0123456789abcdefABCDEF01';
    private const CARE = 'wpcare-k7m2x9';
    private const EMAIL = 'wpcare-k7m2x9@care.bench.test';
    private const USERS = '/wp-json/wp/v2/users';
    private const BY_SLUG = '/wp-json/wp/v2/users?context=edit&slug=wpcare-k7m2x9';
    private const DELETE_CARE = '/wp-json/wp/v2/users/2?force=true&reassign=1';

    private SiteFixtureHttp $http;

    protected function setUp(): void
    {
        $this->http = new SiteFixtureHttp();
    }

    private function site(): WordPressSite
    {
        return new WordPressSite($this->http);
    }

    private function key(): Login
    {
        return new Login('owner', self::KEY);
    }

    private function install(): string
    {
        return $this->site()->installPluginWithKey(self::URL, $this->key(), new Login(self::CARE, self::CARE_PASSWORD), self::EMAIL, 'Site Care Plus');
    }

    /** @param callable(): mixed $call */
    private function failure(callable $call): SiteFailure
    {
        try {
            $call();
        } catch (SiteFailure $failure) {
            self::assertStringNotContainsString(self::KEY, $failure->getMessage());
            self::assertStringNotContainsString(self::CARE_PASSWORD, $failure->getMessage());
            return $failure;
        }
        self::fail('the call did not fail');
    }

    private function approvedByAnAdministrator(): self
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'key-users-me');
        return $this;
    }

    public function testWithTheKeyTheCareAccountIsMadeAndThePluginInstalledAndActivated(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, 'key-user-create')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install');

        self::assertSame(self::CARE, $this->install(), 'the login name the dashboard connects with');

        self::assertSame(['GET /', 'GET /wp-json/wp/v2/users/me?context=edit', 'POST ' . self::USERS, 'GET /wp-json/wp/v2/plugins?search=mainwp-child', 'POST /wp-json/wp/v2/plugins'], $this->http->requests());
        self::assertArrayNotHasKey('Authorization', $this->http->sent[0]['headers'], 'the home page is read without the key');
        foreach (array_slice($this->http->sent, 1) as $request) {
            self::assertSame('Basic ' . base64_encode('owner:' . self::KEY), $request['headers']['Authorization'], 'the key as HTTP Basic authentication');
            self::assertArrayNotHasKey('Cookie', $request['headers'], 'no session is opened on the key route');
            self::assertArrayNotHasKey('X-WP-Nonce', $request['headers']);
            self::assertSame('mainwp-bench-site.test', parse_url($request['url'], PHP_URL_HOST), 'the key goes to the site itself and nowhere else');
        }
        self::assertSame(['username' => self::CARE, 'email' => self::EMAIL, 'password' => self::CARE_PASSWORD, 'roles' => 'administrator', 'name' => 'Site Care Plus'], $this->http->sent[2]['form'], 'the display name is the one the site is given');
        self::assertSame(['slug' => 'mainwp-child', 'status' => 'active'], $this->http->sent[4]['form']);
    }

    public function testACareAccountMadeBeforeARefusedInstallIsRemovedAgain(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, 'key-user-create')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install-forbidden')
            ->on('DELETE', self::DELETE_CARE, 'key-user-delete');

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::INSTALL_FORBIDDEN, $failure->kind);
        self::assertSame('DELETE ' . self::DELETE_CARE, $this->http->requests()[5], 'removed by its id, its content (none) given to the approving account');
        self::assertStringNotContainsString('could not be removed', $failure->getMessage());
    }

    public function testACareAccountThatCannotBeRemovedAfterARefusedInstallIsNamedInTheReason(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, 'key-user-create')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install-forbidden')
            ->on('DELETE', self::DELETE_CARE, new \RuntimeException('Operation timed out'));

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::INSTALL_FORBIDDEN, $failure->kind);
        self::assertStringContainsString('The account wpcare-k7m2x9 made for care could not be removed again', $failure->getMessage());
    }

    public function testACareAccountLeftByAnEarlierAttemptIsFoundAndGivenThePasswordAgain(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, 'key-user-create-exists')
            ->on('GET', self::BY_SLUG, 'key-users-by-slug')
            ->on('POST', self::USERS . '/2', 'key-user-update')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-inactive')
            ->on('POST', '/wp-json/wp/v2/plugins/mainwp-child/mainwp-child', 'plugin-activate');

        self::assertSame(self::CARE, $this->install());

        self::assertSame(['password' => self::CARE_PASSWORD, 'roles' => 'administrator'], $this->http->sent[4]['form'], 'the account the module made earlier is the one the dashboard can log in with again');
        self::assertContains('POST /wp-json/wp/v2/plugins/mainwp-child/mainwp-child', $this->http->requests(), 'a plugin that switched itself off is activated again');
    }

    /** WordPress's own refusal of an address another account holds (wp_insert_user, as the username's in key-user-create-exists; the bench's eighth run saw the same words through MainWP). */
    private static function emailTaken(): HttpResponse
    {
        return new HttpResponse(500, '{"code":"existing_user_email","message":"Sorry, that email address is already used!","data":null}', ['content-type' => ['application/json; charset=UTF-8']]);
    }

    public function testAnAddressAnotherAccountHoldsIsTriedOnceMoreTaggedWithTheCareLogin(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, self::emailTaken(), 'key-user-create')
            ->on('GET', '/wp-json/wp/v2/plugins?search=mainwp-child', 'plugins-search-absent')
            ->on('POST', '/wp-json/wp/v2/plugins', 'plugin-install');

        $name = $this->site()->installPluginWithKey(self::URL, $this->key(), new Login(self::CARE, self::CARE_PASSWORD), 'wordpress@care.bench.test', 'WordPress Care');

        self::assertSame(self::CARE, $name);
        self::assertSame(['wordpress@care.bench.test', 'wordpress+' . self::CARE . '@care.bench.test'], [$this->http->sent[2]['form']['email'] ?? null, $this->http->sent[3]['form']['email'] ?? null]);
        self::assertSame(self::CARE, $this->http->sent[3]['form']['username'] ?? null, 'the same login: only the address was taken');
    }

    public function testATaggedAddressThatIsTakenTooIsTheRefusal(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, self::emailTaken(), self::emailTaken());

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::ACCOUNT_REFUSED, $failure->kind);
        self::assertCount(4, $this->http->sent, 'one more try, no more');
        self::assertStringContainsString('Sorry, that email address is already used!', $failure->getMessage());
    }

    public function testAnApproverWhoIsNotAnAdministratorIsRefusedBeforeAnythingIsChanged(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'users-me-editor');

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::NOT_ADMIN, $failure->kind);
        self::assertStringContainsString('not an administrator', $failure->getMessage());
        self::assertCount(2, $this->http->sent);
    }

    public function testAKeyTheSiteDoesNotAcceptSaysWhatMayStopIt(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'key-users-me-no-header');

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::KEY_REFUSED, $failure->kind);
        self::assertStringContainsString('did not accept the connection you approved', $failure->getMessage());
        self::assertStringNotContainsString('Authorization', $failure->getMessage(), 'the client is not told about headers');
        self::assertStringContainsString('Authorization header', $failure->staffNote, 'staff are: a server that strips the header answers exactly as for a wrong key (bench)');
        self::assertCount(2, $this->http->sent);
    }

    public function testAnHttpLoginInFrontOfTheSiteIsBlocked(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/wp/v2/users/me?context=edit', 'http-auth-users-me');

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::BLOCKED, $failure->kind);
        self::assertStringContainsString('HTTP login', $failure->getMessage());
    }

    public function testASiteThatRefusesTheCareAccountSaysWhyInItsOwnWords(): void
    {
        $this->approvedByAnAdministrator()->http->on('POST', self::USERS, 'key-user-create-wordfence');

        $failure = $this->failure(fn () => $this->install());

        self::assertSame(SiteFailure::ACCOUNT_REFUSED, $failure->kind);
        self::assertStringContainsString('The password provided is too weak', $failure->getMessage());
        self::assertStringNotContainsString('<', $failure->getMessage(), 'no markup in a reason');
        self::assertCount(3, $this->http->sent, 'no plugin is installed for an account that could not be made');
    }

    public function testAnAccountIsRemovedByItsLoginAndOneAlreadyGoneIsDone(): void
    {
        $this->approvedByAnAdministrator()->http->on('GET', self::BY_SLUG, 'key-users-by-slug')->on('DELETE', self::DELETE_CARE, 'key-user-delete');
        $this->site()->removeAccount(self::URL, $this->key(), self::CARE);
        self::assertSame('DELETE ' . self::DELETE_CARE, $this->http->requests()[3]);

        $this->setUp();
        $this->approvedByAnAdministrator()->http->on('GET', self::BY_SLUG, 'key-users-by-slug-none');
        $this->site()->removeAccount(self::URL, $this->key(), self::CARE);
        self::assertCount(3, $this->http->sent, 'nothing to delete');

        $this->setUp();
        $this->approvedByAnAdministrator()->http->on('GET', self::BY_SLUG, 'key-users-by-slug')->on('DELETE', self::DELETE_CARE, 'key-user-delete-unknown');
        $this->site()->removeAccount(self::URL, $this->key(), self::CARE);
        self::assertCount(4, $this->http->sent, 'gone between the look-up and the delete: done');
    }

    public function testOnlyACareAccountIsEverRemoved(): void
    {
        foreach (['owner', 'admin', 'WPCARE-k7m2x9x', ''] as $name) {
            try {
                $this->site()->removeAccount(self::URL, $this->key(), $name);
                self::fail('removed ' . $name);
            } catch (\InvalidArgumentException) {
                self::assertSame([], $this->http->sent, 'nothing is sent for an account the module did not make');
            }
        }
    }

    public function testTheKeyIsDeletedByItsOwnIdentifier(): void
    {
        $this->http->on('GET', '/', 'home')
            ->on('GET', '/wp-json/wp/v2/users/me/application-passwords/introspect', 'key-introspect')
            ->on('DELETE', '/wp-json/wp/v2/users/me/application-passwords/252b18c6-f4f1-4f5c-a5a5-5900bfc8610a', 'key-delete');

        $this->site()->deleteKey(self::URL, $this->key());

        self::assertSame(['GET /', 'GET /wp-json/wp/v2/users/me/application-passwords/introspect', 'DELETE /wp-json/wp/v2/users/me/application-passwords/252b18c6-f4f1-4f5c-a5a5-5900bfc8610a'], $this->http->requests());
        self::assertSame('Basic ' . base64_encode('owner:' . self::KEY), $this->http->sent[2]['headers']['Authorization']);
    }

    public function testAKeyThatNoLongerAuthenticatesCannotBeConfirmedDeleted(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/wp/v2/users/me/application-passwords/introspect', 'key-users-me-after-delete');

        $failure = $this->failure(fn () => $this->site()->deleteKey(self::URL, $this->key()));

        self::assertSame(SiteFailure::KEY_REFUSED, $failure->kind);
    }

    public function testAKeyAlreadyGoneWhenItIsDeletedIsDone(): void
    {
        // Not recorded: the bench cannot ask for this with a key that still works. WordPress's own answer for an unknown uuid.
        $gone = new HttpResponse(404, '{"code":"rest_application_password_not_found","message":"Application password not found.","data":{"status":404}}', ['content-type' => ['application/json; charset=UTF-8']]);
        $this->http->on('GET', '/', 'home')
            ->on('GET', '/wp-json/wp/v2/users/me/application-passwords/introspect', 'key-introspect')
            ->on('DELETE', '/wp-json/wp/v2/users/me/application-passwords/252b18c6-f4f1-4f5c-a5a5-5900bfc8610a', $gone);

        $this->site()->deleteKey(self::URL, $this->key());

        self::assertCount(3, $this->http->sent);
    }

    public function testAKeyIsNeverSentOverPlainHttp(): void
    {
        $http = 'http://mainwp-bench-site.test';
        $calls = [
            fn () => $this->site()->installPluginWithKey($http, $this->key(), new Login(self::CARE, self::CARE_PASSWORD), self::EMAIL, 'WordPress Care'),
            fn () => $this->site()->removeAccount($http, $this->key(), self::CARE),
            fn () => $this->site()->deleteKey($http, $this->key()),
        ];
        foreach ($calls as $call) {
            self::assertSame(SiteFailure::NOT_HTTPS, $this->failure($call)->kind);
        }
        self::assertSame([], $this->http->sent);
    }
}
