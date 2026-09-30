<?php
/**
 * Copyright © JustPush. MIT License.
 */
declare(strict_types=1);

namespace JustPush\Notify\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Renders the "Send test" button in Stores > Configuration > Services > JustPush.
 */
class TestButton extends Field
{
    /**
     * @var string
     */
    protected $_template = 'JustPush_Notify::system/config/test_button.phtml';

    /**
     * No "Use Default" / "Use Website" checkbox for a button.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        $element->unsetData('scope');
        $element->unsetData('can_use_website_value');
        $element->unsetData('can_use_default_value');

        return parent::render($element);
    }

    /**
     * Render the button through the template.
     *
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element)
    {
        $this->setData('html_id', $element->getHtmlId());

        return $this->_toHtml();
    }

    /**
     * URL of the Send test controller, for the website currently shown.
     *
     * @return string
     */
    public function getSendUrl(): string
    {
        $params = [];
        $website = $this->getRequest()->getParam('website');
        if ($website !== null && $website !== '') {
            $params['website'] = (int)$website;
        }

        return $this->getUrl('justpush/test/send', $params);
    }
}
