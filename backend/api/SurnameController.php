<?php

declare(strict_types=1);

/**
 * Nachnamen-Übersicht: Anfangsbuchstaben mit Anzahl, Namen eines Buchstabens, häufigste Namen (Wolke)
 * und die Zweige eines Namens. Bei großen Bäumen der übliche Einstieg statt einer langen Personenliste.
 *
 * Gezählt werden Personen (nicht Namen) über alle ihre Namen, also auch Ehe- und Zweitnamen.
 * Gleiche Namen in anderer Schreibweise (Groß/klein, Akzente: Müller = MÜLLER = Muller) fasst die
 * Sortierfolge der Datenbank (utf8mb4_unicode_ci) zusammen – genauso filtert die Personensuche
 * (PersonQuery, Parameter surname). Besucher sehen nur nicht-private Personen.
 */
final class SurnameController extends TreeScopedController
{
    private const CLOUD     = 60;    // so viele Namen in der Wolke (Standard)
    private const CLOUD_MAX = 300;   // höchstens (große Wolke in der Auswertung, ?cloud=N)

    private const BRANCH_FIELDS = ['id' => 1, 'sex' => 1, 'restriction' => 1, 'given_name' => 1, 'surname' => 1, 'surname_prefix' => 1,
                                   'name_prefix' => 1, 'nickname' => 1, 'alt_surname' => 1, 'birth_date' => 1, 'death_date' => 1, 'private' => 1];

    /**
     * GET surnames – Buchstaben, Personen ohne Nachnamen, Wolke; GET surnames?letter=X – nur die Namen dieses Buchstabens;
     * GET surnames?cloud=N – nur die Wolke mit den N häufigsten Namen
     */
    public function index(): void
    {
        $letter = trim((string) ($_GET['letter'] ?? ''));
        if ($letter !== '') {
            $this->json(['surnames' => $this->byLetter(self::initial($letter))]);
            return;
        }
        if (isset($_GET['cloud'])) {
            $this->json(['cloud' => $this->cloud(max(1, min(self::CLOUD_MAX, (int) $_GET['cloud'])))]);
            return;
        }
        $priv = Privacy::personSql();

        // Anfangsbuchstaben: die Sortierfolge fasst Ä/A usw. schon zusammen, initial() vereinheitlicht den Vertreter
        $initials = [];
        foreach ($this->rows("
            SELECT LEFT(TRIM(n.surname), 1) AS l, COUNT(DISTINCT n.person_id) AS n
            FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ? AND TRIM(n.surname) <> ''$priv
            GROUP BY l") as $r) {
            $k = self::initial($r['l']);
            $initials[$k] = ($initials[$k] ?? 0) + (int) $r['n'];
        }
        uksort($initials, fn($a, $b) => ($a === '#') <=> ($b === '#') ?: strcmp($a, $b));

        $none = $this->rows("
            SELECT COUNT(*) AS n FROM persons p
            WHERE p.tree_id = ?$priv AND NOT EXISTS (
                SELECT 1 FROM person_names n WHERE n.person_id = p.id AND TRIM(n.surname) <> '')")[0]['n'];

        $cloud = $this->cloud(self::CLOUD);
        usort($cloud, fn($a, $b) => strcoll($a['surname'], $b['surname']) ?: strcmp($a['surname'], $b['surname']));

        $this->json([
            'initials' => array_map(fn($k, $n) => ['letter' => (string) $k, 'count' => $n], array_keys($initials), $initials),
            'none'     => (int) $none,
            'cloud'    => $cloud,
        ]);
    }

    /** Die $limit häufigsten Nachnamen, häufigste zuerst */
    private function cloud(int $limit): array
    {
        return array_map(fn($r) => ['surname' => $r['surname'], 'count' => (int) $r['n']], $this->rows("
            SELECT TRIM(n.surname) AS surname, COUNT(DISTINCT n.person_id) AS n
            FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ? AND TRIM(n.surname) <> ''" . Privacy::personSql() . "
            GROUP BY TRIM(n.surname) ORDER BY n DESC, surname LIMIT $limit"));
    }

    /** Namen mit diesem Anfangsbuchstaben ('#' = kein Buchstabe), alphabetisch, mit Anzahl Personen */
    private function byLetter(string $letter): array
    {
        // Alle Namen gruppiert holen (auch bei 50.000+ Personen nur einige tausend Zeilen) und den Buchstaben
        // hier bestimmen – derselbe Weg wie oben, damit Liste und Zählung zusammenpassen
        $out = [];
        foreach ($this->rows("
            SELECT TRIM(n.surname) AS surname, COUNT(DISTINCT n.person_id) AS n
            FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ? AND TRIM(n.surname) <> ''" . Privacy::personSql() . "
            GROUP BY TRIM(n.surname) ORDER BY surname") as $r) {
            if (self::initial($r['surname']) === $letter) $out[] = ['surname' => $r['surname'], 'count' => (int) $r['n']];
        }
        return $out;
    }

    /**
     * GET surnames/branches?surname=X – Zweige eines Namens: Träger des Namens (Geburts- oder Zweitname,
     * kein Ehename) und ihre Nachkommen, soweit sie den Namen weitertragen. Wurzeln sind Träger, deren
     * Eltern ihn nicht tragen. Kinder ohne den Namen (z. B. von Töchtern) stehen als letzte Ebene da.
     *
     * Antwort flach statt verschachtelt: roots (IDs), persons (schlanke Daten je ID),
     * families (je Träger: [{spouse, children}]), bearers (IDs).
     */
    public function branches(): void
    {
        $surname = trim((string) ($_GET['surname'] ?? ''));
        if ($surname === '') throw new HttpError(400, 'Nachname fehlt');

        $stmt = $this->db->prepare("
            SELECT DISTINCT p.id, p.sex FROM person_names n JOIN persons p ON p.id = n.person_id
            WHERE p.tree_id = ? AND TRIM(n.surname) = ? AND n.name_type <> 'MARRIED'" . Privacy::personSql());
        $stmt->execute([$this->treeId, $surname]);
        $bearers = [];   // ID → Geschlecht
        foreach ($stmt as $r) $bearers[(int) $r['id']] = $r['sex'];
        if (!$bearers) {
            $this->json(['roots' => [], 'persons' => (object) [], 'families' => (object) [], 'bearers' => []]);
            return;
        }
        $ids = array_keys($bearers);
        $in  = implode(',', array_fill(0, count($ids), '?'));

        // Eltern der Träger: wer einen Träger als Elternteil hat, ist keine Wurzel
        $stmt = $this->db->prepare("
            SELECT c.person_id AS child, par.person_id AS parent
            FROM family_members c
            JOIN family_members par ON par.family_id = c.family_id AND par.role IN ('HUSB', 'WIFE')
            WHERE c.role = 'CHIL' AND c.person_id IN ($in)");
        $stmt->execute($ids);
        $hasParents = $bearerParent = [];
        foreach ($stmt as $r) {
            $hasParents[(int) $r['child']] = true;
            if (isset($bearers[(int) $r['parent']])) $bearerParent[(int) $r['child']] = true;
        }

        // Familien der Träger: Partner und Kinder
        $stmt = $this->db->prepare("
            SELECT me.person_id AS person, me.family_id AS family,
                   sp.person_id AS spouse, c.person_id AS child
            FROM family_members me
            JOIN families f ON f.id = me.family_id AND f.tree_id = ?
            LEFT JOIN family_members sp ON sp.family_id = me.family_id AND sp.role IN ('HUSB', 'WIFE') AND sp.person_id <> me.person_id
            LEFT JOIN family_members c  ON c.family_id = me.family_id AND c.role = 'CHIL'
            WHERE me.role IN ('HUSB', 'WIFE') AND me.person_id IN ($in)
            ORDER BY me.person_id, me.family_id");
        $stmt->execute([$this->treeId, ...$ids]);
        $fam = $marriedIn = [];
        foreach ($stmt as $r) {
            [$p, $f] = [(int) $r['person'], (int) $r['family']];
            $fam[$p][$f] ??= ['spouse' => null, 'children' => []];
            if ($r['spouse'] !== null) {
                $sp = (int) $r['spouse'];
                $fam[$p][$f]['spouse'] = $sp;
                // Frau eines Trägers ohne eigene Eltern im Baum: vermutlich eingeheiratet
                if (isset($bearers[$sp]) && $bearers[$p] === 'F' && $bearers[$sp] !== 'F' && !isset($hasParents[$p])) $marriedIn[$p] = true;
            }
            if ($r['child'] !== null && !in_array((int) $r['child'], $fam[$p][$f]['children'], true)) {
                $fam[$p][$f]['children'][] = (int) $r['child'];
            }
        }

        // Wurzeln: Träger ohne Träger als Elternteil. Eine Frau ohne Eltern im Baum, die mit einem Träger
        // verheiratet ist, ist meist eingeheiratet (Ehename nicht als solcher markiert) – sie steht beim
        // Partner statt als eigener Zweig. Wer so von keiner Wurzel aus erreicht wird (Schleifen in den
        // Daten, Partner ebenfalls ohne Zweig), wird selbst Wurzel: kein Träger geht verloren.
        $roots   = array_values(array_filter($ids, fn($id) => !isset($bearerParent[$id]) && !isset($marriedIn[$id])));
        $reached = [];
        $visit   = function (int $id) use (&$visit, &$reached, $fam, $bearers) {
            if (isset($reached[$id])) return;
            $reached[$id] = true;
            foreach ($fam[$id] ?? [] as $f) {
                if ($f['spouse'] !== null) $reached[$f['spouse']] ??= true;   // steht beim Partner
                foreach ($f['children'] as $c) if (isset($bearers[$c])) $visit($c);
            }
        };
        foreach ($roots as $r) $visit($r);
        foreach ($ids as $id) if (!isset($reached[$id])) { $roots[] = $id; $visit($id); }

        $all = $ids;
        foreach ($fam as $fs) foreach ($fs as $f) {
            if ($f['spouse'] !== null) $all[] = $f['spouse'];
            array_push($all, ...$f['children']);
        }
        // Nur was die Zweige zeigen (Orte und Fotos machen große Zweige sonst mehrfach so groß)
        $persons = [];
        foreach ((new PersonQuery($this->db, $this->treeId))->slim($all) as $p) {
            $persons[$p['id']] = array_intersect_key($p, self::BRANCH_FIELDS);
        }

        // Nach Geburt, ersatzweise nach Tod; ohne beides ans Ende
        $born = fn(int $id) => GedcomDate::sortKey($persons[$id]['birth_date'] ?? null)
                            ?? GedcomDate::sortKey($persons[$id]['death_date'] ?? null) ?? PHP_INT_MAX;
        $byBirth = function (array $list) use ($born) {
            usort($list, fn($a, $b) => $born($a) <=> $born($b) ?: $a <=> $b);
            return $list;
        };

        $families = [];
        foreach ($fam as $p => $fs) {
            $families[$p] = array_values(array_map(fn($f) => [
                'spouse'   => isset($persons[$f['spouse'] ?? 0]) ? $f['spouse'] : null,
                'children' => $byBirth(array_values(array_filter($f['children'], fn($c) => isset($persons[$c])))),
            ], $fs));
        }

        $this->json([
            'roots'    => $byBirth($roots),
            'persons'  => (object) $persons,
            'families' => (object) $families,
            'bearers'  => $ids,
        ]);
    }

    /** Anfangsbuchstabe zum Gruppieren: groß, ohne Akzente (Ö → O, ß → S); kein Buchstabe → '#' */
    private static function initial(string $s): string
    {
        $c = mb_strtoupper(mb_substr(trim($s), 0, 1));
        if ($c === 'ß' || $c === 'ẞ') return 'S';
        if (class_exists(Normalizer::class)) {
            $c = preg_replace('/\p{Mn}+/u', '', (string) Normalizer::normalize($c, Normalizer::FORM_D));
        } else {
            $c = strtr($c, ['Ä' => 'A', 'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Å' => 'A', 'Č' => 'C', 'Ç' => 'C', 'Ć' => 'C',
                            'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E', 'Í' => 'I', 'Î' => 'I', 'Ñ' => 'N',
                            'Ö' => 'O', 'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ø' => 'O', 'Ř' => 'R', 'Š' => 'S', 'Ś' => 'S',
                            'Ü' => 'U', 'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ý' => 'Y', 'Ž' => 'Z', 'Ź' => 'Z', 'Ż' => 'Z']);
        }
        return preg_match('/^\p{L}$/u', $c) ? $c : '#';
    }

    private function rows(string $sql): array
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$this->treeId]);
        return $stmt->fetchAll();
    }
}
