<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine;

use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ConversationInterface;

/**
 * The host's say over a conversation the store has just built, before it is persisted. The core
 * sets the session id, the principal and the state document; whatever else the host's schema
 * relates a conversation to (a customer, a channel) it sets here, where it still knows who is
 * asking. Nothing the host sets is read back by the core.
 */
interface ConversationInitializer
{
    public function initialize(ConversationInterface $conversation): void;
}
