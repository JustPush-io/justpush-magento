<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Model;

use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * Consumer of the `justpush.notify` queue. Started by Magento's consumers_runner cron, or by
 * hand with `bin/magento queue:consumers:start justpush.notify`.
 *
 * A failed POST is logged and dropped rather than retried, so a JustPush outage can't pile
 * up messages in the queue.
 */
class Consumer
{
    public const TIMEOUT = 5;

    /**
     * @param Sender $sender
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly Sender $sender,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Send one queued message.
     *
     * @param string $message JSON: {"url": "...", "body": {...}}
     * @return void
     */
    public function process(string $message): void
    {
        try {
            $data = $this->json->unserialize($message);
            if (!is_array($data) || empty($data['url']) || !is_array($data['body'] ?? null)) {
                $this->logger->warning('Skipping malformed justpush.notify message', ['message' => $message]);

                return;
            }

            $this->sender->send((string)$data['url'], $data['body'], self::TIMEOUT);
        } catch (\Throwable $e) {
            $this->logger->error('Processing justpush.notify message failed: ' . $e->getMessage());
        }
    }
}
