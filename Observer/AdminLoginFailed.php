<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Observer;

use JustPush\Notify\Model\LoginThrottle;
use JustPush\Notify\Model\PayloadBuilder;
use JustPush\Notify\Model\Publisher;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;

/**
 * `backend_auth_user_login_failed`, throttled per username by LoginThrottle.
 */
class AdminLoginFailed implements ObserverInterface
{
    /**
     * @param Publisher $publisher
     * @param PayloadBuilder $payloadBuilder
     * @param LoginThrottle $throttle
     */
    public function __construct(
        private readonly Publisher $publisher,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly LoginThrottle $throttle
    ) {
    }

    /**
     * Queue `admin.login_failed`, unless the throttle holds it back.
     *
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            if (!$this->publisher->isActive('admin.login_failed', null)) {
                return;
            }

            $username = (string)$observer->getEvent()->getData('user_name');
            $attempts = $this->throttle->registerFailure($username);
            if ($attempts === null) {
                return;
            }

            $this->publisher->publish($this->payloadBuilder->adminLoginFailed($username, $attempts), null);
        } catch (\Throwable) {
            return;
        }
    }
}
