<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260414120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Symfony Security: users.roles (JSON)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users ADD roles JSON DEFAULT NULL');
        $this->addSql('UPDATE users SET roles = \'["ROLE_USER"]\' WHERE roles IS NULL');
        $this->addSql('ALTER TABLE users MODIFY roles JSON NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE users DROP roles');
    }
}
