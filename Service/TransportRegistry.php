<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Service;

use Psr\Container\ContainerInterface;
use TwoChain\PimcoreMessengerDashboardBundle\Service\Adapter\TransportAdapterInterface;

/**
 * Enumerates configured Symfony Messenger transports and hands out adapters.
 *
 * The list of transport names comes from the messenger.receiver_locator's
 * provided services list. The framework bundle wires up one entry per
 * transport defined under framework.messenger.transports.*.
 */
class TransportRegistry
{
    /** @var list<string>|null */
    private ?array $names = null;

    public function __construct(
        private readonly ContainerInterface $receiverLocator,
        private readonly TransportAdapterFactory $factory,
    ) {
    }

    /** @return list<string> user-facing transport names, sorted alphabetically */
    public function names(): array
    {
        if ($this->names !== null) {
            return $this->names;
        }

        // The receiver_locator advertises both the user-facing transport
        // name (e.g. "pimcore_core") AND the internal service ID
        // ("messenger.transport.pimcore_core") as separate keys pointing
        // to the same receiver. We want the user-facing one, identifiable
        // by the absence of dots in the name.
        $names = array_values(array_filter(
            array_keys($this->receiverLocator->getProvidedServices()),
            fn (string $name): bool => !str_contains($name, '.'),
        ));
        sort($names);

        return $this->names = $names;
    }

    public function adapter(string $name): TransportAdapterInterface
    {
        return $this->factory->for($name);
    }

    /** @return iterable<TransportAdapterInterface> */
    public function adapters(): iterable
    {
        foreach ($this->names() as $name) {
            yield $this->adapter($name);
        }
    }
}
