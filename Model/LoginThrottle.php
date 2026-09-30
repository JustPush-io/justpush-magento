<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Model;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Limits `admin.login_failed` to one message per 15 minutes per username.
 *
 * Every failure is counted. When a message may go out, it carries the number of failures
 * since the previous message for that username, including the current one.
 */
class LoginThrottle
{
    public const WINDOW = 900;
    private const CACHE_PREFIX = 'justpush_login_failed_';
    private const CACHE_LIFETIME = 86400;

    /**
     * @param CacheInterface $cache
     * @param Json $json
     */
    public function __construct(
        private readonly CacheInterface $cache,
        private readonly Json $json
    ) {
    }

    /**
     * Records one failed login. Returns the attempts count to send, or null to stay quiet.
     *
     * @param string $username
     * @param int|null $now Unix time, for tests
     * @return int|null
     */
    public function registerFailure(string $username, ?int $now = null): ?int
    {
        $now ??= time();
        $key = self::CACHE_PREFIX . sha1(mb_strtolower($username));

        $state = ['last_sent' => null, 'pending' => 0];
        $cached = $this->cache->load($key);
        if (is_string($cached) && $cached !== '') {
            $decoded = $this->json->unserialize($cached);
            if (is_array($decoded)) {
                $state = array_merge($state, $decoded);
            }
        }

        $state['pending'] = (int)$state['pending'] + 1;

        $attempts = null;
        if ($state['last_sent'] === null || $now - (int)$state['last_sent'] >= self::WINDOW) {
            $attempts = $state['pending'];
            $state = ['last_sent' => $now, 'pending' => 0];
        }

        $this->cache->save($this->json->serialize($state), $key, [], self::CACHE_LIFETIME);

        return $attempts;
    }
}
