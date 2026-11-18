<?php

declare(strict_types=1);

/** Version und Updates der Installation (nur Admin) */
class SystemController extends BaseController
{
    // GET /api/system/update[?refresh=1]  –  installierte und neueste Version, ob automatisch installierbar
    public function updateStatus(): void
    {
        $this->json((new Updater($this->db))->status(!empty($_GET['refresh'])));
    }

    // POST /api/system/update  –  body: { version }  –  neueste Version installieren
    public function installUpdate(): void
    {
        $version = (string) ($this->body()['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new HttpError(422, 'Ungültige Version');
        $this->json((new Updater($this->db))->install($version));
    }
}
