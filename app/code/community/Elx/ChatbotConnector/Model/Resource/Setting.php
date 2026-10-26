<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Resource_Setting extends Mage_Core_Model_Resource_Db_Abstract
{
    protected function _construct(): void
    {
        $this->_init('elx_chatbotconnector/setting', 'setting_id');
    }
}
