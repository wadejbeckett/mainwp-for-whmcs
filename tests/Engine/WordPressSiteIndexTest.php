<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Engine;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\Engine\HttpResponse;
use MainWpForWhmcs\Engine\SiteFailure;
use MainWpForWhmcs\Engine\SiteIndex;
use MainWpForWhmcs\Engine\WordPressSite;
use MainWpForWhmcs\Tests\Support\SiteFixtureHttp;

/**
 * What the module learns from a site's public REST index before it offers a route, on the answers recorded on the bench.
 */
final class WordPressSiteIndexTest extends TestCase
{
    private const URL = 'https://mainwp-bench-site.test';

    private SiteFixtureHttp $http;

    protected function setUp(): void
    {
        $this->http = new SiteFixtureHttp();
    }

    private function index(string $url = self::URL): SiteIndex
    {
        return (new WordPressSite($this->http))->index($url);
    }

    private function failure(string $url = self::URL): SiteFailure
    {
        try {
            $this->index($url);
        } catch (SiteFailure $failure) {
            return $failure;
        }
        self::fail('the index was read');
    }

    private static function json(string $body): HttpResponse
    {
        return new HttpResponse(200, $body, ['content-type' => ['application/json; charset=UTF-8']]);
    }

    public function testAPlainSiteOffersItsApprovalScreenAndShowsNoWordfence(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        $index = $this->index();

        self::assertSame(self::URL . '/wp-admin/authorize-application.php', $index->approvalUrl);
        self::assertFalse($index->wordfence);
        foreach ($this->http->sent as $request) {
            self::assertArrayNotHasKey('Authorization', $request['headers'], 'the index is read without any login');
        }
    }

    public function testWordfenceWithItsDefaultsShowsInTheIndexAndOffersNoApprovalScreen(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index-wordfence');

        $index = $this->index();

        self::assertNull($index->approvalUrl);
        self::assertTrue($index->wordfence);
    }

    public function testAnApprovalAddressOnAnotherHostOrOverPlainHttpIsNotOffered(): void
    {
        $body = static fn (string $address): HttpResponse => self::json(json_encode(['namespaces' => ['wp/v2'], 'authentication' => ['application-passwords' => ['endpoints' => ['authorization' => $address]]]], JSON_THROW_ON_ERROR));
        $this->http->on('GET', '/', 'home', 'home', 'home')
            ->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', $body('https://elsewhere.example/wp-admin/authorize-application.php'), $body('http://mainwp-bench-site.test/wp-admin/authorize-application.php'), $body('https://www.mainwp-bench-site.test/wp-admin/authorize-application.php'));

        self::assertNull($this->index()->approvalUrl, 'another host');
        self::assertNull($this->index()->approvalUrl, 'plain http');
        self::assertSame('https://www.mainwp-bench-site.test/wp-admin/authorize-application.php', $this->index()->approvalUrl, 'the www form of the same host is the same site');
    }

    public function testARestRootThatTheHomePageNamesOnAnotherHostIsNotFollowed(): void
    {
        $this->http->on('GET', '/', new HttpResponse(200, '<html></html>', ['link' => ['<https://elsewhere.example/wp-json/>; rel="https://api.w.org/"']]))
            ->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        $index = $this->index();

        self::assertSame(self::URL . '/wp-json/', $index->restRoot, 'a login or a key is only ever sent to the site itself');
        self::assertSame(['GET /', 'GET /wp-json/?_fields=authentication,namespaces,home,url'], $this->http->requests());
        self::assertSame('mainwp-bench-site.test', parse_url($this->http->sent[1]['url'], PHP_URL_HOST));
    }

    public function testASiteThatSendsVisitorsToAnotherHostSaysWhichAddressToUse(): void
    {
        // Not recorded: the bench has one host name. What a server answers that prefers "www." (HTTP 301 on every address).
        $this->http->on('GET', '/', new HttpResponse(301, '', ['location' => ['https://www.mainwp-bench-site.test/']]));

        $failure = $this->failure();

        self::assertSame(SiteFailure::MOVED, $failure->kind);
        self::assertSame('https://www.mainwp-bench-site.test', $failure->movedTo);
        self::assertStringContainsString('sends visitors on to www.mainwp-bench-site.test', $failure->getMessage());
        self::assertCount(1, $this->http->sent, 'nothing is asked of the other host until it is the stored address');
    }

    public function testAFolderIsKeptWhenTheHostMovesAndAPlainHttpTargetIsNamedAsSuch(): void
    {
        $this->http->on('GET', '/blog/', new HttpResponse(301, '', ['location' => ['https://www.mainwp-bench-site.test/blog/']]), new HttpResponse(302, '', ['location' => ['http://www.mainwp-bench-site.test/blog/']]));

        self::assertSame('https://www.mainwp-bench-site.test/blog', $this->failure(self::URL . '/blog')->movedTo);
        $failure = $this->failure(self::URL . '/blog');
        self::assertSame([SiteFailure::MOVED, 'http://www.mainwp-bench-site.test/blog'], [$failure->kind, $failure->movedTo], 'the caller sees that the target is not https');
    }

    public function testAHomePageThatRedirectsWithinTheSiteIsStillRead(): void
    {
        $this->http->on('GET', '/', new HttpResponse(302, '', ['location' => ['https://mainwp-bench-site.test/en/']]))->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        self::assertSame(self::URL . '/wp-admin/authorize-application.php', $this->index()->approvalUrl, 'a language or landing redirect is not a move');
    }

    public function testTheIndexSaysWhichAddressTheSiteCallsItsOwn(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        $index = $this->index();

        self::assertSame([self::URL, self::URL], [$index->home, $index->siteUrl]);
        self::assertNull($index->answersElsewhere(self::URL), 'typed as the site reports it');
    }

    public function testAFolderWithNoWordPressOfItsOwnIsAnsweredByTheMainSite(): void
    {
        // WordPress sends the main site's Link header on every path, so the folder's "index" is the main site's.
        $this->http->on('GET', '/nothing-here/', 'folder-without-wordpress')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index');

        $index = $this->index(self::URL . '/nothing-here');

        self::assertSame(self::URL, $index->answersElsewhere(self::URL . '/nothing-here'));
    }

    public function testAWordPressInAFolderIsItsOwnSite(): void
    {
        $this->http->on('GET', '/blog/', 'home-wordpress-in-folder')->on('GET', '/blog/wp-json/?_fields=authentication,namespaces,home,url', 'rest-index-wordpress-in-folder');

        $index = $this->index(self::URL . '/blog');

        self::assertNull($index->answersElsewhere(self::URL . '/blog'));
        self::assertSame(self::URL . '/blog/wp-admin/authorize-application.php', $index->approvalUrl);
        self::assertSame(self::URL . '/blog', $index->answersElsewhere(self::URL), 'the main address is not the folder\'s site');
    }

    public function testTheSchemeTheHostsCaseItsWwwFormAndATrailingSlashDoNotMakeAnotherSite(): void
    {
        $index = new SiteIndex(self::URL . '/wp-json/', null, false, 'http://WWW.mainwp-bench-site.test/', 'http://www.mainwp-bench-site.test');

        foreach ([self::URL, self::URL . '/', 'https://www.mainwp-bench-site.test', 'https://Mainwp-Bench-Site.test'] as $typed) {
            self::assertNull($index->answersElsewhere($typed), $typed);
        }
        self::assertSame('https://www.mainwp-bench-site.test', $index->answersElsewhere(self::URL . '/shop'), 'named over https, as the typed address was read, without its trailing slash');
        self::assertSame(self::URL . '/blog', (new SiteIndex(self::URL . '/wp-json/', null, false, self::URL . '/blog'))->answersElsewhere(self::URL . '/Blog'), 'a path keeps its case');
    }

    public function testTheDefaultPortOfAnAddressSchemeDoesNotMakeAnotherHost(): void
    {
        $index = new SiteIndex(self::URL . '/wp-json/', null, false, self::URL, self::URL);

        self::assertNull($index->answersElsewhere(self::URL . ':443'), 'the site\'s own address');
        self::assertSame(self::URL, $index->answersElsewhere(self::URL . ':443/nothing-here'), 'the same host: its WordPress is named');
        self::assertSame(self::URL, (new SiteIndex(self::URL . '/wp-json/', null, false, self::URL . ':443'))->answersElsewhere(self::URL . '/shop'), 'named without the default port');
        self::assertNull($index->answersElsewhere(self::URL . ':8443/shop'), 'another port is another host');
    }

    public function testASiteWhoseWordPressFilesLiveInAFolderIsTakenAtEitherAddress(): void
    {
        // WordPress's "url" (where its files are) may differ from "home" (where visitors go).
        $index = new SiteIndex(self::URL . '/wp-json/', null, false, self::URL, self::URL . '/wp');

        self::assertNull($index->answersElsewhere(self::URL));
        self::assertNull($index->answersElsewhere(self::URL . '/wp'));
        self::assertSame(self::URL, $index->answersElsewhere(self::URL . '/other'), 'the address visitors use is the one named');
    }

    public function testASiteThatReportsAnotherHostIsLeftToConnectAsBefore(): void
    {
        // A domain alias, or a mapped address, served without a redirect: WordPress names another host as its own.
        $index = new SiteIndex(self::URL . '/wp-json/', null, false, 'https://elsewhere.example', 'https://elsewhere.example');

        self::assertNull($index->answersElsewhere(self::URL));
        self::assertNull($index->answersElsewhere(self::URL . '/nothing-here'), 'nothing on the typed host says where its WordPress is');
        // One address on the typed host is enough to name it, whichever of the two it is.
        self::assertSame(self::URL . '/wp', (new SiteIndex(self::URL . '/wp-json/', null, false, 'https://elsewhere.example', self::URL . '/wp'))->answersElsewhere(self::URL . '/other'));
    }

    public function testAnIndexThatReportsNoAddressChangesNothing(): void
    {
        $this->http->on('GET', '/nothing-here/', 'folder-without-wordpress')
            ->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', self::json('{"namespaces":["wp/v2"],"authentication":[]}'));

        $index = $this->index(self::URL . '/nothing-here');

        self::assertSame([null, null], [$index->home, $index->siteUrl]);
        self::assertNull($index->answersElsewhere(self::URL . '/nothing-here'));
        self::assertNull((new SiteIndex(self::URL . '/wp-json/', null, false, 'not an address'))->answersElsewhere(self::URL . '/x'), 'nor one that is no address');
    }

    public function testAnHttpLoginInFrontOfTheSiteIsBlocked(): void
    {
        $this->http->on('GET', '/', new HttpResponse(401, '<html>Unauthorized</html>', ['www-authenticate' => ['Basic realm="Private"']]));

        $failure = $this->failure();

        self::assertSame(SiteFailure::BLOCKED, $failure->kind);
        self::assertCount(1, $this->http->sent, 'nothing more is asked of a site that refuses');
    }

    public function testAnIndexThatIsRefusedIsBlocked(): void
    {
        $this->http->on('GET', '/', 'home')->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', new HttpResponse(403, '{"code":"rest_forbidden","message":"Sorry, you are not allowed to do that.","data":{"status":403}}'));

        self::assertSame(SiteFailure::BLOCKED, $this->failure()->kind);
    }

    public function testAnAddressWithoutWordPressAnswersBadly(): void
    {
        $this->http->on('GET', '/', new HttpResponse(200, '<html>It works!</html>'), new HttpResponse(200, '<html>It works!</html>'))
            ->on('GET', '/wp-json/?_fields=authentication,namespaces,home,url', new HttpResponse(404, '<html>Not Found</html>'), new HttpResponse(200, '<html>Not JSON</html>'));

        $failure = $this->failure();

        self::assertSame(SiteFailure::BAD_ANSWER, $failure->kind);
        self::assertStringContainsString('REST API', $failure->getMessage());
        self::assertSame(SiteFailure::BAD_ANSWER, $this->failure()->kind, 'an answer that is not JSON');
    }

    public function testAPlainHttpAddressIsRefusedBeforeAnythingIsSent(): void
    {
        self::assertSame(SiteFailure::NOT_HTTPS, $this->failure('http://mainwp-bench-site.test')->kind);
        self::assertSame([], $this->http->sent);
    }

    public function testAnUnreachableSiteSaysSo(): void
    {
        $this->http->on('GET', '/', new \RuntimeException('Could not resolve host: mainwp-bench-site.test'));

        $failure = $this->failure();

        self::assertSame(SiteFailure::UNREACHABLE, $failure->kind);
        self::assertStringContainsString('could not be reached', $failure->getMessage());
    }
}
