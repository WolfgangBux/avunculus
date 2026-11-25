<?php

declare(strict_types=1);

// 1.2.0 – Ausschnitt auf dem Original einer Quellenangabe: der Bereich, den die Transkription wiedergibt.
return function (PDO $db): void {
    foreach (['person_citation_media', 'family_citation_media', 'event_citation_media', 'family_event_citation_media'] as $table) {
        $db->exec("
            ALTER TABLE $table
                ADD COLUMN IF NOT EXISTS crop_left   DECIMAL(7,6) NULL COMMENT 'Bereich der Transkription: linker Rand als Anteil der Bildbreite (0–1)',
                ADD COLUMN IF NOT EXISTS crop_top    DECIMAL(7,6) NULL COMMENT 'oberer Rand als Anteil der Bildhöhe',
                ADD COLUMN IF NOT EXISTS crop_width  DECIMAL(7,6) NULL COMMENT 'Breite als Anteil der Bildbreite',
                ADD COLUMN IF NOT EXISTS crop_height DECIMAL(7,6) NULL COMMENT 'Höhe als Anteil der Bildhöhe'
        ");
    }
};
