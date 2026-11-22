<?php

declare(strict_types=1);

/** Fehlersuche: unplausible Lebensdaten im aktuellen Stammbaum (nur Bearbeiter, siehe Plausibility) */
class CheckController extends TreeScopedController
{
    // GET /api/checks  –  { items: [{ check, person, other?, family?, age?, days?, dates }], counts: { check: n },
    //                       truncated, persons: { id: schlanke Personendaten } }
    public function index(): void
    {
        $result = (new Plausibility($this->db, $this->treeId))->run();

        $ids = [];
        foreach ($result['items'] as $i) {
            $ids[$i['person']] = true;
            if (isset($i['other'])) $ids[$i['other']] = true;
        }
        $persons = [];
        $query   = new PersonQuery($this->db, $this->treeId);
        foreach (array_chunk(array_keys($ids), 1000) as $chunk) {
            foreach ($query->slim($chunk) as $p) $persons[$p['id']] = $p;
        }
        $this->json([...$result, 'counts' => (object) $result['counts'], 'persons' => (object) $persons]);
    }
}
