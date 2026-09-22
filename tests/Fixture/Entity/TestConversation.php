<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\Conversation;

#[ORM\Entity]
#[ORM\Table(name: 'test_agent_conversation')]
class TestConversation extends Conversation
{
    /** What a host adds of its own; here only so a ConversationInitializer has something to set. */
    #[ORM\Column(type: 'string', length: 64, nullable: true)]
    protected ?string $label = null;

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }
}
