<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Api;

use GhostUnicorns\CrtBase\Api\CrtConfigInterface;

interface DefaultStockConfigInterface extends CrtConfigInterface
{
    /**
     * @return int
     */
    public function getStockId(): int;

    /**
     * @return bool
     */
    public function isReindexAfterImport(): bool;
}
