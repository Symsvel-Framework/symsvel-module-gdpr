<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261127173001 extends AbstractMigration
{
    public function up(Schema $schema): void
    {
        $table = $schema->createTable('symsvel_gdpr_creation_limits');
        $table->addColumn('key_hash', 'string', ['length' => 64]);
        $table->addColumn('window_start', 'bigint');
        $table->setPrimaryKey(['key_hash', 'window_start']);
        $table->addIndex(['window_start'], 'idx_gdpr_creation_window');
    }

    public function down(Schema $schema): void
    {
        $schema->dropTable('symsvel_gdpr_creation_limits');
    }
}
