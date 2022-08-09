<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Model\Catalog\Product;

use GhostUnicorns\CrtBase\Exception\CrtException;

interface SetStockInterface
{
    /**
     * @param string $sku
     * @param float $quantity
     * @param int $stockId
     * @param bool $reindex
     * @throws CrtException
     */
    public function execute(string $sku, float $quantity, int $stockId, bool $reindex = false);
}
