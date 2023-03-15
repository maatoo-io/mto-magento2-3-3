<?php

namespace Maatoo\Maatoo\Model\Synchronization;

use DateTime;
use Maatoo\Maatoo\Api\Data\SyncInterface;
use Maatoo\Maatoo\Model\Config\Config;
use Maatoo\Maatoo\Model\OrderRepository;
use Maatoo\Maatoo\Model\OrderStatusMap;
use Magento\Quote\Model\ResourceModel\Quote;

/**
 * Class Order
 * @package Maatoo\Maatoo\Model\Synchronization
 */
class Order
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
     * @var OrderRepository
     */
    private $orderRepository;

    /**
     * @var Quote\CollectionFactory
     */
    private $collectionQuoteFactory;

    /**
     * @var \Maatoo\Maatoo\Model\StoreMap
     */
    private $storeMap;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var \Maatoo\Maatoo\Model\SyncRepository
     */
    private $syncRepository;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    private $urlBilder;

    /**
     * @var \Maatoo\Maatoo\Model\ConversionFactory
     */
    private $conversionFactory;

    /**
     * @var \Maatoo\Maatoo\Model\ResourceModel\Conversion
     */
    private $conversionResource;

    /**
     * @var \Maatoo\Maatoo\Model\OrderLeadFactory
     */
    private $orderLeadFactory;

    /**
     * @var \Maatoo\Maatoo\Model\ResourceModel\OrderLead
     */
    private $orderLeadResource;

    /**
     * @var \Magento\Framework\Serialize\Serializer\Serialize
     */
    private $serialize;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;


    public function __construct(
        \Maatoo\Maatoo\Model\StoreConfigManager                    $storeManager,
        \Magento\Quote\Model\ResourceModel\Quote\CollectionFactory $collectionQuoteFactory,
        \Magento\Framework\UrlInterface                            $urlBilder,
        \Maatoo\Maatoo\Model\OrderRepository                       $orderRepository,
        \Maatoo\Maatoo\Model\Config\Config                         $config,
        \Maatoo\Maatoo\Adapter\AdapterInterface                    $adapter,
        \Maatoo\Maatoo\Model\StoreMap                              $storeMap,
        \Maatoo\Maatoo\Model\SyncRepository                        $syncRepository,
        \Maatoo\Maatoo\Model\ConversionFactory                     $conversionFactory,
        \Maatoo\Maatoo\Model\ResourceModel\Conversion              $conversionResource,
        \Maatoo\Maatoo\Model\OrderLeadFactory                      $orderLeadFactory,
        \Maatoo\Maatoo\Model\ResourceModel\OrderLead               $orderLeadResource,
        \Magento\Framework\Serialize\Serializer\Serialize          $serialize,
        \Psr\Log\LoggerInterface                                   $logger
    )
    {
        $this->storeManager = $storeManager;
        $this->orderRepository = $orderRepository;
        $this->collectionQuoteFactory = $collectionQuoteFactory;
        $this->config = $config;
        $this->adapter = $adapter;
        $this->storeMap = $storeMap;
        $this->syncRepository = $syncRepository;
        $this->urlBilder = $urlBilder;
        $this->conversionFactory = $conversionFactory;
        $this->conversionResource = $conversionResource;
        $this->orderLeadFactory = $orderLeadFactory;
        $this->orderLeadResource = $orderLeadResource;
        $this->serialize = $serialize;
        $this->logger = $logger;
    }

    /**
     * @param \Closure|null $cl
     */
    public function sync(\Closure $cl = null)
    {
        $this->logger->info("Begin syncing orders to maatoo.");
        /** @var \Magento\Store\Api\Data\StoreInterface $store */
        foreach ($this->storeManager->getStores() as $store) {

            $collection = $this->collectionQuoteFactory->create();
            $collection->addFieldToFilter('store_id', $store->getId());
            $lifetime = $this->config->getOrderLifetime();
            $collection->getSelect()->where(
                new \Zend_Db_Expr('TIME_TO_SEC(TIMEDIFF(CURRENT_TIMESTAMP, `updated_at`)) <= ' . $lifetime * 24 * 60 * 60)
            );

            $this->logger->info("Found ". sizeof($collection) ." orders to be processed for maatoo");
            /** @var \Magento\Quote\Model\Quote $quote */
            foreach ($collection as $quote) {
                /** @var \Maatoo\Maatoo\Model\Sync $sync */
                $sync = $this->syncRepository->getByParam([
                    'entity_id' => $quote->getId(),
                    'entity_type' => SyncInterface::TYPE_ORDER,
                    'store_id' => $store->getId(),
                ]);

                if ($sync->getData('status') == SyncInterface::STATUS_SYNCHRONIZED) {
                    continue;
                }

                $parameters = $this->getParameters($quote);

                if (empty($parameters)) {
                    continue;
                }

                $conversion = $this->conversionFactory->create();
                $this->conversionResource->load($conversion, $quote->getId(), 'order_id');
                if (!empty($conversion->getValue())) {
                    $conversionArray = $this->serialize->unserialize($conversion->getValue());
                    if (isset($conversionArray['channel']) && isset($conversionArray['lead'])) {
                        $conversionArrayKeys = array_keys($conversionArray['channel']);
                        $firstKey = reset($conversionArrayKeys);
                        $parameters['conversion']['type'] = $firstKey;
                        $parameters['conversion']['id'] = $conversionArray['channel'][$firstKey];

                        if (isset($parameters['lead_id'])) {
                            //$parameters['lead_id'] = $conversionArray['lead'];
                        }
                    }
                }

                $result = [];

                if (empty($sync->getData('status')) || $sync->getData('status') == SyncInterface::STATUS_EMPTY) {
                    $result = $this->adapter->makeRequest('orders/new', $parameters, 'POST');
                    $this->logger->info('Added order #' . $quote->getId() . ' to maatoo');
                    if (is_callable($cl)) {
                        $cl('Added order #' . $quote->getId() . ' to maatoo');
                    }
                } elseif ($sync->getData('status') == SyncInterface::STATUS_UPDATED) {
                    $result = $this->adapter->makeRequest('orders/' . $sync->getData('maatoo_id') . '/edit', $parameters, 'PATCH');
                    $this->logger->info('Updated order #' . $quote->getId() . ' in maatoo');
                    if (is_callable($cl)) {
                        $cl('Updated order #' . $quote->getId() . ' in maatoo');
                    }
                } elseif ($sync->getData('status') == SyncInterface::STATUS_DELETED) {
                    $result = $this->adapter->makeRequest('orders/' . $sync->getData('maatoo_id') . '/delete', [], 'DELETE');
                    $this->logger->info('Deleted order #' . $quote->getId() . ' from maatoo');
                    if (is_callable($cl)) {
                        $cl('Deleted order #' . $quote->getId() . ' from maatoo');
                    }
                }


                if (isset($result['order']['id'])) {

                    // Update contacts
                    $lead = $this->getLead($quote->getId());
                    if (!empty($lead->getId()) && !empty($parameters['email'])) {
                        $leadId = $lead->getLeadId();
                        $data = [
                            'firstname' => $parameters['firstName'] ?? '',
                            'lastname' => $parameters['lastName'] ?? '',
                            'email' => $parameters['email'] ?? '',
                        ];
                        if (!empty($lead->getSubscribe())) {
                            $data['tags'] = $this->storeManager->getTags($store);
                        }
                        if (isset($parameters['birthday']) && $parameters['birthday']) {
                            $data['birthday_date'] = $parameters['birthday'];
                        }
                        $this->adapter->makeRequest('contacts/' . $leadId . '/edit', $data, 'PATCH');
                    }

                    // Update sync table in store
                    $param = [
                        'entity_id' => $quote->getId(),
                        'entity_type' => SyncInterface::TYPE_ORDER
                    ];
                    /** @var \Maatoo\Maatoo\Model\Sync $sync */
                    $sync = $this->syncRepository->getByParam($param);
                    $sync->setStatus(SyncInterface::STATUS_SYNCHRONIZED);
                    $sync->setMaatooId($result['order']['id']);
                    $sync->setEntityId($quote->getId());
                    $sync->setEntityType(SyncInterface::TYPE_ORDER);
                    $sync->setStoreId($quote->getStoreId());
                    $this->syncRepository->save($sync);
                }
            }
        }
        $this->logger->info("Finished syncing orders to maatoo.");
    }

    /**
     * Set parameters to sync in maatoo system
     *
     * @param \Magento\Quote\Model\Quote $quote
     * @return array|null
     */
    public function getParameters(\Magento\Quote\Model\Quote $quote)
    {
        $parameters = [];
        $order = $this->orderRepository->getByIncrementId($quote->getReservedOrderId());
        $leadId = $this->getLeadId($quote->getId());

        if ($order !== null && !empty($order->getId())) {
            $billingAddress = $order->getBillingAddress();
            $birthdayDateTime = $billingAddress ? $billingAddress->getBirthday() : '';
            if ($birthdayDateTime) {
                $birthdayDate = DateTime::createFromFormat('Y-m-d H:i:s', $birthdayDateTime)->format('Y-m-d');
            }

            $parameters = [
                'store' => $this->storeMap->getStoreToMaatoo($order->getStoreId()),
                'externalOrderId' => $order->getIncrementId(),
                'externalDateProcessed' => $order->getCreatedAt(),
                'externalDateUpdated' => $order->getUpdatedAt(),
                'value' => (float)$order->getGrandTotal(),
                'discountValue' => (float)$order->getDiscountAmount(),
                'taxValue' => (float)$order->getTaxAmount(),
                'shippingValue' => (float)$order->getShippingAmount(),
                'url' => $this->urlBilder->getUrl('sales/order/view', ['id' => $order->getId()]),
                'status' => OrderStatusMap::getStatus($order->getStatus()),
                'paymentMethod' => $order->getPayment()->getMethod(),
                'email' => $order->getCustomerEmail() ?: '',
                'firstName' => $order->getCustomerFirstname() ?: '',
                'lastName' => $order->getCustomerLastname() ?: '',
                'lead_id' => $leadId,
                'conversion' => [],
                'birthday' => $birthdayDate ?? ''
            ];

            if ($order->getState() == \Magento\Sales\Model\Order::STATE_CANCELED) {
                $orderCancelDate = null;
                $commentCollection = $order->getStatusHistoryCollection();
                /**
                 * @var $comment \Magento\Sales\Model\Order\Status\History
                 */
                foreach ($commentCollection as $comment) {
                    if ($comment->getStatus() === \Magento\Sales\Model\Order::STATE_CANCELED) {
                        $orderCancelDate = $comment->getCreatedAt();
                    }
                }

                if ($orderCancelDate) {
                    $parameters['externalDateCancelled'] = $orderCancelDate;
                }
            }

        } else {
            $updateTime = strtotime($quote->getUpdatedAt());
            if ($updateTime > (date('U') - 1800)) {
                return null;
            }
            if (empty($leadId)) {
                return null;
            }
            $parameters = [
                'store' => $this->storeMap->getStoreToMaatoo($quote->getStoreId()),
                'externalOrderId' => 'draft' . $quote->getId(),
                'externalDateProcessed' => $quote->getCreatedAt(),
                'externalDateUpdated' => $quote->getUpdatedAt(),
                'value' => (float)$quote->getGrandTotal(),
                'url' => $this->urlBilder->getUrl('checkout/cart'),
                'status' => OrderStatusMap::DRAFT,
                'lead_id' => $leadId,
                'conversion' => []
            ];
        }

        return $parameters;
    }

    public function getLead($orderId)
    {
        /** @var \Maatoo\Maatoo\Model\OrderLead $orderLead */
        $orderLead = $this->orderLeadFactory->create();
        $this->orderLeadResource->load($orderLead, $orderId, 'order_id');
        return $orderLead;
    }

    public function getLeadId($orderId)
    {
        /** @var \Maatoo\Maatoo\Model\OrderLead $orderLead */
        $orderLead = $this->getLead($orderId);
        $leadId = '';
        if (!empty($orderLead->getLeadId())) {
            $leadId = $orderLead->getLeadId();
        }
        return $leadId;
    }
}
