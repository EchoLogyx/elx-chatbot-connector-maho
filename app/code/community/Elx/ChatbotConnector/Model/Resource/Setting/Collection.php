<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Resource_Setting_Collection extends Mage_Core_Model_Resource_Db_Collection_Abstract
{
    protected function _construct(): void
    {
        $this->_init('elx_chatbotconnector/setting');
    }
}
