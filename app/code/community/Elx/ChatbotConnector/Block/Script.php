<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Block_Script extends Mage_Core_Block_Template
{
    public function getScriptUrl(): string
    {
        /** @var Elx_ChatbotConnector_Helper_Data $helper */
        $helper = $this->helper('elx_chatbotconnector');
        $settings = $helper->settings();
        $botId = $settings->getValue(Elx_ChatbotConnector_Helper_Data::PATH_BOT_ID);
        if ($botId === '') {
            return '';
        }

        $configured = $settings->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SCRIPT_URL);
        if ($configured !== '') {
            return $configured;
        }

        return Elx_ChatbotConnector_Helper_Data::SCRIPT_URL . '?botid=' . rawurlencode($botId);
    }

    protected function _toHtml(): string
    {
        if ($this->getScriptUrl() === '') {
            return '';
        }

        return parent::_toHtml();
    }
}
