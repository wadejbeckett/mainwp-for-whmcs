<?php

// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace MainWpForWhmcs\Tests\Support;

use MainWpForWhmcs\Repository;
use PHPUnit\Framework\TestCase;

/** The unit tests' stand-in answers the lists as the real repository does (the same contract runs against MySQL). */
final class MemoryRepositoryTest extends TestCase
{
    use ListingContract;

    private MemoryRepository $repo;

    protected function setUp(): void
    {
        $this->repo = new MemoryRepository();
    }

    protected function listingRepository(): Repository
    {
        return $this->repo;
    }

    protected function whmcsHas(array $productAddons, ?string $recordsPerPage): void
    {
        $this->repo->productAddons = $productAddons;
        $this->repo->recordsPerPage = $recordsPerPage;
    }

    protected function whmcsClient(int $id, string $first, string $last, string $company): void
    {
        $this->repo->clients[$id] = ['firstname' => $first, 'lastname' => $last, 'companyname' => $company];
    }
}
