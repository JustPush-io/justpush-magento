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
use Magento\Sales\Model\Order\Invoice;

/**
 * `sales_order_invoice_pay` and `sales_order_invoice_save_after`.
 *
 * Invoice::pay() usually runs before the invoice is saved, when it has no increment ID yet.
 * In that case the invoice is flagged, and the message goes out from save_after, inside the
 * same transaction.
 */
class InvoicePaid implements ObserverInterface
{
    private const FLAG = 'justpush_paid_pending';

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
     * Queue `invoice.paid`, or flag the invoice until it is saved.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $invoice = $observer->getEvent()->getData('invoice');
            if (!$invoice instanceof Invoice) {
                return;
            }

            if ($observer->getEvent()->getName() === 'sales_order_invoice_pay') {
                if ($invoice->getId() && $invoice->getIncrementId()) {
                    $this->send($invoice);
                } else {
                    $invoice->setData(self::FLAG, true);
                }

                return;
            }

            // sales_order_invoice_save_after
            if ($invoice->getData(self::FLAG)) {
                $invoice->unsetData(self::FLAG);
                $this->send($invoice);
            }
        } catch (\Throwable) {
            return;
        }
    }

    /**
     * Queue one `invoice.paid` message.
     *
     * @param Invoice $invoice
     * @return void
     */
    private function send(Invoice $invoice): void
    {
        $websiteId = (int)$invoice->getStore()->getWebsiteId();
        if ($this->publisher->isActive('invoice.paid', $websiteId)) {
            $this->publisher->publish($this->payloadBuilder->invoicePaid($invoice), $websiteId);
        }
    }
}
