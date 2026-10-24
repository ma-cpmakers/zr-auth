<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Le rate e i pagamenti del CRM nel backoffice finto (CRM-08, #2364): `crm.rate.elenca`, `crm.rate.riepilogo.mostra`, `crm.rate.mostra`,
 * `crm.acquisti.piano.crea`, `crm.rate.pagamenti.crea`, `crm.rate.pagamenti.elenca` e `crm.pagamenti.annullamento.crea`, con le regole, i
 * pointer e l'ordine di `RateController` e `PagamentiController` del backoffice. Il piano di un acquisto con un prezzo ha da 1 a 24 rate
 * la cui somma è il prezzo; il pagato di una rata e dell'acquisto è la somma dei pagamenti non annullati; lo `stato` di una rata non è una
 * colonna: si calcola a ogni risposta. Un pagamento non si cancella e non si modifica: si annulla (solo proprietario e amministratore).
 * Una rata tolta da un piano nuovo esce dall'API (`eliminata`). Il finto non emette gli eventi.
 */
trait CrmRate
{
    private const CRM_RATE_MASSIMO = 24;

    private const CRM_RATE_GIORNI_IN_SCADENZA = 7;

    private const CRM_RATE_STATI = ['prevista', 'in_scadenza', 'scaduta', 'parziale', 'pagata'];

    private const CRM_RATE_ORDINI = ['scade_il', '-scade_il'];

    private const CRM_RATE_ETICHETTA = 60;

    private const CRM_RATE_PAGAMENTI_PER_RATA = 100;

    private const CRM_RATE_RIFERIMENTO = 100;

    private const CRM_RATE_MOTIVO = 500;

    private const CRM_RATE_METODI = ['carta', 'contanti', 'bonifico', 'satispay', 'paypal', 'altro'];

    private const CRM_RATE_TETTO_DEL_TOTALE = 10000;

    /**
     * crm.rate.elenca (RateController::elenca): le rate a cursore, per scadenza. Filtri `persona_id`, `acquisto_id`, `stato` (a virgole),
     * `scade_dal`, `scade_al`, `pagata_dal`; `ordina` `scade_il` o `-scade_il`, a parità il numero e l'id nello stesso verso. Con
     * `totale=true` anche quante sono, fino a 10.000.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaRateCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'persona_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'acquisto_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'stato' => $this->regolaDelleVociCrm('stato', self::CRM_RATE_STATI),
            'scade_dal' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
            'scade_al' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d', 'after_or_equal:scade_dal'],
            'pagata_dal' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
            'ordina' => ['bail', 'sometimes', 'string', Rule::in(self::CRM_RATE_ORDINI)],
            'totale' => ['sometimes', 'string', Rule::in(['true', 'false'])],
        ]);
        $stati = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($query['stato'] ?? ''))), fn (string $voce) => $voce !== '')));
        $persona = isset($query['persona_id']) ? strtolower($query['persona_id']) : null;
        $acquisto = isset($query['acquisto_id']) ? strtolower($query['acquisto_id']) : null;
        $ordina = (string) ($query['ordina'] ?? self::CRM_RATE_ORDINI[0]);
        $oggi = self::oggiAcquistoCrm();
        $righe = array_values(array_filter(
            $this->righeCrm('rate', $chi['workspace']),
            fn (array $riga) => ($persona === null || $riga['persona_id'] === $persona) && ($acquisto === null || $riga['acquisto_id'] === $acquisto)
                && ($stati === [] || in_array(self::statoRataCrm($riga, $oggi), $stati, true))
                && (! isset($query['scade_dal']) || $riga['scade_il'] >= $query['scade_dal']) && (! isset($query['scade_al']) || $riga['scade_il'] <= $query['scade_al'])
                && (! isset($query['pagata_dal']) || ($riga['ultimo_pagamento_il'] !== null && $riga['ultimo_pagamento_il'] >= $query['pagata_dal'])),
        ));
        $verso = str_starts_with($ordina, '-') ? -1 : 1;
        $lista = 'crm.rate.elenca:'.substr(hash('sha256', json_encode([$ordina, $persona, $acquisto, $stati, $query['scade_dal'] ?? null, $query['scade_al'] ?? null, $query['pagata_dal'] ?? null], JSON_THROW_ON_ERROR)), 0, 16);
        $ordine = fn (array $una, array $altra): int => $verso * ([$una['scade_il'], $una['numero'], $una['id']] <=> [$altra['scade_il'], $altra['numero'], $altra['id']]);
        $risposta = $this->paginaCrm($richiesta, $lista, $righe, $ordine, fn (array $riga) => $this->voceRataCrm($riga));

        if (($query['totale'] ?? null) === 'true') {
            $risposta[1]['totale'] = min(count($righe), self::CRM_RATE_TETTO_DEL_TOTALE);

            if (count($righe) > self::CRM_RATE_TETTO_DEL_TOTALE) {
                $risposta[1]['totale_almeno'] = true;
            }
        }

        return $risposta;
    }

    /**
     * crm.rate.riepilogo.mostra (RateController::riepilogoMostra): `da_incassare` (i residui con scadenza nel periodo), `scaduto` e
     * `rate_scadute` (le rate scadute del workspace). Ogni parametro che non è `scade_dal` o `scade_al` è 422 `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function riepilogoRateCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $regole = [
            'scade_dal' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
            'scade_al' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d', 'after_or_equal:scade_dal'],
        ];
        $estranei = array_diff(array_map('strval', array_keys(self::query($richiesta))), array_keys($regole));
        $query = $this->testi->validaQuery(self::query($richiesta), $regole + array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $attributo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $attributo]);
        }]));
        $oggi = self::oggiAcquistoCrm();
        $valuta = $this->valutaCrm($chi['workspace']);
        $righe = $this->righeCrm('rate', $chi['workspace']);
        $nelPeriodo = array_filter($righe, fn (array $riga) => (! isset($query['scade_dal']) || $riga['scade_il'] >= $query['scade_dal']) && (! isset($query['scade_al']) || $riga['scade_il'] <= $query['scade_al']));
        $scadute = array_filter($righe, fn (array $riga) => self::statoRataCrm($riga, $oggi) === 'scaduta');
        $residui = fn (array $insieme): int => array_sum(array_map(fn (array $riga) => self::residuoRataCrm($riga), $insieme));

        return [200, ['data' => [
            'da_incassare' => ['importo' => $residui($nelPeriodo), 'valuta' => $valuta],
            'scaduto' => ['importo' => $residui($scadute), 'valuta' => $valuta],
            'rate_scadute' => count($scadute),
        ]]];
    }

    /**
     * crm.acquisti.piano.crea (RateController::pianoCrea): sostituisce il piano dell'acquisto e risponde 200 con le rate nuove nell'ordine
     * delle scadenze. `If-Match` letto prima del corpo. Senza prezzo 422 `senza_prezzo`; con un pagamento non annullato 409
     * `rate_con_pagamenti`; la somma diversa dal prezzo 422 `somma_diversa` con la `differenza`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function creaPianoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $acquisto = $this->rigaCrm('acquisti', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceAcquistoCrm($acquisto));
        $campi = $this->testi->validaCorpo($corpo, $this->regoleDelPianoCrm($chi['workspace'], true));
        $this->controllaLaPersonaDelPagamentoCrm($acquisto);
        $nuove = $this->scriviIlPianoCrm($acquisto, $campi['rate']);
        $di = count($nuove);

        return [200, ['data' => array_map(fn (array $riga) => $this->voceRataCrm($riga, $di), $nuove)]];
    }

    /**
     * crm.rate.pagamenti.crea (RateController::pagamentiCrea): 201 col solo pagamento, senza `Location`. `importo` (nella valuta del CRM),
     * `metodo`, `pagato_il` (di partenza oggi, non nel futuro), `riferimento` (al più 100). L'importo oltre il residuo è 422 `oltre_il_residuo`
     * col `residuo`; una rata pagata 409 `rata_pagata`; un contatto anonimizzato 409; oltre 100 pagamenti per rata 409 `limite_raggiunto`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaPagamentoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $rata = $this->rigaCrm('rate', $chi['workspace'], $id);

        return $this->conIdempotenza($richiesta, 'crm.rate.pagamenti.crea', $chi, $corpo, function () use ($chi, $corpo, $rata) {
            $campi = $this->testi->validaCorpo($corpo, [
                'importo' => ['bail', 'required', 'array'],
                'importo.importo' => ['bail', 'required', 'integer:strict', 'between:1,'.self::CRM_ACQUISTI_IMPORTO],
                'importo.valuta' => ['bail', 'required', 'string', function (string $campo, mixed $valuta, Closure $fail) use ($chi) {
                    if ($valuta !== $this->valutaCrm($chi['workspace'])) {
                        $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'regole.valuta_del_workspace', ['valuta' => $this->valutaCrm($chi['workspace'])]);
                    }
                }],
                'pagato_il' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
                'metodo' => ['bail', 'required', 'string', Rule::in(self::CRM_RATE_METODI)],
                'riferimento' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::CRM_RATE_RIFERIMENTO, new SenzaCarattereNullo],
            ]);
            $this->controllaLaPersonaDelPagamentoCrm($this->crm['acquisti'][$rata['acquisto_id']]);
            $residuo = self::residuoRataCrm($rata);

            if ($residuo === 0) {
                throw new Problema('rata_pagata');
            }

            if (count(array_filter($this->crm['pagamenti'], fn (array $pagamento) => $pagamento['rata_id'] === $rata['id'])) >= self::CRM_RATE_PAGAMENTI_PER_RATA) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_RATE_PAGAMENTI_PER_RATA]);
            }

            $oggi = self::oggiAcquistoCrm();
            $importo = (int) $campi['importo']['importo'];
            $giorno = (string) ($campi['pagato_il'] ?? $oggi);
            $errori = [];

            if ($importo > $residuo) {
                $errori[] = ['detail' => $this->testi->testo('regole.pagamento_oltre_il_residuo'), 'regola' => Regola::OLTRE_IL_RESIDUO, 'pointer' => '#/importo/importo'];
            }

            if ($giorno > $oggi) {
                $errori[] = ['detail' => $this->testi->testo('regole.pagamento_data_futura'), 'regola' => Regola::FUORI_INTERVALLO, 'pointer' => '#/pagato_il'];
            }

            if ($errori !== []) {
                throw new Problema('dati_non_validi', $errori, estensioni: $importo > $residuo ? ['residuo' => $residuo] : []);
            }

            $pagamento = $this->registraIlPagamentoCrm($rata, [
                'importo' => $importo, 'pagato_il' => $giorno, 'metodo' => $campi['metodo'], 'riferimento' => $campi['riferimento'] ?? null,
            ], $chi['persona']);

            return [201, ['data' => $this->vocePagamentoCrm($pagamento)], []];
        });
    }

    /**
     * crm.rate.pagamenti.elenca: i pagamenti di una rata, annullati compresi, dal più recente (`pagato_il`, poi `id`, decrescenti), a cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaPagamentiCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $rata = $this->rigaCrm('rate', $chi['workspace'], $id);
        $righe = array_values(array_filter($this->crm['pagamenti'], fn (array $pagamento) => $pagamento['rata_id'] === $rata['id']));

        return $this->paginaCrm(
            $richiesta,
            "crm.rate.pagamenti.elenca:{$rata['id']}",
            $righe,
            fn (array $uno, array $altro) => [$altro['pagato_il'], $altro['id']] <=> [$uno['pagato_il'], $uno['id']],
            fn (array $pagamento) => $this->vocePagamentoCrm($pagamento),
        );
    }

    /**
     * crm.pagamenti.annullamento.crea (PagamentiController::annullamentoCrea): annulla un pagamento (proprietario e amministratore), con un
     * `motivo` obbligatorio (al più 500). Già annullato 409 `pagamento_annullato`; di un contatto anonimizzato 409. Il pagamento resta.
     *
     * @return array{int, array<string, mixed>}
     */
    private function annullaPagamentoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $pagamento = $this->rigaCrm('pagamenti', $chi['workspace'], $id);
        $campi = $this->testi->validaCorpo($corpo, ['motivo' => ['bail', 'required', 'string', 'max:'.self::CRM_RATE_MOTIVO, new SenzaCarattereNullo]]);
        $this->controllaLaPersonaDelPagamentoCrm($this->crm['acquisti'][$pagamento['acquisto_id']]);

        if ($pagamento['annullato_il'] !== null) {
            throw new Problema('pagamento_annullato');
        }

        $this->salvaCrm('pagamenti', $pagamento['id'], ['annullato_il' => self::adessoCrm(), 'motivo_annullamento' => (string) $campi['motivo']]);
        $this->ricalcolaLaRataCrm($pagamento['rata_id']);

        return [200, ['data' => $this->vocePagamentoCrm($this->crm['pagamenti'][$pagamento['id']])]];
    }

    /**
     * La rata unica di un acquisto che nasce senza `rate` (Piano::rataUnica): l'intero prezzo, il giorno dell'acquisto, e se c'è un pagato un
     * pagamento col metodo della vendita (`altro` per `da_pagare`). Senza prezzo sopra zero, niente.
     *
     * @param  array<string, mixed>  $acquisto
     */
    private function creaLaRataUnicaCrm(array $acquisto, ?string $autore): void
    {
        if (($acquisto['prezzo_importo'] ?? 0) <= 0) {
            return;
        }

        $rata = $this->nuovaRataCrm($acquisto, 1, (string) $acquisto['acquistato_il'], (int) $acquisto['prezzo_importo'], null, $autore);

        if ($acquisto['pagato_importo'] > 0) {
            $this->registraIlPagamentoCrm($rata, [
                'importo' => (int) $acquisto['pagato_importo'], 'pagato_il' => (string) $acquisto['acquistato_il'],
                'metodo' => in_array($acquisto['metodo_pagamento'], self::CRM_RATE_METODI, true) ? $acquisto['metodo_pagamento'] : 'altro', 'riferimento' => null,
            ], $autore);
        }
    }

    /**
     * Le regole del campo `rate` (Piano::regole): da 1 a 24 righe (oltre, `troppe_rate`), ognuna con `scade_il`, `importo` e l'`etichetta`.
     *
     * @return array<string, mixed>
     */
    private function regoleDelPianoCrm(string $workspace, bool $obbligatorio): array
    {
        return [
            'rate' => ['bail', $obbligatorio ? 'required' : 'sometimes', 'array', function (string $campo, mixed $rate, Closure $fail) {
                if (! is_array($rate) || ! array_is_list($rate) || $rate === []) {
                    $this->rifiutaCrm($fail, $campo, Regola::OBBLIGATORIO, 'regole.rate_troppe', ['massimo' => self::CRM_RATE_MASSIMO]);
                } elseif (count($rate) > self::CRM_RATE_MASSIMO) {
                    $this->rifiutaCrm($fail, $campo, Regola::TROPPE_RATE, 'regole.rate_troppe', ['massimo' => self::CRM_RATE_MASSIMO]);
                }
            }],
            'rate.*' => ['array'],
            'rate.*.scade_il' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'rate.*.importo' => ['bail', 'required', 'array'],
            'rate.*.importo.importo' => ['bail', 'required', 'integer:strict', 'between:1,'.self::CRM_ACQUISTI_IMPORTO],
            'rate.*.importo.valuta' => ['bail', 'required', 'string', function (string $campo, mixed $valuta, Closure $fail) use ($workspace) {
                if ($valuta !== $this->valutaCrm($workspace)) {
                    $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'regole.valuta_del_workspace', ['valuta' => $this->valutaCrm($workspace)]);
                }
            }],
            'rate.*.etichetta' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::CRM_RATE_ETICHETTA, new SenzaCarattereNullo],
        ];
    }

    /**
     * Sostituisce il piano (Piano::scrivi): senza prezzo 422, con un pagamento non annullato 409, la somma diversa dal prezzo 422 con la
     * `differenza`. Le rate di prima escono (`eliminata`); le nuove nascono per scadenza, a parità nell'ordine del corpo, col numero da 1.
     *
     * @param  array<string, mixed>  $acquisto
     * @param  list<array<string, mixed>>  $rate
     * @return list<array<string, mixed>>
     */
    private function scriviIlPianoCrm(array $acquisto, array $rate): array
    {
        if ($acquisto['prezzo_importo'] === null) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.rate_senza_prezzo'), 'regola' => Regola::SENZA_PREZZO, 'pointer' => '#/rate']]);
        }

        if (array_filter($this->crm['pagamenti'], fn (array $pagamento) => $pagamento['acquisto_id'] === $acquisto['id'] && $pagamento['annullato_il'] === null) !== []) {
            throw new Problema('rate_con_pagamenti');
        }

        $differenza = array_sum(array_map(fn (array $rata) => (int) $rata['importo']['importo'], $rate)) - (int) $acquisto['prezzo_importo'];

        if ($differenza !== 0) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.rate_somma_diversa'), 'regola' => Regola::SOMMA_DIVERSA, 'pointer' => '#/rate']], estensioni: ['differenza' => $differenza]);
        }

        foreach ($this->crm['rate'] as $id => $vecchia) {
            if ($vecchia['acquisto_id'] === $acquisto['id'] && ! ($vecchia['eliminata'] ?? false)) {
                $this->crm['rate'][$id]['eliminata'] = true;
                $this->toccaLAcquistoCrm($acquisto['id']);
            }
        }

        $scadenze = array_column($rate, 'scade_il');
        $posizioni = array_keys($rate);
        array_multisort($scadenze, SORT_ASC, $posizioni, SORT_ASC, $rate);
        $nuove = [];

        foreach ($rate as $posizione => $rata) {
            $nuove[] = $this->nuovaRataCrm($acquisto, $posizione + 1, (string) $rata['scade_il'], (int) $rata['importo']['importo'], $rata['etichetta'] ?? null, $acquisto['creato_da']);
        }

        return $nuove;
    }

    /**
     * @param  array<string, mixed>  $acquisto
     * @return array<string, mixed>
     */
    private function nuovaRataCrm(array $acquisto, int $numero, string $scadenza, int $importo, ?string $etichetta, ?string $autore): array
    {
        $id = self::id();
        $ora = self::adessoCrm();
        $this->toccaLAcquistoCrm($acquisto['id']);

        return $this->crm['rate'][$id] = [
            'id' => $id, 'workspace' => $acquisto['workspace'], 'acquisto_id' => $acquisto['id'], 'persona_id' => $acquisto['persona_id'],
            'numero' => $numero, 'etichetta' => $etichetta, 'scade_il' => $scadenza, 'importo' => $importo, 'pagato_importo' => 0,
            'ultimo_pagamento_il' => null, 'ultimo_metodo' => null, 'creato_da' => $autore, 'creato_il' => $ora, 'aggiornato_il' => $ora,
        ];
    }

    /**
     * Scrive un pagamento (Rate::paga) e riallinea la rata e l'acquisto.
     *
     * @param  array<string, mixed>  $rata
     * @param  array{importo: int, pagato_il: string, metodo: string, riferimento: string|null}  $campi
     * @return array<string, mixed>
     */
    private function registraIlPagamentoCrm(array $rata, array $campi, ?string $autore): array
    {
        $id = self::id();
        $ora = self::adessoCrm();
        $pagamento = $this->crm['pagamenti'][$id] = $campi + [
            'id' => $id, 'workspace' => $rata['workspace'], 'rata_id' => $rata['id'], 'acquisto_id' => $rata['acquisto_id'], 'origine' => 'manuale',
            'annullato_il' => null, 'motivo_annullamento' => null, 'creato_da' => $autore, 'creato_il' => $ora, 'aggiornato_il' => $ora,
        ];
        $this->ricalcolaLaRataCrm($rata['id']);
        $this->toccaLAcquistoCrm($rata['acquisto_id']);

        return $pagamento;
    }

    /** Una rata o un pagamento scritti toccano l'acquisto (`$touches`): il suo `aggiornato_il` cresce almeno di un millisecondo. */
    private function toccaLAcquistoCrm(string $id): void
    {
        $this->crm['acquisti'][$id]['aggiornato_il'] = self::dopoCrm($this->crm['acquisti'][$id]['aggiornato_il']);
    }

    /** Riscrive il pagato della rata dai suoi pagamenti non annullati (Rate::ricalcola), e poi quello dell'acquisto (Rate::allinea). */
    private function ricalcolaLaRataCrm(string $id): void
    {
        $validi = array_values(array_filter($this->crm['pagamenti'], fn (array $pagamento) => $pagamento['rata_id'] === $id && $pagamento['annullato_il'] === null));
        usort($validi, fn (array $uno, array $altro) => [$altro['pagato_il'], $altro['id']] <=> [$uno['pagato_il'], $uno['id']]);
        $this->salvaCrm('rate', $id, [
            'pagato_importo' => array_sum(array_column($validi, 'importo')),
            'ultimo_pagamento_il' => $validi[0]['pagato_il'] ?? null,
            'ultimo_metodo' => $validi[0]['metodo'] ?? null,
        ]);
        $acquisto = $this->crm['rate'][$id]['acquisto_id'];
        $vive = array_filter($this->crm['rate'], fn (array $rata) => $rata['acquisto_id'] === $acquisto && ! ($rata['eliminata'] ?? false));
        $this->salvaCrm('acquisti', $acquisto, ['pagato_importo' => array_sum(array_column($vive, 'pagato_importo'))]);
    }

    /**
     * @param  array<string, mixed>  $acquisto
     */
    private function controllaLaPersonaDelPagamentoCrm(array $acquisto): void
    {
        if (($this->crm['persone'][$acquisto['persona_id']]['anonimizzata_il'] ?? null) !== null) {
            throw new Problema('persona_anonimizzata');
        }
    }

    /** Svuota il riferimento e il motivo dei pagamenti degli acquisti del contatto (Anonimizzazione::pagamenti). */
    private function dimenticaIPagamentiCrm(string $persona): void
    {
        $acquisti = array_keys(array_filter($this->crm['acquisti'], fn (array $acquisto) => $acquisto['persona_id'] === $persona));

        foreach ($this->crm['pagamenti'] as $id => $pagamento) {
            if (in_array($pagamento['acquisto_id'], $acquisti, true)) {
                $this->crm['pagamenti'][$id]['riferimento'] = null;
                $this->crm['pagamenti'][$id]['motivo_annullamento'] = null;
            }
        }
    }

    /**
     * Lo schema Rata (Forme::rata); `$di` sono le rate vive del piano, contate se mancano.
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function voceRataCrm(array $riga, ?int $di = null): array
    {
        $valuta = $this->valutaCrm($riga['workspace']);
        $di ??= count(array_filter($this->crm['rate'], fn (array $rata) => $rata['acquisto_id'] === $riga['acquisto_id'] && ! ($rata['eliminata'] ?? false)));

        return [
            'id' => $riga['id'],
            'acquisto_id' => $riga['acquisto_id'],
            'persona_id' => $riga['persona_id'],
            'numero' => $riga['numero'],
            'di' => $di,
            'etichetta' => $riga['etichetta'],
            'scade_il' => $riga['scade_il'],
            'importo' => ['importo' => $riga['importo'], 'valuta' => $valuta],
            'pagato' => ['importo' => $riga['pagato_importo'], 'valuta' => $valuta],
            'residuo' => ['importo' => self::residuoRataCrm($riga), 'valuta' => $valuta],
            'stato' => self::statoRataCrm($riga, self::oggiAcquistoCrm()),
            'pagata_il' => $riga['ultimo_pagamento_il'],
            'ultimo_metodo' => $riga['ultimo_metodo'],
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
        ];
    }

    /**
     * Lo schema Pagamento (Forme::pagamento).
     *
     * @param  array<string, mixed>  $pagamento
     * @return array<string, mixed>
     */
    private function vocePagamentoCrm(array $pagamento): array
    {
        return [
            'id' => $pagamento['id'],
            'rata_id' => $pagamento['rata_id'],
            'acquisto_id' => $pagamento['acquisto_id'],
            'importo' => ['importo' => $pagamento['importo'], 'valuta' => $this->valutaCrm($pagamento['workspace'])],
            'pagato_il' => $pagamento['pagato_il'],
            'metodo' => $pagamento['metodo'],
            'riferimento' => $pagamento['riferimento'],
            'origine' => $pagamento['origine'],
            'annullato_il' => $pagamento['annullato_il'] === null ? null : self::iso($pagamento['annullato_il']),
            'motivo_annullamento' => $pagamento['motivo_annullamento'],
            'creato_da' => $pagamento['creato_da'],
            'creato_il' => self::iso($pagamento['creato_il']),
        ];
    }

    /** Quanto resta da incassare di una rata (Rate::residuo). */
    private static function residuoRataCrm(array $riga): int
    {
        return max(0, $riga['importo'] - $riga['pagato_importo']);
    }

    /** Lo stato di una rata a `$oggi` (Rate::stato): `pagata`, `scaduta`, `parziale`, `in_scadenza` o `prevista`. */
    private static function statoRataCrm(array $riga, string $oggi): string
    {
        return match (true) {
            self::residuoRataCrm($riga) === 0 => 'pagata',
            $riga['scade_il'] < $oggi => 'scaduta',
            $riga['pagato_importo'] > 0 => 'parziale',
            $riga['scade_il'] <= CarbonImmutable::parse($oggi, 'UTC')->addDays(self::CRM_RATE_GIORNI_IN_SCADENZA)->format('Y-m-d') => 'in_scadenza',
            default => 'prevista',
        };
    }
}
