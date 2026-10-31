<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;

/**
 * I ruoli di una board e le epiche del finto (#2816, board.board.ruoli.*, board.schede.figlie.elenca): il Product Owner e lo Scrum master di una
 * board sono due persone del workspace o nessuna, e una scheda di tipo `epica` raccoglie le schede che la indicano con `epica_id`. Il finto non
 * modella board.schede.crea né .modifica: le epiche e le figlie nascono con `scheda()` (parametro `$epica`), e il conto `figlie` lo calcola da
 * quelle, come il backoffice (vive = non archiviate; `fatte` = completate; `punti` solo in un'epica).
 */
trait BoardRuoliEpiche
{
    /** @var array<string, array{product_owner_id: ?string, scrum_master_id: ?string}> i ruoli di ogni board, per id */
    private array $ruoliDelleBoard = [];

    /**
     * board.board.ruoli.elenca (BoardRuoliController::elenca): i due ruoli della board, ai tre ruoli del workspace.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaRuoliBoard(Request $richiesta, string $board): array
    {
        $chi = $this->conPm($richiesta);
        $board = $this->boardDelPercorso($chi['workspace'], $board);

        return [200, ['data' => $this->formaDeiRuoli($chi['workspace'], $board)]];
    }

    /**
     * board.board.ruoli.modifica (BoardRuoliController::modifica): `product_owner_id` e `scrum_master_id`, l'id di una persona del workspace o `null`
     * che toglie. Un id che non è di una persona del workspace è 422 sul suo campo; `[]` è «nessun campo»; lo stesso valore di adesso non scrive.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaRuoliBoard(Request $richiesta, array $corpo, string $board): array
    {
        $chi = $this->conPm($richiesta);
        $board = $this->boardDelPercorso($chi['workspace'], $board);
        $campi = $this->testi->validaCorpo($corpo, [
            'product_owner_id' => ['nullable', 'string', 'max:26'],
            'scrum_master_id' => ['nullable', 'string', 'max:26'],
        ]);
        $ruoli = $this->ruoliDelleBoard[$board] ?? ['product_owner_id' => null, 'scrum_master_id' => null];
        $errori = [];

        foreach ($campi as $campo => $id) {
            if ($id !== null && $id !== $ruoli[$campo] && ! isset($this->membri[$chi['workspace']][$id])) {
                $errori[] = ['detail' => $this->testi->testo('regole.utente_del_workspace'), 'regola' => Regola::NON_TROVATO, 'pointer' => '#/'.$campo];
            }
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }

        $this->ruoliDelleBoard[$board] = [...$ruoli, ...$campi];

        return [200, ['data' => $this->formaDeiRuoli($chi['workspace'], $board)]];
    }

    /**
     * board.schede.figlie.elenca (SchedeFiglieController::elenca): le figlie vive dell'epica, per id, a cursore, nella forma breve. Una scheda che
     * non è un'epica ha la lista vuota; una scheda di un altro workspace è 404.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaFigliePerScheda(Request $richiesta, string $scheda): array
    {
        $chi = $this->conPm($richiesta);
        $epica = $this->schedaDelPercorso($chi['workspace'], $scheda);
        $voci = array_map(
            fn (array $figlia) => array_diff_key($this->voceScheda($figlia), ['descrizione' => true]),
            $this->figlieVive($epica),
        );

        return $this->pagina($richiesta, 'board.schede.figlie.elenca', 'id', $voci, fn (array $voce) => [$voce['id']], fn (string $id) => [$id]);
    }

    /**
     * Le figlie vive di una scheda, per id: le schede del suo workspace con il suo `epica_id`, non archiviate. Vuote se la scheda non è un'epica.
     *
     * @param  array<string, mixed>  $epica
     * @return list<array<string, mixed>>
     */
    private function figlieVive(array $epica): array
    {
        if (($epica['tipo'] ?? null) !== 'epica') {
            return [];
        }

        $figlie = array_values(array_filter($this->schede, fn (array $scheda) => ($scheda['epica_id'] ?? null) === $epica['id'] && $scheda['archiviata_il'] === null));
        usort($figlie, fn (array $una, array $altra) => $una['id'] <=> $altra['id']);

        return $figlie;
    }

    /**
     * Il campo `figlie` di una scheda: `totale` e `fatte` (completate), più `punti` se è un'epica.
     *
     * @param  array<string, mixed>  $scheda
     * @return array<string, int>
     */
    private function contoDelleFiglie(array $scheda): array
    {
        $conto = ['totale' => 0, 'fatte' => 0];

        if (($scheda['tipo'] ?? null) !== 'epica') {
            return $conto;
        }

        $conto['punti'] = 0;

        foreach ($this->figlieVive($scheda) as $figlia) {
            $conto['totale']++;
            $conto['fatte'] += $figlia['completata_il'] === null ? 0 : 1;
            $conto['punti'] += (int) ($figlia['punti'] ?? 0);
        }

        return $conto;
    }

    /**
     * I due ruoli di una board nella forma del backoffice: `{id, nome}` o `null` (anche per una persona uscita), e `modificabile`.
     *
     * @return array<string, mixed>
     */
    private function formaDeiRuoli(string $workspace, string $board): array
    {
        $ruoli = $this->ruoliDelleBoard[$board] ?? ['product_owner_id' => null, 'scrum_master_id' => null];
        $persona = fn (?string $id) => $id !== null && isset($this->membri[$workspace][$id]) ? ['id' => $id, 'nome' => $this->persone[$id]['nome']] : null;

        return ['product_owner' => $persona($ruoli['product_owner_id']), 'scrum_master' => $persona($ruoli['scrum_master_id']), 'modificabile' => true];
    }
}
