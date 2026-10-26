<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * I profili di fatturazione e gli indirizzi di spedizione dei clienti del CRM nel backoffice finto (#2751, CRM-01 F5-F7):
 * `crm.profili-fatturazione.elenca`, `ricerca.crea`, `crea`, `mostra`, `modifica` e `crm.indirizzi-spedizione.elenca`, `crea`, `mostra`,
 * `modifica`, con le regole, i pointer, l'ordine e gli errori di `ProfiliFatturazioneController`, `IndirizziSpedizioneController` e
 * `App\Crm\DelCliente` del backoffice. Il cliente è un contatto o un'azienda (uno solo); il primo elemento di un cliente è il predefinito, e
 * uno solo lo è fra i non archiviati; non si elimina, si archivia con `archiviato`; al più 20 non archiviati per cliente. `area_fiscale`,
 * `stato` e `mancano` di un profilo si calcolano sul paese dell'azienda del workspace di adesso. Leggono e scrivono i tre ruoli. Non c'è lo
 * storico (non si legge dall'API) e non ci sono i freni (della creazione del CRM e della ricerca): se ne accorge solo chi li prova contro il
 * backoffice vero. L'`ETag` non è lo stesso valore del backoffice: conta la forma.
 */
trait CrmProfili
{
    private const CRM_PROFILI_TIPI = ['privato', 'professionista', 'azienda', 'ente_pubblico'];

    /** I non archiviati per cliente (ProfiloFatturazione::TETTO, IndirizzoSpedizione::TETTO) e le email delle fatture di un profilo. */
    private const CRM_PROFILI_TETTO = 20;

    private const CRM_PROFILI_EMAIL = 5;

    /** Ciò che può mancare per fatturare, nell'ordine del contratto (ProfiliFatturazione::MANCANO). */
    private const CRM_PROFILI_MANCANO = ['intestatario', 'indirizzo.via', 'indirizzo.cap', 'indirizzo.citta', 'indirizzo.provincia', 'codice_fiscale', 'partita_iva', 'codice_destinatario', 'codice_univoco_ufficio', 'identificativo_estero'];

    /** I campi dell'indirizzo di un profilo e la colonna di ognuno (ProfiliFatturazione::INDIRIZZO). */
    private const CRM_PROFILI_INDIRIZZO = ['via' => 'via', 'civico' => 'civico', 'cap' => 'cap', 'citta' => 'citta', 'provincia' => 'provincia', 'paese' => 'indirizzo_paese'];

    /** I campi di testo di un indirizzo di spedizione e la lunghezza massima (IndirizziSpedizione::TESTI). */
    private const CRM_INDIRIZZI_TESTI = [
        'nome_indirizzo' => 100, 'destinatario' => 200, 'presso' => 200, 'via' => 200, 'civico' => 20, 'riga2' => 200, 'cap' => 20, 'citta' => 100,
        'provincia' => 100, 'note_consegna' => 500,
    ];

    /** I campi che un indirizzo ha sempre e che una modifica non toglie (IndirizziSpedizione::OBBLIGATORI). */
    private const CRM_INDIRIZZI_OBBLIGATORI = ['via', 'cap', 'citta', 'paese'];

    /** I dati personali che nessuna query di un elenco ammette (ClienteElencaRequest::VIETATI). */
    private const CRM_PROFILI_VIETATI = ['q', 'codice_fiscale', 'partita_iva', 'nome', 'cognome', 'email', 'telefono', 'testo', 'intestatario', 'pec'];

    // ———————————————————————————————————————— Elenco e ricerca

    /**
     * crm.profili-fatturazione.elenca e crm.indirizzi-spedizione.elenca (ElencoDelCliente): gli elementi di **un** cliente, dal più vecchio.
     * Senza `archiviati` o con `archiviati=false` escono i non archiviati. Un dato personale nella query è 422 `campo_vietato`; un cliente
     * che non c'è nel workspace, anche d'un altro, è 404 dopo la query e il cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaDelClienteCrm(Request $richiesta, string $tipo, string $operazione): array
    {
        $chi = $this->conCrm($richiesta);
        $id = fn (string $altro) => ['bail', 'required_without:'.$altro, 'prohibits:'.$altro, 'string', 'max:64'];
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'persona_id' => $id('organizzazione_id'),
            'organizzazione_id' => $id('persona_id'),
            'archiviati' => ['sometimes', 'string', Rule::in(['true', 'false'])],
            ...array_fill_keys(self::CRM_PROFILI_VIETATI, ['prohibited']),
        ]);
        $persona = isset($query['persona_id']) ? strtolower($query['persona_id']) : null;
        $organizzazione = isset($query['organizzazione_id']) ? strtolower($query['organizzazione_id']) : null;
        $archiviati = ($query['archiviati'] ?? null) === 'true';
        $esiste = $persona !== null ? $this->esisteCrm('persone', $chi['workspace'], $persona) : $this->esisteCrm('organizzazioni', $chi['workspace'], $organizzazione);
        $pagina = $this->paginaCrm(
            $richiesta,
            $this->listaDelClienteCrm($operazione, [$persona, $organizzazione, $archiviati]),
            $esiste ? $this->righeDelClienteCrm($tipo, $chi['workspace'], $persona, $organizzazione, $archiviati) : [],
            self::ordineDelClienteCrm(...),
            $this->formaDelClienteCrm($tipo, $chi['workspace']),
        );

        return $esiste ? $pagina : throw new Problema('non_trovato');
    }

    /**
     * crm.profili-fatturazione.ricerca.crea (ProfiliFatturazioneController::ricercaCrea): i profili per codice fiscale e per partita IVA,
     * con il termine nel corpo di un POST e non nell'URL. Risponde 200, perché nessuna risorsa nasce, con la forma di `elenca`. Almeno uno
     * fra i due, nessun campo estraneo; il valore si normalizza come alla creazione e si confronta per uguaglianza esatta. Un cursore di
     * un altro metodo o di altri filtri è 422 sul pointer `#/cursore`.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function ricercaProfiliCrm(Request $richiesta, array $corpo, string $operazione): array
    {
        $chi = $this->conCrm($richiesta);
        $campi = $this->testi->validaCorpo($corpo, [
            'codice_fiscale' => ['nullable', 'required_without:partita_iva', 'string', 'max:30'],
            'partita_iva' => ['nullable', 'required_without:codice_fiscale', 'string', 'max:30'],
            'archiviati' => ['nullable', 'boolean:strict'],
            'limite' => ['nullable', 'integer:strict', 'between:1,'.self::LIMITE_MASSIMO],
            'cursore' => ['nullable', 'string', 'max:2048'],
        ]);
        $codice = isset($campi['codice_fiscale']) ? Fiscale::codiceFiscaleDaCercare($campi['codice_fiscale']) : null;
        $partita = isset($campi['partita_iva']) ? Fiscale::partitaIvaDaCercare($campi['partita_iva']) : null;
        $archiviati = $campi['archiviati'] ?? false;
        $lista = $this->listaDelClienteCrm($operazione, [$codice, $partita, $archiviati]);
        $cursore = $campi['cursore'] ?? null;

        if ($cursore !== null && $this->leggiCursore($lista, 'id', $cursore) === null) {
            throw $this->erroreCrm('cursore', 'regole.cursore', Regola::FORMATO, ['attribute' => 'cursore']);
        }

        $righe = array_values(array_filter(
            $this->righeCrm('profili', $chi['workspace']),
            fn (array $riga) => ($archiviati ? $riga['archiviata_il'] !== null : $riga['archiviata_il'] === null)
                && ($codice === null || $riga['codice_fiscale'] === $codice) && ($partita === null || $riga['partita_iva'] === $partita),
        ));
        usort($righe, self::ordineDelClienteCrm(...));
        $limite = (int) ($campi['limite'] ?? self::LIMITE_PREDEFINITO);

        if ($cursore !== null) {
            $posto = array_search((string) $this->leggiCursore($lista, 'id', $cursore), array_column($righe, 'id'), true);
            $righe = $posto === false ? [] : array_slice($righe, $posto + 1);
        }

        $pagina = array_slice($righe, 0, $limite);
        $forma = $this->formaDelClienteCrm('profili', $chi['workspace']);

        return [200, [
            'data' => array_map($forma, $pagina),
            'successivo' => count($righe) > $limite ? $this->firmaCursore($lista, ['id' => $pagina[$limite - 1]['id']]) : null,
        ]];
    }

    /** Il cursore vale per il metodo e per i filtri che l'hanno dato (FiltriDelCliente::impronta). */
    private function listaDelClienteCrm(string $operazione, array $filtri): string
    {
        return $operazione.':'.substr(hash('sha256', json_encode($filtri, JSON_THROW_ON_ERROR)), 0, 16);
    }

    /**
     * Gli elementi del cliente, o del workspace, secondo i filtri (ElencoDelCliente::pagina).
     *
     * @return list<array<string, mixed>>
     */
    private function righeDelClienteCrm(string $tipo, string $workspace, ?string $persona, ?string $organizzazione, bool $archiviati): array
    {
        return array_values(array_filter(
            $this->righeCrm($tipo, $workspace),
            fn (array $riga) => ($persona === null || $riga['persona_id'] === $persona) && ($organizzazione === null || $riga['organizzazione_id'] === $organizzazione)
                && ($archiviati ? $riga['archiviata_il'] !== null : $riga['archiviata_il'] === null),
        ));
    }

    /** Dal più vecchio (`creato_il`, a parità `id`). */
    private static function ordineDelClienteCrm(array $una, array $altra): int
    {
        return [self::iso($una['creato_il']), $una['id']] <=> [self::iso($altra['creato_il']), $altra['id']];
    }

    /** La forma di un elemento in una pagina. */
    private function formaDelClienteCrm(string $tipo, string $workspace): Closure
    {
        if ($tipo === 'indirizzi') {
            return fn (array $riga) => $this->voceIndirizzoSpedizioneCrm($riga);
        }

        $paese = $this->paeseDelWorkspaceCrm($workspace);

        return fn (array $riga) => $this->voceProfiloFatturazioneCrm($riga, $paese);
    }

    // ———————————————————————————————————————— Profili di fatturazione

    /**
     * crm.profili-fatturazione.crea (ProfiliFatturazioneController::crea): 201 con il profilo, `Location` ed `ETag`. Prima il corpo, poi i
     * dati fiscali per il paese, poi il cliente (un contatto anonimizzato è 409 `persona_anonimizzata`), il tetto di 20 non archiviati
     * (409 `limite_raggiunto`) e il predefinito. Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaProfiloFatturazioneCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.profili-fatturazione.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                ...$this->regoleDelClienteCrm($chi['workspace']),
                ...$this->regoleDelProfiloCrm(true),
                ...array_fill_keys(['id', 'area_fiscale', 'stato', 'mancano', 'vies', 'archiviato_il', 'creato_il', 'aggiornato_il', 'creato_da', 'archiviato'], ['prohibited']),
            ]);
            $fiscali = $this->fiscaliDelProfiloCrm($campi['paese'], $campi['codice_fiscale'] ?? null, $campi['partita_iva'] ?? null);
            $cliente = $this->clienteDelCorpoCrm($campi);
            $vivi = $this->viviDelClienteCrm('profili', $chi['workspace'], $cliente['colonna'], $cliente['id']);

            $this->tettoDelClienteCrm($vivi);

            $predefinito = ($campi['predefinito'] ?? false) === true || $vivi === [];
            $this->liberaIlPredefinitoCrm('profili', $predefinito ? $vivi : [], null);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['profili'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'persona_id' => null, 'organizzazione_id' => null, $cliente['colonna'] => $cliente['id'],
                'nome_profilo' => $campi['nome_profilo'] ?? null,
                'tipo_intestatario' => $campi['tipo_intestatario'],
                'intestatario' => $campi['intestatario'] ?? null,
                'paese' => $campi['paese'],
                'partita_iva' => $fiscali['partita_iva'],
                'codice_fiscale' => $fiscali['codice_fiscale'],
                'estero_tipo' => $campi['identificativo_estero']['tipo'] ?? null,
                'estero_valore' => $campi['identificativo_estero']['valore'] ?? null,
                'codice_destinatario' => isset($campi['codice_destinatario']) ? strtoupper($campi['codice_destinatario']) : null,
                'pec' => isset($campi['pec']) ? strtolower($campi['pec']) : null,
                'codice_univoco_ufficio' => isset($campi['codice_univoco_ufficio']) ? strtoupper($campi['codice_univoco_ufficio']) : null,
                'scissione_pagamenti' => $campi['scissione_pagamenti'] ?? false,
                'sostituto_imposta' => $campi['sostituto_imposta'] ?? false,
                'regime_forfettario' => $campi['regime_forfettario'] ?? false,
                ...$this->colonneDellIndirizzoCrm(is_array($campi['indirizzo'] ?? null) ? $campi['indirizzo'] : [], true),
                'email_fatture' => array_map('strtolower', array_values($campi['email_fatture'] ?? [])),
                'lingua' => $campi['lingua'] ?? null,
                'predefinito' => $predefinito, 'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora, 'creato_da' => $chi['persona'],
            ];
            $voce = $this->voceProfiloFatturazioneCrm($this->crm['profili'][$id], $this->paeseDelWorkspaceCrm($chi['workspace']));

            return [201, ['data' => $voce], ['Location' => "/v1/crm/profili-fatturazione/{$id}", 'ETag' => self::etagCrm($voce)]];
        });
    }

    /**
     * crm.profili-fatturazione.mostra: un profilo del workspace, anche archiviato, con l'ETag; un `If-None-Match` che lo contiene dà 304.
     *
     * @return array{int, array<string, mixed>|null, array<string, string>}
     */
    private function mostraProfiloFatturazioneCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->mostraDelClienteCrm($richiesta, $this->voceProfiloFatturazioneCrm($this->rigaCrm('profili', $chi['workspace'], $id), $this->paeseDelWorkspaceCrm($chi['workspace'])));
    }

    /**
     * crm.profili-fatturazione.modifica (ProfiliFatturazioneController::modifica): JSON Merge Patch con `If-Match` letto prima del corpo
     * (412 sulla versione vecchia). I valori si validano come sono **dopo** la modifica: cambiare il `paese` rifà i controlli dei dati
     * fiscali già salvati. Il cliente non si cambia. Archiviare il predefinito promuove il più vecchio rimasto; `predefinito: true` sposta
     * lo stato; ripristinare oltre il tetto è 409. Una richiesta che non cambia niente non scrive.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaProfiloFatturazioneCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('profili', $chi['workspace'], $id);
        $paese = $this->paeseDelWorkspaceCrm($chi['workspace']);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceProfiloFatturazioneCrm($riga, $paese));
        $campi = $this->modificaCrm($corpo, [...$this->regoleDelProfiloCrm(false), 'archiviato' => ['filled', 'boolean:strict']]);

        $this->contattoCollegabileCrm($riga['persona_id']);
        $dopo = $this->dopoDelProfiloCrm($riga, $campi);
        $this->scriviDelClienteCrm('profili', $chi['workspace'], $riga, $campi, $dopo);

        $voce = $this->voceProfiloFatturazioneCrm($this->crm['profili'][$riga['id']], $paese);

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * Le regole dei campi di un profilo (ProfiliFatturazione::regoleDeiCampi): `paese` e `tipo_intestatario` obbligatori alla creazione e
     * `filled` in una modifica, gli altri facoltativi con un `null` che vale come un campo assente. Il controllo dei dati fiscali per paese
     * si fa dopo, perché dipende da più campi.
     *
     * @return array<string, mixed>
     */
    private function regoleDelProfiloCrm(bool $nuovo): array
    {
        $testo = fn (int $massimo) => ['nullable', 'string', 'max:'.$massimo, new SenzaCarattereNullo];
        $logico = $nuovo ? ['nullable', 'boolean:strict'] : ['filled', 'boolean:strict'];

        return [
            'nome_profilo' => $testo(100),
            'tipo_intestatario' => ['bail', $nuovo ? 'required' : 'filled', 'string', Rule::in(self::CRM_PROFILI_TIPI)],
            'intestatario' => $testo(200),
            'paese' => ['bail', $nuovo ? 'required' : 'filled', 'string', 'regex:/^[A-Z]{2}$/', Rule::in(self::PAESI)],
            'partita_iva' => $testo(30),
            'codice_fiscale' => $testo(30),
            'identificativo_estero' => ['nullable', 'array', 'required_array_keys:tipo,valore'],
            'identificativo_estero.tipo' => ['bail', 'required', 'string', 'min:1', 'max:20', new SenzaCarattereNullo],
            'identificativo_estero.valore' => ['bail', 'required', 'string', 'min:1', 'max:40', new SenzaCarattereNullo],
            'codice_destinatario' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{7}$/'],
            'pec' => ['nullable', 'string', 'email', 'max:254'],
            'codice_univoco_ufficio' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{6}$/'],
            'scissione_pagamenti' => $logico,
            'sostituto_imposta' => $logico,
            'regime_forfettario' => $logico,
            'indirizzo' => ['nullable', 'array'],
            'indirizzo.via' => $testo(200),
            'indirizzo.civico' => $testo(20),
            'indirizzo.cap' => $testo(20),
            'indirizzo.citta' => $testo(100),
            'indirizzo.provincia' => $testo(100),
            'indirizzo.paese' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'email_fatture' => ['nullable', 'array', 'list', 'max:'.self::CRM_PROFILI_EMAIL],
            'email_fatture.*' => ['bail', 'string', 'email', 'max:254', 'distinct:ignore_case'],
            'lingua' => ['nullable', 'string', Rule::in(Testi::LINGUE)],
            'predefinito' => $logico,
        ];
    }

    /**
     * Il codice fiscale e la partita IVA normalizzati per il paese, o 422 `dati_non_validi` sui loro pointer (tutti e due insieme se sono
     * tutti e due sbagliati). Un valore assente resta `null`.
     *
     * @return array{codice_fiscale: ?string, partita_iva: ?string}
     */
    private function fiscaliDelProfiloCrm(string $paese, ?string $codiceFiscale, ?string $partitaIva): array
    {
        $errori = [];
        $codice = $codiceFiscale === null ? null : Fiscale::codiceFiscale($paese, $codiceFiscale);
        $partita = $partitaIva === null ? null : Fiscale::partitaIva($paese, $partitaIva);

        if ($codiceFiscale !== null && $codice === null) {
            $errori[] = ['detail' => $this->testi->testo('regole.codice_fiscale_non_valido'), 'regola' => Regola::FORMATO, 'pointer' => '#/codice_fiscale'];
        }

        if ($partitaIva !== null && $partita === null) {
            $errori[] = ['detail' => $this->testi->testo('regole.partita_iva_non_valida'), 'regola' => Regola::FORMATO, 'pointer' => '#/partita_iva'];
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }

        return ['codice_fiscale' => $codice, 'partita_iva' => $partita];
    }

    /**
     * Le colonne del profilo come saranno dopo la modifica (ProfiliFatturazione::dopo): i campi mandati. L'indirizzo si cambia per campo,
     * `null` lo toglie intero; `identificativo_estero` sono due colonne. I dati fiscali si rifanno se cambia uno di loro o il paese.
     *
     * @param  array<string, mixed>  $riga
     * @param  array<string, mixed>  $campi
     * @return array<string, mixed>
     */
    private function dopoDelProfiloCrm(array $riga, array $campi): array
    {
        $dopo = [];

        foreach (['nome_profilo', 'tipo_intestatario', 'intestatario', 'paese', 'scissione_pagamenti', 'sostituto_imposta', 'regime_forfettario', 'lingua'] as $campo) {
            if (array_key_exists($campo, $campi)) {
                $dopo[$campo] = $campi[$campo];
            }
        }

        foreach (['codice_destinatario' => 'strtoupper', 'codice_univoco_ufficio' => 'strtoupper', 'pec' => 'strtolower'] as $campo => $funzione) {
            if (array_key_exists($campo, $campi)) {
                $dopo[$campo] = $campi[$campo] === null ? null : $funzione($campi[$campo]);
            }
        }

        if (array_key_exists('identificativo_estero', $campi)) {
            $dopo['estero_tipo'] = $campi['identificativo_estero']['tipo'] ?? null;
            $dopo['estero_valore'] = $campi['identificativo_estero']['valore'] ?? null;
        }

        if (array_key_exists('indirizzo', $campi)) {
            $dopo += $campi['indirizzo'] === null ? array_fill_keys(array_values(self::CRM_PROFILI_INDIRIZZO), null) : $this->colonneDellIndirizzoCrm($campi['indirizzo'], false);
        }

        if (array_key_exists('email_fatture', $campi)) {
            $dopo['email_fatture'] = array_map('strtolower', array_values($campi['email_fatture'] ?? []));
        }

        $paese = $dopo['paese'] ?? $riga['paese'];
        $daControllare = [
            'codice_fiscale' => array_key_exists('codice_fiscale', $campi) ? $campi['codice_fiscale'] : (array_key_exists('paese', $campi) ? $riga['codice_fiscale'] : null),
            'partita_iva' => array_key_exists('partita_iva', $campi) ? $campi['partita_iva'] : (array_key_exists('paese', $campi) ? $riga['partita_iva'] : null),
        ];

        if ($daControllare['codice_fiscale'] !== null || $daControllare['partita_iva'] !== null || array_key_exists('codice_fiscale', $campi) || array_key_exists('partita_iva', $campi)) {
            $fiscali = $this->fiscaliDelProfiloCrm($paese, $daControllare['codice_fiscale'], $daControllare['partita_iva']);

            foreach (['codice_fiscale', 'partita_iva'] as $campo) {
                if (array_key_exists($campo, $campi) || array_key_exists('paese', $campi)) {
                    $dopo[$campo] = $fiscali[$campo];
                }
            }
        }

        return $dopo;
    }

    /**
     * Le colonne dell'indirizzo da un oggetto del corpo. Alla creazione ogni colonna c'è (`null` se manca); in una modifica solo quelle mandate.
     *
     * @param  array<string, mixed>  $indirizzo
     * @return array<string, mixed>
     */
    private function colonneDellIndirizzoCrm(array $indirizzo, bool $tutte): array
    {
        $colonne = [];

        foreach (self::CRM_PROFILI_INDIRIZZO as $campo => $colonna) {
            if ($tutte || array_key_exists($campo, $indirizzo)) {
                $colonne[$colonna] = $indirizzo[$campo] ?? null;
            }
        }

        return $colonne;
    }

    /** Il paese dell'azienda del workspace, o `IT` (ProfiliFatturazione::paeseDelWorkspace). */
    private function paeseDelWorkspaceCrm(string $workspace): string
    {
        $paese = $this->datiAziende[$this->workspace[$workspace]['azienda_id'] ?? '']['paese'] ?? null;

        return is_string($paese) && $paese !== '' ? $paese : 'IT';
    }

    /** `nazionale` se il paese del profilo è quello del workspace, `ue` se è un altro paese dell'UE, `extra_ue` altrimenti. */
    private static function areaFiscaleCrm(string $paese, string $paeseDelWorkspace): string
    {
        return match (true) {
            $paese === $paeseDelWorkspace => 'nazionale',
            Fiscale::ue($paese) => 'ue',
            default => 'extra_ue',
        };
    }

    /**
     * Ciò che manca per fatturare, per nome di campo e nell'ordine di `CRM_PROFILI_MANCANO` (ProfiliFatturazione::mancano): sempre
     * l'intestatario e l'indirizzo (via, cap, città; con `IT` anche la provincia); poi, in area nazionale, il codice fiscale per un privato,
     * un professionista o un ente pubblico, la partita IVA per un professionista o un'azienda, il codice destinatario (se non c'è né lui né
     * la PEC) per i due, il codice univoco ufficio per un ente pubblico; in area UE la partita IVA per chi non è privato; in area extra UE
     * l'identificativo estero per chi non è privato.
     *
     * @param  array<string, mixed>  $riga
     * @return list<string>
     */
    private static function mancanoDelProfiloCrm(array $riga, string $area): array
    {
        $manca = ['intestatario' => $riga['intestatario'] === null, 'indirizzo.via' => $riga['via'] === null, 'indirizzo.cap' => $riga['cap'] === null, 'indirizzo.citta' => $riga['citta'] === null];
        $manca['indirizzo.provincia'] = $riga['paese'] === 'IT' && $riga['provincia'] === null;
        $tipo = $riga['tipo_intestatario'];
        $privato = $tipo === 'privato';
        $impresa = in_array($tipo, ['professionista', 'azienda'], true);

        if ($area === 'nazionale') {
            $manca['codice_fiscale'] = $riga['codice_fiscale'] === null && in_array($tipo, ['privato', 'professionista', 'ente_pubblico'], true);
            $manca['partita_iva'] = $riga['partita_iva'] === null && $impresa;
            $manca['codice_destinatario'] = $impresa && $riga['codice_destinatario'] === null && $riga['pec'] === null;
            $manca['codice_univoco_ufficio'] = $tipo === 'ente_pubblico' && $riga['codice_univoco_ufficio'] === null;
        } elseif ($area === 'ue') {
            $manca['partita_iva'] = $riga['partita_iva'] === null && ! $privato;
        } else {
            $manca['identificativo_estero'] = $riga['estero_tipo'] === null && ! $privato;
        }

        return array_values(array_filter(self::CRM_PROFILI_MANCANO, fn (string $campo) => $manca[$campo] ?? false));
    }

    /**
     * Lo schema ProfiloFatturazione (Forme::profiloFatturazione): il cliente è `persona_id` o `organizzazione_id`, l'altro `null`;
     * `area_fiscale`, `stato` e `mancano` sul paese dell'azienda del workspace; `vies` dice che il numero non è stato verificato.
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function voceProfiloFatturazioneCrm(array $riga, string $paeseDelWorkspace): array
    {
        $area = self::areaFiscaleCrm($riga['paese'], $paeseDelWorkspace);
        $mancano = self::mancanoDelProfiloCrm($riga, $area);

        return [
            'id' => $riga['id'],
            'persona_id' => $riga['persona_id'],
            'organizzazione_id' => $riga['organizzazione_id'],
            'nome_profilo' => $riga['nome_profilo'],
            'tipo_intestatario' => $riga['tipo_intestatario'],
            'intestatario' => $riga['intestatario'],
            'paese' => $riga['paese'],
            'partita_iva' => $riga['partita_iva'],
            'codice_fiscale' => $riga['codice_fiscale'],
            'identificativo_estero' => $riga['estero_tipo'] === null || $riga['estero_valore'] === null ? null : ['tipo' => $riga['estero_tipo'], 'valore' => $riga['estero_valore']],
            'codice_destinatario' => $riga['codice_destinatario'],
            'pec' => $riga['pec'],
            'codice_univoco_ufficio' => $riga['codice_univoco_ufficio'],
            'scissione_pagamenti' => $riga['scissione_pagamenti'],
            'sostituto_imposta' => $riga['sostituto_imposta'],
            'regime_forfettario' => $riga['regime_forfettario'],
            'indirizzo' => [
                'via' => $riga['via'], 'civico' => $riga['civico'], 'cap' => $riga['cap'], 'citta' => $riga['citta'], 'provincia' => $riga['provincia'],
                'paese' => $riga['indirizzo_paese'],
            ],
            'email_fatture' => $riga['email_fatture'],
            'lingua' => $riga['lingua'],
            'predefinito' => $riga['predefinito'],
            'area_fiscale' => $area,
            'stato' => $mancano === [] ? 'completo' : 'incompleto',
            'mancano' => $mancano,
            'vies' => ['esito' => 'non_verificata', 'verificato_il' => null],
            'archiviato_il' => self::isoCrm($riga['archiviata_il']),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
            'creato_da' => $riga['creato_da'],
        ];
    }

    // ———————————————————————————————————————— Indirizzi di spedizione

    /**
     * crm.indirizzi-spedizione.crea (IndirizziSpedizioneController::crea): 201 con l'indirizzo, `Location` ed `ETag`. Obbligatori il
     * cliente, `via`, `cap`, `citta` e `paese`. Il cliente, il tetto e il predefinito sono quelli dei profili. Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaIndirizzoSpedizioneCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.indirizzi-spedizione.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                ...$this->regoleDelClienteCrm($chi['workspace']),
                ...$this->regoleDelIndirizzoCrm(true),
                ...array_fill_keys(['id', 'archiviato_il', 'creato_il', 'aggiornato_il', 'creato_da', 'archiviato'], ['prohibited']),
            ]);
            $cliente = $this->clienteDelCorpoCrm($campi);
            $vivi = $this->viviDelClienteCrm('indirizzi', $chi['workspace'], $cliente['colonna'], $cliente['id']);

            $this->tettoDelClienteCrm($vivi);

            $predefinito = ($campi['predefinito'] ?? false) === true || $vivi === [];
            $this->liberaIlPredefinitoCrm('indirizzi', $predefinito ? $vivi : [], null);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['indirizzi'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'persona_id' => null, 'organizzazione_id' => null, $cliente['colonna'] => $cliente['id'],
                ...$this->colonneDelIndirizzoCrm($campi, true),
                'predefinito' => $predefinito, 'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora, 'creato_da' => $chi['persona'],
            ];
            $voce = $this->voceIndirizzoSpedizioneCrm($this->crm['indirizzi'][$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/crm/indirizzi-spedizione/{$id}", 'ETag' => self::etagCrm($voce)]];
        });
    }

    /**
     * crm.indirizzi-spedizione.mostra: come quella dei profili.
     *
     * @return array{int, array<string, mixed>|null, array<string, string>}
     */
    private function mostraIndirizzoSpedizioneCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->mostraDelClienteCrm($richiesta, $this->voceIndirizzoSpedizioneCrm($this->rigaCrm('indirizzi', $chi['workspace'], $id)));
    }

    /**
     * crm.indirizzi-spedizione.modifica (IndirizziSpedizioneController::modifica): come quella dei profili. `via`, `cap`, `citta` e `paese`
     * non si tolgono (`null` è 422), gli altri sì.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaIndirizzoSpedizioneCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('indirizzi', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceIndirizzoSpedizioneCrm($riga));
        $campi = $this->modificaCrm($corpo, [...$this->regoleDelIndirizzoCrm(false), 'archiviato' => ['filled', 'boolean:strict']]);

        $this->contattoCollegabileCrm($riga['persona_id']);
        $this->scriviDelClienteCrm('indirizzi', $chi['workspace'], $riga, $campi, $this->colonneDelIndirizzoCrm($campi, false));

        $voce = $this->voceIndirizzoSpedizioneCrm($this->crm['indirizzi'][$riga['id']]);

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * Le regole dei campi di un indirizzo (IndirizziSpedizione::regoleDeiCampi).
     *
     * @return array<string, mixed>
     */
    private function regoleDelIndirizzoCrm(bool $nuovo): array
    {
        $regole = [];

        foreach (self::CRM_INDIRIZZI_TESTI as $campo => $massimo) {
            $regole[$campo] = in_array($campo, self::CRM_INDIRIZZI_OBBLIGATORI, true)
                ? ['bail', $nuovo ? 'required' : 'filled', 'string', 'min:1', 'max:'.$massimo, new SenzaCarattereNullo]
                : ['nullable', 'string', 'max:'.$massimo, new SenzaCarattereNullo];
        }

        return [
            ...$regole,
            'paese' => ['bail', $nuovo ? 'required' : 'filled', 'string', 'regex:/^[A-Z]{2}$/'],
            'telefono_corriere' => ['nullable', 'string', 'max:30', 'regex:/^[0-9 +().-]*$/'],
            'predefinito' => $nuovo ? ['nullable', 'boolean:strict'] : ['filled', 'boolean:strict'],
        ];
    }

    /**
     * Le colonne di un indirizzo dai campi del corpo: con `$tutte` (la creazione) ogni campo c'è, `null` se manca; senza (una modifica)
     * solo quelli mandati.
     *
     * @param  array<string, mixed>  $campi
     * @return array<string, mixed>
     */
    private function colonneDelIndirizzoCrm(array $campi, bool $tutte): array
    {
        $colonne = [];

        foreach ([...array_keys(self::CRM_INDIRIZZI_TESTI), 'paese', 'telefono_corriere'] as $campo) {
            if ($tutte || array_key_exists($campo, $campi)) {
                $colonne[$campo] = $campi[$campo] ?? null;
            }
        }

        return $colonne;
    }

    /**
     * Lo schema IndirizzoSpedizione (Forme::indirizzoSpedizione).
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function voceIndirizzoSpedizioneCrm(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'persona_id' => $riga['persona_id'],
            'organizzazione_id' => $riga['organizzazione_id'],
            'nome_indirizzo' => $riga['nome_indirizzo'],
            'destinatario' => $riga['destinatario'],
            'presso' => $riga['presso'],
            'via' => $riga['via'],
            'civico' => $riga['civico'],
            'riga2' => $riga['riga2'],
            'cap' => $riga['cap'],
            'citta' => $riga['citta'],
            'provincia' => $riga['provincia'],
            'paese' => $riga['paese'],
            'telefono_corriere' => $riga['telefono_corriere'],
            'note_consegna' => $riga['note_consegna'],
            'predefinito' => $riga['predefinito'],
            'archiviato_il' => self::isoCrm($riga['archiviata_il']),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
            'creato_da' => $riga['creato_da'],
        ];
    }

    // ———————————————————————————————————————— Il cliente, il predefinito e l'archivio (App\Crm\DelCliente)

    /**
     * Le regole del cliente nel corpo di una creazione: uno solo fra `persona_id` e `organizzazione_id`, e uno del workspace. Un id che non
     * c'è, di un altro workspace o eliminato ha lo stesso 422 `non_trovato` sul suo pointer (G80).
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDelClienteCrm(string $workspace): array
    {
        return [
            'persona_id' => ['bail', 'required_without:organizzazione_id', 'prohibits:organizzazione_id', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'persone', 'regole.contatto_non_esiste')],
            'organizzazione_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'organizzazioni', 'regole.azienda_non_esiste')],
        ];
    }

    /**
     * Il cliente del corpo: la colonna e l'id. Un contatto anonimizzato è 409 `persona_anonimizzata`.
     *
     * @param  array<string, mixed>  $campi
     * @return array{colonna: string, id: string}
     */
    private function clienteDelCorpoCrm(array $campi): array
    {
        if (isset($campi['persona_id'])) {
            $persona = strtolower($campi['persona_id']);
            $this->contattoCollegabileCrm($persona);

            return ['colonna' => 'persona_id', 'id' => $persona];
        }

        return ['colonna' => 'organizzazione_id', 'id' => strtolower((string) $campi['organizzazione_id'])];
    }

    /**
     * Gli elementi non archiviati di un cliente, in ordine di nascita.
     *
     * @return list<array<string, mixed>>
     */
    private function viviDelClienteCrm(string $tipo, string $workspace, string $colonna, string $cliente): array
    {
        $vivi = array_values(array_filter($this->righeCrm($tipo, $workspace), fn (array $riga) => $riga[$colonna] === $cliente && $riga['archiviata_il'] === null));
        usort($vivi, self::ordineDelClienteCrm(...));

        return $vivi;
    }

    /**
     * Un elemento non archiviato in più nel cliente: oltre il tetto è 409 `limite_raggiunto`, con `limite`.
     *
     * @param  list<array<string, mixed>>  $vivi
     */
    private function tettoDelClienteCrm(array $vivi): void
    {
        if (count($vivi) >= self::CRM_PROFILI_TETTO) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_PROFILI_TETTO]);
        }
    }

    /**
     * Toglie il predefinito agli elementi non archiviati del cliente, tranne `$tranne`: ognuno si salva.
     *
     * @param  list<array<string, mixed>>  $vivi
     */
    private function liberaIlPredefinitoCrm(string $tipo, array $vivi, ?string $tranne): void
    {
        foreach ($vivi as $vivo) {
            if ($vivo['id'] !== $tranne && $vivo['predefinito'] === true) {
                $this->salvaCrm($tipo, $vivo['id'], ['predefinito' => false]);
            }
        }
    }

    /**
     * Lo stato di archivio e di predefinito di un elemento dopo una modifica (DelCliente::stato), o l'errore. `predefinito: false` sul
     * predefinito è 422 (si sposta mettendone un altro), tranne se lo si archivia insieme; un elemento archiviato non è predefinito
     * (`predefinito: true` con `archiviato: true`, o su un archiviato che resta tale, è 422); ripristinare oltre il tetto è 409; un
     * elemento ripristinato è predefinito solo se è l'unico non archiviato.
     *
     * @param  array<string, mixed>  $riga
     * @param  array<string, mixed>  $campi
     * @param  list<array<string, mixed>>  $vivi  i non archiviati del cliente, prima della modifica
     * @return array{predefinito: bool, archiviata: bool, promuovi: bool}
     */
    private function statoDelClienteCrm(array $riga, array $campi, array $vivi): array
    {
        $eraArchiviato = $riga['archiviata_il'] !== null;
        $eraPredefinito = $riga['predefinito'] === true;
        $archiviata = array_key_exists('archiviato', $campi) ? $campi['archiviato'] === true : $eraArchiviato;
        $vuole = $campi['predefinito'] ?? null;

        if ($archiviata && $vuole === true) {
            throw $this->erroreCrm('predefinito', 'regole.predefinito_archiviato', Regola::VALORE_NON_AMMESSO);
        }

        if ($vuole === false && $eraPredefinito && ! $archiviata) {
            throw $this->erroreCrm('predefinito', 'regole.predefinito_non_si_toglie', Regola::VALORE_NON_AMMESSO);
        }

        if ($eraArchiviato && ! $archiviata) {
            $this->tettoDelClienteCrm($vivi);
        }

        $predefinito = match (true) {
            $archiviata => false,
            $vuole === true, $eraPredefinito => true,
            default => $eraArchiviato && $vivi === [],
        };

        return ['predefinito' => $predefinito, 'archiviata' => $archiviata, 'promuovi' => $eraPredefinito && $archiviata];
    }

    /**
     * Scrive una modifica (DelCliente::stato e ::applica, e ::dopoIlSalvataggio): le colonne di `$dopo` e lo stato di archivio e di
     * predefinito. Se diventa il predefinito toglie lo stato agli altri; se archivia il predefinito promuove il più vecchio rimasto. Una
     * richiesta che non cambia niente non scrive.
     *
     * @param  array<string, mixed>  $riga
     * @param  array<string, mixed>  $campi
     * @param  array<string, mixed>  $dopo
     */
    private function scriviDelClienteCrm(string $tipo, string $workspace, array $riga, array $campi, array $dopo): void
    {
        $colonna = $riga['persona_id'] !== null ? 'persona_id' : 'organizzazione_id';
        $vivi = $this->viviDelClienteCrm($tipo, $workspace, $colonna, $riga[$colonna]);
        $stato = $this->statoDelClienteCrm($riga, $campi, $vivi);

        if ($stato['predefinito'] === $riga['predefinito'] && $stato['archiviata'] === ($riga['archiviata_il'] !== null) && array_filter($dopo, fn (mixed $valore, string $campo) => $riga[$campo] !== $valore, ARRAY_FILTER_USE_BOTH) === []) {
            return;
        }

        if ($stato['predefinito'] && $riga['predefinito'] !== true) {
            $this->liberaIlPredefinitoCrm($tipo, $vivi, $riga['id']);
        }

        $this->salvaCrm($tipo, $riga['id'], [
            ...$dopo,
            'predefinito' => $stato['predefinito'],
            'archiviata_il' => $stato['archiviata'] ? ($riga['archiviata_il'] ?? self::adessoCrm()) : null,
        ]);

        if ($stato['promuovi']) {
            $rimasti = $this->viviDelClienteCrm($tipo, $workspace, $colonna, $riga[$colonna]);

            if ($rimasti !== [] && ! in_array(true, array_column($rimasti, 'predefinito'), true)) {
                $this->salvaCrm($tipo, $rimasti[0]['id'], ['predefinito' => true]);
            }
        }
    }

    /**
     * Una risorsa letta con l'ETag: forte, e un `If-None-Match` che lo contiene (confronto debole) dà 304 senza corpo.
     *
     * @param  array<string, mixed>  $voce
     * @return array{int, array<string, mixed>|null, array<string, string>}
     */
    private function mostraDelClienteCrm(Request $richiesta, array $voce): array
    {
        $etag = self::etagCrm($voce);

        if (self::trovaEtagCrm($richiesta->toPsrRequest()->getHeaderLine('If-None-Match'), $etag, true)) {
            return [304, null, ['ETag' => $etag]];
        }

        return [200, ['data' => $voce], ['ETag' => $etag]];
    }

    /**
     * Anonimizzazione::profiliEIndirizzi: i profili e gli indirizzi del contatto, tutti, anche quelli già archiviati, perdono ogni dato
     * (`null`, e `[]` per `email_fatture`), non sono più predefiniti e si archiviano; restano l'id, il `paese`, il `tipo_intestatario`
     * e le date.
     */
    private function dimenticaIProfiliCrm(string $persona): void
    {
        $azzerati = [
            'profili' => ['nome_profilo', 'intestatario', 'partita_iva', 'codice_fiscale', 'estero_tipo', 'estero_valore', 'codice_destinatario', 'pec', 'codice_univoco_ufficio', 'via', 'civico', 'cap', 'citta', 'provincia', 'indirizzo_paese'],
            'indirizzi' => ['nome_indirizzo', 'destinatario', 'presso', 'via', 'civico', 'riga2', 'cap', 'citta', 'provincia', 'telefono_corriere', 'note_consegna'],
        ];
        $ora = self::adessoCrm();

        foreach ($azzerati as $tipo => $colonne) {
            foreach ($this->crm[$tipo] as $id => $riga) {
                if ($riga['persona_id'] === $persona) {
                    $this->crm[$tipo][$id] = [
                        ...$riga, ...array_fill_keys($colonne, null), ...($tipo === 'profili' ? ['email_fatture' => []] : []),
                        'predefinito' => false, 'archiviata_il' => $riga['archiviata_il'] ?? $ora, 'aggiornato_il' => $ora,
                    ];
                }
            }
        }
    }
}
