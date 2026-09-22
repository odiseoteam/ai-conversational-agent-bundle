<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Bridge\Doctrine;

use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ConversationInterface;

/** The default: a conversation is the principal string and nothing else. */
final class NullConversationInitializer implements ConversationInitializer
{
    public function initialize(ConversationInterface $conversation): void
    {
    }
}
