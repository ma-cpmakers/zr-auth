<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * La personalizzazione del CRM nel backoffice finto (P1 «palestra» #2638-#2641 e P2 #2674-#2676, zr-crm #2637, #2673): il pack attivo
 * (`crm.personalizzazione.mostra`), i pacchetti pubblicati e l'installazione (`crm.personalizzazioni.*`,
 * `crm.personalizzazione.installazione.crea`), le funzioni (`crm.funzioni.*`), il vocabolario (`crm.vocabolario.*`) e i primi passi
 * (`crm.primi-passi.*`), con le regole, i pointer e l'ordine dei controller `Crm/Personalizzazione*`, `Funzioni`, `Vocabolario` e
 * `PrimiPassi` del backoffice. Il pack è il «generico» di `resources/crm` (copia del backoffice: l'unico pubblicato); le scelte del
 * workspace stanno in memoria e vincono sul pack. Nessuna copia di dati: «fatto» e `dati_presenti` si leggono dai dati del CRM del finto,
 * a ogni chiamata. Ciò che il finto non imita (storico, eventi, il lock del workspace) è nel README.
 */
trait CrmPersonalizzazione
{
    /** Il tetto di una stringa del vocabolario, in caratteri. */
    private const CRM_VOCABOLARIO_TESTO_MASSIMO = 200;

    /** Gli stati che una persona scrive su un primo passo. */
    private const CRM_PASSI_SCRIVIBILI = ['da_fare', 'saltato'];

    /** @var array<string, array<string, bool>> le funzioni accese o spente da ogni workspace, per chiave */
    private array $crmFunzioniScelte = [];

    /** @var array<string, array<string, array{it: string, en: string, es: string}>> le rinomine del vocabolario di ogni workspace */
    private array $crmRinomine = [];

    /** @var array<string, array<string, string>> lo stato scelto per ogni primo passo di ogni workspace */
    private array $crmPassiScelti = [];

    /** @var array<string, array{pack: string, variante: ?string, versione: int, creata_il: \Carbon\CarbonImmutable}> l'installazione di ogni workspace */
    private array $crmInstallazioni = [];

    /** @var array<string, array{pack: array<string, mixed>, vocabolario: array<string, array<string, string>>}> i pack letti da resources/crm, per chiave */
    private array $crmPackLetti = [];

    /**
     * crm.personalizzazione.mostra (PersonalizzazioneController::mostra): il pack attivo, il cliente predefinito, la barra già filtrata sulle
     * funzioni accese, l'istante dell'installazione e `installabile`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraPersonalizzazioneCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $pack = $this->packCrm($chi['workspace']);
        $installazione = $this->crmInstallazioni[$chi['workspace']] ?? null;

        return [200, ['data' => [
            'pack' => ['chiave' => $pack['chiave'], 'versione' => $pack['versione'], 'variante' => $installazione['variante'] ?? $pack['variante']],
            'tipo_cliente_predefinito' => $pack['tipo_cliente_predefinito'],
            'barra' => $this->barraCrm($chi['workspace']),
            'installata_il' => $installazione === null ? null : self::iso($installazione['creata_il']),
            'installabile' => $this->installabileCrm($chi['workspace']),
        ]]];
    }

    /**
     * crm.personalizzazione.installazione.crea (PersonalizzazioneController::installazioneCrea): il pack e la variante si controllano
     * sull'elenco dei pubblicati (422 sul pointer); poi `409` `pacchetto_gia_installato` se c'è già un'installazione e `409`
     * `personalizzazione_con_dati` se il CRM ha dati, la prima delle due se ci sono tutte e due. Un rifiuto non scrive niente.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function installaPersonalizzazioneCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.personalizzazione.installazione.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $scelto = $corpo['pack'] ?? null;
            $varianti = is_string($scelto) && in_array($scelto, $this->chiaviDeiPackCrm(), true) ? array_column($this->packDiCrm($scelto)['varianti'], 'chiave') : [];
            $campi = $this->testi->validaCorpo($corpo, [
                'pack' => ['bail', 'required', 'string', 'max:40', Rule::in($this->chiaviDeiPackCrm())],
                'variante' => ['bail', 'sometimes', 'nullable', 'string', 'max:40', Rule::in($varianti)],
            ]);

            if (isset($this->crmInstallazioni[$chi['workspace']])) {
                throw new Problema('pacchetto_gia_installato');
            }

            if ($this->conDatiCrm($chi['workspace'])) {
                throw new Problema('personalizzazione_con_dati');
            }

            $pack = $this->packDiCrm($campi['pack']);
            $this->crmInstallazioni[$chi['workspace']] = ['pack' => $pack['chiave'], 'variante' => $campi['variante'] ?? null, 'versione' => $pack['versione'], 'creata_il' => self::adessoCrm()];
            $fatta = $this->crmInstallazioni[$chi['workspace']];

            // Come il backoffice: la pipeline di partenza intatta si archivia solo se il pack porta le sue (#2707); poi le pipeline, i motivi, i
            // tipi di scadenza e il catalogo del pack (InstallaIlPack), o tutto o niente.
            $prima = $this->crm;

            try {
                $archiviate = ($pack['pipeline'] ?? []) === [] ? [] : $this->archiviaLePipelineDiPartenzaCrm($chi['workspace']);
                $this->installaDatiDelPackCrm($chi, $pack);
            } catch (Problema $errore) {
                $this->crm = $prima;
                unset($this->crmInstallazioni[$chi['workspace']]);

                throw $errore;
            }

            return [201, ['data' => [
                'pack' => ['chiave' => $fatta['pack'], 'versione' => $fatta['versione'], 'variante' => $fatta['variante']],
                'installata_il' => self::iso($fatta['creata_il']),
                'pipeline_archiviate' => $archiviate,
            ]], ['Location' => '/v1/crm/personalizzazione']];
        });
    }

    /**
     * crm.personalizzazioni.elenca (PersonalizzazioniController::elenca): i pacchetti pubblicati, con nome e descrizione nella lingua della persona.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaPersonalizzazioniCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);

        return [200, ['data' => array_map(fn (string $chiave) => $this->descrittoCrm($chi['persona'], $this->packDiCrm($chiave)), $this->chiaviDeiPackCrm())]];
    }

    /**
     * crm.personalizzazioni.mostra (PersonalizzazioniController::mostra): un pacchetto con l'anteprima di cosa cambia; uno non pubblicato è 404.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraAnteprimaCrm(Request $richiesta, string $chiave): array
    {
        $chi = $this->conCrm($richiesta);

        if (! in_array($chiave, $this->chiaviDeiPackCrm(), true)) {
            throw new Problema('non_trovato');
        }

        $pack = $this->packDiCrm($chiave);
        $accese = array_keys(array_filter($pack['funzioni'], fn (array $funzione) => $funzione['sempre'] || $funzione['predefinita']));
        $lingua = $this->linguaCrm($chi['persona']);
        $generico = $this->packLetturaCrm('generico')['vocabolario'];

        return [200, ['data' => $this->descrittoCrm($chi['persona'], $pack) + ['anteprima' => [
            'vocabolario_chiavi' => array_keys(array_filter($this->packLetturaCrm($chiave)['vocabolario'], fn (array $testi, string $voce) => ($generico[$voce] ?? null) !== $testi, ARRAY_FILTER_USE_BOTH)),
            'pipeline' => array_map(fn (array $pipeline) => $pipeline['nome'][$lingua], $pack['pipeline'] ?? []),
            'campi' => [],
            'catalogo_bozza' => array_map(fn (array $voce) => $voce['nome'][$lingua], $pack['catalogo'] ?? []),
            'tipi_scadenza' => array_map(fn (array $tipo) => $tipo['nome'][$lingua], $pack['tipi_scadenza'] ?? []),
            'funzioni_attive' => $accese,
            'barra' => array_keys(array_filter($pack['barra'], fn (?string $funzione) => $funzione === null || in_array($funzione, $accese, true))),
            'primi_passi' => array_keys(array_filter($pack['passi'], fn (array $passo) => in_array($passo['funzione'], $accese, true))),
        ]]]];
    }

    /**
     * crm.funzioni.elenca (FunzioniController::elenca): le funzioni nell'ordine del pack, con lo stato del workspace.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaFunzioniCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);

        return [200, ['data' => $this->funzioniCrm($chi['workspace'])]];
    }

    /**
     * crm.funzioni.modifica (FunzioniController::modifica): accende o spegne una funzione. Una che non c'è è 404 prima del corpo; lo stesso valore
     * di adesso è 200 senza scrivere; nessuna cascata: accendere con una richiesta spenta, spegnere una richiesta da una accesa o toccare una
     * `sempre_attiva` è 409 `funzione_vincolata`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaFunzioneCrm(Request $richiesta, array $corpo, string $funzione): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        if (! array_key_exists($funzione, $this->packCrm($chi['workspace'])['funzioni'])) {
            throw new Problema('non_trovato');
        }

        $campi = $this->modificaCrm($corpo, ['attivo' => ['required', 'boolean:strict']]);
        $elenco = array_column($this->funzioniCrm($chi['workspace']), null, 'chiave');
        $questa = $elenco[$funzione];
        $voglio = (bool) $campi['attivo'];

        if ($voglio === $questa['attivo']) {
            return [200, ['data' => $questa]];
        }

        $vincolo = $questa['sempre_attiva']
            || ($voglio && array_filter($questa['richiede'], fn (string $altra) => ! $elenco[$altra]['attivo']) !== [])
            || (! $voglio && array_filter($elenco, fn (array $altra) => $altra['attivo'] && in_array($funzione, $altra['richiede'], true)) !== []);

        if ($vincolo) {
            throw new Problema('funzione_vincolata');
        }

        $this->crmFunzioniScelte[$chi['workspace']][$funzione] = $voglio;

        return [200, ['data' => array_merge($questa, ['attivo' => $voglio])]];
    }

    /**
     * crm.vocabolario.elenca (VocabolarioController::elenca): i testi del CRM per chiave, nell'ordine del file del pack.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaVocabolarioCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);

        return [200, ['data' => $this->vocabolarioCrm($chi['workspace'], $chi['persona'])]];
    }

    /**
     * crm.vocabolario.modifica (VocabolarioController::modifica): `{chiave: {it, en, es} | null}`. Tutto o niente: l'elenco dei rifiuti è intero e
     * un rifiuto non scrive nemmeno le altre chiavi. Una chiave che il pack non ha è `campo_estraneo` su `#/<chiave>`; una rinomina ha le tre
     * lingue, non vuote e al più 200 caratteri dopo `trim`. `null` toglie la rinomina. Un corpo senza chiavi è 422 su `#`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaVocabolarioCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        if ($corpo === []) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => 'chiavi del vocabolario']), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#']]);
        }

        if (array_is_list($corpo)) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('validation.json', ['attribute' => 'corpo']), 'regola' => Regola::TIPO, 'pointer' => '#']]);
        }

        $valide = array_keys($this->packLetturaCrm($this->chiavePackCrm($chi['workspace']))['vocabolario']);
        $errori = [];

        foreach ($corpo as $chiave => $valore) {
            $chiave = (string) $chiave;

            if (! in_array($chiave, $valide, true)) {
                $errori[] = ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => $chiave]), 'regola' => Regola::CAMPO_ESTRANEO, 'pointer' => '#/'.self::pointerCrm($chiave)];

                continue;
            }

            if ($valore !== null) {
                array_push($errori, ...$this->erroriDellaRinominaCrm($chiave, $valore));
            }
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }

        foreach ($corpo as $chiave => $valore) {
            if ($valore === null) {
                unset($this->crmRinomine[$chi['workspace']][(string) $chiave]);

                continue;
            }

            $this->crmRinomine[$chi['workspace']][(string) $chiave] = ['it' => trim($valore['it']), 'en' => trim($valore['en']), 'es' => trim($valore['es'])];
        }

        return [200, ['data' => $this->vocabolarioCrm($chi['workspace'], $chi['persona'])]];
    }

    /**
     * crm.primi-passi.elenca (PrimiPassiController::elenca): i primi passi del pack la cui funzione è accesa, nell'ordine fisso.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaPrimiPassiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);

        return [200, ['data' => $this->passiCrm($chi['workspace'])]];
    }

    /**
     * crm.primi-passi.modifica (PrimiPassiController::modifica): salta un passo o lo riporta a `da_fare`. Un passo che il pack non ha, o la cui
     * funzione è spenta, è 404 prima del corpo; `fatto` non si scrive (422 su `#/stato`); un passo già fatto resta fatto, 200 senza scrivere.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaPassoCrm(Request $richiesta, array $corpo, string $passo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $trova = fn () => array_values(array_filter($this->passiCrm($chi['workspace']), fn (array $dato) => $dato['chiave'] === $passo))[0] ?? null;

        if ($trova() === null) {
            throw new Problema('non_trovato');
        }

        $campi = $this->modificaCrm($corpo, ['stato' => ['required', 'string', Rule::in(self::CRM_PASSI_SCRIVIBILI)]]);

        if ($trova()['stato'] !== 'fatto') {
            $this->crmPassiScelti[$chi['workspace']][$passo] = $campi['stato'];
        }

        return [200, ['data' => $trova()]];
    }

    /** Il pack e il suo vocabolario, letti una volta. @return array{pack: array<string, mixed>, vocabolario: array<string, array<string, string>>} */
    private function packLetturaCrm(string $chiave): array
    {
        $risorse = dirname(__DIR__, 3).'/resources/crm';

        return $this->crmPackLetti[$chiave] ??= [
            'pack' => require $risorse."/{$chiave}.php",
            'vocabolario' => json_decode((string) file_get_contents($risorse."/{$chiave}-vocabolario.json"), true, 512, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * Ciò che il pack scrive nel workspace (InstallaIlPack): le pipeline con le fasi aperte nell'ordine del pack (la prima nata è quella di
     * partenza), i motivi di perdita, i tipi di scadenza e le voci del catalogo in bozza, nella lingua di chi installa. Un motivo o un tipo
     * già presente (stesso nome per somiglianza) non si rifà. Il chiamante ripristina lo stato se qualcosa lancia.
     *
     * @param  array{workspace: string, persona: string}  $chi
     * @param  array<string, mixed>  $pack
     */
    private function installaDatiDelPackCrm(array $chi, array $pack): void
    {
        $lingua = $this->linguaCrm($chi['persona']);
        $workspace = $chi['workspace'];

        foreach ($pack['pipeline'] ?? [] as $pipeline) {
            $this->nascePipelineCrm($chi, [
                'nome' => $pipeline['nome'][$lingua],
                'fasi' => array_map(fn (array $fase) => ['nome' => $fase['nome'][$lingua], 'probabilita' => $fase['probabilita'], 'giorni_massimi' => null], $pipeline['fasi']),
            ]);
        }

        foreach ($pack['motivi_perdita'] ?? [] as $motivo) {
            $nome = $motivo[$lingua];
            $presente = array_filter($this->motiviViviCrm($workspace), fn (array $altro) => self::nomeSimileCrm($altro['nome'], null) === self::nomeSimileCrm($nome, null));

            if ($presente !== []) {
                continue;
            }

            if (count($this->righeCrm('motivi', $workspace)) >= self::CRM_TETTI['motivi']) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['motivi']]);
            }

            $vivi = $this->motiviViviCrm($workspace);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['motivi'][$id] = [
                'id' => $id, 'workspace' => $workspace, 'nome' => $nome,
                'posizione' => $vivi === [] ? self::CRM_PASSO : end($vivi)['posizione'] + self::CRM_PASSO,
                'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
        }

        foreach ($pack['tipi_scadenza'] ?? [] as $tipo) {
            $nome = $tipo['nome'][$lingua];
            $presente = array_filter($this->righeCrm('scadenzeTipi', $workspace), fn (array $altro) => $altro['stato'] === 'attivo' && self::nomeSimileCrm($altro['nome'], null) === self::nomeSimileCrm($nome, null));

            if ($presente !== []) {
                continue;
            }

            if (count($this->righeCrm('scadenzeTipi', $workspace)) >= self::CRM_SCADENZE_TIPI_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_SCADENZE_TIPI_TETTO]);
            }

            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['scadenzeTipi'][$id] = [
                'id' => $id, 'workspace' => $workspace, 'nome' => $nome, 'preavvisi' => self::preavvisiOrdinatiCrm($tipo['preavvisi']),
                'ricorrenza_mesi' => $tipo['ricorrenza_mesi'], 'sensibile' => $tipo['sensibile'], 'stato' => 'attivo',
                'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];
        }

        foreach ($pack['catalogo'] ?? [] as $voce) {
            $this->nasceVoceDelPackCrm($chi, $voce, $lingua);
        }
    }

    /**
     * Una voce di catalogo del pack, in bozza, come `CatalogoController::daUnPack`: il nome, la categoria e l'etichetta delle unità nella lingua
     * di chi installa, il prezzo solo se il pack lo dà.
     *
     * @param  array{workspace: string, persona: string}  $chi
     * @param  array<string, mixed>  $voce
     */
    private function nasceVoceDelPackCrm(array $chi, array $voce, string $lingua): void
    {
        $campi = ['tipo' => $voce['tipo'], 'nome' => $voce['nome'][$lingua], 'iva_inclusa' => $voce['iva_inclusa']];

        foreach (['durata_minuti', 'unita_incluse', 'validita_mesi', 'rinnovo'] as $campo) {
            if (array_key_exists($campo, $voce)) {
                $campi[$campo] = $voce[$campo];
            }
        }

        foreach (['categoria', 'etichetta_unita'] as $campo) {
            if (isset($voce[$campo])) {
                $campi[$campo] = $voce[$campo][$lingua];
            }
        }

        if (is_array($voce['prezzo'] ?? null)) {
            $campi['prezzo'] = ['importo' => $voce['prezzo']['importo']];
        }

        $this->coerenzaDellaVoceCrm($this->completaLaVoceCrm($campi, null));

        if (count($this->righeCrm('catalogo', $chi['workspace'])) >= self::CRM_CATALOGO_TETTO) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_CATALOGO_TETTO]);
        }

        $id = self::id();
        $ora = self::adessoCrm();
        $this->crm['catalogo'][$id] = $this->colonneDellaVoceCrm($campi) + [
            'id' => $id, 'workspace' => $chi['workspace'], 'stato' => 'bozza', 'iva_inclusa' => true, 'archiviata_il' => null,
            'creata_il' => $ora, 'aggiornata_il' => $ora, 'creata_da' => $chi['persona'],
        ] + $this->colonneDellaVoceCrm(array_fill_keys(self::CRM_CATALOGO_CAMPI, null));
        $this->cambioCrm('catalogo', $id, $chi['persona'], 'creata', []);
    }

    /** @return list<string> le chiavi dei pack pubblicati: «generico» per primo, poi le altre in ordine alfabetico (come `Personalizzazione::pubblicati`) */
    private function chiaviDeiPackCrm(): array
    {
        $chiavi = array_map(fn (string $file) => basename($file, '.php'), glob(dirname(__DIR__, 3).'/resources/crm/*.php') ?: []);
        sort($chiavi);
        usort($chiavi, fn (string $a, string $b) => ($b === 'generico') <=> ($a === 'generico'));

        return $chiavi;
    }

    /** La chiave del pack del workspace: quello che ha installato, o «generico». */
    private function chiavePackCrm(string $workspace): string
    {
        return $this->crmInstallazioni[$workspace]['pack'] ?? 'generico';
    }

    /** @return array<string, mixed> il pack di una chiave pubblicata */
    private function packDiCrm(string $chiave): array
    {
        return $this->packLetturaCrm($chiave)['pack'];
    }

    /** @return array<string, mixed> il pack attivo del workspace: quello che ha installato, o «generico» */
    private function packCrm(string $workspace): array
    {
        return $this->packDiCrm($this->chiavePackCrm($workspace));
    }

    /** La lingua del vocabolario e dei nomi: quella della persona del gettone (it, en o es). */
    private function linguaCrm(string $persona): string
    {
        $lingua = $this->persone[$persona]['lingua'] ?? 'it';

        return in_array($lingua, ['it', 'en', 'es'], true) ? $lingua : 'it';
    }

    /** @return array{chiave: string, nome: string, descrizione: string, versione: int, varianti: list<array{chiave: string, nome: string}>} */
    private function descrittoCrm(string $persona, array $pack): array
    {
        $lingua = $this->linguaCrm($persona);

        return [
            'chiave' => $pack['chiave'],
            'nome' => $pack['nome'][$lingua],
            'descrizione' => $pack['descrizione'][$lingua],
            'versione' => $pack['versione'],
            'varianti' => array_map(fn (array $variante) => ['chiave' => $variante['chiave'], 'nome' => $variante['nome'][$lingua]], $pack['varianti']),
        ];
    }

    /** @return list<array{chiave: string, attivo: bool, sempre_attiva: bool, richiede: list<string>, dati_presenti: bool}> */
    private function funzioniCrm(string $workspace): array
    {
        $elenco = [];

        foreach ($this->packCrm($workspace)['funzioni'] as $chiave => $funzione) {
            $elenco[] = [
                'chiave' => $chiave,
                'attivo' => $funzione['sempre'] || ($this->crmFunzioniScelte[$workspace][$chiave] ?? $funzione['predefinita']),
                'sempre_attiva' => $funzione['sempre'],
                'richiede' => $funzione['richiede'],
                'dati_presenti' => $this->datiDellaFunzioneCrm($workspace, $chiave) !== [],
            ];
        }

        return $elenco;
    }

    /** @return list<string> le chiavi delle funzioni accese */
    private function acceseCrm(string $workspace): array
    {
        return array_column(array_filter($this->funzioniCrm($workspace), fn (array $funzione) => $funzione['attivo']), 'chiave');
    }

    /** @return list<string> le chiavi della barra, nell'ordine del pack, solo delle voci le cui funzioni sono accese */
    private function barraCrm(string $workspace): array
    {
        $accese = $this->acceseCrm($workspace);

        return array_keys(array_filter($this->packCrm($workspace)['barra'], fn (?string $funzione) => $funzione === null || in_array($funzione, $accese, true)));
    }

    /** Se «Benvenuto» si può ancora mostrare e il pack installare: nessuna installazione e nessun dato del CRM. */
    private function installabileCrm(string $workspace): bool
    {
        return ! isset($this->crmInstallazioni[$workspace]) && ! $this->conDatiCrm($workspace);
    }

    /**
     * Se una pipeline è ancora come è nata da partenza.crea (Pipelines::intattaDiPartenza): segnata, non archiviata, mai toccata (`aggiornata_il`
     * uguale a `creata_il`: ogni scrittura di una fase la fa crescere), con le fasi non eliminate e senza nessuna trattativa.
     *
     * @param  array<string, mixed>  $pipeline
     */
    private function pipelineIntattaDiPartenzaCrm(array $pipeline): bool
    {
        if (! ($pipeline['di_partenza'] ?? false) || $pipeline['archiviata_il'] !== null || self::iso($pipeline['aggiornata_il']) !== self::iso($pipeline['creata_il'])) {
            return false;
        }

        $fasiToccate = array_filter($this->crm['fasi'], fn (array $fase) => $fase['pipeline_id'] === $pipeline['id'] && ($fase['eliminata'] ?? false));
        $trattative = array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['pipeline_id'] === $pipeline['id']);

        return $fasiToccate === [] && $trattative === [];
    }

    /**
     * Archivia le pipeline intatte di partenza del workspace (Pipelines::archiviaLeDiPartenza) e dice quali, con id e nome.
     *
     * @return list<array{id: string, nome: string}>
     */
    private function archiviaLePipelineDiPartenzaCrm(string $workspace): array
    {
        $archiviate = [];
        $vive = array_filter($this->righeCrm('pipeline', $workspace), fn (array $pipeline) => $pipeline['di_partenza'] ?? false);
        usort($vive, fn (array $una, array $altra) => [$una['creata_il'], $una['id']] <=> [$altra['creata_il'], $altra['id']]);

        foreach ($vive as $pipeline) {
            if (! $this->pipelineIntattaDiPartenzaCrm($pipeline)) {
                continue;
            }

            $this->crm['pipeline'][$pipeline['id']]['archiviata_il'] = self::adessoCrm();
            $archiviate[] = ['id' => $pipeline['id'], 'nome' => $pipeline['nome']];
        }

        return $archiviate;
    }

    /** Se il CRM ha dati di una qualsiasi delle sue funzioni, o una pipeline creata o toccata dalla persona (#2707). */
    private function conDatiCrm(string $workspace): bool
    {
        foreach ($this->righeCrm('pipeline', $workspace) as $pipeline) {
            if (! $this->pipelineIntattaDiPartenzaCrm($pipeline)) {
                return true;
            }
        }

        foreach (array_keys($this->packCrm($workspace)['funzioni']) as $funzione) {
            if ($this->datiDellaFunzioneCrm($workspace, $funzione) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * I dati che dicono che una funzione è in uso: l'unico punto che lo sa. `documenti` e `beni` non hanno ancora dati.
     *
     * @return list<array<string, mixed>>
     */
    private function datiDellaFunzioneCrm(string $workspace, string $funzione): array
    {
        $tipo = match ($funzione) {
            'clienti', 'lead' => 'persone',
            'pipeline' => 'trattative',
            'attivita' => 'attivita',
            'catalogo' => 'catalogo',
            'preventivi' => 'preventivi',
            'acquisti' => 'acquisti',
            'incassi' => 'pagamenti',
            'scadenze' => 'scadenze',
            default => null,
        };

        if ($tipo === null) {
            return [];
        }

        return array_values(array_filter(
            $this->righeCrm($tipo, $workspace),
            fn (array $riga) => $funzione !== 'lead' || $riga['stato'] === 'lead',
        ));
    }

    /**
     * @return list<array{chiave: string, testo: string, testi: array<string, string>, personalizzato: bool}>
     */
    private function vocabolarioCrm(string $workspace, string $persona): array
    {
        $lingua = $this->linguaCrm($persona);
        $elenco = [];

        foreach ($this->packLetturaCrm($this->chiavePackCrm($workspace))['vocabolario'] as $chiave => $pack) {
            $rinomina = $this->crmRinomine[$workspace][$chiave] ?? null;
            $testi = $rinomina ?? $pack;
            $elenco[] = ['chiave' => $chiave, 'testo' => $testi[$lingua], 'testi' => $testi, 'personalizzato' => $rinomina !== null];
        }

        return $elenco;
    }

    /**
     * I primi passi del pack nell'ordine fisso, quelli la cui funzione è accesa. `fatto` lo dicono i dati del workspace (e vince su ogni scelta);
     * altrimenti vale la scelta della persona, `da_fare` se non ne ha fatta.
     *
     * @return list<array{chiave: string, stato: string, fatto_il: string|null, azione: string}>
     */
    private function passiCrm(string $workspace): array
    {
        $accese = $this->acceseCrm($workspace);
        $elenco = [];

        foreach ($this->packCrm($workspace)['passi'] as $chiave => $passo) {
            if (! in_array($passo['funzione'], $accese, true)) {
                continue;
            }

            $nati = array_map(fn (array $riga) => $riga['creato_il'] ?? $riga['creata_il'], $this->datiDellaFunzioneCrm($workspace, $passo['funzione']));
            $primo = $nati === [] ? null : min($nati);
            $elenco[] = [
                'chiave' => $chiave,
                'stato' => $primo !== null ? 'fatto' : ($this->crmPassiScelti[$workspace][$chiave] ?? 'da_fare'),
                'fatto_il' => $primo === null ? null : self::iso($primo),
                'azione' => $passo['azione'],
            ];
        }

        return $elenco;
    }

    /**
     * Gli errori di una rinomina (VocabolarioController::erroriDellaRinomina): un oggetto con le tre lingue, ognuna una stringa non vuota
     * di al più 200 caratteri dopo `trim`; una lingua in più è `campo_estraneo`.
     *
     * @return list<array{detail: string, regola: string, pointer: string}>
     */
    private function erroriDellaRinominaCrm(string $chiave, mixed $valore): array
    {
        $dove = self::pointerCrm($chiave);

        if (! is_array($valore) || ($valore !== [] && array_is_list($valore))) {
            return [['detail' => $this->testi->testo('validation.array', ['attribute' => $chiave]), 'regola' => Regola::TIPO, 'pointer' => "#/{$dove}"]];
        }

        $errori = [];

        foreach (array_keys($valore) as $lingua) {
            if (! in_array($lingua, ['it', 'en', 'es'], true)) {
                $errori[] = ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => "{$chiave}.{$lingua}"]), 'regola' => Regola::CAMPO_ESTRANEO, 'pointer' => "#/{$dove}/".self::pointerCrm((string) $lingua)];
            }
        }

        foreach (['it', 'en', 'es'] as $lingua) {
            $testo = $valore[$lingua] ?? null;
            $nome = "{$chiave}.{$lingua}";

            if ($testo === null || (is_string($testo) && trim($testo) === '')) {
                $errori[] = ['detail' => $this->testi->testo('validation.required', ['attribute' => $nome]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => "#/{$dove}/{$lingua}"];
            } elseif (! is_string($testo)) {
                $errori[] = ['detail' => $this->testi->testo('validation.string', ['attribute' => $nome]), 'regola' => Regola::TIPO, 'pointer' => "#/{$dove}/{$lingua}"];
            } elseif (mb_strlen(trim($testo)) > self::CRM_VOCABOLARIO_TESTO_MASSIMO) {
                $errori[] = ['detail' => $this->testi->testo('validation.max.string', ['attribute' => $nome, 'max' => self::CRM_VOCABOLARIO_TESTO_MASSIMO]), 'regola' => Regola::TROPPO_LUNGO, 'pointer' => "#/{$dove}/{$lingua}"];
            }
        }

        return $errori;
    }

    /** Un segmento di pointer JSON (RFC 6901, ErroreDiCampo::pointer): `~` e `/` si scrivono `~0` e `~1`, poi la codifica dell'URL. Le chiavi del vocabolario hanno punti, che restano. */
    private static function pointerCrm(string $segmento): string
    {
        return rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segmento));
    }
}
