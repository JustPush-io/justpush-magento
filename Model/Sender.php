<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Model;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;

/**
 * POSTs one payload to one webhook URL. Never throws.
 */
class Sender
{
    public const USER_AGENT = 'JustPush-Magento/1.0.0';
    public const TEST_TIMEOUT = 5;

    /**
     * @param CurlFactory $curlFactory
     * @param Json $json
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Sends the body and returns the HTTP status, or 0 when the request failed.
     *
     * Fills in `sent_at` right before sending, so a queued message carries the time it
     * actually left the shop.
     *
     * @param string $url
     * @param mixed[] $body
     * @param int $timeout Total timeout in seconds
     * @return int
     */
    public function send(string $url, array $body, int $timeout = 5): int
    {
        $event = (string)($body['event'] ?? 'unknown');

        try {
            if (array_key_exists('sent_at', $body)) {
                $body['sent_at'] = gmdate('Y-m-d\TH:i:s\Z');
            }

            $curl = $this->curlFactory->create();
            $curl->setTimeout($timeout);
            $curl->setOptions([
                CURLOPT_CONNECTTIMEOUT => min($timeout, 2),
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $curl->setHeaders([
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => self::USER_AGENT,
            ]);
            $curl->post($url, $this->json->serialize($body));

            $status = $curl->getStatus();
            if ($status < 200 || $status >= 300) {
                $this->logger->warning(sprintf('%s to %s answered HTTP %d', $event, $url, $status), [
                    'response' => mb_substr($curl->getBody(), 0, 500),
                ]);
            }

            return $status;
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('%s to %s failed: %s', $event, $url, $e->getMessage()));

            return 0;
        }
    }
}
