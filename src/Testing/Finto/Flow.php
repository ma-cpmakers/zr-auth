<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Finanze (app `flow`, tappa 1, gruppo 1, #3455-#3472) nel backoffice finto: `flow.conti.*`, `flow.conti.importazioni.*`, `flow.categorie.elenca`, `flow.movimenti.*`, `flow.regole.*`, `flow.fisse.*` (#3475-#3477) e `flow.bilancio.*` (#3478-#3479), con le
 * regole, i pointer e l'ordine di `ContiController`, `ImportazioniController`, `MovimentiController`, `RegoleController` e `FisseController` del backoffice. Metodi dell'app `flow`: 403 `app_non_attiva` se spenta, prima di
 * cercare la risorsa; tutti e tre i ruoli, filtrati dalla visibilità del conto. Un conto `comune` lo vede ogni membro, uno `personale` solo il suo titolare, e per ogni altro è 404 `non_trovato`; lo
 * cambia e lo elimina solo il titolare (403 `permesso_negato` per un conto comune di un altro). Un movimento è visibile quanto il suo conto. Una regola `comune` la vede ogni membro, una `personale` solo
 * chi l'ha scritta. Gli importi sono interi in centesimi col segno, la valuta è sempre EUR. Eliminare archivia (il conto con i suoi movimenti e caricamenti): da lì 404. Il gettone di assistenza non
 * ammette nessun metodo `flow.*`. Il finto non emette gli eventi.
 */
trait Flow
{
    private const FLOW_VISIBILITA = ['comune', 'personale'];

    private const FLOW_TIPI = ['corrente', 'carta', 'risparmio', 'altro'];

    private const FLOW_MOTIVI = ['versamento_membri', 'saldo_carta', 'giroconto', 'rimborso', 'altro'];

    private const FLOW_CONTI_TETTO = 50;

    private const FLOW_REGOLE_TETTO = 500;

    /** I movimenti che una sola `flow.regole.applicazioni.crea` può toccare (config `flow.regole.applicazione_massimo`). */
    private const FLOW_REGOLE_APPLICAZIONE_MASSIMO = 2000;

    private const FLOW_FISSE_TETTO = 100;

    private const FLOW_RICORRENTI_TIPI = ['fissa', 'bolletta', 'abitudine', 'stagionale'];

    private const FLOW_RICORRENTI_STATI = ['attiva', 'nascosta'];

    private const FLOW_RICORRENTI_RIFERIMENTI = ['rif', 'riferimento', 'ref', 'cro', 'trn', 'cod', 'codice', 'id', 'n', 'nr', 'num', 'numero', 'ord', 'ordine', 'del', 'data'];

    private const FLOW_FISSA_NOME = 80;

    private const FLOW_FISSE_FREQUENZE = [1, 3, 12];

    private const FLOW_REGOLA_TESTO = 100;

    private const FLOW_REGOLA_MINIMO = 2;

    private const FLOW_FORMATO = 2048;

    private const FLOW_RIGHE = 5000;

    private const FLOW_CAMPIONE = 10;

    private const FLOW_IMPORTO = 999_999_999_999;

    private const FLOW_DESCRIZIONE = 500;

    /** @var array{avvisi: array<string, array<string, mixed>>, avvisiStati: array<string, array<string, string>>, avvisiSilenzi: array<string, true>, conti: array<string, array<string, mixed>>, importazioni: array<string, array<string, mixed>>, movimenti: array<string, array<string, mixed>>, regole: array<string, array<string, mixed>>, fisse: array<string, array<string, mixed>>, correzioni: array<string, int|null>} */
    private array $flow = ['conti' => [], 'importazioni' => [], 'movimenti' => [], 'regole' => [], 'fisse' => [], 'correzioni' => [], 'nascoste' => [], 'avvisi' => [], 'avvisiStati' => [], 'avvisiSilenzi' => []];

    /** @var array<string, array<string, true>> chi è stato membro di un workspace e ne è uscito (workspace => persona => true): il diritto alla cancellazione dei dati di Finanze non finisce con l'uscita (#3597) */
    private array $flowEx = [];

    /**
     * Accende Finanze nel workspace, come app.modifica: dal #3550 il catalogo del finto la dà «disponibile» a tutti, e un workspace che nasce dal finto (io.workspace.crea) l'ha già accesa; serve a un
     * workspace messo su a mano (workspace()).
     *
     * @param  array<string, mixed>  $workspace  un workspace di workspace()
     */
    public function conFinanze(array $workspace): self
    {
        $id = $workspace['id'] ?? null;

        if (! is_string($id) || ! isset($this->workspace[$id])) {
            throw new InvalidArgumentException('Il workspace non è del finto: nasce con workspace().');
        }

        $this->attive[$id]['flow'] = true;

        return $this;
    }

    // ———————————————————————————————————————— Guardia

    /**
     * Il workspace del gettone con l'app `flow` accesa (i middleware `workspace` e `app:flow`): al gettone dell'accesso 403 gettone_senza_workspace, a un ospite 403 permesso_negato, all'app
     * spenta 403 app_non_attiva.
     *
     * @return array{persona: string, accesso: string, workspace: string}
     */
    private function conFlow(Request $richiesta): array
    {
        $chi = $this->conWorkspace($richiesta);

        if (! $this->appAttiva($chi['workspace'], 'flow')) {
            throw new Problema('app_non_attiva');
        }

        return $chi;
    }

    /** @return array<string, list<Closure>> i parametri della query che il metodo non ha: ognuno è 422 `campo_estraneo` sul suo nome */
    private function estraneiFlow(array $grezzi, array $ammessi): array
    {
        $estranei = array_diff(array_map('strval', array_keys($grezzi)), $ammessi);

        return array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $attributo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $attributo]);
        }]);
    }

    /** Il nome di una persona se è ancora membro del workspace, altrimenti null (Conti::nomi); senza persona (la riga comune che ha perso chi l'ha creata, #3597) null. */
    private function nomeFlow(string $workspace, ?string $persona): ?string
    {
        return $persona !== null && isset($this->membri[$workspace][$persona]) ? $this->persone[$persona]['nome'] : null;
    }

    private static function istanteFlow(): CarbonImmutable
    {
        return CarbonImmutable::now()->startOfMillisecond();
    }

    // ———————————————————————————————————————— Conti

    /**
     * flow.conti.elenca (ContiController::elenca): i conti che la persona vede, per banca, nome e id, a cursore; `visibilita` li filtra, ogni altro parametro è 422 `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaContiFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $vista = $grezzi['visibilita'] ?? null;

        return $this->pagina(
            $richiesta,
            'flow.conti.elenca:'.(is_string($vista) ? $vista : ''),
            'id',
            fn () => array_map(
                fn (array $conto) => $this->voceContoFlow($conto),
                array_values(array_filter($this->flow['conti'], fn (array $conto) => $this->contoVisibileFlow($conto, $chi) && ($vista === null || $conto['visibilita'] === $vista))),
            ),
            fn (array $voce) => [$voce['banca'], $voce['nome'], $voce['id']],
            function (string $id) use ($chi) {
                $conto = $this->flow['conti'][$id] ?? null;

                return $conto !== null && $conto['workspace'] === $chi['workspace'] && ($conto['visibilita'] === 'comune' || $conto['titolare'] === $chi['persona']) ? [$conto['banca'], $conto['nome'], $conto['id']] : null;
            },
            false,
            self::LIMITE_MASSIMO,
            null,
            ['visibilita' => ['bail', 'sometimes', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow($grezzi, ['limite', 'cursore', 'visibilita']),
        );
    }

    /**
     * flow.conti.mostra (ContiController::mostra): il conto intero e l'`ETag`. Uno personale di un altro è 404.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function mostraContoFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $voce = $this->voceContoFlow($this->contoFlow($chi, $id));

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * flow.conti.crea (ContiController::crea): un conto di chi chiama, che ne è il titolare (dal gettone). 201 con il conto, il suo percorso in `Location` e l'`ETag`. Il freno per persona si conta prima del
     * corpo, il tetto di 50 (409 `limite_raggiunto`) dopo. Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaContoFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);

        return $this->conIdempotenza($richiesta, 'flow.conti.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('freni:flow.conti.crea:'.$chi['persona'], self::FRENI['flow_conti_persona'], self::ORA);
            $campi = $this->testi->validaCorpo($corpo, $this->regoleContoFlow(true));

            if (count(array_filter($this->flow['conti'], fn (array $conto) => $conto['workspace'] === $chi['workspace'] && ! $conto['archiviato'])) >= self::FLOW_CONTI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::FLOW_CONTI_TETTO]);
            }

            $id = self::id();
            $this->flow['conti'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'titolare' => $chi['persona'], 'banca' => $campi['banca'], 'nome' => $campi['nome'], 'tipo' => $campi['tipo'], 'visibilita' => $campi['visibilita'],
                'iban_ultime4' => $campi['iban_ultime4'] ?? null, 'saldo' => $campi['saldo'] ?? null, 'saldo_il' => $campi['saldo_il'] ?? null, 'formato_importazione' => $campi['formato_importazione'] ?? null,
                'creato_il' => self::istanteFlow(), 'archiviato' => false,
            ];
            $voce = $this->voceContoFlow($this->flow['conti'][$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/flow/conti/{$id}", 'ETag' => self::etagCrm($voce)]];
        });
    }

    /**
     * flow.conti.modifica (ContiController::modifica): JSON Merge Patch. Solo il titolare: un conto personale di un altro è 404, uno comune di un altro 403 `permesso_negato`, prima di ogni controllo del
     * corpo. `If-Match` si legge prima del corpo (412 sulla versione vecchia). Un corpo che non cambia niente non scrive e lascia lo stesso `ETag`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaContoFlow(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $conto = $this->contoFlow($chi, $id, true);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceContoFlow($conto));
        $campi = $this->modificaCrm($corpo, $this->regoleContoFlow(false));

        foreach (['banca', 'nome', 'tipo', 'visibilita', 'iban_ultime4', 'saldo', 'saldo_il', 'formato_importazione'] as $campo) {
            if (array_key_exists($campo, $campi) && json_encode(self::ordinato($campi[$campo])) !== json_encode(self::ordinato($conto[$campo]))) {
                $this->flow['conti'][$conto['id']][$campo] = $campi[$campo];
            }
        }

        $voce = $this->voceContoFlow($this->flow['conti'][$conto['id']]);

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * flow.conti.elimina (ContiController::elimina): archivia il conto con i suoi movimenti e caricamenti (204): da lì risponde 404. Solo il titolare (come `modifica`).
     *
     * @return array{int, null}
     */
    private function eliminaContoFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $conto = $this->contoFlow($chi, $id, true);
        $this->flow['conti'][$conto['id']]['archiviato'] = true;

        foreach (['movimenti', 'importazioni'] as $tipo) {
            foreach ($this->flow[$tipo] as $chiave => $riga) {
                if ($riga['conto'] === $conto['id']) {
                    $this->flow[$tipo][$chiave]['archiviato'] = true;
                }
            }
        }

        return [204, null];
    }

    /**
     * Le regole del corpo di un conto. In creazione `banca`, `nome`, `tipo` e `visibilita` sono obbligatori; in modifica tutti facoltativi.
     *
     * @return array<string, mixed>
     */
    private function regoleContoFlow(bool $crea): array
    {
        $presente = $crea ? ['required'] : ['filled'];

        return [
            'banca' => ['bail', ...$presente, 'string', 'max:80', new NomeScritto],
            'nome' => ['bail', ...$presente, 'string', 'max:80', new NomeScritto],
            'tipo' => ['bail', ...$presente, 'string', Rule::in(self::FLOW_TIPI)],
            'visibilita' => ['bail', ...$presente, 'string', Rule::in(self::FLOW_VISIBILITA)],
            'iban_ultime4' => ['bail', 'nullable', 'string', 'regex:/^[0-9]{4}$/D'],
            'valuta' => ['bail', 'string', Rule::in(['EUR'])],
            'saldo' => ['bail', 'nullable', 'integer:strict', 'between:-100000000000000,100000000000000'],
            'saldo_il' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            'formato_importazione' => ['bail', 'nullable', 'array', function (string $attributo, mixed $valore, Closure $fail) {
                if ($valore !== [] && array_is_list($valore)) {
                    $this->rifiutaCrm($fail, $attributo, Regola::TIPO, 'validation.array', ['attribute' => $attributo]);
                } elseif (strlen(json_encode($valore, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '') > self::FLOW_FORMATO) {
                    $this->rifiutaCrm($fail, $attributo, Regola::TROPPO_LUNGO, 'validation.max.string', ['attribute' => $attributo, 'max' => self::FLOW_FORMATO]);
                }
            }],
        ];
    }

    /**
     * Il conto del workspace che chi chiama vede: uno che non c'è, di un altro workspace, eliminato o personale di un altro è 404 `non_trovato`. Con `$scrive` solo il titolare: per un conto comune di
     * un altro 403 `permesso_negato` (Conti::letto e Conti::scritto).
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return array<string, mixed>
     */
    private function contoFlow(array $chi, string $id, bool $scrive = false): array
    {
        $conto = $this->flow['conti'][strtolower($id)] ?? null;

        if ($conto === null || ! $this->contoVisibileFlow($conto, $chi)) {
            throw new Problema('non_trovato');
        }

        if ($scrive && ! $this->puoScrivereContoFlow($conto, $chi)) {
            throw new Problema('permesso_negato');
        }

        return $conto;
    }

    /**
     * Chi cambia o toglie un conto (Conti::puoScrivere): il titolare; un conto comune senza titolare (la persona ha chiesto la cancellazione dei suoi dati di Finanze, #3597) lo cambiano e lo tolgono il
     * proprietario e gli amministratori.
     *
     * @param  array<string, mixed>  $conto
     * @param  array{persona: string, workspace: string}  $chi
     */
    private function puoScrivereContoFlow(array $conto, array $chi): bool
    {
        return $conto['titolare'] === $chi['persona']
            || ($conto['titolare'] === null && in_array($this->membri[$chi['workspace']][$chi['persona']], ['proprietario', 'amministratore'], true));
    }

    /**
     * @param  array<string, mixed>  $conto
     * @param  array{persona: string, workspace: string}  $chi
     */
    private function contoVisibileFlow(array $conto, array $chi): bool
    {
        return $conto['workspace'] === $chi['workspace'] && ! $conto['archiviato'] && ($conto['visibilita'] === 'comune' || $conto['titolare'] === $chi['persona']);
    }

    /**
     * Lo schema Conto (Conti::forma): `movimenti_fino_al` è la data dell'ultimo movimento non archiviato, null se non ne ha.
     *
     * @param  array<string, mixed>  $conto
     * @return array<string, mixed>
     */
    private function voceContoFlow(array $conto): array
    {
        $date = array_column(array_filter($this->flow['movimenti'], fn (array $movimento) => $movimento['conto'] === $conto['id'] && ! $movimento['archiviato']), 'data');

        return [
            'id' => $conto['id'],
            'banca' => $conto['banca'],
            'nome' => $conto['nome'],
            'tipo' => $conto['tipo'],
            'visibilita' => $conto['visibilita'],
            'titolare' => ['id' => $conto['titolare'], 'nome' => $this->nomeFlow($conto['workspace'], $conto['titolare'])],
            'iban_ultime4' => $conto['iban_ultime4'],
            'valuta' => 'EUR',
            'saldo' => $conto['saldo'],
            'saldo_il' => $conto['saldo_il'],
            'movimenti_fino_al' => $date === [] ? null : max($date),
            'formato_importazione' => $conto['formato_importazione'] === null ? null : (object) $conto['formato_importazione'],
            'creato_il' => self::iso($conto['creato_il']),
        ];
    }

    // ———————————————————————————————————————— Categorie

    /**
     * flow.categorie.elenca (CategorieController::elenca): le categorie di sistema, tutte insieme e senza cursore, uguali per ogni workspace.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCategorieFlow(Request $richiesta): array
    {
        $this->conFlow($richiesta);

        return [200, ['data' => FlowCategorie::forme()]];
    }

    // ———————————————————————————————————————— Caricamenti

    /**
     * flow.conti.importazioni.elenca (ImportazioniController::elenca): i caricamenti del conto, dal più recente, anche quelli annullati. Un conto che chi chiama non vede è 404.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaImportazioniFlow(Request $richiesta, string $conto): array
    {
        $chi = $this->conFlow($richiesta);
        $conto = $this->contoFlow($chi, $conto);

        return $this->pagina(
            $richiesta,
            'flow.conti.importazioni.elenca',
            'id',
            fn () => array_map(
                fn (array $importazione) => $this->voceImportazioneFlow($importazione),
                array_values(array_filter($this->flow['importazioni'], fn (array $importazione) => $importazione['conto'] === $conto['id'] && ! $importazione['archiviato'])),
            ),
            fn (array $voce) => [$voce['creata_il'], $voce['id']],
            fn (string $id) => isset($this->flow['importazioni'][$id]) && $this->flow['importazioni'][$id]['conto'] === $conto['id'] ? [self::iso($this->flow['importazioni'][$id]['creata_il']), $id] : null,
            true,
        );
    }

    /**
     * flow.conti.importazioni.crea (ImportazioniController::crea): carica le righe di un estratto su un conto che chi chiama vede, o con `prova: true` ne dice l'esito senza scrivere niente. Il corpo si valida
     * prima del freno (che è per le prove o per i caricamenti). 200 in prova (`id` null), 201 dopo il caricamento; solo il caricamento accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaImportazioneFlow(Request $richiesta, array $corpo, string $conto): array
    {
        $chi = $this->conFlow($richiesta);
        $esegue = function () use ($chi, $corpo, $conto) {
            $conto = $this->contoFlow($chi, $conto);
            $campi = $this->testi->validaCorpo($corpo, [
                'file_nome' => ['required', 'string', 'max:200'],
                'prova' => ['nullable', 'boolean:strict'],
                'righe' => ['required', 'array', 'list', 'min:1', 'max:'.self::FLOW_RIGHE],
                'righe.*' => ['array'],
                'righe.*.data' => ['required', 'string', 'date_format:Y-m-d'],
                'righe.*.importo' => ['required', 'integer:strict', 'between:'.-self::FLOW_IMPORTO.','.self::FLOW_IMPORTO],
                'righe.*.descrizione' => ['required', 'string', 'max:'.self::FLOW_DESCRIZIONE],
                'righe.*.saldo' => ['nullable', 'integer:strict', 'between:'.-self::FLOW_IMPORTO.','.self::FLOW_IMPORTO],
            ]);
            $prova = (bool) ($campi['prova'] ?? false);
            $this->frena('freni:flow.conti.importazioni.crea:'.($prova ? 'prova:' : '').$chi['persona'], self::FRENI[$prova ? 'flow_importazioni_prova_persona' : 'flow_importazioni_persona'], self::ORA);
            $lette = $this->analizzaLeRigheFlow($conto, $campi['righe']);

            if ($prova) {
                return [200, ['data' => $this->riepilogoFlow(null, $campi['righe'], $lette)], []];
            }

            $nuove = array_values(array_filter($lette, fn (array $riga) => ! $riga['doppione']));
            $date = array_column($lette, 'data');
            $id = self::id();
            $ora = self::istanteFlow();
            $this->flow['importazioni'][$id] = [
                'id' => $id, 'workspace' => $conto['workspace'], 'conto' => $conto['id'], 'creata_da' => $chi['persona'], 'file_nome' => $campi['file_nome'], 'righe_lette' => count($lette), 'inserite' => count($nuove),
                'doppioni' => count($lette) - count($nuove), 'periodo_da' => min($date), 'periodo_a' => max($date), 'creata_il' => $ora, 'annullata_il' => null, 'movimenti_archiviati' => 0, 'archiviato' => false,
            ];

            foreach ($nuove as $riga) {
                $movimento = self::id();
                $this->flow['movimenti'][$movimento] = [
                    'id' => $movimento, 'workspace' => $conto['workspace'], 'conto' => $conto['id'], 'importazione' => $id, 'data' => $riga['data'], 'importo' => $riga['importo'], 'descrizione' => $riga['descrizione'],
                    'impronta' => $riga['impronta'], 'categoria_id' => $riga['categoria_id'], 'categoria_fonte' => $riga['categoria_fonte'], 'regola_id' => $riga['regola_id'], 'nota' => null, 'escluso' => false, 'escluso_motivo' => null,
                    'escluso_origine' => null, 'riconoscimento_rifiutato' => false, 'origine' => 'importazione', 'archiviato' => false,
                ];
            }

            return [201, ['data' => $this->riepilogoFlow($id, $campi['righe'], $lette)], []];
        };

        // Solo il caricamento vero accetta Idempotency-Key (IdempotenzaTranneLaProva): una prova gira ogni volta.
        return ($corpo['prova'] ?? null) === true ? $esegue() : $this->conIdempotenza($richiesta, 'flow.conti.importazioni.crea', $chi, $corpo, $esegue);
    }

    /**
     * flow.conti.importazioni.annullamento.crea (ImportazioniController::annullamentoCrea): archivia tutti i movimenti del caricamento, anche se la persona ne ha cambiato la categoria o la nota; 201
     * con l'esito `{id, annullata_il, movimenti_archiviati}`, ripetuto 200 con lo stesso esito. Il corpo, se c'è, è un oggetto senza campi.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function annullaImportazioneFlow(Request $richiesta, array $corpo, string $conto, string $importazione): array
    {
        $chi = $this->conFlow($richiesta);
        $conto = $this->contoFlow($chi, $conto);
        $this->frena('freni:flow.conti.importazioni.annullamento.crea:'.$chi['persona'], self::FRENI['flow_annullamenti_persona'], self::ORA);
        $this->testi->validaCorpo($corpo, []);
        $riga = $this->flow['importazioni'][strtolower($importazione)] ?? null;

        if ($riga === null || $riga['conto'] !== $conto['id'] || $riga['archiviato']) {
            throw new Problema('non_trovato');
        }

        $nuovo = $riga['annullata_il'] === null;

        if ($nuovo) {
            $archiviati = 0;

            foreach ($this->flow['movimenti'] as $id => $movimento) {
                if (($movimento['importazione'] ?? null) === $riga['id'] && ! $movimento['archiviato']) {
                    $this->flow['movimenti'][$id]['archiviato'] = true;
                    $archiviati++;
                }
            }

            $this->flow['importazioni'][$riga['id']]['annullata_il'] = self::istanteFlow();
            $this->flow['importazioni'][$riga['id']]['movimenti_archiviati'] = $archiviati;
        }

        $salvata = $this->flow['importazioni'][$riga['id']];

        return [$nuovo ? 201 : 200, ['data' => ['id' => $salvata['id'], 'annullata_il' => self::iso($salvata['annullata_il']), 'movimenti_archiviati' => $salvata['movimenti_archiviati']]]];
    }

    /**
     * Cosa sarebbe di ogni riga sul conto (Caricamento::analizza): l'impronta (data, importo, descrizione senza maiuscole né spazi in più, e il posto fra le righe uguali dello stesso giorno nel file), se
     * esiste già fra i movimenti vivi del conto (`doppione`), e la categoria. Non scrive.
     *
     * @param  array<string, mixed>  $conto
     * @param  list<array<string, mixed>>  $righe
     * @return list<array{data: string, importo: int, descrizione: string, impronta: string, doppione: bool, categoria_id: string|null, categoria_fonte: string, regola_id: string|null}>
     */
    private function analizzaLeRigheFlow(array $conto, array $righe): array
    {
        $posti = [];
        $lette = [];
        $regole = $this->regoleDelContoFlow($conto);
        $esistenti = array_column(array_filter($this->flow['movimenti'], fn (array $movimento) => $movimento['conto'] === $conto['id'] && ! $movimento['archiviato']), 'id', 'impronta');

        foreach ($righe as $riga) {
            $uguale = $riga['data'].'|'.$riga['importo'].'|'.trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($riga['descrizione'])));
            $posti[$uguale] = ($posti[$uguale] ?? -1) + 1;
            $impronta = hash('sha256', $uguale.'|'.$posti[$uguale]);
            [$categoria, $fonte, $regola] = $this->categoriaDiUnaRigaFlow($regole, $riga['descrizione']);
            $lette[] = ['data' => $riga['data'], 'importo' => $riga['importo'], 'descrizione' => $riga['descrizione'], 'impronta' => $impronta, 'doppione' => isset($esistenti[$impronta]), 'categoria_id' => $categoria, 'categoria_fonte' => $fonte, 'regola_id' => $regola];
        }

        return $lette;
    }

    /**
     * Il riepilogo di un caricamento o della sua prova: gli stessi campi, `id` null in prova (ImportazioniController::riepilogo).
     *
     * @param  list<array<string, mixed>>  $righe
     * @param  list<array<string, mixed>>  $lette
     * @return array<string, mixed>
     */
    private function riepilogoFlow(?string $id, array $righe, array $lette): array
    {
        $date = array_column($lette, 'data');
        $doppioni = count(array_filter($lette, fn (array $riga) => $riga['doppione']));
        $campione = [];

        foreach (array_slice($lette, 0, self::FLOW_CAMPIONE, true) as $indice => $riga) {
            $campione[] = [
                'indice' => $indice, 'data' => $riga['data'], 'importo' => $riga['importo'], 'descrizione' => $riga['descrizione'], 'esito' => $riga['doppione'] ? 'doppione' : 'nuova',
                'categoria_id' => $riga['categoria_id'], 'categoria_fonte' => $riga['categoria_fonte'],
            ];
        }

        return [
            'id' => $id,
            'righe_lette' => count($lette),
            'inserite' => count($lette) - $doppioni,
            'doppioni' => $doppioni,
            'periodo_da' => min($date),
            'periodo_a' => max($date),
            'saldo' => $this->saldoDelFileFlow($righe),
            'campione' => $campione,
        ];
    }

    /**
     * Il saldo del file (Caricamento::saldo): `ok` se ogni riga lo porta e ciascuno è il precedente più l'importo (file dal più vecchio) o meno l'importo della riga prima (dal più recente); `non_torna` se
     * non quadra in nessun verso; `assente` se manca anche in una sola riga.
     *
     * @param  list<array<string, mixed>>  $righe
     */
    private function saldoDelFileFlow(array $righe): string
    {
        foreach ($righe as $riga) {
            if (($riga['saldo'] ?? null) === null) {
                return 'assente';
            }
        }

        $dalPiuVecchio = true;
        $dalPiuRecente = true;

        for ($i = 1; $i < count($righe); $i++) {
            $dalPiuVecchio = $dalPiuVecchio && $righe[$i]['saldo'] === $righe[$i - 1]['saldo'] + $righe[$i]['importo'];
            $dalPiuRecente = $dalPiuRecente && $righe[$i]['saldo'] === $righe[$i - 1]['saldo'] - $righe[$i - 1]['importo'];
        }

        return $dalPiuVecchio || $dalPiuRecente ? 'ok' : 'non_torna';
    }

    /**
     * Lo schema Importazione (Importazioni::forma): mai un valore delle righe.
     *
     * @param  array<string, mixed>  $importazione
     * @return array<string, mixed>
     */
    private function voceImportazioneFlow(array $importazione): array
    {
        return [
            'id' => $importazione['id'],
            'file_nome' => $importazione['file_nome'],
            'righe_lette' => $importazione['righe_lette'],
            'inserite' => $importazione['inserite'],
            'doppioni' => $importazione['doppioni'],
            'periodo_da' => $importazione['periodo_da'],
            'periodo_a' => $importazione['periodo_a'],
            'creata_il' => self::iso($importazione['creata_il']),
            'creata_da' => ['id' => $importazione['creata_da'], 'nome' => $this->nomeFlow($importazione['workspace'], $importazione['creata_da'])],
            'annullata_il' => $importazione['annullata_il'] === null ? null : self::iso($importazione['annullata_il']),
            'movimenti_attivi' => count(array_filter($this->flow['movimenti'], fn (array $movimento) => ($movimento['importazione'] ?? null) === $importazione['id'] && ! $movimento['archiviato'])),
        ];
    }

    // ———————————————————————————————————————— Movimenti

    /**
     * flow.movimenti.elenca (MovimentiController::elenca): i movimenti della vista (`visibilita` obbligatoria), per data decrescente e id, con i filtri; a cursore. Ogni altro parametro è 422 `campo_estraneo`,
     * `q` per primo: il testo libero non sta nell'URL. Un `conto` che chi chiama non vede è 404.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaMovimentiFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $filtri = ['visibilita', 'conto', 'categoria', 'da', 'a', 'escluso'];
        $firma = array_map(fn (string $filtro) => is_scalar($grezzi[$filtro] ?? null) ? (string) $grezzi[$filtro] : null, $filtri);

        // Il filtro della tappa 4 entra nella firma solo se c'è (come in Movimenti::listaDi).
        if (isset($grezzi['escluso_origine']) && is_scalar($grezzi['escluso_origine'])) {
            $firma[] = (string) $grezzi['escluso_origine'];
        }

        $lista = 'flow.movimenti.elenca|'.json_encode($firma, JSON_UNESCAPED_UNICODE);

        return $this->pagina(
            $richiesta,
            $lista,
            'id',
            fn () => $this->movimentiDellaVistaFlow($chi, [
                'visibilita' => (string) $grezzi['visibilita'], 'conto' => $grezzi['conto'] ?? null, 'categoria' => $grezzi['categoria'] ?? null, 'da' => $grezzi['da'] ?? null, 'a' => $grezzi['a'] ?? null,
                'escluso' => isset($grezzi['escluso']) ? $grezzi['escluso'] === 'true' : null,
                'escluso_origine' => $grezzi['escluso_origine'] ?? null,
            ], null),
            fn (array $voce) => [$voce['data'], $voce['id']],
            fn (string $id) => isset($this->flow['movimenti'][$id]) && $this->flow['movimenti'][$id]['workspace'] === $chi['workspace'] ? [$this->flow['movimenti'][$id]['data'], $id] : null,
            true,
            self::LIMITE_MASSIMO,
            null,
            $this->regoleDeiFiltriFlow(false) + $this->estraneiFlow($grezzi, ['limite', 'cursore', ...$filtri, 'escluso_origine']),
        );
    }

    /**
     * flow.movimenti.ricerca.crea (MovimentiController::ricercaCrea): lo stesso elenco con il testo cercato (`q`, da 2 a 100 caratteri) nel corpo di un POST, mai nell'URL. «Contiene», senza maiuscole né
     * accenti, nella descrizione e nella nota. Risponde 200, perché non crea niente. Un `q` nella query è 422 `campo_estraneo` sul parametro; un `q` che normalizzato è vuoto 422 su `#/q`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function cercaMovimentiFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);

        if (array_key_exists('q', self::query($richiesta))) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.prohibited', ['attribute' => 'q']), 'regola' => Regola::CAMPO_ESTRANEO, 'parameter' => 'q']]);
        }

        $campi = $this->testi->validaCorpo($corpo, $this->regoleDeiFiltriFlow(true) + [
            'q' => ['bail', 'required', 'string', 'min:2', 'max:100'],
            'limite' => ['nullable', 'integer:strict', 'between:1,'.self::LIMITE_MASSIMO],
            'cursore' => ['nullable', 'string'],
        ]);
        $cercato = FlowCategorie::normalizza((string) $campi['q']);

        if ($cercato === '') {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.required', ['attribute' => 'q']), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#/q']]);
        }

        $filtri = ['visibilita' => (string) $campi['visibilita'], 'conto' => $campi['conto'] ?? null, 'categoria' => $campi['categoria'] ?? null, 'da' => $campi['da'] ?? null, 'a' => $campi['a'] ?? null, 'escluso' => $campi['escluso'] ?? null, 'escluso_origine' => $campi['escluso_origine'] ?? null];
        $lista = 'flow.movimenti.ricerca.crea|'.json_encode([...array_values(array_slice($filtri, 0, 6)), $cercato, ...($filtri['escluso_origine'] === null ? [] : [$filtri['escluso_origine']])], JSON_UNESCAPED_UNICODE);
        $this->parametriDelCorpo = true;

        try {
            if (isset($campi['cursore']) && $this->leggiPosizione($lista, ['id'], $campi['cursore']) === null) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.cursore', ['attribute' => 'cursore']), 'regola' => Regola::FORMATO, 'pointer' => '#/cursore']]);
            }

            return $this->impagina(
                ['limite' => $campi['limite'] ?? null, 'cursore' => $campi['cursore'] ?? null],
                $lista,
                'id',
                $this->movimentiDellaVistaFlow($chi, $filtri, $cercato),
                fn (array $voce) => [$voce['data'], $voce['id']],
                fn (string $id) => isset($this->flow['movimenti'][$id]) && $this->flow['movimenti'][$id]['workspace'] === $chi['workspace'] ? [$this->flow['movimenti'][$id]['data'], $id] : null,
                true,
            );
        } finally {
            $this->parametriDelCorpo = false;
        }
    }

    /**
     * flow.movimenti.mostra (MovimentiController::mostra): il movimento intero e l'`ETag`. Uno di un conto che chi chiama non vede è 404.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function mostraMovimentoFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $voce = $this->voceMovimentoFlow($this->movimentoFlow($chi, $id));

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * flow.movimenti.crea (MovimentiController::crea): un movimento a mano su un conto che chi chiama vede. 201 con il movimento, `Location` ed `ETag`. La categoria, se la persona non la sceglie, la assegna
     * il backoffice come a un caricamento. Un conto che non si vede o eliminato è 404. Il freno si conta prima del corpo. Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaMovimentoFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);

        return $this->conIdempotenza($richiesta, 'flow.movimenti.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('freni:flow.movimenti.crea:'.$chi['persona'], self::FRENI['flow_movimenti_persona'], self::ORA);
            $campi = $this->testi->validaCorpo($corpo, $this->regoleMovimentoFlow(true));
            $conto = $this->contoFlow($chi, $campi['conto_id']);
            [$categoria, $fonte, $regola] = isset($campi['categoria_id']) ? [$campi['categoria_id'], 'utente', null] : $this->categoriaDiUnaRigaFlow($this->regoleDelContoFlow($conto), $campi['descrizione']);
            $id = self::id();
            $this->flow['movimenti'][$id] = [
                'id' => $id, 'workspace' => $conto['workspace'], 'conto' => $conto['id'], 'importazione' => null, 'data' => $campi['data'], 'importo' => $campi['importo'], 'descrizione' => $campi['descrizione'],
                'impronta' => null, 'categoria_id' => $categoria, 'categoria_fonte' => $fonte, 'regola_id' => $regola, 'nota' => $campi['nota'] ?? null, 'escluso' => false, 'escluso_motivo' => null, 'escluso_origine' => null, 'riconoscimento_rifiutato' => false, 'origine' => 'manuale', 'archiviato' => false,
            ];
            $voce = $this->voceMovimentoFlow($this->flow['movimenti'][$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/flow/movimenti/{$id}", 'ETag' => self::etagCrm($voce)]];
        });
    }

    /**
     * flow.movimenti.modifica (MovimentiController::modifica): JSON Merge Patch con `If-Match` letto prima del corpo. Categoria e nota si cambiano su ogni movimento (una categoria scelta dalla persona dà
     * `categoria_fonte: utente`, `null` la toglie), `escluso` vuole il suo motivo; data, importo e descrizione solo su un movimento a mano (su un importato 422 `campo_vietato`).
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaMovimentoFlow(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $movimento = $this->movimentoFlow($chi, $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceMovimentoFlow($movimento));
        $campi = $this->modificaCrm($corpo, $this->regoleMovimentoFlow(false));

        if ($movimento['origine'] === 'importazione') {
            $errori = [];

            foreach (['data', 'importo', 'descrizione'] as $campo) {
                if (array_key_exists($campo, $campi)) {
                    $errori[] = ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => $campo]), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => '#/'.$campo];
                }
            }

            if ($errori !== []) {
                throw new Problema('dati_non_validi', $errori);
            }
        }

        $nuovo = [];

        foreach (['data', 'importo', 'descrizione', 'nota'] as $campo) {
            if (array_key_exists($campo, $campi) && $campi[$campo] !== $movimento[$campo]) {
                $nuovo[$campo] = $campi[$campo];
            }
        }

        if (array_key_exists('categoria_id', $campi)) {
            $fonte = $campi['categoria_id'] === null ? 'nessuna' : 'utente';

            if ($campi['categoria_id'] !== $movimento['categoria_id'] || $fonte !== $movimento['categoria_fonte']) {
                $nuovo['categoria_id'] = $campi['categoria_id'];
                $nuovo['categoria_fonte'] = $fonte;
                $nuovo['regola_id'] = null;
            }
        }

        $escluso = $campi['escluso'] ?? $movimento['escluso'];
        $motivo = array_key_exists('escluso_motivo', $campi) ? $campi['escluso_motivo'] : $movimento['escluso_motivo'];

        if ($escluso && $motivo === null) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.required', ['attribute' => 'escluso_motivo']), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#/escluso_motivo']]);
        }

        if (! $escluso && ($campi['escluso_motivo'] ?? null) !== null) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.prohibited', ['attribute' => 'escluso_motivo']), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => '#/escluso_motivo']]);
        }

        $motivo = $escluso ? $motivo : null;

        if ($escluso !== $movimento['escluso']) {
            $nuovo['escluso'] = $escluso;
            // Chi esclude è la persona; chi rimette nei totali un movimento escluso vuole che il riconoscimento non lo riesclude più (#3523).
            $nuovo['escluso_origine'] = $escluso ? 'manuale' : null;

            if (! $escluso) {
                $nuovo['riconoscimento_rifiutato'] = true;
            }
        } elseif ($escluso && $motivo !== $movimento['escluso_motivo']) {
            $nuovo['escluso_origine'] = 'manuale';
        }

        if ($motivo !== $movimento['escluso_motivo']) {
            $nuovo['escluso_motivo'] = $motivo;
        }

        $this->flow['movimenti'][$movimento['id']] = $nuovo + $movimento;
        $voce = $this->voceMovimentoFlow($this->flow['movimenti'][$movimento['id']]);

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * flow.movimenti.elimina (MovimentiController::elimina): toglie un movimento inserito a mano archiviandolo (204). Uno caricato da un estratto non si toglie da solo (409 `movimento_importato`): si
     * annulla il caricamento.
     *
     * @return array{int, null}
     */
    private function eliminaMovimentoFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $movimento = $this->movimentoFlow($chi, $id);

        if ($movimento['origine'] === 'importazione') {
            throw new Problema('movimento_importato');
        }

        $this->flow['movimenti'][$movimento['id']]['archiviato'] = true;

        return [204, null];
    }

    /**
     * Le regole del corpo di un movimento. In creazione `conto_id`, `data`, `importo` e `descrizione` sono obbligatori; in modifica tutti facoltativi, con `escluso` e il suo motivo al posto del conto.
     *
     * @return array<string, mixed>
     */
    private function regoleMovimentoFlow(bool $crea): array
    {
        $presente = $crea ? ['required'] : ['filled'];

        return [
            ...($crea ? ['conto_id' => ['bail', 'required', 'string', 'ulid']] : [
                'escluso' => ['bail', 'filled', 'boolean:strict'],
                'escluso_motivo' => ['bail', 'nullable', 'string', Rule::in(self::FLOW_MOTIVI)],
            ]),
            'data' => ['bail', ...$presente, 'string', 'date_format:Y-m-d'],
            'importo' => ['bail', ...$presente, 'integer:strict', 'between:'.-self::FLOW_IMPORTO.','.self::FLOW_IMPORTO, 'not_in:0'],
            'valuta' => ['bail', 'string', Rule::in(['EUR'])],
            'descrizione' => ['bail', ...$presente, 'string', 'max:'.self::FLOW_DESCRIZIONE],
            'nota' => ['bail', 'nullable', 'string', 'max:'.self::FLOW_DESCRIZIONE],
            'categoria_id' => ['bail', 'nullable', 'string', Rule::in(FlowCategorie::id())],
        ];
    }

    /**
     * Le regole dei filtri, uguali per l'URL di `elenca` (tutto testo) e per il corpo di `ricerca.crea` (tipi veri). `visibilita` è obbligatoria (Movimenti::regoleDeiFiltri).
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDeiFiltriFlow(bool $inCorpo): array
    {
        $facoltativo = $inCorpo ? 'nullable' : 'sometimes';

        return [
            'visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)],
            'conto' => ['bail', $facoltativo, 'string', 'ulid'],
            'categoria' => ['bail', $facoltativo, 'string', Rule::in(FlowCategorie::id())],
            'da' => ['bail', $facoltativo, 'string', 'date_format:Y-m-d'],
            'a' => ['bail', $facoltativo, 'string', 'date_format:Y-m-d'],
            'escluso' => $inCorpo ? ['bail', 'nullable', 'boolean:strict'] : ['bail', 'sometimes', 'string', Rule::in(['true', 'false'])],
            'escluso_origine' => ['bail', $facoltativo, 'string', Rule::in(['automatica', 'manuale'])],
        ];
    }

    /**
     * I movimenti della vista (Movimenti::pagina): quelli dei conti `comune` o `personale` (di chi chiama) non eliminati. Un `conto` che la persona non vede è 404, non una lista vuota; uno che vede ma sta
     * nell'altra vista dà una lista vuota. `$cercato` è il testo già normalizzato, o null.
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @param  array{visibilita: string, conto: mixed, categoria: mixed, da: mixed, a: mixed, escluso: mixed, escluso_origine: mixed}  $filtri
     * @return list<array<string, mixed>>
     */
    private function movimentiDellaVistaFlow(array $chi, array $filtri, ?string $cercato): array
    {
        if ($filtri['conto'] !== null) {
            $this->contoFlow($chi, (string) $filtri['conto']);
        }

        $conti = array_keys(array_filter($this->flow['conti'], fn (array $conto) => $this->contoVisibileFlow($conto, $chi) && $conto['visibilita'] === $filtri['visibilita']));
        $voci = [];

        foreach ($this->flow['movimenti'] as $movimento) {
            if ($movimento['archiviato'] || ! in_array($movimento['conto'], $conti, true)
                || ($filtri['conto'] !== null && $movimento['conto'] !== strtolower((string) $filtri['conto']))
                || ($filtri['categoria'] !== null && $movimento['categoria_id'] !== $filtri['categoria'])
                || ($filtri['da'] !== null && $movimento['data'] < $filtri['da'])
                || ($filtri['a'] !== null && $movimento['data'] > $filtri['a'])
                || ($filtri['escluso'] !== null && $movimento['escluso'] !== $filtri['escluso'])
                || ($filtri['escluso_origine'] !== null && ! ($movimento['escluso'] && ($movimento['escluso_origine'] ?? 'manuale') === $filtri['escluso_origine']))
                || ($cercato !== null && ! str_contains(FlowCategorie::normalizza($movimento['descrizione']).'|'.FlowCategorie::normalizza($movimento['nota'] ?? ''), $cercato))) {
                continue;
            }

            $voci[] = $this->voceMovimentoFlow($movimento);
        }

        return $voci;
    }

    /**
     * Il movimento per chi lo legge o lo tocca: uno di un conto che non si vede (personale di un altro, o eliminato) è 404 (Movimenti::letto).
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return array<string, mixed>
     */
    private function movimentoFlow(array $chi, string $id): array
    {
        $movimento = $this->flow['movimenti'][strtolower($id)] ?? null;
        $conto = $movimento === null ? null : ($this->flow['conti'][$movimento['conto']] ?? null);

        if ($movimento === null || $movimento['archiviato'] || $conto === null || ! $this->contoVisibileFlow($conto, $chi)) {
            throw new Problema('non_trovato');
        }

        return $movimento;
    }

    /**
     * Lo schema Movimento (Movimenti::forma): `visibilita` è quella del suo conto.
     *
     * @param  array<string, mixed>  $movimento
     * @return array<string, mixed>
     */
    private function voceMovimentoFlow(array $movimento): array
    {
        return [
            'id' => $movimento['id'],
            'conto_id' => $movimento['conto'],
            'visibilita' => $this->flow['conti'][$movimento['conto']]['visibilita'],
            'data' => $movimento['data'],
            'importo' => $movimento['importo'],
            'valuta' => 'EUR',
            'descrizione' => $movimento['descrizione'],
            'categoria_id' => $movimento['categoria_id'],
            'categoria_fonte' => $movimento['categoria_fonte'],
            'nota' => $movimento['nota'],
            'escluso' => $movimento['escluso'],
            'escluso_motivo' => $movimento['escluso_motivo'],
            'escluso_origine' => $movimento['escluso'] ? ($movimento['escluso_origine'] ?? 'manuale') : null,
            'origine' => $movimento['origine'],
            'importazione_id' => $movimento['importazione'] ?? null,
        ];
    }

    // ———————————————————————————————————————— Regole di categoria

    /**
     * flow.regole.elenca (RegoleController::elenca): le regole che la persona vede (le comuni e le sue personali), dalla più recente, a cursore; `visibilita` le filtra, ogni altro parametro è 422
     * `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaRegoleFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $vista = $grezzi['visibilita'] ?? null;

        return $this->pagina(
            $richiesta,
            'flow.regole.elenca:'.(is_string($vista) ? $vista : ''),
            'id',
            fn () => array_map(
                fn (array $regola) => $this->voceRegolaFlow($regola),
                array_values(array_filter($this->flow['regole'], fn (array $regola) => ! $regola['archiviato'] && $this->regolaVisibileFlow($regola, $chi) && ($vista === null || $regola['visibilita'] === $vista))),
            ),
            fn (array $voce) => [$voce['creata_il'], $voce['id']],
            fn (string $id) => isset($this->flow['regole'][$id]) && $this->regolaVisibileFlow($this->flow['regole'][$id], $chi) ? [self::iso($this->flow['regole'][$id]['creata_il']), $id] : null,
            true,
            self::LIMITE_MASSIMO,
            null,
            ['visibilita' => ['bail', 'sometimes', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow($grezzi, ['limite', 'cursore', 'visibilita']),
        );
    }

    /**
     * flow.regole.crea (RegoleController::crea): una regola di chi chiama (dal gettone). 201 con la regola. Vale dai movimenti che nascono dopo. Lo stesso testo (senza maiuscole né accenti) nella stessa
     * vista, e per la stessa persona se personale, è 422 su `#/testo`; il tetto di 500 (409 `limite_raggiunto`) si conta dopo. Il freno si conta prima del corpo. Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaRegolaFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);

        return $this->conIdempotenza($richiesta, 'flow.regole.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('freni:flow.regole.crea:'.$chi['persona'], self::FRENI['flow_regole_persona'], self::ORA);
            $campi = $this->testi->validaCorpo($corpo, [
                'testo' => ['bail', 'required', 'string', 'min:'.self::FLOW_REGOLA_MINIMO, 'max:'.self::FLOW_REGOLA_TESTO],
                'categoria_id' => ['bail', 'required', 'string', Rule::in(FlowCategorie::id())],
                'visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)],
            ]);
            $chiave = FlowCategorie::normalizza($campi['testo']);

            if (mb_strlen($chiave) < self::FLOW_REGOLA_MINIMO) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.min.string', ['attribute' => 'testo', 'min' => (string) self::FLOW_REGOLA_MINIMO]), 'regola' => Regola::TROPPO_CORTO, 'pointer' => '#/testo']]);
            }

            $stesse = array_filter($this->flow['regole'], fn (array $regola) => ! $regola['archiviato'] && $regola['workspace'] === $chi['workspace'] && $regola['visibilita'] === $campi['visibilita']
                && ($campi['visibilita'] === 'comune' || $regola['creata_da'] === $chi['persona']));

            if (array_filter($stesse, fn (array $regola) => $regola['testo_chiave'] === $chiave) !== []) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.unique', ['attribute' => 'testo']), 'regola' => Regola::DUPLICATO, 'pointer' => '#/testo']]);
            }

            if (count($stesse) >= self::FLOW_REGOLE_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::FLOW_REGOLE_TETTO]);
            }

            $id = self::id();
            $this->flow['regole'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'creata_da' => $chi['persona'], 'testo' => $campi['testo'], 'testo_chiave' => $chiave, 'categoria_id' => $campi['categoria_id'],
                'visibilita' => $campi['visibilita'], 'creata_il' => self::istanteFlow(), 'archiviato' => false,
            ];

            return [201, ['data' => $this->voceRegolaFlow($this->flow['regole'][$id])], []];
        });
    }

    /**
     * flow.regole.elimina (RegoleController::elimina): toglie una regola archiviandola (204). I movimenti già categorizzati non cambiano. La toglie chi l'ha scritta; una comune anche il proprietario e
     * l'amministratore (per ogni altro membro 403 `permesso_negato`); una personale di un altro è 404.
     *
     * @return array{int, null}
     */
    private function eliminaRegolaFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $regola = $this->flow['regole'][strtolower($id)] ?? null;

        if ($regola === null || $regola['archiviato'] || ! $this->regolaVisibileFlow($regola, $chi)) {
            throw new Problema('non_trovato');
        }

        if ($regola['creata_da'] !== $chi['persona'] && ! in_array($this->membri[$chi['workspace']][$chi['persona']], ['proprietario', 'amministratore'], true)) {
            throw new Problema('permesso_negato');
        }

        $this->flow['regole'][$regola['id']]['archiviato'] = true;

        return [204, null];
    }

    /**
     * flow.regole.applicazioni.crea (RegoleController::applicazioniCrea): applica la regola ai movimenti passati della sua vista e risponde 200 `{movimenti_toccati}`. Tocca i movimenti vivi che la regola
     * cattura, dove è la più recente fra le regole della vista, e che la persona non ha corretto a mano; idempotente (la seconda volta 0); tutto o niente, oltre 2.000 movimenti è 409 `limite_raggiunto`. La
     * applica chi può eliminare la regola (403 `permesso_negato` per gli altri); una personale di un altro è 404. Il freno si conta prima del corpo, che se c'è è un oggetto senza campi.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function applicaRegolaFlow(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $regola = $this->flow['regole'][strtolower($id)] ?? null;

        if ($regola === null || $regola['archiviato'] || ! $this->regolaVisibileFlow($regola, $chi)) {
            throw new Problema('non_trovato');
        }

        if ($regola['creata_da'] !== $chi['persona'] && ! in_array($this->membri[$chi['workspace']][$chi['persona']], ['proprietario', 'amministratore'], true)) {
            throw new Problema('permesso_negato');
        }

        $this->frena('freni:flow.regole.applicazioni.crea:'.$chi['persona'], self::FRENI['flow_regole_applicazioni_persona'], self::ORA);
        $this->testi->validaCorpo($corpo, []);
        $regole = $this->regoleDellaVistaFlow($regola['workspace'], $regola['visibilita'], $regola['creata_da']);
        $conti = array_keys(array_filter($this->flow['conti'], fn (array $conto) => ! $conto['archiviato'] && $conto['workspace'] === $regola['workspace'] && $conto['visibilita'] === $regola['visibilita']
            && ($regola['visibilita'] === 'comune' || $conto['titolare'] === $regola['creata_da'])));
        $da = [];

        foreach ($this->flow['movimenti'] as $movimento) {
            if ($movimento['archiviato'] || ! in_array($movimento['conto'], $conti, true) || $movimento['categoria_fonte'] === 'utente') {
                continue;
            }

            foreach ($regole as $candidata) {
                if (str_contains(FlowCategorie::normalizza($movimento['descrizione']), $candidata['testo'])) {
                    if ($candidata['id'] === $regola['id'] && ! ($movimento['categoria_fonte'] === 'regola' && ($movimento['regola_id'] ?? null) === $regola['id'] && $movimento['categoria_id'] === $regola['categoria_id'])) {
                        $da[] = $movimento['id'];
                    }

                    break;
                }
            }
        }

        if (count($da) > self::FLOW_REGOLE_APPLICAZIONE_MASSIMO) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::FLOW_REGOLE_APPLICAZIONE_MASSIMO]);
        }

        foreach ($da as $movimento) {
            $this->flow['movimenti'][$movimento]['categoria_id'] = $regola['categoria_id'];
            $this->flow['movimenti'][$movimento]['categoria_fonte'] = 'regola';
            $this->flow['movimenti'][$movimento]['regola_id'] = $regola['id'];
        }

        return [200, ['movimenti_toccati' => count($da)]];
    }

    /**
     * @param  array<string, mixed>  $regola
     * @param  array{persona: string, workspace: string}  $chi
     */
    private function regolaVisibileFlow(array $regola, array $chi): bool
    {
        return $regola['workspace'] === $chi['workspace'] && ($regola['visibilita'] === 'comune' || $regola['creata_da'] === $chi['persona']);
    }

    /**
     * Le regole che valgono per un conto, dalla più recente: le comuni per un conto comune, le personali del suo titolare per uno personale (Regole::delConto).
     *
     * @param  array<string, mixed>  $conto
     * @return list<array{id: string, testo: string, categoria_id: string}>
     */
    private function regoleDelContoFlow(array $conto): array
    {
        return $this->regoleDellaVistaFlow($conto['workspace'], $conto['visibilita'], $conto['titolare']);
    }

    /**
     * Le regole di una vista, dalla più recente: le comuni del workspace, o le personali di `$titolare` (Regole::dellaVista).
     *
     * @return list<array{id: string, testo: string, categoria_id: string}>
     */
    private function regoleDellaVistaFlow(string $workspace, string $visibilita, ?string $titolare): array
    {
        $regole = array_filter($this->flow['regole'], fn (array $regola) => ! $regola['archiviato'] && $regola['workspace'] === $workspace && $regola['visibilita'] === $visibilita
            && ($visibilita === 'comune' || $regola['creata_da'] === $titolare));
        usort($regole, fn (array $una, array $altra) => [$altra['creata_il'], $altra['id']] <=> [$una['creata_il'], $una['id']]);

        return array_values(array_map(fn (array $regola) => ['id' => $regola['id'], 'testo' => $regola['testo_chiave'], 'categoria_id' => $regola['categoria_id']], $regole));
    }

    /**
     * La categoria di una riga che nasce e da dove viene (Regole::categoriaDi): la regola, poi la parola chiave di sistema, poi niente.
     *
     * @param  list<array{id: string, testo: string, categoria_id: string}>  $regole
     * @return array{0: string|null, 1: string, 2: string|null} la categoria, la `categoria_fonte` e la regola (solo se la fonte è `regola`)
     */
    private function categoriaDiUnaRigaFlow(array $regole, string $descrizione): array
    {
        $normalizzato = FlowCategorie::normalizza($descrizione);

        foreach ($regole as $regola) {
            if (str_contains($normalizzato, $regola['testo'])) {
                return [$regola['categoria_id'], 'regola', $regola['id']];
            }
        }

        $categoria = FlowCategorie::assegna($normalizzato);

        return [$categoria, $categoria === null ? 'nessuna' : 'sistema', null];
    }

    /**
     * Lo schema Regola (Regole::forma).
     *
     * @param  array<string, mixed>  $regola
     * @return array<string, mixed>
     */
    private function voceRegolaFlow(array $regola): array
    {
        $volte = count(array_filter($this->flow['movimenti'], fn (array $movimento) => ! $movimento['archiviato'] && $movimento['categoria_fonte'] === 'regola' && ($movimento['regola_id'] ?? null) === $regola['id']));

        return [
            'id' => $regola['id'],
            'testo' => $regola['testo'],
            'categoria_id' => $regola['categoria_id'],
            'visibilita' => $regola['visibilita'],
            'creata_da' => ['id' => $regola['creata_da'], 'nome' => $this->nomeFlow($regola['workspace'], $regola['creata_da'])],
            'creata_il' => self::iso($regola['creata_il']),
            'applicata_volte' => $volte,
        ];
    }

    // ———————————————————————————————————————— Spese fisse

    /**
     * flow.fisse.elenca (FisseController::elenca): le spese fisse della vista chiesta (`visibilita`, obbligatoria: le comuni del workspace o le proprie personali), dalla più recente, a cursore. Il
     * cursore è legato alla vista; ogni altro parametro è 422 `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaFisseFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $vista = is_string($grezzi['visibilita'] ?? null) ? $grezzi['visibilita'] : '';

        return $this->pagina(
            $richiesta,
            'flow.fisse.elenca:'.$vista,
            'id',
            fn () => array_map(
                fn (array $fissa) => $this->voceFissaFlow($fissa),
                array_values(array_filter($this->flow['fisse'], fn (array $fissa) => ! $fissa['archiviato'] && $this->fissaDellaVistaFlow($fissa, $chi, $vista))),
            ),
            fn (array $voce) => [$voce['creata_il'], $voce['id']],
            fn (string $id) => isset($this->flow['fisse'][$id]) && $this->fissaDellaVistaFlow($this->flow['fisse'][$id], $chi, $vista) ? [self::iso($this->flow['fisse'][$id]['creata_il']), $id] : null,
            true,
            self::LIMITE_MASSIMO,
            null,
            ['visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow($grezzi, ['limite', 'cursore', 'visibilita']),
        );
    }

    /**
     * flow.fisse.crea (FisseController::crea): una spesa fissa di chi chiama (dal gettone). 201 con la spesa, senza `Location`. `prima_data` è obbligatoria se la frequenza è 3 o 12; il tetto di 100 per
     * vista (409 `limite_raggiunto`) si conta dopo il corpo. Il freno si conta prima del corpo. Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaFissaFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);

        return $this->conIdempotenza($richiesta, 'flow.fisse.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('freni:flow.fisse.crea:'.$chi['persona'], self::FRENI['flow_fisse_persona'], self::ORA);
            $campi = $this->testi->validaCorpo($corpo, [
                'visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)],
                'nome' => ['bail', 'required', 'string', 'max:'.self::FLOW_FISSA_NOME, new NomeScritto],
                'importo' => ['bail', 'required', 'integer:strict', 'between:1,'.self::FLOW_IMPORTO],
                'frequenza_mesi' => ['bail', 'required', 'integer:strict', Rule::in(self::FLOW_FISSE_FREQUENZE)],
                'prima_data' => ['bail', 'nullable', Rule::requiredIf(fn () => in_array($corpo['frequenza_mesi'] ?? null, [3, 12], true)), 'string', 'date_format:Y-m-d'],
            ]);

            if (count(array_filter($this->flow['fisse'], fn (array $fissa) => ! $fissa['archiviato'] && $this->fissaDellaVistaFlow($fissa, $chi, $campi['visibilita']))) >= self::FLOW_FISSE_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::FLOW_FISSE_TETTO]);
            }

            $id = self::id();
            $this->flow['fisse'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'creata_da' => $chi['persona'], 'nome' => $campi['nome'], 'importo' => $campi['importo'], 'frequenza_mesi' => $campi['frequenza_mesi'],
                'prima_data' => $campi['prima_data'] ?? null, 'visibilita' => $campi['visibilita'], 'creata_il' => self::istanteFlow(), 'archiviato' => false,
            ];

            return [201, ['data' => $this->voceFissaFlow($this->flow['fisse'][$id])], []];
        });
    }

    /**
     * flow.fisse.elimina (FisseController::elimina): toglie una spesa fissa archiviandola (204). La toglie chi l'ha scritta; una comune anche il proprietario e l'amministratore (per ogni altro membro
     * 403 `permesso_negato`); una personale di un altro è 404.
     *
     * @return array{int, null}
     */
    private function eliminaFissaFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $fissa = $this->flow['fisse'][strtolower($id)] ?? null;

        if ($fissa === null || $fissa['archiviato'] || $fissa['workspace'] !== $chi['workspace'] || ($fissa['visibilita'] === 'personale' && $fissa['creata_da'] !== $chi['persona'])) {
            throw new Problema('non_trovato');
        }

        if ($fissa['creata_da'] !== $chi['persona'] && ! in_array($this->membri[$chi['workspace']][$chi['persona']], ['proprietario', 'amministratore'], true)) {
            throw new Problema('permesso_negato');
        }

        $this->flow['fisse'][$fissa['id']]['archiviato'] = true;

        return [204, null];
    }

    /**
     * Una spesa è della vista che la persona guarda: le comuni del workspace, o le personali che ha scritto lei (Fisse::dellaVista).
     *
     * @param  array<string, mixed>  $fissa
     * @param  array{persona: string, workspace: string}  $chi
     */
    private function fissaDellaVistaFlow(array $fissa, array $chi, string $vista): bool
    {
        return $fissa['workspace'] === $chi['workspace'] && $fissa['visibilita'] === $vista && ($vista === 'comune' || $fissa['creata_da'] === $chi['persona']);
    }

    /**
     * Lo schema SpesaFissa (Fisse::forma).
     *
     * @param  array<string, mixed>  $fissa
     * @return array<string, mixed>
     */
    private function voceFissaFlow(array $fissa): array
    {
        return [
            'id' => $fissa['id'],
            'visibilita' => $fissa['visibilita'],
            'nome' => $fissa['nome'],
            'importo' => $fissa['importo'],
            'frequenza_mesi' => $fissa['frequenza_mesi'],
            'prima_data' => $fissa['prima_data'],
            'creata_da' => ['id' => $fissa['creata_da'], 'nome' => $this->nomeFlow($fissa['workspace'], $fissa['creata_da'])],
            'creata_il' => self::iso($fissa['creata_il']),
        ];
    }

    // ———————————————————————————————————————— Il conto del mese

    /**
     * flow.bilancio.mostra (BilancioController::mostra, App\Flow\Bilancio): il conto del mese della vista (`visibilita`, obbligatoria; ogni altro parametro è 422 `campo_estraneo`). La comune usa i soli conti e le
     * sole spese fisse comuni, la personale i soli personali di chi chiama. Il mese in corso è quello di Roma. Le medie vogliono tre mesi interi di storico (null con meno); esclusi e trasferimenti non contano mai.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraBilancioFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $this->testi->validaQuery(self::query($richiesta), ['visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow(self::query($richiesta), ['visibilita']));
        $vista = (string) self::query($richiesta)['visibilita'];
        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();

        $date = array_column($this->movimentiBilancioFlow($chi, $vista), 'data');
        $mesi = $date === [] ? 0 : max(0, ($oggi->year * 12 + $oggi->month) - ((int) substr(min($date), 0, 4) * 12 + (int) substr(min($date), 5, 2)));
        $medie = $mesi >= 3 ? $this->medieBilancioFlow($chi, $vista, $oggi) : null;
        $entrate = $this->entrateAlMeseFlow($chi, $vista, $mesi, $medie['entrate'] ?? null);

        $fisse = array_values(array_filter($this->flow['fisse'], fn (array $fissa) => ! $fissa['archiviato'] && $this->fissaDellaVistaFlow($fissa, $chi, $vista)));
        $mensili = array_sum(array_column(array_filter($fisse, fn (array $fissa) => $fissa['frequenza_mesi'] === 1), 'importo'));
        $daConti = $medie === null ? null : array_sum($medie['uscite']);
        $totale = $daConti === null ? null : $daConti + $mensili;
        $daParte = $this->mettiDaParteFlow($fisse, array_values(array_filter($this->ricorrentiDellaVistaFlow($chi, $vista, $oggi), fn (array $voce) => $voce['tipo'] === 'stagionale' && $voce['importo_tipico'] < 0 && $voce['stato'] === 'attiva')), $oggi);
        $conti = array_filter($this->flow['conti'], fn (array $conto) => $this->contoDellaVistaFlow($conto, $chi, $vista));

        return [200, ['data' => [
            'visibilita' => $vista,
            'movimenti_fino_al' => $date === [] ? null : max($date),
            'mesi_di_storico' => $mesi,
            'nel_conto' => [
                'importo' => (int) array_sum(array_filter(array_column($conti, 'saldo'), fn (mixed $saldo) => $saldo !== null)),
                'conti_senza_saldo' => count(array_filter($conti, fn (array $conto) => $conto['saldo'] === null)),
            ],
            'entrate_al_mese' => $entrate,
            'spese_ogni_mese' => ['da_conti' => $daConti, 'altre_spese_fisse' => $mensili, 'totale' => $totale, 'mesi' => min(3, $mesi)],
            'metti_da_parte' => $daParte,
            'ti_restano' => $entrate['importo'] === null || $totale === null ? null : $entrate['importo'] - $totale - $daParte['importo'],
        ]]];
    }

    /**
     * flow.riepiloghi.mostra (RiepiloghiController::mostra, App\Flow\Riepilogo): entrate e uscite del mese `AAAA-MM` della vista (`visibilita`, obbligatoria; ogni altro parametro è 422 `campo_estraneo`; un mese che
     * non è `AAAA-MM` è 404 `non_trovato`, dopo la query). Per categoria col mese prima intero e la media dei tre mesi interi che lo precedono (null con meno di tre mesi interi di storico: dal mese del primo movimento della
     * vista al mese chiesto, escluso); gli ultimi sei mesi fino al mese chiesto. Esclusi e trasferimenti non contano mai; il segno divide entrate e uscite, e le uscite sono positive. In ordine di uscite, poi di entrate.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraRiepilogoFlow(Request $richiesta, string $mese): array
    {
        $chi = $this->conFlow($richiesta);
        $this->testi->validaQuery(self::query($richiesta), ['visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow(self::query($richiesta), ['visibilita']));
        $vista = (string) self::query($richiesta)['visibilita'];

        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $mese) !== 1) {
            throw new Problema('non_trovato');
        }

        $inizio = CarbonImmutable::createFromFormat('!Y-m-d', $mese.'-01', 'Europe/Rome');
        $trasferimenti = array_column(array_filter(FlowCategorie::TUTTE, fn (array $categoria) => $categoria['tipo'] === 'trasferimento'), 'id');
        $movimenti = $this->movimentiBilancioFlow($chi, $vista);
        $date = array_column($movimenti, 'data');
        $storico = $date === [] ? 0 : max(0, ($inizio->year * 12 + $inizio->month) - ((int) substr(min($date), 0, 4) * 12 + (int) substr(min($date), 5, 2)));

        $mesi = [];

        for ($i = 5; $i >= 0; $i--) {
            $mesi[$inizio->subMonthsNoOverflow($i)->format('Y-m')] = ['entrate' => 0, 'uscite' => 0, 'categorie' => []];
        }

        foreach ($movimenti as $movimento) {
            $chiave = substr($movimento['data'], 0, 7);

            if (! isset($mesi[$chiave]) || $movimento['escluso'] || in_array($movimento['categoria_id'], $trasferimenti, true)) {
                continue;
            }

            $categoria = (string) $movimento['categoria_id'];
            $mesi[$chiave]['categorie'][$categoria] ??= ['entrate' => 0, 'uscite' => 0];

            if ($movimento['importo'] > 0) {
                $mesi[$chiave]['entrate'] += $movimento['importo'];
                $mesi[$chiave]['categorie'][$categoria]['entrate'] += $movimento['importo'];
            } elseif ($movimento['importo'] < 0) {
                $mesi[$chiave]['uscite'] -= $movimento['importo'];
                $mesi[$chiave]['categorie'][$categoria]['uscite'] -= $movimento['importo'];
            }
        }

        $uscitePerCategoria = fn (string $chiave, string $categoria): int => ($mesi[$chiave]['categorie'][$categoria]['uscite'] ?? 0);
        $righe = [];

        foreach ($mesi[$mese]['categorie'] as $categoria => $totali) {
            $media = null;

            if ($storico >= 3) {
                $somma = 0;

                for ($i = 1; $i <= 3; $i++) {
                    $somma += $uscitePerCategoria($inizio->subMonthsNoOverflow($i)->format('Y-m'), (string) $categoria);
                }

                $media = intdiv(2 * $somma + 3, 6);
            }

            $righe[] = [
                'categoria_id' => $categoria === '' ? null : (string) $categoria,
                'uscite' => $totali['uscite'],
                'entrate' => $totali['entrate'],
                'mese_precedente' => $uscitePerCategoria($inizio->subMonthNoOverflow()->format('Y-m'), (string) $categoria),
                'media_tre_mesi' => $media,
            ];
        }

        usort($righe, fn (array $a, array $b) => [$b['uscite'], $b['entrate'], (string) $a['categoria_id']] <=> [$a['uscite'], $a['entrate'], (string) $b['categoria_id']]);

        return [200, ['data' => [
            'mese' => $mese,
            'visibilita' => $vista,
            'movimenti_fino_al' => $date === [] ? null : max($date),
            'entrate' => $mesi[$mese]['entrate'],
            'uscite' => $mesi[$mese]['uscite'],
            'resta' => $mesi[$mese]['entrate'] - $mesi[$mese]['uscite'],
            'per_categoria' => $righe,
            'ultimi_mesi' => array_map(fn (string $chiave) => ['mese' => $chiave, 'entrate' => $mesi[$chiave]['entrate'], 'uscite' => $mesi[$chiave]['uscite']], array_keys($mesi)),
        ]]];
    }

    /**
     * flow.previsioni.mostra (PrevisioniController::mostra, App\Flow\Previsione): «Questo mese spenderai» della vista (`visibilita`, obbligatoria; ogni altro parametro è 422 `campo_estraneo`). `mese` deve essere
     * il mese in corso di Roma: un altro è 422 `valore_non_ammesso` sul parametro `mese`, uno che non è `AAAA-MM` 404 `non_trovato`. Per categoria il massimo fra il già speso (da inizio mese a oggi compreso) e la media dei tre mesi
     * interi precedenti; con meno di tre mesi interi di storico `storico_insufficiente` e le stime `null`. Esclusi e trasferimenti non contano mai.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraPrevisioneFlow(Request $richiesta, string $mese): array
    {
        $chi = $this->conFlow($richiesta);
        $this->testi->validaQuery(self::query($richiesta), ['visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow(self::query($richiesta), ['visibilita']));
        $vista = (string) self::query($richiesta)['visibilita'];

        if (preg_match('/^[0-9]{4}-(0[1-9]|1[0-2])$/D', $mese) !== 1) {
            throw new Problema('non_trovato');
        }

        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();

        if ($mese !== $oggi->format('Y-m')) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.in', ['attribute' => 'mese']), 'regola' => Regola::VALORE_NON_AMMESSO, 'parameter' => 'mese']]);
        }

        $movimenti = $this->movimentiBilancioFlow($chi, $vista);
        $date = array_column($movimenti, 'data');
        $storico = $date === [] ? 0 : max(0, ($oggi->year * 12 + $oggi->month) - ((int) substr(min($date), 0, 4) * 12 + (int) substr(min($date), 5, 2)));
        $completa = $storico >= 3;
        $trasferimenti = array_column(array_filter(FlowCategorie::TUTTE, fn (array $categoria) => $categoria['tipo'] === 'trasferimento'), 'id');
        $inizioMese = $oggi->startOfMonth()->format('Y-m-d');
        $inizioMedia = $oggi->startOfMonth()->subMonthsNoOverflow(3)->format('Y-m-d');
        $spese = [];
        $somme = [];

        foreach ($movimenti as $movimento) {
            if ($movimento['escluso'] || $movimento['importo'] >= 0 || in_array($movimento['categoria_id'], $trasferimenti, true)) {
                continue;
            }

            $categoria = (string) $movimento['categoria_id'];

            if ($movimento['data'] >= $inizioMese && $movimento['data'] <= $oggi->format('Y-m-d')) {
                $spese[$categoria] = ($spese[$categoria] ?? 0) - $movimento['importo'];
            } elseif ($movimento['data'] >= $inizioMedia && $movimento['data'] < $inizioMese) {
                $somme[$categoria] = ($somme[$categoria] ?? 0) - $movimento['importo'];
            }
        }

        $medie = $completa ? array_filter(array_map(fn (int $totale) => intdiv(2 * $totale + 3, 6), $somme), fn (int $importo) => $importo > 0) : [];
        $righe = [];

        foreach (array_keys($spese + $medie) as $categoria) {
            $categoria = (string) $categoria;
            $speso = $spese[$categoria] ?? 0;
            $media = $medie[$categoria] ?? 0;
            $righe[] = [
                'categoria_id' => $categoria === '' ? null : $categoria,
                'gia_speso' => $speso,
                'stima' => $completa ? max($speso, $media) : null,
                'base' => $completa ? ($speso >= $media ? 'gia_speso' : 'media_tre_mesi') : null,
            ];
        }

        usort($righe, fn (array $a, array $b) => [$b['stima'] ?? $b['gia_speso'], $b['gia_speso'], (string) $a['categoria_id']] <=> [$a['stima'] ?? $a['gia_speso'], $a['gia_speso'], (string) $b['categoria_id']]);
        $giaSpeso = array_sum($spese);
        $stima = $completa ? array_sum(array_column($righe, 'stima')) : null;

        return [200, ['data' => [
            'mese' => $mese,
            'visibilita' => $vista,
            'stato' => $completa ? 'completa' : 'storico_insufficiente',
            'mesi_di_storico' => $storico,
            'movimenti_fino_al' => $date === [] ? null : max($date),
            'gia_speso' => $giaSpeso,
            'stima_fine_mese' => $stima,
            'ancora_da_spendere' => $stima === null ? null : $stima - $giaSpeso,
            'per_categoria' => $righe,
        ]]];
    }

    /**
     * flow.membri.uscita.mostra (UscitaController::mostra): i conti personali vivi di chi chiama nel workspace, in ordine di banca, nome e id, con i movimenti vivi di ognuno e, uguali su ogni riga, gli avvisi personali e le
     * regole personali che la persona ha. `{utente}` che non è la persona del gettone (anche in maiuscole: vale lo stesso) è 404 `non_trovato`, un id mal formato o inesistente compreso. Sola lettura, `Cache-Control: no-store, private`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraUscitaFlow(Request $richiesta, string $utente): array
    {
        $chi = $this->conFlow($richiesta);

        if (strtolower($utente) !== strtolower($chi['persona'])) {
            throw new Problema('non_trovato');
        }

        $conti = array_values(array_filter($this->flow['conti'], fn (array $conto) => $this->contoDellaVistaFlow($conto, $chi, 'personale')));
        usort($conti, fn (array $a, array $b) => [$a['banca'], $a['nome'], $a['id']] <=> [$b['banca'], $b['nome'], $b['id']]);
        $avvisi = count(array_filter($this->flow['avvisi'], fn (array $avviso) => $avviso['workspace'] === $chi['workspace'] && $avviso['visibilita'] === 'personale' && $avviso['titolare'] === $chi['persona']));
        $regole = count(array_filter($this->flow['regole'], fn (array $regola) => ! $regola['archiviato'] && $regola['workspace'] === $chi['workspace'] && $regola['visibilita'] === 'personale' && $regola['creata_da'] === $chi['persona']));

        return [200, ['data' => ['conti_personali' => array_map(fn (array $conto) => [
            'id' => $conto['id'],
            'banca' => $conto['banca'],
            'nome' => $conto['nome'],
            'movimenti' => count(array_filter($this->flow['movimenti'], fn (array $movimento) => ! $movimento['archiviato'] && $movimento['conto'] === $conto['id'])),
            'avvisi' => $avvisi,
            'regole' => $regole,
        ], $conti)]]];
    }

    /**
     * flow.bilancio.entrate.modifica (BilancioController::entrateModifica): la correzione di «Entri al mese» della vista, o `null` per tornare alle rilevate. Una per workspace nella comune, una per persona nella
     * personale. 200 con `entrate_al_mese`. Il freno si conta prima del corpo.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function correggiEntrateFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);
        $this->frena('freni:flow.bilancio.entrate.modifica:'.$chi['persona'], self::FRENI['flow_bilancio_persona'], self::ORA);
        $campi = $this->testi->validaCorpo($corpo, [
            'visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)],
            'importo' => ['bail', 'present', 'nullable', 'integer:strict', 'between:0,'.self::FLOW_IMPORTO],
        ]);

        $this->flow['correzioni'][$this->chiaveCorrezioneFlow($chi, $campi['visibilita'])] = $campi['importo'] === null ? null : (int) $campi['importo'];
        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $date = array_column($this->movimentiBilancioFlow($chi, $campi['visibilita']), 'data');
        $mesi = $date === [] ? 0 : max(0, ($oggi->year * 12 + $oggi->month) - ((int) substr(min($date), 0, 4) * 12 + (int) substr(min($date), 5, 2)));

        return [200, ['data' => $this->entrateAlMeseFlow($chi, $campi['visibilita'], $mesi, $mesi >= 3 ? $this->medieBilancioFlow($chi, $campi['visibilita'], $oggi)['entrate'] : null)]];
    }

    /**
     * La correzione della vista è una per workspace nella comune e una per persona nella personale.
     *
     * @param  array{persona: string, workspace: string}  $chi
     */
    private function chiaveCorrezioneFlow(array $chi, string $vista): string
    {
        return $chi['workspace'].'|'.$vista.'|'.($vista === 'personale' ? $chi['persona'] : '');
    }

    /**
     * @param  array{persona: string, workspace: string}  $chi
     * @return array{importo: int|null, origine: string, mesi: int}
     */
    private function entrateAlMeseFlow(array $chi, string $vista, int $mesiDiStorico, ?int $rilevate): array
    {
        $corretta = $this->flow['correzioni'][$this->chiaveCorrezioneFlow($chi, $vista)] ?? null;
        $mesi = min(3, $mesiDiStorico);

        return $corretta !== null ? ['importo' => $corretta, 'origine' => 'corrette', 'mesi' => $mesi] : ['importo' => $rilevate, 'origine' => 'rilevate', 'mesi' => $mesi];
    }

    /**
     * @param  array<string, mixed>  $conto
     * @param  array{persona: string, workspace: string}  $chi
     */
    private function contoDellaVistaFlow(array $conto, array $chi, string $vista): bool
    {
        return $conto['workspace'] === $chi['workspace'] && ! $conto['archiviato'] && $conto['visibilita'] === $vista && ($vista === 'comune' || $conto['titolare'] === $chi['persona']);
    }

    /**
     * I movimenti non archiviati dei conti della vista (Storico::movimenti).
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function movimentiBilancioFlow(array $chi, string $vista): array
    {
        $conti = array_keys(array_filter($this->flow['conti'], fn (array $conto) => $this->contoDellaVistaFlow($conto, $chi, $vista)));

        return array_values(array_filter($this->flow['movimenti'], fn (array $movimento) => ! $movimento['archiviato'] && in_array($movimento['conto'], $conti, true)));
    }

    /**
     * La media mensile dei tre mesi interi che precedono il mese in corso (Storico::medie): le entrate in tutto e le uscite per categoria. Esclusi e trasferimenti fuori; il segno divide entrate e uscite; la media
     * è arrotondata al centesimo più vicino, mezzo per eccesso.
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return array{entrate: int, uscite: array<string, int>}
     */
    private function medieBilancioFlow(array $chi, string $vista, CarbonImmutable $oggi): array
    {
        $fine = $oggi->startOfMonth()->format('Y-m-d');
        $inizio = $oggi->startOfMonth()->subMonthsNoOverflow(3)->format('Y-m-d');
        $trasferimenti = array_column(array_filter(FlowCategorie::TUTTE, fn (array $categoria) => $categoria['tipo'] === 'trasferimento'), 'id');
        $entrate = 0;
        $uscite = [];

        foreach ($this->movimentiBilancioFlow($chi, $vista) as $movimento) {
            if ($movimento['escluso'] || $movimento['data'] < $inizio || $movimento['data'] >= $fine || in_array($movimento['categoria_id'], $trasferimenti, true)) {
                continue;
            }

            if ($movimento['importo'] > 0) {
                $entrate += $movimento['importo'];
            } elseif ($movimento['importo'] < 0) {
                $uscite[(string) $movimento['categoria_id']] = ($uscite[(string) $movimento['categoria_id']] ?? 0) - $movimento['importo'];
            }
        }

        $media = fn (int $totale) => intdiv(2 * $totale + 3, 6);

        return ['entrate' => $media($entrate), 'uscite' => array_filter(array_map($media, $uscite), fn (int $importo) => $importo > 0)];
    }

    /**
     * Quanto mettere da parte (Bilancio::mettiDaParte): il massimo, sulle scadenze dei prossimi dodici mesi (da oggi compreso a fra dodici mesi escluso) delle spese non mensili in ordine di data, del cumulato diviso i
     * mesi di calendario fino alla scadenza (almeno 1), per eccesso a 5 €. Una `prima_data` passata si porta avanti di `frequenza_mesi`. Le rilevate sono le ricorrenti stagionali attive (uscite) dalla loro `prossima_data`
     * (passata: nessuna scadenza), senza quelle col nome di una spesa fissa.
     *
     * @param  list<array<string, mixed>>  $fisse
     * @param  list<array<string, mixed>>  $rilevate
     * @return array{importo: int, scadenze: list<array<string, mixed>>, totale_scadenze: int}
     */
    private function mettiDaParteFlow(array $fisse, array $rilevate, CarbonImmutable $oggi): array
    {
        $fine = $oggi->addMonthsNoOverflow(12);
        $scadenze = [];
        $nomiInseriti = [];

        foreach ($fisse as $fissa) {
            $nomiInseriti[$this->nomeRicorrenteFlow($fissa['nome'])] = true;

            if ($fissa['frequenza_mesi'] === 1 || $fissa['prima_data'] === null) {
                continue;
            }

            $prima = CarbonImmutable::parse($fissa['prima_data'], 'Europe/Rome')->startOfDay();

            for ($passo = 0; ($data = $prima->addMonthsNoOverflow($passo * $fissa['frequenza_mesi'])) < $fine; $passo++) {
                if ($data >= $oggi) {
                    $scadenze[] = ['data' => $data, 'nome' => $fissa['nome'], 'id' => $fissa['id'], 'importo' => $fissa['importo'], 'fissa_id' => $fissa['id'], 'ricorrente_id' => null, 'origine' => 'inserita'];
                }
            }
        }

        foreach ($rilevate as $ricorrente) {
            $prima = CarbonImmutable::parse($ricorrente['prossima_data'], 'Europe/Rome')->startOfDay();

            if (isset($nomiInseriti[$ricorrente['chiave']]) || $prima < $oggi) {
                continue;
            }

            for ($passo = 0; ($data = $prima->addMonthsNoOverflow($passo * $ricorrente['cadenza']['valore'])) < $fine; $passo++) {
                $scadenze[] = ['data' => $data, 'nome' => $ricorrente['nome'], 'id' => $ricorrente['id'], 'importo' => -$ricorrente['importo_tipico'], 'fissa_id' => null, 'ricorrente_id' => $ricorrente['id'], 'origine' => 'rilevata'];
            }
        }

        usort($scadenze, fn (array $a, array $b) => [$a['data'], $a['nome'], $a['id']] <=> [$b['data'], $b['nome'], $b['id']]);
        $cumulato = 0;
        $massimo = 0;
        $elenco = [];

        foreach ($scadenze as $scadenza) {
            $mesi = max(1, ($scadenza['data']->year * 12 + $scadenza['data']->month) - ($oggi->year * 12 + $oggi->month));
            $cumulato += $scadenza['importo'];
            $massimo = max($massimo, intdiv($cumulato + $mesi - 1, $mesi));
            $elenco[] = [
                'fissa_id' => $scadenza['fissa_id'], 'ricorrente_id' => $scadenza['ricorrente_id'], 'origine' => $scadenza['origine'], 'nome' => $scadenza['nome'],
                'data' => $scadenza['data']->format('Y-m-d'), 'importo' => $scadenza['importo'], 'mesi' => $mesi,
            ];
        }

        return ['importo' => intdiv($massimo + 499, 500) * 500, 'scadenze' => $elenco, 'totale_scadenze' => $cumulato];
    }

    // ———————————————————————————————————————— Ricorrenti

    /**
     * flow.ricorrenti.elenca (RicorrentiController::elenca): le spese e le entrate che tornano, trovate dai movimenti degli ultimi 36 mesi della vista chiesta (`visibilita`, obbligatoria), per `prossima_data`
     * crescente e poi per `id`, a cursore. Filtri `tipo` e `stato` (`attiva` di default). Il cursore è legato alla vista e ai filtri; ogni altro parametro è 422 `campo_estraneo`. Il giorno è quello di Roma.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaRicorrentiFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $testo = fn (string $nome) => is_string($grezzi[$nome] ?? null) ? $grezzi[$nome] : '';
        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();

        return $this->pagina(
            $richiesta,
            'flow.ricorrenti.elenca:'.$testo('visibilita').':'.$testo('tipo').':'.$testo('stato'),
            'id',
            function () use ($chi, $grezzi, $oggi) {
                $stato = (string) ($grezzi['stato'] ?? 'attiva');
                $tipo = $grezzi['tipo'] ?? null;

                return array_map(
                    fn (array $voce) => array_diff_key($voce, ['movimenti' => 1, 'base' => 1]),
                    array_values(array_filter(
                        $this->ricorrentiDellaVistaFlow($chi, (string) $grezzi['visibilita'], $oggi),
                        fn (array $voce) => $voce['stato'] === $stato && ($tipo === null || $voce['tipo'] === $tipo),
                    )),
                );
            },
            fn (array $voce) => [$voce['prossima_data'], $voce['id']],
            function (string $id) use ($chi, $grezzi, $oggi) {
                foreach ($this->ricorrentiDellaVistaFlow($chi, (string) $grezzi['visibilita'], $oggi) as $voce) {
                    if ($voce['id'] === $id) {
                        return [$voce['prossima_data'], $id];
                    }
                }

                return null;
            },
            false,
            self::LIMITE_MASSIMO,
            null,
            [
                'visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)],
                'tipo' => ['bail', 'sometimes', 'string', Rule::in(self::FLOW_RICORRENTI_TIPI)],
                'stato' => ['bail', 'sometimes', 'string', Rule::in(self::FLOW_RICORRENTI_STATI)],
            ] + $this->estraneiFlow($grezzi, ['limite', 'cursore', 'visibilita', 'tipo', 'stato']),
        );
    }

    /**
     * flow.ricorrenti.mostra (RicorrentiController::mostra): una ricorrente con i suoi movimenti (al più 24, i più recenti prima) e `base`. Un id che non c'è, mal formato, non più rilevato o di un conto personale
     * altrui è 404 `non_trovato`, uguale nei casi.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraRicorrenteFlow(Request $richiesta, string $id): array
    {
        $voce = $this->trovaRicorrenteFlow($this->conFlow($richiesta), $id) ?? throw new Problema('non_trovato');

        return [200, ['data' => $this->dettaglioRicorrenteFlow($voce)], ['ETag' => self::etagCrm(['id' => $voce['id'], 'stato' => $voce['stato']])]];
    }

    /**
     * flow.ricorrenti.modifica (RicorrentiController::modifica): nasconde o riattiva una ricorrente (`stato`). Il freno si conta prima di tutto; una ricorrente che non c'è o è personale di un altro è 404, prima
     * dell'`If-Match` (412 sullo stato cambiato) e del corpo. Ripetere lo stato che c'è non cambia niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaRicorrenteFlow(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $this->frena('freni:flow.ricorrenti.modifica:'.$chi['persona'], self::FRENI['flow_ricorrenti_persona'], self::ORA);
        $voce = $this->trovaRicorrenteFlow($chi, $id) ?? throw new Problema('non_trovato');
        $this->pretendiLaVersioneCrm($richiesta, ['id' => $voce['id'], 'stato' => $voce['stato']]);
        $campi = $this->modificaCrm($corpo, ['stato' => ['bail', 'required', 'string', Rule::in(self::FLOW_RICORRENTI_STATI)]]);

        if ($campi['stato'] === 'nascosta') {
            $this->flow['nascoste'][$voce['id']] = true;
        } else {
            unset($this->flow['nascoste'][$voce['id']]);
        }

        $voce['stato'] = $campi['stato'];

        return [200, ['data' => $this->dettaglioRicorrenteFlow($voce)], ['ETag' => self::etagCrm(['id' => $voce['id'], 'stato' => $voce['stato']])]];
    }

    /**
     * La ricorrente con questo id fra quelle che chi chiama vede (le comuni e le sue personali), o null: un id mal formato, che non c'è, non più rilevato o di un conto personale altrui.
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return array<string, mixed>|null
     */
    private function trovaRicorrenteFlow(array $chi, string $id): ?array
    {
        $id = mb_strtolower($id);

        if (preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $id) !== 1) {
            return null;
        }

        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();

        foreach (self::FLOW_VISIBILITA as $vista) {
            foreach ($this->ricorrentiDellaVistaFlow($chi, $vista, $oggi) as $voce) {
                if ($voce['id'] === $id) {
                    return $voce;
                }
            }
        }

        return null;
    }

    /**
     * La ricorrente con i suoi movimenti (al più 24, i più recenti prima).
     *
     * @param  array<string, mixed>  $voce
     * @return array<string, mixed>
     */
    private function dettaglioRicorrenteFlow(array $voce): array
    {
        return array_replace($voce, ['movimenti' => array_slice($voce['movimenti'], 0, 24)]);
    }

    // ———————————————————————————————————————— Le spese future

    /**
     * flow.futuro.mostra (FuturoController::mostra, App\Flow\Futuro): quanto uscirà nei prossimi 30 giorni, se i soldi bastano e i dodici mesi, mese per mese, della vista chiesta (`visibilita`, obbligatoria; ogni
     * altro parametro è 422 `campo_estraneo`). Le spese sono le ricorrenti rilevate (le `nascoste` no) ripetute lungo la cadenza più le spese fisse inserite; una voce senza giorno conta alla fine del suo periodo.
     * Il mese e il giorno sono quelli di Roma.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraFuturoFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $this->testi->validaQuery(self::query($richiesta), ['visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow(self::query($richiesta), ['visibilita']));

        return [200, ['data' => $this->futuroDellaVistaFlow($chi, (string) self::query($richiesta)['visibilita'], CarbonImmutable::now('Europe/Rome')->startOfDay())]];
    }

    /**
     * Lo schema SpeseFuture di una vista (Futuro::mostra).
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return array<string, mixed>
     */
    private function futuroDellaVistaFlow(array $chi, string $vista, CarbonImmutable $oggi): array
    {
        $date = array_column($this->movimentiBilancioFlow($chi, $vista), 'data');
        $storico = $date === [] ? 0 : max(0, ($oggi->year * 12 + $oggi->month) - ((int) substr(min($date), 0, 4) * 12 + (int) substr(min($date), 5, 2)));
        $primoMese = $oggi->startOfMonth();
        $voci = $this->vociFuturoFlow($chi, $vista, $oggi, $primoMese->addMonthsNoOverflow(12));
        $entro30 = $oggi->addDays(30)->format('Y-m-d');

        $spese30 = 0;
        $entrate30 = 0;
        $mesi = [];

        for ($i = 0; $i < 12; $i++) {
            $mesi[$primoMese->addMonthsNoOverflow($i)->format('Y-m')] = ['totale' => 0, 'rara' => null];
        }

        foreach ($voci as $voce) {
            if ($voce['importo'] > 0) {
                if ($voce['certezza'] !== 'possibile' && $voce['fine'] < $entro30) {
                    $entrate30 += $voce['importo'];
                }

                continue;
            }

            if ($voce['fine'] < $entro30) {
                $spese30 += $voce['importo'];
            }

            $mese = substr($voce['fine'], 0, 7);

            if (! isset($mesi[$mese])) {
                continue;
            }

            $mesi[$mese]['totale'] += $voce['importo'];
            $rara = $mesi[$mese]['rara'];

            if ($voce['rara'] && ($rara === null || [$voce['importo'], $voce['nome'], $voce['riferimento_id']] < [$rara['importo'], $rara['nome'], $rara['id']])) {
                $mesi[$mese]['rara'] = ['nome' => $voce['nome'], 'importo' => $voce['importo'], 'id' => $voce['riferimento_id']];
            }
        }

        $somma = array_sum(array_column($mesi, 'totale'));
        $saldi = array_filter(array_column(array_filter($this->flow['conti'], fn (array $conto) => $this->contoDellaVistaFlow($conto, $chi, $vista)), 'saldo'), fn (mixed $saldo) => $saldo !== null);
        $disponibile = $saldi === [] ? null : (int) array_sum($saldi) + $entrate30;
        $mancano = $disponibile === null ? null : max(0, -$spese30 - $disponibile);
        $elenco = [];

        foreach ($mesi as $mese => $dati) {
            $pesante = $dati['totale'] !== 0 && abs($dati['totale']) * 12 * 1000 >= 1200 * abs($somma);
            $elenco[] = ['mese' => (string) $mese, 'totale' => $dati['totale'], 'motivo' => $pesante && $dati['rara'] !== null ? ['nome' => $dati['rara']['nome'], 'importo' => $dati['rara']['importo']] : null];
        }

        return [
            'visibilita' => $vista,
            'movimenti_fino_al' => $date === [] ? null : max($date),
            'mesi_di_storico' => $storico,
            'prossimi_30_giorni' => ['importo' => $spese30, 'disponibile' => $disponibile, 'mancano' => $mancano === 0 ? null : $mancano],
            'prossimi_12_mesi' => ['importo' => $somma],
            'mesi' => $elenco,
        ];
    }

    /**
     * flow.futuro.calendario.elenca (FuturoController::calendarioElenca): le spese dei prossimi 60 giorni della vista chiesta, per data (le voci col solo periodo per l'inizio del periodo), poi per nome e
     * `riferimento_id`, a cursore legato alla vista. Senza entrate. Ogni altro parametro che non è `limite`, `cursore` o `visibilita` è 422 `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCalendarioFuturoFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $vista = is_string($grezzi['visibilita'] ?? null) ? $grezzi['visibilita'] : '';

        [$stato, $corpo] = $this->pagina(
            $richiesta,
            'flow.futuro.calendario.elenca:'.$vista,
            'chiave',
            function () use ($chi, $vista, $oggi) {
                $fine = $oggi->addDays(60);

                return array_map(
                    fn (array $voce) => $voce + ['chiave' => $voce['ordine'].'|'.$voce['nome']."\x1f".$voce['riferimento_id']],
                    array_values(array_filter($this->vociFuturoFlow($chi, $vista, $oggi, $fine), fn (array $voce) => $voce['importo'] < 0 && $voce['ordine'] < $fine->format('Y-m-d'))),
                );
            },
            fn (array $voce) => [$voce['chiave']],
            fn (string $chiave) => str_contains($chiave, '|') ? [$chiave] : null,
            false,
            self::LIMITE_MASSIMO,
            null,
            ['visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)]] + $this->estraneiFlow($grezzi, ['limite', 'cursore', 'visibilita']),
        );

        $corpo['data'] = array_map(fn (array $voce) => [
            'data' => $voce['data'], 'periodo' => $voce['periodo'], 'nome' => $voce['nome'], 'categoria_id' => $voce['categoria_id'], 'importo' => $voce['importo'], 'certezza' => $voce['certezza'],
            'base' => $voce['base'], 'origine' => $voce['origine'], 'riferimento_id' => $voce['riferimento_id'],
        ], $corpo['data']);

        return [$stato, $corpo];
    }

    /**
     * La certezza di una ricorrente oggi (Futuro::certezza): `certa` (una `fissa` o una `bolletta` con almeno 6 occorrenze e l'ultima entro 1,5 cadenze), `possibile` (una `stagionale`, un'`abitudine`, o non vista da più di
     * 2 cadenze), `stima` il resto. La usano le voci del futuro e l'avviso «un addebito non è arrivato».
     *
     * @param  array<string, mixed>  $ricorrente
     */
    private function certezzaRicorrenteFlow(array $ricorrente, CarbonImmutable $oggi): string
    {
        $valore = $ricorrente['cadenza']['valore'];
        $cadenzaGiorni = $ricorrente['cadenza']['unita'] === 'mesi' ? $valore * 30.4375 : $valore * 7;
        $giorni = intdiv((int) strtotime($oggi->format('Y-m-d').' UTC') - (int) strtotime($ricorrente['movimenti'][0]['data'].' UTC'), 86400);

        return match (true) {
            $ricorrente['tipo'] === 'stagionale', $ricorrente['tipo'] === 'abitudine', $giorni > 2 * $cadenzaGiorni => 'possibile',
            in_array($ricorrente['tipo'], ['fissa', 'bolletta'], true) && $ricorrente['occorrenze'] >= 6 && $giorni <= 1.5 * $cadenzaGiorni => 'certa',
            default => 'stima',
        };
    }

    /**
     * Le voci delle spese future da oggi (compreso) a `$fine` (escluso), ogni origine, entrate comprese (App\Flow\Futuro::voci): le spese fisse inserite della vista e le ricorrenti rilevate che non sono
     * `nascoste` e non hanno il nome di una fissa. Ogni voce ha `data` o `periodo`, `fine` (la data o la fine del periodo), `ordine` (la data o l'inizio del periodo) e `rara` (non torna ogni mese).
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function vociFuturoFlow(array $chi, string $vista, CarbonImmutable $oggi, CarbonImmutable $fine): array
    {
        $fisse = array_values(array_filter($this->flow['fisse'], fn (array $fissa) => ! $fissa['archiviato'] && $this->fissaDellaVistaFlow($fissa, $chi, $vista)));
        $voci = [];
        $nomi = [];

        foreach ($fisse as $fissa) {
            $nomi[$this->nomeRicorrenteFlow($fissa['nome'])] = true;
            $comune = [
                'origine' => 'fissa', 'riferimento_id' => $fissa['id'], 'nome' => $fissa['nome'], 'categoria_id' => null, 'importo' => -$fissa['importo'], 'certezza' => 'certa',
                'base' => ['tipo' => 'inserita', 'mesi' => $fissa['frequenza_mesi']], 'rara' => $fissa['frequenza_mesi'] !== 1,
            ];

            if ($fissa['prima_data'] === null) {
                for ($mese = $oggi->startOfMonth(); $mese < $fine; $mese = $mese->addMonthNoOverflow()) {
                    $da = max($oggi, $mese);
                    $voci[] = $comune + ['data' => null, 'periodo' => ['da' => $da->format('Y-m-d'), 'a' => $mese->endOfMonth()->format('Y-m-d')], 'fine' => $mese->endOfMonth()->format('Y-m-d'), 'ordine' => $da->format('Y-m-d')];
                }

                continue;
            }

            $prima = CarbonImmutable::parse($fissa['prima_data'], 'Europe/Rome')->startOfDay();

            for ($passo = 0; ($data = $prima->addMonthsNoOverflow($passo * $fissa['frequenza_mesi'])) < $fine; $passo++) {
                if ($data >= $oggi) {
                    $voci[] = $comune + ['data' => $data->format('Y-m-d'), 'periodo' => null, 'fine' => $data->format('Y-m-d'), 'ordine' => $data->format('Y-m-d')];
                }
            }
        }

        foreach ($this->ricorrentiDellaVistaFlow($chi, $vista, $oggi) as $ricorrente) {
            if ($ricorrente['stato'] === 'nascosta' || isset($nomi[$ricorrente['chiave']])) {
                continue;
            }

            $valore = $ricorrente['cadenza']['valore'];
            $inMesi = $ricorrente['cadenza']['unita'] === 'mesi';
            $prima = CarbonImmutable::parse($ricorrente['prossima_data'], 'Europe/Rome')->startOfDay();
            $occorrenza = fn (int $passo): CarbonImmutable => $inMesi ? $prima->addMonthsNoOverflow($passo * $valore) : $prima->addWeeks($passo * $valore);
            $certezza = $this->certezzaRicorrenteFlow($ricorrente, $oggi);
            $comune = [
                'origine' => 'ricorrente', 'riferimento_id' => $ricorrente['id'], 'nome' => $ricorrente['nome'], 'categoria_id' => $ricorrente['categoria_id'], 'importo' => $ricorrente['importo_tipico'],
                'certezza' => $certezza, 'base' => $ricorrente['base'], 'rara' => $inMesi && $valore >= 3,
            ];
            $passo = 0;

            while ($occorrenza($passo) < $oggi) {
                $passo++;
            }

            if ($passo === 1 && ($attesa = min($occorrenza(1)->subDay(), $oggi->endOfMonth()->startOfDay())) >= $oggi) {
                $voci[] = $comune + ['data' => null, 'periodo' => ['da' => $oggi->format('Y-m-d'), 'a' => $attesa->format('Y-m-d')], 'fine' => $attesa->format('Y-m-d'), 'ordine' => $oggi->format('Y-m-d')];
            }

            for (; ($data = $occorrenza($passo)) < $fine; $passo++) {
                $voci[] = $comune + ['data' => $data->format('Y-m-d'), 'periodo' => null, 'fine' => $data->format('Y-m-d'), 'ordine' => $data->format('Y-m-d')];
            }
        }

        return $voci;
    }

    /**
     * Le ricorrenti di una vista, nella forma dello schema Ricorrente (Ricorrenti::rileva e Ricorrenti::forma del backoffice): i movimenti degli ultimi 36 mesi, senza esclusi, trasferimenti né importi a zero,
     * raggruppati per conto, segno e nome normalizzato; un gruppo con almeno 3 occorrenze (4 per un'abitudine) a cadenza regolare è una ricorrente.
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return list<array<string, mixed>>
     */
    private function ricorrentiDellaVistaFlow(array $chi, string $vista, CarbonImmutable $oggi): array
    {
        $inizio = $oggi->subMonthsNoOverflow(36)->format('Y-m-d');
        $fine = $oggi->format('Y-m-d');
        $trasferimenti = array_column(array_filter(FlowCategorie::TUTTE, fn (array $categoria) => $categoria['tipo'] === 'trasferimento'), 'id');
        $movimenti = array_filter(
            $this->movimentiBilancioFlow($chi, $vista),
            fn (array $movimento) => ! $movimento['escluso'] && $movimento['importo'] !== 0 && $movimento['data'] >= $inizio && $movimento['data'] <= $fine && ! in_array($movimento['categoria_id'], $trasferimenti, true),
        );
        usort($movimenti, fn (array $uno, array $altro) => [$uno['data'], $uno['id']] <=> [$altro['data'], $altro['id']]);

        $gruppi = [];

        foreach ($movimenti as $movimento) {
            $nome = $this->nomeRicorrenteFlow((string) $movimento['descrizione']);

            if ($nome === '') {
                continue;
            }

            $segno = $movimento['importo'] > 0 ? 'entrata' : 'uscita';
            $gruppi[$movimento['conto'].'|'.$segno.'|'.$nome]['dati'] = [$movimento['conto'], $segno, $nome];
            $gruppi[$movimento['conto'].'|'.$segno.'|'.$nome]['movimenti'][] = $movimento;
            $gruppi[$movimento['conto'].'|'.$segno.'|'.$nome]['ultima'] = (string) $movimento['descrizione'];
        }

        $voci = [];

        foreach ($gruppi as $gruppo) {
            $voce = $this->valutaRicorrenteFlow($gruppo['dati'], $gruppo['movimenti'], $chi['workspace'], $vista, $gruppo['ultima']);

            if ($voce !== null) {
                $voci[] = $voce;
            }
        }

        usort($voci, fn (array $uno, array $altro) => strcmp($uno['prossima_data'].$uno['id'], $altro['prossima_data'].$altro['id']));

        return $voci;
    }

    /** Il nome di un movimento per una ricorrente (Ricorrenti::nome): minuscolo, senza punteggiatura né parole con una cifra né parole di riferimento, al più 80 caratteri. */
    private function nomeRicorrenteFlow(string $descrizione): string
    {
        $testo = preg_replace('/[^\p{L}\p{N}]+/u', ' ', mb_strtolower($descrizione, 'UTF-8')) ?? '';
        $parole = array_filter(
            explode(' ', trim($testo)),
            fn (string $parola) => $parola !== '' && ! preg_match('/\p{N}/u', $parola) && ! in_array($parola, self::FLOW_RICORRENTI_RIFERIMENTI, true),
        );

        return mb_substr(implode(' ', $parole), 0, 80);
    }

    /**
     * Un gruppo di movimenti, dal più vecchio, che è una ricorrente o non lo è (Ricorrenti::valuta).
     *
     * @param  array{string, string, string}  $dati  conto, segno, nome
     * @param  list<array<string, mixed>>  $movimenti
     * @return array<string, mixed>|null
     */
    private function valutaRicorrenteFlow(array $dati, array $movimenti, string $workspace, string $vista, string $ultima): ?array
    {
        $occorrenze = count($movimenti);

        if ($occorrenze < 3) {
            return null;
        }

        $giorni = array_map(fn (array $movimento) => intdiv((int) strtotime($movimento['data'].' UTC'), 86400), $movimenti);
        $intervalli = [];

        for ($i = 1; $i < $occorrenze; $i++) {
            $intervalli[] = $giorni[$i] - $giorni[$i - 1];
        }

        $cadenza = $this->medianaFlow($intervalli);

        if ($cadenza <= 0 || max(array_map(fn (int $intervallo) => abs($intervallo - $cadenza), $intervalli)) > 0.15 * $cadenza) {
            return null;
        }

        $importi = array_column($movimenti, 'importo');
        $tipico = (int) round($this->medianaFlow($importi));
        $ultimo = $movimenti[$occorrenze - 1];
        $ultimaData = CarbonImmutable::createFromFormat('Y-m-d', $ultimo['data'], 'Europe/Rome')->startOfDay();

        if ($cadenza < 26) {
            $settimane = (int) round($cadenza / 7);

            if ($settimane < 1 || $occorrenze < 4) {
                return null;
            }

            $tipo = 'abitudine';
            $forma = ['valore' => $settimane, 'unita' => 'settimane'];
            $prossima = $ultimaData->addWeeks($settimane);
            $base = ['tipo' => 'cadenza_settimanale', 'settimane' => $settimane];
        } else {
            $mesi = max(1, (int) round($cadenza / 30.4375));
            $fissa = max(array_map(fn (int $importo) => abs($importo - $tipico), $importi)) <= 0.02 * abs($tipico);
            $tipo = match (true) {
                $mesi >= 3 => 'stagionale',
                $mesi === 1 && $fissa => 'fissa',
                default => 'bolletta',
            };
            $forma = ['valore' => $mesi, 'unita' => 'mesi'];
            $prossima = $ultimaData->addMonthsNoOverflow($mesi);
            $base = match (true) {
                $mesi === 12 => ['tipo' => 'annuale', 'anni' => 1],
                $mesi >= 3 => ['tipo' => 'stagionale', 'mesi' => $mesi],
                default => ['tipo' => $fissa ? 'stesso_importo' : 'importo_variabile', 'mesi' => $mesi * $occorrenze],
            };
        }

        $categorie = [];

        foreach ($movimenti as $movimento) {
            if ($movimento['categoria_id'] !== null) {
                $categorie[(string) $movimento['categoria_id']] = ($categorie[(string) $movimento['categoria_id']] ?? 0) + 1;
            }
        }

        arsort($categorie);
        $id = $this->idRicorrenteFlow($workspace, $dati[0], $dati[1], $dati[2]);

        return [
            'id' => $id,
            'nome' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $ultima)), 0, 80),
            'chiave' => $dati[2],
            'categoria_id' => array_key_first($categorie),
            'tipo' => $tipo,
            'importo_tipico' => $tipico,
            'cadenza' => $forma,
            'prossima_data' => $prossima->format('Y-m-d'),
            'occorrenze' => $occorrenze,
            'ultimo_importo' => $ultimo['importo'],
            'variazione' => $ultimo['importo'] - $tipico,
            'conto_id' => $dati[0],
            'visibilita' => $vista,
            'stato' => isset($this->flow['nascoste'][$id]) ? 'nascosta' : 'attiva',
            'movimenti' => array_reverse(array_map(fn (array $movimento) => ['id' => $movimento['id'], 'data' => $movimento['data'], 'importo' => $movimento['importo']], $movimenti)),
            'base' => $base,
        ];
    }

    /** L'id di una ricorrente (Ricorrenti::id): un ULID che deriva da workspace, conto, segno e nome normalizzato. */
    private function idRicorrenteFlow(string $workspace, string $conto, string $segno, string $nome): string
    {
        $bit = '00';

        foreach (str_split(substr(hash('sha256', implode('|', ['flow.ricorrente', $workspace, $conto, $segno, $nome]), true), 0, 16)) as $byte) {
            $bit .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $id = '';

        for ($posto = 0; $posto < 26; $posto++) {
            $id .= '0123456789abcdefghjkmnpqrstvwxyz'[(int) bindec(substr($bit, $posto * 5, 5))];
        }

        return $id;
    }

    // ———————————————————————————————————————— Avvisi

    /**
     * flow.avvisi.elenca (AvvisiController::elenca): gli avvisi della vista chiesta (`visibilita`, obbligatoria) che la persona vede, dal più nuovo (per `id`), a cursore. `stato=aperti` (di default) porta quelli che la persona non ha
     * chiuso, `chiusi` gli altri; un avviso silenziato è chiuso per chi l'ha silenziato. Ogni altro parametro è 422 `campo_estraneo`. Il compito `flow:avvisi` del backoffice li scrive una volta al giorno: il finto li scrive alla prima
     * lettura (elenca, mostra o modifica) dopo che i dati li giustificano, e da lì restano, come le righe del backoffice (non emette la notifica in campanella, come non emette gli eventi).
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaAvvisiFlow(Request $richiesta): array
    {
        $chi = $this->conFlow($richiesta);
        $grezzi = self::query($richiesta);
        $testo = fn (string $nome) => is_string($grezzi[$nome] ?? null) ? $grezzi[$nome] : '';

        return $this->pagina(
            $richiesta,
            'flow.avvisi.elenca:'.$testo('visibilita').':'.$testo('stato'),
            'id',
            function () use ($chi, $grezzi) {
                $this->generaAvvisiFlow($chi['workspace']);
                $chiusi = ($grezzi['stato'] ?? 'aperti') === 'chiusi';
                $voci = [];

                foreach ($this->flow['avvisi'] as $avviso) {
                    if ($avviso['visibilita'] !== $grezzi['visibilita'] || ! $this->avvisoVisibileFlow($avviso, $chi)) {
                        continue;
                    }

                    $stato = $this->statoAvvisoFlow($avviso, $chi);

                    if (($stato['stato'] === 'chiuso') === $chiusi) {
                        $voci[] = $this->voceAvvisoFlow($avviso, $stato);
                    }
                }

                return $voci;
            },
            fn (array $voce) => [$voce['id']],
            fn (string $id) => [$id],
            true,
            self::LIMITE_MASSIMO,
            null,
            [
                'visibilita' => ['bail', 'required', 'string', Rule::in(self::FLOW_VISIBILITA)],
                'stato' => ['bail', 'sometimes', 'string', Rule::in(['aperti', 'chiusi'])],
            ] + $this->estraneiFlow($grezzi, ['limite', 'cursore', 'visibilita', 'stato']),
        );
    }

    /**
     * flow.avvisi.mostra (AvvisiController::mostra): un avviso, con l'`ETag` del suo stato per chi chiama. Un `id` che non c'è, mal formato o di un conto personale altrui è 404 `non_trovato`, uguale nei tre casi.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function mostraAvvisoFlow(Request $richiesta, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $this->generaAvvisiFlow($chi['workspace']);
        $avviso = $this->trovaAvvisoFlow($chi, $id) ?? throw new Problema('non_trovato');
        $voce = $this->voceAvvisoFlow($avviso, $this->statoAvvisoFlow($avviso, $chi));

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /**
     * flow.avvisi.modifica (AvvisiController::modifica): cambia lo stato (`letto` o `chiuso`) e il silenzio (`silenzia`) di questa persona sull'avviso; gli altri non lo vedono cambiare. `silenzia: true` chiude anche l'avviso (con
     * `stato: letto` è 422), `false` toglie il silenzio. Il freno si conta prima di tutto; un avviso che non c'è o di un conto personale altrui è 404, prima dell'`If-Match` (letto prima del corpo: formato sbagliato 422) e del corpo
     * (almeno un campo); la versione vecchia è 412 dopo il corpo. `If-Match` è facoltativo.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaAvvisoFlow(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conFlow($richiesta);
        $this->frena('freni:flow.avvisi.modifica:'.$chi['persona'], self::FRENI['flow_avvisi_persona'], self::ORA);
        $this->generaAvvisiFlow($chi['workspace']);
        $avviso = $this->trovaAvvisoFlow($chi, $id) ?? throw new Problema('non_trovato');
        $se = $this->ifMatchFlow($richiesta);
        $campi = $this->modificaCrm($corpo, [
            'stato' => ['bail', 'sometimes', 'string', Rule::in(($corpo['silenzia'] ?? null) === true ? ['chiuso'] : ['letto', 'chiuso'])],
            'silenzia' => ['bail', 'sometimes', 'boolean:strict'],
        ]);
        $prima = $this->statoAvvisoFlow($avviso, $chi);

        if ($se !== null && ! self::trovaEtagCrm($se, self::etagAvvisoFlow($avviso['id'], $prima['stato'], $prima['silenziato']), false)) {
            throw new Problema('precondizione_fallita');
        }

        if ($campi['silenzia'] ?? false) {
            $campi['stato'] = 'chiuso';
        }

        if (isset($campi['stato'])) {
            $this->flow['avvisiStati'][$avviso['id']][$chi['persona']] = $campi['stato'];
        }

        if (isset($campi['silenzia'])) {
            $chiave = $this->chiaveSilenzioFlow($avviso, $chi);

            if ($campi['silenzia']) {
                $this->flow['avvisiSilenzi'][$chiave] = true;
            } else {
                unset($this->flow['avvisiSilenzi'][$chiave]);
            }
        }

        $voce = $this->voceAvvisoFlow($avviso, $this->statoAvvisoFlow($avviso, $chi));

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /** L'`If-Match` di una scrittura (Precondizione::daRichiesta): il valore, o null senza header; né `*` né una lista di entity-tag è 422 sull'header. */
    private function ifMatchFlow(Request $richiesta): ?string
    {
        $psr = $richiesta->toPsrRequest();

        if (! $psr->hasHeader('If-Match')) {
            return null;
        }

        $valore = trim($psr->getHeaderLine('If-Match'));
        $tag = '(?:W/)?"[\x21\x23-\x7E\x80-\xFF]*"';

        if ($valore !== '*' && preg_match('~^'.$tag.'(?:\s*,\s*'.$tag.')*$~D', $valore) !== 1) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.if_match'), 'regola' => Regola::FORMATO, 'header' => 'If-Match']]);
        }

        return $valore;
    }

    /** La versione di un avviso per `If-Match` (Avvisi::versione): dipende dal suo `id` e dallo stato di questa persona, mai da quello di un'altra. */
    private static function etagAvvisoFlow(string $id, string $stato, bool $silenziato): string
    {
        return '"'.md5('avviso-1|'.$id.'|'.$stato.'|'.($silenziato ? '1' : '0')).'"';
    }

    /**
     * Lo schema Avviso (Avvisi::forma).
     *
     * @param  array<string, mixed>  $avviso
     * @param  array{stato: string, silenziato: bool}  $stato
     * @return array<string, mixed>
     */
    private function voceAvvisoFlow(array $avviso, array $stato): array
    {
        return [
            'id' => $avviso['id'],
            'tipo' => $avviso['tipo'],
            'riferimento' => $avviso['riferimento'],
            'importo' => $avviso['importo'],
            'mese' => $avviso['mese'],
            'creato_il' => self::iso($avviso['creato_il']),
            'stato' => $stato['stato'],
            'silenziato' => $stato['silenziato'],
            'etag' => self::etagAvvisoFlow($avviso['id'], $stato['stato'], $stato['silenziato']),
        ];
    }

    /**
     * Lo stato e il silenzio di una persona su un avviso (Avvisi::stati, senza le notifiche): lo stato scritto vince; senza, un avviso silenziato è `chiuso`, gli altri `aperto`.
     *
     * @param  array<string, mixed>  $avviso
     * @param  array{persona: string, workspace: string}  $chi
     * @return array{stato: string, silenziato: bool}
     */
    private function statoAvvisoFlow(array $avviso, array $chi): array
    {
        $silenziato = isset($this->flow['avvisiSilenzi'][$this->chiaveSilenzioFlow($avviso, $chi)]);

        return ['stato' => $this->flow['avvisiStati'][$avviso['id']][$chi['persona']] ?? ($silenziato ? 'chiuso' : 'aperto'), 'silenziato' => $silenziato];
    }

    /** @param  array<string, mixed>  $avviso */
    private function chiaveSilenzioFlow(array $avviso, array $chi): string
    {
        return implode('|', [$chi['workspace'], $chi['persona'], $avviso['tipo'], (string) $avviso['riferimento']]);
    }

    /** Un avviso lo vede ogni membro se è comune, solo il titolare se è personale (e sempre nel suo workspace). */
    private function avvisoVisibileFlow(array $avviso, array $chi): bool
    {
        return $avviso['workspace'] === $chi['workspace'] && ($avviso['titolare'] === null || $avviso['titolare'] === $chi['persona']);
    }

    /**
     * L'avviso con questo `id` fra quelli che chi chiama vede (Avvisi::trova), o null: un `id` mal formato, che non c'è o di un conto personale altrui, tutti uguali.
     *
     * @return array<string, mixed>|null
     */
    private function trovaAvvisoFlow(array $chi, string $id): ?array
    {
        $id = mb_strtolower($id);

        if (preg_match('/^[0-7][0-9a-hjkmnp-tv-z]{25}$/', $id) !== 1) {
            return null;
        }

        $avviso = $this->flow['avvisi'][$id] ?? null;

        return $avviso !== null && $this->avvisoVisibileFlow($avviso, $chi) ? $avviso : null;
    }

    /**
     * Scrive gli avvisi di oggi del workspace (Avvisi::genera): la vista comune e quella di ogni titolare di conti personali. Uno con la stessa chiave (workspace, vista, titolare, tipo, riferimento, mese) non si riscrive.
     */
    private function generaAvvisiFlow(string $workspace): void
    {
        $oggi = CarbonImmutable::now('Europe/Rome')->startOfDay();
        $titolari = [];

        foreach ($this->flow['conti'] as $conto) {
            if ($conto['workspace'] === $workspace && ! $conto['archiviato'] && $conto['visibilita'] === 'personale') {
                $titolari[(string) $conto['titolare']] = true;
            }
        }

        ksort($titolari);
        $viste = [['comune', null]];

        foreach (array_keys($titolari) as $titolare) {
            $viste[] = ['personale', (string) $titolare];
        }

        $chiavi = array_column($this->flow['avvisi'], 'chiave');

        foreach ($viste as [$vista, $titolare]) {
            foreach ($this->avvisiTrovatiFlow(['persona' => (string) $titolare, 'workspace' => $workspace], $vista, $oggi) as $trovato) {
                $chiave = hash('sha256', implode('|', [$workspace, $vista, (string) $titolare, $trovato['tipo'], (string) $trovato['riferimento'], $trovato['mese']]));

                if (in_array($chiave, $chiavi, true)) {
                    continue;
                }

                $id = self::id();
                $this->flow['avvisi'][$id] = $trovato + ['id' => $id, 'workspace' => $workspace, 'visibilita' => $vista, 'titolare' => $titolare, 'chiave' => $chiave, 'creato_il' => self::istanteFlow()];
                $chiavi[] = $chiave;
            }
        }
    }

    /**
     * Ciò che merita un avviso in una vista, oggi (Avvisi::trovati), con le soglie di `config/flow.php`: una categoria che spende nel mese almeno il 30 % e 50 € sopra la media dei tre mesi interi precedenti (con almeno tre mesi di storico);
     * una ricorrente di spesa `certa` non nascosta la cui `prossima_data` è passata da più di 5 giorni; i prossimi 30 giorni che dicono `mancano`; un conto il cui ultimo movimento ha più di 35 giorni.
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @return list<array{tipo: string, riferimento: string|null, mese: string, importo: int|null}>
     */
    private function avvisiTrovatiFlow(array $chi, string $vista, CarbonImmutable $oggi): array
    {
        $mese = $oggi->format('Y-m');
        $movimenti = $this->movimentiBilancioFlow($chi, $vista);
        $date = array_column($movimenti, 'data');
        $copertura = $date === [] ? 0 : max(0, ($oggi->year * 12 + $oggi->month) - ((int) substr(min($date), 0, 4) * 12 + (int) substr(min($date), 5, 2)));
        $trovati = [];

        if ($copertura >= 3) {
            $medie = $this->medieBilancioFlow($chi, $vista, $oggi)['uscite'];
            $trasferimenti = array_column(array_filter(FlowCategorie::TUTTE, fn (array $categoria) => $categoria['tipo'] === 'trasferimento'), 'id');
            $spese = [];

            foreach ($movimenti as $movimento) {
                if (! $movimento['escluso'] && $movimento['importo'] < 0 && $movimento['data'] >= $oggi->startOfMonth()->format('Y-m-d') && $movimento['data'] <= $oggi->format('Y-m-d') && ! in_array($movimento['categoria_id'], $trasferimenti, true)) {
                    $spese[(string) $movimento['categoria_id']] = ($spese[(string) $movimento['categoria_id']] ?? 0) - $movimento['importo'];
                }
            }

            foreach ($spese as $categoria => $spesa) {
                $media = $medie[(string) $categoria] ?? 0;

                if ((string) $categoria !== '' && $media > 0 && $spesa * 100 >= $media * 130 && $spesa - $media >= 5000) {
                    $trovati[] = ['tipo' => 'spesa_fuori_dal_solito', 'riferimento' => (string) $categoria, 'mese' => $mese, 'importo' => -$spesa];
                }
            }
        }

        $limite = $oggi->subDays(5)->format('Y-m-d');

        foreach ($this->ricorrentiDellaVistaFlow($chi, $vista, $oggi) as $ricorrente) {
            if ($ricorrente['importo_tipico'] < 0 && $ricorrente['stato'] !== 'nascosta' && $ricorrente['prossima_data'] < $limite && $this->certezzaRicorrenteFlow($ricorrente, $oggi) === 'certa') {
                $trovati[] = ['tipo' => 'addebito_non_arrivato', 'riferimento' => $ricorrente['id'], 'mese' => substr($ricorrente['prossima_data'], 0, 7), 'importo' => $ricorrente['importo_tipico']];
            }
        }

        $mancano = $this->futuroDellaVistaFlow($chi, $vista, $oggi)['prossimi_30_giorni']['mancano'];

        if (is_int($mancano) && $mancano > 0) {
            $trovati[] = ['tipo' => 'saldo_non_basta', 'riferimento' => null, 'mese' => $mese, 'importo' => -$mancano];
        }

        $fermo = $oggi->subDays(35)->format('Y-m-d');
        $conti = array_keys(array_filter($this->flow['conti'], fn (array $conto) => $this->contoDellaVistaFlow($conto, $chi, $vista)));
        sort($conti);

        foreach ($conti as $conto) {
            $ultimi = array_column(array_filter($movimenti, fn (array $movimento) => $movimento['conto'] === $conto), 'data');

            if ($ultimi !== [] && max($ultimi) < $fermo) {
                $trovati[] = ['tipo' => 'estratto_fermo', 'riferimento' => (string) $conto, 'mese' => $mese, 'importo' => null];
            }
        }

        return $trovati;
    }

    /**
     * La mediana: il valore di mezzo, o la media dei due di mezzo.
     *
     * @param  non-empty-list<int>  $valori
     */
    private function medianaFlow(array $valori): float
    {
        sort($valori);
        $mezzo = intdiv(count($valori), 2);

        return count($valori) % 2 === 1 ? (float) $valori[$mezzo] : ($valori[$mezzo - 1] + $valori[$mezzo]) / 2;
    }

    // ———————————————————————————————————————— Cancellazione (#3597)

    /**
     * flow.cancellazioni.crea (CancellazioniController::crea): la persona del gettone cancella per sempre i suoi dati di Finanze in questo workspace, la deroga di Luciano (10/11) a «mai cancellare», solo
     * per Finanze e solo a richiesta della persona. Il corpo è `{conferma}`, il nome del workspace com'è, lettera per lettera: un valore diverso, mancante o che non è una stringa è 422 `dati_non_validi` su
     * `#/conferma` e non cambia niente. Il freno (5 all'ora per persona) si conta prima del corpo, anche per una conferma sbagliata. Risponde 200 con le sole conte, senza `data`, e
     * `Cache-Control: no-store, private`. Accetta `Idempotency-Key`. Il finto non scrive lo storico né gli eventi.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaCancellazioneFlow(Request $richiesta, array $corpo): array
    {
        $chi = $this->conFlow($richiesta);

        return $this->conIdempotenza($richiesta, 'flow.cancellazioni.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->frena('freni:flow.cancellazioni.crea:'.$chi['persona'], self::FRENI['flow_cancellazioni_persona'], self::ORA);

            return [200, $this->cancellaConLaConfermaFlow($corpo, $chi['workspace'], $chi['persona']), ['Cache-Control' => 'no-store, private']];
        });
    }

    /**
     * io.finanze.conservati.elenca (IoFinanzeController::conservatiElenca): i workspace dove la persona del gettone dell'accesso ha ancora dati di Finanze (una riga che porta la sua id, anche archiviata),
     * `{id, nome}` per id, a cursore; anche quelli da cui è uscita. Niente importi, niente conte. Un gettone di workspace è 403 `gettone_con_workspace`. `limite` e `cursore` si controllano prima del freno
     * (60 all'ora per persona), che non conta una richiesta rifiutata. `Cache-Control: no-store, private`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function elencaFinanzeConservateFlow(Request $richiesta): array
    {
        $persona = $this->personaDelGettoneDellAccessoFlow($richiesta);
        $voci = array_map(fn (string $id) => ['id' => $id, 'nome' => $this->workspace[$id]['nome']], array_values(array_filter($this->workspaceConDatiFlow($persona), fn (string $id) => isset($this->workspace[$id]))));

        [$stato, $corpo] = $this->pagina($richiesta, 'io.finanze.conservati.elenca', 'id', $voci, fn (array $voce) => [$voce['id']], fn (string $id) => [$id]);
        $this->frena('freni:io.finanze.conservati.elenca:'.$persona, self::FRENI['io_finanze_conservati_per_persona'], self::ORA);

        return [$stato, $corpo, ['Cache-Control' => 'no-store, private']];
    }

    /**
     * io.finanze.cancellazione.crea (IoFinanzeController::cancellazioneCrea): la stessa cancellazione di `flow.cancellazioni.crea`, col gettone dell'accesso, in un workspace dove la persona è stata membro,
     * ora o in passato. Nell'ordine del backoffice: il gettone (403 `gettone_con_workspace`), il freno (5 all'ora), il corpo (422 su `#/conferma`), poi un workspace dove non è mai stata membro o che non c'è
     * (404 `non_trovato`, lo stesso), poi la conferma. Accetta `Idempotency-Key`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaCancellazioneDelWorkspaceFlow(Request $richiesta, array $corpo, string $workspace): array
    {
        $persona = $this->personaDelGettoneDellAccessoFlow($richiesta);
        $workspace = strtolower($workspace);

        return $this->conIdempotenza($richiesta, 'io.finanze.cancellazione.crea', ['persona' => $persona, 'workspace' => $workspace], $corpo, function () use ($persona, $workspace, $corpo) {
            $this->frena('freni:io.finanze.cancellazione.crea:'.$persona, self::FRENI['io_finanze_cancellazione_per_persona'], self::ORA);
            $this->testi->validaCorpo($corpo, ['conferma' => ['bail', 'required', 'string', 'max:200']]);

            if (! isset($this->workspace[$workspace]) || ! (isset($this->membri[$workspace][$persona]) || isset($this->flowEx[$workspace][$persona]))) {
                throw new Problema('non_trovato');
            }

            return [200, $this->cancellaConLaConfermaFlow($corpo, $workspace, $persona), ['Cache-Control' => 'no-store, private']];
        });
    }

    /** La persona del gettone dell'accesso (middleware `accesso`): un gettone di workspace è 403 `gettone_con_workspace`. */
    private function personaDelGettoneDellAccessoFlow(Request $richiesta): string
    {
        $chi = $this->autentica($richiesta);

        if ($chi['workspace'] !== null) {
            throw new Problema('gettone_con_workspace');
        }

        return $chi['persona'];
    }

    /**
     * Controlla la conferma (il nome del workspace com'è) e cancella.
     *
     * @param  array<mixed>  $corpo
     * @return array{movimenti: int, importazioni: int, conti: int}
     */
    private function cancellaConLaConfermaFlow(array $corpo, string $workspace, string $persona): array
    {
        $campi = $this->testi->validaCorpo($corpo, ['conferma' => ['bail', 'required', 'string', 'max:200']]);

        if ($campi['conferma'] !== $this->workspace[$workspace]['nome']) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.in', ['attribute' => 'conferma']), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => '#/conferma']]);
        }

        return $this->cancellaIDatiDiFinanzeFlow($workspace, $persona);
    }

    /**
     * I workspace dove la persona ha dati di Finanze (Cancellazione::workspaceDi): una riga che porta la sua id, in qualunque tabella, anche archiviata. Solo gli id, in ordine.
     *
     * @return list<string>
     */
    private function workspaceConDatiFlow(string $persona): array
    {
        $trovati = [];

        foreach ([['conti', 'titolare'], ['importazioni', 'creata_da'], ['regole', 'creata_da'], ['fisse', 'creata_da'], ['avvisi', 'titolare']] as [$tabella, $colonna]) {
            foreach ($this->flow[$tabella] as $riga) {
                if ($riga[$colonna] === $persona) {
                    $trovati[$riga['workspace']] = true;
                }
            }
        }

        foreach (array_keys($this->flow['correzioni']) as $chiave) {
            [$workspace, $vista, $titolare] = explode('|', (string) $chiave) + [null, null, null];

            if ($vista === 'personale' && $titolare === $persona) {
                $trovati[(string) $workspace] = true;
            }
        }

        foreach ($this->flow['avvisiStati'] as $avviso => $stati) {
            if (isset($stati[$persona]) && isset($this->flow['avvisi'][$avviso])) {
                $trovati[$this->flow['avvisi'][$avviso]['workspace']] = true;
            }
        }

        foreach (array_keys($this->flow['avvisiSilenzi']) as $chiave) {
            [$workspace, $titolare] = explode('|', (string) $chiave) + [null, null];

            if ($titolare === $persona) {
                $trovati[(string) $workspace] = true;
            }
        }

        $id = array_map('strval', array_keys($trovati));
        sort($id);

        return $id;
    }

    /**
     * Cancella davvero, anche le righe archiviate (Cancellazione::esegui): i conti personali della persona con i loro movimenti e caricamenti; i caricamenti che ha fatto su un conto comune con i movimenti
     * che ne sono nati; le sue regole, spese fisse ed entrate corrette personali, i suoi avvisi con gli stati e i suoi silenziamenti. Restano, senza di lei (`titolare` e `creata_da` null), i conti comuni, le
     * regole e le spese fisse comuni che ha creato, e i movimenti scritti a mano su un conto comune. Le ricorrenti nascoste personali vanno con i loro conti: il finto le ricava dai movimenti.
     *
     * @return array{movimenti: int, importazioni: int, conti: int} quante righe sono state cancellate
     */
    private function cancellaIDatiDiFinanzeFlow(string $workspace, string $persona): array
    {
        $conti = array_keys(array_filter($this->flow['conti'], fn (array $conto) => $conto['workspace'] === $workspace && $conto['visibilita'] === 'personale' && $conto['titolare'] === $persona));
        $importazioni = array_keys(array_filter($this->flow['importazioni'], fn (array $importazione) => $importazione['workspace'] === $workspace && ($importazione['creata_da'] === $persona || in_array($importazione['conto'], $conti, true))));
        $movimenti = array_keys(array_filter($this->flow['movimenti'], fn (array $movimento) => $movimento['workspace'] === $workspace && (in_array($movimento['conto'], $conti, true) || in_array($movimento['importazione'], $importazioni, true))));

        foreach ($movimenti as $id) {
            unset($this->flow['movimenti'][$id]);
        }

        foreach ($importazioni as $id) {
            unset($this->flow['importazioni'][$id]);
        }

        foreach ($conti as $id) {
            unset($this->flow['conti'][$id]);
        }

        foreach (['conti' => 'titolare', 'regole' => 'creata_da', 'fisse' => 'creata_da'] as $tabella => $colonna) {
            foreach ($this->flow[$tabella] as $id => $riga) {
                if ($riga['workspace'] !== $workspace || $riga[$colonna] !== $persona) {
                    continue;
                }

                if ($riga['visibilita'] === 'personale') {
                    unset($this->flow[$tabella][$id]);
                } else {
                    $this->flow[$tabella][$id][$colonna] = null;
                }
            }
        }

        foreach (array_keys($this->flow['correzioni']) as $chiave) {
            if (str_starts_with((string) $chiave, $workspace.'|personale|'.$persona)) {
                unset($this->flow['correzioni'][$chiave]);
            }
        }

        foreach (array_keys($this->flow['avvisiSilenzi']) as $chiave) {
            if (str_starts_with((string) $chiave, $workspace.'|'.$persona.'|')) {
                unset($this->flow['avvisiSilenzi'][$chiave]);
            }
        }

        foreach ($this->flow['avvisi'] as $id => $avviso) {
            if ($avviso['workspace'] === $workspace && $avviso['visibilita'] === 'personale' && $avviso['titolare'] === $persona) {
                unset($this->flow['avvisi'][$id], $this->flow['avvisiStati'][$id]);
            } elseif ($avviso['workspace'] === $workspace) {
                unset($this->flow['avvisiStati'][$id][$persona]);
            }
        }

        return ['movimenti' => count($movimenti), 'importazioni' => count($importazioni), 'conti' => count($conti)];
    }
}
