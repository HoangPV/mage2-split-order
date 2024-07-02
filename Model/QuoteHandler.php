<?php

namespace Niteco\SplitOrder\Model;

use Magento\Quote\Api\CartManagementInterface;
use Magento\Customer\Api\Data\GroupInterface;

/**
 * Class QuoteHandler
 */
class QuoteHandler
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    /**
     * @param \Magento\Checkout\Model\Session $checkoutSession
     */
    public function __construct(
        \Magento\Checkout\Model\Session $checkoutSession
    ) {
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @param $quote
     * @return array
     */
    public function normalizeQuotes($quote) {
        $allItems = $quote->getAllVisibleItems();
        $mid = round(count($allItems) / 2);
        $firstHalf = array_slice($allItems, 0, $mid);
        $secondHalf = array_slice($allItems, $mid);
        return array($firstHalf, $secondHalf);
    }

    /**
     * @param $quote
     * @return array
     */
    public function collectAddressesData($quote)
    {
        $billing = $quote->getBillingAddress()->getData();
        unset($billing['id']);
        unset($billing['quote_id']);

        $shipping = $quote->getShippingAddress()->getData();
        unset($shipping['id']);
        unset($shipping['quote_id']);

        return [
            'payment' => $quote->getPayment()->getMethod(),
            'billing' => $billing,
            'shipping' => $shipping
        ];
    }

    /**
     * @param $quote
     * @param $split
     * @return $this
     */
    public function setCustomerData($quote, $split)
    {
        $split->setStoreId($quote->getStoreId());
        $split->setCustomer($quote->getCustomer());
        $split->setCustomerIsGuest($quote->getCustomerIsGuest());

        if ($quote->getCheckoutMethod() === CartManagementInterface::METHOD_GUEST) {
            $split->setCustomerId(null);
            $split->setCustomerEmail($quote->getBillingAddress()->getEmail());
            $split->setCustomerIsGuest(true);
            $split->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID);
        }
        return $this;
    }

    /**
     * @param $quotes
     * @param $split
     * @param $items
     * @param $addresses
     * @param $payment
     * @return $this
     */
    public function populateQuote($quotes, $split, $items, $addresses, $payment)
    {
        $this->recollectTotal($quotes, $items, $split, $addresses);
        // Set payment method.
        $this->setPaymentMethod($split, $addresses['payment'], $payment);

        return $this;
    }

    /**
     * @param $quotes
     * @param $items
     * @param $quote
     * @param $addresses
     * @return $this
     */
    public function recollectTotal($quotes, $items, $quote, $addresses)
    {
        $tax = 0.0;
        $discount = 0.0;
        $finalPrice = 0.0;

        foreach ($items as $item) {
            // Retrieve values.
            $tax += $item->getData('tax_amount');
            $discount += $item->getData('discount_amount');

            $finalPrice += ($item->getPrice() * $item->getQty());
        }

        // Set addresses.
        $quote->getBillingAddress()->setData($addresses['billing']);
        $quote->getShippingAddress()->setData($addresses['shipping']);

        // Add shipping amount if product is not virtual.
        $shipping = $this->shippingAmount($quotes, $quote);

        // Recollect totals into the quote.
        foreach ($quote->getAllAddresses() as $address) {
            // Build grand total.
            $grandTotal = (($finalPrice + $shipping + $tax) - $discount);

            $address->setBaseSubtotal($finalPrice);
            $address->setSubtotal($finalPrice);
            $address->setDiscountAmount($discount);
            $address->setTaxAmount($tax);
            $address->setBaseTaxAmount($tax);
            $address->setBaseGrandTotal($grandTotal);
            $address->setGrandTotal($grandTotal);
        }
        return $this;
    }

    /**
     * @param $quotes
     * @param $quote
     * @param $total
     * @return float|mixed
     */
    public function shippingAmount($quotes, $quote, $total = 0.0)
    {
        // Add shipping amount if product is not virtual.
        if ($quote->hasVirtualItems() === true) {
            return $total;
        }
        $shippingTotals = $quote->getShippingAddress()->getShippingAmount();

        if ($shippingTotals > 0) {
            // Divide shipping to each order.
            $total = (float) ($shippingTotals / count($quotes));
            $quote->getShippingAddress()->setShippingAmount($total);
        }
        return $total;
    }

    /**
     * @param $split
     * @param $payment
     * @param $paymentMethod
     * @return $this
     */
    public function setPaymentMethod($split, $payment, $paymentMethod)
    {
        $split->getPayment()->setMethod($payment);

        if ($paymentMethod) {
            $split->getPayment()->setQuote($split);
            $data = $paymentMethod->getData();
            $split->getPayment()->importData($data);
        }
        return $this;
    }

    /**
     * @param $split
     * @param $order
     * @param $orderIds
     * @return $this
     */
    public function defineSessions($split, $order, $orderIds)
    {
        $this->checkoutSession->setLastQuoteId($split->getId());
        $this->checkoutSession->setLastSuccessQuoteId($split->getId());
        $this->checkoutSession->setLastOrderId($order->getId());
        $this->checkoutSession->setLastRealOrderId($order->getIncrementId());
        $this->checkoutSession->setLastOrderStatus($order->getStatus());
        $this->checkoutSession->setOrderIds($orderIds);

        return $this;
    }
}
