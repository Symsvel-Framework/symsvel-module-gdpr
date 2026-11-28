<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SymSvel\Module\Gdpr\Mail\GdprMailTemplateProvider;
use SymSvel\Module\Gdpr\Mail\InactivityNoticeRenderer;
use SymSvel\Module\Mails\Mail\MailTemplateRegistry;
use SymSvel\Module\Mails\Mail\RegisteredMailRenderer;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

final class InactivityNoticeRendererTest extends TestCase
{
    private function renderer(): InactivityNoticeRenderer
    {
        $loader = new FilesystemLoader();
        $loader->addPath(dirname(__DIR__, 2).'/templates', 'Gdpr');

        return new InactivityNoticeRenderer(new RegisteredMailRenderer(
            new MailTemplateRegistry([new GdprMailTemplateProvider()]), new Environment($loader, ['strict_variables' => true]),
        ));
    }

    public function testLocalizedNoticesUseActualModeAndFullWarningPeriod(): void
    {
        $renderer = $this->renderer();
        $fr = $renderer->render('fr-fr', 7, 'anonymize', 'https://app.example.test');
        self::assertStringContainsString('neutralisé', $fr->text);
        self::assertStringContainsString('7 jours', $fr->html);
        self::assertStringContainsString('https://app.example.test/login', $fr->text);
        $en = $renderer->render('en_GB', 10, 'delete', 'https://app.example.test:8443/');
        self::assertStringContainsString('deleted', $en->text);
        self::assertStringContainsString('10 days', $en->html);
        self::assertStringContainsString('https://app.example.test:8443/login', $en->html);
        self::assertSame($fr->subject, $renderer->render(null, 7, 'anonymize', 'https://app.example.test')->subject);
    }

    public function testUnsafeLoginOriginIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->renderer()->render('fr', 7, 'delete', 'http://app.example.com');
    }
}
