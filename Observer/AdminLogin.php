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
use Magento\User\Model\User;

/**
 * `backend_auth_user_login_success`.
 */
class AdminLogin implements ObserverInterface
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
     * Queue `admin.login`.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $user = $observer->getEvent()->getData('user');
            if (!$user instanceof User || !$this->publisher->isActive('admin.login', null)) {
                return;
            }

            $this->publisher->publish($this->payloadBuilder->adminLogin($user), null);
        } catch (\Throwable) {
            return;
        }
    }
}
