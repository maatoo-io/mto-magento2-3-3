<?php

namespace Maatoo\Maatoo\Plugin\Model;

use Maatoo\Maatoo\Model\OrderLeadFactory;
use Maatoo\Maatoo\Model\ResourceModel\OrderLead;
use Magento\Checkout\Api\GuestPaymentInformationManagementInterface;
use Magento\Checkout\Model\GuestPaymentInformationManagement;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\PaymentInterface;
use Magento\Quote\Model\MaskedQuoteIdToQuoteIdInterface;
use Psr\Log\LoggerInterface;

class GuestSavePaymentPlugin
{
    private $orderLeadFactory;

    private $orderLeadResource;

    private $logger;

    private $maskedQuoteIdToQuoteId;

    /**
     * GuestSavePaymentPlugin constructor
     *
     * @param OrderLeadFactory $orderLeadFactory
     * @param OrderLead $orderLeadResource
     * @param CartRepositoryInterface $quoteRepository
     * @param MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderLeadFactory        $orderLeadFactory,
        OrderLead               $orderLeadResource,
        CartRepositoryInterface $quoteRepository,
        MaskedQuoteIdToQuoteIdInterface $maskedQuoteIdToQuoteId,
        LoggerInterface         $logger
    ) {
        $this->orderLeadFactory = $orderLeadFactory;
        $this->orderLeadResource = $orderLeadResource;
        $this->logger = $logger;
        $this->quoteRepository = $quoteRepository;
        $this->maskedQuoteIdToQuoteId = $maskedQuoteIdToQuoteId;
    }

    /**
     * Add maatoo opt-in parameter to needed table
     *
     * @param GuestPaymentInformationManagement $subject
     * @param string $cartId
     * @param string $email
     * @param PaymentInterface $paymentMethod
     * @param AddressInterface|null $billingAddress
     *
     * @throws NoSuchEntityException
     */
    public function beforeSavePaymentInformationAndPlaceOrder(
        GuestPaymentInformationManagementInterface $subject,
                                                   $cartId,
                                                   $email,
        PaymentInterface                           $paymentMethod,
        AddressInterface                           $billingAddress = null
    ) {
        $quoteId = $this->maskedQuoteIdToQuoteId->execute($cartId);

        if (!empty($paymentMethod->getExtensionAttributes()->getMaatooOptIn())) {
            try {
                $orderLead = $this->orderLeadFactory->create();
                $this->orderLeadResource->load($orderLead, $quoteId, 'order_id');
                $orderLead->setSubscribe(1);
                $this->orderLeadResource->save($orderLead);
            } catch (\Exception $exception) {
                $this->logger->error($exception->getMessage());
            }
        }
    }
}
