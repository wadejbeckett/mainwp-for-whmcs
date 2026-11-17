<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Engine;


/** A client's WordPress site, reached over HTTPS through WordPress's own login and REST routes. Throws a SiteFailure on refusal. */
interface Site
{
    /** Reads the site's public REST index, with no login: where the REST API is and whether a key can be approved there. */
    public function index(string $url): SiteIndex;

    /**
     * Logs in once as $login (username or email), installs and activates MainWP's own plugin, and
     * logs out. Returns the account's WordPress login name, which the dashboard connects with.
     */
    public function installPlugin(string $url, Login $login): string;

    /**
     * The key route. $key is the approving account's login name and the application password, sent
     * as HTTP Basic authentication to the site itself and nowhere else. Checks that the account is
     * an administrator, makes the care account $care with $careEmail and the display name $careName (or, when an earlier attempt
     * left it, gives it the password again; when another account holds the address, it tries once
     * more with the address plus-tagged with the care login), and installs and activates MainWP's
     * own plugin. A care account made before a refused install is removed again. Returns the care
     * account's login name, which the dashboard connects with.
     */
    public function installPluginWithKey(string $url, Login $key, Login $care, string $careEmail, string $careName): string;

    /** Removes the care account $username with the key; one that is already gone is done. Only a name that begins as a care account's does (Secrets::CARE_LOGIN_PREFIX) is ever removed. */
    public function removeAccount(string $url, Login $key, string $username): void;

    /**
     * Deletes the key on the site, with the key itself. A key that still works but whose entry is
     * already gone is done; a key the site no longer accepts cannot be confirmed deleted, and fails.
     */
    public function deleteKey(string $url, Login $key): void;
}
