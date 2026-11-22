<?php

declare(strict_types=1);

/**
 * Statistik eines Stammbaums: Umfang, Geschlechter, Geburten je Jahrzehnt, Lebensalter und
 * Heiratsalter je Jahrhundert, häufigste Namen/Orte/Berufe, Datenqualität.
 *
 * Jahreszahlen und Alter kommen aus GedcomDate::sortKey (ungefähre Angaben wie ABT/BEF zählen mit
 * ihrem Jahr). Für Besucher zählen nur nicht-private Personen (Privacy::personSql).
 */
final class StatisticsController extends TreeScopedController
{
    private const BIRTH_TYPES = ['BIRT' => 1, 'CHR' => 2, 'BAPM' => 2];      // Rang: Geburt vor Taufe
    private const DEATH_TYPES = ['DEAT' => 1, 'BURI' => 2, 'CREM' => 2];
    private const MAX_AGE     = 115;
    private const TOP         = 15;

    public function index(): void
    {
        $persons = $this->personDates();
        $this->json([
            'counts'         => $this->counts(),
            'sex'            => $this->sexCounts(),
            'births'         => $this->birthsPerDecade($persons),
            'lifespan'       => $this->lifespanPerCentury($persons),
            'marriage_age'   => $this->marriageAgePerCentury($persons),
            'surnames'       => $this->topSurnames(),
            'given_names'    => ['M' => $this->topGivenNames('M'), 'F' => $this->topGivenNames('F')],
            'birth_places'   => $this->topPlaces(array_keys(self::BIRTH_TYPES)),
            'death_places'   => $this->topPlaces(array_keys(self::DEATH_TYPES)),
            'occupations'    => $this->topOccupations(),
            'event_types'    => $this->eventTypes(),
            'quality'        => $this->quality($persons),
            'oldest'         => $this->oldest($persons),
            'range'          => $this->yearRange($persons),
        ]);
    }

    /**
     * Daten für die Ortskarte: Orte mit Koordinaten (fehlende vom nächsten übergeordneten Ort, dann „ungefähr“)
     * und Anzahl Ereignisse je Ort, Art (B Geburt/Taufe, D Tod/Begräbnis, M Heirat, O übrige) und Jahrzehnt
     * (0 = ohne Jahr). Kompakt als Listen, gefiltert wird im Browser.
     */
    public function map(): void
    {
        // Koordinaten aller Orte, fehlende entlang der Oberorte ergänzen
        // Ersatz nur durch Siedlungen (Gemeinde, Stadt …): ein Gebiet wie Landkreis oder Staat würde den Ort
        // an dessen Mittelpunkt setzen, ebenso jede oberste Ebene ohne eigenen Oberort
        $all = [];
        foreach ($this->db->query('SELECT id, parent_id, name, full_name, place_type, latitude, longitude FROM places') as $r) $all[(int) $r['id']] = $r;
        $isArea = PlaceIndex::isArea(...);
        $coord = function (int $id) use ($all, $isArea): ?array {
            for ($i = $id, $hops = 0; $i && isset($all[$i]) && $hops < 4; $i = (int) $all[$i]['parent_id'], $hops++) {
                if ($hops > 0 && $isArea($all[$i])) return null;
                if ($all[$i]['latitude'] !== null && $all[$i]['longitude'] !== null) {
                    return [(float) $all[$i]['latitude'], (float) $all[$i]['longitude'], $i !== $id];
                }
            }
            return null;
        };

        $year = "CAST(REGEXP_SUBSTR(e.event_date, '[0-9]{3,4}') AS UNSIGNED)";
        $dec  = "COALESCE(FLOOR($year / 10) * 10, 0)";
        $rows = $this->rows("
            SELECT e.place_id, CASE WHEN e.event_type IN ('BIRT','CHR','BAPM') THEN 'B'
                                    WHEN e.event_type IN ('DEAT','BURI','CREM') THEN 'D' ELSE 'O' END AS kind,
                   $dec AS decade, COUNT(*) AS n
            FROM person_events e JOIN persons p ON p.id = e.person_id
            WHERE p.tree_id = ? AND e.place_id IS NOT NULL" . Privacy::personSql() . "
            GROUP BY e.place_id, kind, decade
            UNION ALL
            SELECT e.place_id, CASE WHEN e.event_type = 'MARR' THEN 'M' ELSE 'O' END, $dec, COUNT(*)
            FROM family_events e JOIN families f ON f.id = e.family_id
            WHERE f.tree_id = ? AND e.place_id IS NOT NULL" . Privacy::familySql('f.id') . "
            GROUP BY e.place_id, 2, 3", [$this->treeId, $this->treeId]);

        $places = [];   // place_id → Index in der Ausgabe
        $out    = ['places' => [], 'counts' => [], 'without_coords' => 0];
        foreach ($rows as $r) {
            $pid = (int) $r['place_id'];
            if (!isset($places[$pid])) {
                $c = $coord($pid);
                if (!$c) { $places[$pid] = -1; }
                else {
                    $places[$pid] = count($out['places']);
                    $out['places'][] = [$pid, $all[$pid]['name'], $all[$pid]['full_name'], round($c[0], 5), round($c[1], 5), $c[2] ? 1 : 0];
                }
            }
            if ($places[$pid] < 0) { $out['without_coords'] += (int) $r['n']; continue; }
            $d = (int) $r['decade'];
            $out['counts'][] = [$places[$pid], $r['kind'], $d >= 500 && $d <= 2100 ? $d : 0, (int) $r['n']];
        }
        $this->json($out);
    }

    // ── Grundlagen ────────────────────────────────────────

    private function scalar(string $sql, ?array $params = null): int
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params ?? [$this->treeId]);
        return (int) $stmt->fetchColumn();
    }

    private function rows(string $sql, ?array $params = null): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params ?? [$this->treeId]);
        return $stmt->fetchAll();
    }

    /** JJJJMMTT aus dem Sortierschlüssel (ohne BEF/AFT-Feinheit) */
    private static function ymd(?string $date): ?int
    {
        $k = GedcomDate::sortKey($date);
        return $k === null ? null : intdiv($k, 10);
    }

    /** Vollendete Jahre zwischen zwei JJJJMMTT-Werten; fehlt Monat/Tag, zählt nur das Jahr */
    private static function age(int $from, int $to): int
    {
        if ($from % 10000 === 0 || $to % 10000 === 0) return intdiv($to, 10000) - intdiv($from, 10000);
        return intdiv($to - $from, 10000);
    }

    /**
     * Je Person: Geschlecht, Geburts- und Sterbedatum (JJJJMMTT) – Geburt vor Taufe, Tod vor Begräbnis.
     * @return array<int, array{sex: string, birth: ?int, death: ?int}>
     */
    private function personDates(): array
    {
        $out = [];
        foreach ($this->rows('SELECT p.id, p.sex FROM persons p WHERE p.tree_id = ?' . Privacy::personSql()) as $p) {
            $out[(int) $p['id']] = ['sex' => $p['sex'], 'birth' => null, 'death' => null, 'br' => 9, 'dr' => 9];
        }
        $types = "'" . implode("','", [...array_keys(self::BIRTH_TYPES), ...array_keys(self::DEATH_TYPES)]) . "'";
        $stmt  = $this->db->prepare("
            SELECT e.person_id, e.event_type, e.event_date
            FROM person_events e JOIN persons p ON p.id = e.person_id
            WHERE p.tree_id = ? AND e.event_type IN ($types) AND e.event_date IS NOT NULL" . Privacy::personSql());
        $stmt->execute([$this->treeId]);
        while ($e = $stmt->fetch()) {
            $id = (int) $e['person_id'];
            if (!isset($out[$id]) || ($d = self::ymd($e['event_date'])) === null || $d < 5000000) continue;   // Jahr < 500: Tippfehler
            if (isset(self::BIRTH_TYPES[$e['event_type']])) {
                $rank = self::BIRTH_TYPES[$e['event_type']];
                if ($rank < $out[$id]['br']) { $out[$id]['birth'] = $d; $out[$id]['br'] = $rank; }
            } else {
                $rank = self::DEATH_TYPES[$e['event_type']];
                if ($rank < $out[$id]['dr']) { $out[$id]['death'] = $d; $out[$id]['dr'] = $rank; }
            }
        }
        return $out;
    }

    // ── Umfang ────────────────────────────────────────────

    private function counts(): array
    {
        $priv = Privacy::personSql();
        return [
            'persons'      => $this->scalar("SELECT COUNT(*) FROM persons p WHERE p.tree_id = ?$priv"),
            'families'     => $this->scalar('SELECT COUNT(*) FROM families f WHERE f.tree_id = ?' . Privacy::familySql('f.id')),
            'events'       => $this->scalar("SELECT COUNT(*) FROM person_events e JOIN persons p ON p.id = e.person_id WHERE p.tree_id = ?$priv")
                            + $this->scalar('SELECT COUNT(*) FROM family_events e JOIN families f ON f.id = e.family_id WHERE f.tree_id = ?'
                                            . Privacy::familySql('f.id')),
            'sources'      => $this->scalar('SELECT COUNT(*) FROM sources WHERE tree_id = ?'),
            'repositories' => $this->scalar('SELECT COUNT(*) FROM repositories WHERE tree_id = ?'),
            'citations'    => $this->scalar("SELECT COUNT(*) FROM event_citations c JOIN person_events e ON e.id = c.event_id
                                             JOIN persons p ON p.id = e.person_id WHERE p.tree_id = ?$priv")
                            + $this->scalar("SELECT COUNT(*) FROM person_citations c JOIN persons p ON p.id = c.person_id WHERE p.tree_id = ?$priv"),
            'places'       => $this->scalar("SELECT COUNT(DISTINCT e.place_id) FROM person_events e JOIN persons p ON p.id = e.person_id
                                             WHERE p.tree_id = ? AND e.place_id IS NOT NULL$priv"),
            'media'        => $this->scalar('SELECT COUNT(*) FROM media m WHERE m.tree_id = ?' . Privacy::mediaSql('m')),
            'surnames'     => $this->scalar("SELECT COUNT(DISTINCT n.surname) FROM person_names n JOIN persons p ON p.id = n.person_id
                                             WHERE p.tree_id = ? AND n.surname <> ''$priv"),
        ];
    }

    private function sexCounts(): array
    {
        $out = ['M' => 0, 'F' => 0, 'X' => 0, 'U' => 0];
        foreach ($this->rows('SELECT p.sex, COUNT(*) AS n FROM persons p WHERE p.tree_id = ?' . Privacy::personSql() . ' GROUP BY p.sex') as $r) {
            $out[$r['sex']] = (int) $r['n'];
        }
        return $out;
    }

    // ── Zeitreihen ────────────────────────────────────────

    /** Geburten (bzw. Taufen) je Jahrzehnt, lückenlos vom ersten bis zum letzten Jahrzehnt */
    private function birthsPerDecade(array $persons): array
    {
        $n = [];
        foreach ($persons as $p) {
            if ($p['birth'] === null) continue;
            $dec = intdiv(intdiv($p['birth'], 10000), 10) * 10;
            $n[$dec] = ($n[$dec] ?? 0) + 1;
        }
        if (!$n) return [];
        // Ausreißer (einzelne Tippfehler weit vor dem Rest) nicht die Achse bestimmen lassen
        ksort($n);
        $keys  = array_keys($n);
        $total = array_sum($n);
        $cum   = 0;
        $start = $keys[0];
        foreach ($n as $dec => $c) { $cum += $c; if ($cum > $total * 0.002) { $start = $dec; break; } }
        $out = [];
        for ($d = $start; $d <= end($keys); $d += 10) $out[] = ['decade' => $d, 'count' => $n[$d] ?? 0];
        return $out;
    }

    /** Durchschnittliches Sterbealter und Anteil vor dem 5. Lebensjahr Verstorbener – je Geburtsjahrhundert */
    private function lifespanPerCentury(array $persons): array
    {
        $acc = [];
        foreach ($persons as $p) {
            if ($p['birth'] === null || $p['death'] === null) continue;
            $age = self::age($p['birth'], $p['death']);
            if ($age < 0 || $age > self::MAX_AGE) continue;
            $c = intdiv(intdiv($p['birth'], 10000), 100) * 100;
            $acc[$c] ??= ['n' => 0, 'sum' => 0, 'child' => 0, 'adult_n' => 0, 'adult_sum' => 0];
            $acc[$c]['n']++;
            $acc[$c]['sum'] += $age;
            if ($age < 5) $acc[$c]['child']++;
            else { $acc[$c]['adult_n']++; $acc[$c]['adult_sum'] += $age; }
        }
        ksort($acc);
        $out = [];
        foreach ($acc as $c => $a) {
            if ($a['n'] < 5) continue;   // zu wenige für eine Aussage
            $out[] = [
                'century'     => $c,
                'count'       => $a['n'],
                'avg_age'     => round($a['sum'] / $a['n'], 1),
                'avg_adult'   => $a['adult_n'] ? round($a['adult_sum'] / $a['adult_n'], 1) : null,
                'child_share' => round(100 * $a['child'] / $a['n'], 1),
            ];
        }
        return $out;
    }

    /** Durchschnittliches Alter bei der (ersten) Heirat je Geburtsjahrhundert, getrennt nach Mann und Frau */
    private function marriageAgePerCentury(array $persons): array
    {
        $stmt = $this->db->prepare("
            SELECT fm.person_id, e.event_date
            FROM family_events e
            JOIN families f        ON f.id = e.family_id
            JOIN family_members fm ON fm.family_id = f.id AND fm.role IN ('HUSB', 'WIFE')
            WHERE f.tree_id = ? AND e.event_type = 'MARR' AND e.event_date IS NOT NULL");
        $stmt->execute([$this->treeId]);
        $first = [];   // Person → früheste Heirat
        while ($r = $stmt->fetch()) {
            $d = self::ymd($r['event_date']);
            $id = (int) $r['person_id'];
            if ($d !== null && (!isset($first[$id]) || $d < $first[$id])) $first[$id] = $d;
        }
        $acc = [];
        foreach ($first as $id => $m) {
            $p = $persons[$id] ?? null;
            if (!$p || $p['birth'] === null || !in_array($p['sex'], ['M', 'F'], true)) continue;
            $age = self::age($p['birth'], $m);
            if ($age < 12 || $age > 80) continue;   // offensichtlich falsche Daten
            $c = intdiv(intdiv($p['birth'], 10000), 100) * 100;
            $acc[$c][$p['sex']][] = $age;
        }
        ksort($acc);
        $out = [];
        foreach ($acc as $c => $bySex) {
            $avg = fn(array $a) => count($a) >= 5 ? round(array_sum($a) / count($a), 1) : null;
            $row = ['century' => $c, 'M' => $avg($bySex['M'] ?? []), 'F' => $avg($bySex['F'] ?? []),
                    'count_M' => count($bySex['M'] ?? []), 'count_F' => count($bySex['F'] ?? [])];
            if ($row['M'] !== null || $row['F'] !== null) $out[] = $row;
        }
        return $out;
    }

    // ── Ranglisten ────────────────────────────────────────

    private function topSurnames(): array
    {
        return array_map(fn($r) => ['label' => $r['label'], 'count' => (int) $r['n']], $this->rows("
            SELECT TRIM(CONCAT_WS(' ', NULLIF(n.surname_prefix, ''), n.surname)) AS label, COUNT(DISTINCT n.person_id) AS n
            FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ? AND n.surname IS NOT NULL AND n.surname <> '' AND n.name_type = 'BIRTH'" . Privacy::personSql() . "
            GROUP BY label ORDER BY n DESC, label LIMIT " . self::TOP));
    }

    /** Häufigste Vornamen (jeder Vorname eines Namens zählt, z. B. „Johann Georg“ → Johann, Georg) */
    private function topGivenNames(string $sex): array
    {
        $n = array_slice($this->givenNameCounts($sex), 0, self::TOP, true);
        return array_map(fn($k, $v) => ['label' => (string) $k, 'count' => $v], array_keys($n), $n);
    }

    /** Personen je Vorname (Hauptname), häufigste zuerst; $sex null = alle */
    private function givenNameCounts(?string $sex): array
    {
        $stmt = $this->db->prepare("
            SELECT n.given_name FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ?" . ($sex ? ' AND p.sex = ?' : '') . " AND n.sort_order = 0 AND n.given_name <> ''" . Privacy::personSql());
        $stmt->execute($sex ? [$this->treeId, $sex] : [$this->treeId]);
        $n = [];
        while (($g = $stmt->fetchColumn()) !== false) {
            foreach (array_unique(preg_split('/[\s,]+/u', trim((string) $g))) as $part) {
                $part = trim($part, '*"„“()');   // webtrees markiert den Rufnamen mit *
                if (mb_strlen($part) < 2 || str_ends_with($part, '.')) continue;
                $n[$part] = ($n[$part] ?? 0) + 1;
            }
        }
        arsort($n);
        return $n;
    }

    /**
     * GET statistics/cloud?kind=given|place&n=N[&sex=M|F] – die N häufigsten Vornamen bzw. Orte für die Wolke
     * (Nachnamen: SurnameController, surnames?cloud=N). Antwort: { cloud: [{ label, count, id?, title? }] }
     */
    public function cloud(): void
    {
        $limit = max(1, min(300, (int) ($_GET['n'] ?? 100)));
        $kind  = (string) ($_GET['kind'] ?? '');
        if ($kind === 'given') {
            $sex = in_array($_GET['sex'] ?? '', ['M', 'F'], true) ? $_GET['sex'] : null;
            $n = array_slice($this->givenNameCounts($sex), 0, $limit, true);
            $this->json(['cloud' => array_map(fn($k, $v) => ['label' => (string) $k, 'count' => $v], array_keys($n), $n)]);
        } elseif ($kind === 'place') {
            $this->json(['cloud' => $this->placeCloud($limit)]);
        } else {
            throw new HttpError(400, 'Unbekannte Art');
        }
    }

    /**
     * Orte nach Anzahl Personen mit einem Ereignis dort (eigene Ereignisse und die ihrer Familien).
     * Gleichnamige Orte bekommen ihren übergeordneten Ort dazu („Hausen (Landshut)“).
     */
    private function placeCloud(int $limit): array
    {
        $rows = $this->rows("
            SELECT pl.id, pl.name, pl.full_name, par.name AS parent, x.n FROM (
                SELECT place_id, COUNT(DISTINCT pid) AS n FROM (
                    SELECT e.place_id, e.person_id AS pid
                    FROM person_events e JOIN persons p ON p.id = e.person_id
                    WHERE p.tree_id = ? AND e.place_id IS NOT NULL" . Privacy::personSql() . "
                    UNION ALL
                    SELECT e.place_id, m.person_id
                    FROM family_events e JOIN families f ON f.id = e.family_id
                    JOIN family_members m ON m.family_id = f.id AND m.role IN ('HUSB', 'WIFE')
                    JOIN persons p ON p.id = m.person_id
                    WHERE f.tree_id = ? AND e.place_id IS NOT NULL" . Privacy::familySql('f.id') . Privacy::personSql() . "
                ) ev GROUP BY place_id ORDER BY n DESC, place_id LIMIT $limit
            ) x
            JOIN places pl ON pl.id = x.place_id
            LEFT JOIN places par ON par.id = pl.parent_id
            ORDER BY x.n DESC, pl.name", [$this->treeId, $this->treeId]);

        $same = array_count_values(array_map(fn($r) => mb_strtolower($r['name']), $rows));
        return array_map(fn($r) => [
            'id'    => (int) $r['id'],
            'label' => $same[mb_strtolower($r['name'])] > 1 && $r['parent'] ? "{$r['name']} ({$r['parent']})" : $r['name'],
            'title' => $r['full_name'],
            'count' => (int) $r['n'],
        ], $rows);
    }

    private function topPlaces(array $types): array
    {
        $in = "'" . implode("','", $types) . "'";
        return array_map(fn($r) => ['label' => $r['name'], 'title' => $r['full_name'], 'count' => (int) $r['n']], $this->rows("
            SELECT pl.name, pl.full_name, COUNT(DISTINCT e.person_id) AS n
            FROM person_events e JOIN persons p ON p.id = e.person_id JOIN places pl ON pl.id = e.place_id
            WHERE p.tree_id = ? AND e.event_type IN ($in)" . Privacy::personSql() . "
            GROUP BY pl.id ORDER BY n DESC, pl.name LIMIT " . self::TOP));
    }

    private function topOccupations(): array
    {
        return array_map(fn($r) => ['label' => $r['label'], 'count' => (int) $r['n']], $this->rows("
            SELECT TRIM(e.event_value) AS label, COUNT(DISTINCT e.person_id) AS n
            FROM person_events e JOIN persons p ON p.id = e.person_id
            WHERE p.tree_id = ? AND e.event_type = 'OCCU' AND e.event_value IS NOT NULL AND TRIM(e.event_value) <> ''" . Privacy::personSql() . "
            GROUP BY label ORDER BY n DESC, label LIMIT " . self::TOP));
    }

    /** Erfasste Ereignisse und Merkmale je Art (Personen und Familien) */
    private function eventTypes(): array
    {
        $rows = [
            ...array_map(fn($r) => [...$r, 'scope' => 'person'], $this->rows("
                SELECT e.event_type AS type, COUNT(*) AS n FROM person_events e JOIN persons p ON p.id = e.person_id
                WHERE p.tree_id = ?" . Privacy::personSql() . ' GROUP BY e.event_type')),
            ...array_map(fn($r) => [...$r, 'scope' => 'family'], $this->rows("
                SELECT e.event_type AS type, COUNT(*) AS n FROM family_events e JOIN families f ON f.id = e.family_id
                WHERE f.tree_id = ?" . Privacy::familySql('f.id') . ' GROUP BY e.event_type')),
        ];
        usort($rows, fn($a, $b) => $b['n'] <=> $a['n']);
        return array_map(fn($r) => ['type' => $r['type'], 'scope' => $r['scope'], 'count' => (int) $r['n']], $rows);
    }

    // ── Datenqualität ─────────────────────────────────────

    private function quality(array $persons): array
    {
        $priv  = Privacy::personSql();
        $total = count($persons);
        $noBirth = count(array_filter($persons, fn($p) => $p['birth'] === null));
        $dead    = count(array_filter($persons, fn($p) => $p['death'] !== null));

        $noParents = $this->scalar("SELECT COUNT(*) FROM persons p WHERE p.tree_id = ?$priv
            AND NOT EXISTS (SELECT 1 FROM family_members m WHERE m.person_id = p.id AND m.role = 'CHIL')");
        $isolated  = $this->scalar("SELECT COUNT(*) FROM persons p WHERE p.tree_id = ?$priv
            AND NOT EXISTS (SELECT 1 FROM family_members m WHERE m.person_id = p.id)");
        $noSource  = $this->scalar("SELECT COUNT(*) FROM persons p WHERE p.tree_id = ?$priv
            AND NOT EXISTS (SELECT 1 FROM person_citations c WHERE c.person_id = p.id)
            AND NOT EXISTS (SELECT 1 FROM event_citations c JOIN person_events e ON e.id = c.event_id WHERE e.person_id = p.id)");
        $noSex     = $this->scalar("SELECT COUNT(*) FROM persons p WHERE p.tree_id = ? AND p.sex = 'U'$priv");

        // Datumsangaben, die nicht dem GEDCOM-Format entsprechen
        $invalid = 0;
        $stmt = $this->db->prepare("SELECT e.event_date FROM person_events e JOIN persons p ON p.id = e.person_id
                                    WHERE p.tree_id = ? AND e.event_date IS NOT NULL AND e.event_date <> ''$priv");
        $stmt->execute([$this->treeId]);
        while (($d = $stmt->fetchColumn()) !== false) if (!GedcomDate::isValid((string) $d)) $invalid++;

        // Unplausibel: Tod vor Geburt oder älter als MAX_AGE
        $implausible = count(array_filter($persons, fn($p) => $p['birth'] !== null && $p['death'] !== null
            && (($a = self::age($p['birth'], $p['death'])) < 0 || $a > self::MAX_AGE)));

        return [
            'total'         => $total,
            'with_birth'    => $total - $noBirth,
            'with_death'    => $dead,
            'with_parents'  => $total - $noParents,
            'with_source'   => $total - $noSource,
            'with_sex'      => $total - $noSex,
            'isolated'      => $isolated,
            'invalid_dates' => $invalid,
            'implausible'   => $implausible,
        ];
    }

    // ── Besonderheiten ────────────────────────────────────

    /** Die ältesten (verstorbenen) Personen */
    private function oldest(array $persons): array
    {
        $ages = [];
        foreach ($persons as $id => $p) {
            if ($p['birth'] === null || $p['death'] === null) continue;
            $a = self::age($p['birth'], $p['death']);
            if ($a >= 0 && $a <= self::MAX_AGE) $ages[$id] = $a;
        }
        arsort($ages);
        $ages = array_slice($ages, 0, 10, true);
        if (!$ages) return [];
        $in   = implode(',', array_map('intval', array_keys($ages)));
        $names = [];
        foreach ($this->rows("
            SELECT n.person_id, CONCAT_WS(', ', NULLIF(TRIM(CONCAT_WS(' ', n.surname_prefix, n.surname)), ''), NULLIF(n.given_name, '')) AS name
            FROM person_names n WHERE n.person_id IN ($in) AND n.sort_order = 0", []) as $r) {
            $names[(int) $r['person_id']] = $r['name'];
        }
        $out = [];
        foreach ($ages as $id => $a) {
            $out[] = ['person_id' => $id, 'name' => $names[$id] ?? '–', 'sex' => $persons[$id]['sex'], 'age' => $a,
                      'birth_year' => intdiv($persons[$id]['birth'], 10000), 'death_year' => intdiv($persons[$id]['death'], 10000)];
        }
        return $out;
    }

    private function yearRange(array $persons): array
    {
        $years = array_map(fn($p) => intdiv($p['birth'], 10000), array_filter($persons, fn($p) => $p['birth'] !== null));
        if (!$years) return ['first' => null, 'last' => null];
        sort($years);
        // robuste Grenzen: 0,2 %-Ausreißer (Tippfehler) abschneiden
        $cut   = (int) floor(count($years) * 0.002);
        $first = $years[$cut];
        $last  = $years[count($years) - 1 - $cut];
        return ['first' => $first, 'last' => $last];
    }
}
