<?php

declare(strict_types=1);

/** Verwandtschaftsrechner: Beziehung zwischen zwei Personen des Baums (siehe Kinship) */
class RelationshipController extends TreeScopedController
{
    // GET /api/relationship?a=&b=  –  { a, b, relations: [...], persons: { id: schlanke Personendaten } }
    public function show(): void
    {
        $a = (int) ($_GET['a'] ?? 0);
        $b = (int) ($_GET['b'] ?? 0);
        if (count($this->idsInTree('persons', [$a, $b])) !== count(array_unique([$a, $b]))) {
            throw new HttpError(404, 'Person nicht gefunden');
        }

        $relations = (new Kinship($this->db, $this->treeId))->between($a, $b);

        $ids = [$a => true, $b => true];
        foreach ($relations as $r) {
            foreach ($r['path'] as $step) $ids[$step['id']] = true;
            foreach ($r['ancestors'] ?? [] as $id) $ids[$id] = true;
        }
        $persons = [];
        foreach ((new PersonQuery($this->db, $this->treeId))->slim(array_keys($ids)) as $p) $persons[$p['id']] = $p;

        $this->json(['a' => $a, 'b' => $b, 'relations' => $relations, 'persons' => (object) $persons]);
    }
}
