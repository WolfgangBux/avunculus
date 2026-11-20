<?php

declare(strict_types=1);

require_once __DIR__ . '/DuplicateMatcher.php';
require_once __DIR__ . '/GedcomTags.php';
require_once __DIR__ . '/MediaStore.php';
require_once __DIR__ . '/MediaLinks.php';

/**
 * Liest eine GEDCOM-Datei (5.5 / 5.5.1 / 7.0) und legt ihren Inhalt in einem Stammbaum an.
 *
 * Übernommen werden: Personen (INDI) mit Namen, Geschlecht, Notizen und Ereignissen,
 * Quellen (SOUR) samt Quellenangaben an Ereignissen, Familien (FAM) mit Partnern, Kindern
 * und Familienereignissen (Heirat, Scheidung …), Medien (OBJE) – aber nur, wenn die Datei im
 * hochgeladenen ZIP-Archiv liegt. Alles Übrige wird gezählt und im Ergebnis als „nicht übernommen“ gemeldet.
 *
 * Import in einen bestehenden Baum (merge):
 *   1. analyze() sucht mögliche Dubletten und liefert sie zur Prüfung.
 *   2. import() mit $mapping (xref → vorhandene Personen-ID) ergänzt bei zugeordneten
 *      Personen nur Fehlendes (Namen, Ereignisse, leere Datums-/Ortsfelder, Quellenangaben,
 *      Notizen). Vorhandene Werte werden nie überschrieben; Widersprüche werden gemeldet.
 *      Familien zwischen zugeordneten Partnern und Quellen mit gleichem Titel werden
 *      wiederverwendet.
 *
 * Erwartet, dass der Aufrufer eine Transaktion geöffnet hat.
 */
final class GedcomImporter
{
    /** GEDCOM-Tag → Ereignis-/Merkmalstyp in Avunculus (alle Personen-Typen 1:1, siehe GedcomTags) */
    private static function eventMap(): array
    {
        static $map = null;
        return $map ??= array_combine(GedcomTags::personTypes(), GedcomTags::personTypes());
    }

    private const NAME_TYPE_MAP = [
        'BIRTH' => 'BIRTH', 'MAIDEN' => 'BIRTH', 'MARRIED' => 'MARRIED', 'AKA' => 'AKA',
        'IMMIGRANT' => 'IMMIGRANT', 'PROFESSIONAL' => 'PROFESSIONAL',
    ];

    /** Familienereignisse, die übernommen werden (siehe FamilyController::EVENT_TYPES) */
    private const FAMILY_EVENTS = ['MARR', 'MARB', 'MARC', 'MARL', 'ENGA', 'DIV', 'DIVF', 'ANUL', 'CENS', 'RESI', 'EVEN'];

    /** Ereignisse, die es nur einmal gibt: abweichende Daten sind ein Widerspruch, kein zweites Ereignis */
    private const UNIQUE_PERSON_EVENTS = ['BIRT', 'DEAT', 'BURI', 'CREM', 'BAPM'];
    private const UNIQUE_FAMILY_EVENTS = ['MARR', 'DIV', 'ANUL'];

    /** Tags, die in INDI bewusst anders verarbeitet werden und nicht als „übersprungen“ zählen */
    private const INDI_HANDLED = ['NAME', '_AKA', '_MARNM', '_MARNM_SURN', 'ALIA', ...self::IGNORED_INDI, 'SEX', 'NOTE', 'FAMC', 'FAMS', 'CHAN', 'RIN', '_UID', 'UID', 'RESN', 'SOUR', 'OBJE', 'ASSO', '_ASSO'];
    private const FAM_HANDLED  = ['HUSB', 'WIFE', 'CHIL', 'NOTE', 'CHAN', 'RIN', '_UID', 'UID', 'NCHI', 'SOUR', 'OBJE', '_UPD'];
    /**
     * Bewusst übergangen (nicht als „übersprungen“ gemeldet): Änderungszeitpunkt (_UPD), direkte
     * Eltern-/Geschwisterverweise aus „Mein Stammbaum“ (die Familien stehen per FAMC/FAMS darin)
     */
    private const IGNORED_INDI = ['_UPD', 'FATH', 'MOTH', 'BROT', 'SIST'];

    /**
     * Unter-Tags, die der Import auswertet (oder bewusst übergeht) – unterhalb der Datensatz-Ebene.
     * Alles andere wird mit Anzahl in „skipped“ gemeldet.
     */
    private const KNOWN_SUBTAGS = [
        'ABBR', 'ADDR', 'ADR1', 'ADR2', 'ADR3', 'AGE', 'AGNC', 'ASSO', '_ASSO', 'AUTH', 'CALN', 'CAUS', 'CHAN',
        'CHIL', 'CITY', 'CTRY', 'DATA', 'DATE', 'EMAIL', 'FAMC', 'FAMS', 'FAX', 'GIVN', '_GOV', '_GOVTYPE', 'HUSB', 'LANG', 'LATI',
        '_AKA', '_LINK', '_LOC', 'LONG', 'MAP', '_MARNM', '_MARNM_SURN', 'MEDI', 'NAME', 'NICK', 'NOTE', 'NPFX', 'NSFX', 'OBJE', 'PAGE', 'PEDI', 'PHON',
        'PHRASE', 'PLAC', 'POST', 'PUBL', 'RELA', 'RELI', 'REPO', 'RESN', 'RIN', 'ROLE', 'SEX', 'SOUR', 'SPFX', 'SURN',
        'TEXT', 'TIME', 'TITL', 'TYPE', '_UID', 'UID', 'WIFE', 'WWW', ...self::NAMED_PERSON_TAGS,
        'QUAY', '_RUFNAME', '_FORMERNAME', '_FREL', '_MREL',
        // bewusst übergangen: Bundesland (Adressen an Ereignissen werden nicht gespeichert), Änderungszeitpunkt,
        // MyHeritage-Interna (EVEN/ROLE an Quellenangaben, _TYPE/_MEDI an Quellen, PRIN an Notizen)
        'STAE', '_UPD', 'EVEN', '_TYPE', '_MEDI', 'PRIN',
    ];
    /** Tags, deren Unterbaum nicht geprüft wird (CHAN wird übergangen, OBJE meldet Fehlschläge selbst) */
    private const OPAQUE_TAGS = ['CHAN', 'OBJE'];

    private array $notes     = [];   // NOTE-xref  → Text
    private array $repos     = [];   // REPO-xref  → Datensatz (Knoten)
    private array $repoIds   = [];   // REPO-xref  → repositories.id (beim ersten Bedarf angelegt)
    private array $repoNames = [];   // normalisierter Name → repositories.id (Wiederverwendung)
    private array $sources   = [];   // SOUR-xref  → id
    private array $persons   = [];   // INDI-xref  → id
    private array $skipped   = [];   // Tag → Anzahl

    // Datensätze der Datei als Rohtext, je Datensatz eine Zeichenkette. Zerlegt wird erst bei Bedarf
    // (record()), sonst bräuchte ein Baum mit 50.000 Personen weit über 1 GB Speicher.
    private array $recTexts  = [];   // Index → Text des Datensatzes („0 @I1@ INDI …“ samt Unterzeilen)
    private array $recTags   = [];   // Index → Tag der Ebene 0 (INDI, FAM, SOUR …)
    private array $parsed    = [];   // Index → true, sobald unlesbare Zeilen darin gezählt sind
    private array $warnings  = [];
    private array $conflicts = [];
    private array $stats     = [
        'persons' => 0, 'names' => 0, 'events' => 0, 'citations' => 0,
        'families' => 0, 'sources' => 0, 'invalid_dates' => 0, 'family_events' => 0,
        // nur beim Import in einen bestehenden Baum
        'repositories' => 0,
        'persons_merged' => 0, 'families_merged' => 0, 'sources_reused' => 0,
        'names_added' => 0, 'events_added' => 0, 'events_completed' => 0, 'conflicts' => 0,
        'media' => 0, 'media_reused' => 0, 'associations' => 0,
    ];

    private bool  $merge        = false;
    private array $mapping      = [];   // xref → vorhandene Personen-ID
    private array $mergedIds    = [];   // vorhandene Personen-ID → true
    private array $sourceTitles = [];   // normalisierter Titel → Quellen-ID
    private array $displayNames = [];   // INDI-xref → „Nachname, Vorname“ (für Meldungen)
    private array $famIds       = [];   // FAM-xref  → Familien-ID (für FAMC/PEDI)
    private array $placeInfo    = [];   // Ortsangabe → [lat, lon, gov, _LOC-xref] aus PLAC.MAP/_GOV/_LOC
    private array $locs         = [];   // _LOC-xref → Datensatz (GEDCOM-L-Ortsdatensatz)
    private ?PlaceIndex $placeIndex = null;   // Ortsangabe → places.id (fehlende Orte werden angelegt)
    private array $childPedigree = [];  // „FAM-xref|INDI-xref“ → Abstammungsart aus FAM.CHIL._FREL/_MREL
    private array $pendingAssos = [];   // [Tabelle, Besitzer-ID, INDI-xref, ROLE/RELA, PHRASE, Notiz] – nach allen Personen auflösen

    private int   $treeId       = 0;
    private ?ZipArchive $zip    = null;   // hochgeladenes ZIP-Archiv (Quelle der Mediendateien)
    private ?array $zipIndex    = null;   // Pfadende (klein) → Eintragsname
    private array $objes        = [];   // OBJE-xref → Datensatz (Knoten)
    private array $mediaIds     = [];   // OBJE-xref bzw. Inline-Schlüssel → media.id | false
    private array $mediaMime    = [];   // media.id → MIME-Typ
    private int   $mediaMissing = 0;    // Medien, deren Datei nicht gefunden wurde
    private ?MediaLinks $links  = null;

    public function __construct(private readonly PDO $db) {}

    /** Neue Personen-ID zu einer Kennung aus der Datei (@I12@) – nach import() */
    public function importedPersonId(string $xref): ?int
    {
        return $this->persons[$xref] ?? null;
    }

    // ── Öffentliche Schnittstelle ─────────────────────────

    /**
     * Sucht mögliche Dubletten zwischen Datei und Baum, ohne etwas zu schreiben.
     * @return array{header: array, persons_in_file: int, existing_persons: int, candidates: list<array>}
     */
    public function analyze(string $raw, int $treeId): array
    {
        $header = $this->load($raw);

        $fileProfiles     = $this->fileProfiles();
        $existingProfiles = $this->existingProfiles($treeId);
        $matches          = (new DuplicateMatcher())->match($fileProfiles, $existingProfiles);

        // Vorauswahl: sichere Treffer, beste zuerst; jede vorhandene Person höchstens einmal
        $pairs = [];
        foreach ($matches as $xref => $cands) {
            foreach ($cands as $c) if ($c['sure']) $pairs[] = [$c['score'], $xref, $c['key']];
        }
        usort($pairs, fn($a, $b) => $b[0] <=> $a[0]);
        $preselect = $usedExisting = [];
        foreach ($pairs as [, $xref, $key]) {
            if (isset($preselect[$xref]) || isset($usedExisting[$key])) continue;
            $preselect[$xref]   = $key;
            $usedExisting[$key] = true;
        }

        $candidates = [];
        foreach ($matches as $xref => $cands) {
            $candidates[] = [
                'xref'       => $xref,
                'file'       => $fileProfiles[$xref]['display'],
                'best'       => $cands[0]['score'],
                'preselect'  => $preselect[$xref] ?? null,
                'candidates' => array_map(fn($c) => [
                    'id'    => $c['key'],
                    'score' => $c['score'],
                    'sure'  => $c['sure'],
                    ...$existingProfiles[$c['key']]['display'],
                ], $cands),
            ];
        }
        usort($candidates, fn($a, $b) => $b['best'] <=> $a['best']);

        return [
            'header'           => $header,
            'persons_in_file'  => count($fileProfiles),
            'existing_persons' => count($existingProfiles),
            'candidates'       => $candidates,
        ];
    }

    /**
     * Importiert die Datei in den Baum.
     * @param array $mapping xref → vorhandene Personen-ID (nur bei $merge)
     * @param bool  $merge   true = Import in einen bestehenden Baum (Quellen wiederverwenden, Familien zusammenführen)
     * @return array{stats: array, skipped: array, warnings: array, conflicts: array, header: array}
     */
    public function import(string $raw, int $treeId, array $mapping = [], bool $merge = false, ?string $zipPath = null): array
    {
        $header = $this->load($raw);
        unset($raw);   // nur die Kopie in diesem Aufruf; die Datensätze stehen in $recTexts

        $this->treeId = $treeId;
        $this->links  = new MediaLinks($this->db, $treeId);
        if ($zipPath !== null) {
            $zip = new ZipArchive();
            if ($zip->open($zipPath) === true) $this->zip = $zip;
        }

        $this->merge   = $merge;
        $this->mapping = $mapping;
        $this->placeIndex = new PlaceIndex($this->db);
        if ($merge) {
            $stmt = $this->db->prepare('SELECT id, title FROM sources WHERE tree_id = ?');
            $stmt->execute([$treeId]);
            foreach ($stmt->fetchAll() as $s) {
                $this->sourceTitles[DuplicateMatcher::normalize($s['title'])] ??= (int) $s['id'];
            }
        }

        if ($merge) {
            $stmt = $this->db->prepare('SELECT id, name FROM repositories WHERE tree_id = ?');
            $stmt->execute([$treeId]);
            foreach ($stmt->fetchAll() as $rp) $this->repoNames[DuplicateMatcher::normalize($rp['name'])] ??= (int) $rp['id'];
        }
        foreach ($this->records('SOUR') as $r) $this->importSource($r, $treeId);
        // Archive ohne Quelle trotzdem übernehmen
        foreach ($this->repos as $xref => $node) $this->repositoryFor($xref, $treeId);
        foreach ($this->records('INDI') as $r) $this->importPerson($r, $treeId);
        $this->insertAssociations();
        foreach ($this->records('FAM') as $r)  $this->importFamily($r, $treeId);
        $this->insertAssociations();   // Beteiligte an Familienereignissen (Trauzeugen …)
        foreach ($this->records('INDI') as $r) $this->applyPedigree($r);
        $this->importPlaces($treeId);
        // Medien, auf die nichts verweist, trotzdem in die Mediathek übernehmen
        foreach (array_keys($this->objes) as $xref) $this->mediaFor(['tag' => 'OBJE', 'xref' => null, 'value' => $xref, 'children' => []]);
        $this->zip?->close();

        $this->countUnused();
        if ($this->mediaMissing > 0) {
            $this->warnings[] = $zipPath === null
                ? "Die Datei verweist auf {$this->mediaMissing} Medien (OBJE). Medien werden nur übernommen, wenn sie "
                  . 'zusammen mit der GEDCOM-Datei als ZIP-Archiv hochgeladen werden.'
                : "{$this->mediaMissing} Medien wurden nicht übernommen, weil ihre Datei im ZIP-Archiv fehlt.";
        }

        if ($this->stats['invalid_dates'] > 0) {
            $this->warnings[] = "{$this->stats['invalid_dates']} Datumsangaben entsprechen nicht dem GEDCOM-Format "
                              . 'und wurden unverändert übernommen. Beim nächsten Bearbeiten der Person müssen sie korrigiert werden.';
        }
        $this->stats['conflicts'] = count($this->conflicts);

        arsort($this->skipped);
        return [
            'stats'     => $this->stats,
            'skipped'   => $this->skipped,
            'warnings'  => $this->warnings,
            'conflicts' => array_slice($this->conflicts, 0, 500),
            'header'    => $header,
        ];
    }

    // ── Einlesen ──────────────────────────────────────────

    /**
     * Datei in Datensätze teilen (Rohtext, siehe $recTexts) und Kopfdaten, Notizen, Archive usw. einlesen.
     * @return array Kopfdaten
     */
    private function load(string $raw): array
    {
        [$text, $charset] = $this->decode($raw);
        unset($raw);
        // an jeder Zeile der Ebene 0 teilen – die Zeilenumbrüche bleiben im Text, parse() kommt damit zurecht
        $this->recTexts = preg_split('/(?:\r\n|\r|\n)(?=[ \t]*0[ \t])/', $text);
        unset($text);
        $this->recTags = $this->parsed = [];
        foreach ($this->recTexts as $i => $chunk) {
            $this->recTags[$i] = preg_match('/^\s*0\s+(?:@[^@\s]+@\s+)?([A-Za-z0-9_]+)/', $chunk, $m) ? strtoupper($m[1]) : '';
        }

        $first = array_key_first(array_filter($this->recTags, fn($t) => $t !== ''));
        $head  = $first !== null ? $this->record($first) : null;
        if (!$head || $head['tag'] !== 'HEAD') {
            throw new InvalidArgumentException('Die Datei ist keine GEDCOM-Datei (Kopfzeile „0 HEAD“ fehlt).');
        }

        $header = [
            'version' => $this->value($this->child($this->child($head, 'GEDC'), 'VERS')),
            'source'  => $this->value($this->child($this->child($head, 'SOUR'), 'NAME'))
                         ?: $this->value($this->child($head, 'SOUR')),
            'charset' => $charset,
        ];

        foreach ($this->records('NOTE', 'REPO', '_LOC', 'OBJE') as $r) {
            if (!$r['xref']) continue;
            if ($r['tag'] === 'NOTE') $this->notes[$r['xref']] = $this->fullText($r);
            if ($r['tag'] === 'REPO') $this->repos[$r['xref']] = $r;
            if ($r['tag'] === '_LOC') $this->locs[$r['xref']]  = $r;
            if ($r['tag'] === 'OBJE') $this->objes[$r['xref']] = $r;
        }

        return $header;
    }

    /** Datensatz Nr. $i als Knotenbaum (INDI/FAM vereinheitlicht); null bei einem leeren Abschnitt */
    private function record(int $i): ?array
    {
        $r = $this->parse($this->recTexts[$i], !isset($this->parsed[$i]))[0] ?? null;
        $this->parsed[$i] = true;
        return match ($r['tag'] ?? null) {
            null    => null,
            'INDI'  => $this->normalizeIndi($r),
            'FAM'   => $this->normalizeFam($r),
            default => $r,
        };
    }

    /** Datensätze mit diesen Tags (ohne Angabe: alle) nacheinander als Knotenbaum */
    private function records(string ...$tags): Generator
    {
        foreach ($this->recTags as $i => $tag) {
            if ($tags && !in_array($tag, $tags, true)) continue;
            if ($r = $this->record($i)) yield $r;
        }
    }

    /** @return array{0: string, 1: string} UTF-8-Text und erkannter Zeichensatz */
    private function decode(string $raw): array
    {
        if (str_starts_with($raw, "\xEF\xBB\xBF")) return [substr($raw, 3), 'UTF-8'];
        if (str_starts_with($raw, "\xFF\xFE"))     return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16LE'), 'UTF-16'];
        if (str_starts_with($raw, "\xFE\xFF"))     return [mb_convert_encoding(substr($raw, 2), 'UTF-8', 'UTF-16BE'), 'UTF-16'];

        $declared = preg_match('/^1\s+CHAR\s+(\S+)/m', substr($raw, 0, 4000), $m) ? strtoupper($m[1]) : 'UTF-8';

        if (mb_check_encoding($raw, 'UTF-8')) return [$raw, $declared];

        // Manche Programme (z. B. MyHeritage) teilen bei CONC ein Mehrbyte-Zeichen auf zwei Zeilen auf –
        // CONC-Zeilen vorab zusammenfügen (genau wie beim Einlesen), dann ist das Zeichen wieder ganz
        $joined = preg_replace('/\r?\n[ \t]*\d+[ \t]+CONC(?: |(?=\r?\n|$))/', '', $raw);
        if (mb_check_encoding($joined, 'UTF-8')) return [$joined, $declared];
        if (in_array($declared, ['UTF-8', 'UTF8', 'UNICODE'], true)
            && preg_match_all('/[\xC3\xC2][\x80-\xBF]/', $joined) > 10) {   // erkennbar UTF-8 mit einzelnen kaputten Bytes
            $this->warnings[] = 'Die Datei enthält einzelne ungültige UTF-8-Zeichen; sie wurden durch „?“ ersetzt.';
            return [mb_scrub($joined, 'UTF-8'), $declared];
        }

        if ($declared === 'ANSEL') {
            $this->warnings[] = 'Die Datei verwendet den Zeichensatz ANSEL. Sonderzeichen wurden näherungsweise umgewandelt '
                              . 'und sollten stichprobenartig geprüft werden.';
        }
        return [mb_convert_encoding($raw, 'UTF-8', 'Windows-1252'), $declared];
    }

    /**
     * Zerlegt Text in verschachtelte Knoten [tag, xref, value, children].
     * $countSkips: unlesbare Zeilen melden (nur beim ersten Zerlegen eines Datensatzes, sonst doppelt gezählt)
     */
    private function parse(string $text, bool $countSkips = true): array
    {
        $nodes = [];   // flache Liste; 'kids' enthält Indizes
        $roots = [];
        $stack = [];   // $stack[$level] = Index des letzten Knotens auf dieser Ebene

        foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
            if (trim($line) === '') continue;
            if (!preg_match('/^\s*(\d+)\s+(?:(@[^@\s]+@)\s+)?([A-Za-z0-9_]+)(?: (.*))?$/', $line, $m)) {
                // Zeile ohne Ebenennummer: echter Zeilenumbruch im Text (z. B. MyHeritage) → Fortsetzung des vorigen Werts
                if ($nodes && !preg_match('/^\s*\d+(\s|$)/', $line)) {
                    $nodes[array_key_last($nodes)]['value'] .= "\n" . trim($line);
                } elseif ($countSkips) {
                    $this->skip('(unlesbare Zeile)');
                }
                continue;
            }
            $level = min((int) $m[1], count($stack));   // Ebenensprünge abfangen
            $xref  = $m[2] !== '' ? $m[2] : null;
            $tag   = strtoupper($m[3]);
            $value = $m[4] ?? '';
            if (str_starts_with($value, '@@')) $value = substr($value, 1);   // maskiertes „@“

            // Fortsetzungszeilen an den Wert des übergeordneten Knotens anhängen
            if ($tag === 'CONC' || $tag === 'CONT') {
                if ($level > 0) {
                    $nodes[$stack[$level - 1]]['value'] .= ($tag === 'CONT' ? "\n" : '') . $value;
                }
                continue;
            }

            $idx     = count($nodes);
            $nodes[] = ['tag' => $tag, 'xref' => $xref, 'value' => $value, 'kids' => []];
            if ($level === 0) $roots[] = $idx;
            else              $nodes[$stack[$level - 1]]['kids'][] = $idx;

            $stack         = array_slice($stack, 0, $level);
            $stack[$level] = $idx;
        }

        $build = function (int $i) use (&$build, &$nodes): array {
            $n = $nodes[$i];
            return ['tag' => $n['tag'], 'xref' => $n['xref'], 'value' => $n['value'],
                    'children' => array_map($build, $n['kids'])];
        };
        return array_map($build, $roots);
    }

    private function child(?array $node, string $tag): ?array
    {
        foreach ($node['children'] ?? [] as $c) if ($c['tag'] === $tag) return $c;
        return null;
    }

    private function children(?array $node, string $tag): array
    {
        return array_values(array_filter($node['children'] ?? [], fn($c) => $c['tag'] === $tag));
    }

    private function value(?array $node): string
    {
        return trim($node['value'] ?? '');
    }

    /** Text eines NOTE-Knotens, Verweise (@N1@) aufgelöst */
    private function noteText(array $node): string
    {
        $v = $this->value($node);
        if (preg_match('/^@[^@]+@$/', $v)) return $this->notes[$v] ?? '';
        return $v;
    }

    private function fullText(array $node): string
    {
        return trim($node['value'] ?? '');
    }

    /**
     * Beteiligte nur als Name, ohne Verweis auf eine Person (z. B. Ahnenblatt „2 _WITN Otto Mustermann, …“,
     * „2 _GODP Max Mustermann“): werden als Zeile in die Notiz des Ereignisses übernommen.
     * Mit Verweis („_WITN @I12@“) werden sie zur verknüpften Person (collectAssociations).
     */
    private const NAMED_PERSON_TAGS = ['_WITN', '_WITNESS', '_GODP', '_GODPARENT'];
    private const NAMED_PERSON_LABELS = ['_WITN' => 'Zeugen', '_WITNESS' => 'Zeugen', '_GODP' => 'Paten', '_GODPARENT' => 'Paten'];

    /** Notiz eines Ereignisses: NOTE-Texte und Beteiligte, die nur als Name angegeben sind */
    private function eventNotes(array $event): ?string
    {
        $lines = [];
        foreach (self::NAMED_PERSON_LABELS as $tag => $label) {
            foreach ($this->children($event, $tag) as $n) {
                $v = $this->fullText($n);
                if ($v !== '' && !preg_match('/^@[^@]+@$/', $v)) $lines[$label][] = $v;
            }
        }
        $named = implode("\n", array_map(fn($label, $names) => "$label: " . implode(', ', $names), array_keys($lines), $lines));
        $notes = $this->notesOf($event);
        if ($named === '') return $notes;
        return $notes === null ? $named : "$notes\n\n$named";
    }

    private function notesOf(array $node): ?string
    {
        $parts = array_filter(array_map(fn($n) => $this->noteText($n), $this->children($node, 'NOTE')));
        return $parts ? implode("\n\n", $parts) : null;
    }

    /**
     * Zählt alle Tags der Datei, die nicht übernommen werden – auf jeder Ebene.
     * Direkt unter INDI/FAM gelten die dort verarbeiteten Tags, darunter KNOWN_SUBTAGS.
     * Kopf- und Einsenderdaten werden nicht geprüft; unter einem nicht übernommenen Tag wird nicht weiter gezählt.
     */
    private function countUnused(): void
    {
        $sub  = array_flip(self::KNOWN_SUBTAGS);
        $indi = array_flip([...GedcomTags::personTypes(), ...self::INDI_HANDLED]);
        $fam  = array_flip([...self::FAMILY_EVENTS, ...self::FAM_HANDLED]);

        $walk = function (array $node, array $known) use (&$walk, $sub): void {
            foreach ($node['children'] as $c) {
                if (!isset($known[$c['tag']]))                         $this->skip($c['tag']);
                elseif (!in_array($c['tag'], self::OPAQUE_TAGS, true)) $walk($c, $sub);
            }
        };
        foreach ($this->records() as $r) {
            match ($r['tag']) {
                'HEAD', 'TRLR', 'SUBM', 'SUBN'         => null,
                'INDI'                                 => $walk($r, $indi),
                'FAM'                                  => $walk($r, $fam),
                'NOTE', 'REPO', 'SOUR', '_LOC'         => $walk($r, $sub),
                'OBJE'                                 => null,   // Fehlschläge meldet mediaFor()
                default                                => $this->skip($r['tag']),
            };
        }
    }

    // ── Medien (OBJE) ─────────────────────────────────────

    /**
     * OBJE-Verweise eines Knotens verknüpfen – auch die an seinen Quellenangaben (z. B. Scan des
     * Kirchenbucheintrags an der Taufe). Ohne _PRIM wird bei Personen das erste Bild zum Hauptbild.
     */
    private function linkMedia(string $kind, int $ownerId, array $node): void
    {
        $objes = $this->children($node, 'OBJE');
        foreach ($this->children($node, 'SOUR') as $s) array_push($objes, ...$this->children($s, 'OBJE'));
        if (!$objes) return;

        $items = [];
        foreach ($objes as $o) {
            $id = $this->mediaFor($o);
            if ($id === null) continue;
            $rec  = preg_match('/^@[^@]+@$/', $this->value($o)) ? ($this->objes[$this->value($o)] ?? $o) : $o;
            $prim = strtoupper($this->value($this->child($o, '_PRIM')) ?: $this->value($this->child($rec, '_PRIM')));
            $items[] = ['id' => $id, 'is_primary' => $prim === 'Y' || $prim === 'YES'];
        }
        if (!$items) return;
        if ($kind === 'person' && !array_filter(array_column($items, 'is_primary'))) {
            foreach ($items as &$m) {
                if (str_starts_with($this->mediaMime[$m['id']] ?? '', 'image/')) { $m['is_primary'] = true; break; }
            }
            unset($m);
        }
        $this->links->append($kind, $ownerId, $items);
    }

    /** Medium zu einem OBJE-Verweis (@O1@) oder eingebetteten OBJE; null, wenn die Datei fehlt */
    private function mediaFor(array $o): ?int
    {
        $ref = $this->value($o);
        if (preg_match('/^@[^@]+@$/', $ref)) {
            $key = $ref;
            $rec = $this->objes[$ref] ?? null;
        } else {
            $key = 'inline:' . md5(serialize($o['children']));
            $rec = $o;
        }
        if (!array_key_exists($key, $this->mediaIds)) {
            $this->mediaIds[$key] = $rec ? ($this->createMedia($rec) ?? false) : false;
            if ($this->mediaIds[$key] === false) {
                $this->mediaMissing++;
                $this->skip('OBJE');
            }
        }
        return $this->mediaIds[$key] ?: null;
    }

    /** Datei aus dem ZIP-Archiv in die Mediathek übernehmen (gleiche Datei im Baum wird wiederverwendet) */
    private function createMedia(array $rec): ?int
    {
        if (!$this->zip) return null;
        foreach ($this->children($rec, 'FILE') as $file) {
            $entry = $this->zipEntry($this->value($file));
            if ($entry === null) continue;

            $tmp = tempnam(sys_get_temp_dir(), 'avunculus-obje-');
            $in  = $this->zip->getStream($entry);
            $out = fopen($tmp, 'wb');
            if (!$in || !$out) { @unlink($tmp); continue; }
            stream_copy_to_stream($in, $out);
            fclose($in); fclose($out);

            $checksum = sha1_file($tmp);
            $stmt = $this->db->prepare('SELECT id, mime_type FROM media WHERE tree_id = ? AND checksum = ? LIMIT 1');
            $stmt->execute([$this->treeId, $checksum]);
            if ($found = $stmt->fetch()) {
                @unlink($tmp);
                $this->stats['media_reused']++;
                $this->mediaMime[(int) $found['id']] = $found['mime_type'];
                return (int) $found['id'];
            }

            $name   = basename(str_replace('\\', '/', $entry));
            $stored = MediaStore::store($this->treeId, $tmp, $name);
            $title  = $this->fullText($this->child($file, 'TITL') ?? $this->child($rec, 'TITL') ?? []);
            $date   = $this->value($this->child($rec, 'DATE')) ?: $this->value($this->child($rec, '_DATE'));
            $this->db->prepare('
                INSERT INTO media (tree_id, title, file_name, original_name, mime_type, file_size, width, height, checksum, media_date, notes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ')->execute([
                $this->treeId, self::cut($title, 300), $stored['file_name'], self::cut($name, 255), $stored['mime_type'],
                $stored['file_size'], $stored['width'], $stored['height'], $stored['checksum'],
                self::cut($date !== '' ? GedcomDate::normalize($date) : null, 100), self::cut($this->notesOf($rec), 65000),
            ]);
            $id = (int) $this->db->lastInsertId();
            $this->mediaMime[$id] = $stored['mime_type'];
            $this->stats['media']++;
            return $id;
        }
        return null;
    }

    /**
     * Eintrag im ZIP-Archiv zu einem FILE-Pfad. Programme schreiben absolute Windows-Pfade
     * („C:\Fotos\Oma.jpg“), relative Pfade oder URLs – gesucht wird daher über das Pfadende,
     * vom längsten zum kürzesten (zuletzt nur der Dateiname).
     */
    private function zipEntry(string $path): ?string
    {
        if ($this->zipIndex === null) {
            $this->zipIndex = [];
            for ($i = 0; $i < $this->zip->numFiles; $i++) {
                $name = (string) $this->zip->getNameIndex($i);
                if (str_ends_with($name, '/') || str_starts_with($name, '__MACOSX/') || str_contains($name, '/._')) continue;
                $parts = explode('/', mb_strtolower(str_replace('\\', '/', $name)));
                for ($k = 0, $n = count($parts); $k < $n; $k++) {
                    $this->zipIndex[implode('/', array_slice($parts, $k))] ??= $name;
                }
            }
        }
        $path = trim($path);
        if ($path === '') return null;
        foreach (array_unique([$path, rawurldecode($path)]) as $p) {
            $p = preg_replace('#^file:(//)?#i', '', str_replace('\\', '/', $p));
            $parts = array_values(array_filter(explode('/', mb_strtolower($p)), fn($x) => $x !== '' && $x !== '.'));
            for ($k = 0, $n = count($parts); $k < $n; $k++) {
                $tail = implode('/', array_slice($parts, $k));
                if (isset($this->zipIndex[$tail])) return $this->zipIndex[$tail];
            }
        }
        return null;
    }

    private function skip(string $tag): void
    {
        $this->skipped[$tag] = ($this->skipped[$tag] ?? 0) + 1;
    }

    private static function cut(?string $s, int $max): ?string
    {
        if ($s === null) return null;
        $s = trim($s);
        return $s === '' ? null : mb_substr($s, 0, $max);
    }

    private function firstPlace(array $event): ?string
    {
        $plac  = $this->child($event, 'PLAC');
        $place = self::cut(strtok($this->value($plac), "\n") ?: null, 200);
        if ($place !== null && !isset($this->placeInfo[$place])) {
            $map = $this->child($plac, 'MAP');
            $info = [
                'lat' => self::coordinate($this->value($this->child($map, 'LATI')), 'N', 'S'),
                'lon' => self::coordinate($this->value($this->child($map, 'LONG')), 'E', 'W'),
                'gov' => self::cut($this->value($this->child($plac, '_GOV')), 60),
                'loc' => $this->value($this->child($plac, '_LOC')) ?: null,
            ];
            if (array_filter($info)) $this->placeInfo[$place] = $info;
        }
        return $place;
    }

    /** Ort zur Ortsangabe (fehlende Orte werden angelegt) */
    private function placeRef(?string $place): ?int
    {
        return $place !== null ? $this->placeIndex->resolve($place) : null;
    }

    /** „N48.7283“ / „W3.5“ / „-3.5“ → Gradzahl; null bei ungültigen Angaben */
    private static function coordinate(string $v, string $pos, string $neg): ?float
    {
        if (!preg_match('/^([NSEW])?\s*(-?\d+(?:[.,]\d+)?)$/i', trim($v), $m)) return null;
        $deg = (float) str_replace(',', '.', $m[2]);
        if (strtoupper($m[1]) === $neg) $deg = -$deg;
        return abs($deg) <= ($pos === 'N' ? 90 : 180) ? $deg : null;
    }

    // ── Orte ──────────────────────────────────────────────

    /**
     * Koordinaten, GOV-Kennungen und – aus GEDCOM-L-Ortsdatensätzen (_LOC) – Typ, weitere Namen
     * und Angaben der übergeordneten Orte übernehmen. Vorhandene Angaben bleiben unverändert.
     */
    private function importPlaces(int $treeId): void
    {
        if (!$this->placeInfo) return;
        $index = $this->placeIndex;
        $fill  = $this->db->prepare('UPDATE places SET latitude = COALESCE(latitude, ?), longitude = COALESCE(longitude, ?),
                                     gov_id = COALESCE(gov_id, ?), place_type = COALESCE(place_type, ?) WHERE id = ?');
        $done  = [];

        foreach ($this->placeInfo as $place => $info) {
            $id = $index->resolve((string) $place);
            if ($id === null) continue;
            $hasMap = $info['lat'] !== null && $info['lon'] !== null;
            $fill->execute([$hasMap ? $info['lat'] : null, $hasMap ? $info['lon'] : null, $info['gov'], null, $id]);

            // _LOC-Kette nach oben gehen und den Teilen der Ortsangabe über den Namen zuordnen;
            // Ebenen, die in der Ortsangabe fehlen (z. B. Regierungsbezirk), werden übersprungen
            $path = $index->path($id);
            $i    = count($path) - 1;
            $seen = [];
            for ($loc = $info['loc']; $loc && $i >= 0 && isset($this->locs[$loc]) && !isset($seen[$loc]); ) {
                $seen[$loc] = true;
                $rec   = $this->locs[$loc];
                $names = $this->children($rec, 'NAME');
                $match = array_filter($names, fn($n) => mb_strtolower($this->value($n)) === mb_strtolower($path[$i]['name']));
                if ($match) {
                    $nodeId = $path[$i]['id'];
                    if (!isset($done[$nodeId])) {
                        $done[$nodeId] = true;
                        $this->applyLoc($rec, $nodeId, $fill, array_key_first($match));
                    }
                    $i--;
                }
                $parents = $this->children($rec, '_LOC');
                $loc = $parents ? $this->value(end($parents)) : null;   // bei mehreren der zuletzt genannte (aktuelle)
            }
        }
    }

    private function applyLoc(array $rec, int $placeId, PDOStatement $fill, int $matched): void
    {
        $map  = $this->child($rec, 'MAP');
        $lat  = self::coordinate($this->value($this->child($map, 'LATI')), 'N', 'S');
        $lon  = self::coordinate($this->value($this->child($map, 'LONG')), 'E', 'W');
        $type = preg_replace('/^\d+\s+/', '', $this->value($this->child($rec, 'TYPE')));   // GOV-Typnummer („39 Ort“) weglassen
        $fill->execute([$lat !== null && $lon !== null ? $lat : null, $lat !== null && $lon !== null ? $lon : null,
                        self::cut($this->value($this->child($rec, '_GOV')), 60), self::cut($type, 60), $placeId]);

        $stmt = $this->db->prepare('SELECT LOWER(name) FROM place_names WHERE place_id = ?');
        $stmt->execute([$placeId]);
        $known = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
        $ins   = $this->db->prepare('INSERT INTO place_names (place_id, name, language, date_period, sort_order) VALUES (?, ?, ?, ?, ?)');
        $order = count($known);
        foreach ($this->children($rec, 'NAME') as $k => $n) {
            $name = self::cut($this->value($n), 200);
            if ($k === $matched || $name === null || isset($known[mb_strtolower($name)])) continue;
            $known[mb_strtolower($name)] = true;
            $ins->execute([$placeId, $name, self::cut($this->value($this->child($n, 'LANG')), 40),
                           self::cut($this->value($this->child($n, 'DATE')), 100), min($order++, 255)]);
        }
    }

    // ── Profile für die Dublettenprüfung ──────────────────

    /** Namensvarianten [given, surname] normalisiert – mit und ohne Namenszusatz (von, van …) */
    private static function nameKeys(?string $given, ?string $prefix, ?string $surname): array
    {
        $g    = DuplicateMatcher::normalize($given);
        $full = DuplicateMatcher::normalize(trim(($prefix ?? '') . ' ' . ($surname ?? '')));
        $bare = DuplicateMatcher::normalize($surname);
        $keys = [[$g, $full]];
        if ($bare !== $full) $keys[] = [$g, $bare];
        return $keys;
    }

    private static function displayName(?string $given, ?string $prefix, ?string $surname): string
    {
        $sur = trim(($prefix ?? '') . ' ' . ($surname ?? ''));
        return implode(', ', array_filter([$sur, trim((string) $given)])) ?: '–';
    }

    /** Profile aller Personen der Datei (Schlüssel = xref) */
    private function fileProfiles(): array
    {
        $parents = $spouses = [];
        foreach ($this->records('FAM') as $r) {
            $h = $this->value($this->child($r, 'HUSB')) ?: null;
            $w = $this->value($this->child($r, 'WIFE')) ?: null;
            foreach ($this->children($r, 'CHIL') as $c) $parents[$this->value($c)] ??= [$h, $w];
            if ($h && $w) { $spouses[$h][] = $w; $spouses[$w][] = $h; }
        }

        $profiles = [];
        foreach ($this->records('INDI') as $r) {
            if (!$r['xref']) continue;

            $names = [];
            $display = null;
            foreach ($this->nameNodes($r) as $n) {
                $p = $this->parseName($n);
                array_push($names, ...self::nameKeys($p['givn'], $p['spfx'], $p['surn']));
                $display ??= self::displayName($p['givn'], $p['spfx'], $p['surn']);
            }

            $birth = $this->child($r, 'BIRT') ?? $this->child($r, 'CHR') ?? $this->child($r, 'BAPM');
            $death = $this->child($r, 'DEAT') ?? $this->child($r, 'BURI');
            $bDate = $birth ? $this->value($this->child($birth, 'DATE')) : '';
            $dDate = $death ? $this->value($this->child($death, 'DATE')) : '';
            $bPlace = $birth ? (string) $this->firstPlace($birth) : '';
            $sex   = strtoupper(substr($this->value($this->child($r, 'SEX')), 0, 1));

            $profiles[$r['xref']] = [
                'key'         => $r['xref'],
                'sex'         => in_array($sex, ['M', 'F', 'X'], true) ? $sex : 'U',
                'names'       => $names,
                'birth'       => $bDate !== '' ? GedcomDate::extractYear($bDate) : null,
                'death'       => $dDate !== '' ? GedcomDate::extractYear($dDate) : null,
                'birth_place' => DuplicateMatcher::placeKey($bPlace),
                'display'     => [
                    'name'        => $display ?? '–',
                    'sex'         => in_array($sex, ['M', 'F', 'X'], true) ? $sex : 'U',
                    'birth'       => $bDate !== '' ? GedcomDate::normalize($bDate) : null,
                    'death'       => $dDate !== '' ? GedcomDate::normalize($dDate) : null,
                    'birth_place' => $bPlace ?: null,
                ],
            ];
        }

        return $this->addRelations($profiles, $parents, $spouses);
    }

    /** Profile aller vorhandenen Personen des Baums (Schlüssel = Personen-ID) */
    private function existingProfiles(int $treeId): array
    {
        $q = function (string $sql) use ($treeId): array {
            $stmt = $this->db->prepare($sql);
            $stmt->execute([$treeId]);
            return $stmt->fetchAll();
        };

        $profiles = [];
        foreach ($q('SELECT id, sex FROM persons WHERE tree_id = ?') as $p) {
            $profiles[(int) $p['id']] = [
                'key' => (int) $p['id'], 'sex' => $p['sex'], 'names' => [],
                'birth' => null, 'death' => null, 'birth_place' => '',
                'display' => ['name' => '–', 'sex' => $p['sex'], 'birth' => null, 'death' => null, 'birth_place' => null],
            ];
        }

        foreach ($q('
            SELECT n.person_id, n.given_name, n.surname_prefix, n.surname
            FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ? ORDER BY n.person_id, n.sort_order, n.id
        ') as $n) {
            $id = (int) $n['person_id'];
            array_push($profiles[$id]['names'], ...self::nameKeys($n['given_name'], $n['surname_prefix'], $n['surname']));
            if ($profiles[$id]['display']['name'] === '–') {
                $profiles[$id]['display']['name'] = self::displayName($n['given_name'], $n['surname_prefix'], $n['surname']);
            }
        }

        // erstes Geburts-/Taufereignis bzw. Sterbe-/Begräbnisereignis
        $rank = ['BIRT' => 0, 'BAPM' => 1, 'DEAT' => 0, 'BURI' => 1];
        $best = [];
        foreach ($q('
            SELECT e.person_id, e.event_type, e.event_date, pl.full_name AS event_place
            FROM person_events e JOIN persons p ON p.id = e.person_id LEFT JOIN places pl ON pl.id = e.place_id
            WHERE p.tree_id = ? AND e.event_type IN (\'BIRT\', \'BAPM\', \'DEAT\', \'BURI\')
            ORDER BY e.person_id, e.sort_order, e.id
        ') as $e) {
            $id   = (int) $e['person_id'];
            $kind = in_array($e['event_type'], ['BIRT', 'BAPM'], true) ? 'birth' : 'death';
            if (isset($best[$id][$kind]) && $best[$id][$kind] <= $rank[$e['event_type']]) continue;
            $best[$id][$kind] = $rank[$e['event_type']];
            $profiles[$id][$kind]            = $e['event_date'] ? GedcomDate::extractYear($e['event_date']) : null;
            $profiles[$id]['display'][$kind] = $e['event_date'];
            if ($kind === 'birth') {
                $profiles[$id]['birth_place']            = DuplicateMatcher::placeKey($e['event_place']);
                $profiles[$id]['display']['birth_place'] = $e['event_place'];
            }
        }

        $fams = [];
        foreach ($q('
            SELECT fm.family_id, fm.person_id, fm.role
            FROM family_members fm JOIN families f ON f.id = fm.family_id
            WHERE f.tree_id = ?
        ') as $m) {
            $fams[(int) $m['family_id']][$m['role']][] = (int) $m['person_id'];
        }
        $parents = $spouses = [];
        foreach ($fams as $f) {
            $h = $f['HUSB'][0] ?? null;
            $w = $f['WIFE'][0] ?? null;
            foreach ($f['CHIL'] ?? [] as $c) $parents[$c] ??= [$h, $w];
            if ($h && $w) { $spouses[$h][] = $w; $spouses[$w][] = $h; }
        }

        return $this->addRelations($profiles, $parents, $spouses);
    }

    /** Ergänzt Profile um die Namen von Eltern und Partnern */
    private function addRelations(array $profiles, array $parents, array $spouses): array
    {
        $namesOf   = fn($k) => $k !== null && isset($profiles[$k]) ? $profiles[$k]['names'] : [];
        $displayOf = fn($k) => $k !== null && isset($profiles[$k]) ? $profiles[$k]['display']['name'] : null;

        foreach ($profiles as $k => &$p) {
            [$father, $mother] = $parents[$k] ?? [null, null];
            $p['father']  = $namesOf($father);
            $p['mother']  = $namesOf($mother);
            $p['spouses'] = array_map($namesOf, $spouses[$k] ?? []);
            $p['display']['parents'] = implode(' & ', array_filter([$displayOf($father), $displayOf($mother)])) ?: null;
            $p['display']['spouses'] = implode('; ', array_filter(array_map($displayOf, $spouses[$k] ?? []))) ?: null;
        }
        return $profiles;
    }

    // ── Datensätze ────────────────────────────────────────

    /**
     * Archiv zu einem REPO-Verweis (@R1@) bzw. Freitext-Namen: legt es beim ersten Bedarf an,
     * beim Import in einen bestehenden Baum werden gleichnamige Archive wiederverwendet.
     */
    private function repositoryFor(string $ref, int $treeId): ?int
    {
        $isPtr = (bool) preg_match('/^@[^@]+@$/', $ref);
        if ($isPtr && isset($this->repoIds[$ref])) return $this->repoIds[$ref];

        $node = $isPtr ? ($this->repos[$ref] ?? null) : null;
        $name = $node ? $this->value($this->child($node, 'NAME')) : trim($ref);
        if ($name === '') $name = $isPtr ? '(Archiv ohne Namen)' : '';
        if ($name === '') return null;

        $key = DuplicateMatcher::normalize($name);
        // Freitext und Wiederverwendung im bestehenden Baum über den Namen
        if ((!$isPtr || $this->merge) && isset($this->repoNames[$key])) {
            return $isPtr ? $this->repoIds[$ref] = $this->repoNames[$key] : $this->repoNames[$key];
        }

        $addr = $node ? $this->child($node, 'ADDR') : null;
        $street = $addr ? trim(implode("\n", array_filter([
            $this->fullText($addr),
            $this->value($this->child($addr, 'ADR1')),
            $this->value($this->child($addr, 'ADR2')),
            $this->value($this->child($addr, 'ADR3')),
        ]))) : '';
        // PHON/EMAIL/WWW stehen je nach Version am Archiv oder unter ADDR
        $contact = fn(string $tag) => $node ? ($this->value($this->child($node, $tag)) ?: $this->value($this->child($addr, $tag))) : '';

        $this->db->prepare('
            INSERT INTO repositories (tree_id, name, address, postal_code, city, country, phone, email, website, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $treeId,
            self::cut($name, 300),
            self::cut($street, 65000),
            self::cut($addr ? $this->value($this->child($addr, 'POST')) : null, 20),
            self::cut($addr ? $this->value($this->child($addr, 'CITY')) : null, 120),
            self::cut($addr ? $this->value($this->child($addr, 'CTRY')) : null, 120),
            self::cut($contact('PHON'), 60),
            self::cut($contact('EMAIL'), 200),
            self::cut($contact('WWW'), 500),
            self::cut(trim(implode("\n", array_filter([
                $node ? $this->notesOf($node) : null,
                ($fax = $contact('FAX')) !== '' ? "Fax: $fax" : null,   // kein eigenes Feld – Fax in die Notiz
            ]))) ?: null, 65000),
        ]);
        $id = (int) $this->db->lastInsertId();
        $this->repoNames[$key] ??= $id;
        if ($isPtr) $this->repoIds[$ref] = $id;
        $this->stats['repositories']++;
        return $id;
    }

    private function importSource(array $r, int $treeId): void
    {
        // Archiv (REPO) mit Signatur (CALN) und Medium (MEDI)
        $repo         = $this->child($r, 'REPO');
        $repositoryId = $repo ? $this->repositoryFor($this->value($repo), $treeId) : null;
        $caln         = $repo ? $this->child($repo, 'CALN') : null;
        $callNumber   = $caln ? $this->value($caln) : null;
        $media        = strtoupper($this->value($this->child($caln, 'MEDI')) ?: $this->value($this->child($repo, 'MEDI')));
        $mediaType    = in_array($media, GedcomTags::MEDIA_TYPES, true) ? $media : null;

        $title = $this->fullText($this->child($r, 'TITL') ?? [])
              ?: $this->value($this->child($r, 'ABBR'))
              ?: '(ohne Titel)';

        // Import in bestehenden Baum: Quelle mit gleichem Titel wiederverwenden
        $titleKey = DuplicateMatcher::normalize($title);
        if ($this->merge && isset($this->sourceTitles[$titleKey])) {
            if ($r['xref']) $this->sources[$r['xref']] = $this->sourceTitles[$titleKey];
            $this->stats['sources_reused']++;
            $this->linkMedia('source', $this->sourceTitles[$titleKey], $r);
            return;
        }

        $this->db->prepare('
            INSERT INTO sources (tree_id, title, abbreviation, author, publication, repository_id, call_number, media_type, source_text, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([
            $treeId,
            self::cut($title, 500),
            self::cut($this->value($this->child($r, 'ABBR')), 100),
            self::cut($this->fullText($this->child($r, 'AUTH') ?? []), 300),
            self::cut($this->fullText($this->child($r, 'PUBL') ?? []), 65000),
            $repositoryId,
            self::cut($callNumber, 250),
            $mediaType,
            self::cut($this->fullText($this->child($r, 'TEXT') ?? []), 65000),
            self::cut($this->notesOf($r), 65000),
        ]);

        $id = (int) $this->db->lastInsertId();
        if ($r['xref']) $this->sources[$r['xref']] = $id;
        $this->sourceTitles[$titleKey] ??= $id;
        $this->linkMedia('source', $id, $r);
        $this->stats['sources']++;
    }

    private function importPerson(array $r, int $treeId): void
    {
        if ($r['xref']) {
            $first = $this->child($r, 'NAME');
            $p     = $first ? $this->parseName($first) : null;
            $this->displayNames[$r['xref']] = $p ? self::displayName($p['givn'], $p['spfx'], $p['surn']) : '–';
        }

        if ($r['xref'] && isset($this->mapping[$r['xref']])) {
            $this->mergePerson($r, (int) $this->mapping[$r['xref']]);
            return;
        }

        $sex = strtoupper(substr($this->value($this->child($r, 'SEX')), 0, 1));
        if (!in_array($sex, ['M', 'F', 'X', 'U'], true)) $sex = 'U';

        $this->db->prepare('INSERT INTO persons (tree_id, sex, restriction, notes) VALUES (?, ?, ?, ?)')
            ->execute([$treeId, $sex, GedcomTags::restriction($this->value($this->child($r, 'RESN'))),
                       self::cut($this->notesOf($r), 65000)]);
        $personId = (int) $this->db->lastInsertId();
        if ($r['xref']) $this->persons[$r['xref']] = $personId;
        $this->stats['persons']++;

        // Namen
        $names = $this->nameNodes($r);
        if (!$names) $names = [['tag' => 'NAME', 'xref' => null, 'value' => '', 'children' => []]];
        foreach ($names as $i => $n) {
            $this->insertName($personId, $this->parseName($n), $i);
            $this->stats['names']++;
        }
        $this->collectAssociations($r, $personId, 'person_associations');
        $this->linkMedia('person', $personId, $r);

        // Quellenangaben direkt an der Person (INDI.SOUR)
        $personCit = $this->citationInsert('person_citations', 'person_id');
        foreach ($this->children($r, 'SOUR') as $j => $src) $this->importCitation($src, $personId, $j, $personCit);

        // Ereignisse
        $evtStmt = $this->personEventInsert();
        $citStmt = $this->citationInsert('event_citations');
        $order = 0;
        foreach ($r['children'] as $c) {
            if (isset(self::eventMap()[$c['tag']])) {
                $evtStmt->execute([$personId, self::eventMap()[$c['tag']], self::cut($this->eventDate($c), 100),
                                   $this->placeRef($this->firstPlace($c)), ...$this->eventDetails($c), min($order++, 255)]);
                $eventId = (int) $this->db->lastInsertId();
                $this->stats['events']++;
                foreach ($this->children($c, 'SOUR') as $j => $s) $this->importCitation($s, $eventId, $j, $citStmt);
                $this->collectAssociations($c, $eventId, 'event_associations');
                $this->linkMedia('event', $eventId, $c);
            }
        }
    }

    /** Vorhandene Person: nur Fehlendes ergänzen, nichts überschreiben */
    private function mergePerson(array $r, int $personId): void
    {
        $this->persons[$r['xref']]   = $personId;
        $this->mergedIds[$personId]  = true;
        $this->stats['persons_merged']++;

        $stmt = $this->db->prepare('SELECT sex, restriction, notes FROM persons WHERE id = ?');
        $stmt->execute([$personId]);
        $cur = $stmt->fetch();

        // Geschlecht nur setzen, wenn bisher unbekannt
        $sex = strtoupper(substr($this->value($this->child($r, 'SEX')), 0, 1));
        if ($cur['sex'] === 'U' && in_array($sex, ['M', 'F', 'X'], true)) {
            $this->db->prepare('UPDATE persons SET sex = ? WHERE id = ?')->execute([$sex, $personId]);
        }

        // Datenschutz-Kennzeichen nur setzen, wenn bisher keines
        $resn = GedcomTags::restriction($this->value($this->child($r, 'RESN')));
        if ($resn && !$cur['restriction']) {
            $this->db->prepare('UPDATE persons SET restriction = ? WHERE id = ?')->execute([$resn, $personId]);
        }

        // Notizen anhängen, wenn sie noch nicht enthalten sind
        $fileNotes = $this->notesOf($r);
        if ($fileNotes !== null) {
            $curNotes = (string) $cur['notes'];
            if ($curNotes === '' || !str_contains($curNotes, $fileNotes)) {
                $this->db->prepare('UPDATE persons SET notes = ? WHERE id = ?')
                    ->execute([self::cut($curNotes === '' ? $fileNotes : $curNotes . "\n\n" . $fileNotes, 65000), $personId]);
            }
        }

        // Fehlende Namensvarianten ergänzen
        $stmt = $this->db->prepare('SELECT given_name, surname_prefix, surname, sort_order FROM person_names WHERE person_id = ?');
        $stmt->execute([$personId]);
        $known = [];
        $order = 0;
        foreach ($stmt->fetchAll() as $n) {
            $known[self::nameKeys($n['given_name'], $n['surname_prefix'], $n['surname'])[0][0] . '|'
                 . self::nameKeys($n['given_name'], $n['surname_prefix'], $n['surname'])[0][1]] = true;
            $order = max($order, (int) $n['sort_order'] + 1);
        }
        foreach ($this->nameNodes($r) as $n) {
            $p = $this->parseName($n);
            [$g, $s] = self::nameKeys($p['givn'], $p['spfx'], $p['surn'])[0];
            if (isset($known["$g|$s"]) || ($g === '' && $s === '')) continue;
            $this->insertName($personId, $p, $order++);
            $known["$g|$s"] = true;
            $this->stats['names_added']++;
        }

        $this->mergeCitations($r, $personId, 'person_citations',
                              $this->citationInsert('person_citations', 'person_id'), 'person_id');
        $this->collectAssociations($r, $personId, 'person_associations');
        $this->linkMedia('person', $personId, $r);

        $this->mergeEvents($r, $personId, 'person_events', 'person_id', 'event_citations',
                           self::eventMap(), self::UNIQUE_PERSON_EVENTS, $this->displayNames[$r['xref']] ?? '–');
    }

    private function importFamily(array $r, int $treeId): void
    {
        foreach ($this->children($r, 'CHIL') as $ch) {
            if ($p = $this->relPedigree($ch)) $this->childPedigree[($r['xref'] ?? '') . '|' . $this->value($ch)] = $p;
        }
        $members = [];
        foreach (['HUSB', 'WIFE', 'CHIL'] as $role) {
            foreach ($this->children($r, $role) as $m) {
                $pid = $this->persons[$this->value($m)] ?? null;
                if ($pid) $members[] = [$pid, $role];
            }
        }
        if (!$members) return;

        // Import in bestehenden Baum: Familie zwischen denselben (zugeordneten) Partnern wiederverwenden
        if ($this->merge) {
            $partners = array_values(array_unique(array_map(fn($m) => $m[0],
                array_filter($members, fn($m) => $m[1] !== 'CHIL'))));
            if ($partners && count(array_filter($partners, fn($id) => isset($this->mergedIds[$id]))) === count($partners)) {
                $familyId = $this->findFamily($treeId, $partners);
                if ($familyId) {
                    if ($r['xref']) $this->famIds[$r['xref']] = $familyId;
                    $this->mergeFamily($r, $familyId, $members);
                    return;
                }
            }
        }

        $this->db->prepare('INSERT INTO families (tree_id, notes) VALUES (?, ?)')
            ->execute([$treeId, self::cut($this->notesOf($r), 65000)]);
        $familyId = (int) $this->db->lastInsertId();
        if ($r['xref']) $this->famIds[$r['xref']] = $familyId;
        $this->insertMembers($familyId, $members);
        $this->stats['families']++;

        // Quellenangaben direkt an der Familie (FAM.SOUR)
        $famCit = $this->citationInsert('family_citations', 'family_id');
        foreach ($this->children($r, 'SOUR') as $j => $src) $this->importCitation($src, $familyId, $j, $famCit);
        $this->linkMedia('family', $familyId, $r);

        $evtStmt = $this->familyEventInsert();
        $citStmt = $this->citationInsert('family_event_citations');
        $order = 0;
        foreach ($r['children'] as $c) {
            if (in_array($c['tag'], self::FAMILY_EVENTS, true)) {
                $evtStmt->execute([$familyId, $c['tag'], self::cut($this->eventDate($c), 100),
                                   $this->placeRef($this->firstPlace($c)), ...$this->familyEventDetails($c), min($order++, 255)]);
                $eventId = (int) $this->db->lastInsertId();
                $this->stats['family_events']++;
                foreach ($this->children($c, 'SOUR') as $j => $src) $this->importCitation($src, $eventId, $j, $citStmt);
                $this->linkMedia('family_event', $eventId, $c);
                $this->collectAssociations($c, $eventId, 'family_event_associations');
            }
        }
    }

    /** Familie mit genau diesen Partnern (als HUSB/WIFE) im Baum suchen */
    private function findFamily(int $treeId, array $partnerIds): ?int
    {
        $in   = implode(',', array_fill(0, count($partnerIds), '?'));
        $stmt = $this->db->prepare("
            SELECT fm.family_id
            FROM family_members fm JOIN families f ON f.id = fm.family_id
            WHERE f.tree_id = ? AND fm.role <> 'CHIL' AND fm.person_id IN ($in)
            GROUP BY fm.family_id
            HAVING COUNT(DISTINCT fm.person_id) = ?
            ORDER BY fm.family_id
            LIMIT 1
        ");
        $stmt->execute([$treeId, ...$partnerIds, count($partnerIds)]);
        $id = $stmt->fetchColumn();
        return $id ? (int) $id : null;
    }

    private function mergeFamily(array $r, int $familyId, array $members): void
    {
        $this->stats['families_merged']++;
        $this->insertMembers($familyId, $members);

        $fileNotes = $this->notesOf($r);
        if ($fileNotes !== null) {
            $this->db->prepare('UPDATE families SET notes = ? WHERE id = ? AND (notes IS NULL OR notes = \'\')')
                ->execute([self::cut($fileNotes, 65000), $familyId]);
        }

        $this->mergeCitations($r, $familyId, 'family_citations',
                              $this->citationInsert('family_citations', 'family_id'), 'family_id');
        $this->linkMedia('family', $familyId, $r);

        $label = implode(' & ', array_filter(array_map(
            fn($tag) => $this->displayNames[$this->value($this->child($r, $tag))] ?? null, ['HUSB', 'WIFE'])));
        $this->mergeEvents($r, $familyId, 'family_events', 'family_id', 'family_event_citations',
                           array_combine(self::FAMILY_EVENTS, self::FAMILY_EVENTS), self::UNIQUE_FAMILY_EVENTS,
                           $label ?: 'Familie');
    }

    /**
     * Ereignisse eines Datensatzes mit den vorhandenen Ereignissen abgleichen:
     * gleiches Ereignis → leere Felder und fehlende Quellenangaben ergänzen,
     * einmalige Ereignisse mit anderem Datum → Widerspruch melden, sonst neu anlegen.
     */
    private function mergeEvents(array $r, int $ownerId, string $table, string $ownerCol, string $citTable,
                                 array $typeMap, array $unique, string $label): void
    {
        // Wert und Details (Merkmale wie Beruf, Todesursache, Alter …) – je nach Tabelle andere Spalten
        $isPerson    = $table === 'person_events';
        $mediaKind   = $isPerson ? 'event' : 'family_event';
        $assoTable   = $isPerson ? 'event_associations' : 'family_event_associations';
        $withDetails = true;
        $fields      = $isPerson
            ? ['event_date', 'place_id', 'event_value', 'event_descriptor', 'age', 'cause', 'agency', 'religion', 'event_time', 'notes']
            : ['event_date', 'place_id', 'event_value', 'event_descriptor', 'husb_age', 'wife_age', 'cause', 'agency', 'religion', 'event_time', 'notes'];

        $stmt = $this->db->prepare('SELECT id, event_type, sort_order, ' . implode(', ', $fields)
                                 . " FROM $table WHERE $ownerCol = ? ORDER BY sort_order, id");
        $stmt->execute([$ownerId]);
        $existing = $stmt->fetchAll();
        $order    = $existing ? max(array_column($existing, 'sort_order')) + 1 : 0;

        $evtStmt = $isPerson ? $this->personEventInsert() : $this->familyEventInsert();
        $citStmt = $this->citationInsert($citTable);
        $update  = $this->db->prepare("UPDATE $table SET " . implode(', ', array_map(fn($f) => "$f = ?", $fields)) . ' WHERE id = ?');

        foreach ($r['children'] as $c) {
            if (!isset($typeMap[$c['tag']])) continue;
            $type    = $typeMap[$c['tag']];
            $date    = self::cut($this->eventDate($c), 100);
            $placeText = $this->firstPlace($c);
            $place   = $this->placeRef($placeText);
            $details = $isPerson ? $this->eventDetails($c) : $this->familyEventDetails($c);
            $file    = array_combine($fields, [$date, $place, ...$details]);

            // gleiche Art; bei Merkmalen muss auch der Wert passen (Beruf „Bauer“ ≠ „Müller“)
            $same = array_values(array_filter($existing, fn($e) => $e['event_type'] === $type
                && (!$withDetails || $e['event_value'] === null || $file['event_value'] === null
                    || DuplicateMatcher::normalize($e['event_value']) === DuplicateMatcher::normalize($file['event_value']))));

            $match = null;
            foreach ($same as $i => $e) {
                if (($e['event_date'] ?? null) !== null && $date !== null
                    && GedcomDate::normalize($e['event_date']) === $date) { $match = $i; break; }
            }
            if ($match === null && $date === null && $same) $match = 0;
            if ($match === null && $date !== null) {
                foreach ($same as $i => $e) if ($e['event_date'] === null) { $match = $i; break; }
            }

            if ($match !== null) {
                // nur leere Felder ergänzen
                $e      = $same[$match];
                $merged = [];
                foreach ($fields as $f) $merged[$f] = $e[$f] ?? $file[$f];
                if (array_diff_assoc(array_map('strval', $merged), array_map('strval', array_intersect_key($e, $merged)))) {
                    $update->execute([...array_values($merged), $e['id']]);
                    $this->stats['events_completed']++;
                    foreach ($existing as &$x) if ($x['id'] === $e['id']) $x = [...$x, ...$merged];
                    unset($x);
                }
                $this->mergeCitations($c, (int) $e['id'], $citTable, $citStmt);
                $this->linkMedia($mediaKind, (int) $e['id'], $c);
                $this->collectAssociations($c, (int) $e['id'], $assoTable);
                continue;
            }

            if ($same && in_array($type, $unique, true)) {
                $this->conflicts[] = [
                    'name'     => $label,
                    'type'     => $type,
                    'scope'    => $table === 'family_events' ? 'family' : 'person',
                    'existing' => trim(($same[0]['event_date'] ?? '') . ' '
                                  . ($same[0]['place_id'] ? $this->placeIndex->fullName((int) $same[0]['place_id']) : '')),
                    'file'     => trim(($date ?? '') . ' ' . ($placeText ?? '')),
                ];
                continue;
            }

            $evtStmt->execute([$ownerId, $type, $date, $place, ...$details, min($order++, 255)]);
            $eventId    = (int) $this->db->lastInsertId();
            $existing[] = ['id' => $eventId, 'event_type' => $type, 'sort_order' => $order, ...$file];
            $this->stats['events_added']++;
            foreach ($this->children($c, 'SOUR') as $j => $s) $this->importCitation($s, $eventId, $j, $citStmt);
            $this->linkMedia($mediaKind, $eventId, $c);
            $this->collectAssociations($c, $eventId, $assoTable);
        }
    }

    /** Quellenangaben ergänzen, die am vorhandenen Ereignis noch fehlen (gleiche Quelle + Seite) */
    private function mergeCitations(array $event, int $eventId, string $citTable, PDOStatement $stmt, string $ownerCol = 'event_id'): void
    {
        $sources = $this->children($event, 'SOUR');
        if (!$sources) return;

        $q = $this->db->prepare("SELECT source_id, page, MAX(sort_order) AS so FROM $citTable WHERE $ownerCol = ? GROUP BY source_id, page");
        $q->execute([$eventId]);
        $seen  = [];
        $order = 0;
        foreach ($q->fetchAll() as $c) {
            $seen[($c['source_id'] ?? '') . '|' . DuplicateMatcher::normalize($c['page'])] = true;
            $order = max($order, (int) $c['so'] + 1);
        }
        foreach ($sources as $s) {
            $this->importCitation($s, $eventId, $order, $stmt, $seen) && $order++;
        }
    }

    /** @return bool true, wenn eine Quellenangabe angelegt wurde */
    private function importCitation(array $s, int $eventId, int $order, PDOStatement $stmt, ?array &$seen = null): bool
    {
        $v        = $this->value($s);
        $isPtr    = (bool) preg_match('/^@[^@]+@$/', $v);
        $sourceId = $isPtr ? ($this->sources[$v] ?? null) : null;
        $text     = $this->fullText($this->child($this->child($s, 'DATA'), 'TEXT') ?? []);
        if (!$isPtr && $v !== '') $text = trim($v . ($text !== '' ? "\n" . $text : ''));   // Quelle als Freitext
        $page = $this->value($this->child($s, 'PAGE'));
        $url  = $this->value($this->child($s, 'WWW')) ?: $this->value($this->child($s, '_LINK'));

        if (!$sourceId && $page === '' && $text === '' && $url === '') return false;

        if ($seen !== null) {
            $key = ($sourceId ?? '') . '|' . DuplicateMatcher::normalize($page);
            if (isset($seen[$key])) return false;
            $seen[$key] = true;
        }

        $quay = $this->value($this->child($s, 'QUAY'));   // Verlässlichkeit 0–3
        $stmt->execute([$eventId, $sourceId, self::cut($page, 250), self::cut($text, 65000),
                        self::cut($url, 500), preg_match('/^[0-3]$/', $quay) ? (int) $quay : null, min($order, 255)]);
        $this->stats['citations']++;
        return true;
    }

    /**
     * Verknüpfte Personen sammeln – am Ereignis (Taufpate, Trauzeuge) oder direkt an der Person.
     * GEDCOM 7: „ASSO @I1@ / ROLE GODP / PHRASE …“, 5.5.1: „ASSO @I1@ / RELA Taufpate“ (unter Ereignissen als _ASSO).
     */
    private function collectAssociations(array $node, int $ownerId, string $table): void
    {
        foreach ([...$this->children($node, 'ASSO'), ...$this->children($node, '_ASSO')] as $a) {
            $ref = $this->value($a);
            if (!preg_match('/^@[^@]+@$/', $ref)) continue;
            $role = $this->child($a, 'ROLE');
            $this->pendingAssos[] = [
                $table, $ownerId, $ref,
                $role ? $this->value($role) : $this->value($this->child($a, 'RELA')),
                $role ? $this->value($this->child($role, 'PHRASE')) : '',
                $this->notesOf($a),
            ];
        }
        // „ALIA @I12@“: anderer Datensatz, vermutlich dieselbe Person → verknüpfte Person „alias“
        foreach ($this->children($node, 'ALIA') as $a) {
            $ref = $this->value($a);
            if (preg_match('/^@[^@]+@$/', $ref)) $this->pendingAssos[] = [$table, $ownerId, $ref, 'alias', '', $this->notesOf($a)];
        }
        // „_WITN @I12@“ / „_GODP @I12@“: Verweis statt Name → verknüpfte Person mit passender Rolle
        foreach (self::NAMED_PERSON_TAGS as $tag) {
            foreach ($this->children($node, $tag) as $n) {
                $ref = $this->value($n);
                if (!preg_match('/^@[^@]+@$/', $ref)) continue;
                $this->pendingAssos[] = [$table, $ownerId, $ref, str_contains($tag, 'GOD') ? 'GODP' : 'WITN', '', $this->notesOf($n)];
            }
        }
    }

    /** RELA-Freitexte (nur Buchstaben, groß) → ROLE */
    private const ASSO_ALIASES = [
        'GODPARENT' => 'GODP', 'GODFATHER' => 'GODP', 'GODMOTHER' => 'GODP', 'PATE' => 'GODP', 'PATIN' => 'GODP',
        'TAUFPATE' => 'GODP', 'TAUFPATIN' => 'GODP', 'FIRMPATE' => 'GODP', 'FIRMPATIN' => 'GODP', 'SPONSOR' => 'GODP',
        'WITNESS' => 'WITN', 'ZEUGE' => 'WITN', 'ZEUGIN' => 'WITN', 'TRAUZEUGE' => 'WITN', 'TRAUZEUGIN' => 'WITN',
        'WITNESSOFMARRIAGE' => 'WITN', 'MARRIAGEWITNESS' => 'WITN', 'BEISTAND' => 'WITN',
        'PRIEST' => 'CLERGY', 'PFARRER' => 'CLERGY', 'GEISTLICHER' => 'CLERGY', 'PASTOR' => 'CLERGY',
        'OFFICIANT' => 'OFFICIATOR', 'STANDESBEAMTER' => 'OFFICIATOR',
        'NEIGHBOR' => 'NGHBR', 'NEIGHBOUR' => 'NGHBR', 'NACHBAR' => 'NGHBR', 'NACHBARIN' => 'NGHBR',
        'FREUND' => 'FRIEND', 'FREUNDIN' => 'FRIEND',
    ];

    /** RELA/ROLE → [ROLE, PHRASE]; Unbekanntes wird OTHER mit dem Text als PHRASE */
    private static function assoRole(string $rela, string $phrase): array
    {
        $rela = trim($rela);
        if ($rela === '') return [null, $phrase];
        $key = strtr(mb_strtoupper(preg_replace('/[^\p{L}]+/u', '', $rela)), ['Ä' => 'AE', 'Ö' => 'OE', 'Ü' => 'UE']);
        if (in_array($key, GedcomTags::ASSO_ROLES, true)) return [$key, $phrase];
        if (isset(self::ASSO_ALIASES[$key]))        return [self::ASSO_ALIASES[$key], $phrase];
        return ['OTHER', $phrase !== '' ? $phrase : $rela];
    }

    private function insertAssociations(): void
    {
        if (!$this->pendingAssos) return;
        $sql = [
            'event_associations'        => 'INSERT INTO event_associations (event_id, person_id, role, role_phrase, sort_order) VALUES (?, ?, ?, ?, ?)',
            'family_event_associations' => 'INSERT INTO family_event_associations (event_id, person_id, role, role_phrase, sort_order) VALUES (?, ?, ?, ?, ?)',
            'person_associations'       => 'INSERT INTO person_associations (person_id, associate_id, role, role_phrase, sort_order, notes) VALUES (?, ?, ?, ?, ?, ?)',
        ];
        // Spalten [Besitzer, verknüpfte Person] – fest im Code
        $cols = [
            'event_associations'        => ['event_id', 'person_id'],
            'family_event_associations' => ['event_id', 'person_id'],
            'person_associations'       => ['person_id', 'associate_id'],
        ];
        $stmts = [];
        $order = [];
        foreach ($this->pendingAssos as [$table, $ownerId, $ref, $rela, $phrase, $notes]) {
            $personId = $this->persons[$ref] ?? null;
            if (!$personId) continue;
            if ($table === 'person_associations' && $personId === $ownerId) continue;
            [$ownerCol, $personCol] = $cols[$table];
            $okey = "$table:$ownerId";
            // Beim Import in einen bestehenden Baum: dieselbe Person nicht zweimal verknüpfen, hinten anfügen
            if ($this->merge) {
                $chk = $this->db->prepare("SELECT 1 FROM $table WHERE $ownerCol = ? AND $personCol = ?");
                $chk->execute([$ownerId, $personId]);
                if ($chk->fetch()) continue;
                if (!isset($order[$okey])) {
                    $max = $this->db->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM $table WHERE $ownerCol = ?");
                    $max->execute([$ownerId]);
                    $order[$okey] = (int) $max->fetchColumn();
                }
            }
            [$role, $phrase] = self::assoRole((string) $rela, (string) $phrase);
            $n = $order[$okey] = ($order[$okey] ?? -1) + 1;
            $params = [$ownerId, $personId, $role, self::cut($phrase, 200), min($n, 255)];
            if ($table === 'person_associations') $params[] = self::cut($notes, 65000);
            ($stmts[$table] ??= $this->db->prepare($sql[$table]))->execute($params);
            $this->stats['associations']++;
        }
        $this->pendingAssos = [];
    }

    // ── Kleine Helfer zum Schreiben ───────────────────────

    private function insertName(int $personId, array $p, int $order): void
    {
        $this->db->prepare('
            INSERT INTO person_names
                (person_id, name_type, name_prefix, given_name, nickname, surname_prefix, surname, name_suffix, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ')->execute([$personId, $p['type'], $p['npfx'], $p['givn'], $p['nick'], $p['spfx'], $p['surn'], $p['nsfx'], min($order, 255)]);
    }

    private function insertMembers(int $familyId, array $members): void
    {
        $stmt = $this->db->prepare('INSERT IGNORE INTO family_members (family_id, person_id, role) VALUES (?, ?, ?)');
        foreach ($members as [$pid, $role]) $stmt->execute([$familyId, $pid, $role]);
    }

    private function personEventInsert(): PDOStatement
    {
        return $this->db->prepare('
            INSERT INTO person_events (person_id, event_type, event_date, place_id,
                                       event_value, event_descriptor, age, cause, agency, religion, event_time, notes, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
    }

    /** Wert und Details eines Ereignisses/Merkmals: [value, descriptor (TYPE), age, cause, agency, religion, time, notes] */
    private function eventDetails(array $c): array
    {
        $value = $this->fullText($c);
        if ($value === '' || strtoupper($value) === 'Y') $value = null;   // „1 BIRT Y“ = Ereignis fand statt
        return [
            self::cut($value, 65000),
            self::cut($this->value($this->child($c, 'TYPE')), 120),
            self::cut($this->value($this->child($c, 'AGE')), 30),
            self::cut($this->value($this->child($c, 'CAUS')), 250),
            self::cut($this->value($this->child($c, 'AGNC')), 250),
            self::cut($this->value($this->child($c, 'RELI')), 120),
            self::cut($this->value($this->child($this->child($c, 'DATE'), 'TIME')), 20),
            self::cut($this->eventNotes($c), 65000),
        ];
    }

    /** Abstammungsart (FAMC/PEDI) an der Kind-Mitgliedschaft setzen – vorhandene Angaben bleiben */
    private function applyPedigree(array $r): void
    {
        $personId = $this->persons[$r['xref'] ?? ''] ?? null;
        if (!$personId) return;
        foreach ($this->children($r, 'FAMC') as $famc) {
            $familyId = $this->famIds[$this->value($famc)] ?? null;
            $pedigree = GedcomTags::pedigree($this->value($this->child($famc, 'PEDI')))
                     ?? $this->relPedigree($famc) ?? $this->childPedigree[$this->value($famc) . '|' . $r['xref']] ?? null;
            if (!$familyId || !$pedigree) continue;
            $this->db->prepare("UPDATE family_members SET pedigree = ?
                                WHERE family_id = ? AND person_id = ? AND role = 'CHIL' AND pedigree IS NULL")
                ->execute([$pedigree, $familyId, $personId]);
        }
    }

    private function citationInsert(string $table, string $ownerCol = 'event_id'): PDOStatement
    {
        return $this->db->prepare("INSERT INTO $table ($ownerCol, source_id, page, transcription, url, quality, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
    }

    private function familyEventInsert(): PDOStatement
    {
        return $this->db->prepare('
            INSERT INTO family_events (family_id, event_type, event_date, place_id, event_value, event_descriptor,
                                       husb_age, wife_age, cause, agency, religion, event_time, notes, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
    }

    /** Details eines Familienereignisses: [value, descriptor (TYPE), husb_age, wife_age, cause, agency, religion, time, notes] */
    private function familyEventDetails(array $c): array
    {
        $value = $this->fullText($c);
        if ($value === '' || strtoupper($value) === 'Y') $value = null;
        return [
            self::cut($value, 65000),
            self::cut($this->value($this->child($c, 'TYPE')), 120),
            self::cut($this->value($this->child($this->child($c, 'HUSB'), 'AGE')), 30),
            self::cut($this->value($this->child($this->child($c, 'WIFE'), 'AGE')), 30),
            self::cut($this->value($this->child($c, 'CAUS')), 250),
            self::cut($this->value($this->child($c, 'AGNC')), 250),
            self::cut($this->value($this->child($c, 'RELI')), 120),
            self::cut($this->value($this->child($this->child($c, 'DATE'), 'TIME')), 20),
            self::cut($this->eventNotes($c), 65000),
        ];
    }

    /** Datum eines Ereignisknotens, normalisiert; ungültige Angaben werden gezählt */
    private function eventDate(array $event): ?string
    {
        $date = $this->value($this->child($event, 'DATE'));
        if ($date === '') return null;
        $date = GedcomDate::repair($date);   // „20 6 1879“ → „20 JUN 1879“
        if (!GedcomDate::isValid($date)) $this->stats['invalid_dates']++;
        return $date;
    }

    /** Militärdienst als Erweiterung (FTM, Legacy, RootsMagic …) – wird „1 EVEN / 2 TYPE Militärdienst“ */
    private const MILITARY_TAGS = ['_MILI', '_MILT', '_MILITARY', '_MILITARY_SERVICE'];

    /**
     * INDI vereinheitlichen: Militärdienst-Erweiterungen → Sonstiges Ereignis mit Art „Militärdienst“,
     * _INTE (Bestattung/interment) → Begräbnis
     */
    private function normalizeIndi(array $r): array
    {
        foreach ($r['children'] as &$c) {
            if ($c['tag'] === '_INTE') { $c['tag'] = 'BURI'; continue; }
            if (!in_array($c['tag'], self::MILITARY_TAGS, true)) continue;
            $c['tag'] = 'EVEN';
            if (!$this->child($c, 'TYPE')) {
                array_unshift($c['children'], ['tag' => 'TYPE', 'xref' => null, 'value' => 'Militärdienst', 'children' => []]);
            }
        }
        unset($c);
        return $r;
    }

    /** Familienstatus als Erweiterung → Sonstiges Familienereignis mit dieser Art */
    private const FAMILY_STATUS_TAGS = ['_NMR' => 'nicht verheiratet', '_COML' => 'Lebensgemeinschaft ohne Trauschein'];

    /**
     * FAM vereinheitlichen: _NMR/_COML → Sonstiges Familienereignis; FACT → Sonstiges Familienereignis
     * mit Wert und Art (eine bloße alte Kennung „FACT … / TYPE _UID“ wird verworfen)
     */
    private function normalizeFam(array $r): array
    {
        $out = [];
        foreach ($r['children'] as $c) {
            if (isset(self::FAMILY_STATUS_TAGS[$c['tag']])) {
                $flag = strtoupper(trim($this->value($c)));
                if (in_array($flag, ['N', 'NO', 'NEIN', '0'], true)) continue;
                $c = ['tag' => 'EVEN', 'xref' => null, 'value' => '',
                      'children' => [['tag' => 'TYPE', 'xref' => null, 'value' => self::FAMILY_STATUS_TAGS[$c['tag']], 'children' => []],
                                     ...$c['children']]];
            } elseif ($c['tag'] === 'FACT') {
                if (strtoupper($this->value($this->child($c, 'TYPE'))) === '_UID') continue;
                $c['tag'] = 'EVEN';
            }
            $out[] = $c;
        }
        return [...$r, 'children' => $out];
    }

    /** Abstammungsart aus _FREL/_MREL (Family Tree Maker u. a.): Beziehung zu Vater bzw. Mutter */
    private function relPedigree(array $node): ?string
    {
        $map = ['NATURAL' => 'BIRTH', 'BIRTH' => 'BIRTH', 'BIOLOGICAL' => 'BIRTH', 'LEIBLICH' => 'BIRTH',
                'ADOPTED' => 'ADOPTED', 'ADOPTIERT' => 'ADOPTED', 'FOSTER' => 'FOSTER', 'PFLEGE' => 'FOSTER',
                'SEALED' => 'SEALED', 'STEP' => 'OTHER', 'GUARDIAN' => 'OTHER', 'PRIVATE' => null, 'UNKNOWN' => null];
        $rels = array_filter(array_map(fn($t) => $map[strtoupper(trim($this->value($this->child($node, $t))))] ?? null,
                                       ['_FREL', '_MREL']));
        if (!$rels) return null;
        foreach ($rels as $p) if ($p !== 'BIRTH') return $p;   // eine Seite adoptiert → Kind ist adoptiert
        return 'BIRTH';
    }

    /** Zusätzliche Namen aus verbreiteten Erweiterungen: Tag → Namensart */
    private const EXTRA_NAME_TAGS = ['_AKA' => 'AKA', '_MARNM' => 'MARRIED', '_MARNM_SURN' => 'MARRIED', 'ALIA' => 'AKA',
                                     '_FORMERNAME' => 'AKA'];

    /**
     * Alle Namen einer Person: NAME-Einträge, dazu „auch bekannt als“ (_AKA) und Ehename (_MARNM) –
     * jeweils direkt an der Person (1 _AKA) oder unter einem Namen (2 _MARNM).
     * Ein Ehename ohne /…/ ist meist nur der Nachname; der Vorname kommt dann vom zugehörigen Namen.
     */
    private function nameNodes(array $r): array
    {
        $names = [];
        $extra = [];   // [Knoten, zugehöriger NAME-Knoten oder null]
        foreach ($r['children'] as $c) {
            if ($c['tag'] === 'NAME') {
                $names[] = $c;
                foreach ($c['children'] as $x) {
                    if (isset(self::EXTRA_NAME_TAGS[$x['tag']]) && $x['tag'] !== '_FORMERNAME') $extra[] = [$x, $c];
                }
            } elseif (isset(self::EXTRA_NAME_TAGS[$c['tag']])) {
                $extra[] = [$c, null];
            }
        }
        // _FORMERNAME (MyHeritage): frühere Nachnamen, durch Komma getrennt → je ein weiterer Name
        foreach ($names as $nm) {
            foreach ($this->children($nm, '_FORMERNAME') as $f) {
                foreach (preg_split('/\s*[,;]\s*/', trim($this->value($f))) as $sn) {
                    if ($sn !== '') $extra[] = [['tag' => '_FORMERNAME', 'xref' => null, 'value' => $sn, 'children' => []], $nm];
                }
            }
        }
        foreach ($extra as [$x, $parent]) {
            $v = trim($this->value($x));
            if ($v === '' || preg_match('/^@[^@]+@$/', $v)) continue;   // ALIA @I12@ ist ein Verweis, kein Name
            if ((str_starts_with($x['tag'], '_MARNM') || $x['tag'] === '_FORMERNAME') && !str_contains($v, '/')) {
                $base  = $parent ?? ($names[0] ?? null);
                $given = $base ? ($this->parseName($base)['givn'] ?? '') : '';
                $x     = [...$x, 'value' => trim("$given /$v/")];
            }
            $names[] = $x;
        }
        return $names;
    }

    /** Rufname (GEDCOM-L _RUFNAME) ins Feld „Spitzname / Rufname“; beides vorhanden → „Sepp, Josef“ */
    private static function nickWithCallName(?string $nick, ?string $call): ?string
    {
        if ($call === null || ($nick !== null && mb_strtolower($nick) === mb_strtolower($call))) return $nick;
        return $nick === null ? $call : "$nick, $call";
    }

    /** „Johann Georg /von Berg/ Jr.“ + Unterfelder → Namensbestandteile */
    private function parseName(array $n): array
    {
        $v = $this->value($n);
        $givn = $surn = $nsfx = null;
        if (preg_match('#^(.*?)/(.*?)/(.*)$#', $v, $m)) {
            $givn = trim($m[1]); $surn = trim($m[2]); $nsfx = trim($m[3]);
        } else {
            $givn = $v;
        }

        $sub  = fn(string $tag) => ($x = $this->value($this->child($n, $tag))) !== '' ? $x : null;
        $type = strtoupper($this->value($this->child($n, 'TYPE')));

        return [
            'type' => self::EXTRA_NAME_TAGS[$n['tag']] ?? self::NAME_TYPE_MAP[$type] ?? 'BIRTH',
            'npfx' => self::cut($sub('NPFX'), 30),
            'givn' => self::cut($sub('GIVN') ?? $givn, 100),
            'nick' => self::cut(self::nickWithCallName($sub('NICK'), $sub('_RUFNAME')), 100),
            'spfx' => self::cut($sub('SPFX'), 30),
            'surn' => self::cut($sub('SURN') ?? $surn, 100),
            'nsfx' => self::cut($sub('NSFX') ?? $nsfx, 30),
        ];
    }
}
