<?php

declare(strict_types=1);

/**
 * Stammbaum als GEDCOM 5.5.1 (UTF-8) ausgeben.
 *
 * Personen und Familien werden in Blöcken gelesen, damit auch sehr große Bäume
 * (> 50 000 Personen) mit wenig Speicher auskommen. Die Ausgabe geht Zeile für
 * Zeile an $out (z. B. echo), damit der Download sofort beginnt.
 *
 * Abbildung (Gegenstück zu GedcomImporter):
 *   persons/person_names/person_events  → INDI mit NAME, Ereignissen, Merkmalen
 *   event_associations / family_event_associations → _ASSO + RELA unter dem Ereignis (5.5.1-Erweiterung)
 *   person_associations                 → ASSO + RELA (+ NOTE) unter INDI
 *   families/family_members/family_events → FAM, INDI.FAMS/FAMC (+ PEDI)
 *   sources / repositories              → SOUR (mit REPO/CALN/MEDI) / REPO
 *   *_citations                         → SOUR-Verweise mit PAGE, DATA.TEXT, WWW
 *   places (Koordinaten, GOV)           → PLAC.MAP.LATI/LONG, PLAC._GOV (GEDCOM-L)
 *   media + *_media                     → OBJE-Datensätze (FILE media/…) und OBJE-Verweise (_PRIM Y = Hauptbild)
 */
final class GedcomExporter
{
    private const BATCH    = 2000;
    private const MAX_LINE = 200;   // Zeichen je Wert, Rest per CONC (GEDCOM erlaubt 255 inkl. Ebene/Tag)

    /** Ort des Ereignisses mit Koordinaten und GOV-Kennung (Join auf places als pl) */
    private const PLACE_COLS = 'pl.full_name AS event_place, pl.latitude AS place_lat, pl.longitude AS place_lon, pl.gov_id AS place_gov';

    /** @var callable(string): void */
    private $out;

    private array $stats = ['persons' => 0, 'families' => 0, 'sources' => 0, 'repositories' => 0, 'media' => 0];

    /** Teilexport: nur diese Personen/Familien; Quellen, Archive und Medien nur, soweit benutzt */
    private ?array $onlyPersons  = null;   // person_id → true
    private ?array $onlyFamilies = null;   // family_id → true
    private array  $usedSources  = [];
    private array  $usedRepos    = [];
    private array  $usedMedia    = [];

    public function __construct(private readonly PDO $db, private readonly int $treeId, callable $out)
    {
        $this->out = $out;
    }

    /** Nur einen Teil des Baums ausgeben (siehe Relatives) */
    public function limitTo(array $personIds, array $familyIds): static
    {
        $this->onlyPersons  = array_fill_keys($personIds, true);
        $this->onlyFamilies = array_fill_keys($familyIds, true);
        return $this;
    }

    /** IDs der ausgegebenen Medien (für das ZIP-Archiv eines Teilexports) */
    public function usedMedia(): array
    {
        return array_keys($this->usedMedia);
    }

    private function hasPerson(int|string $id): bool { return $this->onlyPersons === null || isset($this->onlyPersons[(int) $id]); }
    private function hasFamily(int|string $id): bool { return $this->onlyFamilies === null || isset($this->onlyFamilies[(int) $id]); }

    public function run(string $treeName): array
    {
        $this->header($treeName);
        $this->persons();
        $this->families();
        $this->sources();
        $this->repositories();
        $this->media();
        $this->line(0, 'TRLR');
        return $this->stats;
    }

    // ── Kopf ──────────────────────────────────────────────

    private function header(string $treeName): void
    {
        $this->line(0, 'HEAD');
        $this->line(1, 'SOUR', 'AVUNCULUS');
        $this->line(2, 'NAME', 'Avunculus');
        $this->line(1, 'DATE', strtoupper(date('j M Y')));
        $this->line(2, 'TIME', date('H:i:s'));
        $this->line(1, 'SUBM', '@U1@');
        $this->line(1, 'FILE', self::fileName($treeName));
        $this->line(1, 'GEDC');
        $this->line(2, 'VERS', '5.5.1');
        $this->line(2, 'FORM', 'LINEAGE-LINKED');
        $this->line(1, 'CHAR', 'UTF-8');
        $this->line(0, 'SUBM', null, '@U1@');
        $this->line(1, 'NAME', 'Avunculus');
    }

    public static function fileName(string $treeName, string $ext = 'ged'): string
    {
        $base = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '', $treeName)) ?: 'Stammbaum';
        return "$base.$ext";
    }

    /** Pfad eines Mediums in der Ausgabe (FILE) – im ZIP-Export liegt die Datei genau dort */
    public static function mediaPath(array $m): string
    {
        $ext  = pathinfo($m['file_name'], PATHINFO_EXTENSION);
        $base = pathinfo((string) ($m['original_name'] ?: $m['title'] ?: 'medium'), PATHINFO_FILENAME);
        $base = trim(preg_replace('/[^\p{L}\p{N} ._-]+/u', '_', $base), ' ._') ?: 'medium';
        return 'media/' . $m['id'] . '-' . mb_substr($base, 0, 80) . ".$ext";
    }

    // ── Personen ──────────────────────────────────────────

    private function persons(): void
    {
        $lastId = 0;
        $stmt = $this->db->prepare('SELECT id, sex, restriction, notes FROM persons
                                    WHERE tree_id = ? AND id > ? ORDER BY id LIMIT ' . self::BATCH);
        while (true) {
            $stmt->execute([$this->treeId, $lastId]);
            $persons = $stmt->fetchAll();
            if (!$persons) break;
            $ids = array_map(fn($p) => (int) $p['id'], $persons);
            $in  = implode(',', $ids);
            $lastId = end($ids);

            $names   = $this->group("SELECT * FROM person_names WHERE person_id IN ($in) ORDER BY person_id, sort_order, id", 'person_id');
            $events  = $this->group("SELECT e.*, " . self::PLACE_COLS . " FROM person_events e LEFT JOIN places pl ON pl.id = e.place_id
                                     WHERE e.person_id IN ($in) ORDER BY e.person_id, e.sort_order, e.id", 'person_id');
            $evIds   = implode(',', array_merge([0], ...array_map(fn($l) => array_column($l, 'id'), array_values($events))));
            $evCits  = $this->group("SELECT * FROM event_citations WHERE event_id IN ($evIds) ORDER BY event_id, sort_order, id", 'event_id');
            $assos   = $this->group("SELECT * FROM event_associations WHERE event_id IN ($evIds) ORDER BY event_id, sort_order, id", 'event_id');
            $pCits   = $this->group("SELECT * FROM person_citations WHERE person_id IN ($in) ORDER BY person_id, sort_order, id", 'person_id');
            $members = $this->group("SELECT person_id, family_id, role, pedigree FROM family_members
                                     WHERE person_id IN ($in) ORDER BY person_id, id", 'person_id');
            $pAssos  = $this->group("SELECT * FROM person_associations WHERE person_id IN ($in) ORDER BY person_id, sort_order, id", 'person_id');
            $pMedia  = $this->group("SELECT person_id, media_id, is_primary FROM person_media
                                     WHERE person_id IN ($in) ORDER BY person_id, sort_order, id", 'person_id');
            $evMedia = $this->group("SELECT event_id, media_id FROM event_media WHERE event_id IN ($evIds) ORDER BY event_id, sort_order, id", 'event_id');

            foreach ($persons as $p) {
                $id = (int) $p['id'];
                if (!$this->hasPerson($id)) continue;
                $this->line(0, 'INDI', null, "@I$id@");
                foreach ($names[$id] ?? [] as $n) $this->name($n);
                if ($p['sex'] !== 'U') $this->line(1, 'SEX', $p['sex']);
                if ($p['restriction']) $this->line(1, 'RESN', strtolower($p['restriction']));

                foreach ($events[$id] ?? [] as $e) {
                    $this->event(1, $e, [
                        'AGE' => $e['age'],
                    ]);
                    foreach ($assos[$e['id']] ?? [] as $a) {
                        if ($this->hasPerson($a['person_id'])) $this->association(2, '_ASSO', $a['person_id'], $a);
                    }
                    foreach ($evCits[$e['id']] ?? [] as $c) $this->citation(2, $c);
                    $this->mediaRefs(2, $evMedia[$e['id']] ?? []);
                }

                foreach ($members[$id] ?? [] as $m) {
                    if ($m['role'] === 'CHIL' && $this->hasFamily($m['family_id'])) {
                        $this->line(1, 'FAMC', "@F{$m['family_id']}@");
                        if ($m['pedigree']) $this->line(2, 'PEDI', strtolower($m['pedigree']));
                    }
                }
                foreach ($members[$id] ?? [] as $m) {
                    if ($m['role'] !== 'CHIL' && $this->hasFamily($m['family_id'])) $this->line(1, 'FAMS', "@F{$m['family_id']}@");
                }

                foreach ($pAssos[$id] ?? [] as $a) {
                    if ($this->hasPerson($a['associate_id'])) $this->association(1, 'ASSO', $a['associate_id'], $a);
                }
                foreach ($pCits[$id] ?? [] as $c) $this->citation(1, $c);
                $this->mediaRefs(1, $pMedia[$id] ?? []);
                $this->text(1, 'NOTE', $p['notes']);
                $this->stats['persons']++;
            }
        }
    }

    private function name(array $n): void
    {
        $surname = trim(($n['surname_prefix'] ?? '') . ' ' . ($n['surname'] ?? ''));
        $value   = trim(implode(' ', array_filter([
            $n['given_name'],
            $surname !== '' || $n['given_name'] === null ? "/$surname/" : null,
            $n['name_suffix'],
        ], fn($v) => $v !== null && $v !== '')));

        $this->line(1, 'NAME', $value !== '' ? $value : '//');
        if ($n['name_type'] && $n['name_type'] !== 'BIRTH') $this->line(2, 'TYPE', strtolower($n['name_type']));
        foreach (['NPFX' => 'name_prefix', 'GIVN' => 'given_name', 'NICK' => 'nickname',
                  'SPFX' => 'surname_prefix', 'SURN' => 'surname', 'NSFX' => 'name_suffix'] as $tag => $col) {
            if (($n[$col] ?? '') !== '') $this->line(2, $tag, $n[$col]);
        }
    }

    // ── Familien ──────────────────────────────────────────

    private function families(): void
    {
        $lastId = 0;
        $stmt = $this->db->prepare('SELECT id, notes FROM families
                                    WHERE tree_id = ? AND id > ? ORDER BY id LIMIT ' . self::BATCH);
        while (true) {
            $stmt->execute([$this->treeId, $lastId]);
            $families = $stmt->fetchAll();
            if (!$families) break;
            $ids = array_map(fn($f) => (int) $f['id'], $families);
            $in  = implode(',', $ids);
            $lastId = end($ids);

            // Kinder nach Geburtsjahr (soweit bekannt), sonst in Eingabereihenfolge
            $members = $this->group("
                SELECT fm.family_id, fm.person_id, fm.role,
                       (SELECT MIN(e.event_date) FROM person_events e
                         WHERE e.person_id = fm.person_id AND e.event_type IN ('BIRT','CHR','BAPM')) AS born
                FROM family_members fm WHERE fm.family_id IN ($in)
                ORDER BY fm.family_id, FIELD(fm.role, 'HUSB', 'WIFE', 'CHIL'), fm.id", 'family_id');
            $events  = $this->group("SELECT e.*, " . self::PLACE_COLS . " FROM family_events e LEFT JOIN places pl ON pl.id = e.place_id
                                     WHERE e.family_id IN ($in) ORDER BY e.family_id, e.sort_order, e.id", 'family_id');
            $evIds   = implode(',', array_merge([0], ...array_map(fn($l) => array_column($l, 'id'), array_values($events))));
            $evCits  = $this->group("SELECT * FROM family_event_citations WHERE event_id IN ($evIds) ORDER BY event_id, sort_order, id", 'event_id');
            $fCits   = $this->group("SELECT * FROM family_citations WHERE family_id IN ($in) ORDER BY family_id, sort_order, id", 'family_id');
            $evAssos = $this->group("SELECT * FROM family_event_associations WHERE event_id IN ($evIds) ORDER BY event_id, sort_order, id", 'event_id');
            $fMedia  = $this->group("SELECT family_id, media_id FROM family_media WHERE family_id IN ($in) ORDER BY family_id, sort_order, id", 'family_id');
            $evMedia = $this->group("SELECT event_id, media_id FROM family_event_media WHERE event_id IN ($evIds) ORDER BY event_id, sort_order, id", 'event_id');

            foreach ($families as $f) {
                $id = (int) $f['id'];
                if (!$this->hasFamily($id)) continue;
                $this->line(0, 'FAM', null, "@F$id@");
                $list = array_values(array_filter($members[$id] ?? [], fn($m) => $this->hasPerson($m['person_id'])));
                $children = array_values(array_filter($list, fn($m) => $m['role'] === 'CHIL'));
                usort($children, fn($a, $b) =>
                    (GedcomDate::extractYear((string) $a['born']) ?? PHP_INT_MAX) <=> (GedcomDate::extractYear((string) $b['born']) ?? PHP_INT_MAX));
                foreach ($list as $m) {
                    if ($m['role'] !== 'CHIL') $this->line(1, $m['role'], "@I{$m['person_id']}@");
                }
                foreach ($children as $m) $this->line(1, 'CHIL', "@I{$m['person_id']}@");

                foreach ($events[$id] ?? [] as $e) {
                    $this->event(1, $e, []);
                    foreach (['HUSB' => 'husb_age', 'WIFE' => 'wife_age'] as $tag => $col) {
                        if (($e[$col] ?? '') !== '') {
                            $this->line(2, $tag);
                            $this->line(3, 'AGE', $e[$col]);
                        }
                    }
                    foreach ($evAssos[$e['id']] ?? [] as $a) {
                        if ($this->hasPerson($a['person_id'])) $this->association(2, '_ASSO', $a['person_id'], $a);
                    }
                    foreach ($evCits[$e['id']] ?? [] as $c) $this->citation(2, $c);
                    $this->mediaRefs(2, $evMedia[$e['id']] ?? []);
                }

                foreach ($fCits[$id] ?? [] as $c) $this->citation(1, $c);
                $this->mediaRefs(1, $fMedia[$id] ?? []);
                $this->text(1, 'NOTE', $f['notes']);
                $this->stats['families']++;
            }
        }
    }

    // ── Quellen und Archive ───────────────────────────────

    private function sources(): void
    {
        $sMedia = $this->group('SELECT l.source_id, l.media_id FROM source_media l JOIN sources s ON s.id = l.source_id
                                WHERE s.tree_id = ' . $this->treeId . ' ORDER BY l.source_id, l.sort_order, l.id', 'source_id');
        $stmt = $this->db->prepare('SELECT * FROM sources WHERE tree_id = ? ORDER BY id');
        $stmt->execute([$this->treeId]);
        while ($s = $stmt->fetch()) {
            if ($this->onlyPersons !== null && !isset($this->usedSources[(int) $s['id']])) continue;
            if ($s['repository_id']) $this->usedRepos[(int) $s['repository_id']] = true;
            $this->line(0, 'SOUR', null, "@S{$s['id']}@");
            $this->text(1, 'TITL', $s['title']);
            if ($s['abbreviation']) $this->line(1, 'ABBR', $s['abbreviation']);
            $this->text(1, 'AUTH', $s['author']);
            $this->text(1, 'PUBL', $s['publication']);
            $this->text(1, 'TEXT', $s['source_text']);
            if ($s['repository_id'] || $s['call_number']) {
                // ohne Archiv: leerer REPO-Verweis, damit die Signatur nicht verloren geht
                $this->line(1, 'REPO', $s['repository_id'] ? "@R{$s['repository_id']}@" : null);
                if ($s['call_number']) {
                    $this->line(2, 'CALN', $s['call_number']);
                    if ($s['media_type']) $this->line(3, 'MEDI', strtolower($s['media_type']));
                } elseif ($s['media_type']) {
                    $this->line(2, 'MEDI', strtolower($s['media_type']));
                }
            }
            $this->mediaRefs(1, $sMedia[$s['id']] ?? []);
            $this->text(1, 'NOTE', $s['notes']);
            $this->stats['sources']++;
        }
    }

    private function repositories(): void
    {
        $stmt = $this->db->prepare('SELECT * FROM repositories WHERE tree_id = ? ORDER BY id');
        $stmt->execute([$this->treeId]);
        while ($r = $stmt->fetch()) {
            if ($this->onlyPersons !== null && !isset($this->usedRepos[(int) $r['id']])) continue;
            $this->line(0, 'REPO', null, "@R{$r['id']}@");
            $this->line(1, 'NAME', $r['name']);
            $hasAddr = array_filter([$r['address'], $r['postal_code'], $r['city'], $r['country']]);
            if ($hasAddr) {
                // ADDR enthält die ganze Anschrift; die Teile zusätzlich strukturiert
                $full = implode("\n", array_filter([
                    $r['address'],
                    trim(($r['postal_code'] ?? '') . ' ' . ($r['city'] ?? '')),
                    $r['country'],
                ]));
                $this->text(1, 'ADDR', $full);
                foreach (array_slice(array_values(array_filter(explode("\n", (string) $r['address']), 'trim')), 0, 3) as $i => $l) {
                    $this->line(2, 'ADR' . ($i + 1), trim($l));
                }
                if ($r['city'])        $this->line(2, 'CITY', $r['city']);
                if ($r['postal_code']) $this->line(2, 'POST', $r['postal_code']);
                if ($r['country'])     $this->line(2, 'CTRY', $r['country']);
            }
            if ($r['phone'])   $this->line(1, 'PHON', $r['phone']);
            if ($r['email'])   $this->line(1, 'EMAIL', $r['email']);
            if ($r['website']) $this->line(1, 'WWW', $r['website']);
            $this->text(1, 'NOTE', $r['notes']);
            $this->stats['repositories']++;
        }
    }

    /** Verknüpfte Person: ROLE-Kennung bzw. Freitext als RELA, ggf. mit Notiz */
    private function association(int $level, string $tag, int|string $personId, array $a): void
    {
        $this->line($level, $tag, "@I$personId@");
        $rela = $a['role'] && $a['role'] !== 'OTHER' ? $a['role'] : ($a['role_phrase'] ?: $a['role']);
        if ($rela) $this->line($level + 1, 'RELA', $rela);
        $this->text($level + 1, 'NOTE', $a['notes'] ?? null);
    }

    // ── Medien ────────────────────────────────────────────

    /** OBJE-Datensätze; FILE zeigt auf media/… (so liegen die Dateien im ZIP-Export) */
    private function media(): void
    {
        $stmt = $this->db->prepare('SELECT * FROM media WHERE tree_id = ? ORDER BY id');
        $stmt->execute([$this->treeId]);
        while ($m = $stmt->fetch()) {
            if ($this->onlyPersons !== null && !isset($this->usedMedia[(int) $m['id']])) continue;
            $this->usedMedia[(int) $m['id']] = true;
            $this->line(0, 'OBJE', null, "@M{$m['id']}@");
            $this->line(1, 'FILE', self::mediaPath($m));
            $this->line(2, 'FORM', pathinfo($m['file_name'], PATHINFO_EXTENSION));
            if ($m['title']) $this->line(2, 'TITL', $m['title']);
            if ($m['media_date']) $this->line(1, '_DATE', $m['media_date']);
            $this->text(1, 'NOTE', $m['notes']);
            $this->stats['media']++;
        }
    }

    /** Verweise auf Medien; das Hauptbild einer Person mit _PRIM Y (verbreitete Erweiterung) */
    private function mediaRefs(int $level, array $links): void
    {
        foreach ($links as $l) {
            $this->usedMedia[(int) $l['media_id']] = true;
            $this->line($level, 'OBJE', "@M{$l['media_id']}@");
            if (!empty($l['is_primary'])) $this->line($level + 1, '_PRIM', 'Y');
        }
    }

    // ── Bausteine ─────────────────────────────────────────

    /** Ereignis/Merkmal mit Wert, Art, Datum, Ort und Details */
    private function event(int $level, array $e, array $extra): void
    {
        $value = $e['event_value'] ?? null;
        $hasDetails = ($e['event_date'] ?? '') !== '' || ($e['event_place'] ?? '') !== ''
            || array_filter([$e['event_descriptor'] ?? null, $e['cause'] ?? null, $e['agency'] ?? null, $e['religion'] ?? null,
                             $e['notes'] ?? null, $e['age'] ?? null, $e['husb_age'] ?? null, $e['wife_age'] ?? null]);
        // „1 DEAT Y“: Ereignis fand statt, Einzelheiten unbekannt
        if (($value === null || $value === '') && !$hasDetails && $e['event_type'] !== 'EVEN') $value = 'Y';

        $this->text($level, $e['event_type'], $value);
        $sub = $level + 1;
        if ($e['event_descriptor']) $this->line($sub, 'TYPE', $e['event_descriptor']);
        if ($e['event_date']) {
            $this->line($sub, 'DATE', $e['event_date']);
            if ($e['event_time'] ?? null) $this->line($sub + 1, 'TIME', $e['event_time']);
        }
        if ($e['event_place'])      $this->place($sub, $e);
        foreach ($extra as $tag => $v) if (($v ?? '') !== '') $this->line($sub, $tag, $v);
        if ($e['cause'])            $this->line($sub, 'CAUS', $e['cause']);
        if ($e['agency'])           $this->line($sub, 'AGNC', $e['agency']);
        if ($e['religion'] ?? null) $this->line($sub, 'RELI', $e['religion']);
        $this->text($sub, 'NOTE', $e['notes']);
    }

    /** Ort mit Koordinaten (MAP) und GOV-Kennung (_GOV, GEDCOM-L), soweit unter „Orte“ erfasst */
    private function place(int $level, array $e): void
    {
        $this->line($level, 'PLAC', $e['event_place']);
        if ($e['place_lat'] !== null && $e['place_lon'] !== null) {
            $this->line($level + 1, 'MAP');
            $this->line($level + 2, 'LATI', self::coord((float) $e['place_lat'], 'N', 'S'));
            $this->line($level + 2, 'LONG', self::coord((float) $e['place_lon'], 'E', 'W'));
        }
        if ($e['place_gov']) $this->line($level + 1, '_GOV', $e['place_gov']);
    }

    /** 48.7283 → „N48.7283“, -3.5 → „W3.5“ */
    private static function coord(float $v, string $pos, string $neg): string
    {
        return ($v < 0 ? $neg : $pos) . rtrim(rtrim(number_format(abs($v), 6, '.', ''), '0'), '.');
    }

    /** Quellenangabe: Verweis mit Seite/Abschrift/Link oder – ohne Quelle – als Freitext */
    private function citation(int $level, array $c): void
    {
        $sub = $level + 1;
        if ($c['source_id']) {
            $this->usedSources[(int) $c['source_id']] = true;
            $this->line($level, 'SOUR', "@S{$c['source_id']}@");
            if ($c['page']) $this->line($sub, 'PAGE', $c['page']);
            if ($c['transcription']) {
                $this->line($sub, 'DATA');
                $this->text($sub + 1, 'TEXT', $c['transcription']);
            }
        } else {
            $this->text($level, 'SOUR', $c['transcription'] ?: ($c['page'] ?: $c['url']));
            if ($c['page'] && $c['transcription']) $this->line($sub, 'PAGE', $c['page']);
        }
        if ($c['url']) $this->line($sub, 'WWW', $c['url']);
        if (isset($c['quality']) && $c['quality'] !== null) $this->line($sub, 'QUAY', (string) $c['quality']);
    }

    /** Mehrzeiliger Text: Zeilenumbrüche als CONT (lange Zeilen teilt line() per CONC) */
    private function text(int $level, string $tag, ?string $value): void
    {
        if ($value === null || trim($value) === '') {
            // reine Textfelder ohne Inhalt weglassen, Ereignisse ohne Wert („1 EVEN“) aber ausgeben
            if (!in_array($tag, ['NOTE', 'TITL', 'AUTH', 'PUBL', 'TEXT', 'ADDR', 'SOUR'], true)) $this->line($level, $tag);
            return;
        }
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", rtrim($value)));
        foreach ($lines as $i => $l) {
            if ($i === 0) $this->line($level, $tag, $l);
            else          $this->line($level + 1, 'CONT', $l);
        }
    }

    /** Lange Zeile teilen – nicht an Leerzeichen, weil manche Programme sie an CONC-Grenzen verlieren */
    private static function split(string $s): array
    {
        $out = [];
        while (mb_strlen($s) > self::MAX_LINE) {
            $cut = self::MAX_LINE;
            while ($cut > 20 && (mb_substr($s, $cut - 1, 1) === ' ' || mb_substr($s, $cut, 1) === ' ')) $cut--;
            $out[] = mb_substr($s, 0, $cut);
            $s     = mb_substr($s, $cut);
        }
        $out[] = $s;
        return $out;
    }

    private function line(int $level, string $tag, ?string $value = null, ?string $xref = null): void
    {
        $value = $value === null ? '' : str_replace(["\r", "\n"], ' ', $value);
        // nur ein führendes „@“ verdoppeln (wie GEDCOM 7); Verweise (@I1@) bleiben unverändert –
        // „@“ mitten im Text (E-Mail) lesen fast alle Programme wörtlich
        if (str_starts_with($value, '@') && !preg_match('/^@[A-Z]\d+@$/', $value)) $value = '@' . $value;
        $chunks = self::split($value);
        ($this->out)($level . ($xref ? " $xref" : '') . " $tag" . ($chunks[0] !== '' ? " $chunks[0]" : '') . "\r\n");
        // CONC gehört zum Element selbst – bei einer CONT-Zeile also auf deren Ebene
        $concLevel = $tag === 'CONT' ? $level : $level + 1;
        for ($i = 1, $n = count($chunks); $i < $n; $i++) ($this->out)("$concLevel CONC $chunks[$i]\r\n");
    }

    /** Zeilen einer Abfrage nach einer Spalte gruppieren */
    private function group(string $sql, string $key): array
    {
        $out = [];
        foreach ($this->db->query($sql) as $row) $out[$row[$key]][] = $row;
        return $out;
    }
}
