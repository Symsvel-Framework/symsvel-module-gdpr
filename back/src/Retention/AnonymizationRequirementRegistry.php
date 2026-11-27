<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Retention;

/** A deterministic snapshot of the requirements registered in this container. */
final readonly class AnonymizationRequirementRegistry
{
    /** @var list<string> */
    private array $modules;

    /** @param iterable<AnonymizationRequirement> $requirements */
    public function __construct(iterable $requirements)
    {
        $modules = [];
        foreach ($requirements as $requirement) {
            if (!$requirement instanceof AnonymizationRequirement) {
                throw new \InvalidArgumentException('Invalid anonymization requirement service.');
            }
            $module = $requirement->moduleId();
            if (!preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $module)) {
                throw new \InvalidArgumentException('Invalid anonymization requirement module.');
            }
            if (isset($modules[$module])) {
                throw new \LogicException('Duplicate anonymization requirement: '.$module);
            }
            $modules[$module] = true;
        }
        $ids = array_keys($modules);
        sort($ids, SORT_STRING);
        $this->modules = $ids;
    }

    /** @return list<string> */
    public function requiringAnonymization(): array
    {
        return $this->modules;
    }

    public function isLocked(): bool
    {
        return $this->modules !== [];
    }
}
