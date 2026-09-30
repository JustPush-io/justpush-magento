<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Controller\Adminhtml\Test;

use JustPush\Notify\Model\Config;
use JustPush\Notify\Model\PayloadBuilder;
use JustPush\Notify\Model\Sender;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;

/**
 * "Send test" button: POSTs `test.ping` to every saved webhook URL, right away.
 *
 * Works whether or not the module is enabled, so the connection can be checked first.
 */
class Send extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'JustPush_Notify::config';

    /**
     * @param Context $context
     * @param JsonFactory $jsonFactory
     * @param Config $config
     * @param PayloadBuilder $payloadBuilder
     * @param Sender $sender
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        private readonly JsonFactory $jsonFactory,
        private readonly Config $config,
        private readonly PayloadBuilder $payloadBuilder,
        private readonly Sender $sender,
        private readonly StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
    }

    /**
     * @return JsonResult
     */
    public function execute(): JsonResult
    {
        $result = $this->jsonFactory->create();

        $websiteParam = $this->getRequest()->getParam('website');
        $websiteId = $websiteParam !== null && $websiteParam !== '' ? (int)$websiteParam : null;

        $storeId = null;
        if ($websiteId !== null) {
            try {
                $website = $this->storeManager->getWebsite($websiteId);
                $storeId = $website instanceof Website ? (int)$website->getDefaultStore()->getId() : null;
            } catch (\Throwable) {
                $storeId = null;
            }
        }

        $urls = $this->config->getAllWebhookUrls($websiteId);
        if ($urls === []) {
            return $result->setData([
                'success' => false,
                'message' => (string)__(
                    'No webhook URLs saved yet. Paste a URL, save the configuration, then click Send test.'
                ),
            ]);
        }

        $body = $this->payloadBuilder->testPing($storeId);
        $lines = [];
        $allOk = true;
        foreach (array_unique($urls) as $field => $url) {
            $status = $this->sender->send($url, $body, Sender::TEST_TIMEOUT);
            $ok = $status >= 200 && $status < 300;
            $allOk = $allOk && $ok;
            if ($ok) {
                $outcome = __('sent (HTTP %1)', $status);
            } elseif ($status) {
                $outcome = __('failed (HTTP %1)', $status);
            } else {
                $outcome = __('failed, see var/log/justpush.log');
            }
            $lines[] = sprintf('%s: %s', ucfirst(str_replace('_', ' ', $field)), $outcome);
        }

        return $result->setData([
            'success' => $allOk,
            'message' => implode("\n", $lines),
        ]);
    }
}
