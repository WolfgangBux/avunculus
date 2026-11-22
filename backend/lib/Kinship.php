<?php

declare(strict_types=1);

/**
 * Verwandtschaftsrechner: Wie sind zwei Personen miteinander verwandt?
 *
 * 1. Blutsverwandtschaft über die nächsten gemeinsamen Vorfahren. Für jeden nächsten gemeinsamen Vorfahren
 *    (keiner seiner Nachkommen ist selbst gemeinsamer Vorfahr) zählen die Generationen bis zu A und bis zu B –
 *    daraus benennt das Frontend die Verwandtschaft (Onkel, Cousin 2. Grades …). Bei Ahnenschwund gibt es
 *    mehrere Wege, alle werden geliefert. Ein Elternpaar zählt als ein Weg; stammen die beiden Linien aus
 *    verschiedenen Familien des Vorfahren, ist es eine Halbverwandtschaft.
 * 2. Partner (Familie mit beiden als Partner) – auch zusätzlich zur Blutsverwandtschaft.
 * 3. Ohne Blutsverwandtschaft: Verschwägerung über einen Partner – A ist blutsverwandt mit dem Partner von B
 *    (Schwiegervater, Schwager, Stiefsohn …) oder Partner eines Blutsverwandten von B (Schwiegersohn, Stiefmutter …).
 * 4. Sonst der kürzeste Weg über Eltern, Kinder und Partner, falls es überhaupt einen gibt.
 *
 * Abstammung zählt unabhängig von der Art (leiblich, adoptiert …), wie in Relatives.
 * Ein Weg ist eine Liste [{ id, rel }] von A nach B; rel sagt, was die Person für die vorige ist
 * ('parent', 'child', 'partner'; beim ersten Eintrag null).
 */
final class Kinship
{
    public const MAX_RELATIONS = 8;

    /** @var array<int, list<int>> */
    private array $parentsOf = [];   // Familie → Partner
    private array $childrenOf = [];  // Familie → Kinder
    private array $asChild = [];     // Person → Familien als Kind
    private array $asPartner = [];   // Person → Familien als Partner

    public function __construct(private readonly PDO $db, private readonly int $treeId) {}

    /**
     * @return list<array> Verwandtschaften, die nächsten zuerst:
     *   { type: 'same' | 'blood' | 'partner' | 'inlaw' | 'path', path,
     *     blood:  ga, gb (Generationen von A bzw. B zum Vorfahren), half, ancestors: [id, Partner?]
     *     inlaw:  side ('a': A ist Partner eines Blutsverwandten von B, 'b': A ist blutsverwandt mit Bs Partner),
     *             via (dieser Partner bzw. Blutsverwandte), ga, gb, half, ancestors – gerechnet zwischen A bzw. via und B bzw. via }
     */
    public function between(int $a, int $b): array
    {
        if ($a === $b) return [['type' => 'same', 'path' => [['id' => $a, 'rel' => null]]]];
        $this->load();

        $result  = $this->blood($a, $b);
        $partner = in_array($b, $this->partners($a), true);
        if ($partner) $result[] = ['type' => 'partner', 'path' => [['id' => $a, 'rel' => null], ['id' => $b, 'rel' => 'partner']]];

        if (!$result) {
            // A ist blutsverwandt mit einem Partner von B
            foreach ($this->partners($b) as $s) {
                foreach ($this->blood($a, $s) as $r) {
                    $r['path'][] = ['id' => $b, 'rel' => 'partner'];
                    $result[] = [...$r, 'type' => 'inlaw', 'side' => 'b', 'via' => $s];
                }
            }
            // A ist Partner eines Blutsverwandten von B
            foreach ($this->partners($a) as $s) {
                foreach ($this->blood($s, $b) as $r) {
                    $r['path'][0]['rel'] = 'partner';
                    array_unshift($r['path'], ['id' => $a, 'rel' => null]);
                    $result[] = [...$r, 'type' => 'inlaw', 'side' => 'a', 'via' => $s];
                }
            }
            usort($result, fn($x, $y) => count($x['path']) <=> count($y['path']));
        }

        if (!$result && ($path = $this->shortestPath($a, $b))) $result[] = ['type' => 'path', 'path' => $path];
        return array_slice($result, 0, self::MAX_RELATIONS);
    }

    // ── Daten ─────────────────────────────────────────────

    private function load(): void
    {
        // Alle Familienmitgliedschaften des Baums auf einmal (auch bei 50.000+ Personen nur wenige MB)
        $stmt = $this->db->prepare('SELECT fm.family_id, fm.person_id, fm.role FROM family_members fm
                                    JOIN families f ON f.id = fm.family_id WHERE f.tree_id = ?');
        $stmt->execute([$this->treeId]);
        while ($m = $stmt->fetch(PDO::FETCH_NUM)) {
            [$f, $p, $role] = [(int) $m[0], (int) $m[1], $m[2]];
            if ($role === 'CHIL') { $this->childrenOf[$f][] = $p; $this->asChild[$p][] = $f; }
            else                  { $this->parentsOf[$f][] = $p;  $this->asPartner[$p][] = $f; }
        }
    }

    /** @return list<int> */
    private function partners(int $p): array
    {
        $out = [];
        foreach ($this->asPartner[$p] ?? [] as $f) {
            foreach ($this->parentsOf[$f] ?? [] as $q) if ($q !== $p) $out[$q] = true;
        }
        return array_keys($out);
    }

    // ── Blutsverwandtschaft ───────────────────────────────

    /**
     * Vorfahren (einschließlich der Person) mit kürzestem Generationsabstand – Breitensuche,
     * dazu je Vorfahr das Kind, über das er auf diesem kürzesten Weg erreicht wurde.
     * @return array{0: array<int,int>, 1: array<int,?int>}
     */
    private function ancestors(int $p): array
    {
        $dist = [$p => 0];
        $down = [$p => null];
        for ($level = [$p], $d = 1; $level; $d++) {
            $next = [];
            foreach ($level as $c) {
                foreach ($this->asChild[$c] ?? [] as $f) {
                    foreach ($this->parentsOf[$f] ?? [] as $parent) {
                        if (isset($dist[$parent])) continue;
                        $dist[$parent] = $d;
                        $down[$parent] = $c;
                        $next[] = $parent;
                    }
                }
            }
            $level = $next;
        }
        return [$dist, $down];
    }

    /** @return list<array{type: string, ga: int, gb: int, half: bool, ancestors: list<int>, path: list<array>}> */
    private function blood(int $a, int $b): array
    {
        if ($a === $b) return [];
        [$distA, $downA] = $this->ancestors($a);
        [$distB, $downB] = $this->ancestors($b);
        $common = array_intersect_key($distA, $distB);
        if (!$common) return [];

        // Nächste gemeinsame Vorfahren: keine Vorfahren eines anderen gemeinsamen Vorfahren
        // (jeder Vorfahr eines gemeinsamen Vorfahren ist selbst gemeinsam – eine Suche nach oben genügt)
        $covered = [];
        $queue   = [];
        foreach (array_keys($common) as $c) foreach ($this->parentIds($c) as $q) $queue[] = $q;
        while ($queue) {
            $p = array_pop($queue);
            if (isset($covered[$p])) continue;
            $covered[$p] = true;
            foreach ($this->parentIds($p) as $q) if (!isset($covered[$q])) $queue[] = $q;
        }
        $lowest = array_diff_key($common, $covered);
        uksort($lowest, fn($x, $y) => ($distA[$x] + $distB[$x]) <=> ($distA[$y] + $distB[$y]) ?: $x <=> $y);

        $result = [];
        $done   = [];
        foreach (array_keys($lowest) as $anc) {
            if (isset($done[$anc])) continue;
            $done[$anc] = true;
            $ca = $downA[$anc];   // Kind des Vorfahren in Richtung A (null, wenn A selbst der Vorfahr ist)
            $cb = $downB[$anc];
            $half = false;
            $ancestors = [$anc];
            if ($ca !== null && $cb !== null) {
                $shared = array_values(array_intersect($this->familiesWithChild($anc, $ca), $this->familiesWithChild($anc, $cb)));
                $half   = !$shared;
                if ($shared) {
                    foreach ($this->parentsOf[$shared[0]] ?? [] as $q) {
                        if ($q !== $anc && isset($lowest[$q]) && !isset($done[$q])
                            && $distA[$q] === $distA[$anc] && $distB[$q] === $distB[$anc]) {
                            $ancestors[] = $q;
                            $done[$q] = true;
                        }
                    }
                }
            }
            $result[] = [
                'type' => 'blood', 'ga' => $distA[$anc], 'gb' => $distB[$anc], 'half' => $half, 'ancestors' => $ancestors,
                'path' => $this->bloodPath($anc, $downA, $downB),
            ];
        }
        return $result;
    }

    /** @return list<int> */
    private function parentIds(int $p): array
    {
        $out = [];
        foreach ($this->asChild[$p] ?? [] as $f) foreach ($this->parentsOf[$f] ?? [] as $q) $out[] = $q;
        return $out;
    }

    /** Familien, in denen $parent Partner und $child Kind ist */
    private function familiesWithChild(int $parent, int $child): array
    {
        return array_values(array_intersect($this->asPartner[$parent] ?? [], $this->asChild[$child] ?? []));
    }

    /** Weg von A hinauf zum Vorfahren und hinunter zu B */
    private function bloodPath(int $anc, array $downA, array $downB): array
    {
        $up = [];   // vom Vorfahren hinunter zu A, wird umgedreht
        for ($p = $anc; $p !== null; $p = $downA[$p]) $up[] = $p;
        $up = array_reverse($up);
        $path = [];
        foreach ($up as $i => $p) $path[] = ['id' => $p, 'rel' => $i ? 'parent' : null];
        for ($p = $downB[$anc]; $p !== null; $p = $downB[$p]) $path[] = ['id' => $p, 'rel' => 'child'];
        return $path;
    }

    // ── Kürzester Weg ─────────────────────────────────────

    /** Breitensuche über Eltern, Kinder und Partner; null, wenn die beiden nicht verbunden sind */
    private function shortestPath(int $a, int $b): ?array
    {
        $prev = [$a => null];
        $rel  = [$a => null];
        for ($level = [$a]; $level && !isset($prev[$b]); ) {
            $next = [];
            foreach ($level as $p) {
                $steps = [];
                foreach ($this->asChild[$p] ?? [] as $f)   foreach ($this->parentsOf[$f] ?? [] as $q)  $steps[] = [$q, 'parent'];
                foreach ($this->asPartner[$p] ?? [] as $f) {
                    foreach ($this->parentsOf[$f] ?? [] as $q)  if ($q !== $p) $steps[] = [$q, 'partner'];
                    foreach ($this->childrenOf[$f] ?? [] as $q) $steps[] = [$q, 'child'];
                }
                foreach ($steps as [$q, $r]) {
                    if (array_key_exists($q, $prev)) continue;
                    $prev[$q] = $p;
                    $rel[$q]  = $r;
                    $next[]   = $q;
                }
            }
            $level = $next;
        }
        if (!array_key_exists($b, $prev)) return null;

        $path = [];
        for ($p = $b; $p !== null; $p = $prev[$p]) $path[] = ['id' => $p, 'rel' => $rel[$p]];
        return array_reverse($path);
    }
}
