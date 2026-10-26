<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Helper_Data extends Mage_Core_Helper_Abstract
{
    public const PATH_SECRET_KEY = 'elxchatbot/registration/secret_key';
    public const PATH_ACCESS_TOKEN_SAVED = 'elxchatbot/registration/access_token_saved';
    public const PATH_INSTALL_ID = 'elxchatbot/registration/install_id';
    public const PATH_BOT_ID = 'elxchatbot/registration/bot_id';
    public const PATH_SCRIPT_URL = 'elxchatbot/registration/script_url';
    public const PATH_UPDATE_INTERVAL = 'elxchatbot/feed/update_interval';
    public const PATH_CRON_EXPR = 'elx_chatbotconnector/feed/cron_expr';

    public const REGISTER_URL = 'https://laravel-plugin.elxchatbot.ai/api/v1/stores';
    public const FEED_PUSH_URL = 'https://laravel-plugin.elxchatbot.ai/api/v1/feed';
    public const SCRIPT_URL = 'https://script.elxchatbot.ai/chatBot.js';
    public const DASHBOARD_URL = 'https://app.elxchatbot.ai';
    public const FEED_FILENAME = 'feed.json';

    public function settings(): Elx_ChatbotConnector_Model_Settings
    {
        return Mage::getModel('elx_chatbotconnector/settings');
    }

    public function getStore(): Mage_Core_Model_Store
    {
        $store = Mage::app()->getDefaultStoreView();
        if ($store instanceof Mage_Core_Model_Store) {
            return $store;
        }

        return Mage::app()->getStore();
    }

    public function getFeedFilePath(): string
    {
        return Mage::getBaseDir() . DS . 'public' . DS . 'feeds' . DS . self::FEED_FILENAME;
    }

    public function getFeedUrl(): string
    {
        // The file is public/feeds/feed.json. Link URLs include the store code
        // (/default/...), and nginx denies .json files outside /feeds/.
        return rtrim($this->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), '/')
            . '/feeds/' . self::FEED_FILENAME;
    }

    public function isConnected(): bool
    {
        return $this->settings()->getValue(self::PATH_SECRET_KEY) !== '';
    }
}
