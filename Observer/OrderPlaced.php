<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Observer;

use JustPush\Notify\Model\PayloadBuilder;
use JustPush\Notify\Model\Publisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;

/**
 * `sales_model_service_quote_submit_success`: fired by QuoteManagement::submit for storefront,
 * admin, REST and GraphQL orders.
 *
 * Multishipping doesn't use QuoteManagement::submit. Its orders arrive through
 * `checkout_submit_all_after` with an `orders` list (one message per order). The single-order
 * form of that event (`order` key) is ignored, since submit_success already covered it.
 */
class OrderPlaced implements ObserverInterface
{
    /**
     * @param Publisher $publisher
     * @param PayloadBuilder $payloadBuilder
     */
    public function __construct(
        private readonly Publisher $publisher,
        private readonly PayloadBuilder $payloadBuilder
    ) {
    }

    /**
     * Queue `order.placed` for the order(s) in the event.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        $event = $observer->getEvent();
        if ($event->getName() === 'checkout_submit_all_after') {
            $orders = $event->getData('orders');
            foreach (is_array($orders) ? $orders : [] as $order) {
                if ($order instanceof Order && $order->getId() && $order->getState() !== Order::STATE_CANCELED) {
                    $this->send($order);
                }
            }

            return;
        }

        $order = $event->getData('order');
        if ($order instanceof Order) {
            $this->send($order);
        }
    }

    /**
     * Queue one `order.placed` message.
     *
     * @param Order $order
     * @return void
     */
    private function send(Order $order): void
    {
        try {
            $websiteId = (int)$order->getStore()->getWebsiteId();
            if (!$this->publisher->isActive('order.placed', $websiteId)) {
                return;
            }

            $this->publisher->publish($this->payloadBuilder->orderPlaced($order), $websiteId);
        } catch (\Throwable) {
            // Never break checkout. Publisher logs its own failures.
            return;
        }
    }
}
