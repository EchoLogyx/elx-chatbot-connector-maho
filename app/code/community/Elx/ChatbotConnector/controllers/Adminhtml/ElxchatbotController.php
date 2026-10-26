<?php

declare(strict_types=1);

class Elx_ChatbotConnector_Adminhtml_ElxchatbotController extends Mage_Adminhtml_Controller_Action
{
    public const ADMIN_RESOURCE = 'elx_chatbotconnector/chatbot';

    #[Maho\Config\Route('/admin/elxchatbot/index', methods: ['GET'])]
    public function indexAction(): void
    {
        $this->loadLayout();
        $this->_setActiveMenu('elx_chatbotconnector');
        $this->_title($this->__('ELX Chatbot Connector'));
        $this->_addContent($this->getLayout()->createBlock('elx_chatbotconnector/adminhtml_index'));
        $this->renderLayout();
    }

    #[Maho\Config\Route('/admin/elxchatbot/save', methods: ['POST'])]
    public function saveAction(): void
    {
        try {
            $secretKey = trim((string) $this->getRequest()->getParam('secret_key'));
            if ($secretKey === '') {
                $this->json(['success' => false, 'message' => $this->__('Secret Key is required.')]);
                return;
            }

            /** @var Elx_ChatbotConnector_Helper_Data $helper */
            $helper = Mage::helper('elx_chatbotconnector');
            $settings = $helper->settings();
            $installId = $settings->getValue(Elx_ChatbotConnector_Helper_Data::PATH_INSTALL_ID);
            if ($installId === '') {
                $installId = $this->generateUuid();
                $settings->saveValue(Elx_ChatbotConnector_Helper_Data::PATH_INSTALL_ID, $installId);
            }

            $store = $helper->getStore();
            $primaryDomain = $this->webBaseUrl($store);
            $payload = [
                'name' => (string) $store->getName(),
                'primary_domain' => $primaryDomain,
                'store_domain' => $this->extractDomain($primaryDomain),
                'store_type' => 'maho',
                'currency' => (string) $store->getCurrentCurrencyCode(),
                'store_hash' => $installId,
                'secret_key' => $secretKey,
            ];

            /** @var Elx_ChatbotConnector_Model_Client $client */
            $client = Mage::getModel('elx_chatbotconnector/client');
            $response = $client->register($payload);
            $data = $response['json'];
            $ok = ($response['status'] === 200 || $response['status'] === 201)
                && (!isset($data['success']) || $data['success'] === true);

            if (!$ok) {
                $this->json([
                    'success' => false,
                    'message' => $this->messageFromResponse(
                        $data['message'] ?? $data['error'] ?? $data['errors'] ?? null,
                        (string) $this->__('Invalid Secret Key. Please check and try again.'),
                    ),
                ]);
                return;
            }

            $remoteStore = $data['data']['store'] ?? null;
            if (is_array($remoteStore) && array_key_exists('secret_key', $remoteStore) && ($remoteStore['secret_key'] === null || $remoteStore['secret_key'] === '')) {
                $this->json([
                    'success' => false,
                    'message' => $this->__('The ELX server updated the connection but did not store the secret key. This store was not connected.'),
                ]);
                return;
            }

            $settings->saveValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY, $secretKey);
            $orderWarning = null;
            try {
                Mage::getModel('elx_chatbotconnector/orderAccess')->syncToLaravel();
            } catch (Throwable $exception) {
                Mage::logException($exception);
                $orderWarning = $exception->getMessage();
            }
            $botId = $data['data']['store']['bot_id'] ?? null;
            if (is_scalar($botId) && (string) $botId !== '') {
                $settings->saveValue(Elx_ChatbotConnector_Helper_Data::PATH_BOT_ID, (string) $botId);
                $settings->saveValue(
                    Elx_ChatbotConnector_Helper_Data::PATH_SCRIPT_URL,
                    Elx_ChatbotConnector_Helper_Data::SCRIPT_URL . '?botid=' . $botId,
                );
            }

            $message = $this->messageFromResponse(
                $data['message'] ?? null,
                (string) $this->__('Your store has been successfully connected to ELX Chatbot.'),
            );
            if ($orderWarning !== null) {
                $message .= ' ' . $this->__('Order lookup was not set up: %s', $orderWarning);
            }

            $this->json([
                'success' => true,
                'message' => $message,
            ]);
        } catch (Throwable $exception) {
            Mage::logException($exception);
            $this->json([
                'success' => false,
                'message' => $this->__('Connection error: %s', $exception->getMessage()),
            ]);
        }
    }

    #[Maho\Config\Route('/admin/elxchatbot/interval', methods: ['POST'])]
    public function intervalAction(): void
    {
        $interval = trim((string) $this->getRequest()->getParam('update_interval'));
        $map = [
            'daily' => '0 0 * * *',
            'weekly' => '0 0 * * 0',
            'monthly' => '0 0 1 * *',
        ];
        if (!isset($map[$interval])) {
            $this->json(['success' => false, 'message' => $this->__('Invalid interval value.')]);
            return;
        }

        /** @var Elx_ChatbotConnector_Helper_Data $helper */
        $helper = Mage::helper('elx_chatbotconnector');
        $helper->settings()->saveValue(Elx_ChatbotConnector_Helper_Data::PATH_UPDATE_INTERVAL, $interval);
        Mage::getConfig()->saveConfig(Elx_ChatbotConnector_Helper_Data::PATH_CRON_EXPR, $map[$interval]);
        Mage::getConfig()->cleanCache();

        $this->json(['success' => true, 'message' => $this->__('Update interval saved.')]);
    }

    #[Maho\Config\Route('/admin/elxchatbot/generate', methods: ['POST'])]
    public function generateAction(): void
    {
        try {
            Mage::getModel('elx_chatbotconnector/feed')->generate();
            $remote = $this->pushFeed();
            $remoteOk = ($remote['success'] ?? false) === true;
            $this->json([
                'success' => $remoteOk,
                'message' => $remoteOk
                    ? (string) $this->__('Feed generated successfully.')
                    : (string) ($remote['error'] ?? $this->__('The product feed was saved on this store, but ELX did not accept it. Your secret key is still connected.')),
                'remote' => $remote,
            ]);
        } catch (Throwable $exception) {
            Mage::logException($exception);
            $this->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    #[Maho\Config\Route('/admin/elxchatbot/orderaccess', methods: ['POST'])]
    public function orderAccessAction(): void
    {
        try {
            /** @var Elx_ChatbotConnector_Helper_Data $helper */
            $helper = Mage::helper('elx_chatbotconnector');
            $secretKey = $helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY);
            if ($secretKey === '') {
                $this->json([
                    'success' => false,
                    'message' => $this->__('Store is not connected. Please connect with your secret key first.'),
                ]);
                return;
            }

            Mage::getModel('elx_chatbotconnector/orderAccess')->syncToLaravel();
            $this->json(['success' => true, 'message' => $this->__('Order lookup is ready.')]);
            return;
        } catch (Throwable $exception) {
            Mage::logException($exception);
            $this->json(['success' => false, 'message' => $exception->getMessage()]);
        }
    }

    #[Maho\Config\Route('/admin/elxchatbot/credentials', methods: ['POST'])]
    public function credentialsAction(): void
    {
        try {
            $clientId = trim((string) $this->getRequest()->getParam('client_id'));
            $clientSecret = trim((string) $this->getRequest()->getParam('client_secret'));
            if ($clientId === '' || $clientSecret === '') {
                $this->json(['success' => false, 'message' => $this->__('Client ID and Client Secret are required.')]);
                return;
            }

            /** @var Elx_ChatbotConnector_Helper_Data $helper */
            $helper = Mage::helper('elx_chatbotconnector');
            $secretKey = $helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY);
            if ($secretKey === '') {
                $this->json([
                    'success' => false,
                    'message' => $this->__('Store is not connected. Please connect with your secret key first.'),
                ]);
                return;
            }

            /** @var Elx_ChatbotConnector_Model_Client $client */
            $client = Mage::getModel('elx_chatbotconnector/client');
            $accessToken = $this->exchangeClientCredentials($client, $clientId, $clientSecret);
            if ($accessToken === null) {
                return;
            }

            $response = $this->forwardStore($client, $secretKey, $accessToken);
            if ($response['status'] >= 200 && $response['status'] < 300) {
                $this->json(['success' => true, 'message' => $this->__('Access token forwarded successfully.')]);
                return;
            }

            $data = $response['json'];
            $this->json([
                'success' => false,
                'message' => $this->messageFromResponse(
                    $data['message'] ?? $data['error'] ?? $data['errors'] ?? null,
                    (string) $this->__('Failed to forward access token. HTTP Status: %s', $response['status']),
                ),
            ]);
        } catch (Throwable $exception) {
            Mage::logException($exception);
            $this->json(['success' => false, 'message' => $this->__('An unexpected error occurred.')]);
        }
    }

    #[Maho\Config\Route('/admin/elxchatbot/logout', methods: ['POST'])]
    public function logoutAction(): void
    {
        try {
            /** @var Elx_ChatbotConnector_Helper_Data $helper */
            $helper = Mage::helper('elx_chatbotconnector');
            $secretKey = $helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY);
            if ($secretKey === '') {
                $this->json([
                    'success' => false,
                    'message' => $this->__('Store is not connected. Please connect with your secret key first.'),
                ]);
                return;
            }

            /** @var Elx_ChatbotConnector_Model_Client $client */
            $client = Mage::getModel('elx_chatbotconnector/client');
            $response = $client->register($this->storePayload($secretKey, null, true));
            if ($response['status'] >= 200 && $response['status'] < 300) {
                $settings = $helper->settings();
                $settings->deleteValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY);
                $settings->deleteValue(Elx_ChatbotConnector_Helper_Data::PATH_BOT_ID);
                $settings->deleteValue(Elx_ChatbotConnector_Helper_Data::PATH_SCRIPT_URL);
                $settings->deleteValue(Elx_ChatbotConnector_Helper_Data::PATH_ACCESS_TOKEN_SAVED);
                $this->json([
                    'success' => true,
                    'message' => $this->__('ELX secret removed. Connect again with a different one.'),
                ]);
                return;
            }

            $data = $response['json'];
            $this->json([
                'success' => false,
                'message' => $this->messageFromResponse(
                    $data['message'] ?? $data['error'] ?? $data['errors'] ?? null,
                    (string) $this->__('Could not remove the access token. HTTP Status: %s', $response['status']),
                ),
            ]);
        } catch (Throwable $exception) {
            Mage::logException($exception);
            $this->json(['success' => false, 'message' => $this->__('An unexpected error occurred.')]);
        }
    }

    #[Maho\Config\Route('/admin/elxchatbot/secret', methods: ['POST'])]
    public function secretAction(): void
    {
        try {
            $secretKey = trim((string) $this->getRequest()->getParam('secret_key'));
            if ($secretKey === '') {
                $this->json(['success' => false, 'message' => $this->__('ELX secret is required.')]);
                return;
            }

            /** @var Elx_ChatbotConnector_Helper_Data $helper */
            $helper = Mage::helper('elx_chatbotconnector');
            if ($helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY) === '') {
                $this->json([
                    'success' => false,
                    'message' => $this->__('Store is not connected. Please connect with your secret key first.'),
                ]);
                return;
            }

            /** @var Elx_ChatbotConnector_Model_Client $client */
            $client = Mage::getModel('elx_chatbotconnector/client');
            $response = $client->register($this->storePayload($secretKey, null, false));
            $data = $response['json'];
            if ($response['status'] < 200 || $response['status'] >= 300) {
                $this->json([
                    'success' => false,
                    'message' => $this->messageFromResponse(
                        $data['message'] ?? $data['error'] ?? $data['errors'] ?? null,
                        (string) $this->__('Could not update the ELX secret. HTTP Status: %s', $response['status']),
                    ),
                ]);
                return;
            }

            $helper->settings()->saveValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY, $secretKey);
            $this->json(['success' => true, 'message' => $this->__('ELX secret updated.')]);
        } catch (Throwable $exception) {
            Mage::logException($exception);
            $this->json(['success' => false, 'message' => $this->__('An unexpected error occurred.')]);
        }
    }

    /**
     * @return array{status: int, body: string, json: array<string, mixed>}
     */
    private function forwardStore(
        Elx_ChatbotConnector_Model_Client $client,
        string $secretKey,
        ?string $accessToken,
    ): array {
        return $client->register($this->storePayload($secretKey, $accessToken, false));
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(string $secretKey, ?string $accessToken, bool $removeSecret): array
    {
        $store = Mage::helper('elx_chatbotconnector')->getStore();
        $primaryDomain = $this->webBaseUrl($store);
        $payload = [
            'name' => (string) $store->getName(),
            'primary_domain' => $primaryDomain,
            'store_domain' => $this->extractDomain($primaryDomain),
            'store_type' => 'maho',
            'currency' => (string) $store->getCurrentCurrencyCode(),
            'status' => true,
            'secret_key' => $secretKey,
        ];
        if ($accessToken !== null) {
            $payload['access_token'] = $accessToken;
        }
        if ($removeSecret) {
            $payload['remove_secret'] = true;
        }

        return $payload;
    }

    private function exchangeClientCredentials(
        Elx_ChatbotConnector_Model_Client $client,
        string $clientId,
        string $clientSecret,
    ): ?string {
        $credentialError = $this->apiUserCredentialError($clientId, $clientSecret);
        if ($credentialError !== null) {
            $this->json(['success' => false, 'message' => $credentialError]);
            return null;
        }

        $baseUrl = rtrim(
            Mage::helper('elx_chatbotconnector')->getStore()->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB),
            '/',
        );
        $response = $client->mahoAccessToken($baseUrl . '/api/rest/v2/auth/token', $clientId, $clientSecret);
        $token = $response['json']['token'] ?? $response['json']['access_token'] ?? null;
        if (is_string($token) && $token !== '' && $response['status'] >= 200 && $response['status'] < 300) {
            return $token;
        }

        $this->json([
            'success' => false,
            'message' => $this->messageFromResponse(
                $response['json']['message'] ?? $response['json']['error'] ?? null,
                (string) $this->__(
                    'Could not create an access token from those credentials. HTTP Status: %s',
                    $response['status'],
                ),
            ),
        ]);

        return null;
    }

    /**
     * REST v2 client_credentials reads api/user, not a REST (legacy) OAuth consumer.
     */
    private function apiUserCredentialError(string $clientId, string $clientSecret): ?string
    {
        $resource = Mage::getSingleton('core/resource');
        $read = $resource->getConnection('core_read');
        $row = $read->fetchRow(
            $read->select()
                ->from($resource->getTableName('api/user'), ['client_secret', 'is_active'])
                ->where('client_id = ?', $clientId),
        );

        if (!is_array($row)) {
            return (string) $this->__(
                'That Client ID is not a Maho API user. Open System → API → Users, allow ELX Chatbot Orders → Order API Access, save the user, and paste the Client ID and Client Secret shown once. A key from System → API → REST (legacy) → OAuth Consumers is rejected here.',
            );
        }

        $hash = (string) ($row['client_secret'] ?? '');
        if ($hash === '' || !password_verify($clientSecret, $hash)) {
            return (string) $this->__(
                'The Client Secret does not match that API user. Maho shows the secret only when the user is saved. Edit the user, regenerate the client credentials, save, and paste the new pair.',
            );
        }

        if (!(int) ($row['is_active'] ?? 0)) {
            return (string) $this->__('That API user is inactive. Open System → API → Users and set the account to active.');
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function pushFeed(): array
    {
        /** @var Elx_ChatbotConnector_Helper_Data $helper */
        $helper = Mage::helper('elx_chatbotconnector');
        $store = $helper->getStore();
        $payload = [
            'url' => $helper->getFeedUrl(),
            'urlkey' => $helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY),
            'primary_domain' => $this->webBaseUrl($store),
            'bot_id' => $helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_BOT_ID),
        ];

        $empty = array_keys(array_filter($payload, static fn ($value): bool => $value === ''));
        if ($empty !== []) {
            return [
                'success' => false,
                'status' => 0,
                'body' => '',
                'error' => (string) $this->__(
                    'Third-party feed endpoint is not fully configured. Empty fields: %s',
                    implode(', ', $empty),
                ),
            ];
        }

        /** @var Elx_ChatbotConnector_Model_Client $client */
        $client = Mage::getModel('elx_chatbotconnector/client');
        $response = $client->pushFeed($payload);
        $json = $response['json'];
        $accepted = $response['status'] >= 200
            && $response['status'] < 300
            && (!isset($json['success']) || $json['success'] === true);

        if (!$accepted) {
            return [
                'success' => false,
                'status' => $response['status'],
                'body' => $response['body'],
                'error' => $this->messageFromResponse(
                    $json['message'] ?? $json['details'] ?? $json['error'] ?? $json['errors'] ?? null,
                    (string) $this->__('The product feed was saved on this store, but ELX did not accept it. Your secret key is still connected.'),
                ),
            ];
        }

        return [
            'success' => true,
            'status' => $response['status'],
            'body' => $response['body'],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function json(array $payload): void
    {
        $this->getResponse()->setHeader('Content-Type', 'application/json');
        $this->getResponse()->setBody((string) Mage::helper('core')->jsonEncode($payload));
    }

    private function generateUuid(): string
    {
        $hex = bin2hex(random_bytes(16));
        $hex[12] = '4';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20),
        );
    }

    /**
     * Origin of the store, without a store code. Order lookup calls
     * /api/rest/v2 on that origin; a link URL such as /default/ is the storefront.
     */
    private function webBaseUrl(Mage_Core_Model_Store $store): string
    {
        return rtrim($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), '/') . '/';
    }

    private function extractDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : $url;
    }

    private function messageFromResponse(mixed $message, string $fallback): string
    {
        if (is_string($message) && $message !== '') {
            return $message;
        }
        if (is_array($message)) {
            $messages = [];
            array_walk_recursive($message, static function ($item) use (&$messages): void {
                if (is_scalar($item)) {
                    $messages[] = (string) $item;
                }
            });
            if ($messages !== []) {
                return implode(' ', $messages);
            }
        }

        return $fallback;
    }
}
