<?php

declare(strict_types=1);

/**
 * Alle API-Endpunkte (Pfad ohne /api/). {name} steht für eine Zahl und wird der Aktion als int übergeben.
 *
 * Rolle = mindestens nötig:  guest (jeder, auch ohne Anmeldung) < editor < admin
 *
 * Controller, die von TreeScopedController erben, arbeiten in einem Stammbaum (Header X-Tree-Id,
 * bei Bild-/Dateiabrufen ?tree=). Besucher sehen dort nur freigegebene Bäume, Lebende anonymisiert.
 *
 * Reihenfolge ist egal, weil {…} nur Zahlen trifft (persons/search ≠ persons/{id}).
 */

$G = 'guest';
$E = 'editor';
$A = 'admin';

return [
    // ── Anmeldung ───────────────────────────────────────────────────────────────
    ['GET',    'auth/me',                         AuthController::class,       'me',               $G],
    ['POST',   'auth/login',                      AuthController::class,       'login',            $G],
    ['POST',   'auth/logout',                     AuthController::class,       'logout',           $G],
    ['POST',   'auth/setup',                      AuthController::class,       'setup',            $G],   // nur solange es keinen Benutzer gibt
    ['PUT',    'auth/password',                   AuthController::class,       'changePassword',   $E],

    // ── Benutzerverwaltung ──────────────────────────────────────────────────────
    ['GET',    'users',                           UserController::class,       'index',            $A],
    ['POST',   'users',                           UserController::class,       'store',            $A],
    ['PUT',    'users/{id}',                      UserController::class,       'update',           $A],
    ['DELETE', 'users/{id}',                      UserController::class,       'destroy',          $A],

    // ── Stammbäume (ohne Baum-Kontext) ──────────────────────────────────────────
    ['GET',    'trees',                           TreeController::class,       'index',            $G],   // Besucher: nur freigegebene
    ['POST',   'trees',                           TreeController::class,       'store',            $A],
    ['PUT',    'trees/{id}',                      TreeController::class,       'update',           $A],
    ['DELETE', 'trees/{id}',                      TreeController::class,       'destroy',          $A],
    ['POST',   'trees/import',                    TreeController::class,       'import',           $A],
    ['POST',   'trees/import/commit',             TreeController::class,       'commitImport',     $A],
    ['GET',    'trees/{id}/export',               TreeController::class,       'export',           $E],
    ['GET',    'trees/{id}/relatives',            TreeController::class,       'relativesPreview', $A],
    ['POST',   'trees/{id}/relatives',            TreeController::class,       'relativesTree',    $A],

    // ── Personen ────────────────────────────────────────────────────────────────
    ['GET',    'persons/search',                  PersonController::class,     'search',           $G],
    ['GET',    'persons/{id}',                    PersonController::class,     'show',             $G],
    ['GET',    'surnames',                        SurnameController::class,    'index',            $G],   // Buchstaben, Wolke, ?letter=
    ['GET',    'surnames/branches',               SurnameController::class,    'branches',         $G],   // ?surname=
    ['POST',   'persons',                         PersonController::class,     'store',            $E],
    ['PUT',    'persons/{id}',                    PersonController::class,     'update',           $E],
    ['DELETE', 'persons/{id}',                    PersonController::class,     'destroy',          $E],
    ['GET',    'persons/{id}/families',           FamilyController::class,     'personFamilies',   $G],
    ['GET',    'persons/{id}/places',             PersonController::class,     'places',           $G],

    // ── Familien ────────────────────────────────────────────────────────────────
    ['GET',    'families/search',                 FamilyController::class,     'search',           $G],
    ['GET',    'pedigree',                        FamilyController::class,     'pedigree',         $G],
    ['GET',    'families/{id}',                   FamilyController::class,     'show',             $G],
    ['POST',   'families',                        FamilyController::class,     'store',            $E],
    ['PUT',    'families/{id}',                   FamilyController::class,     'update',           $E],
    ['DELETE', 'families/{id}',                   FamilyController::class,     'destroy',          $E],
    ['POST',   'families/{id}/events',            FamilyController::class,     'addEvent',         $E],
    ['PUT',    'families/{id}/events/{event}',    FamilyController::class,     'updateEvent',      $E],
    ['DELETE', 'families/{id}/events/{event}',    FamilyController::class,     'deleteEvent',      $E],
    ['POST',   'families/{id}/members',           FamilyController::class,     'addMember',        $E],
    ['PUT',    'families/{id}/members/{person}',  FamilyController::class,     'updateMember',     $E],
    ['DELETE', 'families/{id}/members/{person}',  FamilyController::class,     'removeMember',     $E],

    // ── Quellen und Archive ─────────────────────────────────────────────────────
    ['GET',    'sources/search',                  SourceController::class,     'search',           $G],
    ['GET',    'sources/{id}',                    SourceController::class,     'show',             $G],
    ['POST',   'sources',                         SourceController::class,     'store',            $E],
    ['PUT',    'sources/{id}',                    SourceController::class,     'update',           $E],
    ['DELETE', 'sources/{id}',                    SourceController::class,     'destroy',          $E],
    ['GET',    'repositories',                    RepositoryController::class, 'index',            $G],
    ['GET',    'repositories/{id}',               RepositoryController::class, 'show',             $G],
    ['POST',   'repositories',                    RepositoryController::class, 'store',            $E],
    ['PUT',    'repositories/{id}',               RepositoryController::class, 'update',           $E],
    ['DELETE', 'repositories/{id}',               RepositoryController::class, 'destroy',          $E],

    // ── Orte (gemeinsame Ortsbasis; Zählungen je Stammbaum) ─────────────────────
    ['GET',    'places',                          PlaceController::class,      'index',            $G],
    ['GET',    'places/search',                   PlaceController::class,      'search',           $G],
    ['GET',    'places/{id}',                     PlaceController::class,      'show',             $G],
    ['POST',   'places',                          PlaceController::class,      'store',            $E],
    ['PUT',    'places/{id}',                     PlaceController::class,      'update',           $E],
    ['DELETE', 'places/{id}',                     PlaceController::class,      'destroy',          $E],
    ['POST',   'places/{id}/merge',               PlaceController::class,      'merge',            $E],
    ['POST',   'places/wikidata',                 PlaceController::class,      'applyWikidata',    $E],
    ['POST',   'places/delete-unused',            PlaceController::class,      'destroyUnused',    $E],

    // ── Mediathek ───────────────────────────────────────────────────────────────
    ['GET',    'media/search',                    MediaController::class,      'search',           $G],
    ['GET',    'media/{id}',                      MediaController::class,      'show',             $G],
    ['GET',    'media/{id}/file',                 MediaController::class,      'file',             $G],
    ['GET',    'media/{id}/thumb',                MediaController::class,      'thumb',            $G],
    ['POST',   'media/upload',                    MediaController::class,      'upload',           $E],
    ['PUT',    'media/{id}',                      MediaController::class,      'update',           $E],
    ['DELETE', 'media/{id}',                      MediaController::class,      'destroy',          $E],

    // ── Einstellungen, Version und Updates ──────────────────────────────────────
    ['GET',    'system/settings',                 SystemController::class,     'settings',         $A],
    ['PUT',    'system/settings',                 SystemController::class,     'saveSettings',     $A],
    ['GET',    'system/update',                   SystemController::class,     'updateStatus',     $A],
    ['POST',   'system/update',                   SystemController::class,     'installUpdate',    $A],

    // ── Änderungsprotokoll ──────────────────────────────────────────────────────
    ['GET',    'changes',                         ChangeController::class,     'index',            $E],

    // ── Fehlersuche (unplausible Lebensdaten) ───────────────────────────────────
    ['GET',    'checks',                          CheckController::class,      'index',            $E],

    // ── Verwandtschaftsrechner ──────────────────────────────────────────────────
    ['GET',    'relationship',                    RelationshipController::class, 'show',           $G],

    // ── Statistik ───────────────────────────────────────────────────────────────
    ['GET',    'statistics',                      StatisticsController::class, 'index',            $G],
    ['GET',    'statistics/map',                  StatisticsController::class, 'map',              $G],
    ['GET',    'statistics/cloud',                StatisticsController::class, 'cloud',            $G],   // ?kind=given|place&n=

    // ── Berichte und Ausdrucke ──────────────────────────────────────────────────
    ['GET',    'reports/ancestors',               ReportController::class,     'ancestors',        $G],
    ['GET',    'reports/descendants',             ReportController::class,     'descendants',      $G],
    ['GET',    'reports/family/{id}',             ReportController::class,     'family',           $G],
    ['GET',    'reports/list',                    ReportController::class,     'list',             $G],
    ['GET',    'reports/familybook',              ReportController::class,     'familyBook',       $G],
];
