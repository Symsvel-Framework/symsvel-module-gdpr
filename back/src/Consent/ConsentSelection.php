<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Consent;

/** Accept only an explicit complete selection of the current catalogue. */
final readonly class ConsentSelection
{
    public function __construct(
        private ConsentCatalogue $catalogue,
        private ConsentRepository $repository,
        private ConsentPolicy $policy,
    ) {
    }

    public function state(string $scope, ?string $visitorToken, bool $enabled, \DateTimeImmutable $now): array
    {
        $purposes = $this->catalogue->current($scope);
        if (!$enabled) {
            return ['enabled' => false, 'version' => null, 'expires_at' => null, 'complete' => false, 'purposes' => []];
        }
        $snapshot = $visitorToken === null ? null : $this->repository->find($visitorToken, $scope);
        $current = $snapshot !== null && $snapshot->decidedAt <= $now && $snapshot->expiresAt > $now;
        $complete = $current && count($snapshot->choices) === count($purposes);
        $entries = [];
        foreach ($purposes as $id => $purpose) {
            $decision = $current ? $snapshot->decision($id) : null;
            $known = $decision !== null && $decision->revision === $purpose->revision;
            $complete = $complete && $known;
            $entries[] = [
                'subject' => $id, 'revision' => $purpose->revision, 'name' => $purpose->name,
                'purpose' => $purpose->purpose, 'description' => $purpose->description,
                'privacy_url' => $purpose->privacyUrl, 'decided' => $known,
                'granted' => $this->policy->permits(true, $scope, $id, $purpose->revision, $now, $decision),
            ];
        }

        return [
            'enabled' => true, 'version' => $snapshot?->version,
            'expires_at' => $current ? $snapshot->expiresAt->format(DATE_ATOM) : null,
            'complete' => $complete, 'purposes' => $entries,
        ];
    }

    /** @param array<string, array{revision: int, granted: bool}> $choices */
    public function save(
        string $scope,
        string $visitorToken,
        ?string $expectedVersion,
        array $choices,
        bool $enabled,
        int $validityMonths,
        \DateTimeImmutable $now,
    ): ConsentSnapshot {
        if (!$enabled) {
            throw new \DomainException('GDPR consent is disabled.');
        }
        $purposes = $this->catalogue->current($scope);
        if (count($purposes) !== count($choices)) {
            throw new \DomainException('Consent catalogue changed or selection is incomplete.');
        }
        foreach ($purposes as $id => $purpose) {
            $choice = $choices[$id] ?? null;
            if (!is_array($choice) || count($choice) !== 2 || ($choice['revision'] ?? null) !== $purpose->revision
                || !is_bool($choice['granted'] ?? null)) {
                throw new \DomainException('Consent selection does not match the current catalogue.');
            }
        }
        $snapshot = new ConsentSnapshot($scope, bin2hex(random_bytes(16)), $choices, $now,
            $this->policy->expiry($now, $validityMonths));
        if (!$this->repository->replace($visitorToken, $snapshot, $expectedVersion)) {
            throw new \DomainException('Consent selection changed concurrently.');
        }

        return $snapshot;
    }
}
