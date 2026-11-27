<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Consent\ConsentCatalogue;
use SymSvel\Module\Gdpr\Consent\ConsentPurpose;
use SymSvel\Module\Gdpr\Consent\ConsentPurposeProvider;

final class ConsentCatalogueTest extends TestCase
{
    private function provider(): ConsentPurposeProvider
    {
        return new class implements ConsentPurposeProvider {
            public function purposes(string $scope): iterable
            {
                if ($scope === 'front') {
                    yield new ConsentPurpose('tracker-example', 2, 'Example', 'Audience', 'Description', 'https://example.invalid/privacy');
                }
            }
        };
    }

    public function testScopeAndDuplicateProtection(): void
    {
        $catalogue = new ConsentCatalogue([$this->provider()]);
        self::assertSame([], $catalogue->current('app'));
        self::assertSame(2, $catalogue->current('front')['tracker-example']->revision);
        $this->expectException(\LogicException::class);
        (new ConsentCatalogue([$this->provider(), $this->provider()]))->current('front');
    }

    public function testBoRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new ConsentCatalogue([]))->current('bo');
    }
}
