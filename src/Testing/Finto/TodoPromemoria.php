<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * I promemoria con anticipo di un to-do nel backoffice finto (#2790, zr-board #2458 T2): `board.todo.elementi.promemoria.elenca`, `crea` e `elimina`,
 * con le regole, i pointer e l'ordine di `TodoElementiPromemoriaController`, e `promemoria_n` in ogni risposta del to-do. Gli anticipi sono 0, 10, 60 e
 * 1440 minuti prima del giorno e dell'ora del to-do; `invio_il` non si tiene, si calcola a ogni risposta dal giorno, dall'ora (le 09:00 se manca) e dal
 * fuso di chi fa il to-do, quindi segue ogni spostamento e vale `null` se il to-do perde il giorno. Lo stesso anticipo due volte è 409
 * `promemoria_gia_presente`; i valori sono quattro, quindi non c'è un `limite_raggiunto`. Il finto non manda le notifiche: il comando che le scrive è del backoffice.
 */
trait TodoPromemoria
{
    /** Gli anticipi che si possono chiedere (`TodoPromemoria::ANTICIPI`). */
    private const PROMEMORIA_ANTICIPI = [0, 10, 60, 1440];

    /** L'ora di un to-do che ha un giorno e non un'ora (`PromemoriaDelleTodo::ORA_PREDEFINITA`). */
    private const PROMEMORIA_ORA = '09:00';

    /** @var array<string, array<string, mixed>> i promemoria vivi, per id (quelli tolti escono dall'API e dal finto) */
    private array $todoPromemoria = [];

    /**
     * board.todo.elementi.promemoria.elenca (TodoElementiPromemoriaController::elenca): i promemoria del to-do, per id (l'ordine di nascita), a cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaPromemoriaTodo(Request $richiesta, string $elemento): array
    {
        $chi = $this->conPm($richiesta);
        $mio = $this->elementoDelPercorsoTodo($chi, $elemento);
        $voci = array_map(fn (array $promemoria) => $this->vocePromemoriaTodo($promemoria, $mio), $this->promemoriaDelTodo($mio['id']));

        return $this->pagina($richiesta, 'board.todo.elementi.promemoria.elenca', 'id', $voci, fn (array $voce) => [$voce['id']], fn (string $id) => [$id]);
    }

    /**
     * board.todo.elementi.promemoria.crea (TodoElementiPromemoriaController::crea): 201 con `{id, anticipo, invio_il}` e la `Location`. Il freno per
     * persona (429) conta prima del lavoro; poi il to-do (404, le sue liste bloccate), il corpo (422), il to-do senza giorno (422 su `#/anticipo`) e
     * l'anticipo già vivo (409 `promemoria_gia_presente`).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaPromemoriaTodo(Request $richiesta, array $corpo, string $elemento): array
    {
        $chi = $this->conPm($richiesta);

        return $this->conIdempotenza($richiesta, 'board.todo.elementi.promemoria.crea', $chi, ['corpo' => $corpo, 'percorso' => ['elemento' => $elemento]], function () use ($chi, $corpo, $elemento) {
            $this->frena('todo.promemoria.crea:persona:'.$chi['persona'].':'.$chi['workspace'], self::FRENI['board_promemoria_persona'], self::MINUTO);

            $mio = $this->elementoDelPercorsoTodo($chi, $elemento);
            $this->scrivibileTodo($this->todoListe[$mio['lista']]);

            $campi = $this->testi->validaStretta($corpo, [
                'anticipo' => ['bail', 'required', 'integer', Rule::in(self::PROMEMORIA_ANTICIPI)],
            ]);
            $anticipo = (int) $campi['anticipo'];

            if ($mio['giorno'] === null) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.todo_promemoria_senza_giorno'), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => '#/anticipo']]);
            }

            if (array_filter($this->promemoriaDelTodo($mio['id']), fn (array $promemoria) => $promemoria['anticipo'] === $anticipo) !== []) {
                throw new Problema('promemoria_gia_presente');
            }

            $id = self::id();
            $this->todoPromemoria[$id] = ['id' => $id, 'workspace' => $chi['workspace'], 'elemento' => $mio['id'], 'anticipo' => $anticipo, 'creato_da' => $chi['persona']];
            $this->toccaIlTodoPromemoria($mio['id']);

            return [201, ['data' => $this->vocePromemoriaTodo($this->todoPromemoria[$id], $mio)], ['Location' => "/v1/board/todo/elementi/{$mio['id']}/promemoria/{$id}"]];
        });
    }

    /**
     * board.todo.elementi.promemoria.elimina (TodoElementiPromemoriaController::elimina): toglie il promemoria, 204; da lì non è più in lista e non si
     * elimina di nuovo (404), e lo stesso anticipo si può rimettere. Un promemoria che non è del to-do del percorso è 404.
     *
     * @return array{int, null}
     */
    private function eliminaPromemoriaTodo(Request $richiesta, string $elemento, string $promemoria): array
    {
        $chi = $this->conPm($richiesta);
        $mio = $this->elementoDelPercorsoTodo($chi, $elemento);
        $this->scrivibileTodo($this->todoListe[$mio['lista']]);
        $voce = $this->todoPromemoria[$promemoria] ?? null;

        if ($voce === null || $voce['elemento'] !== $mio['id']) {
            throw new Problema('non_trovato');
        }

        unset($this->todoPromemoria[$promemoria]);
        $this->toccaIlTodoPromemoria($mio['id']);

        return [204, null];
    }

    /**
     * I promemoria vivi di un to-do, nell'ordine di nascita (l'id).
     *
     * @return list<array<string, mixed>>
     */
    private function promemoriaDelTodo(string $elemento): array
    {
        $vivi = array_filter($this->todoPromemoria, fn (array $promemoria) => $promemoria['elemento'] === $elemento);
        ksort($vivi);

        return array_values($vivi);
    }

    /** Quanti promemoria vivi ha il to-do (`promemoria_n`). */
    private function quantiPromemoriaTodo(string $elemento): int
    {
        return count($this->promemoriaDelTodo($elemento));
    }

    /**
     * Gli anticipi dei promemoria vivi del to-do, in minuti e in ordine crescente (`promemoria_anticipi` nelle righe dell'elenco, #3336).
     *
     * @return list<int>
     */
    private function anticipiPromemoriaTodo(string $elemento): array
    {
        $anticipi = array_map(fn (array $promemoria) => (int) $promemoria['anticipo'], $this->promemoriaDelTodo($elemento));
        sort($anticipi);

        return $anticipi;
    }

    /** Come ogni scrittura di un promemoria nel backoffice, cambia `aggiornato_il` del to-do (e il suo ETag). */
    private function toccaIlTodoPromemoria(string $elemento): void
    {
        $this->todoElementi[$elemento]['aggiornato_il'] = $this->dopoTodo($this->todoElementi[$elemento]['aggiornato_il']);
    }

    /**
     * Un promemoria come lo dà il backoffice (Forme::todoPromemoria): `invio_il` dal giorno, dall'ora e dal fuso di chi fa il to-do, in UTC.
     *
     * @param  array<string, mixed>  $promemoria
     * @param  array<string, mixed>  $elemento
     * @return array<string, mixed>
     */
    private function vocePromemoriaTodo(array $promemoria, array $elemento): array
    {
        $invio = null;

        if ($elemento['giorno'] !== null) {
            $fuso = $this->persone[$elemento['assegnato'] ?? $elemento['persona']]['fuso_orario'] ?? 'Europe/Rome';
            $testo = $elemento['giorno'].' '.($elemento['ora'] ?? self::PROMEMORIA_ORA).':00';

            try {
                $il = CarbonImmutable::parse($testo, $fuso)->utc();
            } catch (Throwable) {
                $il = CarbonImmutable::parse($testo, 'UTC');
            }

            $invio = self::iso($il->subMinutes($promemoria['anticipo']));
        }

        return ['id' => $promemoria['id'], 'anticipo' => $promemoria['anticipo'], 'invio_il' => $invio];
    }
}
