<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support {
    final class WhmcsCalls
    {
        /** @var list<array{hook: string, priority: int, callable: callable}> */
        public static array $hooks = [];
        /** @var list<string> */
        public static array $activity = [];
        /** @var list<array{command: string, values: array<string, mixed>}> */
        public static array $api = [];
        /** @var array<string, string> API command => the error message it answers with */
        public static array $apiErrors = [];

        public static function reset(): void
        {
            self::$hooks = [];
            self::$activity = [];
            self::$api = [];
            self::$apiErrors = [];
            \WHMCS\Session::$values = [];
            \WHMCS\Authentication\CurrentUser::reset();
            \Menu::$contexts = [];
            \WHMCS\View\Menu\Item::$refusesIcons = false;
        }
    }
}

namespace WHMCS\Authentication {
    if (!class_exists('WHMCS\Authentication\CurrentUser', false)) {
        /**
         * Stands in for WHMCS's CurrentUser: by default the authenticated client is the session's
         * 'uid' and the user is its owner; tests set the knobs for an invited user with chosen
         * permissions, an admin logged in as the owner, a client that is not the session's, or
         * nobody logged in.
         */
        final class CurrentUser
        {
            public static ?int $clientId = null;
            public static bool $owner = true;
            /** @var list<string> */
            public static array $permissions = [];
            public static bool $masquerading = false;
            public static bool $loggedOut = false;
            /** @var array<int, list<string>> other accounts the user is linked to: client id => permission list */
            public static array $otherClients = [];
            /** @var list<mixed>|null when set, exactly what User::getClientsByPermission() returns (row shapes) */
            public static ?array $rows = null;
            /** Thrown by User::getClientsByPermission() when set. */
            public static ?\Throwable $throws = null;

            public static function reset(): void
            {
                self::$clientId = null;
                self::$owner = true;
                self::$permissions = [];
                self::$masquerading = false;
                self::$loggedOut = false;
                self::$otherClients = [];
                self::$rows = null;
                self::$throws = null;
            }

            public function client(): ?\WHMCS\User\Client
            {
                if (self::$loggedOut) {
                    return null;
                }
                $id = self::$clientId ?? (int) (\WHMCS\Session::get('uid') ?? 0);
                return $id > 0 ? new \WHMCS\User\Client($id, self::$owner) : null;
            }

            public function user(): ?\WHMCS\User\User
            {
                if (self::$loggedOut) {
                    return null;
                }
                return new \WHMCS\User\User(self::$owner ? 1 : 2, self::$owner, self::$permissions, self::$clientId ?? (int) (\WHMCS\Session::get('uid') ?? 0));
            }

            public function isAuthenticatedUser(): bool
            {
                return !self::$loggedOut;
            }

            public function isMasqueradingAdmin(): bool
            {
                return self::$masquerading;
            }
        }
    }
}

namespace WHMCS\User {
    if (!class_exists('WHMCS\User\Client', false)) {
        final class Client
        {
            public function __construct(public int $id, private bool $owner)
            {
            }

            /** As on WHMCS 9.1, where the method exists but throws for every user (#62): any call fails the tests. */
            public function hasPermission(string $permission): bool
            {
                throw new \RuntimeException('WHMCS\\User\\Client::hasPermission not implemented');
            }

            public function isOwnedBy(User $user): bool
            {
                return $this->owner && $user->id === 1;
            }
        }
    }
    if (!class_exists('WHMCS\User\User', false)) {
        final class User
        {
            /** @param list<string> $permissions the permission list on the user's link to $clientId */
            public function __construct(public int $id, private bool $owner = true, private array $permissions = [], private int $clientId = 0)
            {
            }

            /**
             * As on WHMCS 9.1: a row of client data for each client, across every account the user is
             * linked to, that the user holds the permission on (the owner holding every permission on
             * its own account). CurrentUser::$rows replaces the answer and CurrentUser::$throws makes
             * it throw.
             *
             * @return list<mixed>
             */
            public function getClientsByPermission(string $permission): array
            {
                if (\WHMCS\Authentication\CurrentUser::$throws !== null) {
                    throw \WHMCS\Authentication\CurrentUser::$throws;
                }
                if (\WHMCS\Authentication\CurrentUser::$rows !== null) {
                    return \WHMCS\Authentication\CurrentUser::$rows;
                }
                $rows = [];
                if ($this->clientId > 0 && ($this->owner || in_array($permission, $this->permissions, true))) {
                    $rows[] = ['id' => $this->clientId, 'firstname' => 'Fake', 'status' => 'Active'];
                }
                foreach (\WHMCS\Authentication\CurrentUser::$otherClients as $id => $permissions) {
                    if (in_array($permission, $permissions, true)) {
                        $rows[] = ['id' => $id, 'firstname' => 'Other', 'status' => 'Active'];
                    }
                }
                return $rows;
            }
        }
    }
}

namespace WHMCS\View\Menu {
    if (!class_exists('WHMCS\View\Menu\Item', false)) {
        /**
         * Stands in for WHMCS's menu item (classdocs.whmcs.com 9.0, the same methods for 9.1), with
         * only the documented methods the module and its tests use, behaving as documented: addChild()
         * takes a name and the documented options 'label', 'uri' and 'order' and returns the child;
         * getChild() returns null when there is none; getLabel() falls back to the name; setIcon() throws for
         * a class that is neither Font Awesome nor a Glyphicon. Two things
         * WHMCS does not document are made strict here: a child added under a name already taken
         * throws (as setName() does), and an item given no order has 0, which the module never reads.
         */
        class Item
        {
            private ?string $label = null;
            private ?string $uri = null;
            private int $order = 0;
            private string $bodyHtml = '';
            private string $icon = '';
            /** Tests set this to stand for a WHMCS whose setIcon() refuses the class the module passes. */
            public static bool $refusesIcons = false;
            /** @var array<string, Item> */
            private array $children = [];

            public function __construct(private string $name)
            {
            }

            /** @param array<string, mixed> $options */
            public function addChild(Item|string $child, array $options = []): Item
            {
                if (is_string($child)) {
                    $child = new self($child);
                    if (array_key_exists('label', $options)) {
                        $child->setLabel($options['label'] === null ? null : (string) $options['label']);
                    }
                    if (array_key_exists('uri', $options)) {
                        $child->setUri($options['uri'] === null ? null : (string) $options['uri']);
                    }
                    if (array_key_exists('order', $options)) {
                        $child->setOrder((int) $options['order']);
                    }
                }
                if (isset($this->children[$child->getName()])) {
                    throw new \InvalidArgumentException('a sibling already uses the name ' . $child->getName());
                }
                $this->children[$child->getName()] = $child;
                return $child;
            }

            public function getChild(string $name): ?Item
            {
                return $this->children[$name] ?? null;
            }

            /** @return array<string, Item> */
            public function getChildren(): array
            {
                return $this->children;
            }

            public function hasChildren(): bool
            {
                return $this->children !== [];
            }

            public function getName(): string
            {
                return $this->name;
            }

            public function getLabel(): string
            {
                return $this->label ?? $this->name;
            }

            public function setLabel(?string $label): Item
            {
                $this->label = $label;
                return $this;
            }

            public function getUri(): ?string
            {
                return $this->uri;
            }

            public function setUri(?string $uri): Item
            {
                $this->uri = $uri;
                return $this;
            }

            public function getOrder(): int
            {
                return $this->order;
            }

            public function setOrder(int $order): Item
            {
                $this->order = $order;
                return $this;
            }

            public function getBodyHtml(): string
            {
                return $this->bodyHtml;
            }

            public function setBodyHtml(string $html): Item
            {
                $this->bodyHtml = $html;
                return $this;
            }

            public function hasBodyHtml(): bool
            {
                return $this->bodyHtml !== '';
            }

            /** As documented: an icon that is neither a Font Awesome ("fa-") nor a Glyphicon class throws. */
            public function setIcon(string $icon): Item
            {
                if (self::$refusesIcons || (!str_contains($icon, 'fa-') && !str_contains($icon, 'glyphicon'))) {
                    throw new \InvalidArgumentException('not a Font Awesome icon or a Glyphicon: ' . $icon);
                }
                $this->icon = $icon;
                return $this;
            }

            public function getIcon(): string
            {
                return $this->icon;
            }

            public function hasIcon(): bool
            {
                return $this->icon !== '';
            }
        }
    }
}

namespace WHMCS {
    if (!class_exists('WHMCS\Session', false)) {
        /** Stands in for the WHMCS session: tests set $values ('adminid', 'uid'). */
        final class Session
        {
            /** @var array<string, mixed> */
            public static array $values = [];

            public static function get(string $key): mixed
            {
                return self::$values[$key] ?? null;
            }
        }
    }
}

namespace {
    use MainWpForWhmcs\Tests\Support\WhmcsCalls;

    if (!class_exists('Menu', false)) {
        /**
         * Stands in for WHMCS's Menu: context() returns what tests put in $contexts under the name, or
         * null when there is nothing (documented for 'client' when nobody is logged in).
         */
        final class Menu
        {
            /** @var array<string, mixed> */
            public static array $contexts = [];

            public static function context(string $name): mixed
            {
                return self::$contexts[$name] ?? null;
            }
        }
    }
    if (!function_exists('add_hook')) {
        function add_hook(string $hookPoint, int $priority, callable $function): void
        {
            WhmcsCalls::$hooks[] = ['hook' => $hookPoint, 'priority' => $priority, 'callable' => $function];
        }
    }
    if (!function_exists('logActivity')) {
        function logActivity(string $description, int $userId = 0): void
        {
            WhmcsCalls::$activity[] = $description;
        }
    }
    if (!function_exists('localAPI')) {
        /**
         * The two documented commands the module uses, EncryptPassword and DecryptPassword, with a
         * reversible stand-in for WHMCS's cipher (the result never contains the plain text). As on the
         * WHMCS 9.1, a value passes WHMCS's input filter before it
         * is encrypted: " & ' < > become HTML entities, an entity already there is left as it is, and
         * every other character is kept. What is decrypted is therefore not always what was given.
         *
         * @param array<string, mixed> $values
         * @return array<string, mixed>
         */
        function localAPI(string $command, array $values = [], string $adminUser = ''): array
        {
            WhmcsCalls::$api[] = ['command' => $command, 'values' => $values];
            if (isset(WhmcsCalls::$apiErrors[$command])) {
                return ['result' => 'error', 'message' => WhmcsCalls::$apiErrors[$command]];
            }
            return match ($command) {
                'SendAdminEmail' => ['result' => 'success'],
                'EncryptPassword' => ['result' => 'success', 'password' => 'whmcs-enc:' . base64_encode(strrev(htmlspecialchars((string) $values['password2'], ENT_QUOTES | ENT_HTML401, 'UTF-8', false)))],
                'DecryptPassword' => str_starts_with((string) $values['password2'], 'whmcs-enc:')
                    ? ['result' => 'success', 'password' => strrev((string) base64_decode(substr((string) $values['password2'], 10), true))]
                    : ['result' => 'error', 'message' => 'Decryption failed'],
                default => ['result' => 'error', 'message' => 'not faked: ' . $command],
            };
        }
    }
    if (!function_exists('generate_token')) {
        function generate_token(string $outputType = 'form'): string
        {
            return '<input type="hidden" name="token" value="test-token" />';
        }
    }
    if (!function_exists('check_token')) {
        function check_token(string $namespace = 'WHMCS.admin.default'): void
        {
        }
    }
    if (!defined('WHMCS')) {
        define('WHMCS', true);
    }
}
