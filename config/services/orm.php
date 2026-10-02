<?php

declare(strict_types=1);

use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\ConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\NullConversationInitializer;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmMemoryStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSessionStore;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\Store\OrmSpendLedger;
use Odiseo\AiConversationalAgentBundle\Bridge\Doctrine\TransactionalEvalIsolation;
use Odiseo\AiConversationalAgentBundle\Bridge\Symfony\Command\PruneCommand;
use Odiseo\AiConversationalAgentBundle\Budget\SpendLedger;
use Odiseo\AiConversationalAgentBundle\Eval\EvalIsolation;
use Odiseo\AiConversationalAgentBundle\Memory\MemoryStore;
use Odiseo\AiConversationalAgentBundle\Session\SessionStore;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

use function Symfony\Component\DependencyInjection\Loader\Configurator\param;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

// The stores over Doctrine ORM, loaded when `orm.enabled` is on.
return static function (ContainerConfigurator $container): void {
    $id = 'odiseo_ai_conversational_agent.';
    $services = $container->services();

    // The host fills in what its own schema relates a conversation to by pointing this alias at
    // its own implementation.
    $services->set($id.'conversation_initializer.null', NullConversationInitializer::class);
    $services->alias(ConversationInitializer::class, $id.'conversation_initializer.null');

    $services->set($id.'store.session.orm', OrmSessionStore::class)->args([
        service('doctrine.orm.entity_manager'),
        param($id.'orm.classes.conversation'),
        param($id.'orm.classes.message'),
        param($id.'sessions.retention_days'),
        service(ConversationInitializer::class),
    ]);
    $services->alias(SessionStore::class, $id.'store.session.orm');

    $services->set($id.'store.memory.orm', OrmMemoryStore::class)
        ->args([service('doctrine.orm.entity_manager'), param($id.'orm.classes.memory_fact')]);
    $services->alias(MemoryStore::class, $id.'store.memory.orm');

    $services->set($id.'store.spend.orm', OrmSpendLedger::class)
        ->args([service('doctrine.orm.entity_manager'), param($id.'orm.classes.spend_entry')]);
    $services->alias(SpendLedger::class, $id.'store.spend.orm');

    $services->set($id.'eval.isolation.transactional', TransactionalEvalIsolation::class)
        ->args([service('doctrine.orm.entity_manager')]);
    $services->alias(EvalIsolation::class, $id.'eval.isolation.transactional');

    $services->set($id.'command.prune', PruneCommand::class)
        ->args([
            service($id.'store.session.orm'),
            service($id.'store.spend.orm'),
            service($id.'store.memory.orm'),
            param($id.'conversations.retention_days'),
            param($id.'memory.retention_days'),
        ])
        ->tag('console.command');
};
