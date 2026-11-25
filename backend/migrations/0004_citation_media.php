<?php

declare(strict_types=1);

// 1.2.0 – Medien an Quellenangaben (GEDCOM SOUR.OBJE), z. B. der Scan des zitierten Kirchenbucheintrags.
return function (PDO $db): void {
    $tables = [
        'person_citation_media'       => 'person_citations',
        'family_citation_media'       => 'family_citations',
        'event_citation_media'        => 'event_citations',
        'family_event_citation_media' => 'family_event_citations',
    ];
    foreach ($tables as $table => $citations) {
        $db->exec("
            CREATE TABLE IF NOT EXISTS $table (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                citation_id INT UNSIGNED NOT NULL COMMENT '→ $citations',
                media_id    INT UNSIGNED NOT NULL,
                sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
                FOREIGN KEY (citation_id) REFERENCES $citations(id) ON DELETE CASCADE,
                FOREIGN KEY (media_id)    REFERENCES media(id) ON DELETE CASCADE,
                INDEX idx_citation (citation_id),
                INDEX idx_media (media_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
};
