<?php
/*
  * Copyright © Ghost Unicorns snc. All rights reserved.
 * See LICENSE for license details.
 */

declare(strict_types=1);

namespace GhostUnicorns\CrtMagentoDefaultStock\Transferor;

use GhostUnicorns\CrtActivity\Api\ActivityRepositoryInterface;
use GhostUnicorns\CrtBase\Api\TransferorInterface;
use GhostUnicorns\CrtBase\Exception\CrtException;
use GhostUnicorns\CrtEntity\Api\EntityRepositoryInterface;
use GhostUnicorns\CrtEntity\Model\AddExtraDataToEntitiesByActivityId;
use GhostUnicorns\CrtMagentoDefaultStock\Api\DefaultStockConfigInterface;
use GhostUnicorns\CrtMagentoDefaultStock\Model\Catalog\Product\SetStockInterface;
use GhostUnicorns\CrtUtils\Model\DotConvention;
use Magento\CatalogInventory\Api\Data\StockItemInterface;
use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Monolog\Logger;

class DefaultStockTransferor implements TransferorInterface
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
     * @var string
     */
    private $fieldQuantity;

    /**
     * @param Logger $logger
     * @param EntityRepositoryInterface $entityRepository
     * @param DefaultStockConfigInterface $config
     * @param SetStockInterface $setStock
     * @param DotConvention $dotConvention
     * @param ActivityRepositoryInterface $activityRepository
     * @param AddExtraDataToEntitiesByActivityId $addExtraDataToEntitiesByActivityId
     * @param StockRegistryInterface $stockRegistry
     * @param string $fieldSku
     * @param string $fieldQuantity
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
        string $fieldSku,
        string $fieldQuantity
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
        $this->fieldQuantity = $fieldQuantity;
    }

    /**
     * @param int $activityId
     * @param string $transferorType
     * @throws CrtException
     * @throws NoSuchEntityException
     */
    public function execute(int $activityId, string $transferorType): void
    {
        $allActivityEntities = $this->entityRepository->getAllDataRefinedByActivityIdGroupedByIdentifier($activityId);

        $i = 0;
        $ok = 0;
        $ko = 0;
        $tot = count($allActivityEntities);
        foreach ($allActivityEntities as $entityIdentifier => $entities) {
            $this->logger->info(__(
                'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ step:%4/%5 ~ START',
                $activityId,
                $transferorType,
                $entityIdentifier,
                ++$i,
                $tot
            ));

            try {
                $sku = $this->dotConvention->getValue($entities, $this->fieldSku);
                $quantity = (float)$this->dotConvention->getValue($entities, $this->fieldQuantity);
                $stockId = $this->config->getStockId();
                $reindex = $this->config->isReindexAfterImport();

                $oldStockItem = $this->getStockItemBySku($sku);
                $this->setStock->execute($sku, $quantity, $stockId, $reindex);

                $this->logger->info(__(
                    'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ new stock value:%4 ~ END',
                    $activityId,
                    $transferorType,
                    $entityIdentifier,
                    $quantity
                ));

                $ok++;
                $this->addExtraDataToEntitiesByActivityId->execute(
                    $activityId,
                    (string)$entityIdentifier,
                    [
                        'old_qty' => $oldStockItem->getQty(),
                        'new_qty' => $quantity
                    ]
                );
            } catch (CrtException $e) {
                $ko++;

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

                if (!$this->config->continueInCaseOfErrors()) {
                    $this->updateSummary($activityId, $ok, $ko);
                    throw new CrtException(__(
                        'activityId:%1 ~ Transferor ~ transferorType:%2 ~ entityIdentifier:%3 ~ END ~ '.
                        'Because of continueInCaseOfErrors = false',
                        $activityId,
                        $transferorType,
                        $entityIdentifier
                    ));
                }
            }
        }
        $this->updateSummary($activityId, $ok, $ko);
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
     * @param int $ok
     * @param int $ko
     * @throws NoSuchEntityException
     */
    private function updateSummary(int $activityId, int $ok, int $ko)
    {
        $activity = $this->activityRepository->getById($activityId);
        $activity->addExtraArray(['ok' => $ok, 'ko' => $ko]);
        $this->activityRepository->save($activity);
    }
}
