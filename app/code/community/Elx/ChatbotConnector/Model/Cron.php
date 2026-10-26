<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Model_Cron
{
    #[Maho\Config\CronJob('elx_chatbot_feed_generate', configPath: 'elx_chatbotconnector/feed/cron_expr')]
    public function generateFeed(): void
    {
        try {
            Mage::getModel('elx_chatbotconnector/feed')->generate();
        } catch (Throwable $exception) {
            Mage::logException($exception);
        }
    }

    #[Maho\Config\CronJob('elx_chatbot_order_token', schedule: '0 3 * * *')]
    public function refreshOrderToken(): void
    {
        try {
            Mage::getModel('elx_chatbotconnector/orderAccess')->syncToLaravel();
        } catch (Throwable $exception) {
            Mage::logException($exception);
        }
    }
}
