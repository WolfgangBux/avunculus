<?php

declare(strict_types=1);

/**
 * Mediendateien im Dateisystem: <MEDIA_DIR>/<Baum-ID>/<Datei>, Vorschaubilder in …/thumbs/.
 * MEDIA_DIR kommt aus backend/.env, Standard ist data/media im Projektordner (außerhalb von public/).
 */
final class MediaStore
{
    public const THUMB_SIZE = 320;

    /** Endungen für gespeicherte Dateien je MIME-Typ (sonst die ursprüngliche Endung) */
    private const EXT = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif', 'image/webp' => 'webp',
        'image/bmp' => 'bmp', 'image/tiff' => 'tif', 'image/svg+xml' => 'svg', 'image/heic' => 'heic',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
        'audio/mpeg' => 'mp3', 'audio/wav' => 'wav', 'audio/x-wav' => 'wav', 'audio/ogg' => 'ogg',
        'video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-msvideo' => 'avi',
    ];

    /** Typen, die GD verkleinern kann */
    private const THUMBABLE = ['image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/bmp'];

    /** In dieser Anfrage geschriebene Dateien – bei Abbruch (Rollback) wieder entfernen */
    private static array $written = [];

    public static function baseDir(): string
    {
        Database::getConnection();   // lädt .env nach $_ENV
        $dir = $_ENV['MEDIA_DIR'] ?? '';
        if ($dir === '') return dirname(__DIR__, 2) . '/data/media';
        return str_starts_with($dir, '/') ? rtrim($dir, '/') : dirname(__DIR__, 2) . '/' . trim($dir, '/');
    }

    public static function treeDir(int $treeId): string
    {
        return self::baseDir() . '/' . $treeId;
    }

    public static function path(int $treeId, string $fileName): string
    {
        return self::treeDir($treeId) . '/' . basename($fileName);
    }

    /**
     * Datei in den Medienordner übernehmen (verschieben bzw. kopieren).
     * @return array{file_name: string, mime_type: string, file_size: int, width: ?int, height: ?int, checksum: string}
     */
    public static function store(int $treeId, string $tmpFile, string $originalName, bool $move = true): array
    {
        $mime = self::mimeOf($tmpFile, $originalName);
        $ext  = self::EXT[$mime] ?? strtolower(preg_replace('/[^A-Za-z0-9]/', '', pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin');
        $dir  = self::treeDir($treeId);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Medienordner kann nicht angelegt werden: $dir");
        }
        $name   = bin2hex(random_bytes(12)) . '.' . substr($ext, 0, 10);
        $target = "$dir/$name";
        $info   = self::describe($tmpFile, $mime);
        if (!($move ? rename($tmpFile, $target) : copy($tmpFile, $target))) {
            throw new RuntimeException('Die Datei konnte nicht gespeichert werden.');
        }
        self::$written[] = $target;
        return ['file_name' => $name, 'mime_type' => $mime, ...$info];
    }

    /** @return array{file_size: int, width: ?int, height: ?int, checksum: string} */
    public static function describe(string $file, string $mime): array
    {
        $w = $h = null;
        if (str_starts_with($mime, 'image/') && ($size = @getimagesize($file))) {
            [$w, $h] = $size;
        }
        return ['file_size' => filesize($file), 'width' => $w, 'height' => $h, 'checksum' => sha1_file($file)];
    }

    public static function mimeOf(string $file, string $originalName = ''): string
    {
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file) ?: 'application/octet-stream';
        // finfo erkennt manche Formate nur grob – dann nach Endung
        if (in_array($mime, ['application/octet-stream', 'application/zip'], true)) {
            $byExt = array_search(strtolower(pathinfo($originalName, PATHINFO_EXTENSION)), self::EXT, true);
            if ($byExt !== false) return $byExt;
        }
        return $mime;
    }

    public static function delete(int $treeId, string $fileName): void
    {
        @unlink(self::path($treeId, $fileName));
        @unlink(self::thumbPath($treeId, $fileName));
    }

    /** Ganzen Medienordner eines Baums entfernen */
    public static function deleteTree(int $treeId): void
    {
        $dir = self::treeDir($treeId);
        if (!is_dir($dir)) return;
        foreach (glob("$dir/thumbs/*") ?: [] as $f) @unlink($f);
        @rmdir("$dir/thumbs");
        foreach (glob("$dir/*") ?: [] as $f) if (is_file($f)) @unlink($f);
        @rmdir($dir);
    }

    /** Nach einem Rollback: in dieser Anfrage gespeicherte Dateien wieder entfernen */
    public static function discardWritten(): void
    {
        foreach (self::$written as $f) @unlink($f);
        self::$written = [];
    }

    public static function canThumb(string $mime): bool
    {
        return in_array($mime, self::THUMBABLE, true) && function_exists('imagecreatetruecolor');
    }

    private static function thumbPath(int $treeId, string $fileName): string
    {
        return self::treeDir($treeId) . '/thumbs/' . basename($fileName) . '.jpg';
    }

    /** Vorschaubild (JPEG, längste Seite THUMB_SIZE) – wird beim ersten Abruf erzeugt; null, wenn nicht möglich */
    public static function thumb(int $treeId, string $fileName, string $mime): ?string
    {
        $thumb = self::thumbPath($treeId, $fileName);
        if (is_file($thumb)) return $thumb;
        $src = self::path($treeId, $fileName);
        if (!self::canThumb($mime) || !is_file($src)) return null;

        $img = @match ($mime) {
            'image/jpeg' => imagecreatefromjpeg($src),
            'image/png'  => imagecreatefrompng($src),
            'image/gif'  => imagecreatefromgif($src),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($src) : false,
            'image/bmp'  => function_exists('imagecreatefrombmp') ? imagecreatefrombmp($src) : false,
            default      => false,
        };
        if (!$img) return null;
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) $img = self::orient($img, $src);

        $w = imagesx($img); $h = imagesy($img);
        $scale = min(1, self::THUMB_SIZE / max($w, $h));
        $tw = max(1, (int) round($w * $scale)); $th = max(1, (int) round($h * $scale));
        $out = imagecreatetruecolor($tw, $th);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));   // Transparenz auf Weiß
        imagecopyresampled($out, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
        if (!is_dir(dirname($thumb))) @mkdir(dirname($thumb), 0775, true);
        $ok = imagejpeg($out, $thumb, 82);
        unset($img, $out);   // große Fotos sofort freigeben (imagedestroy ist seit PHP 8.0 wirkungslos, ab 8.5 veraltet)
        return $ok ? $thumb : null;
    }

    /** Hochkant aufgenommene Fotos (EXIF-Orientation) richtig herum drehen */
    private static function orient(GdImage $img, string $src): GdImage
    {
        $o = (int) (@exif_read_data($src)['Orientation'] ?? 1);
        $rotated = match ($o) {
            3 => imagerotate($img, 180, 0),
            6 => imagerotate($img, -90, 0),
            8 => imagerotate($img, 90, 0),
            default => null,
        };
        return $rotated ?: $img;
    }
}
