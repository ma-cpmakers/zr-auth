<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Gli acquisti del CRM nel backoffice finto (CRM-07, #2357): `crm.acquisti.elenca`, `mostra`, `crea`, `modifica`, `consumi.crea` e
 * `consumi.elenca`, con le regole, i pointer e l'ordine di `AcquistiController` del backoffice. Un acquisto è un contatto che compra una
 * voce del catalogo e ne conserva nome, prezzo e condizioni del momento; il pagato si scrive alla creazione, dopo si cambiano il
 * `documento` e l'`inizio_il` (da cui partono la scadenza e il rinnovo). Il primo acquisto di un contatto `lead`, `qualificato` o `perso` lo fa `cliente`, con la sua riga di cronologia. Un consumo
 * porta i crediti di un pacchetto fino a −1 senza mai superarli (409 `crediti_esauriti`), e non si registra su un acquisto scaduto (409
 * `acquisto_scaduto`); un acquisto non si elimina. Lo `stato` non è una colonna: si calcola a ogni risposta. Li chiamano i tre ruoli per leggere e per i consumi; `crea` e `modifica` sono del proprietario e degli amministratori (#2404), un membro è 403 `permesso_negato`.
 * L'`ETag` non è lo stesso valore del backoffice: conta la forma. Il finto non emette gli eventi.
 */
trait CrmAcquisti
{
    /** Gli acquisti per workspace (`zeiras.crm.acquisti_per_workspace`). */
    private const CRM_ACQUISTI_TETTO = 100000;

    /** Il fuso di un workspace la cui azienda non ne ha scelto uno (Acquisti::FUSO). */
    private const CRM_ACQUISTI_FUSO = 'Europe/Rome';

    private const CRM_ACQUISTI_METODI = ['carta', 'contanti', 'bonifico', 'satispay', 'paypal', 'da_pagare'];

    private const CRM_ACQUISTI_DOCUMENTI = ['da_fare', 'ricevuta_fatta', 'fattura_fatta'];

    private const CRM_ACQUISTI_STATI = ['attivo', 'esaurito', 'scaduto'];

    /** Il credito più basso che un pacchetto può toccare (`zeiras.crm.credito_minimo_del_pacchetto`): un consumo non porta i disponibili sotto. */
    private const CRM_ACQUISTI_CREDITO_MINIMO = -1;

    private const CRM_ACQUISTI_ORDINI = ['-acquistato_il', 'acquistato_il', 'valido_fino_al', '-valido_fino_al'];

    /** Quanti mesi dura un periodo di rinnovo (Acquisti::MESI_DEL_RINNOVO). */
    private const CRM_ACQUISTI_MESI_DEL_RINNOVO = ['mensile' => 1, 'trimestrale' => 3, 'semestrale' => 6, 'annuale' => 12];

    private const CRM_ACQUISTI_IMPORTO = 1_000_000_000_000;

    private const CRM_CONSUMO_NOTA = 500;

    private const CRM_CONSUMO_QUANTITA = 100;

    /**
     * crm.acquisti.crea (AcquistiController::crea): 201 con l'acquisto, `Location` ed `ETag`. Prima il corpo, poi il tetto (409
     * `limite_raggiunto`), il contatto (422 se non c'è, 409 se è anonimizzato) e la voce (422 se non c'è o non è `attiva`), le date e il pagato,
     * tutti insieme sul pointer del campo. La voce e il prezzo si copiano; la scadenza e il rinnovo si calcolano dalla voce.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaAcquistoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.acquisti.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, $this->regoleDellAcquistoCrm($chi['workspace']));

            if (count($this->righeCrm('acquisti', $chi['workspace'])) >= self::CRM_ACQUISTI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_ACQUISTI_TETTO]);
            }

            $persona = $this->crm['persone'][strtolower((string) $campi['persona_id'])] ?? null;

            if ($persona === null || $persona['workspace'] !== $chi['workspace']) {
                throw $this->erroreCrm('persona_id', 'validation.exists', Regola::NON_TROVATO, ['attribute' => $this->testi->nome('persona_id')]);
            }

            if ($persona['anonimizzata_il'] !== null) {
                throw new Problema('persona_anonimizzata');
            }

            $voce = $this->crm['catalogo'][strtolower((string) $campi['voce_id'])] ?? null;
            $voce = $voce !== null && $voce['workspace'] === $chi['workspace'] ? $voce : null;
            $this->controllaAcquistoCrm($campi, $persona['archiviata_il'] !== null, $voce);

            $primo = array_filter($this->crm['acquisti'], fn (array $acquisto) => $acquisto['persona_id'] === $persona['id']) === [];
            $id = self::id();
            $ora = self::adessoCrm();
            $giorno = (string) $campi['acquistato_il'];
            $inizio = (string) ($campi['inizio_il'] ?? $giorno);
            $this->crm['acquisti'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'persona_id' => $persona['id'],
                'voce_id' => $voce['id'], 'preventivo_id' => null, 'voce_tipo' => $voce['tipo'], 'voce_nome' => $voce['nome'], 'voce_durata_minuti' => $voce['durata_minuti'],
                'voce_unita_incluse' => $voce['unita_incluse'], 'voce_etichetta_unita' => $voce['etichetta_unita'], 'voce_validita_mesi' => $voce['validita_mesi'],
                'voce_rinnovo' => $voce['rinnovo'], 'prezzo_importo' => $voce['prezzo_importo'],
                'acquistato_il' => $giorno, 'inizio_il' => $inizio, 'pagato_importo' => (int) ($campi['pagato']['importo'] ?? 0), 'metodo_pagamento' => $campi['metodo_pagamento'],
                'documento' => $campi['documento'] ?? 'da_fare',
                'valido_fino_al' => $voce['validita_mesi'] === null ? null : self::dopoMesiAcquistoCrm($inizio, $voce['validita_mesi']),
                'rinnovo_il' => $voce['rinnovo'] === null ? null : self::dopoMesiAcquistoCrm($inizio, self::CRM_ACQUISTI_MESI_DEL_RINNOVO[$voce['rinnovo']]),
                'crediti_totali' => $voce['tipo'] === 'pacchetto' ? $voce['unita_incluse'] : null, 'crediti_usati' => 0, 'crediti_prenotati' => 0,
                'creato_il' => $ora, 'aggiornato_il' => $ora, 'creato_da' => $chi['persona'],
            ];

            // Il piano nasce con l'acquisto (CRM-08): le rate date, o la rata unica del prezzo con il pagato come primo pagamento. Un errore
            // del piano annulla anche l'acquisto.
            try {
                if (isset($campi['rate'])) {
                    $this->scriviIlPianoCrm($this->crm['acquisti'][$id], $campi['rate']);
                } else {
                    $this->creaLaRataUnicaCrm($this->crm['acquisti'][$id], $chi['persona']);
                }
            } catch (Problema $problema) {
                unset($this->crm['acquisti'][$id]);

                throw $problema;
            }

            // Il primo acquisto fa «cliente» il contatto (D10): la cronologia è quella di una modifica a mano. Dal secondo non cambia più.
            if ($primo && in_array($persona['stato'], ['lead', 'qualificato', 'perso'], true)) {
                $this->applicaPersona($persona['id'], ['stato' => 'cliente'], $chi['persona']);
            }

            $acquisto = $this->voceAcquistoCrm($this->crm['acquisti'][$id]);

            return [201, ['data' => $acquisto], ['Location' => "/v1/crm/acquisti/{$id}", 'ETag' => self::etagCrm($acquisto)]];
        });
    }

    /**
     * crm.acquisti.modifica (AcquistiController::modifica): JSON Merge Patch del `documento` e dell'`inizio_il` (che ricalcola la scadenza e il
     * rinnovo dalla copia della voce), con `If-Match` letto prima del corpo (412 sulla versione vecchia). Ogni altro campo che l'acquisto ha è
     * 422 `campo_vietato`, uno che non ha `campo_estraneo`. Un valore uguale a quello di adesso non scrive.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaAcquistoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm('acquisti', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceAcquistoCrm($riga));
        $vietati = ['id', 'persona_id', 'voce_id', 'voce', 'prezzo', 'acquistato_il', 'pagato', 'metodo_pagamento', 'valido_fino_al', 'rinnovo_il', 'crediti', 'stato', 'creato_il', 'aggiornato_il', 'creato_da'];
        $campi = $this->modificaCrm($corpo, [
            'documento' => ['bail', 'filled', 'string', Rule::in(self::CRM_ACQUISTI_DOCUMENTI)],
            'inizio_il' => ['bail', 'filled', 'string', 'date_format:Y-m-d'],
        ] + array_fill_keys($vietati, ['missing']));
        $nuovi = isset($campi['documento']) ? ['documento' => $campi['documento']] : [];

        if (isset($campi['inizio_il']) && $campi['inizio_il'] !== ($riga['inizio_il'] ?? $riga['acquistato_il'])) {
            $inizio = (string) $campi['inizio_il'];
            $nuovi += [
                'inizio_il' => $inizio,
                'valido_fino_al' => $riga['voce_validita_mesi'] === null ? null : self::dopoMesiAcquistoCrm($inizio, $riga['voce_validita_mesi']),
                'rinnovo_il' => $riga['voce_rinnovo'] === null ? null : self::dopoMesiAcquistoCrm($inizio, self::CRM_ACQUISTI_MESI_DEL_RINNOVO[$riga['voce_rinnovo']]),
            ];
        }

        $this->salvaCrm('acquisti', $riga['id'], $nuovi);
        $acquisto = $this->voceAcquistoCrm($this->crm['acquisti'][$riga['id']]);

        return [200, ['data' => $acquisto], ['ETag' => self::etagCrm($acquisto)]];
    }

    /**
     * crm.acquisti.elenca (AcquistiController::elenca): gli acquisti a cursore. Filtri `persona_id`, `voce_id`, `id` (al più 100), `stato` (a
     * virgole); `ordina` per `acquistato_il` (di partenza decrescente) o `valido_fino_al`, con `-`; a parità decide l'id nello stesso verso; i
     * senza scadenza vanno in fondo in entrambi i versi. `q` è vietato. Con `totale=true` anche quanti sono.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaAcquistiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'persona_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'voce_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'id' => $this->regolaDelFiltroPerId(),
            'stato' => $this->regolaDelleVociCrm('stato', self::CRM_ACQUISTI_STATI),
            'ordina' => ['bail', 'sometimes', 'string', Rule::in(self::CRM_ACQUISTI_ORDINI)],
            'totale' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'q' => ['prohibited'],
        ]);
        $stati = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($query['stato'] ?? ''))), fn (string $voce) => $voce !== '')));
        $persona = isset($query['persona_id']) ? strtolower($query['persona_id']) : null;
        $voce = isset($query['voce_id']) ? strtolower($query['voce_id']) : null;
        $ids = self::idDelFiltroCrm($query['id'] ?? null);
        $ordina = (string) ($query['ordina'] ?? self::CRM_ACQUISTI_ORDINI[0]);
        $oggi = self::oggiAcquistoCrm();
        $righe = array_values(array_filter(
            $this->righeCrm('acquisti', $chi['workspace']),
            fn (array $riga) => ($persona === null || $riga['persona_id'] === $persona) && ($voce === null || $riga['voce_id'] === $voce)
                && ($ids === [] || in_array($riga['id'], $ids, true)) && ($stati === [] || in_array(self::statoAcquistoCrm($riga, $oggi), $stati, true)),
        ));
        $campo = ltrim($ordina, '-');
        $verso = str_starts_with($ordina, '-') ? -1 : 1;
        $lista = 'crm.acquisti.elenca:'.substr(hash('sha256', json_encode([$ordina, $persona, $voce, $stati, $ids], JSON_THROW_ON_ERROR)), 0, 16);
        $ordine = function (array $una, array $altra) use ($campo, $verso): int {
            $a = $una[$campo];
            $b = $altra[$campo];

            if (($a === null) !== ($b === null)) {
                return $a === null ? 1 : -1;
            }

            $ordine = $a === null ? 0 : $verso * (strcmp($a, $b) <=> 0);

            return $ordine !== 0 ? $ordine : $verso * (strcmp($una['id'], $altra['id']) <=> 0);
        };
        $risposta = $this->paginaCrm($richiesta, $lista, $righe, $ordine, fn (array $riga) => $this->voceAcquistoCrm($riga));

        if (($query['totale'] ?? null) === 'true') {
            $risposta[1]['totale'] = count($righe);
        }

        return $risposta;
    }

    /**
     * crm.acquisti.consumi.crea (AcquistiController::consumiCrea): 201 con il solo consumo, senza `Location`. `quantita` (1-100, di partenza 1),
     * `svolto_il` (di partenza adesso, non nel futuro) e `nota` (al più 500). Un acquisto senza crediti (servizio, prodotto) è 422; uno
     * scaduto è 409 `acquisto_scaduto`; un pacchetto che dopo il consumo scenderebbe sotto −1 è 409 `crediti_esauriti` coi `disponibili` (a credito zero passa). L'abbonamento
     * si consuma senza tetto.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaConsumoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('acquisti', $chi['workspace'], $id);

        return $this->conIdempotenza($richiesta, 'crm.acquisti.consumi.crea', $chi, $corpo, function () use ($chi, $corpo, $riga) {
            $campi = $this->testi->validaCorpo($corpo, [
                'quantita' => ['bail', 'sometimes', 'integer:strict', 'between:1,'.self::CRM_CONSUMO_QUANTITA],
                'svolto_il' => ['bail', 'sometimes', 'string', $this->regolaDellaSvolta()],
                'nota' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::CRM_CONSUMO_NOTA, new SenzaCarattereNullo],
            ]);
            $quantita = (int) ($campi['quantita'] ?? 1);

            // La nota è testo libero: su un contatto anonimizzato non si scrive (409, come per le note e le attività).
            if (($this->crm['persone'][$riga['persona_id']]['anonimizzata_il'] ?? null) !== null) {
                throw new Problema('persona_anonimizzata');
            }

            if (! in_array($riga['voce_tipo'], ['pacchetto', 'abbonamento'], true)) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.acquisto_senza_crediti'), 'regola' => Regola::NON_VALIDA, 'pointer' => '#']]);
            }

            if (self::statoAcquistoCrm($riga, self::oggiAcquistoCrm()) === 'scaduto') {
                throw new Problema('acquisto_scaduto');
            }

            // Un pacchetto si consuma anche a credito zero, fino al credito minimo (−1, `zeiras.crm.credito_minimo_del_pacchetto`): il cliente è da pagare (#2399).
            if ($riga['voce_tipo'] === 'pacchetto' && self::disponibiliAcquistoCrm($riga) - $quantita < self::CRM_ACQUISTI_CREDITO_MINIMO) {
                throw new Problema('crediti_esauriti', estensioni: ['disponibili' => self::disponibiliAcquistoCrm($riga)]);
            }

            $consumo = [
                'id' => self::id(), 'workspace' => $chi['workspace'], 'acquisto_id' => $riga['id'], 'quantita' => $quantita,
                'svolto_il' => isset($campi['svolto_il']) ? self::istanteCrm($campi['svolto_il']) : self::adessoCrm(),
                'origine' => 'manuale', 'nota' => $campi['nota'] ?? null, 'creato_da' => $chi['persona'], 'creato_il' => self::adessoCrm(),
            ];
            $this->crm['consumi'][$consumo['id']] = $consumo;
            $this->salvaCrm('acquisti', $riga['id'], ['crediti_usati' => $riga['crediti_usati'] + $quantita]);

            return [201, ['data' => $this->voceConsumoCrm($consumo)], []];
        });
    }

    /**
     * crm.acquisti.consumi.elenca: i consumi di un acquisto dal più recente (`svolto_il`, poi `id`, decrescenti), a cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaConsumiCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('acquisti', $chi['workspace'], $id);
        $righe = array_values(array_filter($this->crm['consumi'], fn (array $consumo) => $consumo['acquisto_id'] === $riga['id']));

        return $this->paginaCrm(
            $richiesta,
            "crm.acquisti.consumi.elenca:{$riga['id']}",
            $righe,
            fn (array $uno, array $altro) => [self::iso($altro['svolto_il']), $altro['id']] <=> [self::iso($uno['svolto_il']), $uno['id']],
            fn (array $consumo) => $this->voceConsumoCrm($consumo),
        );
    }

    /**
     * Lo schema Acquisto (Forme::acquisto): la copia della voce e del prezzo del momento, il pagato nella valuta del CRM di adesso, i crediti
     * (null per chi non ne ha) e lo `stato` di oggi.
     *
     * @return array<string, mixed>
     */
    private function voceAcquistoCrm(array $riga): array
    {
        $valuta = $this->valutaCrm($riga['workspace']);

        return [
            'id' => $riga['id'],
            'persona_id' => $riga['persona_id'],
            'voce_id' => $riga['voce_id'],
            'preventivo_id' => $riga['preventivo_id'] ?? null,
            'voce' => [
                'nome' => $riga['voce_nome'],
                'tipo' => $riga['voce_tipo'],
                'durata_minuti' => $riga['voce_durata_minuti'],
                'unita_incluse' => $riga['voce_unita_incluse'],
                'etichetta_unita' => $riga['voce_etichetta_unita'],
                'validita_mesi' => $riga['voce_validita_mesi'],
                'rinnovo' => $riga['voce_rinnovo'],
            ],
            'prezzo' => $riga['prezzo_importo'] === null ? null : ['importo' => $riga['prezzo_importo'], 'valuta' => $valuta],
            'acquistato_il' => $riga['acquistato_il'],
            'inizio_il' => $riga['inizio_il'] ?? $riga['acquistato_il'],
            'pagato' => ['importo' => $riga['pagato_importo'], 'valuta' => $valuta],
            'metodo_pagamento' => $riga['metodo_pagamento'],
            'documento' => $riga['documento'],
            'valido_fino_al' => $riga['valido_fino_al'],
            'rinnovo_il' => $riga['rinnovo_il'],
            'crediti' => self::creditiAcquistoCrm($riga),
            'stato' => self::statoAcquistoCrm($riga, self::oggiAcquistoCrm()),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
            'creato_da' => $riga['creato_da'],
        ];
    }

    /**
     * Lo schema Consumo (Forme::consumo).
     *
     * @return array<string, mixed>
     */
    private function voceConsumoCrm(array $consumo): array
    {
        return [
            'id' => $consumo['id'],
            'acquisto_id' => $consumo['acquisto_id'],
            'quantita' => $consumo['quantita'],
            'svolto_il' => self::iso($consumo['svolto_il']),
            'origine' => $consumo['origine'],
            'nota' => $consumo['nota'],
            'creato_da' => $consumo['creato_da'],
            'creato_il' => self::iso($consumo['creato_il']),
        ];
    }

    /**
     * Le regole dei campi di un acquisto che nasce. Il pagato è `{importo, valuta}` (facoltativo, di partenza 0) nella valuta del CRM del
     * workspace; i controlli che vogliono la voce, il contatto e il giorno di oggi si fanno dopo (`controllaAcquistoCrm`).
     *
     * @return array<string, mixed>
     */
    private function regoleDellAcquistoCrm(string $workspace): array
    {
        return [
            'persona_id' => ['bail', 'required', 'string', 'max:64'],
            'voce_id' => ['bail', 'required', 'string', 'max:64'],
            'acquistato_il' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'inizio_il' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
            'pagato' => ['bail', 'sometimes', 'array', 'prohibits:rate', function (string $campo, mixed $pagato, Closure $fail) {
                if ($pagato === []) {
                    $this->rifiutaCrm($fail, $campo, Regola::OBBLIGATORIO, 'validation.required', ['attribute' => $campo]);
                }
            }],
            'pagato.importo' => ['bail', 'required_with:pagato', 'integer:strict', 'between:0,'.self::CRM_ACQUISTI_IMPORTO],
            'pagato.valuta' => ['bail', 'required_with:pagato', 'string', function (string $campo, mixed $valuta, Closure $fail) use ($workspace) {
                if ($valuta !== $this->valutaCrm($workspace)) {
                    $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'regole.valuta_del_workspace', ['valuta' => $this->valutaCrm($workspace)]);
                }
            }],
            'metodo_pagamento' => ['bail', 'required', 'string', Rule::in(self::CRM_ACQUISTI_METODI)],
            'documento' => ['bail', 'sometimes', 'string', Rule::in(self::CRM_ACQUISTI_DOCUMENTI)],
        ] + $this->regoleDelPianoCrm($workspace, false);
    }

    /**
     * I controlli che vogliono lo stato del momento (AcquistiController::controlla): il contatto non è archiviato, la voce c'è ed è `attiva`,
     * il pagato non supera il prezzo, la data non è dopo oggi e con `da_pagare` il pagato è zero. Tutti gli errori insieme, sul pointer del campo.
     *
     * @param  array<string, mixed>  $campi
     * @param  array<string, mixed>|null  $voce
     */
    private function controllaAcquistoCrm(array $campi, bool $contattoArchiviato, ?array $voce): void
    {
        $errori = [];
        $pagato = (int) ($campi['pagato']['importo'] ?? 0);
        $errore = fn (string $pointer, string $testo, string $regola) => ['detail' => $testo, 'regola' => $regola, 'pointer' => $pointer];

        if ($contattoArchiviato) {
            $errori[] = $errore('#/persona_id', $this->testi->testo('regole.acquisto_contatto_archiviato'), Regola::VALORE_NON_AMMESSO);
        }

        if ($voce === null) {
            $errori[] = $errore('#/voce_id', $this->testi->testo('validation.exists', ['attribute' => 'voce_id']), Regola::NON_TROVATO);
        } elseif ($voce['stato'] !== 'attiva') {
            $errori[] = $errore('#/voce_id', $this->testi->testo('regole.acquisto_voce_non_attiva'), Regola::VALORE_NON_AMMESSO);
        } elseif ($pagato > ($voce['prezzo_importo'] ?? 0)) {
            $errori[] = $errore('#/pagato/importo', $this->testi->testo('regole.acquisto_oltre_il_prezzo'), Regola::FUORI_INTERVALLO);
        }

        if ((string) $campi['acquistato_il'] > self::oggiAcquistoCrm()) {
            $errori[] = $errore('#/acquistato_il', $this->testi->testo('regole.acquisto_data_futura'), Regola::FUORI_INTERVALLO);
        }

        if ($campi['metodo_pagamento'] === 'da_pagare' && $pagato > 0) {
            $errori[] = $errore('#/pagato/importo', $this->testi->testo('regole.acquisto_da_pagare'), Regola::VALORE_NON_AMMESSO);
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }
    }

    /** Oggi nel fuso del workspace, `AAAA-MM-GG` (Acquisti::oggi). */
    private static function oggiAcquistoCrm(): string
    {
        return CarbonImmutable::now(self::CRM_ACQUISTI_FUSO)->format('Y-m-d');
    }

    /** Il giorno `$mesi` mesi dopo `$giorno`, senza sconfinare nel mese dopo (Acquisti::dopoMesi). */
    private static function dopoMesiAcquistoCrm(string $giorno, int $mesi): string
    {
        return CarbonImmutable::parse($giorno, 'UTC')->addMonthsNoOverflow($mesi)->format('Y-m-d');
    }

    /** I crediti di un pacchetto ancora da usare: i totali meno gli usati e i prenotati (Acquisti::disponibili). */
    private static function disponibiliAcquistoCrm(array $riga): int
    {
        return (int) $riga['crediti_totali'] - $riga['crediti_usati'] - $riga['crediti_prenotati'];
    }

    /** `attivo`, `esaurito` o `scaduto` (Acquisti::stato): sul pacchetto scaduto e senza crediti vince `scaduto`. */
    private static function statoAcquistoCrm(array $riga, string $oggi): string
    {
        if ($riga['valido_fino_al'] !== null && $riga['valido_fino_al'] < $oggi) {
            return 'scaduto';
        }

        return $riga['voce_tipo'] === 'pacchetto' && self::disponibiliAcquistoCrm($riga) <= 0 ? 'esaurito' : 'attivo';
    }

    /**
     * I crediti come li vede l'API (Acquisti::crediti): null per chi non ne ha, il conto per il pacchetto, e per l'abbonamento i soli usati e
     * prenotati (`totali` e `disponibili` null: senza limite).
     *
     * @return array<string, int|null>|null
     */
    private static function creditiAcquistoCrm(array $riga): ?array
    {
        if (! in_array($riga['voce_tipo'], ['pacchetto', 'abbonamento'], true)) {
            return null;
        }

        $conTetto = $riga['voce_tipo'] === 'pacchetto';

        return [
            'totali' => $conTetto ? $riga['crediti_totali'] : null,
            'usati' => $riga['crediti_usati'],
            'prenotati' => $riga['crediti_prenotati'],
            'disponibili' => $conTetto ? self::disponibiliAcquistoCrm($riga) : null,
        ];
    }
}
