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
use Magento\Sales\Model\Order\Creditmemo;

/**
 * `sales_order_creditmemo_refund` and `sales_order_creditmemo_save_after`, once per credit memo.
 *
 * Same pattern as InvoicePaid: when the refund event fires before the credit memo has been
 * saved, the message goes out from save_after instead.
 */
class OrderRefunded implements ObserverInterface
{
    private const FLAG = 'justpush_refund_pending';

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
     * Queue `order.refunded`, or flag the credit memo until it is saved.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $creditmemo = $observer->getEvent()->getData('creditmemo');
            if (!$creditmemo instanceof Creditmemo) {
                return;
            }

            if ($observer->getEvent()->getName() === 'sales_order_creditmemo_refund') {
                if ($creditmemo->getId() && $creditmemo->getIncrementId()) {
                    $this->send($creditmemo);
                } else {
                    $creditmemo->setData(self::FLAG, true);
                }

                return;
            }

            // sales_order_creditmemo_save_after
            if ($creditmemo->getData(self::FLAG)) {
                $creditmemo->unsetData(self::FLAG);
                $this->send($creditmemo);
            }
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Queue one `order.refunded` message.
     *
     * @param Creditmemo $creditmemo
     * @return void
     */
    private function send(Creditmemo $creditmemo): void
    {
        $websiteId = (int)$creditmemo->getStore()->getWebsiteId();
        if ($this->publisher->isActive('order.refunded', $websiteId)) {
            $this->publisher->publish($this->payloadBuilder->orderRefunded($creditmemo), $websiteId);
        }
    }
}
