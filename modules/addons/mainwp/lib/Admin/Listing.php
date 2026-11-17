<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Admin;

/**
 * What a list on the module's page was asked for, read from the address the way WHMCS's own lists
 * read it (orderby, order, page), and the furniture WHMCS puts around a list, as data for the
 * template: "N Records Found, Page X of Y", the Jump to Page menu, the page links and a sort link
 * for each heading. Every link is a plain address, so a list can be bookmarked and needs no script;
 * the markup carries WHMCS's own classes and data-page, so its script jumps pages where it runs.
 */
final class Listing
{
    /** How many numbered pages are offered around the current one. */
    private const WINDOW = 2;

    /**
     * @param list<string> $sortable the headings that sort, by their word in the address
     * @param array<string, string> $filter what narrows the list, by its word in the address; empty values are left out of every link
     */
    private function __construct(
        public readonly string $orderBy,
        public readonly string $order,
        public readonly int $page,
        public readonly int $perPage,
        private readonly array $sortable,
        private readonly string $defaultOrderBy,
        private readonly string $defaultOrder,
        private readonly array $filter,
        private readonly string $moduleLink,
        private readonly string $tab,
    ) {
    }

    /**
     * @param array<array-key, mixed> $get the request's query
     * @param list<string> $sortable
     * @param array<string, string> $filter
     */
    public static function fromRequest(array $get, array $sortable, string $defaultOrderBy, string $defaultOrder, int $perPage, array $filter, string $moduleLink, string $tab): self
    {
        $text = static fn (string $key): string => is_scalar($get[$key] ?? null) ? (string) $get[$key] : '';
        $orderBy = in_array($text('orderby'), $sortable, true) ? $text('orderby') : $defaultOrderBy;
        $order = match (strtolower($text('order'))) {
            'asc' => 'asc',
            'desc' => 'desc',
            default => $orderBy === $defaultOrderBy ? $defaultOrder : 'asc',
        };
        $page = ctype_digit($text('page')) && strlen($text('page')) < 10 ? max(1, (int) $text('page')) : 1;
        return new self($orderBy, $order, $page, max(1, $perPage), $sortable, $defaultOrderBy, $defaultOrder, array_filter($filter, static fn (string $v): bool => $v !== ''), $moduleLink, $tab);
    }

    /** The page really shown: the last one when the address asks for one past it. */
    public function pageOf(int $total): int
    {
        return min($this->page, $this->pages($total));
    }

    public function offset(int $total): int
    {
        return ($this->pageOf($total) - 1) * $this->perPage;
    }

    public function pages(int $total): int
    {
        return max(1, (int) ceil($total / $this->perPage));
    }

    /**
     * The list's own address with these changes; what is empty or at its default is left out.
     *
     * @param array<string, int|string> $changes
     */
    public function link(array $changes = []): string
    {
        $query = array_filter(array_merge(['tab' => $this->tab], $this->filter, ['orderby' => $this->orderBy, 'order' => $this->order, 'page' => (string) $this->page], array_map('strval', $changes)), static fn (string $value): bool => $value !== '');
        if (($query['orderby'] ?? '') === $this->defaultOrderBy && ($query['order'] ?? '') === $this->defaultOrder) {
            unset($query['orderby'], $query['order']);
        }
        if (($query['page'] ?? '1') === '1') {
            unset($query['page']);
        }
        return $this->moduleLink . '&' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{total: int, page: int, pages: int, summary: string, jumpLabel: string, hidden: list<array{name: string, value: string}>, jump: list<array{page: int, link: string, current: bool}>, pager: list<array{label: string, page: int, link: string, disabled: bool, current: bool}>, sort: array<string, array{link: string, direction: string}>}
     */
    public function model(int $total): array
    {
        $pages = $this->pages($total);
        $page = $this->pageOf($total);
        $hidden = [];
        parse_str((string) parse_url($this->link(['page' => (string) $page]), PHP_URL_QUERY), $query);
        foreach ($query as $name => $value) {
            if ($name !== 'page' && is_string($value)) {
                $hidden[] = ['name' => (string) $name, 'value' => $value];
            }
        }
        $jump = [];
        for ($p = 1; $p <= $pages; $p++) {
            $jump[] = ['page' => $p, 'link' => $this->link(['page' => $p]), 'current' => $p === $page];
        }
        $pager = [['label' => '« ' . self::word('previouspage', 'Previous Page'), 'page' => max(1, $page - 1), 'link' => $this->link(['page' => max(1, $page - 1)]), 'disabled' => $page <= 1, 'current' => false]];
        for ($p = max(1, $page - self::WINDOW); $p <= min($pages, $page + self::WINDOW); $p++) {
            $pager[] = ['label' => (string) $p, 'page' => $p, 'link' => $this->link(['page' => $p]), 'disabled' => false, 'current' => $p === $page];
        }
        $pager[] = ['label' => self::word('nextpage', 'Next Page') . ' »', 'page' => min($pages, $page + 1), 'link' => $this->link(['page' => min($pages, $page + 1)]), 'disabled' => $page >= $pages, 'current' => false];
        $sort = [];
        foreach ($this->sortable as $column) {
            $current = $column === $this->orderBy;
            $sort[$column] = [
                'link' => $this->link(['orderby' => $column, 'order' => $current && $this->order === 'asc' ? 'desc' : 'asc', 'page' => 1]),
                'direction' => $current ? $this->order : '',
            ];
        }
        return [
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'summary' => $total . ' ' . self::word('recordsfound', 'Records Found') . ', ' . self::word('page', 'Page') . ' ' . $page . ' ' . self::word('of', 'of') . ' ' . $pages,
            'jumpLabel' => self::word('jumppage', 'Jump to Page'),
            'hidden' => $hidden,
            'jump' => $jump,
            'pager' => $pager,
            'sort' => $sort,
        ];
    }

    /** WHMCS's own word in the admin's language where the admin area has loaded it, else the English it ships. */
    public static function word(string $key, string $english): string
    {
        $word = $GLOBALS['_ADMINLANG']['global'][$key] ?? null;
        return is_string($word) && $word !== '' ? $word : $english;
    }

    /** A date, with its time unless $time is false, the way WHMCS shows them to this admin (General Settings, Date Format), or as stored outside WHMCS. */
    public static function date(string $stored, bool $time = true): string
    {
        if ($stored === '' || str_starts_with($stored, '0000')) {
            return '';
        }
        if (function_exists('fromMySQLDate')) {
            $shown = \fromMySQLDate($stored, $time);
            return is_string($shown) && $shown !== '' ? $shown : $stored;
        }
        return $stored;
    }
}
