<?php

declare(strict_types=1);

namespace Odiseo\AiConversationalAgentBundle\Tests\Fixture;

use Doctrine\DBAL\DriverManager;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\Driver\AttributeDriver;
use Doctrine\ORM\Mapping\Driver\SimplifiedXmlDriver;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\Mapping\Driver\MappingDriverChain;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmMemoryStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSessionStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSpendLedger;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestConversation;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestMemoryFact;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestMessage;
use Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity\TestSpendEntry;

/**
 * An entity manager over SQLite in memory with the core's XML mappings and the test entities
 * that extend them, schema created: what the ORM stores need and nothing of Symfony.
 */
final class Orm
{
    public static function entityManager(): EntityManagerInterface
    {
        $config = ORMSetup::createConfiguration(isDevMode: true);
        $chain = new MappingDriverChain();
        $chain->addDriver(new SimplifiedXmlDriver([\dirname(__DIR__, 2).'/config/doctrine' => 'Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model']), 'Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Model');
        $chain->addDriver(new AttributeDriver([__DIR__.'/Entity']), 'Odiseo\AiConversationalAgentBundle\Tests\Fixture\Entity');
        $config->setMetadataDriverImpl($chain);

        $em = new EntityManager(DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true], $config), $config);
        (new SchemaTool($em))->createSchema($em->getMetadataFactory()->getAllMetadata());

        return $em;
    }

    public static function sessionStore(?EntityManagerInterface $em = null, int $retentionDays = 30): OrmSessionStore
    {
        return new OrmSessionStore($em ?? self::entityManager(), TestConversation::class, TestMessage::class, $retentionDays);
    }

    public static function memoryStore(?EntityManagerInterface $em = null): OrmMemoryStore
    {
        return new OrmMemoryStore($em ?? self::entityManager(), TestMemoryFact::class);
    }

    public static function spendLedger(?EntityManagerInterface $em = null): OrmSpendLedger
    {
        return new OrmSpendLedger($em ?? self::entityManager(), TestSpendEntry::class);
    }
}
