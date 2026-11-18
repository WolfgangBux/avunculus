<?php

declare(strict_types=1);

/**
 * Abbruch einer Anfrage mit HTTP-Status und Fehlermeldung, z. B. 404 „Person nicht gefunden“.
 * index.php fängt sie und antwortet mit { "error": … }. Laufende Transaktionen werden auf dem
 * Weg nach oben von den catch-Blöcken der Controller zurückgerollt.
 */
final class HttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $message)
    {
        parent::__construct($message);
    }
}
