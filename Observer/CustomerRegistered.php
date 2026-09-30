<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Observer;

use JustPush\Notify\Model\PayloadBuilder;
use JustPush\Notify\Model\Publisher;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * `customer_save_after_data_object` for new customers only.
 *
 * CustomerRepository::save fires it for storefront sign-ups as well as admin and API
 * customers, so `customer_register_success` isn't needed and nothing is sent twice.
 */
class CustomerRegistered implements ObserverInterface
{
    /**
     * Customer IDs already sent in this request.
     *
     * @var array<int, true>
     */
    private array $sent = [];

    /**
     * @param Publisher $publisher
     * @param PayloadBuilder $payloadBuilder
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        private readonly Publisher $publisher,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Queue `customer.registered` for a newly created customer.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $event = $observer->getEvent();
            $customer = $event->getData('customer_data_object');
            if (!$customer instanceof CustomerInterface || $event->getData('orig_customer_data_object') !== null) {
                return;
            }

            $customerId = (int)$customer->getId();
            if ($customerId === 0 || isset($this->sent[$customerId])) {
                return;
            }
            $this->sent[$customerId] = true;

            $websiteId = $customer->getWebsiteId() !== null
                ? (int)$customer->getWebsiteId()
                : (int)$this->storeManager->getStore((int)$customer->getStoreId())->getWebsiteId();
            if (!$this->publisher->isActive('customer.registered', $websiteId)) {
                return;
            }

            $this->publisher->publish($this->payloadBuilder->customerRegistered($customer), $websiteId);
        } catch (\Throwable) {
            return;
        }
    }
}
