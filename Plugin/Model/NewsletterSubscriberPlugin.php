<?php

namespace Maatoo\Maatoo\Plugin\Model;

use Maatoo\Maatoo\Model\Config\Config;
use Maatoo\Maatoo\Adapter\AdapterInterface;
use Maatoo\Maatoo\Model\StoreConfigManager;
use Magento\Framework\Stdlib\CookieManagerInterface;
use Magento\Newsletter\Controller\Subscriber\NewAction;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Newsletter\Model\Subscriber;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class NewsletterSubscriberPlugin
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
     * @var StoreConfigManager $storeConfigManager
     */
    private $storeConfigManager;

    /**
     * @var StoreManagerInterface $storeManager
     */
    private $storeManager;

    /**
     * @var AdapterInterface $adapter
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
     * @param Magento\Newsletter\Model\Subscriber $action
     * @param $result
     *
     * @return mixed
     */
    public function beforeSendConfirmationSuccessEmail(Subscriber $subject)
    {
        if ($this->config->isNewsletterConfirmationEmailDisabled()) {
            $subject->setImportMode(true);
        }
    }

    /**
     * @param NewAction $action
     * @param $result
     *
     * @return mixed
     */
    public function afterSubscribe(Subscriber $subscriber, $result, $email)
    {
        $storeId = $subscriber->getStoreId();
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
