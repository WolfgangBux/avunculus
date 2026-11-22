<?php

declare(strict_types=1);

// 1.2.0 – Ausschnitt einer Person auf einem Bild (Markierung; Vorschaubild zeigt nur diesen Teil).
return function (PDO $db): void {
    $db->exec("
        ALTER TABLE person_media
            ADD COLUMN IF NOT EXISTS crop_left   DECIMAL(7,6) NULL COMMENT 'Ausschnitt: linker Rand als Anteil der Bildbreite (0–1)',
            ADD COLUMN IF NOT EXISTS crop_top    DECIMAL(7,6) NULL COMMENT 'oberer Rand als Anteil der Bildhöhe',
            ADD COLUMN IF NOT EXISTS crop_width  DECIMAL(7,6) NULL COMMENT 'Breite als Anteil der Bildbreite',
            ADD COLUMN IF NOT EXISTS crop_height DECIMAL(7,6) NULL COMMENT 'Höhe als Anteil der Bildhöhe',
            ADD COLUMN IF NOT EXISTS crop_shape  VARCHAR(10)  NULL COMMENT 'rect oder ellipse (nur Darstellung der Markierung)'
    ");
};
