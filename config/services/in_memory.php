<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Budget\InMemorySpendLedger;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Memory\InMemoryMemoryStore;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;
use Odiseo\AiConversationalAgentBundle\Session\InMemorySessionStore;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

// The stores in memory, loaded when `orm.enabled` is off: nothing outlives the process.
return static function (ContainerConfigurator $container): void {
    $id = 'odiseo_ai_conversational_agent.';
    $services = $container->services();

    $services->set($id.'store.session.in_memory', InMemorySessionStore::class);
    $services->alias(SessionStore::class, $id.'store.session.in_memory');
    $services->set($id.'store.memory.in_memory', InMemoryMemoryStore::class);
    $services->alias(MemoryStore::class, $id.'store.memory.in_memory');
    $services->set($id.'store.spend.in_memory', InMemorySpendLedger::class);
    $services->alias(SpendLedger::class, $id.'store.spend.in_memory');
};
