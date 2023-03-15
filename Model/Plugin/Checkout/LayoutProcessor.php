<?php

namespace Maatoo\Maatoo\Model\Plugin\Checkout;

use Maatoo\Maatoo\Model\Config\Config;

class LayoutProcessor
{
    /**
     * @var Config
     */
    private $config;

    /**
     * Constructor
     *
     * @param Config $config
     */
    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    /**
     * Hide birthday field in checkout page if this configured in admin panel
     *
     * @param \Magento\Checkout\Block\Checkout\LayoutProcessor $subject
     * @param array $jsLayout
     *
     * @return array
     */
    public function afterProcess(
        \Magento\Checkout\Block\Checkout\LayoutProcessor $subject,
        array  $jsLayout
    ) {

        if (!$this->config->isAllowedBirthdayInCheckout()) {
            $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']
            ['shippingAddress']['children']['shipping-address-fieldset']['children']['birthday'] = '';
        }

        return $jsLayout;
    }
}
