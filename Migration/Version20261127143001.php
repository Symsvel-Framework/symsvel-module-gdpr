<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127143001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('symsvel_gdpr_consents');
        $table->addColumn('visitor_hash', 'string', ['length' => 64]);
        $table->addColumn('scope', 'string', ['length' => 5]);
        $table->addColumn('version', 'string', ['length' => 32]);
        $table->addColumn('choices_json', 'text');
        $table->addColumn('decided_at', 'datetime_immutable');
        $table->addColumn('expires_at', 'datetime_immutable');
        $table->setPrimaryKey(['visitor_hash', 'scope']);
        $table->addIndex(['expires_at'], 'idx_gdpr_consents_expiry');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('symsvel_gdpr_consents');
    }
}
