<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

final readonly class ConsentCatalogue
{
    /** @var list<ConsentPurposeProvider> */
    private array $providers;

    /** @param iterable<ConsentPurposeProvider> $providers */
    public function __construct(iterable $providers)
    {
        $registered = [];
        foreach ($providers as $provider) {
            if (!$provider instanceof ConsentPurposeProvider) {
                throw new \LogicException('Invalid consent purpose provider.');
            }
            $registered[] = $provider;
        }
        $this->providers = $registered;
    }

    /** @return array<string, ConsentPurpose> */
    public function current(string $scope): array
    {
        if (!in_array($scope, ['app', 'front'], true)) {
            throw new \InvalidArgumentException('Invalid consent scope.');
        }
        $catalogue = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->purposes($scope) as $purpose) {
                if (!$purpose instanceof ConsentPurpose || isset($catalogue[$purpose->subject])) {
                    throw new \LogicException('Invalid or duplicate consent purpose.');
                }
                $catalogue[$purpose->subject] = $purpose;
                if (count($catalogue) > 250) {
                    throw new \LogicException('Consent catalogue exceeds supported size.');
                }
            }
        }
        ksort($catalogue, SORT_STRING);

        return $catalogue;
    }
}
