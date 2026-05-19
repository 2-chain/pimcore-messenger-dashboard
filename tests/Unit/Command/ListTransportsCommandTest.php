<?php
declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Tests\Unit\Command;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use TwoChain\PimcoreMessengerDashboardBundle\Command\ListTransportsCommand;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\Capabilities;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\MessageDescriptor;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\TransportRegistry;

final class ListTransportsCommandTest extends TestCase
{
    public function testRendersTableWithOneRowPerAdapter(): void
    {
        $registry = new StubRegistry([
            new FakeAdapter('queue_a', 'doctrine', 12, Capabilities::full()),
            new FakeAdapter('queue_b', 'amqp', 4, new Capabilities(canCount: true, canPurge: true)),
        ]);
        $tester = new CommandTester(new ListTransportsCommand($registry));

        $exit = $tester->execute([]);

        $this->assertSame(0, $exit);
        $display = $tester->getDisplay();
        $this->assertStringContainsString('queue_a', $display);
        $this->assertStringContainsString('doctrine', $display);
        $this->assertStringContainsString('12', $display);
        $this->assertStringContainsString('queue_b', $display);
        $this->assertStringContainsString('amqp', $display);
    }

    public function testReportsCountErrorsInline(): void
    {
        $broken = new FakeAdapter('broken', 'redis', 0, Capabilities::countOnly());
        $broken->countException = new \RuntimeException('Connection refused');
        $registry = new StubRegistry([$broken]);
        $tester = new CommandTester(new ListTransportsCommand($registry));

        $tester->execute([]);

        $this->assertStringContainsString('Connection refused', $tester->getDisplay());
    }

    public function testCapabilityFlagsRenderAsCheckmarks(): void
    {
        $adapter = new FakeAdapter('q', 'doctrine', 0, Capabilities::full());
        $tester = new CommandTester(new ListTransportsCommand(new StubRegistry([$adapter])));

        $tester->execute([]);

        // 6 capability columns rendered as ✓, with name/type/count to the left.
        $this->assertSame(6, substr_count($tester->getDisplay(), '✓'));
    }

    public function testCapabilityDotsForMissingCapabilities(): void
    {
        $adapter = new FakeAdapter('q', 'sync', 0, new Capabilities());
        $tester = new CommandTester(new ListTransportsCommand(new StubRegistry([$adapter])));

        $tester->execute([]);

        $this->assertSame(6, substr_count($tester->getDisplay(), '·'));
    }
}

final class StubRegistry extends TransportRegistry
{
    /** @param list<TransportAdapterInterface> $adapters */
    public function __construct(private readonly array $adapters)
    {
    }

    public function names(): array
    {
        return array_map(fn (TransportAdapterInterface $a): string => $a->name(), $this->adapters);
    }

    public function adapter(string $name): TransportAdapterInterface
    {
        foreach ($this->adapters as $a) {
            if ($a->name() === $name) {
                return $a;
            }
        }
        throw new \InvalidArgumentException('not found');
    }

    public function adapters(): iterable
    {
        yield from $this->adapters;
    }
}

final class FakeAdapter implements TransportAdapterInterface
{
    public ?\Throwable $countException = null;

    public function __construct(
        private readonly string $name,
        private readonly string $type,
        private readonly int $count,
        private readonly Capabilities $capabilities,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function capabilities(): Capabilities
    {
        return $this->capabilities;
    }

    public function count(): int
    {
        if ($this->countException !== null) {
            throw $this->countException;
        }

        return $this->count;
    }

    public function countListable(?string $query = null): int
    {
        return 0;
    }

    public function list(int $offset = 0, int $limit = 50, ?string $query = null): array
    {
        return [];
    }

    public function find(string $id): ?MessageDescriptor
    {
        return null;
    }

    public function findEnvelope(string $id): ?\Symfony\Component\Messenger\Envelope
    {
        return null;
    }

    public function deleteOne(string $id): bool
    {
        return false;
    }

    public function purge(): int
    {
        return 0;
    }
}
