<?php

namespace Zeiras\Auth\Testing\Finto;

/**
 * Il padre di una scheda nel finto (#3299, zr-board #3289): una scheda ne contiene altre della stessa board, a più livelli, e le indica con
 * `padre_id`. Il finto non modella board.schede.crea né .modifica: i figli nascono con `scheda()` (parametro `$padre`), e i conti `figli` e
 * `discendenti` si calcolano da quelli, come il backoffice (vivi = non archiviati; `fatte` = completati; i discendenti sono i figli vivi dei figli vivi).
 */
trait BoardPadri
{
    /**
     * I conti `figli` e `discendenti` di una scheda: `{totale, fatte}` ciascuno, `{0, 0}` se non ne ha.
     *
     * @param  array<string, mixed>  $scheda
     * @return array{figli: array{totale: int, fatte: int}, discendenti: array{totale: int, fatte: int}}
     */
    private function contiDelPadre(array $scheda): array
    {
        $diretti = $this->figliViviDelPadre($scheda['id']);
        $conta = fn (array $schede) => ['totale' => count($schede), 'fatte' => count(array_filter($schede, fn (array $s) => $s['completata_il'] !== null))];
        $tutti = [];
        $daVisitare = $diretti;

        while ($daVisitare !== []) {
            $prossimo = array_shift($daVisitare);

            if (isset($tutti[$prossimo['id']])) {
                continue;
            }

            $tutti[$prossimo['id']] = $prossimo;
            array_push($daVisitare, ...$this->figliViviDelPadre($prossimo['id']));
        }

        return ['figli' => $conta($diretti), 'discendenti' => $conta(array_values($tutti))];
    }

    /**
     * I figli vivi di una scheda: le schede con il suo `padre_id`, non archiviate.
     *
     * @return list<array<string, mixed>>
     */
    private function figliViviDelPadre(string $padre): array
    {
        return array_values(array_filter($this->schede, fn (array $s) => ($s['padre_id'] ?? null) === $padre && $s['archiviata_il'] === null));
    }

    /**
     * Gli id di tutte le schede che indicano questa con `padre_id`, vive o no: sono quelle che perdono il padre quando la scheda parte.
     *
     * @return list<string>
     */
    private function figliDelPadre(string $padre): array
    {
        return array_values(array_map(fn (array $s) => $s['id'], array_filter($this->schede, fn (array $s) => ($s['padre_id'] ?? null) === $padre)));
    }
}
