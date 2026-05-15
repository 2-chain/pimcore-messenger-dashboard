<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportRegistry;

#[AsCommand(
    name: 'twochain:messenger-dashboard:debug:transports',
    description: 'List configured Symfony Messenger transports as seen by the dashboard, with their adapter type, capabilities, and current pending count.',
)]
final class ListTransportsCommand extends Command
{
    public function __construct(private readonly TransportRegistry $registry)
    {
        parent::__construct();
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        new SymfonyStyle($input, $output);

        $table = new Table($output);
        $table->setHeaders(['Transport', 'Type', 'Count', 'list', 'inspect', 'delete', 'bulk', 'purge', 'requeue']);

        foreach ($this->registry->adapters() as $adapter) {
            $caps = $adapter->capabilities();
            try {
                $count = (string) $adapter->count();
            } catch (\Throwable $e) {
                $count = '<error>err: ' . $e->getMessage() . '</error>';
            }
            $table->addRow([
                $adapter->name(),
                $adapter->type(),
                $count,
                $caps->canList ? '✓' : '·',
                $caps->canInspectIndividual ? '✓' : '·',
                $caps->canDeleteIndividual ? '✓' : '·',
                $caps->canBulkDelete ? '✓' : '·',
                $caps->canPurge ? '✓' : '·',
                $caps->canRequeue ? '✓' : '·',
            ]);
        }

        $table->render();

        return Command::SUCCESS;
    }
}
