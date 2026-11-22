<?php

declare(strict_types=1);

// 1.2.0 – In der Bilddatei markierte Personen (XMP, z. B. aus Lightroom/digiKam) als Vorschläge.
return function (PDO $db): void {
    $db->exec("
        ALTER TABLE media
            ADD COLUMN IF NOT EXISTS file_regions TEXT NULL
                COMMENT 'In der Datei markierte Personen (XMP-Regionen) als JSON; NULL = noch nicht gelesen'
            AFTER notes
    ");
};
