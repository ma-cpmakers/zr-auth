<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Gli sprint di una board Scrum nel backoffice finto (#2664, tappa 4c dell'Area di zr-board): `board.sprint.elenca`, `crea`, `modifica`,
 * `avvio.crea` e `chiusura.crea`, con le regole, i pointer e l'ordine di `SprintController`. Sono metodi dell'app `pm`, dei tre ruoli.
 * Una board che non è Scrum (un'altra, o senza metodo) risponde 409 `board_senza_metodo` a ogni metodo; una chiusa o nel cestino, ai metodi che
 * scrivono, 409 `board_chiusa`. Una board ha al più uno sprint attivo e uno pianificato; uno sprint non si elimina, si chiude. La chiusura
 * nomina ogni scheda aperta dello sprint con la sua destinazione (`backlog` o `sprint_dopo`), registra la velocità e sposta le schede in un
 * colpo solo. Lo sprint di una scheda (`sprint_id`, `nello_sprint_dal`) lo dà scheda(), e lo cambia solo la chiusura: il finto non ha
 * board.schede.modifica. Il finto non tiene il registro dei movimenti e non emette gli eventi.
 */
trait Sprint
{
    private const SPRINT_NOME = 100;

    private const SPRINT_OBIETTIVO = 1000;

    /** Le schede aperte che una chiusura nomina, al più. */
    private const SPRINT_SCHEDE_DELLA_CHIUSURA = 1000;

    private const SPRINT_STATI = ['pianificato', 'attivo', 'chiuso'];

    /** @var array<string, array<string, mixed>> gli sprint per id */
    private array $sprint = [];

    /** @var array<string, array{unita: string, giorni: list<array<string, mixed>>}> le serie seminate dal test, per id dello sprint */
    private array $andamenti = [];

    /**
     * Uno sprint per i test: nasce `pianificato`, `attivo` (avviato il giorno dell'inizio) o `chiuso` (alla fine della sua durata) (con `$velocita` e la sua unità, `punti` o `schede`). La board è di
     * board() e va con il metodo `scrum`; come nel backoffice una board ha al più uno sprint attivo e uno pianificato.
     *
     * @param  array<string, mixed>  $workspace  un workspace di workspace()
     * @param  array<string, mixed>  $board  una board di board(), con il metodo `scrum`
     * @return array{id: string, board_id: string, nome: string, stato: string}
     */
    public function sprint(array $workspace, array $board, string $nome, string $stato = 'pianificato', ?string $inizio = null, int $durataSettimane = 2, ?string $obiettivo = null, int $velocita = 0, string $unita = 'schede'): array
    {
        $idWorkspace = $this->workspaceDelFinto($workspace);
        $idBoard = $board['id'] ?? null;

        if (! is_string($idBoard) || ($this->boards[$idBoard]['workspace'] ?? null) !== $idWorkspace) {
            throw new InvalidArgumentException('La board non è di questo workspace: nasce con board().');
        }

        if (($this->boards[$idBoard]['metodo'] ?? null) !== 'scrum') {
            throw new InvalidArgumentException('Gli sprint sono di una board Scrum: la board nasce con metodo «scrum».');
        }

        if (! in_array($stato, self::SPRINT_STATI, true)) {
            throw new InvalidArgumentException("Lo stato «{$stato}» non c'è: sono pianificato, attivo e chiuso.");
        }

        if ($stato !== 'chiuso' && $this->sprintDellaBoardInStato($idBoard, $stato) !== null) {
            throw new InvalidArgumentException("La board ha già uno sprint {$stato}: ne ha al più uno.");
        }

        $ora = now()->toImmutable()->startOfMillisecond();
        $inizio ??= $ora->toDateString();
        $id = self::id();
        // Uno sprint avviato è partito il giorno dell'inizio, e uno chiuso si è chiuso alla fine della sua durata: gli istanti non dipendono da quando il test li crea.
        $partenza = CarbonImmutable::parse($inizio);
        $this->sprint[$id] = [
            'id' => $id, 'workspace' => $idWorkspace, 'board' => $idBoard, 'nome' => $nome, 'obiettivo' => $obiettivo, 'stato' => $stato,
            'inizio' => $inizio, 'durata_settimane' => $durataSettimane,
            'avviato_il' => $stato === 'pianificato' ? null : $partenza, 'chiuso_il' => $stato === 'chiuso' ? $partenza->addWeeks($durataSettimane) : null, 'obiettivo_raggiunto' => null,
            'velocita' => $stato === 'chiuso' ? $velocita : null, 'velocita_unita' => $stato === 'chiuso' ? $unita : null,
            'creata_il' => $ora, 'aggiornata_il' => $ora,
        ];

        return ['id' => $id, 'board_id' => $idBoard, 'nome' => $nome, 'stato' => $stato];
    }

    /**
     * Semina la serie di uno sprint, come la darebbe `board.sprint.andamento.mostra` (#2665): il finto non tiene il registro dei movimenti né il
     * flusso delle schede da cui il backoffice la calcola. Ogni giorno ha `data` e i cinque interi cumulativi `pianificato`, `aggiunto`, `rimosso`,
     * `completato` e `rimasto`; `$unita` è `punti` o `schede`. Senza semina la serie è vuota, in `schede`.
     *
     * @param  array{id: string}  $sprint  uno sprint di sprint()
     * @param  list<array{data: string, pianificato: int, aggiunto: int, rimosso: int, completato: int, rimasto: int}>  $giorni
     */
    public function andamento(array $sprint, array $giorni, string $unita = 'punti'): self
    {
        if (! isset($this->sprint[$sprint['id']])) {
            throw new InvalidArgumentException('Lo sprint non è del finto: nasce con sprint().');
        }

        if (! in_array($unita, ['punti', 'schede'], true)) {
            throw new InvalidArgumentException("L'unità «{$unita}» non c'è: sono punti e schede.");
        }

        foreach ($giorni as $giorno) {
            if (array_keys($giorno) !== ['data', 'pianificato', 'aggiunto', 'rimosso', 'completato', 'rimasto']) {
                throw new InvalidArgumentException('Un giorno ha data, pianificato, aggiunto, rimosso, completato e rimasto, in quest\'ordine.');
            }
        }

        $this->andamenti[$sprint['id']] = ['unita' => $unita, 'giorni' => $giorni];

        return $this;
    }

    /**
     * board.sprint.andamento.mostra (SprintAndamentoController::mostra): la serie seminata di uno sprint, o vuota. Uno sprint di un altro workspace,
     * o che non c'è, è 404; una board che non è Scrum 409 `board_senza_metodo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraAndamentoSprint(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $sprint = $this->sprint[$id] ?? null;

        if ($sprint === null || $sprint['workspace'] !== $chi['workspace']) {
            throw new Problema('non_trovato');
        }

        $this->sprintScrum($this->boards[$sprint['board']]);
        $voce = $this->andamenti[$id] ?? ['unita' => 'schede', 'giorni' => []];
        $fine = CarbonImmutable::parse($sprint['inizio'])->addDays($sprint['durata_settimane'] * 7 - 1)->toDateString();

        return [200, ['data' => ['sprint_id' => $id, 'unita' => $voce['unita'], 'inizio' => $sprint['inizio'], 'fine' => $fine, 'giorni' => $voce['giorni']]]];
    }

    /**
     * board.sprint.elenca (SprintController::elenca): gli sprint della board data (`board_id`, obbligatorio), l'attivo, il pianificato, poi i
     * chiusi dal più recente, a cursore; `stato` ne tiene uno solo. `meta` porta la velocità media dei soli sprint chiusi con l'unità
     * dell'ultimo chiuso, e quell'unità, o `null` se nessuno è chiuso. Un `board_id` che non è una board del workspace è 422 sul parametro
     * (come tutti i parametri, prima del metodo della board: 409 `board_senza_metodo`).
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaSprint(Request $richiesta): array
    {
        $chi = $this->conPm($richiesta);
        $grezzo = self::query($richiesta);
        $board = is_string($grezzo['board_id'] ?? null) ? $grezzo['board_id'] : '';
        $ordinati = [];

        [$stato, $pagina] = $this->pagina(
            $richiesta, 'board.sprint.elenca:'.$board, 'id',
            function () use ($board, $grezzo, &$ordinati) {
                $this->sprintScrum($this->boards[$board]);
                $ordinati = array_values(array_map($this->voceSprint(...), $this->sprintOrdinati($board)));

                return array_values(array_filter($ordinati, fn (array $voce) => ! isset($grezzo['stato']) || $voce['stato'] === $grezzo['stato']));
            },
            function (array $voce) use (&$ordinati) {
                return [sprintf('%06d', array_search($voce['id'], array_column($ordinati, 'id'), true))];
            },
            function (string $id) use (&$ordinati) {
                $posto = array_search($id, array_column($ordinati, 'id'), true);

                return $posto === false ? null : [sprintf('%06d', $posto)];
            },
            regole: [
                'board_id' => ['bail', 'required', 'string', function (string $attributo, mixed $valore, Closure $fail) use ($chi) {
                    if (($this->boards[$valore]['workspace'] ?? null) !== $chi['workspace']) {
                        $this->testi->dichiara($attributo, 'non_trovato');
                        $fail('regole.board_del_workspace')->translate();
                    }
                }],
                'stato' => ['sometimes', 'string', Rule::in(self::SPRINT_STATI)],
            ],
        );

        $pagina['meta'] = $this->velocitaMediaSprint($board);

        return [$stato, $pagina];
    }

    /**
     * board.sprint.crea (SprintController::crea): 201 con lo sprint `pianificato`, senza `Location` (uno sprint non ha una lettura sua).
     * Nell'ordine del backoffice: la Idempotency-Key, il corpo (422), la board che non è del workspace (422 su `#/board_id`), la board
     * chiusa (409 `board_chiusa`), non Scrum (409 `board_senza_metodo`), e uno sprint pianificato già c'è (409 `limite_raggiunto`).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaSprint(Request $richiesta, array $corpo): array
    {
        $chi = $this->conPm($richiesta);

        return $this->conIdempotenza($richiesta, 'board.sprint.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'board_id' => ['bail', 'required', 'string'],
                'nome' => ['bail', 'required', 'string', 'max:'.self::SPRINT_NOME, new NomeScritto],
                'obiettivo' => ['nullable', 'string', 'max:'.self::SPRINT_OBIETTIVO],
                'inizio' => ['bail', 'required', 'date_format:Y-m-d'],
                'durata_settimane' => ['bail', 'required', 'integer:strict', 'between:1,4'],
            ]);
            $board = $this->boards[$campi['board_id']] ?? null;

            if ($board === null || $board['workspace'] !== $chi['workspace']) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.board_del_workspace'), 'regola' => Regola::NON_TROVATO, 'pointer' => '#/board_id']]);
            }

            $this->sprintScrum($this->sprintBoardAperta($board));

            if ($this->sprintDellaBoardInStato($board['id'], 'pianificato') !== null) {
                throw new Problema('limite_raggiunto');
            }

            $ora = now()->toImmutable()->startOfMillisecond();
            $id = self::id();
            $this->sprint[$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'board' => $board['id'], 'nome' => trim((string) $campi['nome']), 'obiettivo' => $campi['obiettivo'] ?? null,
                'stato' => 'pianificato', 'inizio' => $campi['inizio'], 'durata_settimane' => $campi['durata_settimane'],
                'avviato_il' => null, 'chiuso_il' => null, 'obiettivo_raggiunto' => null, 'velocita' => null, 'velocita_unita' => null,
                'creata_il' => $ora, 'aggiornata_il' => $ora,
            ];

            return [201, ['data' => $this->voceSprint($this->sprint[$id])], []];
        });
    }

    /**
     * board.sprint.modifica (SprintController::modifica): JSON Merge Patch di `nome`, `obiettivo` (`null` lo toglie), `inizio` e
     * `durata_settimane`. Il corpo si guarda prima dello sprint (404), poi la board (409 `board_chiusa`, `board_senza_metodo`). Uno sprint chiuso
     * è 409 `sprint_chiuso`; su uno attivo un `inizio` o una `durata_settimane` diversi da quelli che ha sono 409 `sprint_attivo`. Un valore che
     * ha già non scrive niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function modificaSprint(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $regole = [
            'nome' => ['bail', 'filled', 'string', 'max:'.self::SPRINT_NOME, new NomeScritto],
            'obiettivo' => ['nullable', 'string', 'max:'.self::SPRINT_OBIETTIVO],
            'inizio' => ['bail', 'filled', 'date_format:Y-m-d'],
            'durata_settimane' => ['bail', 'filled', 'integer:strict', 'between:1,4'],
        ];
        $campi = $this->testi->validaCorpo($corpo, $regole);

        if ($campi === []) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => implode(', ', array_keys($regole))]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#']]);
        }

        $corrente = $this->sprintDelPercorso($chi['workspace'], $id);

        if ($corrente['stato'] === 'chiuso') {
            throw new Problema('sprint_chiuso');
        }

        if (isset($campi['nome'])) {
            $campi['nome'] = trim((string) $campi['nome']);
        }

        $cambi = array_filter($campi, fn (mixed $valore, string $campo) => $valore !== $corrente[$campo], ARRAY_FILTER_USE_BOTH);

        if ($corrente['stato'] === 'attivo' && array_intersect_key($cambi, ['inizio' => true, 'durata_settimane' => true]) !== []) {
            throw new Problema('sprint_attivo');
        }

        if ($cambi !== []) {
            $this->sprint[$id] = $cambi + ['aggiornata_il' => now()->toImmutable()->startOfMillisecond()] + $corrente;
        }

        return [200, ['data' => $this->voceSprint($this->sprint[$id])]];
    }

    /**
     * board.sprint.avvio.crea (SprintController::avvioCrea): avvia lo sprint pianificato, 201 con lo sprint `attivo`. Uno che non è pianificato è
     * 409 `sprint_non_pianificato`; se la board ha già uno sprint attivo, 409 `sprint_attivo`. Senza corpo.
     *
     * @return array{int, array<string, mixed>}
     */
    private function avviaSprint(Request $richiesta, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $corrente = $this->sprintDelPercorso($chi['workspace'], $id);

        if ($corrente['stato'] !== 'pianificato') {
            throw new Problema('sprint_non_pianificato');
        }

        if ($this->sprintDellaBoardInStato($corrente['board'], 'attivo') !== null) {
            throw new Problema('sprint_attivo');
        }

        $ora = now()->toImmutable()->startOfMillisecond();
        $this->sprint[$id] = ['stato' => 'attivo', 'avviato_il' => $ora, 'aggiornata_il' => $ora] + $corrente;

        return [201, ['data' => $this->voceSprint($this->sprint[$id])]];
    }

    /**
     * board.sprint.chiusura.crea (SprintController::chiusuraCrea): chiude lo sprint attivo, 201 con lo sprint `chiuso`. Il corpo (422) prima
     * dello sprint (404) e della board. Nomina ogni scheda aperta dello sprint (non completata né archiviata) con la sua destinazione:
     * `backlog` esce dallo sprint, `sprint_dopo` entra in quello pianificato. Una scheda aperta che il corpo non nomina è 422 su `#/schede`; una
     * che non è aperta nello sprint o è nominata due volte, 422 su `#/schede/N/id`; `sprint_dopo` senza uno sprint pianificato, 422 su
     * `#/schede/N/destinazione`. La velocità è la somma dei punti delle completate se almeno una li ha (unità `punti`), altrimenti il loro
     * numero (`schede`); le completate restano nello sprint chiuso.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function chiudiSprint(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conPm($richiesta);
        $campi = $this->testi->validaCorpo($corpo, [
            'schede' => ['present', 'array', 'list', 'max:'.self::SPRINT_SCHEDE_DELLA_CHIUSURA],
            'schede.*.id' => ['required', 'string'],
            'schede.*.destinazione' => ['required', 'string', 'in:backlog,sprint_dopo'],
            'obiettivo_raggiunto' => ['nullable', 'boolean:strict'],
        ]);
        $corrente = $this->sprintDelPercorso($chi['workspace'], $id);

        if ($corrente['stato'] !== 'attivo') {
            throw new Problema('sprint_non_attivo');
        }

        $dopo = $this->sprintDellaBoardInStato($corrente['board'], 'pianificato');
        $dellaSprint = array_filter($this->schede, fn (array $scheda) => ($scheda['sprint_id'] ?? null) === $id && $scheda['archiviata_il'] === null);
        $aperte = array_filter($dellaSprint, fn (array $scheda) => $scheda['completata_il'] === null);
        $completate = array_filter($dellaSprint, fn (array $scheda) => $scheda['completata_il'] !== null);
        $destinazioni = [];

        foreach ($campi['schede'] as $posto => $voce) {
            if (! isset($aperte[$voce['id']]) || isset($destinazioni[$voce['id']])) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.chiusura_scheda_non_aperta'), 'regola' => Regola::NON_TROVATO, 'pointer' => "#/schede/{$posto}/id"]]);
            }

            if ($voce['destinazione'] === 'sprint_dopo' && $dopo === null) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.chiusura_senza_sprint_dopo'), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => "#/schede/{$posto}/destinazione"]]);
            }

            $destinazioni[$voce['id']] = $voce['destinazione'];
        }

        if (count($destinazioni) !== count($aperte)) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.chiusura_scheda_mancante'), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#/schede']]);
        }

        $ora = now()->toImmutable()->startOfMillisecond();

        foreach ($destinazioni as $scheda => $destinazione) {
            $verso = $destinazione === 'sprint_dopo' && $dopo !== null ? $dopo['id'] : null;
            $this->schede[$scheda]['sprint_id'] = $verso;
            $this->schede[$scheda]['nello_sprint_dal'] = $verso === null ? null : $ora;
        }

        if ($destinazioni !== []) {
            $this->tocca(...array_keys($destinazioni));
        }

        $conPunti = array_filter($completate, fn (array $scheda) => ($scheda['punti'] ?? null) !== null) !== [];
        $this->sprint[$id] = [
            'stato' => 'chiuso', 'chiuso_il' => $ora, 'aggiornata_il' => $ora,
            'velocita' => $conPunti ? array_sum(array_map(fn (array $scheda) => (int) ($scheda['punti'] ?? 0), $completate)) : count($completate),
            'velocita_unita' => $conPunti ? 'punti' : 'schede',
            'obiettivo_raggiunto' => $campi['obiettivo_raggiunto'] ?? null,
        ] + $corrente;

        return [201, ['data' => $this->voceSprint($this->sprint[$id])]];
    }

    /**
     * Lo sprint del percorso, nel workspace del gettone (uno di un altro workspace, o che non c'è, è 404), sulla sua board aperta e Scrum: la
     * board chiusa è 409 `board_chiusa`, la non Scrum 409 `board_senza_metodo` (SprintController::bloccati).
     *
     * @return array<string, mixed>
     */
    private function sprintDelPercorso(string $workspace, string $id): array
    {
        $sprint = $this->sprint[$id] ?? null;

        if ($sprint === null || $sprint['workspace'] !== $workspace) {
            throw new Problema('non_trovato');
        }

        $this->sprintScrum($this->sprintBoardAperta($this->boards[$sprint['board']]));

        return $sprint;
    }

    /**
     * La board di uno sprint che si scrive: 409 `board_chiusa` se è chiusa o nel cestino (BoardAperta::controlla).
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function sprintBoardAperta(array $board): array
    {
        if (($board['stato'] ?? 'attiva') !== 'attiva') {
            throw new Problema('board_chiusa');
        }

        return $board;
    }

    /**
     * La board, se è Scrum: altrimenti 409 `board_senza_metodo`.
     *
     * @param  array<string, mixed>  $board
     * @return array<string, mixed>
     */
    private function sprintScrum(array $board): array
    {
        if (($board['metodo'] ?? null) !== 'scrum') {
            throw new Problema('board_senza_metodo');
        }

        return $board;
    }

    /**
     * Lo sprint pianificato o attivo di una board, o null (ne ha al più uno per stato).
     *
     * @return array<string, mixed>|null
     */
    private function sprintDellaBoardInStato(string $board, string $stato): ?array
    {
        foreach ($this->sprint as $sprint) {
            if ($sprint['board'] === $board && $sprint['stato'] === $stato) {
                return $sprint;
            }
        }

        return null;
    }

    /**
     * Gli sprint di una board nell'ordine dell'elenco: l'attivo, il pianificato, poi i chiusi dal più recente (`chiuso_il`, poi l'id).
     *
     * @return list<array<string, mixed>>
     */
    private function sprintOrdinati(string $board): array
    {
        $suoi = array_values(array_filter($this->sprint, fn (array $sprint) => $sprint['board'] === $board));
        // L'ordine di SprintController::ORDINE: l'attivo, il pianificato, i chiusi (non quello di SPRINT_STATI).
        $gradino = fn (array $sprint): int => ['attivo' => 0, 'pianificato' => 1][$sprint['stato']] ?? 2;
        $quando = fn (array $sprint): int => $sprint['chiuso_il'] === null ? 0 : (int) $sprint['chiuso_il']->format('Uv');
        usort($suoi, fn (array $uno, array $altro) => [$gradino($uno), $quando($altro), $altro['id']] <=> [$gradino($altro), $quando($uno), $uno['id']]);

        return $suoi;
    }

    /**
     * Il `meta` dell'elenco (SprintController::velocitaMedia): la media dei soli sprint chiusi con l'unità dell'ultimo chiuso, arrotondata
     * all'intero, e quell'unità; `null` e `null` se nessuno è chiuso.
     *
     * @return array{velocita_media: int|null, velocita_media_unita: string|null}
     */
    private function velocitaMediaSprint(string $board): array
    {
        $chiusi = array_values(array_filter($this->sprintOrdinati($board), fn (array $sprint) => $sprint['stato'] === 'chiuso'));

        if ($chiusi === []) {
            return ['velocita_media' => null, 'velocita_media_unita' => null];
        }

        $unita = $chiusi[0]['velocita_unita'];
        $stesse = array_filter($chiusi, fn (array $sprint) => $sprint['velocita_unita'] === $unita);

        return ['velocita_media' => (int) round(array_sum(array_column($stesse, 'velocita')) / count($stesse)), 'velocita_media_unita' => $unita];
    }

    /**
     * Uno sprint come lo dà il backoffice (Forme::sprint): `inizio` e `fine` sono giorni `AAAA-MM-GG` (la fine è l'ultimo giorno: l'inizio più
     * `durata_settimane` settimane, meno un giorno), `avviato_il` e `chiuso_il` istanti ISO 8601 in UTC.
     *
     * @param  array<string, mixed>  $sprint
     * @return array<string, mixed>
     */
    private function voceSprint(array $sprint): array
    {
        return [
            'id' => $sprint['id'],
            'board_id' => $sprint['board'],
            'nome' => $sprint['nome'],
            'obiettivo' => $sprint['obiettivo'],
            'stato' => $sprint['stato'],
            'inizio' => $sprint['inizio'],
            'fine' => CarbonImmutable::parse($sprint['inizio'])->addDays($sprint['durata_settimane'] * 7 - 1)->toDateString(),
            'durata_settimane' => $sprint['durata_settimane'],
            'avviato_il' => $sprint['avviato_il'] === null ? null : self::iso($sprint['avviato_il']),
            'chiuso_il' => $sprint['chiuso_il'] === null ? null : self::iso($sprint['chiuso_il']),
            'obiettivo_raggiunto' => $sprint['obiettivo_raggiunto'],
            'velocita' => $sprint['velocita'],
            'velocita_unita' => $sprint['velocita_unita'],
            'creata_il' => self::iso($sprint['creata_il']),
            'aggiornata_il' => self::iso($sprint['aggiornata_il']),
        ];
    }
}
