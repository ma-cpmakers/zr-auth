<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * I preventivi del CRM nel backoffice finto (CRM-06, #2387): `crm.preventivi.elenca`, `crea`, `mostra`, `modifica`, `elimina`, `invio.crea`, `rifiuto.crea` e `accettazione.crea` (#2389), con le
 * regole, i pointer e l'ordine di `PreventiviController` e di `App\Crm\Preventivi` del backoffice. Un preventivo nasce in `bozza` col suo
 * numero (`P-2026-001`, il successivo dell'anno, eliminati compresi); ha da 1 a 50 righe e un piano dei pagamenti (`unica`, senza rate;
 * `acconto_saldo`, due; `rate`, da due a 24) la cui somma è il totale. I prezzi sono IVA inclusa: l'imponibile di ogni aliquota si scorpora
 * dalla somma delle sue righe, e ogni divisione arrotonda al più vicino, a metà in su. Le righe e il piano si sostituiscono interi; i totali
 * li calcola il finto. Una bozza inviata (`invio.crea`, modo `manuale`) non si modifica né si elimina più (409 `preventivo_non_modificabile`);
 * `scaduto` è lo stato che si vede di un inviato con la validità passata, calcolato e mai scritto. Il contatto anonimizzato (409
 * `persona_anonimizzata`) perde l'`introduzione`, il motivo del rifiuto, il nome e la nota dell'accettazione e l'etichetta delle rate di ogni suo preventivo. Il finto non emette gli
 * eventi, e non ha il freno delle creazioni. L'`ETag` non è lo stesso valore del backoffice: conta la forma. La creazione risponde
 * con la sola `Location`, com'è nel contratto.
 */
trait CrmPreventivi
{
    /** I preventivi per workspace, eliminati compresi (`zeiras.crm.preventivi_per_workspace`). */
    private const CRM_PREVENTIVI_TETTO = 100000;

    private const CRM_PREVENTIVI_RIGHE = 50;

    private const CRM_PREVENTIVI_DESCRIZIONE = 500;

    private const CRM_PREVENTIVI_TESTO = 5000;

    private const CRM_PREVENTIVI_QUANTITA = 9999;

    private const CRM_PREVENTIVI_GIORNI_DI_VALIDITA = 30;

    private const CRM_PREVENTIVI_PAGINA_MASSIMA = 50;

    private const CRM_PREVENTIVI_PIANI = ['unica', 'acconto_saldo', 'rate'];

    private const CRM_PREVENTIVI_STATI = ['bozza', 'inviato', 'visto', 'accettato', 'rifiutato', 'scaduto', 'sostituito'];

    private const CRM_PREVENTIVI_ORDINI = ['-creato_il', 'creato_il'];

    /**
     * crm.preventivi.elenca (PreventiviController::elenca): i preventivi a cursore, nella forma di `mostra`. Filtri `persona_id`,
     * `organizzazione_id`, `trattativa_id`, `stato` (a virgole), `numero` (esatto); `ordina` `-creato_il` (di partenza) o `creato_il`, a parità
     * l'id nello stesso verso; al più 50 per pagina. `q` è vietato: un nome non sta nell'URL. Con `totale=true` anche quanti sono, fino a 10.000.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaPreventiviCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::CRM_PREVENTIVI_PAGINA_MASSIMA],
            'persona_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'organizzazione_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'trattativa_id' => ['bail', 'sometimes', 'string', 'ulid'],
            'numero' => ['bail', 'sometimes', 'string', 'regex:/^P-\d{4}-\d{3,}$/'],
            'stato' => $this->regolaDelleVociCrm('stato', self::CRM_PREVENTIVI_STATI),
            'ordina' => ['bail', 'sometimes', 'string', Rule::in(self::CRM_PREVENTIVI_ORDINI)],
            'totale' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            'q' => ['prohibited'],
        ]);
        $stati = array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($query['stato'] ?? ''))), fn (string $voce) => $voce !== '')));
        $persona = isset($query['persona_id']) ? strtolower($query['persona_id']) : null;
        $organizzazione = isset($query['organizzazione_id']) ? strtolower($query['organizzazione_id']) : null;
        $trattativa = isset($query['trattativa_id']) ? strtolower($query['trattativa_id']) : null;
        $numero = $query['numero'] ?? null;
        $ordina = (string) ($query['ordina'] ?? self::CRM_PREVENTIVI_ORDINI[0]);
        $righe = array_values(array_filter(
            $this->righeCrm('preventivi', $chi['workspace']),
            fn (array $riga) => ($persona === null || $riga['persona_id'] === $persona) && ($organizzazione === null || $riga['organizzazione_id'] === $organizzazione)
                && ($trattativa === null || $riga['trattativa_id'] === $trattativa) && ($stati === [] || in_array(self::statoDelPreventivoCrm($riga), $stati, true))
                && ($numero === null || self::numeroPreventivoCrm($riga) === $numero),
        ));
        $verso = str_starts_with($ordina, '-') ? -1 : 1;
        $lista = 'crm.preventivi.elenca:'.substr(hash('sha256', json_encode([$ordina, $persona, $organizzazione, $trattativa, $stati, $numero], JSON_THROW_ON_ERROR)), 0, 16);
        $ordine = fn (array $una, array $altra): int => $verso * ([self::iso($una['creato_il']), $una['id']] <=> [self::iso($altra['creato_il']), $altra['id']]);
        $risposta = $this->paginaCrm($richiesta, $lista, $righe, $ordine, fn (array $riga) => $this->vocePreventivoCrm($riga));

        if (($query['totale'] ?? null) === 'true') {
            $risposta[1]['totale'] = min(count($righe), self::CRM_RATE_TETTO_DEL_TOTALE);

            if (count($righe) > self::CRM_RATE_TETTO_DEL_TOTALE) {
                $risposta[1]['totale_almeno'] = true;
            }
        }

        return $risposta;
    }

    /**
     * crm.preventivi.crea (PreventiviController::crea): 201 con il preventivo e la `Location`, senza `ETag` (si legge con `mostra`). Prima il
     * corpo, poi il tetto (409 `limite_raggiunto`), il contatto (409 `persona_anonimizzata`) e, tutti insieme sul loro pointer, il contatto o
     * l'azienda che manca, la validità passata, le righe e il piano. Senza `valido_fino_al` vale oggi più 30 giorni.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaPreventivoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.preventivi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, $this->regoleDelPreventivoCrm($chi['workspace'], true));
            $tutti = array_filter($this->crm['preventivi'], fn (array $riga) => $riga['workspace'] === $chi['workspace']);

            if (count($tutti) >= self::CRM_PREVENTIVI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_PREVENTIVI_TETTO]);
            }

            $oggi = self::oggiAcquistoCrm();
            $valuta = $this->valutaCrm($chi['workspace']);
            [$persona, $organizzazione, $trattativa] = [$campi['persona_id'] ?? null, $campi['organizzazione_id'] ?? null, $campi['trattativa_id'] ?? null];
            $errori = $this->intestatarioDelPreventivoCrm($persona, $organizzazione);
            $validita = $campi['valido_fino_al'] ?? CarbonImmutable::parse($oggi, 'UTC')->addDays(self::CRM_PREVENTIVI_GIORNI_DI_VALIDITA)->format('Y-m-d');

            if ($validita < $oggi) {
                $errori[] = self::erroreDelPreventivoCrm(['valido_fino_al'], $this->testi->testo('regole.preventivo_validita_passata'), Regola::FUORI_INTERVALLO);
            }

            $righe = $this->righeDelPreventivoCrm($campi['righe'], $valuta, $errori);
            $totale = $righe === null ? null : self::totaliPreventivoCrm($righe['conti'])['totale'];
            $piano = $this->pianoDelPreventivoCrm($campi['piano'] ?? null, $totale, $valuta, $errori, $differenza);

            if ($errori !== []) {
                throw new Problema('dati_non_validi', $errori, estensioni: $differenza === null ? [] : ['differenza' => $differenza]);
            }

            $anno = (int) substr($oggi, 0, 4);
            $progressivo = 1 + (int) max([0, ...array_map(fn (array $riga) => $riga['anno'] === $anno ? $riga['progressivo'] : 0, array_values($tutti))]);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['preventivi'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'anno' => $anno, 'progressivo' => $progressivo, 'stato' => 'bozza',
                'persona_id' => $persona === null ? null : strtolower((string) $persona),
                'organizzazione_id' => $organizzazione === null ? null : strtolower((string) $organizzazione),
                'trattativa_id' => $trattativa === null ? null : strtolower((string) $trattativa),
                'valido_fino_al' => $validita, 'valuta' => $valuta, 'piano_tipo' => $piano['tipo'],
                'introduzione' => $campi['introduzione'] ?? null, 'condizioni' => $campi['condizioni'] ?? null,
                'inviato_il' => null, 'rifiutato_il' => null, 'rifiuto_motivo' => null,
                'righe' => self::scriviLeRighePreventivoCrm($righe['righe']), 'rate' => self::scriviLeRatePreventivoCrm($piano['rate']),
                'creato_il' => $ora, 'aggiornato_il' => $ora, 'creato_da' => $chi['persona'],
            ];

            return [201, ['data' => $this->vocePreventivoCrm($this->crm['preventivi'][$id])], ['Location' => "/v1/crm/preventivi/{$id}"]];
        });
    }

    /**
     * crm.preventivi.modifica (PreventiviController::modifica): JSON Merge Patch di una bozza, con `If-Match` letto prima del corpo (412 sulla
     * versione vecchia). `righe` e `piano` si sostituiscono interi; `stato` è 422 `campo_vietato`. Se cambiano solo le righe il piano di prima
     * deve sommare al totale nuovo, e se cambia solo il piano deve sommare al totale di prima (422 `somma_diversa`). Una modifica che non
     * cambia niente non scrive e risponde com'è.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaPreventivoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('preventivi', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->vocePreventivoCrm($riga));
        $campi = $this->modificaCrm($corpo, $this->regoleDelPreventivoCrm($chi['workspace'], false));

        if ($riga['stato'] !== 'bozza') {
            throw new Problema('preventivo_non_modificabile');
        }

        $oggi = self::oggiAcquistoCrm();
        $persona = array_key_exists('persona_id', $campi) ? $campi['persona_id'] : $riga['persona_id'];
        $organizzazione = array_key_exists('organizzazione_id', $campi) ? $campi['organizzazione_id'] : $riga['organizzazione_id'];
        $errori = $this->intestatarioDelPreventivoCrm($persona, $organizzazione, $riga);
        $nuova = $riga;

        foreach (['persona_id', 'organizzazione_id', 'trattativa_id'] as $campo) {
            if (array_key_exists($campo, $campi)) {
                $nuova[$campo] = $campi[$campo] === null ? null : strtolower((string) $campi[$campo]);
            }
        }

        if (array_key_exists('valido_fino_al', $campi) && $campi['valido_fino_al'] !== $riga['valido_fino_al']) {
            if ($campi['valido_fino_al'] < $oggi) {
                $errori[] = self::erroreDelPreventivoCrm(['valido_fino_al'], $this->testi->testo('regole.preventivo_validita_passata'), Regola::FUORI_INTERVALLO);
            }

            $nuova['valido_fino_al'] = $campi['valido_fino_al'];
        }

        $righeDate = array_key_exists('righe', $campi);
        $righe = $righeDate ? $this->righeDelPreventivoCrm($campi['righe'], $riga['valuta'], $errori) : null;
        $contiAttuali = array_map(fn (array $voce) => ['importo' => self::importoDiRigaPreventivoCrm($voce['quantita'], $voce['prezzo_unitario_importo'], $voce['sconto_percento']), 'aliquota' => $voce['aliquota_iva']], $riga['righe']);
        $totale = $righeDate ? ($righe === null ? null : self::totaliPreventivoCrm($righe['conti'])['totale']) : self::totaliPreventivoCrm($contiAttuali)['totale'];

        $pianoDato = array_key_exists('piano', $campi);
        $attuale = ['tipo' => $riga['piano_tipo'], 'rate' => array_map(fn (array $rata) => [
            'scade_il' => $rata['scade_il'], 'importo' => ['importo' => $rata['importo'], 'valuta' => $riga['valuta']], 'etichetta' => $rata['etichetta'],
        ], $riga['rate'])];
        $piano = $this->pianoDelPreventivoCrm($pianoDato ? $campi['piano'] : $attuale, $totale, $riga['valuta'], $errori, $differenza);

        foreach (['introduzione', 'condizioni'] as $campo) {
            if (array_key_exists($campo, $campi)) {
                $nuova[$campo] = $campi[$campo];
            }
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori, estensioni: $differenza === null ? [] : ['differenza' => $differenza]);
        }

        if ($righeDate && $righe !== null && self::righeDiverseDelPreventivoCrm($riga['righe'], $righe['righe'])) {
            $nuova['righe'] = self::scriviLeRighePreventivoCrm($righe['righe']);
        }

        if ($pianoDato && $piano !== null && self::pianoDiversoDelPreventivoCrm($attuale, $piano)) {
            $nuova['piano_tipo'] = $piano['tipo'];
            $nuova['rate'] = self::scriviLeRatePreventivoCrm($piano['rate']);
        }

        if ($nuova != $riga) {
            $nuova['aggiornato_il'] = self::dopoCrm($riga['aggiornato_il']);
            $this->crm['preventivi'][$riga['id']] = $nuova;
        }

        $preventivo = $this->vocePreventivoCrm($this->crm['preventivi'][$riga['id']]);

        return [200, ['data' => $preventivo], ['ETag' => self::etagCrm($preventivo)]];
    }

    /**
     * crm.preventivi.elimina (PreventiviController::elimina): una bozza esce dall'API (da lì 404) con le sue righe e le sue rate; il numero non
     * si riusa. La elimina chi l'ha creata, il proprietario e l'amministratore (gli altri 403 `permesso_negato`). Risponde 204.
     *
     * @return array{int, null}
     */
    private function eliminaPreventivoCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('preventivi', $chi['workspace'], $id);

        if ($riga['creato_da'] !== $chi['persona'] && ! in_array($this->membri[$chi['workspace']][$chi['persona']], ['proprietario', 'amministratore'], true)) {
            throw new Problema('permesso_negato');
        }

        if ($riga['stato'] !== 'bozza') {
            throw new Problema('preventivo_non_modificabile');
        }

        $this->crm['preventivi'][$riga['id']]['eliminata'] = true;

        return [204, null];
    }

    /**
     * Lo stato che si vede di un preventivo (Preventivi::stato): quello scritto, tranne un inviato o visto con la validità passata, che è
     * `scaduto` (calcolato nel fuso del workspace, mai scritto).
     */
    private static function statoDelPreventivoCrm(array $riga, ?string $oggi = null): string
    {
        if (in_array($riga['stato'], ['inviato', 'visto'], true) && $riga['valido_fino_al'] < ($oggi ?? self::oggiAcquistoCrm())) {
            return 'scaduto';
        }

        return $riga['stato'];
    }

    /**
     * crm.preventivi.invio.crea (PreventiviController::invio): `{"modo": "manuale"}`; `If-Match` letto prima del corpo (412 sulla versione
     * vecchia). Una bozza diventa `inviato` con `inviato_il`; non è una bozza: 409 `preventivo_stato_non_ammesso`; contatto anonimizzato: 409
     * `persona_anonimizzata`; validità passata: 422 su `#/valido_fino_al`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function inviaPreventivoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('preventivi', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->vocePreventivoCrm($riga));
        $this->testi->validaCorpo($corpo, ['modo' => ['bail', 'required', 'string', Rule::in(['manuale'])]]);
        $this->senzaContattoAnonimizzatoPreventivoCrm($riga);

        if ($riga['stato'] !== 'bozza') {
            throw new Problema('preventivo_stato_non_ammesso');
        }

        if ($riga['valido_fino_al'] < self::oggiAcquistoCrm()) {
            throw new Problema('dati_non_validi', [self::erroreDelPreventivoCrm(['valido_fino_al'], $this->testi->testo('regole.preventivo_validita_passata'), Regola::FUORI_INTERVALLO)]);
        }

        $ora = self::dopoCrm($riga['aggiornato_il']);
        $this->crm['preventivi'][$id]['stato'] = 'inviato';
        $this->crm['preventivi'][$id]['inviato_il'] = $ora;
        $this->crm['preventivi'][$id]['aggiornato_il'] = $ora;
        $preventivo = $this->vocePreventivoCrm($this->crm['preventivi'][$id]);

        return [200, ['data' => $preventivo], ['ETag' => self::etagCrm($preventivo)]];
    }

    /**
     * crm.preventivi.rifiuto.crea (PreventiviController::rifiuto): `{"motivo": "…"}` facoltativo (al più 500). Da `inviato` lo stato diventa
     * `rifiutato`, con `rifiutato_il` e il motivo; l'opportunità non cambia. Scaduto: 409 `preventivo_scaduto`; non inviato o già chiuso: 409
     * `preventivo_stato_non_ammesso`; contatto anonimizzato: 409 `persona_anonimizzata`.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function rifiutaPreventivoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('preventivi', $chi['workspace'], $id);
        $campi = $this->testi->validaCorpo($corpo, ['motivo' => ['bail', 'sometimes', 'nullable', 'string', 'max:500']]);
        $this->senzaContattoAnonimizzatoPreventivoCrm($riga);
        $stato = self::statoDelPreventivoCrm($riga);

        if ($stato === 'scaduto') {
            throw new Problema('preventivo_scaduto');
        }

        if (! in_array($stato, ['inviato', 'visto'], true)) {
            throw new Problema('preventivo_stato_non_ammesso');
        }

        $motivo = $campi['motivo'] ?? null;
        $ora = self::dopoCrm($riga['aggiornato_il']);
        $this->crm['preventivi'][$id]['stato'] = 'rifiutato';
        $this->crm['preventivi'][$id]['rifiutato_il'] = $ora;
        $this->crm['preventivi'][$id]['rifiuto_motivo'] = $motivo === '' ? null : $motivo;
        $this->crm['preventivi'][$id]['aggiornato_il'] = $ora;
        $preventivo = $this->vocePreventivoCrm($this->crm['preventivi'][$id]);

        return [200, ['data' => $preventivo], ['ETag' => self::etagCrm($preventivo)]];
    }

    /**
     * crm.preventivi.accettazione.crea (PreventiviController::accettazione, Preventivi::accetta): `{nome, nota, accettato_il, persona_id}`, il solo
     * nome obbligatorio. Da `inviato` o `visto` il preventivo diventa `accettato` e nelle stesse mani nasce un acquisto per riga (col prezzo della
     * riga dopo lo sconto, la voce del catalogo o, per una riga libera, un servizio senza voce), ognuno col suo piano di rate in proporzione
     * (`ripartisci`), l'opportunità aperta diventa vinta (già chiusa resta com'è e `accettazione.opportunita_gia_chiusa` dice quale) con la riga
     * `preventivo.accettato` nella sua cronologia, e il primo acquisto fa «cliente» il contatto. Già accettato: 409 `gia_accettato`; scaduto: 409
     * `preventivo_scaduto`; non inviato: 409 `preventivo_stato_non_ammesso`; contatto anonimizzato: 409 `persona_anonimizzata`; oltre il tetto
     * degli acquisti: 409 `limite_raggiunto`. Risponde 201 col preventivo e la `Location` del preventivo; accetta Idempotency-Key.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function accettaPreventivoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $this->rigaCrm('preventivi', $chi['workspace'], $id);

        return $this->conIdempotenza($richiesta, 'crm.preventivi.accettazione.crea', $chi, $corpo, function () use ($chi, $corpo, $id) {
            $campi = $this->testi->validaCorpo($corpo, [
                'nome' => ['bail', 'required', 'string', 'max:200'],
                'nota' => ['bail', 'sometimes', 'nullable', 'string', 'max:500'],
                'accettato_il' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
                'persona_id' => ['bail', 'sometimes', 'nullable', 'string', 'max:64'],
            ]);
            $riga = $this->crm['preventivi'][$id];
            $this->senzaContattoAnonimizzatoPreventivoCrm($riga);
            $stato = self::statoDelPreventivoCrm($riga);

            if ($stato === 'accettato') {
                throw new Problema('gia_accettato');
            }

            if ($stato === 'scaduto') {
                throw new Problema('preventivo_scaduto');
            }

            if (! in_array($stato, ['inviato', 'visto'], true)) {
                throw new Problema('preventivo_stato_non_ammesso');
            }

            $oggi = self::oggiAcquistoCrm();
            $giorno = (string) ($campi['accettato_il'] ?? $oggi);
            $errori = [];

            if ($giorno > $oggi) {
                $errori[] = self::erroreDelPreventivoCrm(['accettato_il'], $this->testi->testo('regole.preventivo_accettazione_data_futura'), Regola::FUORI_INTERVALLO);
            }

            $compratore = $this->compratoreDelPreventivoCrm($riga, $campi['persona_id'] ?? null, $errori);

            if ($errori !== [] || $compratore === null) {
                throw new Problema('dati_non_validi', $errori);
            }

            if (count($this->righeCrm('acquisti', $chi['workspace'])) + count($riga['righe']) > self::CRM_ACQUISTI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_ACQUISTI_TETTO]);
            }

            $primo = array_filter($this->crm['acquisti'], fn (array $acquisto) => $acquisto['persona_id'] === $compratore['id']) === [];
            $trattativa = $riga['trattativa_id'] === null ? null : ($this->crm['trattative'][$riga['trattativa_id']] ?? null);
            $rate = array_map(fn (array $rata) => ['scade_il' => $rata['scade_il'], 'importo' => $rata['importo'], 'etichetta' => $rata['etichetta'] ?? null], $riga['rate']);
            $ora = self::adessoCrm();
            $acquisti = [];

            foreach ($riga['righe'] as $voce) {
                $catalogo = $voce['voce_id'] === null ? null : ($this->crm['catalogo'][$voce['voce_id']] ?? null);
                $nuovo = self::id();
                $this->crm['acquisti'][$nuovo] = [
                    'id' => $nuovo, 'workspace' => $chi['workspace'], 'persona_id' => $compratore['id'], 'voce_id' => $catalogo['id'] ?? null, 'preventivo_id' => $id,
                    'voce_tipo' => $catalogo['tipo'] ?? 'servizio', 'voce_nome' => mb_substr($voce['descrizione'], 0, 160),
                    'voce_durata_minuti' => $catalogo['durata_minuti'] ?? null, 'voce_unita_incluse' => $catalogo['unita_incluse'] ?? null,
                    'voce_etichetta_unita' => $catalogo['etichetta_unita'] ?? null, 'voce_validita_mesi' => $catalogo['validita_mesi'] ?? null, 'voce_rinnovo' => $catalogo['rinnovo'] ?? null,
                    'prezzo_importo' => self::importoDiRigaPreventivoCrm($voce['quantita'], $voce['prezzo_unitario_importo'], $voce['sconto_percento']),
                    'acquistato_il' => $giorno, 'pagato_importo' => 0, 'metodo_pagamento' => 'da_pagare', 'documento' => 'da_fare',
                    'valido_fino_al' => ($catalogo['validita_mesi'] ?? null) === null ? null : self::dopoMesiAcquistoCrm($giorno, $catalogo['validita_mesi']),
                    'rinnovo_il' => ($catalogo['rinnovo'] ?? null) === null ? null : self::dopoMesiAcquistoCrm($giorno, self::CRM_ACQUISTI_MESI_DEL_RINNOVO[$catalogo['rinnovo']]),
                    'crediti_totali' => ($catalogo['tipo'] ?? null) === 'pacchetto' && $catalogo['unita_incluse'] !== null ? $catalogo['unita_incluse'] * $voce['quantita'] : null,
                    'crediti_usati' => 0, 'crediti_prenotati' => 0,
                    'creato_il' => $ora, 'aggiornato_il' => $ora, 'creato_da' => $chi['persona'],
                ];
                $acquisti[] = $nuovo;
                $parti = $rate === [] ? [] : self::ripartisciPreventivoCrm((int) $this->crm['acquisti'][$nuovo]['prezzo_importo'], array_column($rate, 'importo'));

                if ($parti === []) {
                    $this->creaLaRataUnicaCrm($this->crm['acquisti'][$nuovo], $chi['persona']);

                    continue;
                }

                $numero = 0;

                foreach ($parti as $i => $parte) {
                    $this->nuovaRataCrm($this->crm['acquisti'][$nuovo], ++$numero, (string) $rate[$i]['scade_il'], $parte, $rate[$i]['etichetta'], $chi['persona']);
                }
            }

            $chiusa = null;

            if ($trattativa !== null && ! ($trattativa['eliminata'] ?? false)) {
                if ($trattativa['stato'] === 'aperta') {
                    $this->applicaTrattativa($trattativa, ['stato' => 'vinta'], $chi['persona']);
                } else {
                    $chiusa = $trattativa['stato'];
                }

                $this->cambioCrm('trattative', $trattativa['id'], $chi['persona'], 'preventivo.accettato', [['campo' => 'preventivo_id', 'dopo' => $id]]);
            }

            $persona = $this->crm['persone'][$compratore['id']];

            if ($primo && in_array($persona['stato'], ['lead', 'qualificato', 'perso'], true)) {
                $this->applicaPersona($persona['id'], ['stato' => 'cliente'], $chi['persona']);
            }

            $adesso = self::dopoCrm($riga['aggiornato_il']);
            $this->crm['preventivi'][$id]['stato'] = 'accettato';
            $this->crm['preventivi'][$id]['accettato_il'] = $giorno;
            $this->crm['preventivi'][$id]['accettazione_nome'] = $campi['nome'];
            $this->crm['preventivi'][$id]['accettazione_nota'] = ($campi['nota'] ?? '') === '' ? null : $campi['nota'];
            $this->crm['preventivi'][$id]['accettazione_opportunita'] = $chiusa;
            $this->crm['preventivi'][$id]['acquisti'] = $acquisti;
            $this->crm['preventivi'][$id]['aggiornato_il'] = $adesso;

            return [201, ['data' => $this->vocePreventivoCrm($this->crm['preventivi'][$id])], ['Location' => "/v1/crm/preventivi/{$id}"]];
        });
    }

    /**
     * Il contatto che compra (Preventivi::compratore): quello del preventivo, o per una sola azienda quello dato nel corpo; un contatto dato che
     * non è quello del preventivo, uno che manca, uno che non c'è o è archiviato sono errori di `persona_id`; anonimizzato è 409.
     *
     * @param  array<string, mixed>  $riga
     * @param  list<array{detail: string, regola: string, pointer: string}>  $errori
     * @return array<string, mixed>|null
     */
    private function compratoreDelPreventivoCrm(array $riga, mixed $dato, array &$errori): ?array
    {
        $dato = $dato === null ? null : strtolower((string) $dato);

        if ($riga['persona_id'] !== null && $dato !== null && $dato !== $riga['persona_id']) {
            $errori[] = self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('regole.preventivo_accettazione_altro_contatto'), Regola::VALORE_NON_AMMESSO);

            return null;
        }

        $id = $riga['persona_id'] ?? $dato;

        if ($id === null) {
            $errori[] = self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('regole.preventivo_accettazione_contatto'), Regola::OBBLIGATORIO);

            return null;
        }

        $contatto = $this->crm['persone'][$id] ?? null;

        if ($contatto === null || ($contatto['eliminata'] ?? false) || $contatto['workspace'] !== $riga['workspace']) {
            $errori[] = self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('validation.exists', ['attribute' => $this->testi->nome('persona_id')]), Regola::NON_TROVATO);

            return null;
        }

        if ($contatto['anonimizzata_il'] !== null) {
            throw new Problema('persona_anonimizzata');
        }

        if ($contatto['archiviata_il'] !== null) {
            $errori[] = self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('regole.acquisto_contatto_archiviato'), Regola::VALORE_NON_AMMESSO);

            return null;
        }

        return $contatto;
    }

    /**
     * Divide il prezzo di un acquisto fra le rate del preventivo (Preventivi::ripartisci): la rata `i` vale `prezzo × rata[i] ÷ totale` arrotondata
     * al più vicino (a metà in su), l'ultima prende ciò che avanza; una rata che viene zero non nasce.
     *
     * @param  list<int>  $rate
     * @return array<int, int>
     */
    private static function ripartisciPreventivoCrm(int $prezzo, array $rate): array
    {
        $totale = array_sum($rate);
        $ultima = count($rate) - 1;
        $resto = $prezzo;
        $parti = [];

        foreach ($rate as $i => $importo) {
            $parte = $i === $ultima ? $resto : min($resto, self::proporzionePreventivoCrm($prezzo, $importo, $totale));
            $resto -= $parte;

            if ($parte > 0) {
                $parti[$i] = $parte;
            }
        }

        return $parti;
    }

    /** `a × b ÷ c` arrotondato al più vicino (a metà in su), esatto fino a 10^12 per ogni termine (Preventivi::proporzione). */
    private static function proporzionePreventivoCrm(int $a, int $b, int $c): int
    {
        if ($c <= 0) {
            return 0;
        }

        [$alto, $basso] = [intdiv($b, 1_000_000), $b % 1_000_000];
        $x = $alto * $a;
        $y = $basso * $a;
        $resto = (($x % $c) * 1_000_000) % $c + ($y % $c);
        $intero = intdiv($x, $c) * 1_000_000 + intdiv(($x % $c) * 1_000_000, $c) + intdiv($y, $c) + intdiv($resto, $c);
        $resto %= $c;

        return $intero + (2 * $resto >= $c ? 1 : 0);
    }

    /** Un preventivo il cui contatto è anonimizzato non cambia più, come in `modifica`: 409 `persona_anonimizzata`. */
    private function senzaContattoAnonimizzatoPreventivoCrm(array $riga): void
    {
        if ($riga['persona_id'] !== null && ($this->crm['persone'][$riga['persona_id']]['anonimizzata_il'] ?? null) !== null) {
            throw new Problema('persona_anonimizzata');
        }
    }

    /** Svuota l'introduzione, il motivo del rifiuto, il nome e la nota dell'accettazione e l'etichetta delle rate dei preventivi del contatto, e nome e nota di quelli accettati con lui come compratore (Anonimizzazione::preventivi). */
    private function dimenticaIPreventiviCrm(string $persona): void
    {
        foreach ($this->crm['acquisti'] as $acquisto) {
            if ($acquisto['persona_id'] === $persona && ($acquisto['preventivo_id'] ?? null) !== null && isset($this->crm['preventivi'][$acquisto['preventivo_id']])) {
                $this->crm['preventivi'][$acquisto['preventivo_id']]['accettazione_nome'] = null;
                $this->crm['preventivi'][$acquisto['preventivo_id']]['accettazione_nota'] = null;
            }
        }

        foreach ($this->crm['preventivi'] as $id => $preventivo) {
            if ($preventivo['persona_id'] === $persona) {
                $this->crm['preventivi'][$id]['introduzione'] = null;
                $this->crm['preventivi'][$id]['rifiuto_motivo'] = null;
                $this->crm['preventivi'][$id]['accettazione_nome'] = null;
                $this->crm['preventivi'][$id]['accettazione_nota'] = null;
                $this->crm['preventivi'][$id]['rate'] = array_map(fn (array $rata) => ['etichetta' => null] + $rata, $preventivo['rate']);
            }
        }
    }

    /**
     * Lo schema Preventivo (Forme::preventivo): le righe con l'importo di ciascuna, il piano e i totali, tutti nella valuta del preventivo.
     *
     * @return array<string, mixed>
     */
    private function vocePreventivoCrm(array $riga): array
    {
        $valuta = $riga['valuta'];
        $soldi = fn (int $importo) => ['importo' => $importo, 'valuta' => $valuta];
        $conti = [];
        $righe = [];

        foreach ($riga['righe'] as $posizione => $voce) {
            $importo = self::importoDiRigaPreventivoCrm($voce['quantita'], $voce['prezzo_unitario_importo'], $voce['sconto_percento']);
            $conti[] = ['importo' => $importo, 'aliquota' => $voce['aliquota_iva']];
            $righe[] = [
                'id' => $voce['id'],
                'posizione' => $posizione + 1,
                'voce_id' => $voce['voce_id'],
                'descrizione' => $voce['descrizione'],
                'quantita' => $voce['quantita'],
                'prezzo_unitario' => $soldi($voce['prezzo_unitario_importo']),
                'sconto_percento' => $voce['sconto_percento'],
                'aliquota_iva' => $voce['aliquota_iva'],
                'importo' => $soldi($importo),
            ];
        }

        $totali = self::totaliPreventivoCrm($conti);

        return [
            'id' => $riga['id'],
            'numero' => self::numeroPreventivoCrm($riga),
            'persona_id' => $riga['persona_id'],
            'organizzazione_id' => $riga['organizzazione_id'],
            'trattativa_id' => $riga['trattativa_id'],
            'stato' => self::statoDelPreventivoCrm($riga),
            'valido_fino_al' => $riga['valido_fino_al'],
            'valuta' => $valuta,
            'righe' => $righe,
            'piano' => [
                'tipo' => $riga['piano_tipo'],
                'rate' => array_map(fn (array $rata) => [
                    'numero' => $rata['numero'], 'scade_il' => $rata['scade_il'], 'importo' => $soldi($rata['importo']), 'etichetta' => $rata['etichetta'],
                ], $riga['rate']),
            ],
            'introduzione' => $riga['introduzione'],
            'condizioni' => $riga['condizioni'],
            'totali' => [
                'imponibile' => $soldi($totali['imponibile']),
                'iva' => array_map(fn (array $parte) => ['aliquota' => $parte['aliquota'], 'imponibile' => $soldi($parte['imponibile']), 'imposta' => $soldi($parte['imposta'])], $totali['iva']),
                'totale' => $soldi($totali['totale']),
            ],
            'inviato_il' => ($riga['inviato_il'] ?? null) === null ? null : self::iso($riga['inviato_il']),
            'rifiutato_il' => ($riga['rifiutato_il'] ?? null) === null ? null : self::iso($riga['rifiutato_il']),
            'rifiuto' => ($riga['rifiutato_il'] ?? null) === null ? null : ['motivo' => $riga['rifiuto_motivo'] ?? null],
            'accettato_il' => $riga['accettato_il'] ?? null,
            'accettazione' => ($riga['accettato_il'] ?? null) === null ? null : [
                'nome' => $riga['accettazione_nome'] ?? null,
                'modo' => 'manuale',
                'nota' => $riga['accettazione_nota'] ?? null,
                'opportunita_gia_chiusa' => $riga['accettazione_opportunita'] ?? null,
            ],
            'acquisti' => $riga['acquisti'] ?? [],
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
            'creato_da' => $riga['creato_da'],
        ];
    }

    /** Il numero com'è nel contratto: `P-2026-001` (tre cifre almeno, di più oltre il 999). */
    private static function numeroPreventivoCrm(array $riga): string
    {
        return sprintf('P-%04d-%03d', $riga['anno'], $riga['progressivo']);
    }

    /** L'importo di una riga: quantità × prezzo unitario meno lo sconto, arrotondato al più vicino (a metà in su). */
    private static function importoDiRigaPreventivoCrm(int $quantita, int $prezzo, int $sconto): int
    {
        return intdiv($quantita * $prezzo * (100 - $sconto) + 50, 100);
    }

    /**
     * I totali di un insieme di righe (Preventivi::totali): l'IVA per aliquota (le righe senza aliquota non hanno IVA e vengono prime),
     * l'imponibile scorporato dalla somma di ogni aliquota e il totale, che è la somma degli importi di riga.
     *
     * @param  list<array{importo: int, aliquota: int|null}>  $righe
     * @return array{imponibile: int, iva: list<array{aliquota: int|null, imponibile: int, imposta: int}>, totale: int}
     */
    private static function totaliPreventivoCrm(array $righe): array
    {
        $somme = [];

        foreach ($righe as $riga) {
            $chiave = $riga['aliquota'] ?? -1;
            $somme[$chiave] = ($somme[$chiave] ?? 0) + $riga['importo'];
        }

        ksort($somme);

        $iva = [];
        $imponibile = 0;
        $totale = 0;

        foreach ($somme as $chiave => $somma) {
            $aliquota = $chiave === -1 ? null : $chiave;
            $parte = $aliquota === null || $aliquota === 0 ? $somma : intdiv(2 * $somma * 10000 + (10000 + $aliquota), 2 * (10000 + $aliquota));
            $iva[] = ['aliquota' => $aliquota, 'imponibile' => $parte, 'imposta' => $somma - $parte];
            $imponibile += $parte;
            $totale += $somma;
        }

        return ['imponibile' => $imponibile, 'iva' => $iva, 'totale' => $totale];
    }

    /**
     * Le regole del corpo di crm.preventivi.crea (`$nuovo`) e di crm.preventivi.modifica (Preventivi::regole). Gli id sono del workspace: uno
     * di un altro workspace, uno che non c'è e uno eliminato hanno lo stesso 422 `non_trovato`. I controlli che vogliono la voce del
     * catalogo, la valuta del preventivo e il totale si fanno dopo. `stato` non si scrive.
     *
     * @return array<string, mixed>
     */
    private function regoleDelPreventivoCrm(string $workspace, bool $nuovo): array
    {
        $testo = ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::CRM_PREVENTIVI_TESTO, new SenzaCarattereNullo];

        return [
            'stato' => ['prohibited'],
            'persona_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'persone', 'regole.contatto_non_esiste')],
            'organizzazione_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'organizzazioni', 'regole.azienda_non_esiste')],
            'trattativa_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'trattative', 'regole.opportunita_non_esiste')],
            'valido_fino_al' => ['bail', 'sometimes', 'string', 'date_format:Y-m-d'],
            'righe' => ['bail', $nuovo ? 'required' : 'sometimes', 'array', function (string $campo, mixed $righe, Closure $fail) {
                if (! is_array($righe) || ! array_is_list($righe) || $righe === [] || count($righe) > self::CRM_PREVENTIVI_RIGHE) {
                    $vuota = $righe === [] || ! is_array($righe) || ! array_is_list($righe);

                    $this->rifiutaCrm($fail, $campo, $vuota ? Regola::OBBLIGATORIO : Regola::FUORI_INTERVALLO, 'regole.preventivo_righe_troppe', ['massimo' => self::CRM_PREVENTIVI_RIGHE]);
                }
            }],
            'righe.*' => ['array'],
            'righe.*.voce_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'catalogo', 'regole.voce_non_esiste')],
            'righe.*.descrizione' => ['bail', 'required_without:righe.*.voce_id', 'string', 'min:1', 'max:'.self::CRM_PREVENTIVI_DESCRIZIONE, new SenzaCarattereNullo],
            'righe.*.quantita' => ['bail', 'sometimes', 'integer:strict', 'between:1,'.self::CRM_PREVENTIVI_QUANTITA],
            'righe.*.prezzo_unitario' => ['bail', 'required_without:righe.*.voce_id', 'array'],
            'righe.*.prezzo_unitario.importo' => ['bail', 'required_with:righe.*.prezzo_unitario', 'integer:strict', 'between:0,'.self::CRM_ACQUISTI_IMPORTO],
            'righe.*.prezzo_unitario.valuta' => ['bail', 'required_with:righe.*.prezzo_unitario', 'string'],
            'righe.*.sconto_percento' => ['bail', 'sometimes', 'integer:strict', 'between:0,100'],
            'righe.*.aliquota_iva' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:0,10000'],
            'piano' => ['bail', 'sometimes', 'array'],
            'piano.tipo' => ['bail', 'required_with:piano', 'string', Rule::in(self::CRM_PREVENTIVI_PIANI)],
            'piano.rate' => ['bail', 'sometimes', 'array', function (string $campo, mixed $rate, Closure $fail) {
                if (! is_array($rate) || ! array_is_list($rate)) {
                    $this->rifiutaCrm($fail, $campo, Regola::TIPO, 'validation.array', ['attribute' => $campo]);
                } elseif (count($rate) > self::CRM_RATE_MASSIMO) {
                    $this->rifiutaCrm($fail, $campo, Regola::TROPPE_RATE, 'regole.rate_troppe', ['massimo' => self::CRM_RATE_MASSIMO]);
                }
            }],
            'piano.rate.*' => ['array'],
            'piano.rate.*.scade_il' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'piano.rate.*.importo' => ['bail', 'required', 'array'],
            'piano.rate.*.importo.importo' => ['bail', 'required', 'integer:strict', 'between:1,'.self::CRM_ACQUISTI_IMPORTO],
            'piano.rate.*.importo.valuta' => ['bail', 'required', 'string'],
            'piano.rate.*.etichetta' => ['bail', 'sometimes', 'nullable', 'string', 'max:'.self::CRM_RATE_ETICHETTA, new SenzaCarattereNullo],
            'introduzione' => $testo,
            'condizioni' => $testo,
        ];
    }

    /**
     * Il contatto e l'azienda di un preventivo (Preventivi::intestatario): almeno uno dei due. Un contatto anonimizzato è 409; uno archiviato è
     * un errore di campo, salvo che sia già quello della bozza che si modifica.
     *
     * @param  array<string, mixed>|null  $preventivo
     * @return list<array<string, mixed>>
     */
    private function intestatarioDelPreventivoCrm(mixed $persona, mixed $organizzazione, ?array $preventivo = null): array
    {
        if ($persona === null && $organizzazione === null) {
            return [self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('regole.preventivo_senza_intestatario'), Regola::OBBLIGATORIO)];
        }

        if ($persona === null) {
            return [];
        }

        $id = strtolower((string) $persona);
        $contatto = $this->crm['persone'][$id] ?? null;

        if ($contatto === null || ($contatto['eliminata'] ?? false)) {
            return [self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('validation.exists', ['attribute' => $this->testi->nome('persona_id')]), Regola::NON_TROVATO)];
        }

        if ($contatto['anonimizzata_il'] !== null) {
            throw new Problema('persona_anonimizzata');
        }

        if ($contatto['archiviata_il'] !== null && ($preventivo['persona_id'] ?? null) !== $id) {
            return [self::erroreDelPreventivoCrm(['persona_id'], $this->testi->testo('regole.preventivo_contatto_archiviato'), Regola::VALORE_NON_AMMESSO)];
        }

        return [];
    }

    /**
     * Le righe date, normalizzate (Preventivi::righe): i default della voce del catalogo (descrizione, prezzo unitario, aliquota) dove la riga
     * non li dà, la quantità 1 e lo sconto 0. Una voce non `attiva`, un prezzo che manca, una valuta diversa da quella del preventivo e un
     * totale oltre il massimo sono errori di campo, che si aggiungono a `$errori`; con errori sulle righe restituisce null.
     *
     * @param  list<array<string, mixed>>  $righe
     * @param  list<array<string, mixed>>  $errori
     * @return array{righe: list<array<string, mixed>>, conti: list<array{importo: int, aliquota: int|null}>}|null
     */
    private function righeDelPreventivoCrm(array $righe, string $valuta, array &$errori): ?array
    {
        $normalizzate = [];
        $conti = [];
        $sbagliate = false;
        $totale = 0;

        foreach ($righe as $i => $riga) {
            $voce = ($riga['voce_id'] ?? null) === null ? null : ($this->crm['catalogo'][strtolower((string) $riga['voce_id'])] ?? null);

            if (($riga['voce_id'] ?? null) !== null && $voce === null) {
                $errori[] = self::erroreDelPreventivoCrm(['righe', (string) $i, 'voce_id'], $this->testi->testo('regole.voce_non_esiste'), Regola::NON_TROVATO);
                $sbagliate = true;

                continue;
            }

            if ($voce !== null && $voce['stato'] !== 'attiva') {
                $errori[] = self::erroreDelPreventivoCrm(['righe', (string) $i, 'voce_id'], $this->testi->testo('regole.preventivo_voce_non_attiva'), Regola::VALORE_NON_AMMESSO);
                $sbagliate = true;

                continue;
            }

            if (isset($riga['prezzo_unitario']) && $riga['prezzo_unitario']['valuta'] !== $valuta) {
                $errori[] = self::erroreDelPreventivoCrm(['righe', (string) $i, 'prezzo_unitario', 'valuta'], $this->testi->testo('regole.valuta_del_workspace', ['valuta' => $valuta]), Regola::VALORE_NON_AMMESSO);
                $sbagliate = true;

                continue;
            }

            $prezzo = isset($riga['prezzo_unitario']) ? (int) $riga['prezzo_unitario']['importo'] : $voce['prezzo_importo'] ?? null;

            if ($prezzo === null) {
                $errori[] = self::erroreDelPreventivoCrm(['righe', (string) $i, 'prezzo_unitario'], $this->testi->testo('regole.preventivo_voce_senza_prezzo'), Regola::OBBLIGATORIO);
                $sbagliate = true;

                continue;
            }

            $quantita = (int) ($riga['quantita'] ?? 1);
            $sconto = (int) ($riga['sconto_percento'] ?? 0);
            $aliquota = array_key_exists('aliquota_iva', $riga) ? $riga['aliquota_iva'] : ($voce['aliquota_iva'] ?? null);
            $importo = self::importoDiRigaPreventivoCrm($quantita, $prezzo, $sconto);
            $totale += $importo;

            $normalizzate[] = [
                'voce_id' => $voce['id'] ?? null,
                'descrizione' => (string) ($riga['descrizione'] ?? $voce['nome']),
                'quantita' => $quantita,
                'prezzo_unitario_importo' => $prezzo,
                'sconto_percento' => $sconto,
                'aliquota_iva' => $aliquota === null ? null : (int) $aliquota,
            ];
            $conti[] = ['importo' => $importo, 'aliquota' => $aliquota === null ? null : (int) $aliquota];
        }

        if (! $sbagliate && $totale > self::CRM_ACQUISTI_IMPORTO) {
            $errori[] = self::erroreDelPreventivoCrm(['righe'], $this->testi->testo('regole.preventivo_totale_troppo_alto'), Regola::FUORI_INTERVALLO);
            $sbagliate = true;
        }

        return $sbagliate ? null : ['righe' => $normalizzate, 'conti' => $conti];
    }

    /**
     * Il piano dato (o, senza, quello `unica`), ordinato per scadenza e con la somma controllata sul totale (Preventivi::piano). `$totale` è
     * null quando le righe hanno errori: la somma allora non si controlla. Con la somma diversa dal totale `$differenza` è somma − totale.
     *
     * @param  array<string, mixed>|null  $dato
     * @param  list<array<string, mixed>>  $errori
     * @return array{tipo: string, rate: list<array<string, mixed>>}|null
     */
    private function pianoDelPreventivoCrm(?array $dato, ?int $totale, string $valuta, array &$errori, ?int &$differenza = null): ?array
    {
        $differenza = null;
        $tipo = (string) ($dato['tipo'] ?? 'unica');
        $rate = array_values($dato['rate'] ?? []);
        $minimo = $tipo === 'unica' ? 0 : 2;
        $massimo = $tipo === 'unica' ? 0 : ($tipo === 'acconto_saldo' ? 2 : self::CRM_RATE_MASSIMO);

        if (count($rate) < $minimo || count($rate) > $massimo) {
            $errori[] = self::erroreDelPreventivoCrm(['piano', 'rate'], $this->testi->testo('regole.preventivo_piano_rate', ['tipo' => $tipo, 'minimo' => $minimo, 'massimo' => $massimo]), Regola::VALORE_NON_AMMESSO);

            return null;
        }

        foreach ($rate as $i => $rata) {
            if ($rata['importo']['valuta'] !== $valuta) {
                $errori[] = self::erroreDelPreventivoCrm(['piano', 'rate', (string) $i, 'importo', 'valuta'], $this->testi->testo('regole.valuta_del_workspace', ['valuta' => $valuta]), Regola::VALORE_NON_AMMESSO);

                return null;
            }
        }

        if ($totale !== null && $rate !== []) {
            $differenza = array_sum(array_map(fn (array $rata) => (int) $rata['importo']['importo'], $rate)) - $totale;

            if ($differenza !== 0) {
                $errori[] = self::erroreDelPreventivoCrm(['piano', 'rate'], $this->testi->testo('regole.preventivo_somma_diversa'), Regola::SOMMA_DIVERSA);

                return null;
            }

            $differenza = null;
        }

        // Per scadenza, e a parità nell'ordine del corpo.
        $scadenze = array_map(fn (array $rata) => (string) $rata['scade_il'], $rate);
        $posizioni = array_keys($rate);
        array_multisort($scadenze, SORT_ASC, $posizioni, SORT_ASC, $rate);

        return ['tipo' => $tipo, 'rate' => $rate];
    }

    /**
     * Le righe di prima e quelle nuove sono la stessa cosa? Se sì le righe restano con i loro id.
     *
     * @param  list<array<string, mixed>>  $attuali
     * @param  list<array<string, mixed>>  $nuove
     */
    private static function righeDiverseDelPreventivoCrm(array $attuali, array $nuove): bool
    {
        $comuni = fn (array $riga) => [$riga['voce_id'], $riga['descrizione'], $riga['quantita'], $riga['prezzo_unitario_importo'], $riga['sconto_percento'], $riga['aliquota_iva']];

        return array_map($comuni, $attuali) !== array_map($comuni, $nuove);
    }

    /**
     * @param  array{tipo: string, rate: list<array<string, mixed>>}  $attuale
     * @param  array{tipo: string, rate: list<array<string, mixed>>}  $nuovo
     */
    private static function pianoDiversoDelPreventivoCrm(array $attuale, array $nuovo): bool
    {
        $comune = fn (array $piano) => [$piano['tipo'], array_map(fn (array $rata) => [(string) $rata['scade_il'], (int) $rata['importo']['importo'], $rata['etichetta'] ?? null], $piano['rate'])];

        return $comune($attuale) !== $comune($nuovo);
    }

    /**
     * @param  list<array<string, mixed>>  $righe
     * @return list<array<string, mixed>>
     */
    private static function scriviLeRighePreventivoCrm(array $righe): array
    {
        return array_map(fn (array $riga) => ['id' => self::id()] + $riga, $righe);
    }

    /**
     * @param  list<array<string, mixed>>  $rate
     * @return list<array<string, mixed>>
     */
    private static function scriviLeRatePreventivoCrm(array $rate): array
    {
        $scritte = [];

        foreach ($rate as $posizione => $rata) {
            $scritte[] = ['numero' => $posizione + 1, 'etichetta' => $rata['etichetta'] ?? null, 'scade_il' => (string) $rata['scade_il'], 'importo' => (int) $rata['importo']['importo']];
        }

        return $scritte;
    }

    /**
     * Un errore di campo scritto a mano (ErroreDiCampo::pointer): il pointer è il percorso, i numeri come indici.
     *
     * @param  list<string>  $percorso
     * @return array{detail: string, regola: string, pointer: string}
     */
    private static function erroreDelPreventivoCrm(array $percorso, string $testo, string $regola): array
    {
        return ['detail' => $testo, 'regola' => $regola, 'pointer' => '#/'.implode('/', $percorso)];
    }
}
