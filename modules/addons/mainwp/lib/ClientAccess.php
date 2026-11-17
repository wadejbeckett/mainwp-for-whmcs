<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs;

/**
 * Who is looking at the client area, after Agency Discount 0.3.0. Every user of an account shares
 * the session's client id, so the id alone says nothing about the user: the account's owner, a
 * user holding the permission to manage the account's products, and an admin logged in to the
 * client area from the admin area are permitted; any other user is not. Decided through WHMCS's
 * own classes (CurrentUser, Client::isOwnedBy(), User::getClientsByPermission()); without them, or
 * when the authenticated client is not the session's, the user is not permitted.
 * Client::hasPermission() is not used: on WHMCS 9.1 it throws "not implemented".
 */
final class ClientAccess
{
    /** WHMCS's user permission key behind "Manage Products" (tblusers_clients.permissions, read on the live install 10 November 2026). */
    public const PERMISSION = 'manageproducts';

    /** @return array{clientId: int, permitted: bool}|null null when nobody is logged in */
    public static function viewer(): ?array
    {
        $uid = class_exists(\WHMCS\Session::class) ? \WHMCS\Session::get('uid') : null;
        if (!is_numeric($uid) || (int) $uid < 1) {
            return null;
        }
        return ['clientId' => (int) $uid, 'permitted' => self::permitted((int) $uid)];
    }

    private static function permitted(int $clientId): bool
    {
        if (!class_exists(\WHMCS\Authentication\CurrentUser::class)) {
            return false;
        }
        $current = new \WHMCS\Authentication\CurrentUser();
        $client = $current->client();
        if ($client === null || (int) $client->id !== $clientId) {
            return false;
        }
        if ($current->isMasqueradingAdmin()) {
            return true;
        }
        $user = $current->user();
        if ($user === null) {
            return false;
        }
        if ($client->isOwnedBy($user)) {
            return true;
        }
        // Rows of client data on WHMCS 9.1; read by "client_id" when present, else "id"; any other shape fails closed.
        foreach ($user->getClientsByPermission(self::PERMISSION) as $row) {
            $id = is_array($row) ? ($row['client_id'] ?? $row['id'] ?? null) : (is_object($row) ? ($row->client_id ?? $row->id ?? null) : null);
            if (is_numeric($id) && (int) $id === $clientId) {
                return true;
            }
        }
        return false;
    }
}
