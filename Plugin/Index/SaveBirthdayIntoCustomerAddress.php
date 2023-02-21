<?php

namespace Maatoo\Maatoo\Plugin\Index;

use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Api\Data\AddressInterface;

class SaveBirthdayIntoCustomerAddress
{
    /**
     * @var \Magento\Checkout\Model\Session\Proxy
     */
    private $checkoutSession;

    public function __construct(\Magento\Checkout\Model\Session\Proxy $checkoutSession )
    {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * Before save address
     *
     * @param AddressRepositoryInterface $subject
     * @param AddressInterface $address
     *
     * @return array
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function beforeSave(AddressRepositoryInterface $subject, AddressInterface $address): array
    {
        $quote = $this->checkoutSession->getQuote();
        if ($quote && $quote->getId()) {
            $birthday = '';
            $addresses = $quote->getAllAddresses();
            foreach ($addresses as $addressItem) {
                $extensionAttributes = $addressItem->getExtensionAttributes();
                if ($extensionAttributes && $extensionAttributes->getBirthday()) {
                    $birthday = $extensionAttributes->getBirthday();
                }
            }

            if ($birthday) {
                $address->setCustomAttribute('birthday', $birthday);
            }
        }

        return [$address];
    }
}
