<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * Reads the Stores > Configuration > Services > JustPush settings.
 */
class Config
{
    public const XML_PATH_ENABLED = 'justpush/general/enabled';
    public const XML_PATH_SEND_IMMEDIATELY = 'justpush/general/send_immediately';
    public const XML_PATH_WEBHOOKS = 'justpush/webhooks/';

    /**
     * Payload event name => config field in the "webhooks" group.
     */
    public const EVENT_FIELDS = [
        'order.placed' => 'order_placed',
        'invoice.paid' => 'invoice_paid',
        'order.refunded' => 'order_refunded',
        'customer.registered' => 'customer_registered',
        'admin.login' => 'admin_login',
        'admin.login_failed' => 'admin_login',
    ];

    /**
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
    ) {
    }

    /**
     * Whether the module is switched on. A null website reads Default Config.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function isEnabled(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_ENABLED, ...$this->scope($websiteId));
    }

    /**
     * Whether to POST inline instead of through the message queue.
     *
     * @param int|null $websiteId
     * @return bool
     */
    public function sendImmediately(?int $websiteId = null): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_SEND_IMMEDIATELY, ...$this->scope($websiteId));
    }

    /**
     * The webhook URL for an event, or null when that event is turned off.
     *
     * @param string $event
     * @param int|null $websiteId
     * @return string|null
     */
    public function getWebhookUrl(string $event, ?int $websiteId = null): ?string
    {
        $field = self::EVENT_FIELDS[$event] ?? null;
        if ($field === null) {
            return null;
        }

        $value = $this->scopeConfig->getValue(self::XML_PATH_WEBHOOKS . $field, ...$this->scope($websiteId));
        $url = trim((string)$value);

        return $url === '' ? null : $url;
    }

    /**
     * All filled-in webhook URLs, keyed by config field, without duplicates.
     *
     * @param int|null $websiteId
     * @return array<string, string>
     */
    public function getAllWebhookUrls(?int $websiteId = null): array
    {
        $urls = [];
        foreach (array_unique(self::EVENT_FIELDS) as $event => $field) {
            $url = $this->getWebhookUrl($event, $websiteId);
            if ($url !== null) {
                $urls[$field] = $url;
            }
        }

        return $urls;
    }

    /**
     * Arguments for ScopeConfigInterface: website scope, or Default Config.
     *
     * @param int|null $websiteId
     * @return array{0: string, 1: int|null}
     */
    private function scope(?int $websiteId): array
    {
        return $websiteId === null
            ? [ScopeConfigInterface::SCOPE_TYPE_DEFAULT, null]
            : [ScopeInterface::SCOPE_WEBSITE, $websiteId];
    }
}
