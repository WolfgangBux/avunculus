<?php

declare(strict_types=1);

/**
 * Daten für Ausdrucke und Berichte (Ahnentafel, Nachkommen, Familienblatt, Listen).
 * Das Frontend setzt daraus eine druckfertige HTML-Seite zusammen; als PDF speichert der Browser.
 *
 * Personen kommen als „Fakten“: schlanke Person (PersonQuery::slim, für Besucher anonymisiert)
 * plus Taufe, Begräbnis und Berufe. Listen filtern private Personen/Familien für Besucher heraus.
 */
class ReportController extends TreeScopedController
{
    /** Höchstzahl der Zeilen einer Liste bzw. Personen eines Nachkommenberichts */
    private const LIST_LIMIT        = 5000;
    private const DESCENDANT_LIMIT  = 3000;
    private const MAX_ANCESTOR_GENS = 12;
    private const MAX_DESC_GENS     = 10;

    // GET /api/reports/ancestors?person=&generations=
    // Ahnentafel nach Kekule: 1 = Person, 2n = Vater, 2n+1 = Mutter von n.
    public function ancestors(): void
    {
        $root = $this->rootPerson();
        $gens = max(1, min(self::MAX_ANCESTOR_GENS, (int) ($_GET['generations'] ?? 5)));

        $entries   = [['n' => 1, 'gen' => 1, 'person_id' => $root]];
        $numberOf  = [$root => 1];   // erste Nummer je Person (Ahnenschwund: weitere verweisen darauf)
        $frontier  = [1 => $root];
        $marriages = [];             // Nummer des Vaters → Heirat der Eltern
        for ($g = 2; $g <= $gens && $frontier; $g++) {
            $parents = $this->parentFamilies(array_values($frontier));
            $next = [];
            foreach ($frontier as $n => $pid) {
                $fam = $parents[$pid] ?? null;
                if (!$fam) continue;
                [$father, $mother] = $fam['parents'];
                foreach ([2 * $n => $father, 2 * $n + 1 => $mother] as $num => $parent) {
                    if (!$parent) continue;
                    $entry = ['n' => $num, 'gen' => $g, 'person_id' => $parent];
                    if (isset($numberOf[$parent])) {
                        $entry['same_as'] = $numberOf[$parent];   // schon weiter vorn – nicht noch einmal aufklappen
                    } else {
                        $numberOf[$parent] = $num;
                        $next[$num] = $parent;
                    }
                    $entries[] = $entry;
                }
                $marriages[$fam['family_id']][] = 2 * $n;
            }
            $frontier = $next;
        }
        usort($entries, fn($a, $b) => $a['n'] <=> $b['n']);

        $byNumber = [];
        foreach ($this->marriages(array_keys($marriages)) as $fid => $m) {
            foreach ($marriages[$fid] as $n) $byNumber[$n] = $m;
        }
        $this->json([
            'root'        => $root,
            'generations' => $gens,
            'entries'     => $entries,
            'marriages'   => (object) $byNumber,
            'persons'     => $this->facts(array_keys($numberOf)),
        ]);
    }

    // GET /api/reports/descendants?person=&generations=
    // Je Person ihre Verbindungen (Partner, Heirat, Kinder) – das Frontend nummeriert nach d'Aboville.
    public function descendants(): void
    {
        $root = $this->rootPerson();
        $gens = max(1, min(self::MAX_DESC_GENS, (int) ($_GET['generations'] ?? 4)));

        $unions    = [];
        $persons   = [$root => true];
        $frontier  = [$root];
        $truncated = false;
        for ($g = 1; $g <= $gens && $frontier && !$truncated; $g++) {
            $in   = implode(',', array_fill(0, count($frontier), '?'));
            $stmt = $this->db->prepare("
                SELECT fm.person_id, fm.family_id FROM family_members fm JOIN families f ON f.id = fm.family_id
                WHERE f.tree_id = ? AND fm.role <> 'CHIL' AND fm.person_id IN ($in)
                ORDER BY fm.person_id, fm.family_id
            ");
            $stmt->execute([$this->treeId, ...$frontier]);
            $own = [];   // Familie → Nachkomme, um den es geht
            foreach ($stmt->fetchAll() as $r) $own[(int) $r['family_id']][] = (int) $r['person_id'];
            if (!$own) break;

            $members = $this->members(array_keys($own));
            $marr    = $this->marriages(array_keys($own));
            $next    = [];
            foreach ($own as $fid => $descendants) {
                $m = $members[$fid] ?? ['partners' => [], 'children' => []];
                foreach ($descendants as $pid) {
                    $partner = current(array_filter($m['partners'], fn($x) => $x !== $pid)) ?: null;
                    $unions[$pid][] = ['family_id' => $fid, 'partner_id' => $partner, 'marriage' => $marr[$fid] ?? null,
                                       'children' => $m['children']];
                    if ($partner) $persons[$partner] = true;
                }
                foreach ($m['children'] as $c) {
                    if (isset($persons[$c])) continue;   // schon erfasst (z. B. Kind zweier Nachkommen)
                    $persons[$c] = true;
                    $next[] = $c;
                }
            }
            if (count($persons) > self::DESCENDANT_LIMIT) $truncated = true;
            $frontier = $next;
        }
        // Kinder der letzten Generation werden genannt, aber nicht weiter aufgeklappt
        $this->json([
            'root'        => $root,
            'generations' => $gens,
            'unions'      => (object) array_map([$this, 'byMarriage'], $unions),
            'persons'     => $this->facts(array_keys($persons)),
            'truncated'   => $truncated,
        ]);
    }

    // GET /api/reports/family/{id}
    // Familienblatt: Partner mit Eltern, Ereignisse der Familie, Kinder mit ihren Partnern.
    public function family(int $id): void
    {
        if (!$this->inTree('families', $id)) throw new HttpError(404, 'Familie nicht gefunden');
        $m = $this->members([$id])[$id] ?? ['partners' => [], 'children' => [], 'pedigree' => []];

        $stmt = $this->db->prepare("
            SELECT e.event_type, e.event_date, pl.full_name AS place, e.event_value, e.event_descriptor
            FROM family_events e LEFT JOIN places pl ON pl.id = e.place_id
            WHERE e.family_id = ?" . Privacy::familySql('e.family_id') . " ORDER BY e.sort_order, e.id
        ");
        $stmt->execute([$id]);
        $events = GedcomDate::sortEvents($stmt->fetchAll());

        $ids     = [...$m['partners'], ...$m['children']];
        $parents = [];
        foreach ($this->parentFamilies($m['partners']) as $pid => $fam) {
            $parents[$pid] = $fam['parents'];
            array_push($ids, ...array_filter($fam['parents']));
        }
        // Partner der Kinder (mit Heirat)
        $spouses = [];
        if ($m['children']) {
            $in   = implode(',', array_fill(0, count($m['children']), '?'));
            $stmt = $this->db->prepare("
                SELECT a.person_id AS child, a.family_id, b.person_id AS spouse
                FROM family_members a JOIN families f ON f.id = a.family_id
                LEFT JOIN family_members b ON b.family_id = a.family_id AND b.role <> 'CHIL' AND b.person_id <> a.person_id
                WHERE f.tree_id = ? AND a.role <> 'CHIL' AND a.person_id IN ($in)
                ORDER BY a.person_id, a.family_id
            ");
            $stmt->execute([$this->treeId, ...$m['children']]);
            $rows = $stmt->fetchAll();
            $marr = $this->marriages(array_map(fn($r) => (int) $r['family_id'], $rows));
            foreach ($rows as $r) {
                $spouse = $r['spouse'] !== null ? (int) $r['spouse'] : null;
                $spouses[(int) $r['child']][] = ['spouse_id' => $spouse, 'marriage' => $marr[(int) $r['family_id']] ?? null];
                if ($spouse) $ids[] = $spouse;
            }
        }

        $this->json([
            'id'       => $id,
            'partners' => $m['partners'],
            'children' => array_map(fn($c) => ['person_id' => $c, 'pedigree' => $m['pedigree'][$c] ?? null], $m['children']),
            'events'   => $events,
            'parents'  => (object) $parents,
            'spouses'  => (object) array_map([$this, 'byMarriage'], $spouses),
            'persons'  => $this->facts($ids),
        ]);
    }

    // GET /api/reports/list?kind=births|baptisms|deaths|burials|marriages|occupations&from=&to=&place=
    public function list(): void
    {
        $kind  = (string) ($_GET['kind'] ?? '');
        $types = ['births' => ['BIRT'], 'baptisms' => ['CHR', 'BAPM'], 'deaths' => ['DEAT'],
                  'burials' => ['BURI', 'CREM'], 'occupations' => ['OCCU'], 'marriages' => ['MARR']][$kind]
                 ?? throw new HttpError(400, 'Unbekannte Liste');
        $from  = (int) ($_GET['from'] ?? 0) ?: null;
        $to    = (int) ($_GET['to'] ?? 0) ?: null;
        $place = trim((string) ($_GET['place'] ?? ''));

        $year   = "CAST(REGEXP_SUBSTR(e.event_date, '[0-9]{3,4}') AS UNSIGNED)";
        $where  = 'e.event_type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        $params = $types;
        if ($from) { $where .= " AND $year >= ?"; $params[] = $from; }
        if ($to)   { $where .= " AND $year <= ?"; $params[] = $to; }
        if ($place !== '') { $where .= ' AND pl.full_name LIKE ?'; $params[] = '%' . addcslashes($place, '%_\\') . '%'; }
        $limit = self::LIST_LIMIT + 1;

        if ($kind === 'marriages') {
            $stmt = $this->db->prepare("
                SELECT e.family_id, e.event_date, pl.full_name AS place,
                       (SELECT person_id FROM family_members WHERE family_id = e.family_id AND role = 'HUSB' ORDER BY id LIMIT 1) AS husband_id,
                       (SELECT person_id FROM family_members WHERE family_id = e.family_id AND role = 'WIFE' ORDER BY id LIMIT 1) AS wife_id
                FROM family_events e JOIN families f ON f.id = e.family_id LEFT JOIN places pl ON pl.id = e.place_id
                WHERE f.tree_id = ? AND $where" . Privacy::familySql('e.family_id') . "
                ORDER BY $year IS NULL, $year, e.id LIMIT $limit
            ");
            $stmt->execute([$this->treeId, ...$params]);
            $rows = array_map(fn($r) => [
                'family_id'  => (int) $r['family_id'], 'date' => $r['event_date'], 'place' => $r['place'],
                'husband_id' => $r['husband_id'] !== null ? (int) $r['husband_id'] : null,
                'wife_id'    => $r['wife_id'] !== null ? (int) $r['wife_id'] : null,
            ], $stmt->fetchAll());
            $ids = array_merge(array_column($rows, 'husband_id'), array_column($rows, 'wife_id'));
        } else {
            $order = match ($kind) {
                'occupations' => "e.event_value IS NULL OR e.event_value = '', e.event_value, $year IS NULL, $year",
                'burials'     => "pl.full_name IS NULL, pl.full_name, $year IS NULL, $year",
                default       => "$year IS NULL, $year",
            };
            $stmt = $this->db->prepare("
                SELECT e.person_id, e.event_type, e.event_date, pl.full_name AS place, e.event_value, e.agency
                FROM person_events e JOIN persons p ON p.id = e.person_id LEFT JOIN places pl ON pl.id = e.place_id
                WHERE p.tree_id = ? AND $where" . Privacy::personSql('p') . "
                ORDER BY $order, e.id LIMIT $limit
            ");
            $stmt->execute([$this->treeId, ...$params]);
            $rows = array_map(fn($r) => [
                'person_id' => (int) $r['person_id'], 'type' => $r['event_type'], 'date' => $r['event_date'],
                'place' => $r['place'], 'value' => $r['event_value'], 'agency' => $r['agency'],
            ], $stmt->fetchAll());
            $ids = array_column($rows, 'person_id');
        }

        $truncated = count($rows) > self::LIST_LIMIT;
        $rows      = array_slice($rows, 0, self::LIST_LIMIT);
        // Gruppen (Beruf, Friedhof) in der Reihenfolge aus SQL; darin genau nach Datum (SQL kennt nur das Jahr)
        $group = fn($r) => match ($kind) { 'occupations' => (string) $r['value'], 'burials' => (string) $r['place'], default => '' };
        $rank  = [];
        foreach ($rows as $r) $rank[$group($r)] ??= count($rank);
        $keyed = array_map(fn($r, $i) => [$rank[$group($r)], GedcomDate::sortKey($r['date']) ?? PHP_INT_MAX, $i, $r], $rows, array_keys($rows));
        usort($keyed, fn($a, $b) => array_slice($a, 0, 3) <=> array_slice($b, 0, 3));
        $rows = array_column($keyed, 3);

        $this->json([
            'kind'      => $kind,
            'rows'      => $rows,
            'truncated' => $truncated,
            'limit'     => self::LIST_LIMIT,
            'persons'   => $this->facts(array_filter($ids)),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** Ehen einer Person nach Heiratsdatum (ohne Datum: Reihenfolge der Erfassung) */
    private function byMarriage(array $list): array
    {
        $keys = array_map(fn($u, $i) => [GedcomDate::sortKey($u['marriage']['date'] ?? null) ?? PHP_INT_MAX, $i], $list, array_keys($list));
        array_multisort($keys, $list);
        return $list;
    }

    /** Ausgangsperson aus ?person= (muss zum Baum gehören) */
    private function rootPerson(): int
    {
        $id = (int) ($_GET['person'] ?? 0);
        if (!$this->inTree('persons', $id)) throw new HttpError(404, 'Person nicht gefunden');
        return $id;
    }

    /**
     * Erste Elternfamilie je Person (wie die Stammbaum-Ansicht) mit [Vater, Mutter].
     * @return array<int, array{family_id: int, parents: array{0: ?int, 1: ?int}}>
     */
    private function parentFamilies(array $personIds): array
    {
        $personIds = array_values(array_unique(array_filter(array_map('intval', $personIds))));
        if (!$personIds) return [];
        $in   = implode(',', array_fill(0, count($personIds), '?'));
        $stmt = $this->db->prepare("
            SELECT fm.person_id AS child, MIN(fm.family_id) AS family_id
            FROM family_members fm JOIN families f ON f.id = fm.family_id
            WHERE f.tree_id = ? AND fm.role = 'CHIL' AND fm.person_id IN ($in)
            GROUP BY fm.person_id
        ");
        $stmt->execute([$this->treeId, ...$personIds]);
        $childFam = [];
        foreach ($stmt->fetchAll() as $r) $childFam[(int) $r['child']] = (int) $r['family_id'];
        $members = $this->members(array_values(array_unique($childFam)));

        $out = [];
        foreach ($childFam as $child => $fid) {
            $out[$child] = ['family_id' => $fid, 'parents' => $members[$fid]['parents'] ?? [null, null]];
        }
        return $out;
    }

    /**
     * Mitglieder je Familie: partners (Reihenfolge HUSB, WIFE), parents [Vater, Mutter], children, pedigree je Kind.
     * @return array<int, array{partners: list<int>, parents: array{0: ?int, 1: ?int}, children: list<int>, pedigree: array<int,?string>}>
     */
    private function members(array $familyIds): array
    {
        $familyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds))));
        if (!$familyIds) return [];
        $in   = implode(',', array_fill(0, count($familyIds), '?'));
        $stmt = $this->db->prepare("
            SELECT fm.family_id, fm.person_id, fm.role, fm.pedigree, p.sex
            FROM family_members fm JOIN persons p ON p.id = fm.person_id
            WHERE p.tree_id = ? AND fm.family_id IN ($in)
            ORDER BY fm.family_id, FIELD(fm.role, 'HUSB', 'WIFE', 'CHIL'), fm.id
        ");
        $stmt->execute([$this->treeId, ...$familyIds]);
        $out = [];
        $kids = [];
        foreach ($stmt->fetchAll() as $r) {
            $fid = (int) $r['family_id'];
            $pid = (int) $r['person_id'];
            $out[$fid] ??= ['partners' => [], 'parents' => [null, null], 'children' => [], 'pedigree' => []];
            if ($r['role'] === 'CHIL') {
                $out[$fid]['children'][] = $pid;
                $out[$fid]['pedigree'][$pid] = $r['pedigree'];
                $kids[$fid][] = $pid;
                continue;
            }
            $out[$fid]['partners'][] = $pid;
            // Platz als Vater/Mutter: nach Rolle, bei gleichgeschlechtlichen Paaren der nächste freie
            $slot = $r['role'] === 'WIFE' || ($r['role'] !== 'HUSB' && $r['sex'] === 'F') ? 1 : 0;
            if ($out[$fid]['parents'][$slot] !== null) $slot = 1 - $slot;
            if ($out[$fid]['parents'][$slot] === null) $out[$fid]['parents'][$slot] = $pid;
        }
        // Kinder nach Geburt ordnen (ohne Datum: Reihenfolge der Erfassung)
        $births = $this->birthKeys(array_merge([], ...array_values($kids)));
        foreach ($kids as $fid => $list) {
            $pos = array_flip($list);
            usort($list, fn($a, $b) => [$births[$a] ?? PHP_INT_MAX, $pos[$a]] <=> [$births[$b] ?? PHP_INT_MAX, $pos[$b]]);
            $out[$fid]['children'] = $list;
        }
        return $out;
    }

    /** @return array<int,int> Sortierschlüssel des Geburtsdatums (ersatzweise Taufe) je Person */
    private function birthKeys(array $ids): array
    {
        if (!$ids) return [];
        $out = [];
        foreach (array_chunk(array_values(array_unique($ids)), 1000) as $chunk) {
            $in   = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->db->prepare("SELECT person_id, event_type, event_date FROM person_events
                                        WHERE person_id IN ($in) AND event_type IN ('BIRT', 'CHR', 'BAPM')
                                        ORDER BY event_type = 'BIRT' DESC, sort_order, id");
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $r) {
                $key = GedcomDate::sortKey($r['event_date']);
                if ($key !== null && !isset($out[(int) $r['person_id']])) $out[(int) $r['person_id']] = $key;
            }
        }
        return $out;
    }

    /** @return array<int, array{date: ?string, place: ?string}> erste Heirat je Familie (Besucher: nicht bei privaten Familien) */
    private function marriages(array $familyIds): array
    {
        $familyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds))));
        if (!$familyIds) return [];
        $in   = implode(',', array_fill(0, count($familyIds), '?'));
        $stmt = $this->db->prepare("
            SELECT e.family_id, e.event_date, pl.full_name AS place
            FROM family_events e LEFT JOIN places pl ON pl.id = e.place_id
            WHERE e.event_type = 'MARR' AND e.family_id IN ($in)" . Privacy::familySql('e.family_id') . "
            ORDER BY e.family_id, e.sort_order, e.id
        ");
        $stmt->execute($familyIds);
        $out = [];
        foreach ($stmt->fetchAll() as $r) {
            $out[(int) $r['family_id']] ??= ['date' => $r['event_date'], 'place' => $r['place']];
        }
        return $out;
    }

    /**
     * Personen für Berichte: schlanke Daten + Taufe, Begräbnis/Einäscherung, Berufe.
     * @return object  Personen-ID → Fakten (als Objekt, damit JSON auch bei Lücken ein Objekt bleibt)
     */
    private function facts(array $ids): object
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        $out = [];
        $pq  = new PersonQuery($this->db, $this->treeId);
        foreach (array_chunk($ids, 1000) as $chunk) {
            foreach ($pq->slim($chunk) as $p) {
                $out[$p['id']] = [...$p, 'baptism' => null, 'burial' => null, 'occupations' => []];
            }
            $visible = array_values(array_filter($chunk, fn($id) => isset($out[$id]) && empty($out[$id]['private'])));
            if (!$visible) continue;
            $in   = implode(',', array_fill(0, count($visible), '?'));
            $stmt = $this->db->prepare("
                SELECT e.person_id, e.event_type, e.event_date, pl.full_name AS place, e.event_value
                FROM person_events e LEFT JOIN places pl ON pl.id = e.place_id
                WHERE e.person_id IN ($in) AND e.event_type IN ('CHR', 'BAPM', 'BURI', 'CREM', 'OCCU')
                ORDER BY e.person_id, e.sort_order, e.id
            ");
            $stmt->execute($visible);
            foreach ($stmt->fetchAll() as $r) {
                $p = &$out[(int) $r['person_id']];
                $ev = ['type' => $r['event_type'], 'date' => $r['event_date'], 'place' => $r['place']];
                if ($r['event_type'] === 'OCCU') {
                    $value = trim((string) $r['event_value']);
                    if ($value !== '' && !in_array($value, $p['occupations'], true)) $p['occupations'][] = $value;
                } elseif (in_array($r['event_type'], ['CHR', 'BAPM'], true)) {
                    $p['baptism'] ??= $ev;
                } else {
                    $p['burial'] ??= $ev;
                }
                unset($p);
            }
        }
        return (object) $out;
    }
}
