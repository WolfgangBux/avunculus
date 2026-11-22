<?php

declare(strict_types=1);

/**
 * Markierte Personen in Bilddateien (XMP): lesen und – bei JPEG – schreiben.
 *
 * Lesen: Standard der Metadata Working Group („MWG Regions“, Lightroom, digiKam, Apple Fotos, Picasa),
 * ersatzweise die Regionen der Windows-Fotogalerie (Microsoft „MP“). Ein Rahmen ist dort über
 * seinen Mittelpunkt (x, y) und Breite/Höhe als Anteile des Bilds beschrieben; zurück kommt er wie in
 * person_media als linke obere Ecke plus Größe. Bezug ist das richtig gedrehte (angezeigte) Bild.
 *
 * Schreiben: Die Markierungen aus Avunculus kommen als eigene Regionen in die Liste; jede trägt
 * avu:PersonId, damit sie beim nächsten Schreiben ersetzt statt verdoppelt wird. Regionen anderer
 * Programme bleiben unverändert stehen, ebenso alle übrigen Metadaten. Die neue Datei entsteht neben
 * der alten, wird geprüft (Bildgröße, Markierungen lesbar) und erst dann gegen das Original getauscht.
 */
final class XmpRegions
{
    public const NS = [
        'x'      => 'adobe:ns:meta/',
        'rdf'    => 'http://www.w3.org/1999/02/22-rdf-syntax-ns#',
        'mwg-rs' => 'http://www.metadataworkinggroup.com/schemas/regions/',
        'stArea' => 'http://ns.adobe.com/xmp/sType/Area#',
        'stDim'  => 'http://ns.adobe.com/xap/1.0/sType/Dimensions#',
        'MPRI'   => 'http://ns.microsoft.com/photo/1.2/t/RegionInfo#',
        'MPReg'  => 'http://ns.microsoft.com/photo/1.2/t/Region#',
        'avu'    => 'https://github.com/WolfgangBux/avunculus/ns/xmp/1.0/',
    ];

    private const JPEG_XMP_HEADER = "http://ns.adobe.com/xap/1.0/\0";
    private const READ_BYTES      = 8 * 1024 * 1024;   // XMP steht vorn in der Datei; große Bilder nicht ganz lesen

    /** Kann diese Datei Markierungen aufnehmen? (Schreiben nur JPEG; DOM ist bei PHP üblich, aber optional) */
    public static function canWrite(string $mime): bool
    {
        return $mime === 'image/jpeg' && class_exists(DOMDocument::class);
    }

    /**
     * Markierte Personen der Datei.
     * @return list<array{name: string, type: ?string, crop: array, person_id: ?int}>
     */
    public static function read(string $path): array
    {
        if (!class_exists(DOMDocument::class) || !is_file($path)) return [];
        $data = (string) @file_get_contents($path, false, null, 0, self::READ_BYTES);
        $xml  = self::packetXml($data);
        if ($xml === null || !($dom = self::load($xml))) return [];
        $xp = self::xpath($dom);
        return self::mwgRegions($xp) ?: self::mpRegions($xp);
    }

    /**
     * Markierungen aus Avunculus in eine JPEG-Datei schreiben (ersetzt frühere eigene, behält fremde).
     * $ours: [{ person_id, name, crop: {left, top, width, height} }]; $w/$h: Größe des angezeigten Bilds.
     * @return bool true = Datei geändert; false = nichts zu tun bzw. nicht möglich (Original unverändert)
     */
    public static function write(string $path, array $ours, int $w, int $h): bool
    {
        $data = @file_get_contents($path);
        if ($data === false || !str_starts_with($data, "\xFF\xD8")) return false;
        $segments = self::jpegSegments($data);
        if ($segments === null) return false;

        $xmpSeg = null;
        foreach ($segments as $s) {
            if ($s['marker'] === 0xE1 && str_starts_with(substr($data, $s['start'] + 4, 29), self::JPEG_XMP_HEADER)) { $xmpSeg = $s; break; }
        }
        $oldXml = $xmpSeg ? self::packetXml(substr($data, $xmpSeg['start'] + 4 + 29, $xmpSeg['end'] - $xmpSeg['start'] - 4 - 29)) : null;
        if ($oldXml === null && !$ours) return false;   // nichts drin, nichts hinein

        $dom = $oldXml !== null ? self::load($oldXml) : self::emptyPacket();
        if (!$dom) return false;
        if (!self::setRegions($dom, $ours, $w, $h)) return false;

        $packet  = self::JPEG_XMP_HEADER . self::serialize($dom);
        if (strlen($packet) + 2 > 0xFFFF) return false;   // passt nicht in ein Segment (Extended XMP wird nicht angefasst)
        $segment = "\xFF\xE1" . pack('n', strlen($packet) + 2) . $packet;

        if ($xmpSeg) {
            $new = substr($data, 0, $xmpSeg['start']) . $segment . substr($data, $xmpSeg['end']);
        } else {
            // nach JFIF (APP0) und Exif (APP1) am Anfang einfügen
            $at = 2;
            foreach ($segments as $s) {
                if ($s['marker'] !== 0xE0 && $s['marker'] !== 0xE1) break;
                $at = $s['end'];
            }
            $new = substr($data, 0, $at) . $segment . substr($data, $at);
        }

        // Erst prüfen, dann tauschen: gleiche Bildgröße, Markierungen wieder lesbar
        $tmp = $path . '.xmp-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $new) !== strlen($new)) { @unlink($tmp); return false; }
        $before = @getimagesize($path);
        $after  = @getimagesize($tmp);
        $mine   = array_filter(self::read($tmp), fn($r) => $r['person_id'] !== null);
        if (!$after || !$before || $after[0] !== $before[0] || $after[1] !== $before[1] || count($mine) !== count($ours)) {
            @unlink($tmp);
            return false;
        }
        @chmod($tmp, fileperms($path) & 0777);
        if (!rename($tmp, $path)) { @unlink($tmp); return false; }
        return true;
    }

    // ── Lesen ─────────────────────────────────────────────────────────

    /** @return list<array> */
    private static function mwgRegions(DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query('//mwg-rs:Regions') as $regions) {
            $dimW = (float) self::val($xp, $regions, 'stDim', 'w');
            $dimH = (float) self::val($xp, $regions, 'stDim', 'h');
            foreach ($xp->query('.//mwg-rs:RegionList/*/rdf:li', $regions) as $li) {
                $type = self::val($xp, $li, 'mwg-rs', 'Type');
                if ($type !== null && !in_array(strtolower($type), ['face', 'pet', ''], true)) continue;   // Fokus, Barcode …
                $area = $xp->query('.//mwg-rs:Area', $li)->item(0);
                if (!$area) continue;
                [$x, $y, $w, $h] = array_map(fn($k) => self::val($xp, $area, 'stArea', $k), ['x', 'y', 'w', 'h']);
                if (!is_numeric($x) || !is_numeric($y) || !is_numeric($w) || !is_numeric($h)) continue;
                [$x, $y, $w, $h] = [(float) $x, (float) $y, (float) $w, (float) $h];
                if (strtolower((string) self::val($xp, $area, 'stArea', 'unit')) === 'pixel') {
                    if ($dimW <= 0 || $dimH <= 0) continue;
                    [$x, $y, $w, $h] = [$x / $dimW, $y / $dimH, $w / $dimW, $h / $dimH];
                }
                $crop = MediaLinks::normalizeCrop(['left' => $x - $w / 2, 'top' => $y - $h / 2, 'width' => $w, 'height' => $h]);
                if (!$crop) continue;
                $pid = self::val($xp, $li, 'avu', 'PersonId');
                $out[] = ['name' => trim((string) self::val($xp, $li, 'mwg-rs', 'Name')), 'type' => $type, 'crop' => $crop,
                          'person_id' => is_numeric($pid) ? (int) $pid : null];
            }
        }
        return $out;
    }

    /** Windows-Fotogalerie: Rectangle „x, y, w, h“ (linke obere Ecke) und PersonDisplayName */
    private static function mpRegions(DOMXPath $xp): array
    {
        $out = [];
        foreach ($xp->query('//MPRI:Regions/*/rdf:li') as $li) {
            $p = array_map('trim', explode(',', (string) self::val($xp, $li, 'MPReg', 'Rectangle')));
            if (count($p) !== 4 || array_filter($p, fn($v) => !is_numeric($v))) continue;
            $crop = MediaLinks::normalizeCrop(['left' => $p[0], 'top' => $p[1], 'width' => $p[2], 'height' => $p[3]]);
            if ($crop) $out[] = ['name' => trim((string) self::val($xp, $li, 'MPReg', 'PersonDisplayName')), 'type' => 'Face',
                                 'crop' => $crop, 'person_id' => null];
        }
        return $out;
    }

    /**
     * Wert einer Eigenschaft – RDF erlaubt sie als Attribut oder als Element (auch tiefer verschachtelt,
     * z. B. Lightroom: li › Description[@Name], digiKam: li › Name)
     */
    private static function val(DOMXPath $xp, DOMNode $ctx, string $prefix, string $local): ?string
    {
        $n = $xp->query("(./@$prefix:$local | .//@$prefix:$local | .//$prefix:$local)", $ctx)->item(0);
        if (!$n) return null;
        if ($n instanceof DOMElement) {
            // Sprachvarianten (rdf:Alt) – erste nehmen
            $alt = $xp->query('.//rdf:li', $n)->item(0);
            return trim(($alt ?? $n)->textContent);
        }
        return $n->nodeValue;
    }

    // ── Schreiben ─────────────────────────────────────────────────────

    /** Eigene Regionen ersetzen, fremde behalten; false bei unerwartetem Aufbau */
    private static function setRegions(DOMDocument $dom, array $ours, int $w, int $h): bool
    {
        $xp      = self::xpath($dom);
        $regions = $xp->query('//mwg-rs:Regions')->item(0);
        if (!$regions) {
            if (!$ours) return true;
            $desc = $xp->query('//rdf:RDF/rdf:Description')->item(0);
            if (!$desc) {
                $rdf = $xp->query('//rdf:RDF')->item(0);
                if (!$rdf) return false;
                $desc = $rdf->appendChild($dom->createElementNS(self::NS['rdf'], 'rdf:Description'));
                $desc->setAttributeNS(self::NS['rdf'], 'rdf:about', '');
            }
            self::declare($desc);
            $regions = $desc->appendChild($dom->createElementNS(self::NS['mwg-rs'], 'mwg-rs:Regions'));
            $regions->setAttributeNS(self::NS['rdf'], 'rdf:parseType', 'Resource');
        } else {
            self::declare($regions);
        }
        // Inhalt der Regions: direkt (parseType="Resource") oder in einem rdf:Description
        $holder = $xp->query('./rdf:Description', $regions)->item(0) ?? $regions;

        $bag = $xp->query('.//mwg-rs:RegionList/rdf:Bag', $holder)->item(0);
        if (!$bag) {
            $list = $xp->query('./mwg-rs:RegionList', $holder)->item(0)
                 ?? $holder->appendChild($dom->createElementNS(self::NS['mwg-rs'], 'mwg-rs:RegionList'));
            $bag  = $list->appendChild($dom->createElementNS(self::NS['rdf'], 'rdf:Bag'));
        }
        foreach (iterator_to_array($xp->query('./rdf:li', $bag)) as $li) {
            if ($xp->query('(.//@avu:PersonId | .//avu:PersonId)', $li)->length) $bag->removeChild($li);
        }

        foreach ($ours as $r) {
            $c  = $r['crop'];
            $li = $bag->appendChild($dom->createElementNS(self::NS['rdf'], 'rdf:li'));
            $li->setAttributeNS(self::NS['rdf'], 'rdf:parseType', 'Resource');
            $li->appendChild($dom->createElementNS(self::NS['mwg-rs'], 'mwg-rs:Name'))->appendChild($dom->createTextNode($r['name']));
            $li->appendChild($dom->createElementNS(self::NS['mwg-rs'], 'mwg-rs:Type', 'Face'));
            $area = $li->appendChild($dom->createElementNS(self::NS['mwg-rs'], 'mwg-rs:Area'));
            foreach (['x' => $c['left'] + $c['width'] / 2, 'y' => $c['top'] + $c['height'] / 2, 'w' => $c['width'], 'h' => $c['height']] as $k => $v) {
                $area->setAttributeNS(self::NS['stArea'], "stArea:$k", (string) round($v, 6));
            }
            $area->setAttributeNS(self::NS['stArea'], 'stArea:unit', 'normalized');
            $li->appendChild($dom->createElementNS(self::NS['avu'], 'avu:PersonId', (string) (int) $r['person_id']));
        }

        // Ohne jede Region: Regions ganz entfernen; sonst Bildgröße, auf die sich die Rahmen beziehen
        if (!$xp->query('./rdf:li', $bag)->length) {
            $regions->parentNode->removeChild($regions);
            return true;
        }
        foreach (iterator_to_array($xp->query('./mwg-rs:AppliedToDimensions', $holder)) as $old) $holder->removeChild($old);
        $dim = $dom->createElementNS(self::NS['mwg-rs'], 'mwg-rs:AppliedToDimensions');
        $dim->setAttributeNS(self::NS['stDim'], 'stDim:w', (string) $w);
        $dim->setAttributeNS(self::NS['stDim'], 'stDim:h', (string) $h);
        $dim->setAttributeNS(self::NS['stDim'], 'stDim:unit', 'pixel');
        $holder->insertBefore($dim, $holder->firstChild);
        return true;
    }

    /** Namensräume einmal am Element erklären statt an jedem neuen Kind */
    private static function declare(DOMElement $el): void
    {
        foreach (['mwg-rs', 'stArea', 'stDim', 'avu'] as $p) {
            $el->setAttributeNS('http://www.w3.org/2000/xmlns/', "xmlns:$p", self::NS[$p]);
        }
    }

    private static function emptyPacket(): ?DOMDocument
    {
        return self::load('<x:xmpmeta xmlns:x="adobe:ns:meta/"><rdf:RDF xmlns:rdf="' . self::NS['rdf'] . '">'
                        . '<rdf:Description rdf:about=""/></rdf:RDF></x:xmpmeta>');
    }

    /** XMP-Paket mit Platz zum späteren Bearbeiten (wie es andere Programme erwarten) */
    private static function serialize(DOMDocument $dom): string
    {
        $xml = $dom->saveXML($dom->documentElement);
        return "<?xpacket begin=\"\u{FEFF}\" id=\"W5M0MpCehiHzreSzNTczkc9d\"?>\n$xml\n"
             . str_repeat(str_repeat(' ', 99) . "\n", 20) . '<?xpacket end="w"?>';
    }

    // ── Hilfen ────────────────────────────────────────────────────────

    /** XML des XMP-Pakets aus Dateiinhalt (x:xmpmeta, ersatzweise nacktes rdf:RDF) */
    private static function packetXml(string $data): ?string
    {
        if (preg_match('#<x:xmpmeta\b.*?</x:xmpmeta>#s', $data, $m)) return $m[0];
        if (preg_match('#<rdf:RDF\b.*?</rdf:RDF>#s', $data, $m)) return $m[0];
        return null;
    }

    private static function load(string $xml): ?DOMDocument
    {
        $dom = new DOMDocument();
        $dom->preserveWhiteSpace = true;
        $prev = libxml_use_internal_errors(true);
        $ok   = $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        return $ok ? $dom : null;
    }

    private static function xpath(DOMDocument $dom): DOMXPath
    {
        $xp = new DOMXPath($dom);
        foreach (self::NS as $p => $uri) $xp->registerNamespace($p, $uri);
        return $xp;
    }

    /**
     * Segmente einer JPEG-Datei bis zum Bildbeginn (SOS): [{marker, start, end}] (start = 0xFF des Markers)
     * @return list<array{marker: int, start: int, end: int}>|null
     */
    private static function jpegSegments(string $data): ?array
    {
        $out = [];
        $pos = 2;
        $len = strlen($data);
        while ($pos + 4 <= $len) {
            if ($data[$pos] !== "\xFF") return null;
            $marker = ord($data[$pos + 1]);
            if ($marker === 0xFF) { $pos++; continue; }                 // Füllbytes
            if ($marker === 0xDA || $marker === 0xD9) return $out;      // Bilddaten beginnen / Ende
            $size = unpack('n', substr($data, $pos + 2, 2))[1];
            if ($size < 2 || $pos + 2 + $size > $len) return null;
            $out[] = ['marker' => $marker, 'start' => $pos, 'end' => $pos + 2 + $size];
            $pos += 2 + $size;
        }
        return null;
    }
}
