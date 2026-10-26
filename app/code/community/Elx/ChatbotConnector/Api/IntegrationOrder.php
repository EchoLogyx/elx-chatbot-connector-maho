<?php

declare(strict_types=1);

namespace Elx\ChatbotConnector\Api;

use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\Get;
use Maho\Config\ApiResource;

#[ApiResource(
    mahoId: 'elx-chatbot-orders',
    mahoLabel: 'ELX Chatbot Orders',
    mahoSection: 'ELX Chatbot Connector',
    mahoOperations: ['read' => 'Order API Access'],
    shortName: 'ElxChatbotOrder',
    description: 'Look up store orders for ELX Chatbot by increment id or customer email.',
    operations: [
        new Get(
            uriTemplate: '/integration/order',
            provider: IntegrationOrderProvider::class,
            security: "is_granted('ROLE_ADMIN') or is_granted('elx-chatbot-orders/read')",
            description: 'Get one order by order_id (increment id), or every order for an email.',
        ),
    ],
)]
class IntegrationOrder extends \Maho\ApiPlatform\Resource
{
    #[ApiProperty(identifier: true)]
    public string $id = 'integration-order';
}
