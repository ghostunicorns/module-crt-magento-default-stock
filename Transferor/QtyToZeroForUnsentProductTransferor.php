<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Transferor;

use Exception;
use GhostUnicorns\CrtActivity\Api\ActivityRepositoryInterface;
use GhostUnicorns\CrtBase\Api\TransferorInterface;
use GhostUnicorns\CrtBase\Exception\CrtException;
use GhostUnicorns\CrtEntity\Api\EntityRepositoryInterface;
use GhostUnicorns\CrtEntity\Model\AddExtraDataToEntitiesByActivityId;
use GhostUnicorns\CrtMagentoDefaultStock\Api\DefaultStockConfigInterface;
use GhostUnicorns\CrtMagentoDefaultStock\Model\Catalog\Product\SetStockInterface;
use GhostUnicorns\CrtUtils\Model\DotConvention;
use Luxpets\CatalogCustomAttributes\Model\Api\Product\Attribute\AutoQtyToZeroAttributeInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Monolog\Logger;

class QtyToZeroForUnsentProductTransferor implements TransferorInterface
{
    /**
     * @var Logger
     */
    private $logger;

    /**
     * @var EntityRepositoryInterface
     */
    private $entityRepository;

    /**
     * @var DefaultStockConfigInterface
     */
    private $config;

    /**
     * @var SetStockInterface
     */
    private $setStock;

    /**
     * @var DotConvention
     */
    private $dotConvention;

    /**
     * @var ActivityRepositoryInterface
     */
    private $activityRepository;

    /**
     * @var AddExtraDataToEntitiesByActivityId
     */
    private $addExtraDataToEntitiesByActivityId;

    /**
     * @var StockRegistryInterface
     */
    private $stockRegistry;

    /**
     * @var string
     */
    private $fieldSku;

    /**
     * @var CollectionFactory
     */
    private $productCollectionFactory;

    /**
     * @param Logger $logger
     * @param EntityRepositoryInterface $entityRepository
     * @param DefaultStockConfigInterface $config
     * @param SetStockInterface $setStock
     * @param DotConvention $dotConvention
     * @param ActivityRepositoryInterface $activityRepository
     * @param AddExtraDataToEntitiesByActivityId $addExtraDataToEntitiesByActivityId
     * @param StockRegistryInterface $stockRegistry
     * @param CollectionFactory $productCollectionFactory
     * @param string $fieldSku
     */
    public function __construct(
        Logger $logger,
        EntityRepositoryInterface $entityRepository,
        DefaultStockConfigInterface $config,
        SetStockInterface $setStock,
        DotConvention $dotConvention,
        ActivityRepositoryInterface $activityRepository,
        AddExtraDataToEntitiesByActivityId $addExtraDataToEntitiesByActivityId,
        StockRegistryInterface $stockRegistry,
        CollectionFactory $productCollectionFactory,
        string $fieldSku
    ) {
        $this->logger = $logger;
        $this->entityRepository = $entityRepository;
        $this->config = $config;
        $this->setStock = $setStock;
        $this->dotConvention = $dotConvention;
        $this->activityRepository = $activityRepository;
        $this->addExtraDataToEntitiesByActivityId = $addExtraDataToEntitiesByActivityId;
        $this->stockRegistry = $stockRegistry;
        $this->fieldSku = $fieldSku;
        $this->productCollectionFactory = $productCollectionFactory;
    }

    /**
     * @param int $activityId
     * @param string $transferorType
     * @throws CrtException
     * @throws NoSuchEntityException
     */
    public function execute(int $activityId, string $transferorType): void
    {
        $activity = $this->activityRepository->getById($activityId);

        if ($activity->getExtra()->hasData('data')) {
            //skip if there is data inside extra because it means is specific call, example: button updateStock ecc
            return;
        }

        $allActivityEntities = $this->entityRepository->getAllDataRefinedByActivityIdGroupedByIdentifier($activityId);

        //prepare product sku to skip
        $productsToSkip = [];
        foreach ($allActivityEntities as $entityIdentifier => $entities) {
            try {
                $productsToSkip[] = $this->dotConvention->getValue($entities, $this->fieldSku);
            } catch (CrtException $e) {
                $this->logger->error(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ ERROR ~ error:%4',
                    $activityId,
                    $transferorType,
                    $entityIdentifier,
                    $e->getMessage()
                ));

                $this->addExtraDataToEntitiesByActivityId->execute(
                    $activityId,
                    (string)$entityIdentifier,
                    [
                        'error' => $e->getMessage()
                    ]
                );
            }
        }

        $stockId = $this->config->getStockId();
        $reindex = $this->config->isReindexAfterImport();

        //prepare product collection of product unsent from loft
        $products = $this->productCollectionFactory->create();
        $products->addAttributeToSelect(AutoQtyToZeroAttributeInterface::ATTRIBUTE_CODE);
        $products->addAttributeToFilter(AutoQtyToZeroAttributeInterface::ATTRIBUTE_CODE, true);
        $products->addFieldToFilter('sku', ['nin' => $productsToSkip]);

        $productsUpdatedToZeroQty = [];
        $productsNotUpdatedToZeroQty = [];
        $tot = $products->count();
        $i = 0;
        //set qty 0 to all product unsent from loft that has auto_qty_to_zero flag -> true
        /** @var Product $product */
        foreach ($products as $product) {
            $sku = $product->getSku();

            try {
                $oldStockItem = $this->getStockItemBySku($sku);
                $this->setStock->execute($sku, 0, $stockId, $reindex);

                $this->logger->info(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ product sku:%3 ~ '
                    .'old stock value: %4 ~ new stock value: %5 ~ step:%6/%7 ~ END',
                    $activityId,
                    $transferorType,
                    $sku,
                    $oldStockItem->getQty(),
                    0,
                    ++$i,
                    $tot
                ));

                $productsUpdatedToZeroQty[] = $sku;
            } catch (Exception $exception) {
                $this->logger->error(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ product sku:%3 ~ ERROR ~ error:%4',
                    $activityId,
                    $transferorType,
                    $sku,
                    $exception->getMessage()
                ));

                $productsNotUpdatedToZeroQty[] = $sku;
                if (!$this->config->continueInCaseOfErrors()) {
                    $this->updateSummary($activityId, $productsUpdatedToZeroQty, $productsNotUpdatedToZeroQty);
                    throw new CrtException(__(
                        'activityId:%1 ~ Transferor ~ transferorType:%2 ~ product sku:%3 ~ END ~ '.
                        'Because of continueInCaseOfErrors = false',
                        $activityId,
                        $transferorType,
                        $sku
                    ));
                }
            }
        }

        $this->updateSummary($activityId, $productsUpdatedToZeroQty, $productsNotUpdatedToZeroQty);
    }

    /**
     * @throws CrtException
     */
    private function getStockItemBySku($sku): StockItemInterface
    {
        try {
            return $this->stockRegistry->getStockItemBySku($sku);
        } catch (NoSuchEntityException $e) {
            throw new CrtException(__($e->getMessage()));
        }
    }

    /**
     * @param int $activityId
     * @param array $productsUpdatedToZeroQty
     * @param array $productsNotUpdatedToZeroQty
     * @throws NoSuchEntityException
     */
    private function updateSummary(int $activityId, array $productsUpdatedToZeroQty, array $productsNotUpdatedToZeroQty)
    {
        $activity = $this->activityRepository->getById($activityId);
        $activity->addExtraArray(
            [
                'qty_to_zero' => implode(',', $productsUpdatedToZeroQty),
                'qty_not_to_zero' => implode(',', $productsNotUpdatedToZeroQty),
            ]
        );
        $this->activityRepository->save($activity);
    }
}
