<?php

declare(strict_types=1);

/**
 * Datenschutz für Besucher (nicht angemeldet): vermutlich lebende Personen werden anonymisiert.
 *
 * persons.is_private wird je Baum vorberechnet (recompute), weil die Entscheidung Verwandte
 * einbezieht und bei 50.000+ Personen nicht in jede Abfrage passt. Jede Änderung am Baum setzt
 * trees.privacy_stale; der nächste Besucherzugriff rechnet neu. Neue Personen sind bis dahin
 * privat (Spalten-Standard 1) – im Zweifel wird also eher zu viel verborgen.
 *
 * Eine Person gilt als privat, wenn
 *   * RESN „privacy“ oder „confidential“ gesetzt ist, oder
 *   * kein Tod/Begräbnis erfasst ist und sich nicht belegen lässt, dass sie vor mehr als
 *     ALIVE_YEARS Jahren geboren wurde.
 * Dafür wird je Person ein spätestmögliches Geburtsjahr geschätzt – nur aus eigenen Daten und
 * denen der direkten Verwandten (wie webtrees): frühestes eigenes Ereignis, Heirat − 15,
 * Kind − 15, Enkel − 30, Elternteil + 60, Großelternteil + 120, Partner + 40.
 * Geschätzte Jahre werden bewusst nicht weitergereicht: über Schwager und Schwiegereltern
 * würde sonst ein einziges falsches Datum halbe Bäume für „verstorben“ erklären.
 * Jahre unter 1000 gelten als Tippfehler und werden übergangen.
 */
final class Privacy
{
    public const ALIVE_YEARS = 100;
    public const LABEL       = 'Private Person';

    private const DEATH_TYPES = ['DEAT', 'BURI', 'CREM'];

    private static ?self $active = null;

    /** @var array<int,true>|null */
    private ?array $hidden = null;

    private function __construct(private readonly PDO $db, private readonly int $treeId) {}

    /** Für diese Anfrage gilt der Besucher-Datenschutz (in index.php für nicht angemeldete Zugriffe) */
    public static function enable(PDO $db, int $treeId): void
    {
        $stmt = $db->prepare('SELECT privacy_stale FROM trees WHERE id = ?');
        $stmt->execute([$treeId]);
        if ((int) $stmt->fetchColumn()) self::recompute($db, $treeId);
        self::$active = new self($db, $treeId);
    }

    public static function active(): bool
    {
        return self::$active !== null;
    }

    /** Ist die Person für Besucher verborgen? (immer false für Angemeldete) */
    public static function hides(int $personId): bool
    {
        if (!self::$active) return false;
        self::$active->hidden ??= self::$active->loadHidden();
        return isset(self::$active->hidden[$personId]);
    }

    /** Baum nach Änderungen zum Neuberechnen vormerken */
    public static function markStale(PDO $db, int $treeId): void
    {
        $db->prepare('UPDATE trees SET privacy_stale = 1, updated_at = updated_at WHERE id = ? AND privacy_stale = 0')->execute([$treeId]);
    }

    // ── SQL-Bausteine (leer bzw. „1“ für Angemeldete) ─────────────────

    /** Bedingung für Personen-Alias, z. B. „AND p.is_private = 0“ */
    public static function personSql(string $alias = 'p'): string
    {
        return self::$active ? " AND $alias.is_private = 0" : '';
    }

    /** Bedingung: Familie (ID-Ausdruck) hat keinen privaten Partner */
    public static function familySql(string $familyIdExpr): string
    {
        return self::$active ? " AND " . self::familyVisible($familyIdExpr) : '';
    }

    /** Bedingung: Medium (Alias m) hängt an keiner privaten Person, keiner privaten Familie und keinem ihrer Ereignisse oder Quellenangaben */
    public static function mediaSql(string $alias = 'm'): string
    {
        if (!self::$active) return '';
        return " AND NOT EXISTS (SELECT 1 FROM person_media x JOIN persons xp ON xp.id = x.person_id
                                 WHERE x.media_id = $alias.id AND xp.is_private = 1)
                 AND NOT EXISTS (SELECT 1 FROM event_media x JOIN person_events xe ON xe.id = x.event_id
                                 JOIN persons xp ON xp.id = xe.person_id
                                 WHERE x.media_id = $alias.id AND xp.is_private = 1)
                 AND NOT EXISTS (SELECT 1 FROM family_media x WHERE x.media_id = $alias.id
                                 AND NOT " . self::familyVisible('x.family_id') . ")
                 AND NOT EXISTS (SELECT 1 FROM family_event_media x JOIN family_events xe ON xe.id = x.event_id
                                 WHERE x.media_id = $alias.id AND NOT " . self::familyVisible('xe.family_id') . ")
                 AND NOT EXISTS (SELECT 1 FROM person_citation_media x JOIN person_citations xc ON xc.id = x.citation_id
                                 JOIN persons xp ON xp.id = xc.person_id
                                 WHERE x.media_id = $alias.id AND xp.is_private = 1)
                 AND NOT EXISTS (SELECT 1 FROM event_citation_media x JOIN event_citations xc ON xc.id = x.citation_id
                                 JOIN person_events xe ON xe.id = xc.event_id JOIN persons xp ON xp.id = xe.person_id
                                 WHERE x.media_id = $alias.id AND xp.is_private = 1)
                 AND NOT EXISTS (SELECT 1 FROM family_citation_media x JOIN family_citations xc ON xc.id = x.citation_id
                                 WHERE x.media_id = $alias.id AND NOT " . self::familyVisible('xc.family_id') . ")
                 AND NOT EXISTS (SELECT 1 FROM family_event_citation_media x JOIN family_event_citations xc ON xc.id = x.citation_id
                                 JOIN family_events xe ON xe.id = xc.event_id
                                 WHERE x.media_id = $alias.id AND NOT " . self::familyVisible('xe.family_id') . ")";
    }

    private static function familyVisible(string $familyIdExpr): string
    {
        return "NOT EXISTS (SELECT 1 FROM family_members pfm JOIN persons pp ON pp.id = pfm.person_id
                            WHERE pfm.family_id = $familyIdExpr AND pfm.role IN ('HUSB', 'WIFE') AND pp.is_private = 1)";
    }

    // ── Anonymisieren ─────────────────────────────────────────────────

    /** Schlanke Person (PersonQuery::slim) ohne Namen, Daten, Orte, Bild */
    public static function maskSlim(array $p): array
    {
        if (!self::hides((int) $p['id'])) return $p;
        return [
            ...$p,
            'restriction' => null, 'given_name' => null, 'surname' => null, 'surname_prefix' => null,
            'name_prefix' => null, 'nickname' => null, 'alt_surname' => null, 'name_count' => 0,
            'birth_date' => null, 'birth_place' => null, 'death_date' => null, 'death_place' => null,
            'photo_id' => null, 'photo_crop' => null, 'private' => true,
        ];
    }

    /** Verweis auf eine Person mit display_name (Mitglieder, Paten …); $dateKeys werden geleert */
    public static function maskRef(array $r, string $idKey = 'person_id', array $dateKeys = []): array
    {
        if (!self::hides((int) $r[$idKey])) return $r;
        $r['display_name'] = self::LABEL;
        $r['private'] = true;
        foreach ($dateKeys as $k) $r[$k] = null;
        return $r;
    }

    /** Vollständige Person für Besucher: nur Kennung und Geschlecht */
    public static function maskFull(array $person): array
    {
        return [
            'id' => (int) $person['id'], 'tree_id' => $person['tree_id'], 'sex' => $person['sex'],
            'restriction' => null, 'notes' => null, 'private' => true,
            'names' => [['name_type' => 'BIRTH', 'given_name' => self::LABEL, 'surname' => null]],
            'events' => [], 'citations' => [], 'associated_in' => [], 'associations' => [], 'media' => [],
        ];
    }

    // ── Berechnung ────────────────────────────────────────────────────

    /** @return array<int,true> */
    private function loadHidden(): array
    {
        $stmt = $this->db->prepare('SELECT id FROM persons WHERE tree_id = ? AND is_private = 1');
        $stmt->execute([$this->treeId]);
        return array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** persons.is_private für einen Baum neu setzen */
    public static function recompute(PDO $db, int $treeId): void
    {
        // zuerst zurücksetzen: Änderungen während der Berechnung setzen es wieder
        // (updated_at = updated_at: interne Spalten sind keine inhaltliche Änderung)
        $db->prepare('UPDATE trees SET privacy_stale = 0, updated_at = updated_at WHERE id = ?')->execute([$treeId]);

        $own  = [];   // Person → frühestes eigenes Ereignisjahr
        $dead = [];
        $resn = [];
        $year = function (?string $date): ?int {
            $y = $date !== null ? GedcomDate::extractYear($date) : null;
            return $y !== null && $y >= 1000 ? $y : null;
        };

        $stmt = $db->prepare('SELECT id, restriction FROM persons WHERE tree_id = ?');
        $stmt->execute([$treeId]);
        $persons = [];
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            $persons[(int) $r[0]] = true;
            if ($r[1] !== null && preg_match('/privacy|confidential/i', $r[1])) $resn[(int) $r[0]] = true;
        }

        $stmt = $db->prepare("
            SELECT e.person_id, e.event_type, e.event_date FROM person_events e JOIN persons p ON p.id = e.person_id
            WHERE p.tree_id = ? AND (e.event_date IS NOT NULL OR e.event_type IN ('DEAT', 'BURI', 'CREM'))
        ");
        $stmt->execute([$treeId]);
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            $pid = (int) $r[0];
            if (in_array($r[1], self::DEATH_TYPES, true)) $dead[$pid] = true;
            if (($y = $year($r[2])) !== null && (!isset($own[$pid]) || $y < $own[$pid])) $own[$pid] = $y;
        }

        // Familien: Partner, Kinder, frühestes Familienereignis (Heirat …)
        $stmt = $db->prepare('
            SELECT fm.family_id, fm.person_id, fm.role FROM family_members fm JOIN families f ON f.id = fm.family_id
            WHERE f.tree_id = ?
        ');
        $stmt->execute([$treeId]);
        $partners = [];
        $children = [];
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            if ($r[2] === 'CHIL') $children[(int) $r[0]][] = (int) $r[1];
            else                  $partners[(int) $r[0]][] = (int) $r[1];
        }
        $famYear = [];
        $stmt = $db->prepare('
            SELECT e.family_id, e.event_date FROM family_events e JOIN families f ON f.id = e.family_id
            WHERE f.tree_id = ? AND e.event_date IS NOT NULL
        ');
        $stmt->execute([$treeId]);
        while ($r = $stmt->fetch(PDO::FETCH_NUM)) {
            $fid = (int) $r[0];
            if (($y = $year($r[1])) !== null && (!isset($famYear[$fid]) || $y < $famYear[$fid])) $famYear[$fid] = $y;
        }

        // Eltern und Kinder je Person
        $parentsOf  = [];
        $childrenOf = [];
        foreach ($partners as $fid => $ps) {
            foreach ($children[$fid] ?? [] as $c) {
                foreach ($ps as $p) { $parentsOf[$c][] = $p; $childrenOf[$p][] = $c; }
            }
        }

        // Spätestmögliches Geburtsjahr aus eigenen Daten und denen der direkten Verwandten
        $latest = $own;
        $bound  = function (int $pid, ?int $y) use (&$latest): void {
            if ($y !== null && (!isset($latest[$pid]) || $y < $latest[$pid])) $latest[$pid] = $y;
        };
        foreach ($partners as $fid => $ps) {
            foreach ($ps as $p) {
                if (isset($famYear[$fid])) $bound($p, $famYear[$fid] - 15);
                foreach ($ps as $q) if ($q !== $p && isset($own[$q])) $bound($p, $own[$q] + 40);
            }
        }
        foreach ($childrenOf as $p => $kids) {
            foreach ($kids as $c) {
                if (isset($own[$c])) $bound($p, $own[$c] - 15);
                foreach ($childrenOf[$c] ?? [] as $g) if (isset($own[$g])) $bound($p, $own[$g] - 30);
            }
        }
        foreach ($parentsOf as $c => $ps) {
            foreach ($ps as $p) {
                if (isset($own[$p])) $bound($c, $own[$p] + 60);
                foreach ($parentsOf[$p] ?? [] as $g) if (isset($own[$g])) $bound($c, $own[$g] + 120);
            }
        }

        $limit  = (int) date('Y') - self::ALIVE_YEARS;
        $hidden = [];
        foreach ($persons as $pid => $_) {
            $isDead = isset($dead[$pid]) || (isset($latest[$pid]) && $latest[$pid] < $limit);
            if (isset($resn[$pid]) || !$isDead) $hidden[] = $pid;
        }

        $db->beginTransaction();
        try {
            $db->prepare('UPDATE persons SET is_private = 0, updated_at = updated_at WHERE tree_id = ? AND is_private = 1')->execute([$treeId]);
            foreach (array_chunk($hidden, 1000) as $chunk) {
                $db->prepare('UPDATE persons SET is_private = 1, updated_at = updated_at WHERE tree_id = ? AND id IN (' . implode(',', $chunk) . ')')
                   ->execute([$treeId]);
            }
            $db->commit();
        } catch (\Throwable $e) {
            $db->rollBack();
            Privacy::markStale($db, $treeId);
            throw $e;
        }
    }
}
