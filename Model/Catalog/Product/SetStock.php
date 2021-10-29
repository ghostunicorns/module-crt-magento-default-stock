<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Model\Catalog\Product;

use GhostUnicorns\CrtBase\Exception\CrtException;
use GhostUnicorns\CrtMagentoDefaultStock\Model\ResourceModel\SetDefaultStockInterface;
use Magento\Catalog\Model\ProductFactory;
use Magento\CatalogInventory\Api\Data\StockStatusInterface;
use Magento\CatalogInventory\Model\Indexer\Stock\Action\Row;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;

class SetStock implements SetStockInterface
{
    /**
     * @var SetDefaultStockInterface
     */
    private $setDefaultStock;

    /**
     * @var ProductFactory
     */
    private $productFactory;

    /**
     * @var Row
     */
    private $indexerRow;

    /**
     * @param SetDefaultStockInterface $setDefaultStock
     * @param ProductFactory $productFactory
     * @param Row $indexerRow
     */
    public function __construct(
        SetDefaultStockInterface $setDefaultStock,
        ProductFactory $productFactory,
        Row $indexerRow
    ) {
        $this->productFactory = $productFactory;
        $this->indexerRow = $indexerRow;
        $this->setDefaultStock = $setDefaultStock;
    }

    /**
     * @param string $sku
     * @param float $quantity
     * @param int $stockId
     * @param bool $reindex
     * @param int $stockStatus
     * @throws CrtException
     */
    public function execute(
        string $sku,
        float $quantity,
        int $stockId,
        bool $reindex = false,
        int $stockStatus = StockStatusInterface::STATUS_IN_STOCK
    ) {
        $productId = $this->getProductIdFromSku($sku);

        try {
            $this->setDefaultStock->execute(
                $productId,
                $quantity,
                $stockId,
                $stockStatus
            );
        } catch (NoSuchEntityException $e) {
            throw new CrtException(__($e->getMessage()));
        }

        if ($reindex) {
            try {
                $this->indexerRow->execute($productId);
            } catch (LocalizedException $e) {
                throw new CrtException(__($e->getMessage()));
            }
        }
    }

    /**
     * @param string $sku
     * @param int $stockId
     * @param bool $reindex
     * @param int $stockStatus
     * @throws CrtException
     */
    public function setStockStatus(
        string $sku,
        int $stockId,
        bool $reindex = false,
        int $stockStatus = StockStatusInterface::STATUS_IN_STOCK
    ) {
        $productId = $this->getProductIdFromSku($sku);

        try {
            $this->setDefaultStock->setStock(
                $productId,
                $stockId,
                $stockStatus
            );
        } catch (NoSuchEntityException $e) {
            throw new CrtException(__($e->getMessage()));
        }

        if ($reindex) {
            try {
                $this->indexerRow->execute($productId);
            } catch (LocalizedException $e) {
                throw new CrtException(__($e->getMessage()));
            }
        }
    }

    /**
     * @param string $sku
     * @return int
     * @throws CrtException
     */
    private function getProductIdFromSku(string $sku): int
    {
        $product = $this->productFactory->create();
        $product = $product->loadByAttribute('sku', $sku, 'entity_id');
        if (!$product) {
            throw new CrtException(__('Product with sku %2 does not exist', 'sku', $sku));
        }
        return (int)$product->getId();
    }
}
