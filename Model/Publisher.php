<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Model;

use Magento\Framework\MessageQueue\PublisherInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Hands a payload to the message queue, or sends it inline when "Send immediately" is on.
 *
 * Called from observers, so it never throws: JustPush must not break checkout.
 */
class Publisher
{
    public const TOPIC = 'justpush.notify';
    public const INLINE_TIMEOUT = 2;

    /**
     * @param Config $config
     * @param PublisherInterface $queuePublisher
     * @param Sender $sender
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Config $config,
        private readonly PublisherInterface $queuePublisher,
        private readonly Sender $sender,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Whether an event would be sent for this website. Lets observers skip building a payload.
     *
     * @param string $event
     * @param int|null $websiteId Null for events without a website (admin logins)
     * @return bool
     */
    public function isActive(string $event, ?int $websiteId): bool
    {
        try {
            return $this->config->isEnabled($websiteId) && $this->config->getWebhookUrl($event, $websiteId) !== null;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Reading JustPush config for %s failed: %s', $event, $e->getMessage()));

            return false;
        }
    }

    /**
     * Sends the body to the webhook URL configured for its event, if any.
     *
     * @param mixed[] $body A body from PayloadBuilder
     * @param int|null $websiteId Null for events without a website (admin logins)
     * @return void
     */
    public function publish(array $body, ?int $websiteId): void
    {
        $event = (string)($body['event'] ?? '');

        try {
            if (!$this->config->isEnabled($websiteId)) {
                return;
            }
            $url = $this->config->getWebhookUrl($event, $websiteId);
            if ($url === null) {
                return;
            }

            if ($this->config->sendImmediately($websiteId)) {
                $this->sender->send($url, $body, self::INLINE_TIMEOUT);

                return;
            }

            $this->queuePublisher->publish(self::TOPIC, $this->json->serialize(['url' => $url, 'body' => $body]));
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Queueing %s failed: %s', $event, $e->getMessage()));
        }
    }
}
