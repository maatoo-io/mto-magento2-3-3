<?php

namespace Maatoo\Maatoo\Plugin\Index;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Model\QuoteRepository;
use Magento\Sales\Api\Data\OrderInterface;

class SaveBirthdayIntoOrderAddress
{

    /**
     * @var QuoteRepository
     */
    private $quoteRepository;

    /**
     * Constructor
     *
     * @param QuoteRepository $quote
     */
    public function __construct(QuoteRepository $quote)
    {
        $this->quoteRepository = $quote;
    }

    /**
     * Save birthday into billing and shipping addresses in sales_order_address table
     *
     * @param $subject
     * @param OrderInterface $order
     *
     * @return void
     * @throws NoSuchEntityException
     */
    public function beforePlace(
        $subject,
        OrderInterface $order
    ): void
    {
        $quoteId = $order->getQuoteId();

        $quote = $this->quoteRepository->get($quoteId);

        $quoteShippingAddress = $quote->getShippingAddress();

        // Because birthday extension attribute sets from frontend only into shipping extension attributes
        $shippingBirthday = $quoteShippingAddress->getBirthday();

        if ($shippingBirthday) {
            $orderBillingAddress = $order->getBillingAddress();
            if ($orderBillingAddress) {
                $orderBillingAddress->setBirthday($shippingBirthday);
            }

            $orderShippingAddress = $order->getShippingAddress();
            if ($orderShippingAddress) {
                $orderShippingAddress->setBirthday($shippingBirthday);
            }
        }
    }
}
