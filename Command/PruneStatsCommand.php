<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TwoChain\PimcoreMessengerDashboardBundle\Repository\StatsRecordRepository;

#[AsCommand(
    name: 'twochain:messenger-dashboard:stats:prune',
    description: 'Delete messenger_dashboard_stats rows older than the configured retention window.',
)]
final class PruneStatsCommand extends Command
{
    public function __construct(
        private readonly StatsRecordRepository $repository,
        private readonly int $defaultRetentionDays,
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this
            ->addOption(
                'retention-days',
                null,
                InputOption::VALUE_REQUIRED,
                'Override the configured retention window (in days).',
            )
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Show what would be deleted without modifying the database.',
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $retentionDays = (int) ($input->getOption('retention-days') ?? $this->defaultRetentionDays);
        if ($retentionDays < 1) {
            $io->error(sprintf('retention-days must be >= 1, got %d', $retentionDays));

            return Command::FAILURE;
        }

        $cutoff = new \DateTimeImmutable(sprintf('-%d days', $retentionDays));
        $io->writeln(sprintf('Pruning stats rows with handled_at < <info>%s</info>', $cutoff->format('Y-m-d H:i:s')));

        if ($input->getOption('dry-run')) {
            $count = $this->countOlderThan($cutoff);
            $io->success(sprintf('[dry-run] %d row(s) would be deleted.', $count));

            return Command::SUCCESS;
        }

        $deleted = $this->repository->prune($cutoff);
        $io->success(sprintf('Deleted %d row(s).', $deleted));

        return Command::SUCCESS;
    }

    private function countOlderThan(\DateTimeImmutable $cutoff): int
    {
        $conn = $this->repository->getEntityManager()->getConnection();

        return (int) $conn->fetchOne(
            'SELECT COUNT(*) FROM messenger_dashboard_stats WHERE handled_at < ?',
            [$cutoff->format('Y-m-d H:i:s')],
        );
    }
}
