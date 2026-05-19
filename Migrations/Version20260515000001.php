<?php

declare(strict_types=1);

namespace TwoChain\PimcoreMessengerDashboardBundle\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Override;

final class Version20260515000001 extends AbstractMigration
{
    #[Override]
    public function getDescription(): string
    {
        return 'Create messenger_dashboard_stats audit table';
    }

    #[Override]
    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE messenger_dashboard_stats (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                transport VARCHAR(190) NOT NULL,
                message_class VARCHAR(255) NOT NULL,
                status VARCHAR(16) NOT NULL,
                handled_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                duration_ms INT UNSIGNED DEFAULT NULL,
                retry_count SMALLINT UNSIGNED DEFAULT NULL,
                failure_class VARCHAR(255) DEFAULT NULL,
                failure_message TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                INDEX idx_transport_handled_at (transport, handled_at),
                INDEX idx_transport_status_handled_at (transport, status, handled_at),
                INDEX idx_handled_at (handled_at)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    #[Override]
    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE messenger_dashboard_stats');
    }
}
