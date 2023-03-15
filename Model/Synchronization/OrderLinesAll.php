<?php

namespace Maatoo\Maatoo\Model\Synchronization;

use Closure;
use Maatoo\Maatoo\Adapter\AdapterInterface;
use Maatoo\Maatoo\Api\Data\SyncInterface;
use Maatoo\Maatoo\Helper\DataSync;
use Maatoo\Maatoo\Model\StoreConfigManager;
use Maatoo\Maatoo\Model\StoreMap;
use Maatoo\Maatoo\Model\SyncRepository;
use Magento\Sales\Model\ResourceModel\Order\CollectionFactory;
use Psr\Log\LoggerInterface;

/**
 * Class OrderLinesAll
 * @package Maatoo\Maatoo\Model\Synchronization
 */
class OrderLinesAll
{

    /**
     * @var StoreConfigManager
     */
    private $storeManager;

    /**
     * @var AdapterInterface
     */
    private $adapter;

    /**
     * @var StoreMap
     */
    private $storeMap;

    /**
     * @var SyncRepository
     */
    private $syncRepository;

    /**
     * @var CollectionFactory
     */
    private $collectionOrderFactory;

    /**
     * @var DataSync
     */
    private $helper;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param  StoreConfigManager  $storeManager
     * @param  CollectionFactory  $collectionOrderFactory
     * @param  AdapterInterface  $adapter
     * @param  StoreMap  $storeMap
     * @param  SyncRepository  $syncRepository
     * @param  DataSync  $helper
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreConfigManager $storeManager,
        CollectionFactory $collectionOrderFactory,
        AdapterInterface $adapter,
        StoreMap $storeMap,
        SyncRepository $syncRepository,
        DataSync $helper,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->collectionOrderFactory = $collectionOrderFactory;
        $this->adapter = $adapter;
        $this->storeMap = $storeMap;
        $this->syncRepository = $syncRepository;
        $this->helper = $helper;
        $this->logger = $logger;
    }

    /**
     * @param  Closure|null  $cl
     */
    public function sync(Closure $cl = null)
    {
        $this->logger->info("Begin syncing all orderlines to maatoo.");
        $storesAllowed = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storesAllowed[] = $store->getId();

            $collection = $this->collectionOrderFactory->create();
            $collection->addFieldToFilter('store_id', $store->getId());
            $collection->addFieldToFilter('maatoo_sync', SyncInterface::ORDER_STATUS_SYNCHRONIZED);

            $select = $collection->getSelect();
            $select->limit(100);

            $updateOrdersData = [];
            $maatoSyncInsertData = [];
            $orderLines = [];

            foreach ($collection->getItems() as $order) {
                $quoteId = $order->getQuoteId();

                $sync = $this->syncRepository->getByParam([
                    'entity_id' => $quoteId,
                    'entity_type' => SyncInterface::TYPE_ORDER_LINES,
                    'store_id' => $store->getId(),
                ]);

                if ($sync->getData('status') == SyncInterface::STATUS_SYNCHRONIZED) {
                    $updateOrdersData[$order->getId()] = SyncInterface::ORDER_LINES_STATUS_SYNCHRONIZED;
                    continue;
                }

                if ($order->getTotalItemCount() + count($orderLines) >= 99) {
                    break;
                }

                foreach ($order->getAllVisibleItems() as $product) {
                    $maatooSyncProductRow = $this->syncRepository->getRow([
                        'entity_id' => $product->getId(),
                        'entity_type' => SyncInterface::TYPE_PRODUCT,
                        'store_id' => $store->getId(),
                    ]);

                    if (empty($maatooSyncProductRow['maatoo_id'])) {
                        continue;
                    }

                    $maatooSyncOrderRow = $this->syncRepository->getRow([
                        'entity_id' => $quoteId,
                        'entity_type' => SyncInterface::TYPE_ORDER,
                        'store_id' => $store->getId(),
                    ]);

                    if (empty($maatooSyncOrderRow['maatoo_id'])) {
                        continue;
                    }

                    $orderLines[] = [
                        'store' => $this->storeMap->getStoreToMaatoo($store->getId()),
                        "product" => $maatooSyncProductRow['maatoo_id'],
                        "order" => $maatooSyncOrderRow['maatoo_id'],
                        "quantity" => intval($product->getQtyOrdered())
                    ];

                    $maatoSyncInsertData[] = [
                        'status' => SyncInterface::STATUS_SYNCHRONIZED,
                        'maatoo_id' => '',
                        "entity_id" => $quoteId,
                        "store_id" => $order->getStoreId(),
                        "entity_type" => SyncInterface::TYPE_ORDER_LINES,
                    ];
                }

                $updateOrdersData[$order->getId()] = SyncInterface::ORDER_LINES_STATUS_SYNCHRONIZED;
            }

            if (!empty($updateOrdersData) && !empty($maatoSyncInsertData)) {
                $result = $this->adapter->makeRequest('orderLines/batch/new', $orderLines, 'POST');
                $maatoSyncInsertData = $this->helper->setMaatooIdToInsertArray($maatoSyncInsertData, $result['orderLines']);
                $arrayOrdersDataKeys = array_keys($updateOrdersData);
                $firstKey = reset($arrayOrdersDataKeys);
                $lastKey = end($arrayOrdersDataKeys);
                $this->logger->info(
                    'Added items to orders from #' . $firstKey .
                    ' to #' . $lastKey . ' to maatoo'
                );
                if (is_callable($cl)) {
                    $cl(
                        'Added items to orders from #'. $firstKey.
                        ' to #' . $lastKey . ' to maatoo'
                    );
                }
            }

            $this->helper->executeUpdateSalesOrderTable($updateOrdersData);
            $this->helper->executeInsertOnDuplicate($maatoSyncInsertData);
            $this->logger->info("Finished syncing all orderlines to maatoo.");
        }
    }
}
