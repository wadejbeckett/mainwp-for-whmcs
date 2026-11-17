<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests;

use PHPUnit\Framework\TestCase;
use MainWpForWhmcs\CapsuleRepository;

/** The pure parts of CapsuleRepository; the database parts are covered by tests/integration/CapsuleRepositoryTest.php. */
final class CapsuleRepositoryTest extends TestCase
{
    /**
     * WHMCS stores what is typed into its forms entity-encoded: in one install, 12 of 267 company names held
     * "&amp;" and none a bare "&". A dashboard client must get
     * the name as the client wrote it.
     */
    public function testAStoredNameIsDecodedOnce(): void
    {
        self::assertSame('Smith & Sons', CapsuleRepository::decodeName('Smith &amp; Sons'));
        self::assertSame('O\'Brien "Web" <Ltd>', CapsuleRepository::decodeName('O&#039;Brien &quot;Web&quot; &lt;Ltd&gt;'));
        self::assertSame('A &amp; B', CapsuleRepository::decodeName('A &amp;amp; B'), 'once, not until nothing is left to decode');
        self::assertSame('Plain Name', CapsuleRepository::decodeName('  Plain Name '));
        self::assertSame('', CapsuleRepository::decodeName(null));
    }

    /**
     * Code review, 16 November 2026: an invoice in Payment Pending or Collections is not paid, and
     * reading it as paid lets care be set up before money arrives. A Draft is not a bill: the client
     * cannot see or pay it, so it holds nothing back.
     */
    public function testUnpaidPaymentPendingAndCollectionsCountAsUnpaidAndNoOtherStatus(): void
    {
        foreach (['Unpaid', 'Payment Pending', 'Collections'] as $status) {
            self::assertTrue(CapsuleRepository::invoiceIsUnpaid($status), $status);
        }
        foreach (['Paid', 'Cancelled', 'Refunded', 'Draft'] as $status) {
            self::assertFalse(CapsuleRepository::invoiceIsUnpaid($status), $status);
        }
    }

    /**
     * WHMCS's admin folder is the configuration variable $customadminpath, a global at run time
     * (as read from WHMCS). Only a plain folder name is used; anything else
     * is WHMCS's default, "admin".
     */
    public function testTheAdminFolderIsWhmcssCustomAdminPathWhenItIsAPlainNameElseAdmin(): void
    {
        $had = array_key_exists('customadminpath', $GLOBALS);
        $was = $GLOBALS['customadminpath'] ?? null;
        try {
            unset($GLOBALS['customadminpath']);
            self::assertSame('admin', (new CapsuleRepository())->adminFolder(), 'not set');
            foreach (['staff-area_2' => 'staff-area_2', '' => 'admin', '../admin' => 'admin', 'a b' => 'admin', 'x/y' => 'admin', '<b>' => 'admin', 'admin?x=1' => 'admin'] as $set => $expected) {
                $GLOBALS['customadminpath'] = (string) $set;
                self::assertSame($expected, (new CapsuleRepository())->adminFolder(), 'for "' . $set . '"');
            }
            $GLOBALS['customadminpath'] = ['admin'];
            self::assertSame('admin', (new CapsuleRepository())->adminFolder(), 'not a string');
        } finally {
            if ($had) {
                $GLOBALS['customadminpath'] = $was;
            } else {
                unset($GLOBALS['customadminpath']);
            }
        }
    }

    /**
     * Code review, 16 November 2026: WHMCS's own accessor (WHMCS\Application's get_admin_folder_name())
     * comes first, since the global may not be set in the cron's run; then the global; then "admin".
     */
    public function testWhmcssOwnAdminFolderNameComesBeforeTheGlobal(): void
    {
        self::assertSame('staff', CapsuleRepository::adminFolderFrom('staff', 'other'));
        self::assertSame('other', CapsuleRepository::adminFolderFrom(null, 'other'));
        self::assertSame('other', CapsuleRepository::adminFolderFrom('../x', 'other'), 'not a plain name');
        self::assertSame('admin', CapsuleRepository::adminFolderFrom(null, null));
        self::assertSame('admin', CapsuleRepository::adminFolderFrom('', ''));
    }

    /** The mapping of a tblmodulequeue row, as read on WHMCS 9.1: service_type "addon" carries the add-on row id in service_id. */
    public function testAModuleQueueRowIsReadAsTheAddOnRowItsCommandItsErrorAndItsTries(): void
    {
        $row = (object) ['id' => '7', 'service_id' => '72', 'module_action' => 'SuspendAccount', 'last_attempt_error' => 'The dashboard could not be reached.', 'num_retries' => '2', 'last_attempt' => '2026-11-15 00:05:12'];
        self::assertSame(['id' => 7, 'addon_row_id' => 72, 'action' => 'SuspendAccount', 'error' => 'The dashboard could not be reached.', 'retries' => 2, 'last_attempt' => '2026-11-15 00:05:12'], CapsuleRepository::queueRow($row));

        $bare = (object) ['id' => 8, 'service_id' => 73, 'module_action' => 'TerminateAccount', 'last_attempt_error' => null, 'num_retries' => null, 'last_attempt' => null];
        self::assertSame(['id' => 8, 'addon_row_id' => 73, 'action' => 'TerminateAccount', 'error' => '', 'retries' => 0, 'last_attempt' => ''], CapsuleRepository::queueRow($bare));
    }

    /** An add-on row is set to free when its billing cycle is WHMCS's "Free Account", whatever its amount, or its recurring amount is nothing. */
    public function testAnAddonRowIsFreeOnTheCycleFreeAccountOrARecurringAmountOfNothing(): void
    {
        self::assertTrue(CapsuleRepository::isFree('Monthly', '0.00'));
        self::assertTrue(CapsuleRepository::isFree('Free Account', '85.00'), 'whatever its recurring amount');
        self::assertTrue(CapsuleRepository::isFree(' free account ', '85.00'), 'in any letter case, spaces around it');
        self::assertTrue(CapsuleRepository::isFree('Annually', '-1.00'));
        self::assertTrue(CapsuleRepository::isFree('Monthly', null), 'no amount is nothing');
        self::assertFalse(CapsuleRepository::isFree('Monthly', '85.00'));
        self::assertFalse(CapsuleRepository::isFree('Annually', 935));
        self::assertFalse(CapsuleRepository::isFree('Free', '0.01'));
    }
}
