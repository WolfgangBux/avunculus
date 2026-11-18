<?php

declare(strict_types=1);

/**
 * Findet mögliche Dubletten zwischen Personen einer GEDCOM-Datei und eines Stammbaums.
 *
 * Beide Seiten werden als „Profile“ beschrieben:
 *   [ key, sex, names: [[given, surname]], birth, death (Jahre), birth_place,
 *     father: [[given, surname]], mother: […], spouses: [[[given, surname]], …] ]
 * Namen und Orte sind bereits mit normalize() vereinheitlicht.
 *
 * Bewertung (Punkte):
 *   Nachname gleich (Pflicht) 20 · Vorname gleich 30 / erster Vorname gleich 20
 *   Geburtsjahr gleich 30 / ±1 20 / ±2–5 5 / sonst −40
 *   Sterbejahr  gleich 15 / ±1 10 / ±2–5 0 / sonst −30
 *   Geburtsort gleich 10 · Vater 15 · Mutter 15 · Partner 15 · Geschlecht gleich 5
 *   Widersprüchliches Geschlecht oder verschiedene Vornamen schließen einen Treffer aus.
 */
final class DuplicateMatcher
{
    public const MIN_SCORE  = 50;   // ab hier: möglicher Treffer
    public const SURE_SCORE = 85;   // ab hier: sicherer Treffer (vorausgewählt)

    private static ?Transliterator $translit = null;

    /** Kleinbuchstaben, Umlaute ausgeschrieben, Akzente und Satzzeichen entfernt */
    public static function normalize(?string $s): string
    {
        if ($s === null || $s === '') return '';
        $s = mb_strtolower(trim($s));
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        self::$translit ??= Transliterator::create('Any-Latin; Latin-ASCII');
        if (self::$translit) $s = self::$translit->transliterate($s);
        $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }

    /** Erster Teil einer Ortsangabe („Passau, Bayern“ → „passau“) */
    public static function placeKey(?string $place): string
    {
        return self::normalize(explode(',', (string) $place)[0]);
    }

    /**
     * @param array $fileProfiles     Profile der Datei (key = GEDCOM-xref)
     * @param array $existingProfiles Profile des Baums  (key = Personen-ID)
     * @return array<string, list<array{key: int, score: int, sure: bool}>> Kandidaten je xref, beste zuerst
     */
    public function match(array $fileProfiles, array $existingProfiles): array
    {
        // Index über alle Nachnamen (inkl. Geburts- und Ehenamen) der vorhandenen Personen
        $index = [];
        foreach ($existingProfiles as $e) {
            foreach ($e['names'] as [, $surname]) {
                if ($surname !== '') $index[$surname][$e['key']] = true;
            }
        }

        $result = [];
        foreach ($fileProfiles as $f) {
            $candidateKeys = [];
            foreach ($f['names'] as [, $surname]) {
                foreach (array_keys($index[$surname] ?? []) as $k) $candidateKeys[$k] = true;
            }

            $scored = [];
            foreach (array_keys($candidateKeys) as $k) {
                $score = $this->score($f, $existingProfiles[$k]);
                if ($score >= self::MIN_SCORE) {
                    $scored[] = ['key' => $k, 'score' => $score, 'sure' => $score >= self::SURE_SCORE];
                }
            }
            if ($scored) {
                usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
                $result[$f['key']] = array_slice($scored, 0, 3);
            }
        }
        return $result;
    }

    private function score(array $f, array $e): int
    {
        // Geschlecht: Widerspruch schließt aus
        if (in_array($f['sex'], ['M', 'F'], true) && in_array($e['sex'], ['M', 'F'], true) && $f['sex'] !== $e['sex']) {
            return 0;
        }

        // Bester Namensvergleich über alle Namensvarianten beider Seiten
        $nameScore = -1;
        foreach ($f['names'] as [$fg, $fs]) {
            foreach ($e['names'] as [$eg, $es]) {
                if ($fs === '' || $fs !== $es) continue;
                $nameScore = max($nameScore, 20 + $this->givenScore($fg, $eg));
            }
        }
        if ($nameScore < 20) return 0;              // kein gemeinsamer Nachname
        if ($nameScore === 20 && $this->bothGiven($f, $e)) return 0;   // Vornamen verschieden

        $score  = $nameScore;
        $score += $this->yearScore($f['birth'], $e['birth'], [30, 20, 5, -40]);
        $score += $this->yearScore($f['death'], $e['death'], [15, 10, 0, -30]);
        if ($f['birth_place'] !== '' && $f['birth_place'] === $e['birth_place']) $score += 10;
        if ($f['sex'] !== 'U' && $f['sex'] === $e['sex']) $score += 5;

        if ($this->sameAnyPerson($f['father'], $e['father'])) $score += 15;
        if ($this->sameAnyPerson($f['mother'], $e['mother'])) $score += 15;
        foreach ($f['spouses'] as $fsp) {
            foreach ($e['spouses'] as $esp) {
                if ($this->sameAnyPerson($fsp, $esp)) { $score += 15; break 2; }
            }
        }
        return $score;
    }

    private function givenScore(string $a, string $b): int
    {
        if ($a === '' || $b === '') return 0;
        if ($a === $b) return 30;
        $ta = explode(' ', $a);
        $tb = explode(' ', $b);
        if ($ta[0] === $tb[0]) return 20;
        // Rufname an anderer Stelle („Johann Georg“ ↔ „Georg“)
        if (array_intersect($ta, $tb) && (count($ta) === 1 || count($tb) === 1)) return 15;
        return 0;
    }

    private function bothGiven(array $f, array $e): bool
    {
        $has = fn(array $p) => (bool) array_filter($p['names'], fn($n) => $n[0] !== '');
        return $has($f) && $has($e);
    }

    /** @param int[] $points [gleich, ±1, ±2–5, weiter] */
    private function yearScore(?int $a, ?int $b, array $points): int
    {
        if (!$a || !$b) return 0;
        $d = abs($a - $b);
        return $d === 0 ? $points[0] : ($d === 1 ? $points[1] : ($d <= 5 ? $points[2] : $points[3]));
    }

    /** Gleiche Person anhand des Namens (Nachname gleich, erster Vorname gleich) */
    private function sameAnyPerson(array $namesA, array $namesB): bool
    {
        foreach ($namesA as [$ga, $sa]) {
            foreach ($namesB as [$gb, $sb]) {
                if ($sa !== '' && $sa === $sb && $ga !== '' && explode(' ', $ga)[0] === explode(' ', $gb)[0]) return true;
            }
        }
        return false;
    }
}
