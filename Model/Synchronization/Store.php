<?php

namespace Maatoo\Maatoo\Model\Synchronization;

/**
 * Class Store
 * @package Maatoo\Maatoo\Model\Synchronization
 */
class Store
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
     * @var \Maatoo\Maatoo\Model\StoreRepository
     */
    private $maatooStoreRepository;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    /**
     * Store constructor.
     * @param \Maatoo\Maatoo\Model\StoreConfigManager $storeManager
     * @param \Maatoo\Maatoo\Adapter\AdapterInterface $adapter
     * @param \Maatoo\Maatoo\Model\StoreRepository $maatooStoreRepository
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Maatoo\Maatoo\Model\StoreConfigManager $storeManager,
        \Maatoo\Maatoo\Adapter\AdapterInterface $adapter,
        \Maatoo\Maatoo\Model\StoreRepository $maatooStoreRepository,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->storeManager = $storeManager;
        $this->adapter = $adapter;
        $this->maatooStoreRepository = $maatooStoreRepository;
        $this->logger = $logger;
    }

    /**
     * @param \Closure|null $cl
     */
    public function sync(\Closure $cl = null)
    {
        $this->logger->info("Begin syncing stores to maatoo.");
        $parameters = [];
        $storesMaatoo = $this->adapter->makeRequest('stores', $parameters, 'GET');

        $this->logger->info("Found " . sizeof($this->storeManager->getStores()) ." enabled stores to be synced with maatoo.");
        foreach ($this->storeManager->getStores() as $store) {
            $addNew = true;
            $storesMaatooId = 0;
            foreach ($storesMaatoo['stores'] as $storeMaatoo) {
                if ($storeMaatoo['externalStoreId'] == $store->getId()) {
                    $addNew = false;
                    $storesMaatooId = $storeMaatoo['id'];
                }
            }

            $parameters = [
                'domain' => $store->getBaseUrl(),
                'name' => $store->getName(),
                'shortName' => $this->storeManager->getStoreShortName($store),
                'currency' => $store->getBaseCurrencyCode(),
                'externalStoreId' => $store->getId(),
                'platform' => 'magento',
            ];

            $result = [];
            if ($addNew) {
                $result = $this->adapter->makeRequest('stores/new', $parameters, 'POST');
                $this->logger->info('Added store #' . $store->getId() . ' ' . $store->getName(). ' to maatoo.');
                if(is_callable($cl)) {
                    $cl('Added store #' . $store->getId() . ' ' . $store->getName());
                }
            } else {
                $result = $this->adapter->makeRequest('stores/' . $storesMaatooId . '/edit', $parameters, 'PATCH');
                $this->logger->info('Updated maatoo store #'.$storesMaatooId.' with store #' . $store->getId() . ' ' . $store->getName());
                if(is_callable($cl)) {
                    $cl('Updated store #' . $store->getId() . ' ' . $store->getName());
                }
            }

            if (!$result) {
                $this->logger->warning(__('Response is empty. Please check logs.'));
                continue;
            }

            $maatooStoreModel = $this->maatooStoreRepository->getByStoresId($result['store']['id' ] ?? null, $store->getId());
            $maatooStoreModel->setMaatooStoreId($result['store']['id'] ?? null);
            $maatooStoreModel->setStoreId($store->getId());
            $this->maatooStoreRepository->save($maatooStoreModel);
        }
        $this->logger->info('Finished syncing stores to maatoo.');
    }
}
