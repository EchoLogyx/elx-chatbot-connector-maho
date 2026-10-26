<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Order
{
    /**
     * @return array<string, mixed>
     */
    public function lookup(string $orderId, string $email): array
    {
        $orderId = trim($orderId);
        $email = trim($email);

        if ($orderId === '' && $email === '') {
            return [
                'success' => false,
                'message' => 'Either "order_id" or "email" must be provided.',
            ];
        }

        if ($orderId !== '') {
            $order = $this->findByIncrementId($orderId);
            if (!$order) {
                return [
                    'success' => false,
                    'message' => 'Order not found for the provided order_id.',
                ];
            }

            return $this->formatOrder($order);
        }

        $orders = $this->findByEmail($email);
        if ($orders === []) {
            return [
                'success' => false,
                'message' => 'No orders found for the provided email.',
            ];
        }

        $formatted = [];
        foreach ($orders as $order) {
            $formatted[] = $this->formatOrder($order);
        }

        return [
            'success' => true,
            'count' => count($formatted),
            'orders' => $formatted,
        ];
    }

    private function findByIncrementId(string $orderId): ?Mage_Sales_Model_Order
    {
        $collection = Mage::getModel('sales/order')->getCollection();
        $collection->addFieldToFilter('increment_id', $orderId);
        /** @var Mage_Sales_Model_Order $order */
        $order = $collection->getFirstItem();

        return $order->getId() ? $order : null;
    }

    /**
     * @return list<Mage_Sales_Model_Order>
     */
    private function findByEmail(string $email): array
    {
        $collection = Mage::getModel('sales/order')->getCollection();
        $collection->addFieldToFilter('customer_email', $email);
        $collection->setOrder('created_at', 'DESC');

        $orders = [];
        foreach ($collection as $order) {
            if ($order instanceof Mage_Sales_Model_Order) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * @return array<string, mixed>
     */
    private function formatOrder(Mage_Sales_Model_Order $order): array
    {
        $currency = (string) ($order->getOrderCurrencyCode() ?: $order->getBaseCurrencyCode());

        return [
            'order_id' => (int) $order->getId(),
            'order_name' => '#' . $order->getIncrementId(),
            'customer_email' => (string) $order->getCustomerEmail(),
            'order_status' => (string) $order->getStatus(),
            'created_at' => $this->formatDate((string) $order->getCreatedAt()),
            'subtotal_price' => number_format((float) $order->getSubtotal(), 2, '.', '') . ' ' . $currency,
            'payment_status' => ((float) $order->getTotalPaid() > 0) ? 'paid' : 'pending',
            'is_refundable' => (bool) $order->canCreditmemo(),
            'shipping_address' => $this->formatShippingAddress($order),
            'zip' => $this->getZipCode($order),
            'line_items' => $this->buildLineItems($order),
            'tracking_info' => $this->buildTrackingInfo($order),
            'cancelled_at' => $order->getState() === Mage_Sales_Model_Order::STATE_CANCELED
                ? $this->formatDate((string) $order->getUpdatedAt())
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildLineItems(Mage_Sales_Model_Order $order): array
    {
        $lineItems = [];
        foreach ($order->getAllVisibleItems() as $item) {
            $lineItems[] = [
                'id' => (string) $item->getItemId(),
                'product_id' => (string) $item->getProductId(),
                'name' => (string) $item->getName(),
                'quantity' => (int) $item->getQtyOrdered(),
                'price' => number_format((float) $item->getPrice(), 2, '.', ''),
                'sku' => (string) $item->getSku(),
            ];
        }

        return $lineItems;
    }

    /**
     * @return list<array<string, string>>
     */
    private function buildTrackingInfo(Mage_Sales_Model_Order $order): array
    {
        $trackingInfo = [];
        foreach ($order->getShipmentsCollection() as $shipment) {
            foreach ($shipment->getAllTracks() as $track) {
                $trackingInfo[] = [
                    'tracking_number' => (string) $track->getNumber(),
                    'tracking_carrier' => (string) $track->getCarrierCode(),
                    'tracking_link' => (string) $track->getData('url'),
                    'date_created' => $this->formatDate((string) $shipment->getCreatedAt()),
                    'shipping_method' => (string) ($order->getShippingMethod() ?: ''),
                ];
            }
        }

        return $trackingInfo;
    }

    private function formatShippingAddress(Mage_Sales_Model_Order $order): string
    {
        $shipping = $order->getShippingAddress();
        if (!$shipping) {
            return '';
        }

        $parts = [];
        $street = $shipping->getStreet();
        if (is_array($street)) {
            $street = implode(', ', array_filter($street));
        }
        if (is_string($street) && $street !== '') {
            $parts[] = $street;
        }
        if ($shipping->getCity()) {
            $parts[] = (string) $shipping->getCity();
        }
        if ($shipping->getRegion()) {
            $parts[] = (string) $shipping->getRegion();
        }
        if ($shipping->getCountryId()) {
            $parts[] = (string) $shipping->getCountryId();
        }

        return implode(', ', $parts);
    }

    private function getZipCode(Mage_Sales_Model_Order $order): string
    {
        $shipping = $order->getShippingAddress();

        return $shipping ? (string) $shipping->getPostcode() : '';
    }

    private function formatDate(string $dateString): string
    {
        if ($dateString === '') {
            return '';
        }

        try {
            return (new DateTime($dateString))->format('D, d M Y H:i:s O');
        } catch (Exception) {
            return $dateString;
        }
    }
}
