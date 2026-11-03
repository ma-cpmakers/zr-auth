<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Le scadenze del CRM nel backoffice finto (CRM-09, #2426 e #2427): i tipi (`crm.scadenze.tipi.elenca`, `crea`, `modifica`) e le scadenze
 * (`crm.scadenze.elenca`, `riepilogo.mostra`, `crea`, `mostra`, `modifica`, `elimina`, `rinnovo.crea`, `chiusura.crea`), con le regole, i pointer e
 * l'ordine di `ScadenzeTipiController`, `ScadenzeController` e `App\Crm\Scadenze` del backoffice. Un tipo nasce `attivo` e si archivia con
 * `stato`, mai si elimina; il nome è unico fra gli attivi (409 `nome_gia_usato`) e al più 200 per workspace, archiviati compresi. Una
 * scadenza ha un tipo, un oggetto solo fra contatto e azienda, la data, i preavvisi (una copia di quelli del tipo alla nascita) e un
 * responsabile; lo `stato` che si vede è calcolato dalle date nel fuso del workspace (`attiva`, `in_preavviso`, `scaduta`) o scritto da
 * un'azione (`rinnovata`, `chiusa`). Il rinnovo e la chiusura toccano la cronologia del contatto o dell'azienda con una riga `scadenza.*`
 * (il motivo di una chiusura ci entra solo se il tipo non è sensibile); l'oblio del contatto chiude le sue scadenze aperte e toglie il motivo
 * di tutte (`dimenticaLeScadenzeCrm`). Il finto non emette gli eventi e non ha il freno delle creazioni. L'`ETag` non è lo stesso valore
 * del backoffice: conta la forma.
 */
trait CrmScadenze
{
    private const CRM_SCADENZE_TIPI_TETTO = 200;

    /** Le scadenze aperte per workspace (`zeiras.crm.scadenze_aperte_per_workspace`). */
    private const CRM_SCADENZE_APERTE_TETTO = 100000;

    private const CRM_SCADENZE_MOTIVO = 500;

    private const CRM_SCADENZE_PREAVVISI = 5;

    private const CRM_SCADENZE_GIORNI = 365;

    private const CRM_SCADENZE_RICORRENZA = 120;

    private const CRM_SCADENZE_PREAVVISI_DI_PARTENZA = [60, 30, 7];

    private const CRM_SCADENZE_STATI_DEI_TIPI = ['attivo', 'archiviato'];

    /** Gli stati che un filtro ammette, nell'ordine del contratto; i primi tre sono quelli di partenza di `elenca`. */
    private const CRM_SCADENZE_STATI = ['attiva', 'in_preavviso', 'scaduta', 'rinnovata', 'chiusa'];

    private const CRM_SCADENZE_APERTE = ['attiva', 'in_preavviso', 'scaduta'];

    private const CRM_SCADENZE_ORDINI = ['scade_il', '-scade_il'];

    private const CRM_SCADENZE_VIETATI = ['q', 'motivo', 'titolo', 'note', 'nome', 'cognome', 'email', 'telefono', 'testo'];

    /**
     * crm.scadenze.tipi.elenca (ScadenzeTipiController::elenca): i tipi del workspace a cursore, in ordine di creazione. Senza `stato` i soli
     * attivi; `stato=attivo,archiviato` anche gli archiviati. Un cursore preso con altri stati non vale.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaScadenzeTipiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), ['stato' => $this->regolaDelleVociCrm('stato', self::CRM_SCADENZE_STATI_DEI_TIPI)]);
        $stati = self::vociCrm($query['stato'] ?? null);
        $stati = $stati === [] ? ['attivo'] : $stati;
        $righe = array_values(array_filter($this->righeCrm('scadenzeTipi', $chi['workspace']), fn (array $riga) => in_array($riga['stato'], $stati, true)));
        sort($stati);

        return $this->paginaCrm($richiesta, 'crm.scadenze.tipi.elenca:'.implode(',', $stati), $righe, fn (array $una, array $altra) => $una['id'] <=> $altra['id'], fn (array $riga) => $this->voceScadenzaTipoCrm($riga));
    }

    /**
     * crm.scadenze.tipi.crea (ScadenzeTipiController::crea): 201 con il tipo, la `Location` e l'`ETag`. Prima il corpo, poi il tetto di 200
     * tipi (409 `limite_raggiunto`) e il nome unico fra gli attivi (409 `nome_gia_usato`). Senza `preavvisi` valgono 60, 30 e 7 giorni.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaScadenzaTipoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.scadenze.tipi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, $this->regoleDelTipoDiScadenzaCrm(true));

            if (count($this->righeCrm('scadenzeTipi', $chi['workspace'])) >= self::CRM_SCADENZE_TIPI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_SCADENZE_TIPI_TETTO]);
            }

            $this->nomeDelTipoUnicoCrm($chi['workspace'], $campi['nome'], null);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['scadenzeTipi'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'nome' => $campi['nome'],
                'preavvisi' => self::preavvisiOrdinatiCrm($campi['preavvisi'] ?? self::CRM_SCADENZE_PREAVVISI_DI_PARTENZA),
                'ricorrenza_mesi' => $campi['ricorrenza_mesi'] ?? null, 'sensibile' => $campi['sensibile'] ?? false, 'stato' => 'attivo',
                'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
            $voce = $this->voceScadenzaTipoCrm($this->crm['scadenzeTipi'][$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/crm/scadenze/tipi/{$id}", 'ETag' => $voce['etag']]];
        });
    }

    /**
     * crm.scadenze.tipi.modifica (ScadenzeTipiController::modifica): JSON Merge Patch con `If-Match` letto prima del corpo (412 sulla versione
     * vecchia). `null` toglie la sola `ricorrenza_mesi`; `stato` archivia o ripristina, e un tipo che è o torna attivo non può avere il nome
     * di un altro attivo (409 `nome_gia_usato`). Una modifica che non cambia niente non scrive.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaScadenzaTipoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm('scadenzeTipi', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->vocePulitaDelTipoCrm($riga));
        $campi = $this->modificaCrm($corpo, $this->regoleDelTipoDiScadenzaCrm(false));
        $dopo = [
            'nome' => $campi['nome'] ?? $riga['nome'],
            'preavvisi' => array_key_exists('preavvisi', $campi) ? self::preavvisiOrdinatiCrm($campi['preavvisi']) : $riga['preavvisi'],
            'ricorrenza_mesi' => array_key_exists('ricorrenza_mesi', $campi) ? $campi['ricorrenza_mesi'] : $riga['ricorrenza_mesi'],
            'sensibile' => $campi['sensibile'] ?? $riga['sensibile'],
            'stato' => $campi['stato'] ?? $riga['stato'],
        ];
        $cambiati = array_keys(array_filter($dopo, fn (mixed $valore, string $campo) => $valore !== $riga[$campo], ARRAY_FILTER_USE_BOTH));

        if ($cambiati !== []) {
            if ($dopo['stato'] === 'attivo' && (in_array('nome', $cambiati, true) || in_array('stato', $cambiati, true))) {
                $this->nomeDelTipoUnicoCrm($chi['workspace'], $dopo['nome'], $riga['id']);
            }

            $this->salvaCrm('scadenzeTipi', $riga['id'], $dopo);
        }

        $voce = $this->voceScadenzaTipoCrm($this->crm['scadenzeTipi'][$riga['id']]);

        return [200, ['data' => $voce], ['ETag' => $voce['etag']]];
    }

    /**
     * crm.scadenze.elenca (ScadenzeController::elenca): le scadenze a cursore per `scade_il` crescente (o `-scade_il`) e a parità per id.
     * Senza `stato` quelle aperte; `entro` tiene le scadute entro quel giorno incluso. Con `totale=true` anche quante sono, fino a 10.000.
     * Un testo libero non sta nell'URL: `q`, `motivo` e gli altri sono vietati. Un cursore preso con altri filtri non vale.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaScadenzeCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $workspace = $chi['workspace'];
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'ordina' => ['sometimes', 'string', Rule::in(self::CRM_SCADENZE_ORDINI)],
            'stato' => $this->regolaDelleVociCrm('stato', self::CRM_SCADENZE_STATI),
            'persona_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('persone', $workspace, $id), 'regole.contatto_non_esiste')],
            'organizzazione_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('organizzazioni', $workspace, $id), 'regole.azienda_non_esiste')],
            'responsabile_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => isset($this->membri[$workspace][$id]), 'regole.responsabile_del_workspace')],
            'acquisto_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('acquisti', $workspace, $id), 'regole.acquisto_non_esiste')],
            'tipo_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('scadenzeTipi', $workspace, $id), 'regole.tipo_scadenza_non_esiste')],
            'entro' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
            'totale' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            ...array_fill_keys(self::CRM_SCADENZE_VIETATI, ['prohibited']),
        ]);
        $chiesti = self::vociCrm($query['stato'] ?? null);
        $stati = $chiesti === [] ? self::CRM_SCADENZE_APERTE : array_values(array_intersect(self::CRM_SCADENZE_STATI, $chiesti));
        $id = fn (string $campo): ?string => isset($query[$campo]) && $query[$campo] !== '' ? strtolower((string) $query[$campo]) : null;
        $filtri = [$id('persona_id'), $id('organizzazione_id'), $id('acquisto_id'), $id('tipo_id'), $id('responsabile_id')];
        $entro = isset($query['entro']) && $query['entro'] !== '' ? (string) $query['entro'] : null;
        $ordina = (string) ($query['ordina'] ?? self::CRM_SCADENZE_ORDINI[0]);
        $oggi = self::oggiAcquistoCrm();
        $nascoste = $this->chiaviNascosteDelleScadenzeCrm($workspace);
        $righe = array_values(array_filter(
            $this->righeCrm('scadenze', $workspace),
            fn (array $riga) => ($filtri[0] === null || $riga['persona_id'] === $filtri[0]) && ($filtri[1] === null || $riga['organizzazione_id'] === $filtri[1])
                && ($filtri[2] === null || $riga['acquisto_id'] === $filtri[2]) && ($filtri[3] === null || $riga['tipo_id'] === $filtri[3])
                && ($filtri[4] === null || $riga['responsabile_id'] === $filtri[4]) && ($entro === null || self::dataDiOrdineDellaScadenzaCrm($riga, $nascoste) <= $entro)
                && in_array(self::statoDellaScadenzaCrm($riga, $oggi), $stati, true),
        ));
        $verso = str_starts_with($ordina, '-') ? -1 : 1;
        $lista = 'crm.scadenze.elenca:'.substr(hash('sha256', json_encode([$ordina, $filtri, $stati, $entro], JSON_THROW_ON_ERROR)), 0, 16);
        $risposta = $this->paginaCrm($richiesta, $lista, $righe, fn (array $una, array $altra): int => $verso * strcmp(self::dataDiOrdineDellaScadenzaCrm($una, $nascoste), self::dataDiOrdineDellaScadenzaCrm($altra, $nascoste)) ?: $una['id'] <=> $altra['id'], fn (array $riga) => $this->voceScadenzaCrm($riga, $oggi));

        if (($query['totale'] ?? null) === 'true') {
            $risposta[1]['totale'] = min(count($righe), self::CRM_TETTO_DEL_TOTALE);

            if (count($righe) > self::CRM_TETTO_DEL_TOTALE) {
                $risposta[1]['totale_almeno'] = true;
            }
        }

        return $risposta;
    }

    /**
     * crm.scadenze.riepilogo.mostra (ScadenzeController::riepilogoMostra, Scadenze::riepilogo): i conti delle scadenze aperte, `scadute` (con
     * `scade_il` prima di oggi) e i cumulativi `entro_30`, `entro_60` ed `entro_90` (da oggi compreso, fino a oggi più i giorni, compreso). Si
     * restringono con `responsabile_id` e `tipo_id` (un id che non c'è è 422 `non_trovato`); ogni altro parametro è 422 `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function riepilogoScadenzeCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $workspace = $chi['workspace'];
        $regole = [
            'responsabile_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => isset($this->membri[$workspace][$id]), 'regole.responsabile_del_workspace')],
            'tipo_id' => ['sometimes', ...$this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('scadenzeTipi', $workspace, $id), 'regole.tipo_scadenza_non_esiste')],
        ];
        $estranei = array_diff(array_map('strval', array_keys(self::query($richiesta))), array_keys($regole));
        $query = $this->testi->validaQuery(self::query($richiesta), $regole + array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $attributo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $attributo]);
        }]));
        $id = fn (string $campo): ?string => isset($query[$campo]) && $query[$campo] !== '' ? strtolower((string) $query[$campo]) : null;
        $oggi = self::oggiAcquistoCrm();
        $fino = fn (int $giorni): string => CarbonImmutable::createFromFormat('Y-m-d', $oggi, 'UTC')->addDays($giorni)->format('Y-m-d');
        $aperte = array_filter(
            $this->righeCrm('scadenze', $workspace),
            fn (array $riga) => $riga['stato'] === 'attiva' && ($id('responsabile_id') === null || $riga['responsabile_id'] === $id('responsabile_id')) && ($id('tipo_id') === null || $riga['tipo_id'] === $id('tipo_id')),
        );
        $nascoste = $this->chiaviNascosteDelleScadenzeCrm($workspace);
        $entro = fn (int $giorni): int => count(array_filter($aperte, fn (array $riga) => self::dataDiOrdineDellaScadenzaCrm($riga, $nascoste) >= $oggi && self::dataDiOrdineDellaScadenzaCrm($riga, $nascoste) <= $fino($giorni)));

        return [200, ['data' => [
            'scadute' => count(array_filter($aperte, fn (array $riga) => $riga['scade_il'] < $oggi)),
            'entro_30' => $entro(30),
            'entro_60' => $entro(60),
            'entro_90' => $entro(90),
        ]]];
    }

    /**
     * crm.scadenze.crea (ScadenzeController::crea, Scadenze::crea): 201 con la scadenza, la `Location` e l'`ETag`. Prima il corpo, poi il tetto
     * delle aperte (409 `limite_raggiunto`), il tipo archiviato (422 su `#/tipo_id`) e il contatto anonimizzato (409 `persona_anonimizzata`).
     * Senza `preavvisi` valgono quelli del tipo, copiati; senza `responsabile_id` chi crea. La cronologia dell'oggetto riceve `scadenza.creata`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaScadenzaCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.scadenze.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $workspace = $chi['workspace'];
            $campi = $this->testi->validaCorpo($corpo, [
                'tipo_id' => ['bail', 'required', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'scadenzeTipi', 'regole.tipo_scadenza_non_esiste')],
                'persona_id' => ['bail', 'required_without:organizzazione_id', 'prohibits:organizzazione_id', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'persone', 'regole.contatto_non_esiste')],
                'organizzazione_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'organizzazioni', 'regole.azienda_non_esiste')],
                'acquisto_id' => ['prohibited'],
                'scade_il' => $this->regolaDellaDataDiScadenzaCrm(true),
                ...$this->regoleDeiPreavvisiCrm(),
                'responsabile_id' => $this->regolaDelResponsabile($workspace),
                'stato' => ['prohibited'],
                'origine' => ['prohibited'],
            ]);

            $aperte = array_filter($this->righeCrm('scadenze', $workspace), fn (array $riga) => $riga['stato'] === 'attiva');

            if (count($aperte) >= self::CRM_SCADENZE_APERTE_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_SCADENZE_APERTE_TETTO]);
            }

            $tipo = $this->crm['scadenzeTipi'][strtolower($campi['tipo_id'])];

            if ($tipo['stato'] === 'archiviato') {
                throw $this->erroreCrm('tipo_id', 'regole.tipo_scadenza_archiviato', Regola::VALORE_NON_AMMESSO);
            }

            $persona = isset($campi['persona_id']) ? strtolower($campi['persona_id']) : null;

            if ($persona !== null && $this->crm['persone'][$persona]['anonimizzata_il'] !== null) {
                throw new Problema('persona_anonimizzata');
            }

            $preavvisi = self::preavvisiOrdinatiCrm($campi['preavvisi'] ?? $tipo['preavvisi']);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['scadenze'][$id] = [
                'id' => $id, 'workspace' => $workspace, 'tipo_id' => $tipo['id'], 'persona_id' => $persona,
                'organizzazione_id' => isset($campi['organizzazione_id']) ? strtolower($campi['organizzazione_id']) : null, 'acquisto_id' => null,
                'scade_il' => $campi['scade_il'], 'preavvisi' => $preavvisi, 'preavviso_dal' => self::preavvisoDalCrm($campi['scade_il'], $preavvisi),
                'responsabile_id' => isset($campi['responsabile_id']) ? strtolower($campi['responsabile_id']) : $chi['persona'],
                'origine' => 'manuale', 'stato' => 'attiva', 'chiusa_il' => null, 'motivo' => null, 'rinnovata_da' => null,
                'creata_da' => $chi['persona'], 'creata_il' => $ora, 'aggiornata_il' => $ora,
            ];
            $this->rigaDellaScadenzaCrm($this->crm['scadenze'][$id], $chi['persona'], 'creata', null);

            return $this->rispostaScadenzaCrm($this->crm['scadenze'][$id], 201);
        });
    }

    /**
     * crm.scadenze.modifica (ScadenzeController::modifica, Scadenze::modifica): JSON Merge Patch di `scade_il`, `preavvisi` e
     * `responsabile_id`, con `If-Match` letto prima del corpo (412 sulla versione vecchia). `stato` è 422 `campo_vietato`; una rinnovata o
     * chiusa è 409 `scadenza_chiusa`. Un corpo che non cambia niente non scrive e risponde com'è.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaScadenzaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('scadenze', $chi['workspace'], $id);
        $this->pretendiLaScadenzaVisibileCrm($riga);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceScadenzaCrm($riga, self::oggiAcquistoCrm()));
        $campi = $this->modificaCrm($corpo, [
            'scade_il' => $this->regolaDellaDataDiScadenzaCrm(false),
            ...$this->regoleDeiPreavvisiCrm(),
            'responsabile_id' => $this->regolaDelResponsabile($chi['workspace']),
            'stato' => ['prohibited'],
        ]);
        self::controllaCheSiaApertaCrm($riga);

        $dopo = [
            'scade_il' => $campi['scade_il'] ?? $riga['scade_il'],
            'preavvisi' => array_key_exists('preavvisi', $campi) ? self::preavvisiOrdinatiCrm($campi['preavvisi']) : $riga['preavvisi'],
            'responsabile_id' => isset($campi['responsabile_id']) ? strtolower($campi['responsabile_id']) : $riga['responsabile_id'],
        ];

        if (array_filter($dopo, fn (mixed $valore, string $campo) => $valore !== $riga[$campo], ARRAY_FILTER_USE_BOTH) !== []) {
            $this->salvaCrm('scadenze', $riga['id'], $dopo + ['preavviso_dal' => self::preavvisoDalCrm($dopo['scade_il'], $dopo['preavvisi'])]);
        }

        return $this->rispostaScadenzaCrm($this->crm['scadenze'][$riga['id']], 200);
    }

    /**
     * crm.scadenze.elimina (ScadenzeController::elimina): la scadenza esce dall'API (da lì 404). La elimina chi l'ha creata, il proprietario
     * e l'amministratore (gli altri 403 `permesso_negato`). Risponde 204.
     *
     * @return array{int, null}
     */
    private function eliminaScadenzaCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('scadenze', $chi['workspace'], $id);

        if ($riga['creata_da'] !== $chi['persona'] && ! in_array($this->membri[$chi['workspace']][$chi['persona']], ['proprietario', 'amministratore'], true)) {
            throw new Problema('permesso_negato');
        }

        if ($riga['origine'] !== 'manuale') {
            throw new Problema('permesso_negato');
        }

        $this->crm['scadenze'][$riga['id']]['eliminata'] = true;

        return [204, null];
    }

    /**
     * crm.scadenze.rinnovo.crea (ScadenzeController::rinnovoCrea, Scadenze::rinnova): `{"scade_il": "AAAA-MM-GG"}`, la data della successiva.
     * `If-Match` letto prima del corpo (412). La successiva nasce con lo stesso tipo, oggetto, responsabile e preavvisi; la vecchia diventa
     * `rinnovata` con l'id della successiva in `rinnovata_da`. Già rinnovata o chiusa: 409 `scadenza_chiusa`. Risponde 201 con la successiva.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function rinnovaScadenzaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $this->pretendiLaScadenzaVisibileCrm($this->rigaCrm('scadenze', $chi['workspace'], $id));

        return $this->conIdempotenza($richiesta, 'crm.scadenze.rinnovo.crea', $chi, ['corpo' => $corpo, 'percorso' => ['scadenza' => strtolower($id)]], function () use ($richiesta, $chi, $corpo, $id) {
            $riga = $this->rigaCrm('scadenze', $chi['workspace'], $id);
            $this->pretendiLaVersioneCrm($richiesta, $this->voceScadenzaCrm($riga, self::oggiAcquistoCrm()));
            $campi = $this->testi->validaCorpo($corpo, ['scade_il' => $this->regolaDellaDataDiScadenzaCrm(true)]);
            self::controllaCheSiaApertaCrm($riga);

            $nuova = self::id();
            $ora = self::adessoCrm();
            $this->crm['scadenze'][$nuova] = [
                ...$riga, 'id' => $nuova, 'scade_il' => $campi['scade_il'], 'preavviso_dal' => self::preavvisoDalCrm($campi['scade_il'], $riga['preavvisi']),
                'stato' => 'attiva', 'chiusa_il' => null, 'motivo' => null, 'rinnovata_da' => null,
                'creata_da' => $riga['creata_da'], 'creata_il' => $ora, 'aggiornata_il' => $ora,
            ];
            $this->salvaCrm('scadenze', $riga['id'], ['stato' => 'rinnovata', 'rinnovata_da' => $nuova]);
            $this->rigaDellaScadenzaCrm($this->crm['scadenze'][$nuova], $chi['persona'], 'rinnovata', null);

            return $this->rispostaScadenzaCrm($this->crm['scadenze'][$nuova], 201);
        });
    }

    /**
     * crm.scadenze.chiusura.crea (ScadenzeController::chiusuraCrea, Scadenze::chiudi): `{"motivo": "…"}` facoltativo, fino a 500 caratteri.
     * `If-Match` letto prima del corpo (412). La scadenza diventa `chiusa` con `chiusa_il` (oggi nel fuso del workspace); già rinnovata o
     * chiusa: 409 `scadenza_chiusa`. Il motivo entra nella cronologia solo se il tipo non è sensibile.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function chiudiScadenzaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('scadenze', $chi['workspace'], $id);
        $this->pretendiLaScadenzaVisibileCrm($riga);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceScadenzaCrm($riga, self::oggiAcquistoCrm()));
        $campi = $this->testi->validaCorpo($corpo, ['motivo' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::CRM_SCADENZE_MOTIVO, new SenzaCarattereNullo]]);
        self::controllaCheSiaApertaCrm($riga);

        $motivo = $campi['motivo'] ?? null;
        $this->salvaCrm('scadenze', $riga['id'], ['stato' => 'chiusa', 'chiusa_il' => self::oggiAcquistoCrm(), 'motivo' => $motivo]);
        $this->rigaDellaScadenzaCrm($this->crm['scadenze'][$riga['id']], $chi['persona'], 'chiusa', $motivo);

        return $this->rispostaScadenzaCrm($this->crm['scadenze'][$riga['id']], 200);
    }

    /**
     * L'oblio del contatto (Anonimizzazione::scadenze): le sue scadenze ancora aperte si chiudono, senza motivo e senza una riga di
     * cronologia; il motivo di tutte, anche di quelle già chiuse o eliminate, diventa `null`. La riga `motivo` della cronologia la toglie
     * `anonimizzaCrm`.
     */
    private function dimenticaLeScadenzeCrm(string $persona): void
    {
        $oggi = self::oggiAcquistoCrm();

        foreach ($this->crm['scadenze'] as $id => $riga) {
            if ($riga['persona_id'] !== $persona) {
                continue;
            }

            $nuova = $riga['stato'] === 'attiva' ? [...$riga, 'stato' => 'chiusa', 'chiusa_il' => $oggi, 'motivo' => null] : [...$riga, 'motivo' => null];

            if ($nuova != $riga) {
                $nuova['aggiornata_il'] = self::dopoCrm($riga['aggiornata_il']);
                $this->crm['scadenze'][$id] = $nuova;
            }
        }
    }

    /**
     * Lo schema ScadenzaTipo (Forme::scadenzaTipo) con il suo `etag`: l'impronta della voce senza l'`etag`.
     *
     * @return array<string, mixed>
     */
    private function voceScadenzaTipoCrm(array $riga): array
    {
        return [...$this->vocePulitaDelTipoCrm($riga), 'etag' => self::etagCrm($this->vocePulitaDelTipoCrm($riga))];
    }

    /** @return array<string, mixed> la voce del tipo senza l'`etag`: ciò che l'`ETag` e l'`If-Match` confrontano */
    private function vocePulitaDelTipoCrm(array $riga): array
    {
        return [
            'id' => $riga['id'], 'nome' => $riga['nome'], 'preavvisi' => $riga['preavvisi'], 'ricorrenza_mesi' => $riga['ricorrenza_mesi'],
            'sensibile' => $riga['sensibile'], 'stato' => $riga['stato'],
            'creato_il' => self::iso($riga['creato_il']), 'aggiornato_il' => self::iso($riga['aggiornato_il']),
        ];
    }

    /**
     * Lo schema Scadenza (Forme::scadenza): `stato` è quello che si vede oggi (`$oggi`, nel fuso del workspace). Se la scadenza è nata da un
     * campo «sensibile» che chi chiama non vede, `scade_il`, `prossimo_preavviso_il` e `motivo` sono `null` (lo `stato` resta).
     *
     * @return array<string, mixed>
     */
    private function voceScadenzaCrm(array $riga, string $oggi): array
    {
        $nascosta = self::scadenzaNascostaCrm($riga, $this->chiaviNascosteDelleScadenzeCrm($riga['workspace']));

        return [
            'id' => $riga['id'], 'tipo_id' => $riga['tipo_id'], 'persona_id' => $riga['persona_id'], 'organizzazione_id' => $riga['organizzazione_id'],
            'acquisto_id' => $riga['acquisto_id'], 'scade_il' => $nascosta ? null : $riga['scade_il'], 'preavvisi' => $riga['preavvisi'],
            'responsabile_id' => $riga['responsabile_id'], 'origine' => $riga['origine'], 'stato' => self::statoDellaScadenzaCrm($riga, $oggi),
            'chiusa_il' => $riga['chiusa_il'], 'motivo' => $nascosta ? null : $riga['motivo'], 'rinnovata_da' => $riga['rinnovata_da'],
            'prossimo_preavviso_il' => $nascosta ? null : self::prossimoPreavvisoCrm($riga, $oggi), 'creata_da' => $riga['creata_da'],
            'creata_il' => self::iso($riga['creata_il']), 'aggiornata_il' => self::iso($riga['aggiornata_il']),
        ];
    }

    /** @return array{int, array<string, mixed>, array<string, string>} la scadenza com'è adesso, con l'`ETag` (e la `Location` di una creazione) */
    private function rispostaScadenzaCrm(array $riga, int $stato): array
    {
        $voce = $this->voceScadenzaCrm($riga, self::oggiAcquistoCrm());
        $intestazioni = ['ETag' => self::etagCrm($voce)];

        return [$stato, ['data' => $voce], $stato === 201 ? ['Location' => "/v1/crm/scadenze/{$riga['id']}"] + $intestazioni : $intestazioni];
    }

    /** Lo stato che si vede (Scadenza::statoVisibile): quello scritto, tranne una `attiva` che è `scaduta` dal giorno dopo `scade_il` e `in_preavviso` da `preavviso_dal`. */
    private static function statoDellaScadenzaCrm(array $riga, string $oggi): string
    {
        if ($riga['stato'] !== 'attiva') {
            return $riga['stato'];
        }

        if ($oggi > $riga['scade_il']) {
            return 'scaduta';
        }

        return $riga['preavviso_dal'] !== null && $oggi >= $riga['preavviso_dal'] ? 'in_preavviso' : 'attiva';
    }

    /** La prima data di preavviso non ancora passata (Scadenza::prossimoPreavviso), o null se la scadenza non è aperta o non ne ha più. */
    private static function prossimoPreavvisoCrm(array $riga, string $oggi): ?string
    {
        if ($riga['stato'] !== 'attiva') {
            return null;
        }

        $date = array_map(fn (int $giorni) => self::menoGiorniCrm($riga['scade_il'], $giorni), $riga['preavvisi']);
        sort($date);

        foreach ($date as $data) {
            if ($data >= $oggi) {
                return $data;
            }
        }

        return null;
    }

    private static function menoGiorniCrm(string $data, int $giorni): string
    {
        return CarbonImmutable::createFromFormat('Y-m-d', substr($data, 0, 10), 'UTC')->subDays($giorni)->format('Y-m-d');
    }

    /** @param  list<int>  $preavvisi */
    private static function preavvisoDalCrm(string $scadeIl, array $preavvisi): ?string
    {
        return $preavvisi === [] ? null : self::menoGiorniCrm($scadeIl, max($preavvisi));
    }

    /**
     * @param  list<int>  $preavvisi
     * @return list<int> dal più lontano al più vicino (ScadenzaTipo::ordinati)
     */
    private static function preavvisiOrdinatiCrm(array $preavvisi): array
    {
        rsort($preavvisi);

        return $preavvisi;
    }

    /** Una rinnovata o chiusa non cambia più: 409 `scadenza_chiusa`. */
    private static function controllaCheSiaApertaCrm(array $riga): void
    {
        if ($riga['stato'] !== 'attiva') {
            throw new Problema('scadenza_chiusa');
        }
    }

    /**
     * La scadenza che segue un campo data con `genera_scadenza` (Scadenze::segui, #2696): per ogni valore cambiato di un contatto, la scadenza
     * aperta di quel contatto per quel campo si aggiorna, nasce `attiva` di origine `campo` (preavvisi del tipo, responsabile del contatto) o,
     * se il valore è tolto, si archivia (`eliminata`, da lì 404). Il tetto delle aperte è `limite_raggiunto`; un tipo archiviato non ne fa nascere.
     * Il valore di un campo sensibile non entra nella cronologia.
     *
     * @param  array<string, array{tipo: string, sensibile: bool, prima: mixed, dopo: mixed, riga: mixed}>  $piano
     */
    private function seguiLeScadenzeDeiCampiCrm(string $workspace, string $persona, ?string $autore, array $piano): void
    {
        foreach ($this->campiTuoiCrm($workspace, 'persona', true) as $chiave => $campo) {
            $tipoId = $campo['genera_scadenza']['tipo_id'] ?? null;

            if (! isset($piano[$chiave]) || $campo['tipo'] !== 'data' || $tipoId === null) {
                continue;
            }

            $data = $piano[$chiave]['dopo'];
            $aperta = null;

            foreach ($this->righeCrm('scadenze', $workspace) as $riga) {
                if ($riga['persona_id'] === $persona && ($riga['campo_chiave'] ?? null) === $chiave && $riga['stato'] === 'attiva') {
                    $aperta = $riga;
                }
            }

            if ($data === null) {
                if ($aperta !== null) {
                    $this->crm['scadenze'][$aperta['id']]['eliminata'] = true;
                }

                continue;
            }

            if ($aperta !== null) {
                $this->salvaCrm('scadenze', $aperta['id'], ['scade_il' => $data, 'preavviso_dal' => self::preavvisoDalCrm($data, $aperta['preavvisi'])]);

                continue;
            }

            $tipo = $this->crm['scadenzeTipi'][$tipoId] ?? null;

            if ($tipo === null || $tipo['stato'] === 'archiviato') {
                continue;
            }

            $aperte = array_filter($this->righeCrm('scadenze', $workspace), fn (array $riga) => $riga['stato'] === 'attiva');

            if (count($aperte) >= self::CRM_SCADENZE_APERTE_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_SCADENZE_APERTE_TETTO]);
            }

            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['scadenze'][$id] = [
                'id' => $id, 'workspace' => $workspace, 'tipo_id' => $tipo['id'], 'persona_id' => $persona, 'organizzazione_id' => null, 'acquisto_id' => null,
                'campo_chiave' => $chiave, 'scade_il' => $data, 'preavvisi' => $tipo['preavvisi'], 'preavviso_dal' => self::preavvisoDalCrm($data, $tipo['preavvisi']),
                'responsabile_id' => $this->crm['persone'][$persona]['responsabile_id'] ?? $autore, 'origine' => 'campo', 'stato' => 'attiva', 'chiusa_il' => null,
                'motivo' => null, 'rinnovata_da' => null, 'creata_da' => $autore, 'creata_il' => $ora, 'aggiornata_il' => $ora,
            ];
            $this->rigaDellaScadenzaCrm($this->crm['scadenze'][$id], $autore, 'creata', null, ! ($campo['sensibile'] ?? false) && ! $tipo['sensibile']);
        }
    }

    /**
     * Le chiavi dei campi «sensibili» dei contatti, anche eliminati, che chi chiama non vede (Scadenze::chiaviNascoste): nessuna per il
     * proprietario e gli amministratori.
     *
     * @return list<string>
     */
    private function chiaviNascosteDelleScadenzeCrm(string $workspace): array
    {
        return $this->vedeISensibiliCrm ? [] : $this->chiaviSensibiliDeiContattiCrm($workspace);
    }

    /** @return list<string> */
    private function chiaviSensibiliDeiContattiCrm(string $workspace): array
    {
        $this->campiDelWorkspaceCrm($workspace);
        $chiavi = [];

        foreach ($this->campiCrm[$workspace]['persona']['campi'] as $campo) {
            if ($campo['origine'] === 'tuo' && ($campo['sensibile'] ?? false)) {
                $chiavi[] = (string) $campo['codice'];
            }
        }

        return $chiavi;
    }

    /** Se la data di questa scadenza è nascosta a chi chiama: nata da un campo sensibile che non vede (Scadenze::nascosta). @param list<string> $nascoste */
    private static function scadenzaNascostaCrm(array $riga, array $nascoste): bool
    {
        return $nascoste !== [] && ($riga['origine'] ?? null) === 'campo' && in_array($riga['campo_chiave'] ?? null, $nascoste, true);
    }

    /** Il giorno su cui una scadenza si ordina e si filtra: per chi non ne vede la data il più lontano (Scadenze::chiaveDiOrdine). @param list<string> $nascoste */
    private static function dataDiOrdineDellaScadenzaCrm(array $riga, array $nascoste): string
    {
        return self::scadenzaNascostaCrm($riga, $nascoste) ? '9999-12-31' : (string) $riga['scade_il'];
    }

    /** Chi non vede la data di una scadenza non la cambia, non la rinnova e non la chiude: 403 `permesso_negato` (Scadenze::pretendiVisibile). */
    private function pretendiLaScadenzaVisibileCrm(array $riga): void
    {
        if (self::scadenzaNascostaCrm($riga, $this->chiaviNascosteDelleScadenzeCrm($riga['workspace']))) {
            throw new Problema('permesso_negato');
        }
    }

    /** Se la scadenza è nata da un campo che oggi è sensibile: la sua data è il valore di quel campo (Scadenze::dalCampoSensibile). */
    private function dalCampoSensibileCrm(array $scadenza): bool
    {
        return ($scadenza['origine'] ?? null) === 'campo' && in_array($scadenza['campo_chiave'] ?? null, $this->chiaviSensibiliDeiContattiCrm($scadenza['workspace']), true);
    }

    /**
     * La riga della cronologia dell'oggetto della scadenza (il contatto o l'azienda), con l'autore e l'id della scadenza (Scadenze::riga): il
     * `tipo` è `scadenza.<azione>`, le voci il tipo e la data, e per una chiusura il motivo solo se il tipo non è sensibile. Se la scadenza è
     * nata da un campo «sensibile» né la data né il motivo entrano (Scadenze::voci e dalCampoSensibile).
     */
    private function rigaDellaScadenzaCrm(array $scadenza, ?string $autore, string $azione, ?string $motivo, bool $conLaData = true): void
    {
        $voci = [['campo' => 'tipo_id', 'dopo' => $scadenza['tipo_id']]];
        $dalCampo = $this->dalCampoSensibileCrm($scadenza);

        if ($conLaData && ! $dalCampo) {
            $voci[] = ['campo' => 'scade_il', 'dopo' => $scadenza['scade_il']];
        }

        if ($motivo !== null && ! $dalCampo && ! $this->crm['scadenzeTipi'][$scadenza['tipo_id']]['sensibile']) {
            $voci[] = ['campo' => 'motivo', 'dopo' => $motivo];
        }

        foreach (['persone' => 'persona_id', 'organizzazioni' => 'organizzazione_id'] as $tipo => $colonna) {
            if ($scadenza[$colonna] !== null) {
                $this->cambioCrm($tipo, $scadenza[$colonna], $autore, 'scadenza.'.$azione, $voci, null, null, $scadenza['id']);
            }
        }
    }

    /**
     * Le regole di `nome`, `preavvisi`, `ricorrenza_mesi`, `sensibile` e, in una modifica, `stato` di un tipo (ScadenzeTipiController::regole).
     *
     * @return array<string, mixed>
     */
    private function regoleDelTipoDiScadenzaCrm(bool $nuovo): array
    {
        $regole = [
            'nome' => ['bail', $nuovo ? 'required' : 'filled', 'string', 'max:60', new NomeScritto],
            ...$this->regoleDeiPreavvisiCrm(),
            'ricorrenza_mesi' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:1,'.self::CRM_SCADENZE_RICORRENZA],
            'sensibile' => ['bail', 'sometimes', 'boolean:strict'],
        ];

        return $nuovo ? $regole : $regole + ['stato' => ['bail', 'filled', 'string', Rule::in(self::CRM_SCADENZE_STATI_DEI_TIPI)]];
    }

    /** @return array<string, list<mixed>> fino a 5 interi da 0 a 365 giorni, senza doppioni (Scadenze::regoleDeiPreavvisi) */
    private function regoleDeiPreavvisiCrm(): array
    {
        return [
            'preavvisi' => ['bail', 'sometimes', 'array', 'list', 'max:'.self::CRM_SCADENZE_PREAVVISI],
            'preavvisi.*' => ['bail', 'integer:strict', 'between:0,'.self::CRM_SCADENZE_GIORNI, 'distinct'],
        ];
    }

    /** @return list<string> una data `AAAA-MM-GG` vera, dal 2000 al 2100 (Scadenze::regolaDellaData) */
    private function regolaDellaDataDiScadenzaCrm(bool $obbligatoria): array
    {
        return ['bail', $obbligatoria ? 'required' : 'filled', 'string', 'date_format:Y-m-d', 'after_or_equal:2000-01-01', 'before_or_equal:2100-12-31'];
    }

    /** Il nome non è di un altro tipo attivo, senza maiuscole, accenti e spazi ai bordi: altrimenti 409 `nome_gia_usato`. */
    private function nomeDelTipoUnicoCrm(string $workspace, string $nome, ?string $tranne): void
    {
        foreach ($this->righeCrm('scadenzeTipi', $workspace) as $altro) {
            if ($altro['stato'] === 'attivo' && $altro['id'] !== $tranne && self::nomeSimileCrm($altro['nome'], null) === self::nomeSimileCrm($nome, null)) {
                throw new Problema('nome_gia_usato');
            }
        }
    }
}
