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

    // GET /api/system/settings  –  Einstellungen, die der Admin in der Oberfläche ändern darf
    public function settings(): void
    {
        $out = [];
        foreach (Settings::EDITABLE as $name) $out[$name] = Settings::get($this->db, $name) ?? '';
        $this->json($out);
    }

    // PUT /api/system/settings  –  body: { site_name?, imprint_url?, privacy_url? }  –  leer = kein Name bzw. kein Link
    public function saveSettings(): void
    {
        $data   = $this->body();
        $values = [];
        $errors = [];
        foreach (Settings::EDITABLE as $name) {
            if (!array_key_exists($name, $data)) continue;
            $v = trim((string) $data[$name]);
            if ($name === 'site_name') {
                // Klartext in einer Zeile (Vue gibt ihn als Text aus, nie als HTML)
                $v = trim(preg_replace('/[\p{Cc}\p{Zl}\p{Zp}]+/u', ' ', $v) ?? '');
                if (mb_strlen($v) > Settings::SITE_NAME_MAX) $errors[$name] = 'Höchstens ' . Settings::SITE_NAME_MAX . ' Zeichen';
                $values[$name] = $v;
                continue;
            }
            // Nur echte Webadressen – sonst ließe sich über den Link Skript einschleusen (javascript:…)
            if ($v !== '' && (strlen($v) > 500 || !preg_match('~^https?://[^\s<>"]+$~i', $v)
                              || !filter_var($v, FILTER_VALIDATE_URL))) {
                $errors[$name] = 'Bitte eine Adresse mit http:// oder https:// eingeben';
            }
            $values[$name] = $v;
        }
        if ($errors) { $this->json(['errors' => $errors], 422); return; }
        foreach ($values as $name => $v) Settings::set($this->db, $name, $v === '' ? null : $v);
        $this->settings();
    }

    // POST /api/system/update  –  body: { version }  –  neueste Version installieren
    public function installUpdate(): void
    {
        $version = (string) ($this->body()['version'] ?? '');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $version)) throw new HttpError(422, 'Ungültige Version');
        $this->json((new Updater($this->db))->install($version));
    }
}
