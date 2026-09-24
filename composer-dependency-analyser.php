<?php

declare(strict_types=1);

use ShipMonk\ComposerDependencyAnalyser\Config\Configuration;
use ShipMonk\ComposerDependencyAnalyser\Config\ErrorType;

/**
 * Doctrine and Symfony AI are optional: the ORM stores and the Anthropic adapter are
 * only registered when the host installs them, so they are dev dependencies used in src/.
 */
return (new Configuration())
    ->ignoreErrorsOnPackages([
        'doctrine/dbal',
        'doctrine/doctrine-bundle',
        'doctrine/orm',
        'symfony/ai-platform',
    ], [ErrorType::DEV_DEPENDENCY_IN_PROD])
    // Defined next to ContainerConfigurator and loaded with it.
    ->ignoreUnknownFunctions([
        'Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\service',
        'Symfony\\Component\\DependencyInjection\\Loader\\Configurator\\tagged_iterator',
    ])
;
