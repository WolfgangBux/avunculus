<?php

declare(strict_types=1);

/** Gemeinsame GEDCOM-Kennungen für API und Import */
final class GedcomTags
{
    /** Ereignisse einer Person (INDI events) */
    public const PERSON_EVENTS = [
        'BIRT', 'CHR', 'BAPM', 'CHRA', 'FCOM', 'CONF', 'BARM', 'BASM', 'BLES', 'ADOP',
        'GRAD', 'ORDN', 'CENS', 'RESI', 'EMIG', 'IMMI', 'NATU', 'RETI',
        'DEAT', 'BURI', 'CREM', 'PROB', 'WILL', 'EVEN',
    ];

    /** Merkmale einer Person (INDI attributes) – haben zusätzlich einen Wert */
    public const PERSON_ATTRIBUTES = [
        'OCCU', 'EDUC', 'RELI', 'TITL', 'NATI', 'DSCR', 'PROP', 'IDNO', 'SSN', 'CAST', 'NCHI', 'NMR', 'FACT',
    ];

    /** Rollen verknüpfter Personen (GEDCOM 7 ASSO.ROLE) */
    public const ASSO_ROLES = ['CHIL', 'CLERGY', 'FATH', 'FRIEND', 'GODP', 'HUSB', 'MOTH', 'MULTIPLE',
                               'NGHBR', 'OFFICIATOR', 'PARENT', 'SPOU', 'WIFE', 'WITN', 'OTHER'];

    /** Datenschutz-Kennzeichen (RESN) */
    public const RESTRICTIONS = ['CONFIDENTIAL', 'LOCKED', 'PRIVACY'];

    /** Medium einer Quelle im Archiv (REPO.CALN.MEDI) */
    public const MEDIA_TYPES = ['AUDIO', 'BOOK', 'CARD', 'ELECTRONIC', 'FICHE', 'FILM', 'MAGAZINE', 'MANUSCRIPT',
                                'MAP', 'NEWSPAPER', 'PHOTO', 'TOMBSTONE', 'VIDEO', 'OTHER'];

    /** Abstammungsart eines Kindes in einer Familie (PEDI) */
    public const PEDIGREES = ['BIRTH', 'ADOPTED', 'FOSTER', 'SEALED', 'OTHER'];

    public static function personTypes(): array
    {
        return [...self::PERSON_EVENTS, ...self::PERSON_ATTRIBUTES];
    }

    /** „confidential, Locked“ → „CONFIDENTIAL, LOCKED“; Unbekanntes wird verworfen */
    public static function restriction(?string $raw): ?string
    {
        $parts = array_intersect(
            array_map(fn($p) => strtoupper(trim($p)), explode(',', (string) $raw)),
            self::RESTRICTIONS
        );
        return $parts ? implode(', ', array_unique($parts)) : null;
    }

    public static function pedigree(?string $raw): ?string
    {
        $p = strtoupper(trim((string) $raw));
        return in_array($p, self::PEDIGREES, true) ? $p : null;
    }
}
