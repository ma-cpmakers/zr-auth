<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

/**
 * Finanze (app `flow`, tappa 1, gruppo 1, #3455-#3472) nel backoffice finto: `flow.conti.*`, `flow.conti.importazioni.*`, `flow.categorie.elenca`, `flow.movimenti.*` e `flow.regole.*`, con le
 * regole, i pointer e l'ordine di `ContiController`, `ImportazioniController`, `MovimentiController` e `RegoleController` del backoffice. Metodi dell'app `flow`: 403 `app_non_attiva` se spenta, prima di
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

    private const FLOW_REGOLA_TESTO = 100;

    private const FLOW_REGOLA_MINIMO = 2;

    private const FLOW_FORMATO = 2048;

    private const FLOW_RIGHE = 5000;

    private const FLOW_CAMPIONE = 10;

    private const FLOW_IMPORTO = 999_999_999_999;

    private const FLOW_DESCRIZIONE = 500;

    /** @var array{conti: array<string, array<string, mixed>>, importazioni: array<string, array<string, mixed>>, movimenti: array<string, array<string, mixed>>, regole: array<string, array<string, mixed>>} */
    private array $flow = ['conti' => [], 'importazioni' => [], 'movimenti' => [], 'regole' => []];

    /**
     * Apre Finanze nel workspace, come l'anteprima del backoffice (`ZR_FLOW_ANTEPRIMA`) e app.modifica: il catalogo del finto la dà «in_arrivo» (app.modifica la rifiuta e apps.elenca la mostra così), ma i metodi
     * `flow.*` la vedono accesa.
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
        $this->anteprime[$id]['flow'] = true;

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

    /** Il nome di una persona se è ancora membro del workspace, altrimenti null (Conti::nomi). */
    private function nomeFlow(string $workspace, string $persona): ?string
    {
        return isset($this->membri[$workspace][$persona]) ? $this->persone[$persona]['nome'] : null;
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

        if ($scrive && $conto['titolare'] !== $chi['persona']) {
            throw new Problema('permesso_negato');
        }

        return $conto;
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
                    'impronta' => $riga['impronta'], 'categoria_id' => $riga['categoria_id'], 'categoria_fonte' => $riga['categoria_fonte'], 'nota' => null, 'escluso' => false, 'escluso_motivo' => null,
                    'origine' => 'importazione', 'archiviato' => false,
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
     * @return list<array{data: string, importo: int, descrizione: string, impronta: string, doppione: bool, categoria_id: string|null, categoria_fonte: string}>
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
            [$categoria, $fonte] = $this->categoriaDiUnaRigaFlow($regole, $riga['descrizione']);
            $lette[] = ['data' => $riga['data'], 'importo' => $riga['importo'], 'descrizione' => $riga['descrizione'], 'impronta' => $impronta, 'doppione' => isset($esistenti[$impronta]), 'categoria_id' => $categoria, 'categoria_fonte' => $fonte];
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
        $lista = 'flow.movimenti.elenca|'.json_encode(array_map(fn (string $filtro) => is_scalar($grezzi[$filtro] ?? null) ? (string) $grezzi[$filtro] : null, $filtri), JSON_UNESCAPED_UNICODE);

        return $this->pagina(
            $richiesta,
            $lista,
            'id',
            fn () => $this->movimentiDellaVistaFlow($chi, [
                'visibilita' => (string) $grezzi['visibilita'], 'conto' => $grezzi['conto'] ?? null, 'categoria' => $grezzi['categoria'] ?? null, 'da' => $grezzi['da'] ?? null, 'a' => $grezzi['a'] ?? null,
                'escluso' => isset($grezzi['escluso']) ? $grezzi['escluso'] === 'true' : null,
            ], null),
            fn (array $voce) => [$voce['data'], $voce['id']],
            fn (string $id) => isset($this->flow['movimenti'][$id]) && $this->flow['movimenti'][$id]['workspace'] === $chi['workspace'] ? [$this->flow['movimenti'][$id]['data'], $id] : null,
            true,
            self::LIMITE_MASSIMO,
            null,
            $this->regoleDeiFiltriFlow(false) + $this->estraneiFlow($grezzi, ['limite', 'cursore', ...$filtri]),
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

        $filtri = ['visibilita' => (string) $campi['visibilita'], 'conto' => $campi['conto'] ?? null, 'categoria' => $campi['categoria'] ?? null, 'da' => $campi['da'] ?? null, 'a' => $campi['a'] ?? null, 'escluso' => $campi['escluso'] ?? null];
        $lista = 'flow.movimenti.ricerca.crea|'.json_encode([...array_values($filtri), $cercato], JSON_UNESCAPED_UNICODE);
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
            [$categoria, $fonte] = isset($campi['categoria_id']) ? [$campi['categoria_id'], 'utente'] : $this->categoriaDiUnaRigaFlow($this->regoleDelContoFlow($conto), $campi['descrizione']);
            $id = self::id();
            $this->flow['movimenti'][$id] = [
                'id' => $id, 'workspace' => $conto['workspace'], 'conto' => $conto['id'], 'importazione' => null, 'data' => $campi['data'], 'importo' => $campi['importo'], 'descrizione' => $campi['descrizione'],
                'impronta' => null, 'categoria_id' => $categoria, 'categoria_fonte' => $fonte, 'nota' => $campi['nota'] ?? null, 'escluso' => false, 'escluso_motivo' => null, 'origine' => 'manuale', 'archiviato' => false,
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
        ];
    }

    /**
     * I movimenti della vista (Movimenti::pagina): quelli dei conti `comune` o `personale` (di chi chiama) non eliminati. Un `conto` che la persona non vede è 404, non una lista vuota; uno che vede ma sta
     * nell'altra vista dà una lista vuota. `$cercato` è il testo già normalizzato, o null.
     *
     * @param  array{persona: string, workspace: string}  $chi
     * @param  array{visibilita: string, conto: mixed, categoria: mixed, da: mixed, a: mixed, escluso: mixed}  $filtri
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
     * @return list<array{testo: string, categoria_id: string}>
     */
    private function regoleDelContoFlow(array $conto): array
    {
        $regole = array_filter($this->flow['regole'], fn (array $regola) => ! $regola['archiviato'] && $regola['workspace'] === $conto['workspace'] && $regola['visibilita'] === $conto['visibilita']
            && ($conto['visibilita'] === 'comune' || $regola['creata_da'] === $conto['titolare']));
        usort($regole, fn (array $una, array $altra) => [$altra['creata_il'], $altra['id']] <=> [$una['creata_il'], $una['id']]);

        return array_map(fn (array $regola) => ['testo' => $regola['testo_chiave'], 'categoria_id' => $regola['categoria_id']], $regole);
    }

    /**
     * La categoria di una riga che nasce e da dove viene (Regole::categoriaDi): la regola, poi la parola chiave di sistema, poi niente.
     *
     * @param  list<array{testo: string, categoria_id: string}>  $regole
     * @return array{0: string|null, 1: string}
     */
    private function categoriaDiUnaRigaFlow(array $regole, string $descrizione): array
    {
        $normalizzato = FlowCategorie::normalizza($descrizione);

        foreach ($regole as $regola) {
            if (str_contains($normalizzato, $regola['testo'])) {
                return [$regola['categoria_id'], 'regola'];
            }
        }

        $categoria = FlowCategorie::assegna($normalizzato);

        return [$categoria, $categoria === null ? 'nessuna' : 'sistema'];
    }

    /**
     * Lo schema Regola (Regole::forma).
     *
     * @param  array<string, mixed>  $regola
     * @return array<string, mixed>
     */
    private function voceRegolaFlow(array $regola): array
    {
        return [
            'id' => $regola['id'],
            'testo' => $regola['testo'],
            'categoria_id' => $regola['categoria_id'],
            'visibilita' => $regola['visibilita'],
            'creata_da' => ['id' => $regola['creata_da'], 'nome' => $this->nomeFlow($regola['workspace'], $regola['creata_da'])],
            'creata_il' => self::iso($regola['creata_il']),
        ];
    }
}
