<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use InvalidArgumentException;

/**
 * La pianificazione e il flusso di una board Scrumban nel backoffice finto (#2666, tappa 4e dell'Area di zr-board): `board.board.pianificazione.crea` e
 * `board.board.flusso.mostra`. Sono metodi dell'app `pm`, dei tre ruoli. Il finto non modella liste né il flusso delle schede da cui il backoffice li calcola, quindi li
 * semina il test: flusso() dà i numeri che la lettura risponde (altrimenti un flusso vuoto sul periodo chiesto), pianificazione() le schede che la prossima
 * pianificazione sposta (altrimenti 409 `nessuna_pianificazione`, e dopo una pianificazione di nuovo). Il resto è come nel backoffice: 404 per una board di un altro
 * workspace, 409 `board_senza_metodo` se non è Scrumban (e `board_chiusa` per la pianificazione sulle chiuse), il periodo del flusso (`da` e `a`, al più 90 giorni,
 * per default gli ultimi 30) e il suo `ETag` (non il 304 di `If-None-Match`: il finto risponde sempre 200). `ruolo`, `soglia` e `orizzonte` delle liste e delle schede non ci sono: il finto non ha board.liste.* né board.schede.modifica.
 */
trait Scrumban
{
    private const FLUSSO_GIORNI_MASSIMI = 90;

    private const FLUSSO_GIORNI_PREDEFINITI = 30;

    /** Cresce quando cambia lo schema della risposta del flusso (FlussoController::FORMA). */
    private const FLUSSO_FORMA = 1;

    /** @var array<string, array<string, mixed>> il flusso seminato dal test, per id della board */
    private array $flussi = [];

    /** @var array<string, list<array{id: string, lista_id: string, posizione: int}>> le schede che la prossima pianificazione sposta, per id della board */
    private array $pianificazioni = [];

    /**
     * Semina il flusso di una board Scrumban, come lo darebbe `board.board.flusso.mostra` (#2666): `lead_time` (`mediana`, `p85`, `schede`), `throughput`
     * (`settimana`, `completate`), `cumulativo` (`liste` e `giorni`) e `senza_storia`. `da` e `a` li dà il periodo chiesto. Vale per ogni periodo.
     *
     * @param  array<string, mixed>  $board  una board di board(), col metodo `scrumban`
     * @param  array{lead_time?: array{mediana: float|int|null, p85: float|int|null, schede: int}, throughput?: list<array{settimana: string, completate: int}>, cumulativo?: array{liste: list<array{id: string, nome: string}>, giorni: list<array<string, mixed>>}, senza_storia?: int}  $flusso
     */
    public function flusso(array $board, array $flusso): self
    {
        $this->boardScrumban($board);

        foreach (array_keys($flusso) as $chiave) {
            if (! in_array($chiave, ['lead_time', 'throughput', 'cumulativo', 'senza_storia'], true)) {
                throw new InvalidArgumentException("«{$chiave}» non è nel flusso: sono lead_time, throughput, cumulativo e senza_storia.");
            }
        }

        if (isset($flusso['lead_time']) && array_keys($flusso['lead_time']) !== ['mediana', 'p85', 'schede']) {
            throw new InvalidArgumentException('Il lead_time ha mediana, p85 e schede, in quest\'ordine.');
        }

        if (isset($flusso['cumulativo']) && array_keys($flusso['cumulativo']) !== ['liste', 'giorni']) {
            throw new InvalidArgumentException('Il cumulativo ha liste e giorni, in quest\'ordine.');
        }

        $this->flussi[$board['id']] = $flusso;

        return $this;
    }

    /**
     * Semina le schede che la prossima `board.board.pianificazione.crea` della board sposta nelle pronte, nell'ordine in cui arrivano: `id`, `lista_id` (le
     * pronte) e `posizione`. Una pianificazione le consuma; senza, o dopo, la risposta è 409 `nessuna_pianificazione`.
     *
     * @param  array<string, mixed>  $board  una board di board(), col metodo `scrumban`
     * @param  list<array{id: string, lista_id: string, posizione: int}>  $schede  almeno una
     */
    public function pianificazione(array $board, array $schede): self
    {
        $this->boardScrumban($board);

        if ($schede === []) {
            throw new InvalidArgumentException('Una pianificazione sposta almeno una scheda: senza, la risposta è 409 nessuna_pianificazione e non serve seminarla.');
        }

        foreach ($schede as $scheda) {
            if (array_keys($scheda) !== ['id', 'lista_id', 'posizione']) {
                throw new InvalidArgumentException('Una scheda pianificata ha id, lista_id e posizione, in quest\'ordine.');
            }
        }

        $this->pianificazioni[$board['id']] = array_values($schede);

        return $this;
    }

    /**
     * Una board di board() con il metodo `scrumban`, o un errore.
     *
     * @param  array<string, mixed>  $board
     */
    private function boardScrumban(array $board): void
    {
        if (! is_string($board['id'] ?? null) || ! isset($this->boards[$board['id']])) {
            throw new InvalidArgumentException('La board non è del finto: nasce con board().');
        }

        if ($this->boards[$board['id']]['metodo'] !== 'scrumban') {
            throw new InvalidArgumentException('La pianificazione e il flusso sono di una board Scrumban: la board nasce con metodo «scrumban».');
        }
    }

    /**
     * La board del percorso nel workspace del gettone: una di un altro workspace, o che non c'è, è 404.
     *
     * @return array<string, mixed>
     */
    private function boardDelPercorso(string $workspace, string $id): array
    {
        $board = $this->boards[$id] ?? null;

        return $board !== null && $board['workspace'] === $workspace ? $board : throw new Problema('non_trovato');
    }

    /**
     * board.board.pianificazione.crea (PianificazioneController::crea): 201 con le schede seminate, `{id, lista_id, posizione}`, senza `Location`. Senza corpo (un
     * campo è 422 `campo_estraneo`); una board chiusa è 409 `board_chiusa`, una che non è Scrumban `board_senza_metodo`, e senza niente da pianificare 409
     * `nessuna_pianificazione`.
     *
     * @param  array<string, mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function pianificaBoard(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $board = $this->boardDelPercorso($chi['workspace'], $id);

        if ($corpo !== []) {
            throw new Problema('dati_non_validi', array_map(
                fn (string|int $chiave) => ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => (string) $chiave]), 'regola' => Regola::CAMPO_ESTRANEO, 'pointer' => '#/'.$chiave],
                array_keys($corpo),
            ));
        }

        $this->sprintBoardAperta($board);

        if ($board['metodo'] !== 'scrumban') {
            throw new Problema('board_senza_metodo');
        }

        $mosse = $this->pianificazioni[$id] ?? [];
        unset($this->pianificazioni[$id]);

        if ($mosse === []) {
            throw new Problema('nessuna_pianificazione');
        }

        return [201, ['data' => $mosse]];
    }

    /**
     * board.board.flusso.mostra (FlussoController::mostra): il flusso seminato per il periodo chiesto (`da`, `a`; gli ultimi 30 giorni per default; al più 90),
     * o il flusso vuoto di quel periodo; con l'ETag della board e del periodo. Una board che non è Scrumban è 409 `board_senza_metodo`; `da` dopo `a`, un periodo
     * di più di 90 giorni o una data che non è `YYYY-MM-DD` sono 422 `dati_non_validi` sul parametro.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function mostraFlusso(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $board = $this->boardDelPercorso($chi['workspace'], $id);
        [$da, $a] = $this->periodoDelFlusso(self::query($richiesta));

        if ($board['metodo'] !== 'scrumban') {
            throw new Problema('board_senza_metodo');
        }

        $seminato = $this->flussi[$id] ?? [];
        $giorni = (int) $da->diffInDays($a, true) + 1;
        $settimane = [];

        for ($lunedi = $da->startOfWeek(); $lunedi->lte($a); $lunedi = $lunedi->addWeek()) {
            $settimane[] = ['settimana' => $lunedi->toDateString(), 'completate' => 0];
        }

        $liste = $seminato['cumulativo']['liste'] ?? [];
        $dati = [
            'da' => $da->toDateString(),
            'a' => $a->toDateString(),
            'lead_time' => $seminato['lead_time'] ?? ['mediana' => null, 'p85' => null, 'schede' => 0],
            'throughput' => $seminato['throughput'] ?? $settimane,
            'cumulativo' => $seminato['cumulativo'] ?? ['liste' => $liste, 'giorni' => array_map(
                fn (int $n) => ['data' => $da->addDays($n)->toDateString(), 'liste' => []],
                range(0, $giorni - 1),
            )],
            'senza_storia' => $seminato['senza_storia'] ?? 0,
        ];
        $etag = '"'.hash('xxh128', implode('|', [$id, self::iso($board['aggiornata_il']), $da->toDateString(), $a->toDateString(), self::FLUSSO_FORMA, json_encode($seminato)])).'"';

        return [200, ['data' => $dati], ['ETag' => $etag]];
    }

    /**
     * Il periodo chiesto: il primo e l'ultimo giorno (UTC, estremi compresi). Per default `a` è oggi e `da` 29 giorni prima.
     *
     * @param  array<string, mixed>  $query
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function periodoDelFlusso(array $query): array
    {
        $giorno = function (string $parametro) use ($query): ?CarbonImmutable {
            if (! array_key_exists($parametro, $query)) {
                return null;
            }

            $valore = $query[$parametro];
            $data = is_string($valore) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $valore) === 1 ? CarbonImmutable::createFromFormat('!Y-m-d', $valore, 'UTC') : false;

            if ($data === false || $data->toDateString() !== $valore) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.date_format', ['attribute' => $parametro, 'format' => 'Y-m-d']), 'regola' => Regola::FORMATO, 'parameter' => $parametro]]);
            }

            return $data;
        };
        $a = $giorno('a');
        $da = $giorno('da');
        $fine = $a ?? CarbonImmutable::now('UTC')->startOfDay();

        if ($da !== null && ($da->gt($fine) || (int) $da->diffInDays($fine, true) >= self::FLUSSO_GIORNI_MASSIMI)) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.periodo_del_flusso'), 'regola' => Regola::FUORI_INTERVALLO, 'parameter' => 'da']]);
        }

        return [$da ?? $fine->subDays(self::FLUSSO_GIORNI_PREDEFINITI - 1), $fine];
    }
}
