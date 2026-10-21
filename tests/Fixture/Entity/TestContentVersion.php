<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity;

use Doctrine\ORM\Mapping as ORM;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model\ContentVersion;

#[ORM\Entity]
#[ORM\Table(name: 'test_agent_content_version')]
class TestContentVersion extends ContentVersion
{
}
