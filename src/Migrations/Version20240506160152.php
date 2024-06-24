<?php

declare(strict_types=1);

namespace MonextSyliusPlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240506160152 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Creates MonextReference entity to track payment and Monext session IDs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE monext_reference (id INT AUTO_INCREMENT NOT NULL, payment_id INT NOT NULL, token VARCHAR(255) DEFAULT NULL, transactionId VARCHAR(255) DEFAULT NULL, UNIQUE INDEX UNIQ_F294E7124C3A3BB (payment_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE monext_reference ADD CONSTRAINT FK_F294E7124C3A3BB FOREIGN KEY (payment_id) REFERENCES sylius_payment (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE monext_reference DROP FOREIGN KEY FK_F294E7124C3A3BB');
        $this->addSql('DROP TABLE monext_reference');
    }
}
