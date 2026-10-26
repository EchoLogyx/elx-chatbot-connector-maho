<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Setting extends Mage_Core_Model_Abstract
{
    protected function _construct(): void
    {
        $this->_init('elx_chatbotconnector/setting');
    }
}
