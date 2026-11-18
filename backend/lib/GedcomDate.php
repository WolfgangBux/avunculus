<?php

declare(strict_types=1);

/** GEDCOM-Datumsangaben normalisieren und prüfen (gemeinsam für API und Import). */
final class GedcomDate
{
    public const MONTHS = ['JAN','FEB','MAR','APR','MAY','JUN','JUL','AUG','SEP','OCT','NOV','DEC'];

    /** Deutsche Monatsnamen und Wörter → GEDCOM (für repair) */
    private const GERMAN = [
        'JANUAR' => 'JAN', 'JÄNNER' => 'JAN', 'FEBRUAR' => 'FEB', 'MÄRZ' => 'MAR', 'MAERZ' => 'MAR', 'MRZ' => 'MAR',
        'APRIL' => 'APR', 'MAI' => 'MAY', 'JUNI' => 'JUN', 'JULI' => 'JUL', 'AUGUST' => 'AUG',
        'SEPTEMBER' => 'SEP', 'OKTOBER' => 'OCT', 'OKT' => 'OCT', 'NOVEMBER' => 'NOV', 'DEZEMBER' => 'DEC', 'DEZ' => 'DEC',
        'UM' => 'ABT', 'CA' => 'ABT', 'VOR' => 'BEF', 'NACH' => 'AFT', 'ZWISCHEN' => 'BET', 'UND' => 'AND', 'VON' => 'FROM', 'BIS' => 'TO',
    ];

    public static function normalize(string $s): string
    {
        return preg_replace('/\s+/', ' ', strtoupper(trim($s)));
    }

    public static function isValid(string $raw): bool
    {
        $s   = self::normalize($raw);
        $mon = implode('|', self::MONTHS);
        $yr  = '\d{1,4}';
        $dy  = '\d{1,2}';

        $simple   = "/^(?:$dy (?:$mon) $yr|(?:$mon) $yr|$yr)$/";
        $isSimple = static fn(string $d): bool => (bool) preg_match($simple, $d);

        if ($isSimple($s)) return true;
        if (preg_match('/^(ABT|EST|CAL) (.+)$/', $s, $m)) return $isSimple($m[2]);
        if (preg_match('/^(BEF|AFT) (.+)$/',     $s, $m)) return $isSimple($m[2]);
        if (preg_match('/^BET (.+?) AND (.+)$/',  $s, $m)) return $isSimple($m[1]) && $isSimple($m[2]);
        if (preg_match('/^FROM (.+?) TO (.+)$/',  $s, $m)) return $isSimple($m[1]) && $isSimple($m[2]);
        if (preg_match('/^FROM (.+)$/',           $s, $m)) return $isSimple($m[1]);
        if (preg_match('/^TO (.+)$/',             $s, $m)) return $isSimple($m[1]);

        return false;
    }

    /**
     * Numerische Tagesdaten („20 6 1879“, „20.6.1879“) in GEDCOM-Form bringen („20 JUN 1879“) –
     * auch innerhalb von ABT/BET … AND usw. Nur wenn das Ergebnis gültig ist, sonst unverändert.
     */
    public static function repair(string $raw): string
    {
        $s = self::normalize($raw);
        if ($s === '' || self::isValid($s)) return $s;
        $fixed = mb_strtoupper($s);
        // Deutsche Schreibweisen: Monatsnamen, „um“, „und“
        $fixed = preg_replace_callback('/\b(' . implode('|', array_keys(self::GERMAN)) . ')\b\.?/u',
            fn($m) => self::GERMAN[$m[1]], $fixed);
        // „BET JAN AND NOV 1690“ → Jahr auch beim ersten Monat
        $mon   = implode('|', self::MONTHS);
        $fixed = preg_replace("/^(BET|FROM) ((?:\d{1,2} )?(?:$mon)) (AND|TO) ((?:\d{1,2} )?(?:$mon) (\d{3,4}))$/", '$1 $2 $5 $3 $4', $fixed);
        $fixed = preg_replace_callback('/\b(\d{1,2})[ .\/-](\d{1,2})[ .\/-](\d{3,4})\b/', function ($m) {
            $d = (int) $m[1]; $mo = (int) $m[2];
            return $mo >= 1 && $mo <= 12 && $d >= 1 && $d <= 31 ? $d . ' ' . self::MONTHS[$mo - 1] . ' ' . (int) $m[3] : $m[0];
        }, $fixed);
        return self::isValid($fixed) ? $fixed : $s;
    }

    public static function extractYear(string $raw): ?int
    {
        if ($raw === '') return null;
        preg_match('/\b(\d{3,4})\b/', strtoupper($raw), $m);
        return isset($m[1]) ? (int) $m[1] : null;
    }

    /**
     * Sortierschlüssel einer Datumsangabe (JJJJMMTT·10 + Feinheit) – fehlender Tag/Monat = 0,
     * BEF etwas früher, AFT etwas später; bei Zeiträumen (BET/FROM) zählt der Anfang. null = kein Jahr erkennbar.
     */
    public static function sortKey(?string $raw): ?int
    {
        $s = self::normalize((string) $raw);
        if ($s === '') return null;
        $shift = str_starts_with($s, 'BEF') ? 0 : (str_starts_with($s, 'AFT') ? 9 : 5);
        $s = preg_replace('/^(ABT|EST|CAL|BEF|AFT|BET|FROM|TO|INT)\s+/', '', $s);
        $s = preg_replace('/\s+(AND|TO)\s+.*$/', '', $s);

        $mon = implode('|', self::MONTHS);
        $y = $m = $d = 0;
        if (preg_match("/^(?:(\d{1,2}) )?($mon) (\d{1,4})\b/", $s, $x)) {
            [$d, $m, $y] = [(int) $x[1], array_search($x[2], self::MONTHS, true) + 1, (int) $x[3]];
        } elseif (preg_match('/^(\d{1,2})[ .\/](\d{1,2})[ .\/](\d{3,4})\b/', $s, $x)) {   // „20 6 1879“, „20.6.1879“
            [$d, $m, $y] = [(int) $x[1], (int) $x[2], (int) $x[3]];
        } elseif (preg_match('/\b(\d{3,4})\b/', $s, $x)) {
            $y = (int) $x[1];
        } else {
            return null;
        }
        if ($m > 12) $m = 0;
        if ($d > 31) $d = 0;
        return (($y * 100 + $m) * 100 + $d) * 10 + $shift;
    }

    /** Rangfolge bei gleichem oder fehlendem Datum: Geburt vor Taufe … Tod vor Begräbnis */
    private const LIFE_ORDER = [
        'BIRT' => 1, 'CHR' => 2, 'BAPM' => 2, 'ENGA' => 3, 'MARB' => 3, 'MARC' => 3, 'MARL' => 3, 'MARR' => 4,
        'DIVF' => 6, 'DIV' => 7, 'ANUL' => 7,
        'DEAT' => 10, 'CREM' => 11, 'BURI' => 11, 'PROB' => 12,
    ];
    private const BEFORE_ALL = ['BIRT', 'CHR', 'BAPM'];
    private const AFTER_ALL  = ['DEAT', 'CREM', 'BURI', 'PROB'];

    /**
     * Ereignisse chronologisch ordnen. Ereignisse ohne Datum: Geburt/Taufe an den Anfang, Tod/Begräbnis
     * ans Ende, alle übrigen bleiben hinter ihrem bisherigen Vorgänger. Stabil bei Gleichstand.
     */
    public static function sortEvents(array $events, string $dateKey = 'event_date', string $typeKey = 'event_type'): array
    {
        $prev = PHP_INT_MIN;
        foreach (array_values($events) as $i => $e) {
            $key  = self::sortKey($e[$dateKey] ?? null);
            $type = $e[$typeKey] ?? '';
            if ($key === null) {
                $key = in_array($type, self::BEFORE_ALL, true) ? PHP_INT_MIN
                     : (in_array($type, self::AFTER_ALL, true) ? PHP_INT_MAX : $prev);
            } else {
                $prev = $key;
            }
            $rows[] = [$key, self::LIFE_ORDER[$type] ?? 5, $i, $e];
        }
        if (empty($rows)) return [];
        usort($rows, fn($a, $b) => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return array_column($rows, 3);
    }
}
