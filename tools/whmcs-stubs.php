<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

/** Minimal WHMCS symbol stubs for PHPStan. Never deployed or executed. */

namespace WHMCS\Database {
    /** WHMCS's documented database entry point ("Interacting with the Database"); wraps Illuminate's Capsule Manager. */
    class Capsule
    {
        public static function table(string $table): mixed { return null; }
        public static function schema(): mixed { return null; }
        public static function connection(?string $name = null): mixed { return null; }
    }
}

namespace WHMCS\Authentication {
    /** "Authentication" in the WHMCS developer docs: the authenticated client-area user and client. */
    class CurrentUser
    {
        public function user(): ?\WHMCS\User\User { return null; }
        public function client(): ?\WHMCS\User\Client { return null; }
        public function isAuthenticatedUser(): bool { return false; }
        public function isAuthenticatedAdmin(): bool { return false; }
        public function isMasqueradingAdmin(): bool { return false; }
    }
}

namespace WHMCS\User {
    /**
     * Eloquent model of a client account. Its hasPermission() is left out on purpose: on WHMCS 9.1 it
     * throws "not implemented" (#62), so a call to it should fail the analysis.
     * @property int $id
     */
    class Client
    {
        public function __get(string $name): mixed { return null; }
        public function isOwnedBy(User $user): bool { return false; }
    }
    /**
     * Eloquent model of a client-area user.
     * @property int $id
     */
    class User
    {
        public function __get(string $name): mixed { return null; }
        /** @return array<int, mixed> the clients (rows of client data on WHMCS 9.1) the user holds the permission on; the owner holds every permission */
        public function getClientsByPermission(string $permission): array { return []; }
        public function isOwner(Client $client): bool { return false; }
    }
}

namespace WHMCS\View\Menu {
    /** A client-area menu item: a navbar or sidebar and each of its panels and links (classdocs.whmcs.com 9.0; 9.1 lists the same methods). */
    class Item
    {
        /** @param array<string, mixed> $options documented keys: name, label, uri, order, icon */
        public function addChild(Item|string $child, array $options = []): Item { return $this; }
        public function getChild(string $name): ?Item { return null; }
        public function getOrder(): int { return 0; }
        public function setOrder(int $order): Item { return $this; }
        public function setBodyHtml(string $html): Item { return $this; }
        /** Throws for a class that is neither a Font Awesome nor a Glyphicon class. */
        public function setIcon(string $icon): Item { return $this; }
    }
}

namespace WHMCS {
    class Session
    {
        public static function get(string $key): mixed { return null; }
    }
}

namespace {
    /** WHMCS's menu facade; context('client') is documented, returning null when no client is logged in. */
    class Menu
    {
        public static function context(string $name): mixed { return null; }
    }
    function add_hook(string $hookPoint, int $priority, callable $function): void {}
    function logActivity(string $description, int $userId = 0): void {}
    /** @param array<string, mixed> $values @return array<string, mixed> */
    function localAPI(string $command, array $values = [], string $adminUser = ''): array { return []; }
    /** @param string $outputType 'form' (hidden input), 'link' or 'plain' */
    function generate_token(string $outputType = 'form'): string { return ''; }
    function check_token(string $namespace = 'WHMCS.admin.default'): void {}
}
