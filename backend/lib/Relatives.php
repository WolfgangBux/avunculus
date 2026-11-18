<?php

declare(strict_types=1);

/**
 * Verwandtschaft einer Ausgangsperson für einen Teilbaum.
 *
 * Blutsverwandt ist, wer einen gemeinsamen Vorfahren mit der Ausgangsperson hat: alle Vorfahren und
 * alle Nachkommen dieser Vorfahren (also auch Geschwister, Onkel, Cousins …, Halbgeschwister).
 * Dazu kommen die Ehe-/Lebenspartner der Blutsverwandten – nicht aber deren eigene Verwandte.
 * Abstammung zählt unabhängig von der Art (leiblich, adoptiert …).
 *
 * Familien im Teilbaum: jede Familie, in der ein Blutsverwandter Partner oder Kind ist –
 * also nicht die Elternfamilie eines angeheirateten Partners.
 */
final class Relatives
{
    /** @return array{persons: list<int>, families: list<int>, blood: int, spouses: int, ancestors: int, descendants: int} */
    public static function collect(PDO $db, int $treeId, int $personId): array
    {
        // Alle Familienmitgliedschaften des Baums auf einmal (auch bei 50.000+ Personen nur wenige MB)
        $stmt = $db->prepare('SELECT fm.family_id, fm.person_id, fm.role FROM family_members fm
                              JOIN families f ON f.id = fm.family_id WHERE f.tree_id = ?');
        $stmt->execute([$treeId]);
        $parentsOf = [];   // Familie → Partner
        $childrenOf = [];  // Familie → Kinder
        $asChild   = [];   // Person → Familien als Kind
        $asPartner = [];   // Person → Familien als Partner
        while ($m = $stmt->fetch(PDO::FETCH_NUM)) {
            [$f, $p, $role] = [(int) $m[0], (int) $m[1], $m[2]];
            if ($role === 'CHIL') { $childrenOf[$f][] = $p; $asChild[$p][] = $f; }
            else                  { $parentsOf[$f][] = $p;  $asPartner[$p][] = $f; }
        }

        // 1. Vorfahren (einschließlich der Ausgangsperson)
        $ancestors = [$personId => true];
        for ($queue = [$personId]; $queue; ) {
            $p = array_pop($queue);
            foreach ($asChild[$p] ?? [] as $f) {
                foreach ($parentsOf[$f] ?? [] as $parent) {
                    if (!isset($ancestors[$parent])) { $ancestors[$parent] = true; $queue[] = $parent; }
                }
            }
        }

        // 2. Alle Nachkommen aller Vorfahren = Blutsverwandte
        $blood = $ancestors;
        for ($queue = array_keys($ancestors); $queue; ) {
            $p = array_pop($queue);
            foreach ($asPartner[$p] ?? [] as $f) {
                foreach ($childrenOf[$f] ?? [] as $child) {
                    if (!isset($blood[$child])) { $blood[$child] = true; $queue[] = $child; }
                }
            }
        }

        // 3. Partner der Blutsverwandten; Familien, in denen ein Blutsverwandter vorkommt
        $persons  = $blood;
        $families = [];
        foreach (array_keys($blood) as $p) {
            foreach ([...($asPartner[$p] ?? []), ...($asChild[$p] ?? [])] as $f) $families[$f] = true;
            foreach ($asPartner[$p] ?? [] as $f) {
                foreach ($parentsOf[$f] ?? [] as $partner) $persons[$partner] = true;
            }
        }

        // Nachkommen der Ausgangsperson (nur zur Anzeige)
        $desc = [];
        for ($queue = [$personId]; $queue; ) {
            $p = array_pop($queue);
            foreach ($asPartner[$p] ?? [] as $f) {
                foreach ($childrenOf[$f] ?? [] as $c) if (!isset($desc[$c])) { $desc[$c] = true; $queue[] = $c; }
            }
        }

        return [
            'persons'     => array_keys($persons),
            'families'    => array_keys($families),
            'blood'       => count($blood),
            'spouses'     => count($persons) - count($blood),
            'ancestors'   => count($ancestors) - 1,
            'descendants' => count($desc),
        ];
    }
}
