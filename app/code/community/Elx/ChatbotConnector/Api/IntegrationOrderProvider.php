<?php

declare(strict_types=1);

namespace Elx\ChatbotConnector\Api;

use ApiPlatform\Metadata\Operation;
use Symfony\Component\HttpFoundation\RequestStack;

final class IntegrationOrderProvider extends \Maho\ApiPlatform\Provider
{
    public function __construct(
        private readonly RequestStack $requestStack,
        ?\Symfony\Bundle\SecurityBundle\Security $security = null,
    ) {
        parent::__construct($security);
    }

    #[\Override]
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): object|array|null
    {
        $request = $this->requestStack->getCurrentRequest();
        $orderId = trim((string) $request?->query->get('order_id', ''));
        $email = trim((string) $request?->query->get('email', ''));

        /** @var \Elx_ChatbotConnector_Model_Order $orders */
        $orders = \Mage::getModel('elx_chatbotconnector/order');

        return $this->respondRaw($orders->lookup($orderId, $email));
    }
}
