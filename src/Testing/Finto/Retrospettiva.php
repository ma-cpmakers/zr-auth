<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;

/**
 * La retrospettiva di uno sprint nel backoffice finto (#2667, tappa 4f dell'Area di zr-board): `board.sprint.retrospettiva.crea` e i voti alle schede,
 * `board.schede.voti.elenca`, `.crea` e `.elimina`. Sono metodi dell'app `pm`, dei tre ruoli. La retrospettiva fa nascere una board senza metodo nella cartella della
 * board dello sprint, con le tre liste nella lingua della richiesta (la terza col ruolo `azioni`), e lega lo sprint (`retrospettiva_board_id`); la risposta è la board
 * intera, con `retrospettiva_di`. Il finto non ha board.board.mostra né board.liste.*: le schede della retrospettiva le semina il test con scheda(…, $board), e
 * `dalla_scheda_id` con scheda(…, dallaScheda:). Come nel backoffice: 404 per uno sprint di un altro workspace, 409 `board_chiusa`, `sprint_non_chiuso`,
 * `retrospettiva_esistente`; i voti sono uno per persona e scheda (409 `voto_gia_dato`), solo su una scheda di una retrospettiva (409 `scheda_non_di_retrospettiva`),
 * e ogni voto cambia l'`aggiornata_il` della scheda. Il finto non applica i tetti delle board (`limite_raggiunto`).
 */
trait Retrospettiva
{
    /** @var array<string, list<array{utente_id: string, creato_il: \Carbon\CarbonImmutable}>> i voti di ogni scheda, in ordine di voto; il voto tolto esce */
    private array $voti = [];

    /**
     * La board è la retrospettiva di uno sprint: l'id dello sprint, o null.
     */
    private function sprintDellaRetrospettiva(string $board): ?string
    {
        foreach ($this->sprint as $sprint) {
            if (($sprint['retrospettiva_board'] ?? null) === $board) {
                return $sprint['id'];
            }
        }

        return null;
    }

    /**
     * Le id delle schede non archiviate nate da questa (`dalla_scheda_id`), nell'ordine di nascita.
     *
     * @return list<string>
     */
    private function trasformataIn(string $scheda): array
    {
        return array_values(array_map(
            fn (array $nata) => $nata['id'],
            array_filter($this->schede, fn (array $nata) => ($nata['dalla_scheda'] ?? null) === $scheda && $nata['archiviata_il'] === null),
        ));
    }

    /**
     * Il corpo di una scrittura senza corpo: un campo è 422 `campo_estraneo` sul campo.
     *
     * @param  array<mixed>  $corpo
     */
    private function senzaCorpo(array $corpo): void
    {
        if ($corpo !== []) {
            throw new Problema('dati_non_validi', array_map(
                fn (string|int $chiave) => ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => (string) $chiave]), 'regola' => Regola::CAMPO_ESTRANEO, 'pointer' => '#/'.$chiave],
                array_keys($corpo),
            ));
        }
    }

    /**
     * board.sprint.retrospettiva.crea (RetrospettivaController::crea): 201 con la board intera della retrospettiva e `Location`. Senza corpo (422), prima di tutto il resto;
     * lo sprint di un altro workspace o che non c'è è 404; la board dello sprint chiusa o nel cestino 409 `board_chiusa`; uno sprint non chiuso 409 `sprint_non_chiuso`;
     * la seconda 409 `retrospettiva_esistente`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaRetrospettiva(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $this->senzaCorpo($corpo);
        $sprint = $this->sprint[$id] ?? null;

        if ($sprint === null || $sprint['workspace'] !== $chi['workspace']) {
            throw new Problema('non_trovato');
        }

        $origine = $this->sprintBoardAperta($this->boards[$sprint['board']]);

        if ($sprint['stato'] !== 'chiuso') {
            throw new Problema('sprint_non_chiuso');
        }

        if (($sprint['retrospettiva_board'] ?? null) !== null) {
            throw new Problema('retrospettiva_esistente');
        }

        $ora = now()->toImmutable()->startOfMillisecond();
        $board = self::id();
        $nome = mb_substr($this->testi->testo('retrospettiva.nome', ['sprint' => $sprint['nome']]), 0, 100);
        $this->boards[$board] = [
            'id' => $board, 'workspace' => $chi['workspace'], 'nome' => $nome, 'cartella' => $origine['cartella'], 'metodo' => null, 'stato' => 'attiva', 'creata_il' => $ora, 'aggiornata_il' => $ora,
            'chiusa_il' => null, 'nel_cestino_il' => null,
        ];
        $this->sprint[$id]['retrospettiva_board'] = $board;
        $this->sprint[$id]['aggiornata_il'] = $ora;

        $liste = array_map(fn (string $chiave, ?string $ruolo) => [
            'id' => self::id(),
            'board_id' => $board,
            'nome' => $this->testi->testo('retrospettiva.'.$chiave),
            'ruolo' => $ruolo,
            'soglia' => 0,
            'limite' => null,
            'blocca' => false,
            'passaggi' => null,
            'requisiti' => ['senza_attese' => false, 'checklist_completa' => false],
            'completa_all_arrivo' => false,
            'numero_schede' => 0,
            'archiviata_il' => null,
            'creata_il' => self::iso($ora),
            'aggiornata_il' => self::iso($ora),
        ], ['bene', 'meglio', 'azioni'], [null, null, 'azioni']);

        return [201, ['data' => [
            'id' => $board,
            'nome' => $nome,
            'cartella_id' => $origine['cartella'],
            'metodo' => null,
            'creata_il' => self::iso($ora),
            'aggiornata_il' => self::iso($ora),
            'numero_schede' => 0,
            'descrizione' => null,
            'sfondo' => 'neutral',
            'stato' => 'attiva',
            'applica_regole' => true,
            'chiusa_il' => null,
            'nel_cestino_il' => null,
            'cancellata_il' => null,
            'tipi' => [],
            'retrospettiva_di' => $id,
            'liste' => $liste,
            'etichette' => [],
            'io' => ['vista' => null],
        ]], ['Location' => "/v1/board/board/{$board}"]];
    }

    /**
     * board.schede.voti.elenca (VotiController::elenca): chi ha votato la scheda, `{utente_id, creato_il}` in ordine di voto. Una scheda che non è di una
     * retrospettiva è 409 `scheda_non_di_retrospettiva`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaVoti(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $scheda = $this->schedaDelPercorso($chi['workspace'], $id);
        $this->schedaDiRetrospettiva($scheda);

        return [200, ['data' => array_map(fn (array $voto) => ['utente_id' => $voto['utente_id'], 'creato_il' => self::iso($voto['creato_il'])], $this->voti[$id] ?? [])]];
    }

    /**
     * board.schede.voti.crea (VotiController::crea): il voto di chi chiama, senza corpo (422), 201 con la scheda intera che porta `voti` aggiornato e un'`aggiornata_il`
     * nuova. 409 `board_chiusa`, `scheda_archiviata`, `scheda_non_di_retrospettiva`, e `voto_gia_dato` al secondo voto della stessa persona.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function votaScheda(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $this->senzaCorpo($corpo);
        $scheda = $this->votabile($chi['workspace'], $id);

        foreach ($this->voti[$id] ?? [] as $voto) {
            if ($voto['utente_id'] === $chi['persona']) {
                throw new Problema('voto_gia_dato');
            }
        }

        $this->voti[$id][] = ['utente_id' => $chi['persona'], 'creato_il' => now()->toImmutable()->startOfMillisecond()];
        $this->tocca($scheda['id']);

        return [201, ['data' => $this->voceScheda($this->schede[$id])]];
    }

    /**
     * board.schede.voti.elimina (VotiController::elimina): toglie il voto di chi chiama, 200 con la scheda intera; chi non l'aveva votata è 404 `non_trovato`.
     * Stessi 409 del voto.
     *
     * @return array{int, array<string, mixed>}
     */
    private function togliVoto(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $this->votabile($chi['workspace'], $id);
        $suoi = array_values(array_filter($this->voti[$id] ?? [], fn (array $voto) => $voto['utente_id'] === $chi['persona']));

        if ($suoi === []) {
            throw new Problema('non_trovato');
        }

        $this->voti[$id] = array_values(array_filter($this->voti[$id], fn (array $voto) => $voto['utente_id'] !== $chi['persona']));
        $this->tocca($id);

        return [200, ['data' => $this->voceScheda($this->schede[$id])]];
    }

    /**
     * La scheda del percorso che si può votare o togliere: 404 se non è del workspace, 409 `board_chiusa`, `scheda_archiviata`, `scheda_non_di_retrospettiva`, in
     * quest'ordine.
     *
     * @return array<string, mixed>
     */
    private function votabile(string $workspace, string $id): array
    {
        $scheda = $this->schedaDelPercorso($workspace, $id);
        $this->sprintBoardAperta($this->boards[$scheda['board']] ?? ['stato' => 'attiva']);

        if ($scheda['archiviata_il'] !== null) {
            throw new Problema('scheda_archiviata');
        }

        $this->schedaDiRetrospettiva($scheda);

        return $scheda;
    }

    /**
     * 409 `scheda_non_di_retrospettiva` se la board della scheda non è la retrospettiva di nessuno sprint.
     *
     * @param  array<string, mixed>  $scheda
     */
    private function schedaDiRetrospettiva(array $scheda): void
    {
        if ($this->sprintDellaRetrospettiva($scheda['board']) === null) {
            throw new Problema('scheda_non_di_retrospettiva');
        }
    }
}
