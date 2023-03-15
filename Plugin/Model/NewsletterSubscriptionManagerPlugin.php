<?php

namespace Maatoo\Maatoo\Plugin\Model;

use Maatoo\Maatoo\Model\Config\Config;
use Maatoo\Maatoo\Adapter\AdapterInterface;
use Maatoo\Maatoo\Model\StoreConfigManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Newsletter\Controller\Subscriber\NewAction;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Newsletter\Model\SubscriptionManager;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class NewsletterSubscriptionManagerPlugin
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreConfigManager
     */
    private $storeConfigManager;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var AdapterInterface
     */
    private $adapter;

    /**
     * @var CookieManagerInterface
     */
    private $cookieManager;

    private $logger;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Config $config,
        StoreConfigManager $storeConfigManager,
        AdapterInterface $adapter,
        CookieManagerInterface $cookieManager,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger)
    {
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
        $this->storeConfigManager = $storeConfigManager;
        $this->adapter = $adapter;
        $this->cookieManager = $cookieManager;
        $this->storeManager = $storeManager;
        $this->logger = $logger;

    }


    /**
     * @param Magento\Newsletter\Model\SubscriptionManager $action
     * @param $result
     *
     * @return mixed
     */
    public function afterSubscribe(SubscriptionManager $subscriber, $result, $email, $storeId)
    {
        $store = $this->storeManager->getStore($storeId);

        $leadId = $this->cookieManager->getCookie('mtc_id');
        $data = [
            'email' => $email,
        ];

        $data['tags'] = $this->storeConfigManager->getTags($store);
        $this->adapter->makeRequest('contacts/' . $leadId . '/edit', $data, 'PATCH');


        return $result;
    }

}
