<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260409070955 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'users, user_contacts, registry_contacts, domains (Brizy/amember persistence)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE domains (id INT AUTO_INCREMENT NOT NULL, domain_fqdn VARCHAR(255) NOT NULL, user_id INT NOT NULL, registry_contact_id INT NOT NULL, INDEX IDX_8C7BBF9DA76ED395 (user_id), INDEX IDX_8C7BBF9DACAF5FC (registry_contact_id), UNIQUE INDEX domains_fqdn_unique (domain_fqdn), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE registry_contacts (id INT AUTO_INCREMENT NOT NULL, registry_id VARCHAR(32) NOT NULL, contact_id VARCHAR(16) NOT NULL, user_id INT NOT NULL, INDEX IDX_B6226E14A76ED395 (user_id), UNIQUE INDEX registry_contacts_user_registry (user_id, registry_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE user_contacts (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, email VARCHAR(255) NOT NULL, phone VARCHAR(64) DEFAULT NULL, organization VARCHAR(255) DEFAULT NULL, street VARCHAR(255) DEFAULT NULL, city VARCHAR(128) DEFAULT NULL, state VARCHAR(128) DEFAULT NULL, postal_code VARCHAR(32) DEFAULT NULL, country_code VARCHAR(2) NOT NULL, user_id INT NOT NULL, UNIQUE INDEX user_contacts_user_id_unique (user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('CREATE TABLE users (id INT AUTO_INCREMENT NOT NULL, amember_user_id VARCHAR(64) NOT NULL, UNIQUE INDEX users_amember_user_id_unique (amember_user_id), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');
        $this->addSql('ALTER TABLE domains ADD CONSTRAINT FK_8C7BBF9DA76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE domains ADD CONSTRAINT FK_8C7BBF9DACAF5FC FOREIGN KEY (registry_contact_id) REFERENCES registry_contacts (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE registry_contacts ADD CONSTRAINT FK_B6226E14A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE user_contacts ADD CONSTRAINT FK_D3CDF173A76ED395 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE domains DROP FOREIGN KEY FK_8C7BBF9DA76ED395');
        $this->addSql('ALTER TABLE domains DROP FOREIGN KEY FK_8C7BBF9DACAF5FC');
        $this->addSql('ALTER TABLE registry_contacts DROP FOREIGN KEY FK_B6226E14A76ED395');
        $this->addSql('ALTER TABLE user_contacts DROP FOREIGN KEY FK_D3CDF173A76ED395');
        $this->addSql('DROP TABLE domains');
        $this->addSql('DROP TABLE registry_contacts');
        $this->addSql('DROP TABLE user_contacts');
        $this->addSql('DROP TABLE users');
    }
}
