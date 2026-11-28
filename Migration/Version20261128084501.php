<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261128084501 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('symsvel_gdpr_inactivity_notices');
        $table->addColumn('user_id', 'bigint');
        $table->addColumn('cycle_hash', 'string', ['length' => 64]);
        $table->addColumn('attempt', 'string', ['length' => 32]);
        $table->addColumn('claimed_at', 'datetime_immutable');
        $table->addColumn('sent_at', 'datetime_immutable', ['notnull' => false]);
        $table->setPrimaryKey(['user_id', 'cycle_hash']);
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('symsvel_gdpr_inactivity_notices');
    }
}
