<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\MemoryFact;

#[ORM\Entity]
#[ORM\Table(name: 'test_agent_memory_fact')]
class TestMemoryFact extends MemoryFact
{
}
