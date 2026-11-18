-- Avunculus – vollständiges Datenbankschema für eine Neuinstallation.
--
-- Legt alle Tabellen in der gerade gewählten Datenbank an, z. B.
--   mariadb -u <benutzer> -p <datenbank> < database/schema.sql
-- oder beim Webhosting per phpMyAdmin: Datenbank wählen → Importieren → diese Datei.
--
-- Bewusst ohne CREATE DATABASE/USE (beim Hoster ist der Name vorgegeben) und ohne DROP TABLE:
-- Auf einer bestehenden Datenbank bricht das Skript mit „Table already exists“ ab, statt Daten zu löschen.
-- Jede Tabelle legt utf8mb4 selbst fest, unabhängig vom Standard-Zeichensatz der Datenbank.
SET NAMES utf8mb4;


-- Einstellungen der Installation. schema_version = Nummer der letzten Migration (backend/migrations/),
-- eine neue Installation ist auf dem neuesten Stand. Bei jeder neuen Migration hier mit hochzählen.
CREATE TABLE settings (
    name  VARCHAR(64) NOT NULL PRIMARY KEY,
    value TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO settings (name, value) VALUES ('schema_version', '1');

-- Benutzer; ohne Anmeldung ist man Besucher (sieht nur öffentliche Bäume, Lebende anonymisiert)
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)  NOT NULL,
    display_name  VARCHAR(120) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','editor') NOT NULL DEFAULT 'editor' COMMENT 'admin: alles; editor: Daten bearbeiten',
    last_login    TIMESTAMP    NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_username (username)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Fehlgeschlagene Anmeldungen (LoginThrottle: Sperre nach zu vielen Fehlversuchen, Einträge > 1 Tag werden gelöscht)
CREATE TABLE login_failures (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username   VARCHAR(60) NOT NULL,
    ip         VARCHAR(45) NOT NULL,
    created_at TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip (ip, created_at),
    INDEX idx_username (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ein Stammbaum (Projekt); alle Personen, Familien und Quellen gehören zu genau einem Baum
CREATE TABLE trees (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(200) NOT NULL,
    description TEXT         NULL,
    is_public     TINYINT(1) NOT NULL DEFAULT 0 COMMENT 'für Besucher sichtbar (nur lesend)',
    privacy_stale TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'persons.is_private muss neu berechnet werden',
    start_person_id INT UNSIGNED NULL COMMENT 'Startperson der Stammbaum-Ansicht (ohne Fremdschlüssel: persons verweist auf trees)',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE persons (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id    INT UNSIGNED NOT NULL,
    sex        ENUM('M','F','X','U') NOT NULL DEFAULT 'U',
    restriction VARCHAR(40) NULL COMMENT 'RESN: CONFIDENTIAL, LOCKED, PRIVACY (kommagetrennt)',
    notes      TEXT NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 1 COMMENT 'für Besucher anonymisiert (lebend vermutet / RESN), siehe Privacy.php',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_tree (tree_id),
    INDEX idx_tree_private (tree_id, is_private)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multiple names per person (GEDCOM NAME structure, GEDCOM 7.0)
CREATE TABLE person_names (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id      INT UNSIGNED NOT NULL,
    name_type      VARCHAR(20)  NOT NULL DEFAULT 'BIRTH' COMMENT 'GEDCOM NAME TYPE',
    name_prefix    VARCHAR(30)  NULL     COMMENT 'NPFX',
    given_name     VARCHAR(100) NULL     COMMENT 'GIVN',
    nickname       VARCHAR(100) NULL     COMMENT 'NICK',
    surname_prefix VARCHAR(30)  NULL     COMMENT 'SPFX',
    surname        VARCHAR(100) NULL     COMMENT 'SURN',
    name_suffix    VARCHAR(30)  NULL     COMMENT 'NSFX',
    sort_order     TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    INDEX idx_person_id (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GEDCOM REPOSITORY (REPO) record: Archiv / Aufbewahrungsort
CREATE TABLE repositories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(300) NOT NULL COMMENT 'NAME',
    address     TEXT         NULL     COMMENT 'ADDR (Straße usw.)',
    postal_code VARCHAR(20)  NULL     COMMENT 'ADDR.POST',
    city        VARCHAR(120) NULL     COMMENT 'ADDR.CITY',
    country     VARCHAR(120) NULL     COMMENT 'ADDR.CTRY',
    phone       VARCHAR(60)  NULL     COMMENT 'PHON',
    email       VARCHAR(200) NULL     COMMENT 'EMAIL',
    website     VARCHAR(500) NULL     COMMENT 'WWW',
    notes       TEXT         NULL     COMMENT 'NOTE',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_tree (tree_id),
    INDEX idx_name (name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GEDCOM SOURCE (SOUR) record
CREATE TABLE sources (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id      INT UNSIGNED NOT NULL,
    title        VARCHAR(500) NOT NULL            COMMENT 'TITL',
    abbreviation VARCHAR(100) NULL                COMMENT 'ABBR',
    author       VARCHAR(300) NULL                COMMENT 'AUTH',
    publication  TEXT         NULL                COMMENT 'PUBL',
    repository_id INT UNSIGNED NULL               COMMENT 'REPO → repositories',
    call_number  VARCHAR(250) NULL                COMMENT 'REPO.CALN – Signatur',
    media_type   VARCHAR(30)  NULL                COMMENT 'REPO.CALN.MEDI – Medium',
    source_text  TEXT         NULL                COMMENT 'TEXT',
    notes        TEXT         NULL                COMMENT 'NOTE',
    created_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    FOREIGN KEY (repository_id) REFERENCES repositories(id) ON DELETE SET NULL,
    INDEX idx_tree (tree_id),
    INDEX idx_title (title(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Orte: hierarchisch (Oberort → Unterorte), gemeinsam für alle Stammbäume;
-- Ereignisse verweisen per place_id (siehe backend/lib/PlaceIndex.php)
CREATE TABLE places (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT UNSIGNED  NULL,
    name        VARCHAR(200)  NOT NULL COMMENT 'Name auf dieser Ebene',
    full_name   VARCHAR(1000) NOT NULL DEFAULT '' COMMENT 'ganze Ortsangabe, vom Kleinsten zum Größten',
    place_type  VARCHAR(60)   NULL     COMMENT 'z. B. Ort, Gemeinde, Landkreis, Land',
    latitude    DECIMAL(9,6)  NULL     COMMENT 'MAP.LATI, Grad (Nord positiv)',
    longitude   DECIMAL(9,6)  NULL     COMMENT 'MAP.LONG, Grad (Ost positiv)',
    gov_id      VARCHAR(60)   NULL     COMMENT 'Kennung im GOV (gov.genealogy.net)',
    wikidata_id VARCHAR(20)   NULL     COMMENT 'Wikidata-Objekt, z. B. Q505961',
    notes       TEXT          NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (parent_id) REFERENCES places(id) ON DELETE CASCADE,
    INDEX idx_name (name(50)),
    INDEX idx_full_name (full_name(100))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE place_names (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    place_id    INT UNSIGNED NOT NULL,
    name        VARCHAR(200) NOT NULL,
    language    VARCHAR(40)  NULL COMMENT 'Sprache, z. B. de, pl, cs, la',
    date_period VARCHAR(100) NULL COMMENT 'Gültigkeit als GEDCOM-Datum, z. B. BEF 1945, FROM 1772 TO 1920',
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (place_id) REFERENCES places(id) ON DELETE CASCADE,
    INDEX idx_place (place_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Individual events (GEDCOM INDI events: BIRT, DEAT, BURI, BAPM, ...)
CREATE TABLE person_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id   INT UNSIGNED NOT NULL,
    event_type  VARCHAR(10)  NOT NULL DEFAULT 'EVEN' COMMENT 'GEDCOM tag: BIRT, DEAT, BURI …',
    event_date  VARCHAR(100) NULL                    COMMENT 'GEDCOM date string',
    place_id    INT UNSIGNED NULL                    COMMENT 'PLAC → places',
    event_value      TEXT         NULL COMMENT 'Wert bei Merkmalen (OCCU: Bäcker) bzw. Beschreibung bei EVEN',
    event_descriptor VARCHAR(120) NULL COMMENT 'TYPE – genauere Art (z. B. bei EVEN, FACT, IDNO)',
    age              VARCHAR(30)  NULL COMMENT 'AGE – Alter zum Zeitpunkt',
    cause            VARCHAR(250) NULL COMMENT 'CAUS – Ursache (z. B. Todesursache)',
    agency           VARCHAR(250) NULL COMMENT 'AGNC – zuständige Stelle (Pfarramt, Standesamt …)',
    religion         VARCHAR(120) NULL COMMENT 'RELI – Konfession, unter der das Ereignis stattfand',
    event_time       VARCHAR(20)  NULL COMMENT 'DATE.TIME – Uhrzeit (hh:mm[:ss])',
    notes            TEXT         NULL COMMENT 'NOTE zum Ereignis',
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (place_id)  REFERENCES places(id)  ON DELETE SET NULL,
    INDEX idx_person_event (person_id, event_type),
    INDEX idx_person_sort  (person_id, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Associated persons on individual events (GEDCOM 7.0 ASSO / ROLE)
CREATE TABLE event_associations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NOT NULL,
    person_id   INT UNSIGNED NOT NULL,
    role        VARCHAR(20)  NULL     COMMENT 'GEDCOM 7.0 ROLE enumeration',
    role_phrase VARCHAR(200) NULL     COMMENT 'GEDCOM 7.0 PHRASE (free text for OTHER)',
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (event_id)  REFERENCES person_events(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id)       ON DELETE CASCADE,
    INDEX idx_event  (event_id),
    INDEX idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GEDCOM FAM record: family unit (parents + children)
CREATE TABLE families (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id    INT UNSIGNED NOT NULL,
    notes      TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_tree (tree_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Family membership: HUSB, WIFE, CHIL (GEDCOM FAM member roles)
CREATE TABLE family_members (
    id        INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id INT UNSIGNED NOT NULL,
    person_id INT UNSIGNED NOT NULL,
    role      VARCHAR(10)  NOT NULL COMMENT 'GEDCOM: HUSB, WIFE, CHIL',
    pedigree  VARCHAR(10)  NULL     COMMENT 'PEDI bei Kindern: BIRTH, ADOPTED, FOSTER, SEALED, OTHER',
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id)  ON DELETE CASCADE,
    UNIQUE KEY uq_person_family_role (person_id, family_id, role),
    INDEX idx_family (family_id),
    INDEX idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Source citations on individual events
CREATE TABLE event_citations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    source_id     INT UNSIGNED NULL COMMENT 'FK → sources',
    page          VARCHAR(250) NULL COMMENT 'GEDCOM PAGE',
    transcription TEXT         NULL COMMENT 'GEDCOM DATA.TEXT',
    url           VARCHAR(500) NULL,
    quality       TINYINT UNSIGNED NULL COMMENT 'QUAY – Verlässlichkeit 0–3',
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (event_id)  REFERENCES person_events(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL,
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Familienereignisse (GEDCOM FAM events: MARR, DIV, ENGA …)
CREATE TABLE family_events (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id   INT UNSIGNED NOT NULL,
    event_type  VARCHAR(10)  NOT NULL DEFAULT 'EVEN' COMMENT 'GEDCOM tag: MARR, DIV, ENGA …',
    event_date  VARCHAR(100) NULL                    COMMENT 'GEDCOM date string',
    place_id    INT UNSIGNED NULL                    COMMENT 'PLAC → places',
    event_value      TEXT         NULL COMMENT 'Beschreibung bei EVEN',
    event_descriptor VARCHAR(120) NULL COMMENT 'TYPE – genauere Art',
    husb_age         VARCHAR(30)  NULL COMMENT 'HUSB.AGE – Alter des Mannes',
    wife_age         VARCHAR(30)  NULL COMMENT 'WIFE.AGE – Alter der Frau',
    cause            VARCHAR(250) NULL COMMENT 'CAUS – Ursache',
    agency           VARCHAR(250) NULL COMMENT 'AGNC – zuständige Stelle',
    religion         VARCHAR(120) NULL COMMENT 'RELI – Konfession, unter der das Ereignis stattfand',
    event_time       VARCHAR(20)  NULL COMMENT 'DATE.TIME – Uhrzeit (hh:mm[:ss])',
    notes            TEXT         NULL COMMENT 'NOTE zum Ereignis',
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    FOREIGN KEY (place_id)  REFERENCES places(id)   ON DELETE SET NULL,
    INDEX idx_family_event (family_id, event_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_event_citations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id      INT UNSIGNED NOT NULL,
    source_id     INT UNSIGNED NULL COMMENT 'FK → sources',
    page          VARCHAR(250) NULL COMMENT 'GEDCOM PAGE',
    transcription TEXT         NULL COMMENT 'GEDCOM DATA.TEXT',
    url           VARCHAR(500) NULL,
    quality       TINYINT UNSIGNED NULL COMMENT 'QUAY – Verlässlichkeit 0–3',
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (event_id)  REFERENCES family_events(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL,
    INDEX idx_event (event_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quellenangaben direkt an einer Familie (GEDCOM FAM.SOUR)
CREATE TABLE family_citations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id     INT UNSIGNED NOT NULL,
    source_id     INT UNSIGNED NULL COMMENT 'FK → sources',
    page          VARCHAR(250) NULL COMMENT 'GEDCOM PAGE',
    transcription TEXT         NULL COMMENT 'GEDCOM DATA.TEXT',
    url           VARCHAR(500) NULL,
    quality       TINYINT UNSIGNED NULL COMMENT 'QUAY – Verlässlichkeit 0–3',
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id)  ON DELETE SET NULL,
    INDEX idx_family (family_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Quellenangaben direkt an einer Person (GEDCOM INDI.SOUR)
CREATE TABLE person_citations (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id     INT UNSIGNED NOT NULL,
    source_id     INT UNSIGNED NULL COMMENT 'FK → sources',
    page          VARCHAR(250) NULL COMMENT 'GEDCOM PAGE',
    transcription TEXT         NULL COMMENT 'GEDCOM DATA.TEXT',
    url           VARCHAR(500) NULL,
    quality       TINYINT UNSIGNED NULL COMMENT 'QUAY – Verlässlichkeit 0–3',
    sort_order    TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE SET NULL,
    INDEX idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Mediathek (GEDCOM OBJE): Dateien liegen im Dateisystem (MEDIA_DIR, Standard data/media/<Baum>/)
CREATE TABLE media (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tree_id       INT UNSIGNED NOT NULL,
    title         VARCHAR(300) NULL     COMMENT 'TITL',
    file_name     VARCHAR(100) NOT NULL COMMENT 'Dateiname im Medienordner des Baums',
    original_name VARCHAR(255) NULL     COMMENT 'ursprünglicher Dateiname (Upload bzw. FILE)',
    mime_type     VARCHAR(100) NOT NULL DEFAULT 'application/octet-stream',
    file_size     BIGINT UNSIGNED NOT NULL DEFAULT 0,
    width         INT UNSIGNED NULL,
    height        INT UNSIGNED NULL,
    checksum      CHAR(40)     NOT NULL COMMENT 'SHA-1 des Inhalts (gleiche Datei nur einmal je Baum)',
    media_date    VARCHAR(100) NULL     COMMENT 'Aufnahmedatum als GEDCOM-Datum',
    notes         TEXT         NULL     COMMENT 'NOTE',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (tree_id) REFERENCES trees(id) ON DELETE CASCADE,
    INDEX idx_tree (tree_id),
    INDEX idx_checksum (tree_id, checksum)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE person_media (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id  INT UNSIGNED NOT NULL,
    media_id   INT UNSIGNED NOT NULL,
    is_primary TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Hauptbild der Person (_PRIM)',
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (person_id) REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id)  REFERENCES media(id)   ON DELETE CASCADE,
    INDEX idx_person (person_id, is_primary),
    INDEX idx_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_media (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    family_id  INT UNSIGNED NOT NULL,
    media_id   INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (family_id) REFERENCES families(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id)  REFERENCES media(id)    ON DELETE CASCADE,
    INDEX idx_family (family_id),
    INDEX idx_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE event_media (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id   INT UNSIGNED NOT NULL COMMENT '→ person_events',
    media_id   INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (event_id) REFERENCES person_events(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id) REFERENCES media(id)         ON DELETE CASCADE,
    INDEX idx_event (event_id),
    INDEX idx_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_event_media (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id   INT UNSIGNED NOT NULL COMMENT '→ family_events',
    media_id   INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (event_id) REFERENCES family_events(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id) REFERENCES media(id)         ON DELETE CASCADE,
    INDEX idx_event (event_id),
    INDEX idx_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE source_media (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_id  INT UNSIGNED NOT NULL,
    media_id   INT UNSIGNED NOT NULL,
    sort_order TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (source_id) REFERENCES sources(id) ON DELETE CASCADE,
    FOREIGN KEY (media_id)  REFERENCES media(id)   ON DELETE CASCADE,
    INDEX idx_source (source_id),
    INDEX idx_media (media_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Verknüpfte Personen direkt an Personen (INDI.ASSO) und an Familienereignissen (Trauzeugen …)
CREATE TABLE person_associations (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    person_id    INT UNSIGNED NOT NULL COMMENT 'Person, bei der die Verknüpfung steht',
    associate_id INT UNSIGNED NOT NULL COMMENT 'verknüpfte Person (ASSO @I…@)',
    role         VARCHAR(20)  NULL     COMMENT 'GEDCOM 7.0 ROLE enumeration',
    role_phrase  VARCHAR(200) NULL     COMMENT 'PHRASE bzw. RELA-Freitext (bei OTHER)',
    notes        TEXT         NULL     COMMENT 'NOTE zur Verknüpfung',
    sort_order   TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (person_id)    REFERENCES persons(id) ON DELETE CASCADE,
    FOREIGN KEY (associate_id) REFERENCES persons(id) ON DELETE CASCADE,
    INDEX idx_person (person_id),
    INDEX idx_associate (associate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE family_event_associations (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NOT NULL COMMENT '→ family_events',
    person_id   INT UNSIGNED NOT NULL,
    role        VARCHAR(20)  NULL     COMMENT 'GEDCOM 7.0 ROLE enumeration',
    role_phrase VARCHAR(200) NULL     COMMENT 'PHRASE bzw. RELA-Freitext (bei OTHER)',
    sort_order  TINYINT UNSIGNED NOT NULL DEFAULT 0,
    FOREIGN KEY (event_id)  REFERENCES family_events(id) ON DELETE CASCADE,
    FOREIGN KEY (person_id) REFERENCES persons(id)       ON DELETE CASCADE,
    INDEX idx_event  (event_id),
    INDEX idx_person (person_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Änderungsprotokoll: jede erfolgreiche schreibende Anfrage (backend/lib/ChangeLog.php, zentral in index.php).
-- Bezeichnung und Benutzername als Schnappschuss, damit gelöschte Datensätze und Benutzer lesbar bleiben.
-- Ein Import ist ein Eintrag (Statistik in details), keine Zeile je Person.
CREATE TABLE change_log (
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
