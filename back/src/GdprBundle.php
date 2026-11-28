<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr;

use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use SymSvel\Module\Gdpr\Retention\AnonymizationRequirementRegistry;
use SymSvel\Module\Gdpr\Retention\InactivityPolicy;
use SymSvel\Module\Gdpr\Retention\InactivityCycle;
use SymSvel\Module\Gdpr\Mail\GdprMailTemplateProvider;
use SymSvel\Module\Gdpr\Mail\InactivityNoticeRenderer;
use SymSvel\Module\Gdpr\Retention\InactivityNoticeRepository;
use SymSvel\Module\Gdpr\Consent\ConsentPolicy;
use SymSvel\Module\Gdpr\Consent\ConsentRepository;
use SymSvel\Module\Gdpr\Consent\ConsentCatalogue;
use SymSvel\Module\Gdpr\Consent\ConsentSelection;
use SymSvel\Module\Gdpr\Consent\BrowserConsentToken;
use SymSvel\Module\Gdpr\Consent\TrustedConsentOrigin;
use SymSvel\Module\Gdpr\Consent\ConsentCreationLimiter;
use SymSvel\Module\Gdpr\Controller\ConsentController;
use SymSvel\Module\Gdpr\Command\CleanupConsentsCommand;
use SymSvel\Module\Gdpr\Retention\PurgePolicy;
use SymSvel\Module\Gdpr\Settings\GdprSettingDefinitionProvider;
use SymSvel\Module\Gdpr\Settings\GdprConfiguration;

final class GdprBundle extends AbstractBundle
{
    public function prependExtension(ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->extension('twig', ['paths' => [dirname(__DIR__).'/templates' => 'Gdpr']]);
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $builder->register(InactivityPolicy::class);
        $builder->register(InactivityCycle::class)->setAutowired(true);
        $builder->register(GdprMailTemplateProvider::class)->addTag('symsvel.mails.template_provider');
        $builder->register(InactivityNoticeRenderer::class)->setAutowired(true);
        $builder->register(InactivityNoticeRepository::class)->setAutowired(true);
        $builder->register(ConsentPolicy::class);
        $builder->register(ConsentRepository::class)->setAutowired(true);
        $builder->register(ConsentCatalogue::class)
            ->setArgument(0, new TaggedIteratorArgument('symsvel.gdpr.consent_purpose_provider'));
        $builder->register(ConsentSelection::class)->setAutowired(true);
        $builder->register(BrowserConsentToken::class)->setAutowired(true);
        $builder->register(ConsentCreationLimiter::class)->setAutowired(true);
        $builder->register(ConsentController::class)->setAutowired(true)->addTag('controller.service_arguments');
        $builder->register(CleanupConsentsCommand::class)->setAutowired(true)->addTag('console.command');
        $builder->register(TrustedConsentOrigin::class)->setArgument('$environment', '%kernel.environment%');
        $builder->register(PurgePolicy::class);
        $builder->register(GdprSettingDefinitionProvider::class)->setAutowired(true)->addTag('symsvel.settings.definition_provider');
        $builder->register(GdprConfiguration::class)->setAutowired(true);
        $builder->register(AnonymizationRequirementRegistry::class)
            ->setArgument(0, new TaggedIteratorArgument('symsvel.gdpr.anonymization_requirement'));
    }
}
