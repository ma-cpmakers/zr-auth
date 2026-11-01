<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * I collegamenti di un to-do a un record del CRM nel backoffice finto (#2609, zr-board #2459): `board.todo.elementi.crm.elenca`, `crea` e
 * `elimina`, con le regole, i pointer e l'ordine di `TodoElementiCrmController`, e `crm` nelle risposte del to-do (forma intera in
 * `mostra`, `crea`, `modifica` e `completamento`, forma breve nelle righe di `elenca`). Il «con chi» (fino a 20) e il «dove» (uno solo)
 * di un to-do, con i permessi della modifica: la lista personale la legge e la scrive solo chi l'ha creata, la lista di progetto i
 * membri della board. Come per la scheda (`SchedeCrm`) la board tiene solo il tipo e l'id del record: titolo e contesto si leggono dal
 * CRM del finto a ogni risposta. Un record archiviato, anonimizzato o che chi chiama non legge resta con `titolo` e `contesto` a `null`
 * e il `motivo`; un contatto unito in un altro e non riaperto ha `motivo` `unito` e `unito_in` (l'id di chi resta, decisione di zr-pm).
 * Un to-do che ha un collegamento non risponde 304 a `mostra` (titolo e contesto non entrano nell'`ETag`). Il finto non emette eventi.
 */
trait TodoCrm
{
    /** I tetti per ruolo (`TodoCollegamentoCrm::tetto`). */
    private const TODO_CRM_TETTI = ['con_chi' => 20, 'dove' => 1];

    /** @var array<string, array<string, mixed>> i collegamenti vivi dei to-do, per id (quelli eliminati escono dall'API e dal finto) */
    private array $todoCrm = [];

    /**
     * board.todo.elementi.crm.elenca (TodoElementiCrmController::elenca): i collegamenti del to-do, per id (l'ordine di nascita), a cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaTodoCrm(Request $richiesta, string $elemento): array
    {
        $chi = $this->conPm($richiesta);
        $mio = $this->elementoDelPercorsoTodo($chi, $elemento);
        $voci = array_values(array_map(
            fn (array $collegamento) => $this->voceTodoCrm($collegamento, $chi['workspace'], true),
            $this->collegamentiDelTodoCrm($mio['id']),
        ));

        return $this->pagina($richiesta, 'board.todo.elementi.crm.elenca', 'id', $voci, fn (array $voce) => [$voce['id']], fn (string $id) => [$id]);
    }

    /**
     * board.todo.elementi.crm.crea (TodoElementiCrmController::crea): 201 con il collegamento e la `Location`. Il freno per persona (429)
     * conta prima del lavoro; poi il to-do (404, le sue liste bloccate), il corpo (422), l'app `crm` spenta (403), il record che non c'è nel
     * workspace (422 su `#/id`, un testo solo per l'id di un altro workspace e per uno inventato), il doppione dello stesso ruolo (409
     * `record_gia_collegato`) e il tetto del ruolo (409 `limite_raggiunto` con `limite`: 20 `con_chi`, 1 `dove`).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaTodoCrm(Request $richiesta, array $corpo, string $elemento): array
    {
        $chi = $this->conPm($richiesta);

        return $this->conIdempotenza($richiesta, 'board.todo.elementi.crm.crea', $chi, ['corpo' => $corpo, 'percorso' => ['elemento' => $elemento]], function () use ($chi, $corpo, $elemento) {
            $this->frena('todo.crm.crea:persona:'.$chi['persona'].':'.$chi['workspace'], self::FRENI['board_crm_persona'], self::MINUTO);

            $mio = $this->elementoDelPercorsoTodo($chi, $elemento);
            $this->scrivibileTodo($this->todoListe[$mio['lista']]);

            $campi = $this->testi->validaStretta($corpo, [
                'ruolo' => ['bail', 'required', 'string', Rule::in(array_keys(self::TODO_CRM_TETTI))],
                'tipo' => ['bail', 'required', 'string', Rule::in(array_keys(self::SCHEDE_CRM_TIPI))],
                'id' => ['bail', 'required', 'string', 'ulid'],
            ]);
            $ruolo = (string) $campi['ruolo'];
            $tipo = (string) $campi['tipo'];

            if (! $this->appAttiva($chi['workspace'], 'crm')) {
                throw new Problema('app_non_attiva');
            }

            $record = strtolower((string) $campi['id']);

            if (! $this->esisteCrm(self::SCHEDE_CRM_TIPI[$tipo], $chi['workspace'], $record)) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.record_del_crm'), 'regola' => 'non_trovato', 'pointer' => '#/id']]);
            }

            $vivi = array_filter($this->collegamentiDelTodoCrm($mio['id']), fn (array $collegamento) => $collegamento['ruolo'] === $ruolo);

            if (array_filter($vivi, fn (array $collegamento) => $collegamento['tipo'] === $tipo && $collegamento['record'] === $record) !== []) {
                throw new Problema('record_gia_collegato');
            }

            if (count($vivi) >= self::TODO_CRM_TETTI[$ruolo]) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::TODO_CRM_TETTI[$ruolo]]);
            }

            $id = self::id();
            $this->todoCrm[$id] = ['id' => $id, 'workspace' => $chi['workspace'], 'elemento' => $mio['id'], 'ruolo' => $ruolo, 'tipo' => $tipo, 'record' => $record, 'creato_il' => now()->toImmutable()->startOfMillisecond(), 'creato_da' => $chi['persona']];

            return [201, ['data' => $this->voceTodoCrm($this->todoCrm[$id], $chi['workspace'], true)], ['Location' => "/v1/board/todo/elementi/{$mio['id']}/crm/{$id}"]];
        });
    }

    /**
     * board.todo.elementi.crm.elimina (TodoElementiCrmController::elimina): toglie il collegamento, 204; da lì non è più in lista, non conta
     * per i tetti e non si elimina di nuovo (404), e lo stesso record si può ricollegare. Non legge il CRM. Un collegamento che non è del
     * to-do del percorso è 404.
     *
     * @return array{int, null}
     */
    private function eliminaTodoCrm(Request $richiesta, string $elemento, string $collegamento): array
    {
        $chi = $this->conPm($richiesta);
        $mio = $this->elementoDelPercorsoTodo($chi, $elemento);
        $this->scrivibileTodo($this->todoListe[$mio['lista']]);
        $voce = $this->todoCrm[$collegamento] ?? null;

        if ($voce === null || $voce['elemento'] !== $mio['id']) {
            throw new Problema('non_trovato');
        }

        unset($this->todoCrm[$collegamento]);

        return [204, null];
    }

    /**
     * I collegamenti vivi di un to-do, nell'ordine di nascita (l'id).
     *
     * @return list<array<string, mixed>>
     */
    private function collegamentiDelTodoCrm(string $elemento): array
    {
        $vivi = array_filter($this->todoCrm, fn (array $collegamento) => $collegamento['elemento'] === $elemento);
        ksort($vivi);

        return array_values($vivi);
    }

    /**
     * Il `crm` di un to-do com'è nella risposta (TodoCrm::di): la forma intera nelle risposte di un solo to-do, la breve nelle righe
     * dell'elenco; `[]` se non ne ha.
     *
     * @return list<array<string, mixed>>
     */
    private function crmDelTodo(string $elemento, string $workspace, bool $intero): array
    {
        return array_map(fn (array $collegamento) => $this->voceTodoCrm($collegamento, $workspace, $intero), $this->collegamentiDelTodoCrm($elemento));
    }

    /**
     * Un collegamento come lo dà il backoffice (Forme::todoCollegamentoCrm, o la breve: Forme::todoCollegamentoCrmBreve).
     *
     * @param  array<string, mixed>  $collegamento
     * @return array<string, mixed>
     */
    private function voceTodoCrm(array $collegamento, string $workspace, bool $intero): array
    {
        $lista = self::SCHEDE_CRM_TIPI[$collegamento['tipo']];
        $riga = $this->appAttiva($workspace, 'crm') && $this->esisteCrm($lista, $workspace, $collegamento['record']) ? $this->crm[$lista][$collegamento['record']] : null;
        $unito = $riga !== null && $collegamento['tipo'] === 'crm.persona' ? $this->restaDopoUnioneTodoCrm($collegamento['record']) : null;
        [$titolo, $contesto, $motivo] = $unito !== null ? [null, null, 'unito'] : $this->letturaSchedaCrm($collegamento['tipo'], $riga);

        if (! $intero) {
            return [
                'ruolo' => $collegamento['ruolo'],
                'tipo' => $collegamento['tipo'],
                'record_id' => $collegamento['record'],
                'titolo' => $titolo,
                'motivo' => $motivo,
                'unito_in' => $unito,
            ];
        }

        return [
            'id' => $collegamento['id'],
            'ruolo' => $collegamento['ruolo'],
            'tipo' => $collegamento['tipo'],
            'record_id' => $collegamento['record'],
            'titolo' => $titolo,
            'contesto' => $contesto,
            'motivo' => $motivo,
            'unito_in' => $unito,
            'indirizzo' => '/v1/crm/'.$lista.'/'.$collegamento['record'],
            'creato_il' => self::iso($collegamento['creato_il']),
            'creato_da' => $collegamento['creato_da'],
        ];
    }

    /** L'id di chi resta, se questo contatto si è unito in un altro e l'unione non è stata annullata. */
    private function restaDopoUnioneTodoCrm(string $contatto): ?string
    {
        foreach ($this->crm['unioni'] as $unione) {
            if ($unione['unito_id'] === $contatto && $unione['annullata_il'] === null) {
                return $unione['resta_id'];
            }
        }

        return null;
    }
}
