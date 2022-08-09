<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Model\ResourceModel;

use Magento\CatalogInventory\Api\Data\StockItemInterfaceFactory;
use Magento\CatalogInventory\Model\ResourceModel\Stock\Item;
use Magento\Framework\Model\AbstractModel;

class SetDefaultStock implements SetDefaultStockInterface
{
    /**
     * @var StockItemInterfaceFactory
     */
    private $stockItemInterfaceFactory;

    /**
     * @var Item
     */
    private $stockItemResourceModel;

    /**
     * @param StockItemInterfaceFactory $stockItemInterfaceFactory
     * @param Item $stockItemResourceModel
     */
    public function __construct(
        StockItemInterfaceFactory $stockItemInterfaceFactory,
        Item $stockItemResourceModel
    ) {
        $this->stockItemInterfaceFactory = $stockItemInterfaceFactory;
        $this->stockItemResourceModel = $stockItemResourceModel;
    }

    /**
     * @inheritDoc
     */
    public function execute(int $productId, float $quantity, int $stockId, int $status): void
    {
        $stockItem = $this->stockItemInterfaceFactory->create();
        $this->stockItemResourceModel->loadByProductId($stockItem, $productId, $stockId);

        // For item not set in cataloginventory_stock_item table
        if (!$stockItem->getProductId()) {
            $stockItem->setProductId($productId);
            $stockItem->setStockId($stockId);
            $stockItem->unsetData('stock_qty');
        }

        $stockItem->setQty($quantity);
        $stockItem->setIsInStock($quantity > 0);

        /** @var AbstractModel $stockItem */
        $this->stockItemResourceModel->save($stockItem);
    }

    /**
     * @inheritDoc
     */
    public function setStock(int $productId, int $stockId, int $status): void
    {
        $stockItem = $this->stockItemInterfaceFactory->create();
        $this->stockItemResourceModel->loadByProductId($stockItem, $productId, $stockId);

        $stockItem->setIsInStock($status);

        /** @var AbstractModel $stockItem */
        $this->stockItemResourceModel->save($stockItem);
    }
}
