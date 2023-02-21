<?php

namespace Maatoo\Maatoo\Model\Synchronization;

use Maatoo\Maatoo\Api\Data\SyncInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Quote\Model\ResourceModel\Quote;
use Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory;

class OrderLines
{
    /**
     * @var \Maatoo\Maatoo\Model\StoreConfigManager
     */
    private $storeManager;

    /**
     * @var \Maatoo\Maatoo\Adapter\AdapterInterface
     */
    private $adapter;

    /**
     * @var \Magento\Sales\Model\ResourceModel\Order\CollectionFactory
     */
    private $collectionOrderFactory;

    /**
     * @var Quote\Item\CollectionFactory
     */
    private $collectionQuoteItemFactory;

    /**
     * @var \Magento\Framework\Api\SearchCriteriaBuilder
     */
    private $searchCriteriaBuilder;

    /**
     * @var \Maatoo\Maatoo\Model\StoreMap
     */
    private $storeMap;

    /**
     * @var \Maatoo\Maatoo\Model\SyncRepository
     */
    private $syncRepository;

    /**
     * @var Order
     */
    private $syncOrder;

    /**
     * @var \Maatoo\Maatoo\Model\Config\Config
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * @var \Magento\Eav\Model\ResourceModel\Entity\Attribute
     */
    private $eavAttribute;

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    private $resource;

    /**
     * @var \Maatoo\Maatoo\Helper\DataSync
     */
    private $helper;

    /**
     * @param \Maatoo\Maatoo\Model\StoreConfigManager $storeManager
     * @param CollectionFactory $collectionOrderFactory
     * @param Quote\Item\CollectionFactory $collectionQuoteItemFactory
     * @param \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder
     * @param \Maatoo\Maatoo\Adapter\AdapterInterface $adapter
     * @param \Maatoo\Maatoo\Model\StoreMap $storeMap
     * @param \Maatoo\Maatoo\Model\SyncRepository $syncRepository
     * @param Order $syncOrder
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Eav\Model\ResourceModel\Entity\Attribute $eavAttribute
     * @param \Magento\Framework\App\ResourceConnection  $resource
     * @param \Maatoo\Maatoo\Helper\DataSync $helper
     */
    public function __construct(
        \Maatoo\Maatoo\Model\StoreConfigManager $storeManager,
        \Magento\Sales\Model\ResourceModel\Order\Item\CollectionFactory $collectionOrderFactory,
        \Magento\Quote\Model\ResourceModel\Quote\Item\CollectionFactory $collectionQuoteItemFactory,
        \Magento\Framework\Api\SearchCriteriaBuilder $searchCriteriaBuilder,
        \Maatoo\Maatoo\Adapter\AdapterInterface $adapter,
        \Maatoo\Maatoo\Model\StoreMap $storeMap,
        \Maatoo\Maatoo\Model\SyncRepository $syncRepository,
        \Maatoo\Maatoo\Model\Synchronization\Order $syncOrder,
        \Maatoo\Maatoo\Model\Config\Config $config,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Eav\Model\ResourceModel\Entity\Attribute $eavAttribute,
        \Magento\Framework\App\ResourceConnection $resource,
        \Maatoo\Maatoo\Helper\DataSync $helper
    ) {
        $this->storeManager = $storeManager;
        $this->collectionOrderFactory = $collectionOrderFactory;
        $this->collectionQuoteItemFactory = $collectionQuoteItemFactory;
        $this->adapter = $adapter;
        $this->storeMap = $storeMap;
        $this->syncRepository = $syncRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->syncOrder = $syncOrder;
        $this->config = $config;
        $this->logger = $logger;
        $this->eavAttribute = $eavAttribute;
        $this->resource = $resource;
        $this->helper = $helper;
    }

    /**
     * @param \Closure|null $cl
     */
    public function sync(\Closure $cl = null)
    {
        $this->logger->info("Begin syncing orderlines to maatoo.");
        //$this->syncOrder->sync($cl);

        foreach ($this->storeManager->getStores() as $store) {
            /** @var \Magento\Quote\Model\ResourceModel\Quote\Item\Collection $collection */
            $collection = $this->collectionQuoteItemFactory->create();
            $lifetime = $this->config->getOrderLifetime();

            $attributeId = $this->eavAttribute->getIdByCode(
                \Magento\Catalog\Model\Product::ENTITY,
                'status'
            );

            $select = $collection->getSelect();
            $select->where(
                new \Zend_Db_Expr('TIME_TO_SEC(TIMEDIFF(CURRENT_TIMESTAMP, `updated_at`)) <= ' . $lifetime * 24 * 60 * 60)
            );

            $select->distinct();
            if ($attributeId) {
                $select->join([
                    'additional_table' => $collection->getTable('catalog_product_entity_int')
                ],
                    sprintf(
                        "main_table.product_id = additional_table.entity_id AND additional_table.attribute_id = %s AND additional_table.value <> %s",
                        $attributeId,
                        \Magento\Catalog\Model\Product\Attribute\Source\Status::STATUS_DISABLED
                    ),
                    []
                );
            }

            $updatedOrderItems = [];
            $orderLines = [];

            foreach ($collection->getItems() as $item) {

                /** @var \Maatoo\Maatoo\Model\Sync $sync */
                $sync = $this->syncRepository->getByParam([
                    'entity_id' => $item->getId(),
                    'entity_type' => SyncInterface::TYPE_ORDER_LINES,
                    'store_id' => $store->getId()
                ]);

                if ($sync->getData('status') == SyncInterface::STATUS_SYNCHRONIZED) {
                    continue;
                }

                $maatooSyncProductRow = $this->syncRepository->getRow([
                    'entity_id' => $item->getData('product_id'),
                    'entity_type' => SyncInterface::TYPE_PRODUCT,
                    'store_id' => $store->getId()
                ]);

                if (empty($maatooSyncProductRow['maatoo_id'])) {
                    continue;
                }

                $maatooSyncOrderRow = $this->syncRepository->getRow([
                    'entity_id' => $item->getData('quote_id'),
                    'entity_type' => SyncInterface::TYPE_ORDER,
                    'store_id' => $store->getId()
                ]);

                if (empty($maatooSyncOrderRow['maatoo_id'])) {
                    continue;
                }

                $parameters = [
                    'store' => $this->storeMap->getStoreToMaatoo($item->getStoreId()),
                    "product" => $maatooSyncProductRow['maatoo_id'],
                    "order" => $maatooSyncOrderRow['maatoo_id'],
                    "quantity" => $item->getData('qty')
                ];

                if (count($orderLines) == 99) {
                    break;
                }

                if (empty($sync->getData('status')) || $sync->getData('status') == SyncInterface::STATUS_EMPTY) {
                    $orderLines[] = [
                        'store' => $this->storeMap->getStoreToMaatoo($item->getStoreId()),
                        "product" => $maatooSyncProductRow['maatoo_id'],
                        "order" => $maatooSyncOrderRow['maatoo_id'],
                        "quantity" => $item->getData('qty')
                    ];

                    $maatoSyncInsertData[] = [
                        'status' => SyncInterface::STATUS_SYNCHRONIZED,
                        'maatoo_id' => '',
                        "entity_id" => $item->getId(),
                        "store_id" => $item->getData('store_id'),
                        "entity_type" => SyncInterface::TYPE_ORDER_LINES,
                    ];

                    $updatedOrderItems[] = $item->getItemId();
                }


                $result = [];

                if ($sync->getData('status') == SyncInterface::STATUS_UPDATED) {
                    $result = $this->adapter->makeRequest(
                        'orderLines/' . $sync->getData('maatoo_id') . '/edit',
                        $parameters,
                        'PATCH'
                    );
                    $this->logger->info('Updated item #' . $item->getItemId() . ' in order #' . $sync->getData('maatoo_id') . ' in maatoo');
                    if (is_callable($cl)) {
                        $cl('Updated item #' . $item->getItemId() . ' in order #' . $sync->getData('maatoo_id') . ' ub maatoo');
                    }
                }

                if (isset($result['orderLine']['id'])) {
                    /*$param = [
                        'entity_id' => $item->getId(),
                        'entity_type' => SyncInterface::TYPE_ORDER_LINES,
                        'store_id' => $store->getId()
                    ];*/
                    // @var \Maatoo\Maatoo\Model\Sync $sync
                    //$sync = $this->syncRepository->getByParam($param);
                    $sync->setStatus(SyncInterface::STATUS_SYNCHRONIZED);
                    $sync->setMaatooId($result['orderLine']['id']);
                    $sync->setEntityId($item->getId());
                    $sync->setStoreId($item->getData('store_id'));
                    $sync->setEntityType(SyncInterface::TYPE_ORDER_LINES);
                    $this->syncRepository->save($sync);
                }
            }

            if (!empty($orderLines) && !empty($maatoSyncInsertData)) {
                $result = $this->adapter->makeRequest('orderLines/batch/new', $orderLines, 'POST');
                $maatoSyncInsertData = $this->helper->setMaatooIdToInsertArray($maatoSyncInsertData, $result['orderLines']);
                $this->logger->info(
                    'Added items to orders from # ' . reset($updatedOrderItems) .
                    ' to #' . end($updatedOrderItems) . ' in maatoo'
                );
                if (is_callable($cl)) {
                    $cl(
                        'Added items to orders from # ' . reset($updatedOrderItems) .
                        ' to #' . end($updatedOrderItems)
                    );
                }

                $this->helper->executeInsertOnDuplicate($maatoSyncInsertData);
            }

            // Delete entity
            foreach ($this->storeManager->getStores() as $store) {
                $this->searchCriteriaBuilder->addFilter('entity_type', SyncInterface::TYPE_ORDER_LINES);
                $this->searchCriteriaBuilder->addFilter('status', SyncInterface::STATUS_DELETED);
                $this->searchCriteriaBuilder->addFilter('store_id', $store->getId());
                $searchCriteria = $this->searchCriteriaBuilder->create();
                $collectionForDelete = $this->syncRepository->getList($searchCriteria);
                foreach ($collectionForDelete as $itemDel) {
                    $result = $this->adapter->makeRequest('orderLines/' . $itemDel->getMaatooId() . '/delete', [], 'DELETE');
                    $this->logger->info('Deleted item from order #' . $itemDel->getMaatooId());
                    if (is_callable($cl)) {
                        $cl('Deleted item from order #' . $itemDel->getMaatooId() . ' in maatoo');
                    }

                    $this->syncRepository->delete($itemDel);
                }
            }
        }
        $this->logger->info("Finished syncing orderlines to maatoo.");
    }
}
