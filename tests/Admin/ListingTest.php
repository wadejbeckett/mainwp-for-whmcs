<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Admin;

use MainWpForWhmcs\Admin\Listing;
use PHPUnit\Framework\TestCase;

/** A list's order and page as the address asks for them, and WHMCS's own furniture around it as data. */
final class ListingTest extends TestCase
{
    private const LINK = 'addonmodules.php?module=mainwp';

    protected function tearDown(): void
    {
        unset($GLOBALS['_ADMINLANG']);
    }

    /** @param array<string, mixed> $get */
    private function log(array $get, array $filter = [], int $perPage = 50): Listing
    {
        return Listing::fromRequest($get, ['time', 'by', 'action'], 'time', 'desc', $perPage, $filter, self::LINK, 'log');
    }

    public function testWithoutAWordInTheAddressTheListIsInItsOwnOrderOnPageOne(): void
    {
        $list = $this->log([]);
        self::assertSame(['time', 'desc', 1, 50], [$list->orderBy, $list->order, $list->page, $list->perPage]);
        self::assertSame(self::LINK . '&tab=log', $list->link());
    }

    public function testTheAddressPicksAHeadingADirectionAndAPage(): void
    {
        $list = $this->log(['orderby' => 'by', 'order' => 'DESC', 'page' => '3']);
        self::assertSame(['by', 'desc', 3], [$list->orderBy, $list->order, $list->page]);
        self::assertSame('asc', $this->log(['orderby' => 'by'])->order, 'another heading starts ascending');
        self::assertSame('asc', $this->log(['orderby' => 'time', 'order' => 'asc'])->order);
    }

    public function testAnythingElseInTheAddressIsTheDefault(): void
    {
        foreach ([['orderby' => 'detail; DROP TABLE x'], ['orderby' => ['by']], ['order' => 'sideways'], ['page' => '0'], ['page' => '-2'], ['page' => '1e3'], ['page' => '99999999999999999999'], ['page' => ['2']]] as $get) {
            $list = $this->log($get);
            self::assertSame(['time', 'desc', 1], [$list->orderBy, $list->order, $list->page], json_encode($get, JSON_THROW_ON_ERROR));
        }
    }

    public function testAPagePastTheLastIsTheLast(): void
    {
        $list = $this->log(['page' => '9'], [], 50);
        self::assertSame(3, $list->pages(135));
        self::assertSame(3, $list->pageOf(135));
        self::assertSame(100, $list->offset(135));
        self::assertSame([1, 1, 0], [$list->pages(0), $list->pageOf(0), $list->offset(0)], 'an empty list has one page');
        self::assertSame(1, $this->log([], [], 50)->pages(50));
        self::assertSame(2, $this->log([], [], 50)->pages(51));
    }

    public function testEveryLinkKeepsTheFilterAndLeavesOutWhatIsAtItsDefault(): void
    {
        $list = $this->log(['orderby' => 'by', 'order' => 'desc', 'page' => '2'], ['search' => 'add-on 66 & more', 'outcome' => '']);
        self::assertSame(self::LINK . '&tab=log&search=add-on%2066%20%26%20more&orderby=by&order=desc&page=2', $list->link());
        self::assertSame(self::LINK . '&tab=log&search=add-on%2066%20%26%20more&orderby=by&order=desc', $list->link(['page' => 1]));
        self::assertSame(self::LINK . '&tab=log&search=add-on%2066%20%26%20more&page=2', $list->link(['orderby' => 'time', 'order' => 'desc']));
        self::assertSame(self::LINK . '&tab=log&orderby=by&order=desc', $list->link(['search' => '', 'page' => 1]), 'a filter emptied is a filter gone: the way back to the whole list');
    }

    public function testTheRecordsLineAndTheJumpMenuAreWhmcssOwn(): void
    {
        $model = $this->log(['page' => '2'])->model(135);
        self::assertSame([135, 2, 3], [$model['total'], $model['page'], $model['pages']]);
        self::assertSame('135 Records Found, Page 2 of 3', $model['summary']);
        self::assertSame('Jump to Page', $model['jumpLabel']);
        self::assertSame([[1, false], [2, true], [3, false]], array_map(static fn (array $j): array => [$j['page'], $j['current']], $model['jump']));
        self::assertSame(self::LINK . '&tab=log&page=3', $model['jump'][2]['link']);
        self::assertSame(self::LINK . '&tab=log', $model['jump'][0]['link']);
        self::assertSame([['name' => 'module', 'value' => 'mainwp'], ['name' => 'tab', 'value' => 'log']], $model['hidden'], 'what the jump form sends beside the page');
    }

    public function testTheRecordsLineSpeaksTheAdminsLanguage(): void
    {
        $GLOBALS['_ADMINLANG'] = ['global' => ['recordsfound' => 'Einträge gefunden', 'page' => 'Seite', 'of' => 'von', 'jumppage' => 'Gehe zu Seite', 'previouspage' => 'Vorherige Seite', 'nextpage' => '']];
        $model = $this->log([])->model(3);
        self::assertSame('3 Einträge gefunden, Seite 1 von 1', $model['summary']);
        self::assertSame('Gehe zu Seite', $model['jumpLabel']);
        self::assertSame(['« Vorherige Seite', '1', 'Next Page »'], array_column($model['pager'], 'label'), 'a word WHMCS has not got stays English');
    }

    public function testThePageLinksAreTheNeighboursBetweenPreviousAndNext(): void
    {
        $pager = $this->log(['page' => '5'], [], 10)->model(95)['pager'];
        self::assertSame(['« Previous Page', '3', '4', '5', '6', '7', 'Next Page »'], array_column($pager, 'label'));
        self::assertSame([4, 3, 4, 5, 6, 7, 6], array_column($pager, 'page'));
        self::assertSame([false, false, false, true, false, false, false], array_column($pager, 'current'));
        self::assertSame([false, false, false, false, false, false, false], array_column($pager, 'disabled'));

        $first = $this->log([], [], 10)->model(95)['pager'];
        self::assertSame(['« Previous Page', '1', '2', '3', 'Next Page »'], array_column($first, 'label'));
        self::assertTrue($first[0]['disabled']);
        self::assertFalse($first[4]['disabled']);
        $last = $this->log(['page' => '10'], [], 10)->model(95)['pager'];
        self::assertSame(['« Previous Page', '8', '9', '10', 'Next Page »'], array_column($last, 'label'));
        self::assertTrue($last[4]['disabled']);
        $only = $this->log([])->model(0)['pager'];
        self::assertSame([true, false, true], array_column($only, 'disabled'));
    }

    public function testAHeadingsLinkSortsByItAndTurnsTheCurrentOneRound(): void
    {
        $sort = $this->log(['page' => '4'])->model(500)['sort'];
        self::assertSame(['time', 'by', 'action'], array_keys($sort));
        self::assertSame(['desc', '', ''], array_column($sort, 'direction'));
        self::assertSame(self::LINK . '&tab=log&orderby=time&order=asc', $sort['time']['link'], 'the current heading the other way, from page one');
        self::assertSame(self::LINK . '&tab=log&orderby=by&order=asc', $sort['by']['link']);

        $sort = $this->log(['orderby' => 'by', 'order' => 'asc'])->model(500)['sort'];
        self::assertSame(['', 'asc', ''], array_column($sort, 'direction'));
        self::assertSame(self::LINK . '&tab=log&orderby=by&order=desc', $sort['by']['link']);
        self::assertSame(self::LINK . '&tab=log&orderby=time&order=asc', $sort['time']['link']);
    }

    public function testInsideWhmcsADateIsShownInTheAdminsOwnFormatWithOrWithoutItsTime(): void
    {
        // WHMCS's fromMySQLDate() exists only inside WHMCS: a process of its own stands in for it here.
        $code = 'function fromMySQLDate($d, $time = false) { $t = strtotime((string) $d); return $d === "2000-01-01" ? "" : date($time ? "d/m/Y H:i" : "d/m/Y", $t); }'
            . ' require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
            . ' echo json_encode([MainWpForWhmcs\Admin\Listing::date("2026-11-11 08:36:26"), MainWpForWhmcs\Admin\Listing::date("2026-11-12", false), MainWpForWhmcs\Admin\Listing::date(""), MainWpForWhmcs\Admin\Listing::date("2000-01-01", false)]);';
        $out = shell_exec(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code) . ' 2>&1');

        self::assertSame(['11/11/2026 08:36', '12/11/2026', '', '2000-01-01'], json_decode((string) $out, true), (string) $out);
    }

    public function testADateIsShownAsItIsStoredOutsideWhmcsAndNotAtAllWhenThereIsNone(): void
    {
        self::assertSame('2026-11-11 08:36:26', Listing::date('2026-11-11 08:36:26'));
        self::assertSame('', Listing::date(''));
        self::assertSame('', Listing::date('0000-00-00 00:00:00'));
    }
}
