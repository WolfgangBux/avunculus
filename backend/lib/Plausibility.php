<?php

declare(strict_types=1);

/**
 * Fehlersuche: unplausible Lebensdaten in einem Stammbaum (Mutter zu jung, Kind nach dem Tod der Mutter …).
 *
 * Jede Datumsangabe wird zum Bereich [frühestens, spätestens] (GedcomDate::range). Gemeldet wird nur, was
 * selbst im günstigsten Fall nicht passt – „um 1800“ oder „vor 1750“ erzeugt also keine falschen Treffer.
 * Fehlt die Geburt, gilt die Taufe (Geburt bis zu einem Jahr davor); fehlt der Tod, das Begräbnis.
 *
 * Für große Bäume (50.000+ Personen) liegen die Daten in flachen Arrays je Ereignisart statt in
 * Objekten je Person – das hält den Speicher auch bei 128 MB memory_limit klein.
 */
final class Plausibility
{
    public const MOTHER_MIN = 14;   // Jahre
    public const MOTHER_MAX = 50;
    public const FATHER_MIN = 14;
    public const FATHER_MAX = 80;
    public const MARRIAGE_MIN_F = 16;   // Frauen (und unbekanntes Geschlecht)
    public const MARRIAGE_MIN_M = 18;   // Männer
    public const MAX_AGE = 110;
    public const FATHER_DEATH_GAP = 0.8;   // Kind kann bis ~10 Monate nach dem Tod des Vaters geboren werden
    public const SIBLING_MIN_DAYS = 180;   // Geschwister (taggenau, keine Zwillinge) mindestens so weit auseinander
    public const TWIN_DAYS = 3;
    public const MAX_ITEMS = 5000;

    /** Reihenfolge der Prüfungen = Reihenfolge in der Ausgabe */
    public const CHECKS = [
        'death_before_birth', 'baptism_before_birth', 'burial_before_death', 'too_old',
        'parent_born_after_child', 'mother_too_young', 'mother_too_old', 'father_too_young', 'father_too_old',
        'born_after_mother_death', 'born_after_father_death', 'siblings_too_close',
        'marriage_before_birth', 'marriage_too_young', 'marriage_after_death',
    ];

    private const KINDS = ['BIRT' => 'birt', 'CHR' => 'bapt', 'BAPM' => 'bapt', 'DEAT' => 'deat', 'BURI' => 'buri', 'CREM' => 'buri'];

    /** @var array<string, array<int, float>> Ereignisart → Person → frühestens / spätestens */
    private array $lo  = [];
    private array $hi  = [];
    /** @var array<string, array<int, string>> Ereignisart → Person → Datumsangabe wie erfasst */
    private array $raw = [];
    private array $sex = [];
    private array $items = [];
    private array $counts = [];

    public function __construct(private readonly PDO $db, private readonly int $treeId) {}

    /**
     * @return array{items: list<array>, counts: array<string,int>, truncated: bool}
     *   item: { check, person, other?, family?, age?, days?, dates: [eigenes Datum, Vergleichsdatum] }
     */
    public function run(): array
    {
        $this->loadPersons();
        $this->checkPersons();
        $this->checkFamilies();

        $order = array_flip(self::CHECKS);
        usort($this->items, fn($a, $b) => $order[$a['check']] <=> $order[$b['check']] ?: $a['person'] <=> $b['person']);
        return ['items' => $this->items, 'counts' => $this->counts, 'truncated' => array_sum($this->counts) > count($this->items)];
    }

    // ── Daten laden ───────────────────────────────────────

    private function loadPersons(): void
    {
        $stmt = $this->db->prepare('SELECT id, sex FROM persons WHERE tree_id = ?');
        $stmt->execute([$this->treeId]);
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) $this->sex[(int) $r[0]] = $r[1];

        // Je Person und Art das erste Ereignis mit auswertbarem Datum (Taufe: BAPM oder CHR, Begräbnis: BURI oder CREM)
        $stmt = $this->db->prepare("
            SELECT e.person_id, e.event_type, e.event_date FROM person_events e JOIN persons p ON p.id = e.person_id
            WHERE p.tree_id = ? AND e.event_type IN ('BIRT','CHR','BAPM','DEAT','BURI','CREM') AND e.event_date IS NOT NULL
            ORDER BY e.person_id, e.sort_order, e.id
        ");
        $stmt->execute([$this->treeId]);
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            $pid  = (int) $r[0];
            $kind = self::KINDS[$r[1]];
            if (isset($this->raw[$kind][$pid]) || !($range = GedcomDate::range($r[2]))) continue;
            [$this->lo[$kind][$pid], $this->hi[$kind][$pid]] = $range;
            $this->raw[$kind][$pid] = $r[2];
        }
    }

    /** Geburt, ersatzweise Taufe (Geburt bis zu einem Jahr davor): [frühestens, spätestens, Datum] oder null */
    private function birth(int $pid): ?array
    {
        if (isset($this->raw['birt'][$pid])) return [$this->lo['birt'][$pid], $this->hi['birt'][$pid], $this->raw['birt'][$pid]];
        if (isset($this->raw['bapt'][$pid])) return [$this->lo['bapt'][$pid] - 1, $this->hi['bapt'][$pid], $this->raw['bapt'][$pid]];
        return null;
    }

    /** Tod, ersatzweise Begräbnis (Tod bis zu drei Monate davor) */
    private function death(int $pid): ?array
    {
        if (isset($this->raw['deat'][$pid])) return [$this->lo['deat'][$pid], $this->hi['deat'][$pid], $this->raw['deat'][$pid]];
        if (isset($this->raw['buri'][$pid])) return [$this->lo['buri'][$pid] - 0.25, $this->hi['buri'][$pid], $this->raw['buri'][$pid]];
        return null;
    }

    private function add(string $check, int $person, array $dates, array $extra = []): void
    {
        $this->counts[$check] = ($this->counts[$check] ?? 0) + 1;
        if (count($this->items) < self::MAX_ITEMS) $this->items[] = ['check' => $check, 'person' => $person, ...$extra, 'dates' => $dates];
    }

    // ── Eine Person für sich ──────────────────────────────

    private function checkPersons(): void
    {
        foreach ($this->sex as $pid => $_) {
            $b = $this->birth($pid);
            $d = $this->death($pid);
            if ($b && $d && $d[1] < $b[0]) {
                $this->add('death_before_birth', $pid, [$d[2], $b[2]]);
                continue;   // die übrigen Altersangaben wären dann nur Folgefehler
            }
            if (isset($this->raw['birt'][$pid], $this->raw['bapt'][$pid]) && $this->hi['bapt'][$pid] < $this->lo['birt'][$pid]) {
                $this->add('baptism_before_birth', $pid, [$this->raw['bapt'][$pid], $this->raw['birt'][$pid]]);
            }
            if (isset($this->raw['deat'][$pid], $this->raw['buri'][$pid]) && $this->hi['buri'][$pid] < $this->lo['deat'][$pid]) {
                $this->add('burial_before_death', $pid, [$this->raw['buri'][$pid], $this->raw['deat'][$pid]]);
            }
            if ($b && $d && $d[0] - $b[1] > self::MAX_AGE) {
                $this->add('too_old', $pid, [$b[2], $d[2]], ['age' => (int) floor($d[0] - $b[1])]);
            }
        }
    }

    // ── Eltern, Kinder, Ehepartner ────────────────────────

    private function checkFamilies(): void
    {
        $stmt = $this->db->prepare('
            SELECT fm.family_id, fm.person_id, fm.role, fm.pedigree FROM family_members fm JOIN families f ON f.id = fm.family_id
            WHERE f.tree_id = ? ORDER BY fm.family_id, fm.id
        ');
        $stmt->execute([$this->treeId]);
        $partners = [];
        $children = [];
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            if ($r[2] === 'CHIL') {
                // Adoptiv-, Pflegekinder usw.: Alter der Eltern sagt nichts
                if ($r[3] === null || $r[3] === '' || $r[3] === 'BIRTH') $children[(int) $r[0]][] = (int) $r[1];
            } else {
                $partners[(int) $r[0]][] = (int) $r[1];
            }
        }

        $stmt = $this->db->prepare("
            SELECT e.family_id, e.event_date FROM family_events e JOIN families f ON f.id = e.family_id
            WHERE f.tree_id = ? AND e.event_type = 'MARR' AND e.event_date IS NOT NULL ORDER BY e.family_id, e.sort_order, e.id
        ");
        $stmt->execute([$this->treeId]);
        $marriage = [];
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            if (!isset($marriage[(int) $r[0]]) && ($range = GedcomDate::range($r[1]))) $marriage[(int) $r[0]] = [...$range, $r[1]];
        }

        $byMother = [];   // Geschwister über alle Familien einer Mutter vergleichen
        foreach ($partners as $fid => $ps) {
            foreach ($ps as $p) {
                $this->checkMarriage($fid, $p, $marriage[$fid] ?? null);
                foreach ($children[$fid] ?? [] as $c) $this->checkParent($fid, $p, $c);
            }
            $mother = null;
            foreach ($ps as $p) if (($this->sex[$p] ?? '') === 'F') $mother = $p;
            foreach ($children[$fid] ?? [] as $c) $byMother[$mother ?? "f$fid"][$c] = $fid;
        }
        foreach ($byMother as $kids) $this->checkSiblings($kids);
    }

    private function checkParent(int $fid, int $parent, int $child): void
    {
        if (!($cb = $this->birth($child))) return;
        $isMother = ($this->sex[$parent] ?? '') === 'F';
        $isFather = ($this->sex[$parent] ?? '') === 'M';
        $extra    = ['other' => $parent, 'family' => $fid];

        if ($pb = $this->birth($parent)) {
            if ($cb[1] < $pb[0]) {
                $this->add('parent_born_after_child', $child, [$cb[2], $pb[2]], $extra);
                return;
            }
            $youngest = $cb[1] - $pb[0];   // ältestmöglich bei der Geburt …
            $oldest   = $cb[0] - $pb[1];   // … und jüngstmöglich
            if ($isMother && $youngest < self::MOTHER_MIN) $this->add('mother_too_young', $child, [$cb[2], $pb[2]], $extra + ['age' => (int) floor($youngest)]);
            if ($isFather && $youngest < self::FATHER_MIN) $this->add('father_too_young', $child, [$cb[2], $pb[2]], $extra + ['age' => (int) floor($youngest)]);
            if ($isMother && $oldest > self::MOTHER_MAX)   $this->add('mother_too_old',   $child, [$cb[2], $pb[2]], $extra + ['age' => (int) floor($oldest)]);
            if ($isFather && $oldest > self::FATHER_MAX)   $this->add('father_too_old',   $child, [$cb[2], $pb[2]], $extra + ['age' => (int) floor($oldest)]);
        }
        if ($pd = $this->death($parent)) {
            if ($isMother && $cb[0] > $pd[1])                          $this->add('born_after_mother_death', $child, [$cb[2], $pd[2]], $extra);
            if ($isFather && $cb[0] > $pd[1] + self::FATHER_DEATH_GAP) $this->add('born_after_father_death', $child, [$cb[2], $pd[2]], $extra);
        }
    }

    private function checkMarriage(int $fid, int $p, ?array $marr): void
    {
        if (!$marr) return;
        if ($b = $this->birth($p)) {
            if ($marr[1] < $b[0])                           $this->add('marriage_before_birth', $p, [$marr[2], $b[2]], ['family' => $fid]);
            elseif ($marr[1] - $b[0] < (($this->sex[$p] ?? '') === 'M' ? self::MARRIAGE_MIN_M : self::MARRIAGE_MIN_F)) $this->add('marriage_too_young', $p, [$marr[2], $b[2]], ['family' => $fid, 'age' => (int) floor($marr[1] - $b[0])]);
        }
        if (($d = $this->death($p)) && $marr[0] > $d[1]) {
            $this->add('marriage_after_death', $p, [$marr[2], $d[2]], ['family' => $fid]);
        }
    }

    /** Taggenau erfasste Geburten derselben Mutter: weniger als ~6 Monate auseinander, aber keine Zwillinge */
    private function checkSiblings(array $kids): void
    {
        $exact = [];
        foreach ($kids as $c => $fid) {
            if (isset($this->raw['birt'][$c]) && $this->hi['birt'][$c] - $this->lo['birt'][$c] < 2 / 365) $exact[$c] = $this->lo['birt'][$c];
        }
        asort($exact);
        $prev = null;
        foreach ($exact as $c => $t) {
            if ($prev !== null) {
                $days = (int) round(($t - $exact[$prev]) * 365);
                if ($days > self::TWIN_DAYS && $days < self::SIBLING_MIN_DAYS) {
                    $this->add('siblings_too_close', $c, [$this->raw['birt'][$c], $this->raw['birt'][$prev]],
                               ['other' => $prev, 'family' => $kids[$c], 'days' => $days]);
                }
            }
            $prev = $c;
        }
    }
}
