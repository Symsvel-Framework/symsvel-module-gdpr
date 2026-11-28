<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use SymSvel\Module\Gdpr\Consent\ConsentCreationLimiter;
use SymSvel\Module\Gdpr\Consent\ConsentRepository;

#[AsCommand(name: 'symsvel:gdpr:cleanup-consents', description: 'Remove expired consent choices and creation quotas.')]
final class CleanupConsentsCommand extends Command
{
    public function __construct(private readonly ConsentRepository $consents, private readonly ConsentCreationLimiter $limiter)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $output->writeln(sprintf('Expired consents removed: %d; creation quotas removed: %d.',
            $this->consents->removeExpired($now), $this->limiter->removeExpired($now)));

        return Command::SUCCESS;
    }
}
