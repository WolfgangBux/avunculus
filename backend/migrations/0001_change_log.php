<?php

declare(strict_types=1);

// 1.0.0 – Änderungsprotokoll für Installationen, die von vor 1.0 stammen (neue haben es aus schema.sql).
return function (PDO $db): void {
    $db->exec("
        CREATE TABLE IF NOT EXISTS change_log (
            id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tree_id      INT UNSIGNED NOT NULL,
            user_id      INT UNSIGNED NULL,
            user_name    VARCHAR(120) NOT NULL,
            entity_type  VARCHAR(20)  NOT NULL COMMENT 'person, family, source, repository, place, media, tree',
            entity_id    INT UNSIGNED NULL     COMMENT 'NULL bei Sammelaktionen (z. B. unbenutzte Orte löschen)',
            entity_label VARCHAR(255) NULL,
            action       VARCHAR(30)  NOT NULL COMMENT 'Aktion der Route: store, update, destroy, addMember, commitImport …',
            details      TEXT         NULL     COMMENT 'JSON, z. B. Import-Statistik oder betroffene Person',
            created_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
            INDEX idx_tree   (tree_id, id),
            INDEX idx_entity (tree_id, entity_type, entity_id, id),
            INDEX idx_user   (tree_id, user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
};
