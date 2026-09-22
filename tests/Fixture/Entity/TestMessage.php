<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ConversationInterface;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\Message;

#[ORM\Entity]
#[ORM\Table(name: 'test_agent_message')]
#[ORM\UniqueConstraint(name: 'uniq_test_agent_message_position', columns: ['conversation_id', 'position'])]
class TestMessage extends Message
{
    #[ORM\ManyToOne(targetEntity: TestConversation::class)]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    protected ?ConversationInterface $conversation = null;
}
