<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Block_Adminhtml_Index extends Mage_Adminhtml_Block_Template
{
    public function __construct()
    {
        parent::__construct();
        $this->setTemplate('elx/chatbotconnector/index.phtml');
    }

    public function isConnected(): bool
    {
        return $this->connectorHelper()->isConnected();
    }

    public function getDashboardUrl(): string
    {
        return Elx_ChatbotConnector_Helper_Data::DASHBOARD_URL;
    }

    public function getSaveUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/save');
    }

    public function getIntervalUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/interval');
    }

    public function getGenerateUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/generate');
    }

    public function getCredentialsUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/credentials');
    }

    public function getOrderAccessUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/orderaccess');
    }

    public function getLogoutUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/logout');
    }

    public function getSecretUrl(): string
    {
        return $this->getUrl('adminhtml/elxchatbot/secret');
    }

    public function hasAccessToken(): bool
    {
        return $this->connectorHelper()->settings()->getValue(
            Elx_ChatbotConnector_Helper_Data::PATH_ACCESS_TOKEN_SAVED,
        ) === '1';
    }

    public function getStoreName(): string
    {
        $name = (string) Mage::getStoreConfig('general/store_information/name');
        if ($name !== '') {
            return $name;
        }

        return (string) $this->connectorHelper()->getStore()->getName();
    }

    public function getOwnerName(): string
    {
        return (string) Mage::getStoreConfig('trans_email/ident_general/name');
    }

    public function getContactEmail(): string
    {
        return (string) Mage::getStoreConfig('trans_email/ident_general/email');
    }

    public function getDefaultCurrency(): string
    {
        return (string) Mage::getStoreConfig('currency/options/base');
    }

    public function isFeedAvailable(): bool
    {
        return is_file($this->connectorHelper()->getFeedFilePath());
    }

    public function getFeedUrl(): string
    {
        return $this->connectorHelper()->getFeedUrl();
    }

    public function getLastUpdated(): string
    {
        $path = $this->connectorHelper()->getFeedFilePath();
        if (!is_file($path)) {
            return (string) $this->__('Never Generated');
        }

        $diff = time() - (int) filemtime($path);
        if ($diff < 60) {
            return $diff . ' ' . $this->__('seconds ago');
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' ' . $this->__('minute(s) ago');
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' ' . $this->__('hour(s) ago');
        }

        return (int) floor($diff / 86400) . ' ' . $this->__('day(s) ago');
    }

    public function getUpdateInterval(): string
    {
        $value = $this->connectorHelper()->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_UPDATE_INTERVAL);

        return array_key_exists($value, $this->getUpdateIntervalOptions()) ? $value : 'daily';
    }

    /**
     * @return array<string, string>
     */
    public function getUpdateIntervalOptions(): array
    {
        return [
            'daily' => (string) $this->__('Daily'),
            'weekly' => (string) $this->__('Weekly'),
            'monthly' => (string) $this->__('Monthly'),
        ];
    }

    private function connectorHelper(): Elx_ChatbotConnector_Helper_Data
    {
        return Mage::helper('elx_chatbotconnector');
    }
}
