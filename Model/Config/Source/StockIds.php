<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Model\Config\Source;

use GhostUnicorns\CrtMagentoDefaultStock\Model\ResourceModel\GetStockList;
use Magento\Framework\Data\OptionSourceInterface;

class StockIds implements OptionSourceInterface
{
    /**
     * @var GetStockList
     */
    private $getStockList;

    public function __construct(
        GetStockList $getStockList
    ) {
        $this->getStockList = $getStockList;
    }

    /**
     * @return array
     */
    public function toOptionArray(): array
    {
        $stocks = $this->getStockList->execute();
        $result = [];
        foreach ($stocks as $stock) {
            $result[] = ['value' => $stock['stock_id'], 'label' => $stock['stock_name']];
        }
        return $result;
    }
}
