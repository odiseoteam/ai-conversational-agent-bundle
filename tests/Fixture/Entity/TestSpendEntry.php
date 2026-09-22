<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\SpendEntry;

#[ORM\Entity]
#[ORM\Table(name: 'test_agent_spend_entry')]
class TestSpendEntry extends SpendEntry
{
}
