<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Adds rejection audit columns for the RejectSalesDocument operation
 * (mirrors approved_by / approved_at).
 */
final class Version20260830000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add rejected_by / rejected_at to sales_document';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_document ADD rejected_by INT DEFAULT NULL, ADD rejected_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sales_document DROP rejected_by, DROP rejected_at');
    }
}
