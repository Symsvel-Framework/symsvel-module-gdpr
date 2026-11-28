<?php

declare(strict_types=1);

namespace SymSvel\Module\Gdpr\Mail;

use SymSvel\Module\Mails\Mail\MailTemplate;
use SymSvel\Module\Mails\Mail\MailTemplateProviderInterface;

final class GdprMailTemplateProvider implements MailTemplateProviderInterface
{
    public function templates(): iterable
    {
        $locales = [];
        foreach (['fr', 'en'] as $locale) {
            $prefix = '@Gdpr/mail/inactivity/'.$locale.'/';
            $locales[$locale] = ['subject' => $prefix.'subject.twig', 'html' => $prefix.'body.html.twig', 'text' => $prefix.'body.txt.twig'];
        }

        yield new MailTemplate('gdpr.app.inactivity_notice', $locales, ['warningDays', 'mode', 'loginUrl']);
    }
}
