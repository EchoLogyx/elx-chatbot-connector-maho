<?php

declare(strict_types=1);

/**
 * Creates the Maho API user ELX uses for order lookup.
 * The merchant never sees the client id or client secret.
 */
class Elx_ChatbotConnector_Model_OrderAccess
{
    private const ROLE_NAME = 'ELX Chatbot';
    private const USERNAME = 'elx-chatbot';
    private const PERMISSION = 'elx-chatbot-orders/read';
    private const TOKEN_LIFETIME = '2592000';

    /**
     * Mint a new order token and store it on the Laravel app.
     * No-op when the store has not connected an ELX secret.
     */
    public function syncToLaravel(): void
    {
        $helper = Mage::helper('elx_chatbotconnector');
        $secretKey = $helper->settings()->getValue(Elx_ChatbotConnector_Helper_Data::PATH_SECRET_KEY);
        if ($secretKey === '') {
            return;
        }

        $credentials = $this->provision();
        /** @var Elx_ChatbotConnector_Model_Client $client */
        $client = Mage::getModel('elx_chatbotconnector/client');
        $store = $helper->getStore();
        $baseUrl = rtrim($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), '/');
        $tokenResponse = $client->mahoAccessToken(
            $baseUrl . '/api/rest/v2/auth/token',
            $credentials['client_id'],
            $credentials['client_secret'],
        );
        $token = $tokenResponse['json']['token'] ?? $tokenResponse['json']['access_token'] ?? null;
        if (!is_string($token) || $token === '' || $tokenResponse['status'] < 200 || $tokenResponse['status'] >= 300) {
            throw new RuntimeException($this->responseMessage(
                $tokenResponse['json'],
                'Could not create the order token. HTTP Status: ' . $tokenResponse['status'],
            ));
        }

        $primaryDomain = $baseUrl . '/';
        $host = parse_url($primaryDomain, PHP_URL_HOST);
        $remote = $client->register([
            'name' => (string) $store->getName(),
            'primary_domain' => $primaryDomain,
            'store_domain' => is_string($host) && $host !== '' ? $host : $primaryDomain,
            'store_type' => 'maho',
            'currency' => (string) $store->getCurrentCurrencyCode(),
            'status' => true,
            'secret_key' => $secretKey,
            'access_token' => $token,
        ]);
        if ($remote['status'] < 200 || $remote['status'] >= 300) {
            throw new RuntimeException($this->responseMessage(
                $remote['json'],
                'Could not save the order token. HTTP Status: ' . $remote['status'],
            ));
        }

        $helper->settings()->saveValue(Elx_ChatbotConnector_Helper_Data::PATH_ACCESS_TOKEN_SAVED, '1');
    }

    /**
     * @param array<string, mixed> $json
     */
    private function responseMessage(array $json, string $fallback): string
    {
        $message = $json['message'] ?? $json['error'] ?? $json['errors'] ?? null;
        if (is_string($message) && $message !== '') {
            return $message;
        }
        if (is_array($message)) {
            $parts = [];
            array_walk_recursive($message, static function ($item) use (&$parts): void {
                if (is_scalar($item)) {
                    $parts[] = (string) $item;
                }
            });
            if ($parts !== []) {
                return implode(' ', $parts);
            }
        }

        return $fallback;
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    public function provision(): array
    {
        $this->enableOrderApi();

        $resource = Mage::getSingleton('core/resource');
        $write = $resource->getConnection('core_write');
        $roleTable = $resource->getTableName('api/role');
        $ruleTable = $resource->getTableName('api/rule');
        $userTable = $resource->getTableName('api/user');

        $write->beginTransaction();
        try {
            $roleId = $this->ensureRole($write, $roleTable, $ruleTable);
            $userId = $this->ensureUser($roleId, $roleTable, $write);
            $credentials = $this->rotateClientCredentials($write, $userTable, $userId);
            $write->commit();
        } catch (Throwable $exception) {
            $write->rollBack();
            throw $exception;
        }

        return $credentials;
    }

    private function enableOrderApi(): void
    {
        $config = Mage::getConfig();
        $changed = false;

        if ((string) Mage::getStoreConfig('apiplatform/protocols/rest_v2') !== '1') {
            $config->saveConfig('apiplatform/protocols/rest_v2', '1');
            $changed = true;
        }

        $lifetime = (int) Mage::getStoreConfig('apiplatform/oauth2/token_lifetime');
        if ($lifetime < (int) self::TOKEN_LIFETIME) {
            $config->saveConfig('apiplatform/oauth2/token_lifetime', self::TOKEN_LIFETIME);
            $changed = true;
        }

        if ($changed) {
            $config->cleanCache();
        }
    }

    private function ensureRole(Varien_Db_Adapter_Interface $write, string $roleTable, string $ruleTable): int
    {
        $roleId = $write->fetchOne(
            $write->select()
                ->from($roleTable, ['role_id'])
                ->where('role_type = ?', 'G')
                ->where('role_name = ?', self::ROLE_NAME)
                ->limit(1),
        );

        if (!$roleId) {
            $write->insert($roleTable, [
                'parent_id' => 0,
                'tree_level' => 1,
                'sort_order' => 0,
                'role_type' => 'G',
                'user_id' => 0,
                'role_name' => self::ROLE_NAME,
            ]);
            $roleId = (int) $write->lastInsertId($roleTable);
        } else {
            $roleId = (int) $roleId;
        }

        $hasPermission = $write->fetchOne(
            $write->select()
                ->from($ruleTable, ['role_id'])
                ->where('role_id = ?', $roleId)
                ->where('role_type = ?', 'G')
                ->where('resource_id = ?', self::PERMISSION)
                ->where('api_permission = ?', 'allow')
                ->limit(1),
        );
        if (!$hasPermission) {
            $write->insert($ruleTable, [
                'role_id' => $roleId,
                'resource_id' => self::PERMISSION,
                'api_privileges' => null,
                'assert_id' => 0,
                'role_type' => 'G',
                'api_permission' => 'allow',
            ]);
        }

        return $roleId;
    }

    private function ensureUser(int $roleId, string $roleTable, Varien_Db_Adapter_Interface $write): int
    {
        /** @var Mage_Api_Model_User $user */
        $user = Mage::getModel('api/user')->loadByUsername(self::USERNAME);
        if (!$user->getId()) {
            $user->setUsername(self::USERNAME);
            $user->setFirstname('ELX');
            $user->setLastname('Chatbot');
            $user->setEmail($this->serviceEmail());
            $user->setIsActive(1);
            $user->setNewApiKey(bin2hex(random_bytes(16)));
            $user->save();
        } elseif (!(int) $user->getIsActive()) {
            $user->setIsActive(1);
            $user->save();
        }

        $userId = (int) $user->getId();
        $write->delete($roleTable, [
            'user_id = ?' => $userId,
            'role_type = ?' => 'U',
        ]);
        $write->insert($roleTable, [
            'parent_id' => $roleId,
            'tree_level' => 2,
            'sort_order' => 0,
            'role_type' => 'U',
            'user_id' => $userId,
            'role_name' => self::USERNAME,
        ]);

        return $userId;
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    private function rotateClientCredentials(
        Varien_Db_Adapter_Interface $write,
        string $userTable,
        int $userId,
    ): array {
        $clientId = 'maho_' . bin2hex(random_bytes(16));
        $clientSecret = bin2hex(random_bytes(32));
        $write->update($userTable, [
            'client_id' => $clientId,
            'client_secret' => password_hash($clientSecret, PASSWORD_BCRYPT),
        ], ['user_id = ?' => $userId]);

        return [
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];
    }

    private function serviceEmail(): string
    {
        $store = Mage::helper('elx_chatbotconnector')->getStore();
        $host = parse_url($store->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_WEB), PHP_URL_HOST);

        return 'elx-chatbot@' . (is_string($host) && $host !== '' ? $host : 'localhost');
    }
}
