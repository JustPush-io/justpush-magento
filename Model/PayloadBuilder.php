<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Model;

use Magento\Backend\Model\UrlInterface as BackendUrl;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;
use Magento\Framework\App\State;
use Magento\Framework\HTTP\PhpEnvironment\RemoteAddress;
use Magento\Framework\UrlInterface;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Creditmemo;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\User\Model\User;

/**
 * Builds the JSON bodies of the v1 payload contract (see HANDOFF.md / README.md).
 *
 * Only reads data that is already loaded on the entity, plus a few config values, so it is
 * cheap enough to call from checkout observers.
 */
class PayloadBuilder
{
    public const VERSION = 1;

    /**
     * @param StoreManagerInterface $storeManager
     * @param BackendUrl $backendUrl
     * @param State $appState
     * @param RemoteAddress $remoteAddress
     * @param GroupRepositoryInterface $groupRepository
     * @param RequestInterface $request
     */
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly BackendUrl $backendUrl,
        private readonly State $appState,
        private readonly RemoteAddress $remoteAddress,
        private readonly GroupRepositoryInterface $groupRepository,
        private readonly RequestInterface $request
    ) {
    }

    /**
     * Body for `order.placed`.
     *
     * @param Order $order
     * @return mixed[]
     */
    public function orderPlaced(Order $order): array
    {
        return $this->envelope('order.placed', (int)$order->getStoreId(), [
            'order' => [
                'increment_id' => (string)$order->getIncrementId(),
                'grand_total' => $this->money($order->getGrandTotal()),
                'currency' => $order->getOrderCurrencyCode(),
                'customer_name' => $this->orderCustomerName($order),
                'customer_email' => $order->getCustomerEmail(),
                'is_guest' => (bool)$order->getCustomerIsGuest(),
                'item_count' => (int)round((float)$order->getTotalQtyOrdered()),
                'payment_method' => $this->paymentTitle($order),
                'shipping_method' => $order->getIsVirtual() ? null : ($order->getShippingDescription() ?: null),
                'status' => $order->getStatus(),
                'source' => $this->source(),
                'admin_url' => $this->adminUrl('sales/order/view', ['order_id' => $order->getId()]),
            ],
        ]);
    }

    /**
     * Body for `invoice.paid`.
     *
     * @param Invoice $invoice
     * @return mixed[]
     */
    public function invoicePaid(Invoice $invoice): array
    {
        $order = $invoice->getOrder();

        return $this->envelope('invoice.paid', (int)$invoice->getStoreId(), [
            'invoice' => [
                'increment_id' => (string)$invoice->getIncrementId(),
                'order_increment_id' => (string)$order->getIncrementId(),
                'grand_total' => $this->money($invoice->getGrandTotal()),
                'currency' => $invoice->getOrderCurrencyCode() ?: $order->getOrderCurrencyCode(),
                'customer_name' => $this->orderCustomerName($order),
                'admin_url' => $this->adminUrl('sales/invoice/view', ['invoice_id' => $invoice->getId()]),
            ],
        ]);
    }

    /**
     * Body for `order.refunded`.
     *
     * @param Creditmemo $creditmemo
     * @return mixed[]
     */
    public function orderRefunded(Creditmemo $creditmemo): array
    {
        $order = $creditmemo->getOrder();

        return $this->envelope('order.refunded', (int)$creditmemo->getStoreId(), [
            'creditmemo' => [
                'increment_id' => (string)$creditmemo->getIncrementId(),
                'order_increment_id' => (string)$order->getIncrementId(),
                'grand_total' => $this->money($creditmemo->getGrandTotal()),
                'order_grand_total' => $this->money($order->getGrandTotal()),
                'currency' => $creditmemo->getOrderCurrencyCode() ?: $order->getOrderCurrencyCode(),
                'customer_name' => $this->orderCustomerName($order),
                'admin_url' => $this->adminUrl('sales/creditmemo/view', ['creditmemo_id' => $creditmemo->getId()]),
            ],
        ]);
    }

    /**
     * Body for `customer.registered`.
     *
     * @param CustomerInterface $customer
     * @return mixed[]
     */
    public function customerRegistered(CustomerInterface $customer): array
    {
        $name = trim(implode(' ', array_filter([$customer->getFirstname(), $customer->getLastname()])));

        return $this->envelope('customer.registered', (int)$customer->getStoreId(), [
            'customer' => [
                'name' => $name !== '' ? $name : null,
                'email' => $customer->getEmail(),
                'group' => $this->customerGroupName((int)$customer->getGroupId()),
                'source' => $this->source(),
                'admin_url' => $this->adminUrl('customer/index/edit', ['id' => $customer->getId()]),
            ],
        ]);
    }

    /**
     * Body for `admin.login`.
     *
     * @param User $user
     * @return mixed[]
     */
    public function adminLogin(User $user): array
    {
        $name = trim($user->getFirstName() . ' ' . $user->getLastName());

        return $this->envelope('admin.login', null, [
            'admin' => [
                'username' => $user->getUserName(),
                'name' => $name !== '' ? $name : null,
                'ip' => $this->remoteIp(),
                'admin_url' => $this->adminUrl('adminhtml/user/edit', ['user_id' => $user->getId()]),
            ],
        ]);
    }

    /**
     * Body for `admin.login_failed`.
     *
     * @param string $username
     * @param int $attempts Failed attempts for this username in the current throttle window
     * @return mixed[]
     */
    public function adminLoginFailed(string $username, int $attempts): array
    {
        return $this->envelope('admin.login_failed', null, [
            'admin' => [
                'username' => $username,
                'ip' => $this->remoteIp(),
                'attempts' => $attempts,
                'admin_url' => $this->adminUrl('adminhtml/locks/index'),
            ],
        ]);
    }

    /**
     * Body for `test.ping`.
     *
     * @param int|null $storeId
     * @return mixed[]
     */
    public function testPing(?int $storeId): array
    {
        return $this->envelope('test.ping', $storeId, [
            'message' => 'Test from Magento',
        ]);
    }

    /**
     * Common fields of every body. `sent_at` is filled in by the Sender.
     *
     * @param string $event
     * @param int|null $storeId Null means the default store view
     * @param mixed[] $data
     * @return mixed[]
     */
    private function envelope(string $event, ?int $storeId, array $data): array
    {
        return [
            'event' => $event,
            'version' => self::VERSION,
            'sent_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'store' => $this->storeInfo($storeId),
        ] + $data;
    }

    /**
     * The `store` block.
     *
     * @param int|null $storeId
     * @return array<string, string|null>
     */
    private function storeInfo(?int $storeId): array
    {
        try {
            $store = $storeId ? $this->storeManager->getStore($storeId) : $this->storeManager->getDefaultStoreView();
            if (!$store instanceof Store) {
                return ['website' => null, 'store_view' => null, 'url' => null];
            }

            return [
                'website' => $store->getWebsite()->getName(),
                'store_view' => $store->getName(),
                'url' => $store->getBaseUrl(UrlInterface::URL_TYPE_WEB),
            ];
        } catch (\Throwable) {
            return ['website' => null, 'store_view' => null, 'url' => null];
        }
    }

    /**
     * Where the event came from: frontend, admin, api, or null (cron, CLI, unknown).
     *
     * @return string|null
     */
    private function source(): ?string
    {
        try {
            $area = $this->appState->getAreaCode();
        } catch (\Throwable) {
            return null;
        }

        return match ($area) {
            Area::AREA_FRONTEND => 'frontend',
            Area::AREA_ADMINHTML => 'admin',
            // Luma's checkout places orders through REST from the storefront's own JavaScript,
            // which marks the call as XMLHttpRequest. Headless clients don't.
            Area::AREA_WEBAPI_REST => $this->isStorefrontAjax() ? 'frontend' : 'api',
            Area::AREA_WEBAPI_SOAP, Area::AREA_GRAPHQL => 'api',
            default => null,
        };
    }

    /**
     * Whether the current request is an AJAX call from a browser (jQuery sets this header).
     *
     * @return bool
     */
    private function isStorefrontAjax(): bool
    {
        return $this->request instanceof HttpRequest
            && $this->request->getHeader('X-Requested-With') === 'XMLHttpRequest';
    }

    /**
     * An admin link without secret key.
     *
     * With "Add Secret Key to URLs" on (Magento's default) it lands on the dashboard; that is
     * documented, not worked around.
     *
     * @param string $route
     * @param mixed[] $params
     * @return string|null
     */
    private function adminUrl(string $route, array $params = []): ?string
    {
        try {
            return $this->backendUrl->getUrl($route, $params + ['_nosecret' => true]);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Money as a decimal string, e.g. "49.00".
     *
     * @param float|string|null $amount
     * @return string|null
     */
    private function money(float|string|null $amount): ?string
    {
        return $amount === null ? null : number_format((float)$amount, 2, '.', '');
    }

    /**
     * Customer name on the order, falling back to the billing address (guests).
     *
     * @param Order $order
     * @return string|null
     */
    private function orderCustomerName(Order $order): ?string
    {
        $name = trim($order->getCustomerFirstname() . ' ' . $order->getCustomerLastname());
        if ($name === '' && $order->getBillingAddress()) {
            $billing = $order->getBillingAddress();
            $name = trim($billing->getFirstname() . ' ' . $billing->getLastname());
        }

        return $name !== '' ? $name : null;
    }

    /**
     * The payment method title the customer saw, not its code.
     *
     * @param Order $order
     * @return string|null
     */
    private function paymentTitle(Order $order): ?string
    {
        $payment = $order->getPayment();
        if (!$payment instanceof Payment) {
            return null;
        }

        $title = $payment->getAdditionalInformation('method_title');
        if (is_string($title) && $title !== '') {
            return $title;
        }

        try {
            return $payment->getMethodInstance()->getTitle() ?: $payment->getMethod();
        } catch (\Throwable) {
            return $payment->getMethod();
        }
    }

    /**
     * Customer group name, e.g. "General".
     *
     * @param int $groupId
     * @return string|null
     */
    private function customerGroupName(int $groupId): ?string
    {
        try {
            return $this->groupRepository->getById($groupId)->getCode();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * REMOTE_ADDR. Behind a proxy or CDN this is the proxy's address.
     *
     * @return string|null
     */
    private function remoteIp(): ?string
    {
        $ip = $this->remoteAddress->getRemoteAddress();

        return is_string($ip) && $ip !== '' ? $ip : null;
    }
}
