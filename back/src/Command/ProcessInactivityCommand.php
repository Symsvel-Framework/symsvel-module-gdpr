<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use SymSvel\Module\Gdpr\Retention\InactivityProcessor;

#[AsCommand(name: 'symsvel:gdpr:process', description: 'Inspect or explicitly process inactive App accounts.')]
final class ProcessInactivityCommand extends Command
{
    public function __construct(
        private readonly InactivityProcessor $processor,
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Send notices and treat eligible accounts.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Inspect only (default).')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Number of accounts per batch, 1 to 1000.', '100');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $execute = $input->getOption('execute') === true;
        $size = $input->getOption('batch-size');
        if (($execute && $input->getOption('dry-run')) || !is_string($size) || !preg_match('/^[1-9][0-9]{0,3}$/D', $size) || (int) $size > 1000) {
            $output->writeln('<error>Use either --dry-run or --execute and a valid batch size.</error>');
            return Command::INVALID;
        }
        $lock = null;
        try {
            if ($execute) {
                $directory = $this->projectDir.'/var/lock';
                if (!is_dir($directory) && !@mkdir($directory, 0770, true) && !is_dir($directory)) {
                    throw new \RuntimeException('Cannot create retention lock directory.');
                }
                $lock = @fopen($directory.'/symsvel-gdpr-process.lock', 'c');
                if (!is_resource($lock) || !flock($lock, LOCK_EX | LOCK_NB)) {
                    $output->writeln('<error>Account retention already running or lock unavailable.</error>');
                    return Command::FAILURE;
                }
            }
            $output->writeln($execute ? 'Execution mode.' : 'Dry run (no messages or account changes).');
            $report = $this->processor->run(new \DateTimeImmutable('now', new \DateTimeZone('UTC')), $execute, (int) $size,
                static function (int $id, string $type) use ($output): void {
                    $output->writeln(sprintf('<error>Account #%d: %s</error>', $id, $type));
                });
            $output->writeln('Status: '.$report['status']);
            foreach ($report['counts'] as $name => $count) $output->writeln($name.': '.$count);
            return $report['counts']['errors'] > 0 || ($execute && $report['counts']['unconfigured'] > 0)
                ? Command::FAILURE : Command::SUCCESS;
        } catch (\Throwable $failure) {
            $output->writeln('<error>Retention pass failed: '.$failure::class.'</error>');
            return Command::FAILURE;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }
}
