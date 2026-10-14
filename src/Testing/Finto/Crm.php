<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use LogicException;

/**
 * Il CRM del backoffice finto (#1856, G67): aziende (`organizzazioni`), contatti (`persone`), trattative, pipeline e fasi, motivi di perdita, attività,
 * note, impostazioni e utilizzo (le sezioni e i campi sono di CrmCampi), in memoria e per workspace, con le forme del contratto, i codici del catalogo e le regole dei corpi
 * del backoffice (`app/Http/Controllers/Crm`). Un metodo `crm.*` vuole l'app `crm` attiva nel workspace del gettone (attivaApp()):
 * prima il ruolo (403 permesso_negato), poi l'app (403 app_non_attiva), poi la risorsa del percorso (404 non_trovato, anche per
 * quella di un altro workspace), poi il corpo (422). Ciò che il finto non imita è nel README.
 */
trait Crm
{
    /** I metodi del CRM: verbo, percorso, operationId. I percorsi fissi (`ricerca`, `importazioni`, `partenza`) prima di quelli con un id. */
    private const METODI_CRM = [
        ['GET', '#^/v1/crm/persone$#', 'crm.persone.elenca'],
        ['POST', '#^/v1/crm/persone/ricerca$#', 'crm.persone.ricerca.crea'],
        ['POST', '#^/v1/crm/persone/importazioni$#', 'crm.persone.importazioni.crea'],
        ['GET', '#^/v1/crm/persone/esportazione$#', 'crm.persone.esportazione.mostra'],
        ['POST', '#^/v1/crm/persone$#', 'crm.persone.crea'],
        ['GET', '#^/v1/crm/persone/([^/]+)$#', 'crm.persone.mostra'],
        ['PATCH', '#^/v1/crm/persone/([^/]+)$#', 'crm.persone.modifica'],
        ['GET', '#^/v1/crm/persone/([^/]+)/cronologia$#', 'crm.persone.cronologia.elenca'],
        ['POST', '#^/v1/crm/persone/([^/]+)/anonimizzazione$#', 'crm.persone.anonimizzazione.crea'],
        ['GET', '#^/v1/crm/organizzazioni$#', 'crm.organizzazioni.elenca'],
        ['POST', '#^/v1/crm/organizzazioni/ricerca$#', 'crm.organizzazioni.ricerca.crea'],
        ['POST', '#^/v1/crm/organizzazioni/importazioni$#', 'crm.organizzazioni.importazioni.crea'],
        ['GET', '#^/v1/crm/organizzazioni/esportazione$#', 'crm.organizzazioni.esportazione.mostra'],
        ['POST', '#^/v1/crm/organizzazioni$#', 'crm.organizzazioni.crea'],
        ['GET', '#^/v1/crm/organizzazioni/([^/]+)$#', 'crm.organizzazioni.mostra'],
        ['PATCH', '#^/v1/crm/organizzazioni/([^/]+)$#', 'crm.organizzazioni.modifica'],
        ['GET', '#^/v1/crm/organizzazioni/([^/]+)/cronologia$#', 'crm.organizzazioni.cronologia.elenca'],
        ['GET', '#^/v1/crm/importazioni/([^/]+)$#', 'crm.importazioni.mostra'],
        ['POST', '#^/v1/crm/importazioni/([^/]+)/annullamento$#', 'crm.importazioni.annullamento.crea'],
        ['GET', '#^/v1/crm/motivi$#', 'crm.motivi.elenca'],
        ['POST', '#^/v1/crm/motivi$#', 'crm.motivi.crea'],
        ['PATCH', '#^/v1/crm/motivi/([^/]+)$#', 'crm.motivi.modifica'],
        ['GET', '#^/v1/crm/sezioni$#', 'crm.sezioni.elenca'],
        ['POST', '#^/v1/crm/sezioni$#', 'crm.sezioni.crea'],
        ['PATCH', '#^/v1/crm/sezioni/([^/]+)$#', 'crm.sezioni.modifica'],
        ['DELETE', '#^/v1/crm/sezioni/([^/]+)$#', 'crm.sezioni.elimina'],
        ['GET', '#^/v1/crm/campi$#', 'crm.campi.elenca'],
        ['POST', '#^/v1/crm/campi$#', 'crm.campi.crea'],
        ['PATCH', '#^/v1/crm/campi/([^/]+)$#', 'crm.campi.modifica'],
        ['GET', '#^/v1/crm/pipeline$#', 'crm.pipeline.elenca'],
        ['POST', '#^/v1/crm/pipeline$#', 'crm.pipeline.crea'],
        ['POST', '#^/v1/crm/pipeline/partenza$#', 'crm.pipeline.partenza.crea'],
        ['GET', '#^/v1/crm/pipeline/([^/]+)$#', 'crm.pipeline.mostra'],
        ['PATCH', '#^/v1/crm/pipeline/([^/]+)$#', 'crm.pipeline.modifica'],
        ['POST', '#^/v1/crm/fasi$#', 'crm.fasi.crea'],
        ['PATCH', '#^/v1/crm/fasi/([^/]+)$#', 'crm.fasi.modifica'],
        ['POST', '#^/v1/crm/fasi/([^/]+)/spostamento$#', 'crm.fasi.spostamento.crea'],
        ['DELETE', '#^/v1/crm/fasi/([^/]+)$#', 'crm.fasi.elimina'],
        ['GET', '#^/v1/crm/trattative$#', 'crm.trattative.elenca'],
        ['POST', '#^/v1/crm/trattative/ricerca$#', 'crm.trattative.ricerca.crea'],
        ['GET', '#^/v1/crm/trattative/riepilogo$#', 'crm.trattative.riepilogo.mostra'],
        ['POST', '#^/v1/crm/trattative$#', 'crm.trattative.crea'],
        ['GET', '#^/v1/crm/trattative/([^/]+)$#', 'crm.trattative.mostra'],
        ['PATCH', '#^/v1/crm/trattative/([^/]+)$#', 'crm.trattative.modifica'],
        ['POST', '#^/v1/crm/trattative/([^/]+)/spostamento$#', 'crm.trattative.spostamento.crea'],
        ['GET', '#^/v1/crm/trattative/([^/]+)/cronologia$#', 'crm.trattative.cronologia.elenca'],
        ['GET', '#^/v1/crm/attivita$#', 'crm.attivita.elenca'],
        ['POST', '#^/v1/crm/attivita$#', 'crm.attivita.crea'],
        ['GET', '#^/v1/crm/attivita/([^/]+)$#', 'crm.attivita.mostra'],
        ['PATCH', '#^/v1/crm/attivita/([^/]+)$#', 'crm.attivita.modifica'],
        ['DELETE', '#^/v1/crm/attivita/([^/]+)$#', 'crm.attivita.elimina'],
        ['GET', '#^/v1/crm/note$#', 'crm.note.elenca'],
        ['POST', '#^/v1/crm/note$#', 'crm.note.crea'],
        ['PATCH', '#^/v1/crm/note/([^/]+)$#', 'crm.note.modifica'],
        ['DELETE', '#^/v1/crm/note/([^/]+)$#', 'crm.note.elimina'],
        ['GET', '#^/v1/crm/impostazioni$#', 'crm.impostazioni.mostra'],
        ['PATCH', '#^/v1/crm/impostazioni$#', 'crm.impostazioni.modifica'],
        ['GET', '#^/v1/crm/utilizzo$#', 'crm.utilizzo.mostra'],
    ];

    /** Gli stati di un contatto e di un'azienda (Persona::STATI, Organizzazione::STATI). */
    private const CRM_STATI = ['lead', 'qualificato', 'cliente', 'perso'];

    private const CRM_BASI_GIURIDICHE = ['consenso', 'contratto', 'obbligo_legale', 'interesse_legittimo'];

    private const CRM_ETICHETTE_EMAIL = ['lavoro', 'personale', 'altro'];

    private const CRM_ETICHETTE_TELEFONO = ['lavoro', 'cellulare', 'casa', 'altro'];

    private const CRM_STATI_TRATTATIVA = ['aperta', 'vinta', 'persa'];

    private const CRM_TIPI_ATTIVITA = ['chiamata', 'riunione', 'email', 'compito', 'messaggio'];

    /** I campi dell'indirizzo di un'azienda nell'ordine della risposta (Organizzazioni::INDIRIZZO). */
    private const CRM_INDIRIZZO = ['via', 'cap', 'citta', 'provincia', 'paese'];

    /** La valuta di un CRM che non l'ha mai cambiata (Impostazioni::PREDEFINITA). */
    private const CRM_VALUTA = 'EUR';

    /** Le valute che crm.impostazioni.modifica accetta (config zeiras.crm.valute). */
    private const CRM_VALUTE = [
        'AED', 'AFN', 'ALL', 'AMD', 'AOA', 'ARS', 'AUD', 'AWG', 'AZN', 'BAM', 'BBD', 'BDT', 'BHD', 'BIF', 'BMD', 'BND',
        'BOB', 'BRL', 'BSD', 'BTN', 'BWP', 'BYN', 'BZD', 'CAD', 'CDF', 'CHF', 'CLP', 'CNY', 'COP', 'CRC', 'CUP', 'CVE',
        'CZK', 'DJF', 'DKK', 'DOP', 'DZD', 'EGP', 'ERN', 'ETB', 'EUR', 'FJD', 'FKP', 'GBP', 'GEL', 'GHS', 'GIP', 'GMD',
        'GNF', 'GTQ', 'GYD', 'HKD', 'HNL', 'HTG', 'HUF', 'IDR', 'ILS', 'INR', 'IQD', 'IRR', 'ISK', 'JMD', 'JOD', 'JPY',
        'KES', 'KGS', 'KHR', 'KMF', 'KPW', 'KRW', 'KWD', 'KYD', 'KZT', 'LAK', 'LBP', 'LKR', 'LRD', 'LSL', 'LYD', 'MAD',
        'MDL', 'MGA', 'MKD', 'MMK', 'MNT', 'MOP', 'MRU', 'MUR', 'MVR', 'MWK', 'MXN', 'MYR', 'MZN', 'NAD', 'NGN', 'NIO',
        'NOK', 'NPR', 'NZD', 'OMR', 'PAB', 'PEN', 'PGK', 'PHP', 'PKR', 'PLN', 'PYG', 'QAR', 'RON', 'RSD', 'RUB', 'RWF',
        'SAR', 'SBD', 'SCR', 'SDG', 'SEK', 'SGD', 'SHP', 'SLE', 'SOS', 'SRD', 'SSP', 'STN', 'SYP', 'SZL', 'THB', 'TJS',
        'TMT', 'TND', 'TOP', 'TRY', 'TTD', 'TWD', 'TZS', 'UAH', 'UGX', 'USD', 'UYU', 'UZS', 'VES', 'VND', 'VUV', 'WST',
        'XAF', 'XCD', 'XCG', 'XOF', 'XPF', 'YER', 'ZAR', 'ZMW', 'ZWG',
    ];

    /** I tetti del CRM (config zeiras.crm e le costanti dei controller): quelli di crm.utilizzo.mostra nell'ordine della risposta, poi i motivi, che non vi compaiono. */
    private const CRM_TETTI = [
        'trattative' => 25000, 'trattative_aperte_per_pipeline' => 5000, 'attivita_non_fatte' => 10000, 'attivita' => 50000,
        'persone' => 25000, 'organizzazioni' => 10000, 'note' => 50000, 'pipeline' => 10, 'fasi_per_pipeline' => 20, 'motivi' => 50,
    ];

    /** Il titolo di un'opportunità di un contatto anonimizzato (Anonimizzazione::TITOLO_OPPORTUNITA). */
    private const CRM_TITOLO_ANONIMO = 'Opportunità di un contatto anonimizzato';

    /** Gli ordini di ogni elenco (FiltriDi*::ORDINI): il valore di `ordina` → il campo della riga; il primo è il predefinito. */
    private const CRM_ORDINI = [
        'persone' => ['nome' => 'nome', 'creato_il' => 'creato_il', 'aggiornato_il' => 'aggiornato_il', 'ultima_attivita_il' => 'ultima_attivita_il', 'prossima_scadenza' => 'prossima_scadenza'],
        'organizzazioni' => ['nome' => 'nome', 'creata_il' => 'creata_il', 'aggiornata_il' => 'aggiornata_il', 'ultima_attivita_il' => 'ultima_attivita_il'],
        'trattative' => ['posizione' => 'posizione', 'titolo' => 'titolo', 'valore' => 'importo', 'chiusura_prevista' => 'chiusura_prevista', 'creata_il' => 'creata_il'],
        'attivita' => ['scadenza' => 'scadenza', 'creata_il' => 'creata_il', 'svolta_il' => 'svolta_il'],
    ];

    /** I parametri vietati nella query di un elenco, mai letti: un testo non sta nell'URL (G58; le *ElencaRequest). */
    private const CRM_VIETATI = [
        'persone' => ['q', 'email'],
        'organizzazioni' => ['q', 'nome'],
        'trattative' => ['q', 'titolo', 'nome', 'cognome', 'email', 'telefono', 'motivo_perdita', 'testo'],
        'attivita' => ['q', 'titolo', 'note', 'esito', 'nome', 'cognome', 'email', 'telefono', 'testo'],
    ];

    /** Oltre quante righe `totale` si ferma e porta `totale_almeno` (*::TETTO_DEL_TOTALE). */
    private const CRM_TETTO_DEL_TOTALE = 10000;

    /** I freni delle esportazioni, all'ora (config zeiras.freni.crm_esportazioni_*, #1905): per persona e per workspace, i due metodi insieme. */
    private const CRM_ESPORTAZIONI_PERSONA_ALL_ORA = 10;

    private const CRM_ESPORTAZIONI_WORKSPACE_ALL_ORA = 20;

    /** Quanti id al più nel filtro `id` (FiltroPerId::MASSIMO). */
    private const CRM_ID_NEL_FILTRO = 100;

    /** Le ore entro cui un'importazione si annulla (Importazione::ORE_PER_ANNULLARE), e gli annullamenti all'ora di una persona. */
    private const CRM_ORE_PER_ANNULLARE = 24;

    private const CRM_ANNULLAMENTI_ALL_ORA = 30;

    /** Quante righe al più in un'importazione, e quanti errori al più per riga rifiutata (Importazioni::RIGHE, ERRORI_PER_RIGA). */
    private const CRM_RIGHE_DI_UN_IMPORT = 500;

    private const CRM_ERRORI_PER_RIGA = 3;

    /**
     * I freni delle importazioni, coi tetti all'ora (Importazioni::FRENI e config zeiras.freni.crm_importazioni_*, #1894): le chiamate di
     * una persona, prova compresa, e le righe per persona e per workspace, la prova separata dalla scrittura.
     */
    private const CRM_FRENI_DELLE_IMPORTAZIONI = ['chiamate_persona' => 300, 'prova_persona' => 30000, 'prova_workspace' => 60000, 'scrittura_persona' => 12000, 'scrittura_workspace' => 24000];

    /** Le trattative collegate più recenti che danno righe alla cronologia di un contatto (config zeiras.crm.cronologia_trattative_per_contatto). */
    private const CRM_FINESTRA_DELLE_TRATTATIVE = 200;

    /** Il passo fra due posizioni vicine (Posizioni::PASSO). */
    private const CRM_PASSO = 1024;

    /** Oltre questa posizione la coda non cresce più: si rinumera (Posizioni::SOGLIA). */
    private const CRM_SOGLIA = 1_000_000_000;

    /**
     * Lo stato del CRM, per tipo e per id: ogni riga porta il suo `workspace`. Le righe eliminate (fasi, attività, note) restano con
     * `eliminata`, come nell'archivio di sistema; le `cambi` sono le righe delle cronologie di contatti, aziende e trattative.
     *
     * @var array{organizzazioni: array<string, array<string, mixed>>, persone: array<string, array<string, mixed>>, trattative: array<string, array<string, mixed>>, pipeline: array<string, array<string, mixed>>, motivi: array<string, array<string, mixed>>, fasi: array<string, array<string, mixed>>, attivita: array<string, array<string, mixed>>, note: array<string, array<string, mixed>>, cambi: list<array<string, mixed>>, valute: array<string, string>, importazioni: array<string, array<string, mixed>>, valori: array<string, array<string, array<string, mixed>>>}
     */
    private array $crm = ['organizzazioni' => [], 'persone' => [], 'trattative' => [], 'pipeline' => [], 'motivi' => [], 'fasi' => [], 'attivita' => [], 'note' => [], 'cambi' => [], 'valute' => [], 'importazioni' => [], 'valori' => []];

    /**
     * Esegue un metodo `crm.*`.
     *
     * @param  array<mixed>  $corpo
     * @param  list<string>  $parametri
     * @return array{0: int, 1: array<string, mixed>|null, 2?: array<string, string>}
     */
    private function eseguiCrm(string $operazione, Request $richiesta, array $corpo, array $parametri): array
    {
        $amministra = ['proprietario', 'amministratore'];

        return match ($operazione) {
            'crm.organizzazioni.crea' => $this->creaOrganizzazione($richiesta, $corpo),
            'crm.organizzazioni.mostra' => $this->mostraCrm($richiesta, 'organizzazioni', $parametri[0]),
            'crm.organizzazioni.modifica' => $this->modificaOrganizzazioneCrm($richiesta, $corpo, $parametri[0]),
            'crm.organizzazioni.elenca' => $this->elencaCrm($richiesta, 'organizzazioni', $operazione),
            'crm.organizzazioni.esportazione.mostra' => $this->esportaCrm($richiesta, 'organizzazioni', $operazione),
            'crm.organizzazioni.ricerca.crea' => $this->ricercaCrm($richiesta, $corpo, 'organizzazioni', $operazione),
            'crm.organizzazioni.importazioni.crea' => $this->importaCrm($richiesta, $corpo, 'organizzazioni', $operazione),
            'crm.organizzazioni.cronologia.elenca' => $this->cronologiaCrm($richiesta, 'organizzazioni', $parametri[0], $operazione),
            'crm.persone.crea' => $this->creaPersonaCrm($richiesta, $corpo),
            'crm.persone.mostra' => $this->mostraCrm($richiesta, 'persone', $parametri[0]),
            'crm.persone.modifica' => $this->modificaPersonaCrm($richiesta, $corpo, $parametri[0]),
            'crm.persone.elenca' => $this->elencaCrm($richiesta, 'persone', $operazione),
            'crm.persone.esportazione.mostra' => $this->esportaCrm($richiesta, 'persone', $operazione),
            'crm.persone.ricerca.crea' => $this->ricercaCrm($richiesta, $corpo, 'persone', $operazione),
            'crm.persone.importazioni.crea' => $this->importaCrm($richiesta, $corpo, 'persone', $operazione),
            'crm.persone.cronologia.elenca' => $this->cronologiaCrm($richiesta, 'persone', $parametri[0], $operazione),
            'crm.persone.anonimizzazione.crea' => $this->anonimizzaCrm($richiesta, $parametri[0]),
            'crm.importazioni.mostra' => [200, ['data' => $this->voceImportazioneCrm($this->importazioneCrm($this->conCrm($richiesta, ['proprietario', 'amministratore'])['workspace'], $parametri[0]))]],
            'crm.importazioni.annullamento.crea' => $this->annullaImportazioneCrm($richiesta, $corpo, $parametri[0]),
            'crm.motivi.elenca' => $this->elencaMotiviCrm($richiesta),
            'crm.motivi.crea' => $this->creaMotivoCrm($richiesta, $corpo),
            'crm.motivi.modifica' => $this->modificaMotivoCrm($richiesta, $corpo, $parametri[0]),
            'crm.sezioni.elenca' => $this->elencaSezioniCrm($richiesta),
            'crm.sezioni.crea' => $this->creaSezioneCrm($richiesta, $corpo),
            'crm.sezioni.modifica' => $this->modificaSezioneCrm($richiesta, $corpo, $parametri[0]),
            'crm.sezioni.elimina' => $this->eliminaSezioneCrm($richiesta, $parametri[0]),
            'crm.campi.elenca' => $this->elencaCampiCrm($richiesta),
            'crm.campi.crea' => $this->creaCampoCrm($richiesta, $corpo),
            'crm.campi.modifica' => $this->modificaCampoCrm($richiesta, $corpo, $parametri[0]),
            'crm.pipeline.crea' => $this->creaPipelineCrm($richiesta, $corpo),
            'crm.pipeline.partenza.crea' => $this->partenzaPipelineCrm($richiesta, $corpo),
            'crm.pipeline.mostra' => $this->mostraCrm($richiesta, 'pipeline', $parametri[0]),
            'crm.pipeline.modifica' => $this->modificaPipelineCrm($richiesta, $corpo, $parametri[0]),
            'crm.pipeline.elenca' => $this->elencaPipelineCrm($richiesta),
            'crm.fasi.crea' => $this->creaFaseCrm($richiesta, $corpo),
            'crm.fasi.modifica' => $this->modificaFaseCrm($richiesta, $corpo, $parametri[0]),
            'crm.fasi.spostamento.crea' => $this->spostaFaseCrm($richiesta, $corpo, $parametri[0]),
            'crm.fasi.elimina' => $this->eliminaFaseCrm($richiesta, $parametri[0]),
            'crm.trattative.crea' => $this->creaTrattativaCrm($richiesta, $corpo),
            'crm.trattative.mostra' => $this->mostraCrm($richiesta, 'trattative', $parametri[0]),
            'crm.trattative.modifica' => $this->modificaTrattativaCrm($richiesta, $corpo, $parametri[0]),
            'crm.trattative.spostamento.crea' => $this->spostaTrattativaCrm($richiesta, $corpo, $parametri[0]),
            'crm.trattative.elenca' => $this->elencaCrm($richiesta, 'trattative', $operazione),
            'crm.trattative.ricerca.crea' => $this->ricercaCrm($richiesta, $corpo, 'trattative', $operazione),
            'crm.trattative.riepilogo.mostra' => $this->riepilogoTrattativeCrm($richiesta),
            'crm.trattative.cronologia.elenca' => $this->cronologiaCrm($richiesta, 'trattative', $parametri[0], $operazione),
            'crm.attivita.crea' => $this->creaAttivitaCrm($richiesta, $corpo),
            'crm.attivita.mostra' => $this->mostraCrm($richiesta, 'attivita', $parametri[0]),
            'crm.attivita.modifica' => $this->modificaAttivitaCrm($richiesta, $corpo, $parametri[0]),
            'crm.attivita.elimina' => $this->eliminaAttivitaCrm($richiesta, $parametri[0]),
            'crm.attivita.elenca' => $this->elencaCrm($richiesta, 'attivita', $operazione),
            'crm.note.crea' => $this->creaNotaCrm($richiesta, $corpo),
            'crm.note.modifica' => $this->modificaNotaCrm($richiesta, $corpo, $parametri[0]),
            'crm.note.elimina' => $this->eliminaNotaCrm($richiesta, $parametri[0]),
            'crm.note.elenca' => $this->elencaNoteCrm($richiesta),
            'crm.impostazioni.mostra' => [200, ['data' => ['valuta' => $this->valutaCrm($this->conCrm($richiesta)['workspace'])]]],
            'crm.impostazioni.modifica' => $this->modificaImpostazioniCrm($richiesta, $corpo, $amministra),
            'crm.utilizzo.mostra' => $this->utilizzoCrm($richiesta),
            default => throw new LogicException("Il finto non fa {$operazione}."),
        };
    }

    /**
     * Il workspace del gettone con l'app `crm` (i middleware `workspace` e `app:crm` del backoffice): al gettone dell'accesso 403
     * gettone_senza_workspace, a un ruolo che la rotta non ammette 403 permesso_negato, con l'app spenta 403 app_non_attiva.
     *
     * @param  list<string>  $ruoli
     * @return array{persona: string, accesso: string, workspace: string}
     */
    private function conCrm(Request $richiesta, array $ruoli = []): array
    {
        $chi = $this->conWorkspace($richiesta, $ruoli);

        if (! $this->appAttiva($chi['workspace'], 'crm')) {
            throw new Problema('app_non_attiva');
        }

        return $chi;
    }

    /** RegoleDichiarate::rifiuta del backoffice: la regola del campo, e il testo col nome del campo in parole. */
    private function rifiutaCrm(Closure $fail, string $attributo, string $regola, string $chiave, array $parametri = []): void
    {
        $this->testi->dichiara($attributo, $regola);

        if (isset($parametri['attribute']) && is_string($parametri['attribute'])) {
            $parametri['attribute'] = $this->testi->nome($parametri['attribute']);
        }

        $fail($chiave)->translate($parametri);
    }

    /** Un 422 dati_non_validi con un errore solo, scritto a mano (ErroreDiCampo::pointer del backoffice). */
    private function erroreCrm(string $campo, string $chiave, string $regola, array $parametri = []): Problema
    {
        return new Problema('dati_non_validi', [['detail' => $this->testi->testo($chiave, $parametri), 'regola' => $regola, 'pointer' => '#/'.str_replace('.', '/', $campo)]]);
    }

    /**
     * Il corpo di una modifica (Corpo::modifica): come validaCorpo, e almeno un campo, o 422 sul corpo intero.
     *
     * @param  array<mixed>  $corpo
     * @param  array<string, mixed>  $regole
     * @return array<string, mixed>
     */
    private function modificaCrm(array $corpo, array $regole): array
    {
        $campi = $this->testi->validaCorpo($corpo, $regole);

        if ($campi === []) {
            $ammessi = array_values(array_unique(array_map(fn (string $chiave) => explode('.', $chiave, 2)[0], array_keys($regole))));

            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.almeno_un_campo', ['campi' => implode(', ', $ammessi)]), 'regola' => 'obbligatorio', 'pointer' => '#']]);
        }

        return $campi;
    }

    /**
     * Una riga del CRM del workspace: una di un altro workspace, eliminata o che non c'è è 404 non_trovato.
     *
     * @return array<string, mixed>
     */
    private function rigaCrm(string $tipo, string $workspace, string $id): array
    {
        $riga = $this->crm[$tipo][strtolower($id)] ?? null;

        return $riga !== null && $riga['workspace'] === $workspace && ! ($riga['eliminata'] ?? false) ? $riga : throw new Problema('non_trovato');
    }

    /**
     * Le righe vive di un tipo nel workspace.
     *
     * @return list<array<string, mixed>>
     */
    private function righeCrm(string $tipo, string $workspace): array
    {
        return array_values(array_filter($this->crm[$tipo], fn (array $riga) => $riga['workspace'] === $workspace && ! ($riga['eliminata'] ?? false)));
    }

    private function esisteCrm(string $tipo, string $workspace, mixed $id): bool
    {
        $riga = $this->crm[$tipo][strtolower((string) $id)] ?? null;

        return $riga !== null && $riga['workspace'] === $workspace && ! ($riga['eliminata'] ?? false);
    }

    private static function adessoCrm(): CarbonImmutable
    {
        return now()->toImmutable()->startOfMillisecond();
    }

    /** L'istante di una scrittura (NelRegistro::istanteDopo, AP6): adesso, e almeno un millisecondo dopo quello che la riga aveva. */
    private static function dopoCrm(?CarbonInterface $prima): CarbonImmutable
    {
        $ora = self::adessoCrm();

        return $prima !== null && $ora->lessThanOrEqualTo($prima) ? CarbonImmutable::instance($prima)->addMillisecond() : $ora;
    }

    private static function isoCrm(?CarbonInterface $istante): ?string
    {
        return $istante === null ? null : self::iso($istante);
    }

    /** La valuta del CRM del workspace: l'euro finché non è mai stata cambiata. */
    private function valutaCrm(string $workspace): string
    {
        return $this->crm['valute'][$workspace] ?? self::CRM_VALUTA;
    }

    /**
     * Scrive dei campi su una riga, e se qualcuno cambia porta avanti il suo istante di aggiornamento (il salvataggio di Eloquent: una
     * riga che non cambia non si scrive). Dice se è cambiata.
     *
     * @param  array<string, mixed>  $campi
     */
    private function salvaCrm(string $tipo, string $id, array $campi): bool
    {
        $riga = $this->crm[$tipo][$id];
        $cambiata = false;

        foreach ($campi as $campo => $valore) {
            if (! self::ugualeCrm($riga[$campo] ?? null, $valore)) {
                $riga[$campo] = $valore;
                $cambiata = true;
            }
        }

        if ($cambiata) {
            $colonna = in_array($tipo, ['persone', 'motivi'], true) ? 'aggiornato_il' : 'aggiornata_il';
            $riga[$colonna] = self::dopoCrm($riga[$colonna]);
            $this->crm[$tipo][$id] = $riga;
        }

        return $cambiata;
    }

    private static function ugualeCrm(mixed $uno, mixed $altro): bool
    {
        if ($uno instanceof CarbonInterface || $altro instanceof CarbonInterface) {
            return $uno instanceof CarbonInterface && $altro instanceof CarbonInterface && $uno->equalTo($altro);
        }

        return $uno === $altro;
    }

    /** Una riga della cronologia di un contatto, un'azienda o una trattativa, con l'autore della scrittura. */
    private function cambioCrm(string $tipo, string $id, string $autore, string $cambio, array $campi, ?string $importazione = null, ?string $attivita = null): void
    {
        $this->crm['cambi'][] = [
            'id' => self::id(), 'tipo_risorsa' => $tipo, 'risorsa' => $id, 'tipo' => $cambio, 'autore_id' => $autore, 'istante' => self::adessoCrm(),
            'campi' => $campi, 'importazione_id' => $importazione, 'attivita_id' => $attivita,
        ];
    }

    /**
     * Le regole di `responsabile_id` (Persone::responsabile): un membro del workspace del gettone; un id che non c'è e uno di un altro
     * workspace hanno lo stesso 422.
     *
     * @return list<mixed>
     */
    private function regolaDelResponsabile(string $workspace): array
    {
        return ['bail', 'filled', 'string', function (string $campo, mixed $id, Closure $fail) use ($workspace) {
            if (! isset($this->membri[$workspace][strtolower((string) $id)])) {
                $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, 'regole.responsabile_del_workspace');
            }
        }];
    }

    /** Una regola «c'è nel workspace» con il testo dato (`regole.contatto_non_esiste`, …): G80. */
    private function regolaCheEsiste(string $workspace, string $tipo, string $chiave, bool $conNome = false): Closure
    {
        return function (string $campo, mixed $id, Closure $fail) use ($workspace, $tipo, $chiave, $conNome) {
            if (! $this->esisteCrm($tipo, $workspace, $id)) {
                $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, $chiave, $conNome ? ['attribute' => $campo] : []);
            }
        };
    }

    /**
     * Una pagina di un elenco del CRM: `limite` da 1 a 100 (50 se manca) e `cursore`, il `successivo` della pagina prima, che porta
     * l'id dell'ultima voce. Le voci vanno nell'ordine di `$ordine`, e la pagina parte dalla voce dopo quella del cursore.
     *
     * @param  list<array<string, mixed>>  $righe
     * @param  Closure(array<string, mixed>, array<string, mixed>): int  $ordine
     * @param  Closure(array<string, mixed>): array<string, mixed>  $voce
     * @return array{int, array<string, mixed>}
     */
    private function paginaCrm(Request $richiesta, string $lista, array $righe, Closure $ordine, Closure $voce): array
    {
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO],
            'cursore' => ['sometimes', 'string', function (string $attributo, mixed $valore, Closure $fail) use ($lista) {
                if ($this->leggiCursore($lista, 'id', $valore) === null) {
                    $this->testi->dichiara($attributo, 'formato');
                    $fail('regole.cursore')->translate();
                }
            }],
        ]);

        usort($righe, $ordine);
        $limite = (int) ($query['limite'] ?? self::LIMITE_PREDEFINITO);

        if (isset($query['cursore'])) {
            $dopo = (string) $this->leggiCursore($lista, 'id', $query['cursore']);
            $posto = array_search($dopo, array_column($righe, 'id'), true);
            $righe = $posto === false ? [] : array_slice($righe, $posto + 1);
        }

        $pagina = array_slice($righe, 0, $limite);

        return [200, [
            'data' => array_map($voce, $pagina),
            'successivo' => count($righe) > $limite ? $this->firmaCursore($lista, ['id' => $pagina[$limite - 1]['id']]) : null,
        ]];
    }

    /**
     * L'ordine «prima chi ha il valore, poi chi non ce l'ha; a parità l'id crescente» (Organizzazioni::dopo e l'orderBy degli elenchi).
     * I testi si confrontano senza maiuscole né accenti, come la collation del database.
     */
    private static function ordineCrm(string $campo, bool $discendente = false): Closure
    {
        return function (array $una, array $altra) use ($campo, $discendente): int {
            $a = self::chiaveCrm($una[$campo] ?? null);
            $b = self::chiaveCrm($altra[$campo] ?? null);

            if (($a === null) !== ($b === null)) {
                return $a === null ? 1 : -1;
            }

            $ordine = $a === null ? 0 : ($discendente ? -1 : 1) * self::confrontaCrm($a, $b);

            return $ordine !== 0 ? $ordine : strcmp($una['id'], $altra['id']);
        };
    }

    /** Due chiavi d'ordine: i testi byte per byte (mai come numeri), gli interi come numeri. */
    private static function confrontaCrm(string|int $una, string|int $altra): int
    {
        return is_string($una) && is_string($altra) ? strcmp($una, $altra) <=> 0 : $una <=> $altra;
    }

    private static function chiaveCrm(mixed $valore): string|int|null
    {
        return match (true) {
            $valore === null => null,
            $valore instanceof CarbonInterface => self::iso($valore),
            is_int($valore) => $valore,
            default => Str::lower(Str::ascii((string) $valore)),
        };
    }

    /**
     * crm.persone.esportazione.mostra e crm.organizzazioni.esportazione.mostra (Esportazioni, #1905): il CSV di ciò che l'elenco mostra
     * coi suoi filtri, a proprietario e amministratore (403 prima di tutto il resto), 10 all'ora per persona e 20 per workspace. I filtri
     * e le loro regole sono quelli di `elenca`; `limite`, `cursore`, `totale` e ogni parametro ignoto sono 422 `campo_estraneo`, i testi
     * liberi 422 `campo_vietato`. Il corpo è una stringa: UTF-8 con BOM, virgola, CRLF, l'intestazione, e un apostrofo davanti a una
     * cella che comincia come una formula. Il finto non scrive a blocchi: tiene il file in memoria.
     *
     * @return array{int, string, array<string, string>}
     */
    private function esportaCrm(Request $richiesta, string $tipo, string $operazione): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $query = self::query($richiesta);
        $testo = fn (string $parametro) => isset($query[$parametro]) && is_string($query[$parametro]) && $query[$parametro] !== '' ? $query[$parametro] : null;
        $vero = fn (string $parametro) => ($query[$parametro] ?? null) === 'true';
        $siONo = ['sometimes', 'string', Rule::in(['true', 'false'])];
        $regole = [
            'ordina' => ['sometimes', 'string', Rule::in(self::ordiniAmmessiCrm($tipo))],
            'id' => $this->regolaDelFiltroPerId(),
            'stato' => $this->regolaDelleVociCrm('stato', self::CRM_STATI),
            'responsabile_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            ...($tipo === 'persone' ? ['organizzazione_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'archiviati' => $siONo] : ['archiviate' => $siONo]),
            ...array_fill_keys(self::CRM_VIETATI[$tipo], ['prohibited']),
        ];
        $estranei = array_diff(array_map('strval', array_keys($query)), array_keys($regole));
        $this->testi->validaQuery($query, $regole + array_fill_keys($estranei, [function (string $campo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $campo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $campo]);
        }]));

        $filtri = [
            'stati' => self::vociCrm($testo('stato')), 'responsabile' => self::idCrm($testo('responsabile_id')),
            'archiviate' => $vero($tipo === 'persone' ? 'archiviati' : 'archiviate'), 'ricerca' => null, 'id' => self::idDelFiltroCrm($testo('id')),
            ...($tipo === 'persone' ? ['email' => null, 'organizzazione' => self::idCrm($testo('organizzazione_id'))] : []),
        ];

        $this->frena('freni:crm.esportazioni:persona:'.$chi['persona'], self::CRM_ESPORTAZIONI_PERSONA_ALL_ORA, 3600);
        $this->frena('freni:crm.esportazioni:workspace:'.$chi['workspace'], self::CRM_ESPORTAZIONI_WORKSPACE_ALL_ORA, 3600);

        $ordina = $testo('ordina') ?? (string) array_key_first(self::CRM_ORDINI[$tipo]);
        $campo = self::CRM_ORDINI[$tipo][ltrim($ordina, '-')];
        $righe = array_values(array_filter($this->righeCrm($tipo, $chi['workspace']), fn (array $riga) => $this->tieneCrm($tipo, $chi['workspace'], $filtri, $riga)));
        usort($righe, self::ordineCrm($campo, str_starts_with($ordina, '-')));
        $nome = fn (?string $id) => $id === null ? null : ($this->persone[$id]['nome'] ?? null);
        $celle = [];

        foreach (array_slice($righe, 0, $tipo === 'persone' ? 25000 : 10000) as $riga) {
            if ($tipo === 'persone') {
                $voce = $this->vocePersona($riga);
                $celle[] = [
                    $voce['id'], $voce['nome'], $voce['cognome'], $voce['ruolo'], $voce['stato'],
                    implode('; ', array_column($voce['email'], 'indirizzo')), implode('; ', array_column($voce['telefoni'], 'numero')),
                    $voce['organizzazione_id'], $voce['organizzazione']['nome'] ?? null, $voce['responsabile_id'], $nome($voce['responsabile_id']), $voce['creato_il'],
                ];
            } else {
                $voce = $this->voceOrganizzazione($riga);
                $indirizzo = $voce['indirizzo'] ?? [];
                $celle[] = [
                    $voce['id'], $voce['nome'], $voce['sito'], $voce['telefono'], $indirizzo['via'] ?? null, $indirizzo['cap'] ?? null, $indirizzo['citta'] ?? null,
                    $indirizzo['provincia'] ?? null, $indirizzo['paese'] ?? null, $voce['stato'], $voce['responsabile_id'], $nome($voce['responsabile_id']),
                    $voce['numero_contatti'], $voce['creata_il'],
                ];
            }
        }

        $intestazione = $tipo === 'persone'
            ? ['id', 'nome', 'cognome', 'ruolo', 'stato', 'email', 'telefoni', 'organizzazione_id', 'organizzazione', 'responsabile_id', 'responsabile', 'creato_il']
            : ['id', 'nome', 'sito', 'telefono', 'via', 'cap', 'citta', 'provincia', 'paese', 'stato', 'responsabile_id', 'responsabile', 'numero_contatti', 'creata_il'];
        $file = "\xEF\xBB\xBF".implode('', array_map(self::rigaCsvCrm(...), [$intestazione, ...$celle]));

        return [200, $file, [
            'Content-Type' => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="'.($tipo === 'persone' ? 'contatti' : 'aziende').'-'.gmdate('Y-m-d').'.csv"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]];
    }

    /** @param  array<int, string|int|null>  $celle */
    private static function rigaCsvCrm(array $celle): string
    {
        return implode(',', array_map(function (string|int|null $valore): string {
            $testo = (string) $valore;
            $testo = $testo !== '' && str_contains("=+-@\t\r\n", $testo[0]) ? "'".$testo : $testo;

            return preg_match('/[",;\r\n]/', $testo) === 1 ? '"'.str_replace('"', '""', $testo).'"' : $testo;
        }, $celle))."\r\n";
    }

    /**
     * crm.*.elenca di aziende, contatti, trattative e attività (le *ElencaRequest): i filtri, l'ordine e la pagina dalla query, senza
     * nessun testo libero (un parametro di testo è 422 `campo_vietato`, G58), e la pagina di `paginaFiltrataCrm`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCrm(Request $richiesta, string $tipo, string $lista): array
    {
        $chi = $this->conCrm($richiesta);
        $query = self::query($richiesta);
        $testo = fn (string $parametro) => isset($query[$parametro]) && is_string($query[$parametro]) && $query[$parametro] !== '' ? $query[$parametro] : null;
        $vero = fn (string $parametro) => ($query[$parametro] ?? null) === 'true' ? true : (($query[$parametro] ?? null) === 'false' ? false : null);
        $filtri = match ($tipo) {
            'persone' => [
                'stati' => self::vociCrm($testo('stato')), 'responsabile' => self::idCrm($testo('responsabile_id')), 'archiviate' => $vero('archiviati') === true,
                'ricerca' => null, 'email' => null, 'organizzazione' => self::idCrm($testo('organizzazione_id')), 'id' => self::idDelFiltroCrm($testo('id')),
            ],
            'organizzazioni' => [
                'stati' => self::vociCrm($testo('stato')), 'responsabile' => self::idCrm($testo('responsabile_id')), 'archiviate' => $vero('archiviate') === true,
                'ricerca' => null, 'id' => self::idDelFiltroCrm($testo('id')),
            ],
            'trattative' => [...self::filtriDelleTrattativeCrm($query), 'archiviate' => $vero('archiviate') === true],
            default => [
                'responsabile' => self::idCrm($testo('responsabile_id')), 'persona' => self::idCrm($testo('persona_id')),
                'organizzazione' => self::idCrm($testo('organizzazione_id')), 'trattativa' => self::idCrm($testo('trattativa_id')), 'fatta' => $vero('fatta'),
                'scadenza_dal' => $testo('scadenza_dal'), 'scadenza_al' => $testo('scadenza_al'), 'senza_scadenza' => $vero('senza_scadenza'),
                'svolta_dal' => $testo('svolta_dal'), 'svolta_fino' => $testo('svolta_fino'), 'tipi' => self::vociCrm($testo('tipo')),
            ],
        };
        $chiesta = [
            'ordina' => $testo('ordina') ?? (string) array_key_first(self::CRM_ORDINI[$tipo]),
            'limite' => is_numeric($query['limite'] ?? null) ? (int) $query['limite'] : self::LIMITE_PREDEFINITO,
            'totale' => $vero('totale') === true,
            'cursore' => $testo('cursore'),
            'filtri' => $filtri,
        ];
        $voci = fn (string $parametro, array $ammesse) => $this->regolaDelleVociCrm($parametro, $ammesse);
        $siONo = ['sometimes', 'string', Rule::in(['true', 'false'])];
        $regole = [
            'limite' => ['sometimes', 'integer', 'between:1,'.self::LIMITE_MASSIMO],
            'cursore' => ['bail', 'sometimes', 'string', function (string $campo, mixed $valore, Closure $fail) use ($lista, $chiesta) {
                if ($this->posizioneCrm($lista, [...$chiesta, 'cursore' => (string) $valore]) === null) {
                    $this->rifiutaCrm($fail, $campo, Regola::FORMATO, 'regole.cursore');
                }
            }],
            'ordina' => ['sometimes', 'string', Rule::in(self::ordiniAmmessiCrm($tipo))],
            ...match ($tipo) {
                'persone' => [
                    'id' => $this->regolaDelFiltroPerId(), 'stato' => $voci('stato', self::CRM_STATI),
                    'responsabile_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'organizzazione_id' => ['sometimes', 'nullable', 'string', 'max:64'],
                    'archiviati' => $siONo,
                ],
                'organizzazioni' => [
                    'id' => $this->regolaDelFiltroPerId(), 'stato' => $voci('stato', self::CRM_STATI),
                    'responsabile_id' => ['sometimes', 'nullable', 'string', 'max:64'], 'archiviate' => $siONo,
                ],
                'trattative' => [...$this->regoleDeiFiltriDelleTrattativeCrm($chi['workspace']), 'archiviate' => $siONo],
                default => [
                    'tipo' => $voci('tipo', self::CRM_TIPI_ATTIVITA),
                    ...array_map(fn (array $regole) => ['sometimes', ...$regole], $this->regoleDegliIdDelleAttivita($chi['workspace'])),
                    'fatta' => $siONo, 'scadenza_dal' => ['sometimes', 'string', 'date_format:Y-m-d'], 'scadenza_al' => ['sometimes', 'string', 'date_format:Y-m-d'],
                    'senza_scadenza' => [...$siONo, 'prohibits:scadenza_dal,scadenza_al'],
                    'svolta_dal' => ['sometimes', 'string', 'date_format:Y-m-d'], 'svolta_fino' => ['sometimes', 'string', 'date_format:Y-m-d'],
                ],
            },
            'totale' => $siONo,
            ...array_fill_keys(self::CRM_VIETATI[$tipo], ['prohibited']),
        ];
        $this->testi->validaQuery($query, $regole);

        return $this->paginaFiltrataCrm($chi['workspace'], $tipo, $lista, $chiesta);
    }

    /**
     * I filtri delle trattative dalla query (TrattativeElencaRequest::filtri), senza `archiviate`: li leggono `elenca` e il riepilogo.
     *
     * @param  array<mixed>  $query
     * @return array<string, mixed>
     */
    private static function filtriDelleTrattativeCrm(array $query): array
    {
        $testo = fn (string $parametro) => isset($query[$parametro]) && is_string($query[$parametro]) && $query[$parametro] !== '' ? $query[$parametro] : null;

        return [
            'pipeline' => self::idCrm($testo('pipeline_id')), 'fase' => self::idCrm($testo('fase_id')), 'stati' => self::vociCrm($testo('stato')),
            'responsabile' => self::idCrm($testo('responsabile_id')), 'persona' => self::idCrm($testo('persona_id')),
            'organizzazione' => self::idCrm($testo('organizzazione_id')), 'chiusa_dal' => $testo('chiusa_dal'),
            'ricerca' => null, 'id' => self::idDelFiltroCrm($testo('id')),
        ];
    }

    /**
     * Le regole dei filtri delle trattative (TrattativeElencaRequest::regoleDeiFiltri), senza `archiviate`: `id`, `stato`, gli id,
     * `chiusa_dal` e i testi liberi vietati. Le stesse per `elenca` e per il riepilogo.
     *
     * @return array<string, mixed>
     */
    private function regoleDeiFiltriDelleTrattativeCrm(string $workspace): array
    {
        return [
            'id' => $this->regolaDelFiltroPerId(), 'stato' => $this->regolaDelleVociCrm('stato', self::CRM_STATI_TRATTATIVA),
            ...array_map(fn (array $regole) => ['sometimes', ...$regole], $this->regoleDegliIdDelleTrattative($workspace)),
            'chiusa_dal' => ['sometimes', 'string', 'date_format:Y-m-d'],
            ...array_fill_keys(self::CRM_VIETATI['trattative'], ['prohibited']),
        ];
    }

    /** Un parametro con una o più voci separate da virgola, tutte fra le ammesse e senza ripetizioni. @return list<mixed> */
    private function regolaDelleVociCrm(string $parametro, array $ammesse): array
    {
        return ['bail', 'sometimes', 'nullable', 'string', function (string $campo, mixed $valore, Closure $fail) use ($parametro, $ammesse) {
            $voci = array_values(array_filter(array_map('trim', explode(',', (string) $valore)), fn (string $voce) => $voce !== ''));

            if (array_diff($voci, $ammesse) !== []) {
                $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'validation.in', ['attribute' => $parametro]);
            } elseif (count($voci) !== count(array_unique($voci))) {
                $this->rifiutaCrm($fail, $campo, Regola::DUPLICATO, 'validation.distinct', ['attribute' => $parametro]);
            }
        }];
    }

    /**
     * crm.trattative.riepilogo.mostra (Trattative::riepilogo, #1869): le aperte non archiviate dei filtri di `elenca`, in totale e per
     * fase. Il ponderato di una fase è la somma di valore × probabilità (della trattativa, o della fase) diviso 100, arrotondato una
     * volta con la metà in su; il totale è la somma delle fasi. Un parametro che non è un filtro è 422 `campo_estraneo`.
     *
     * @return array{int, array<string, mixed>}
     */
    private function riepilogoTrattativeCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = self::query($richiesta);
        $regole = $this->regoleDeiFiltriDelleTrattativeCrm($chi['workspace']);
        $estranei = array_diff(array_map('strval', array_keys($query)), array_keys($regole));
        $this->testi->validaQuery($query, $regole + array_fill_keys($estranei, [function (string $campo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $campo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $campo]);
        }]));

        $filtri = [...self::filtriDelleTrattativeCrm($query), 'archiviate' => false];
        $somme = [];

        foreach ($this->righeCrm('trattative', $chi['workspace']) as $riga) {
            if ($riga['stato'] !== 'aperta' || ! $this->tieneCrm('trattative', $chi['workspace'], $filtri, $riga)) {
                continue;
            }

            $fase = $this->crm['fasi'][$riga['fase_id']];
            $voce = $somme[$riga['fase_id']] ?? ['fase' => $fase, 'aperte' => 0, 'valore_aperto' => 0, 'per_cento' => 0, 'senza_valore' => 0];
            $voce['aperte']++;

            if ($riga['importo'] === null) {
                $voce['senza_valore']++;
            } else {
                $voce['valore_aperto'] += $riga['importo'];
                $voce['per_cento'] += $riga['importo'] * ($riga['probabilita'] ?? $fase['probabilita']);
            }

            $somme[$riga['fase_id']] = $voce;
        }

        usort($somme, fn (array $una, array $altra) => [$una['fase']['pipeline_id'], $una['fase']['posizione'], $una['fase']['id']]
            <=> [$altra['fase']['pipeline_id'], $altra['fase']['posizione'], $altra['fase']['id']]);
        $perFase = array_map(fn (array $voce) => [
            'fase_id' => $voce['fase']['id'],
            'aperte' => $voce['aperte'],
            'valore_aperto' => $voce['valore_aperto'],
            'ponderato' => intdiv($voce['per_cento'] + 50, 100),
            'senza_valore' => $voce['senza_valore'],
        ], $somme);

        return [200, ['data' => [
            'valuta' => $this->valutaCrm($chi['workspace']),
            'aperte' => array_sum(array_column($perFase, 'aperte')),
            'valore_aperto' => array_sum(array_column($perFase, 'valore_aperto')),
            'ponderato' => array_sum(array_column($perFase, 'ponderato')),
            'senza_valore' => array_sum(array_column($perFase, 'senza_valore')),
            'per_fase' => $perFase,
        ]]];
    }

    /**
     * crm.*.ricerca.crea di contatti, aziende e trattative (Persone::corpoDellaRicerca e le sorelle): il termine nel corpo di un POST e
     * non nell'URL (G58), con i filtri, l'ordine e la pagina di `elenca`, e la sua forma. Un cursore di un'altra lista, di un altro ordine
     * o di altri filtri è 422 sul pointer `#/cursore`. Risponde 200: non nasce niente.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function ricercaCrm(Request $richiesta, array $corpo, string $tipo, string $lista): array
    {
        $chi = $this->conCrm($richiesta);
        $stati = $tipo === 'trattative' ? self::CRM_STATI_TRATTATIVA : self::CRM_STATI;
        $grezzi = Testi::pulisci($corpo);
        $regole = [
            'q' => ['nullable', 'string', 'max:100'],
            ...($tipo === 'persone' ? ['email' => ['nullable', 'string', 'max:254']] : []),
            'stato' => ['nullable', 'array', 'list', 'max:'.count($stati)],
            'stato.*' => ['bail', 'string', Rule::in($stati), function (string $campo, mixed $stato, Closure $fail) use ($grezzi) {
                $prima = array_slice(array_values((array) ($grezzi['stato'] ?? [])), 0, (int) substr($campo, strlen('stato.')));

                if (in_array($stato, $prima, true)) {
                    $this->rifiutaCrm($fail, $campo, Regola::DUPLICATO, 'validation.distinct', ['attribute' => 'stato']);
                }
            }],
            ...match ($tipo) {
                'persone' => ['responsabile_id' => ['nullable', 'string', 'max:64'], 'organizzazione_id' => ['nullable', 'string', 'max:64'], 'archiviati' => ['nullable', 'boolean:strict']],
                'organizzazioni' => ['responsabile_id' => ['nullable', 'string', 'max:64'], 'archiviate' => ['nullable', 'boolean:strict']],
                default => [...$this->regoleDegliIdDelleTrattative($chi['workspace']), 'chiusa_dal' => ['nullable', 'string', 'date_format:Y-m-d'], 'archiviate' => ['nullable', 'boolean:strict']],
            },
            'ordina' => ['nullable', 'string', Rule::in(self::ordiniAmmessiCrm($tipo))],
            'limite' => ['nullable', 'integer:strict', 'between:1,'.self::LIMITE_MASSIMO],
            'cursore' => ['nullable', 'string'],
            'totale' => ['nullable', 'boolean:strict'],
        ];
        $campi = $this->testi->validaCorpo($corpo, $regole);
        $testo = fn (string $campo) => isset($campi[$campo]) && $campi[$campo] !== '' ? (string) $campi[$campo] : null;
        $ricerca = $testo('q') === null ? null : mb_strtolower((string) $testo('q'));
        $filtri = match ($tipo) {
            'persone' => [
                'stati' => self::vociCrm($campi['stato'] ?? []), 'responsabile' => self::idCrm($testo('responsabile_id')), 'archiviate' => $campi['archiviati'] ?? false,
                'ricerca' => $ricerca, 'email' => $testo('email') === null ? null : mb_strtolower((string) $testo('email')),
                'organizzazione' => self::idCrm($testo('organizzazione_id')), 'id' => [],
            ],
            'organizzazioni' => [
                'stati' => self::vociCrm($campi['stato'] ?? []), 'responsabile' => self::idCrm($testo('responsabile_id')), 'archiviate' => $campi['archiviate'] ?? false,
                'ricerca' => $ricerca, 'id' => [],
            ],
            default => [
                'pipeline' => self::idCrm($testo('pipeline_id')), 'fase' => self::idCrm($testo('fase_id')), 'stati' => self::vociCrm($campi['stato'] ?? []),
                'responsabile' => self::idCrm($testo('responsabile_id')), 'persona' => self::idCrm($testo('persona_id')),
                'organizzazione' => self::idCrm($testo('organizzazione_id')), 'chiusa_dal' => $testo('chiusa_dal'), 'archiviate' => $campi['archiviate'] ?? false,
                'ricerca' => $ricerca, 'id' => [],
            ],
        };
        $chiesta = [
            'ordina' => $campi['ordina'] ?? (string) array_key_first(self::CRM_ORDINI[$tipo]),
            'limite' => $campi['limite'] ?? self::LIMITE_PREDEFINITO,
            'totale' => $campi['totale'] ?? false,
            'cursore' => $campi['cursore'] ?? null,
            'filtri' => $filtri,
        ];

        if ($chiesta['cursore'] !== null && $this->posizioneCrm($lista, $chiesta) === null) {
            throw $this->erroreCrm('cursore', 'regole.cursore', Regola::FORMATO, ['attribute' => 'cursore']);
        }

        return $this->paginaFiltrataCrm($chi['workspace'], $tipo, $lista, $chiesta);
    }

    /**
     * La pagina di un elenco o di una ricerca (la `pagina` dei controller): le righe che rispondono ai filtri, nell'ordine «prima chi ha
     * il valore, poi chi non ce l'ha; a parità l'id crescente» nel verso di `ordina`, dopo la posizione del cursore, e `totale` se chiesto.
     *
     * @param  array{ordina: string, limite: int, totale: bool, cursore: ?string, filtri: array<string, mixed>}  $chiesta
     * @return array{int, array<string, mixed>}
     */
    private function paginaFiltrataCrm(string $workspace, string $tipo, string $lista, array $chiesta): array
    {
        $righe = array_values(array_filter($this->righeCrm($tipo, $workspace), fn (array $riga) => $this->tieneCrm($tipo, $workspace, $chiesta['filtri'], $riga)));
        $campo = self::CRM_ORDINI[$tipo][ltrim($chiesta['ordina'], '-')];
        $discendente = str_starts_with($chiesta['ordina'], '-');
        $posizione = $chiesta['cursore'] === null ? null : $this->posizioneCrm($lista, $chiesta);
        usort($righe, self::ordineCrm($campo, $discendente));

        if ($posizione !== null) {
            [$nullo, $valore, $id] = $posizione;
            $righe = array_values(array_filter($righe, function (array $riga) use ($campo, $discendente, $nullo, $valore, $id) {
                $chiave = self::chiaveCrm($riga[$campo] ?? null);

                if ($nullo) {
                    return $chiave === null && strcmp($riga['id'], $id) > 0;
                }

                if ($chiave === null) {
                    return true;
                }

                $confronto = self::confrontaCrm($chiave, $valore ?? '');

                return ($discendente ? $confronto < 0 : $confronto > 0) || ($confronto === 0 && strcmp($riga['id'], $id) > 0);
            }));
        }

        $limite = $chiesta['limite'];
        $pagina = array_slice($righe, 0, $limite);
        $ultima = $pagina === [] ? null : $pagina[count($pagina) - 1];
        $voce = match ($tipo) {
            'organizzazioni' => fn (array $riga) => $this->voceOrganizzazione($riga),
            'persone' => fn (array $riga) => $this->vocePersona($riga),
            'trattative' => fn (array $riga) => $this->voceTrattativa($riga),
            default => fn (array $riga) => $this->voceAttivita($riga),
        };
        $corpo = [
            'data' => array_map($voce, $pagina),
            'successivo' => count($righe) > $limite && $ultima !== null ? $this->firmaCursore(self::listaDelCursoreCrm($lista, $chiesta), [
                'posizione' => json_encode([self::chiaveCrm($ultima[$campo] ?? null) === null, self::chiaveCrm($ultima[$campo] ?? null), $ultima['id']], JSON_THROW_ON_ERROR),
            ]) : null,
        ];

        if ($chiesta['totale']) {
            $conteggio = count(array_filter($this->righeCrm($tipo, $workspace), fn (array $riga) => $this->tieneCrm($tipo, $workspace, $chiesta['filtri'], $riga)));
            $corpo['totale'] = min($conteggio, self::CRM_TETTO_DEL_TOTALE);

            if ($conteggio > self::CRM_TETTO_DEL_TOTALE) {
                $corpo['totale_almeno'] = true;
            }
        }

        return [200, $corpo];
    }

    /**
     * La riga risponde ai filtri della sua lista (Persone::filtrata, Organizzazioni::filtrata, Trattative::filtrata,
     * AttivitaController::filtrata). La ricerca: ogni parola del termine, senza maiuscole, sta in uno dei campi cercati.
     *
     * @param  array<string, mixed>  $filtri
     * @param  array<string, mixed>  $riga
     */
    private function tieneCrm(string $tipo, string $workspace, array $filtri, array $riga): bool
    {
        $uguale = fn (string $filtro, string $campo) => ($filtri[$filtro] ?? null) === null || ($riga[$campo] ?? null) === $filtri[$filtro];
        $parole = preg_split('/\s+/u', trim((string) ($filtri['ricerca'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $contiene = fn (mixed $testo, string $parola) => is_string($testo) && str_contains(mb_strtolower($testo), $parola);
        $comuni = ($filtri['id'] ?? []) === [] || in_array($riga['id'], $filtri['id'], true);

        if ($tipo !== 'attivita') {
            $comuni = $comuni && ($riga['archiviata_il'] !== null) === $filtri['archiviate']
                && ($filtri['stati'] === [] || in_array($riga['stato'], $filtri['stati'], true)) && $uguale('responsabile', 'responsabile_id');
        }

        return $comuni && match ($tipo) {
            'persone' => $uguale('organizzazione', 'organizzazione_id')
                && array_filter($parole, fn (string $parola) => ! $contiene($riga['nome'], $parola) && ! $contiene($riga['cognome'], $parola)
                    && ! $contiene($riga['organizzazione_id'] === null ? null : ($this->crm['organizzazioni'][$riga['organizzazione_id']]['nome'] ?? null), $parola)) === []
                && ($filtri['email'] === null || $this->personaConEmailCrm($workspace, $filtri['email']) === $riga['id']),
            'organizzazioni' => array_filter($parole, fn (string $parola) => ! $contiene($riga['nome'], $parola)) === [],
            'trattative' => $uguale('pipeline', 'pipeline_id') && $uguale('fase', 'fase_id') && $uguale('persona', 'persona_id') && $uguale('organizzazione', 'organizzazione_id')
                && ($filtri['chiusa_dal'] === null || ($riga['chiusa_il'] !== null && $riga['chiusa_il']->greaterThanOrEqualTo(CarbonImmutable::parse($filtri['chiusa_dal'].' 00:00:00', 'UTC'))))
                && array_filter($parole, fn (string $parola) => ! $contiene($riga['titolo'], $parola)) === [],
            default => $uguale('responsabile', 'responsabile_id') && $uguale('persona', 'persona_id') && $uguale('organizzazione', 'organizzazione_id')
                && $uguale('trattativa', 'trattativa_id') && ($filtri['fatta'] === null || $riga['fatta'] === $filtri['fatta'])
                && ($filtri['tipi'] === [] || in_array($riga['tipo'], $filtri['tipi'], true))
                && ($filtri['senza_scadenza'] === null || ($riga['scadenza'] === null) === $filtri['senza_scadenza'])
                && ($filtri['scadenza_dal'] === null || ($riga['scadenza'] !== null && $riga['scadenza'] >= $filtri['scadenza_dal']))
                && ($filtri['scadenza_al'] === null || ($riga['scadenza'] !== null && $riga['scadenza'] <= $filtri['scadenza_al']))
                && ($filtri['svolta_dal'] === null || ($riga['svolta_il'] !== null && self::iso($riga['svolta_il']) >= $filtri['svolta_dal'].'T00:00:00.000Z'))
                && ($filtri['svolta_fino'] === null || ($riga['svolta_il'] !== null && self::iso($riga['svolta_il']) <= $filtri['svolta_fino'].'T23:59:59.999Z')),
        };
    }

    /**
     * La posizione di un cursore che questa lista ha dato con questo ordine e questi filtri: se il valore manca, il valore e l'id
     * dell'ultima riga della pagina prima; null se il cursore è inventato, ritoccato, di un'altra lista, di un altro ordine o di altri filtri.
     *
     * @param  array{ordina: string, cursore: ?string, filtri: array<string, mixed>}  $chiesta
     * @return array{0: bool, 1: string|int|null, 2: string}|null
     */
    private function posizioneCrm(string $lista, array $chiesta): ?array
    {
        $testo = $this->leggiCursore(self::listaDelCursoreCrm($lista, $chiesta), 'posizione', $chiesta['cursore']);
        $posizione = $testo === null ? null : json_decode($testo, true);

        return is_array($posizione) && count($posizione) === 3 && is_bool($posizione[0]) && is_string($posizione[2]) ? [$posizione[0], $posizione[1], $posizione[2]] : null;
    }

    /** La lista di un cursore: il metodo, l'ordine e l'impronta dei filtri (FiltriDi*::impronta), così un cursore vale solo per loro. */
    private static function listaDelCursoreCrm(string $lista, array $chiesta): string
    {
        return $lista.':'.$chiesta['ordina'].':'.hash('sha256', json_encode($chiesta['filtri'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<string> i valori di `ordina`: ogni colonna, e col segno meno per il verso opposto */
    private static function ordiniAmmessiCrm(string $tipo): array
    {
        $ordini = array_keys(self::CRM_ORDINI[$tipo]);

        return [...$ordini, ...array_map(fn (string $ordine) => '-'.$ordine, $ordini)];
    }

    /**
     * Le voci di un filtro a più valori (`stato=lead,cliente`, o la lista del corpo): senza vuote, senza ripetizioni, in ordine alfabetico.
     *
     * @param  string|list<string>|null  $valore
     * @return list<string>
     */
    private static function vociCrm(string|array|null $valore): array
    {
        $voci = array_values(array_unique(array_filter(array_map('trim', is_array($valore) ? $valore : explode(',', (string) $valore)), fn (string $voce) => $voce !== '')));
        sort($voci);

        return $voci;
    }

    private static function idCrm(?string $id): ?string
    {
        return $id === null || $id === '' ? null : strtolower($id);
    }

    /** @return list<string> gli id del filtro `id`, in minuscolo, senza ripetizioni, in ordine (FiltroPerId::leggi) */
    private static function idDelFiltroCrm(?string $valore): array
    {
        if ($valore === null) {
            return [];
        }

        $id = array_values(array_unique(array_map('strtolower', explode(',', $valore))));
        sort($id);

        return $id;
    }

    /** @return list<mixed> la regola del filtro `id` (FiltroPerId::regole): al più 100 ULID */
    private function regolaDelFiltroPerId(): array
    {
        return ['bail', 'sometimes', 'string', function (string $campo, mixed $valore, Closure $fail) {
            $voci = explode(',', (string) $valore);

            if (count($voci) > self::CRM_ID_NEL_FILTRO) {
                $this->rifiutaCrm($fail, $campo, Regola::FUORI_INTERVALLO, 'validation.max.array', ['attribute' => $campo, 'max' => self::CRM_ID_NEL_FILTRO]);

                return;
            }

            foreach ($voci as $voce) {
                if (! Str::isUlid($voce)) {
                    $this->rifiutaCrm($fail, $campo, Regola::FORMATO, 'validation.ulid', ['attribute' => $campo]);

                    return;
                }
            }
        }];
    }

    /**
     * Gli id dei filtri delle trattative (Trattative::regoleDegliId): uno che non c'è nel workspace è 422 sul suo nome.
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDegliIdDelleTrattative(string $workspace): array
    {
        return [
            'pipeline_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('pipeline', $workspace, $id), 'validation.exists'),
            'fase_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('fasi', $workspace, $id), 'regole.fase_della_pipeline'),
            'persona_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('persone', $workspace, $id), 'regole.contatto_non_esiste'),
            'organizzazione_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('organizzazioni', $workspace, $id), 'regole.azienda_non_esiste'),
            'responsabile_id' => $this->regolaDiUnIdCrm(fn (string $id) => isset($this->membri[$workspace][$id]), 'regole.responsabile_del_workspace'),
        ];
    }

    /**
     * Gli id dei filtri delle attività (AttivitaComune::regoleDegliId).
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDegliIdDelleAttivita(string $workspace): array
    {
        return [
            'responsabile_id' => $this->regolaDiUnIdCrm(fn (string $id) => isset($this->membri[$workspace][$id]), 'regole.responsabile_del_workspace'),
            'persona_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('persone', $workspace, $id), 'regole.contatto_non_esiste'),
            'organizzazione_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('organizzazioni', $workspace, $id), 'regole.azienda_non_esiste'),
            'trattativa_id' => $this->regolaDiUnIdCrm(fn (string $id) => $this->esisteCrm('trattative', $workspace, $id), 'validation.exists'),
        ];
    }

    /** @return list<mixed> un id che `$trova` deve trovare, in minuscolo, o 422 `non_trovato` col testo dato */
    private function regolaDiUnIdCrm(Closure $trova, string $chiave): array
    {
        return ['bail', 'nullable', 'string', 'max:64', function (string $campo, mixed $id, Closure $fail) use ($trova, $chiave) {
            if (! $trova(strtolower((string) $id))) {
                $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, $chiave, ['attribute' => $campo]);
            }
        }];
    }

    /**
     * La cronologia di un contatto, di un'azienda o di una trattativa, anche archiviati, dalla riga più recente (istante, poi id). A quella
     * di un contatto si mescolano la nascita e l'esito (vinta, persa) delle sue trattative collegate oggi e non archiviate, le 200 più
     * recenti, col solo `trattativa_id` (PersoneCronologia), e `trattative_fuori_finestra` dice quante restano fuori. Il cursore di
     * un'altra risorsa è 422.
     *
     * @return array{int, array<string, mixed>}
     */
    private function cronologiaCrm(Request $richiesta, string $tipo, string $id, string $lista): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm($tipo, $chi['workspace'], $id);
        $righe = array_values(array_filter($this->crm['cambi'], fn (array $cambio) => $cambio['tipo_risorsa'] === $tipo && $cambio['risorsa'] === $riga['id']));
        $collegate = [];

        if ($tipo === 'persone') {
            $collegate = array_keys(array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['persona_id'] === $riga['id'] && $trattativa['archiviata_il'] === null));
            rsort($collegate);
            $recenti = array_slice($collegate, 0, self::CRM_FINESTRA_DELLE_TRATTATIVE);
            $righe = [...$righe, ...array_filter($this->crm['cambi'], fn (array $cambio) => $cambio['tipo_risorsa'] === 'trattative' && in_array($cambio['risorsa'], $recenti, true)
                && ($cambio['tipo'] === 'creata' || ($cambio['tipo'] === 'modificata' && in_array(self::esitoCrm($cambio), ['vinta', 'persa'], true))))];
        }

        $voce = function (array $cambio) use ($tipo): array {
            if ($tipo === 'persone' && $cambio['tipo_risorsa'] === 'trattative') {
                return [
                    'id' => $cambio['id'], 'tipo' => $cambio['tipo'] === 'creata' ? 'trattativa.creata' : 'trattativa.'.self::esitoCrm($cambio),
                    'autore_id' => $cambio['autore_id'], 'istante' => self::iso($cambio['istante']), 'campi' => [], 'importazione_id' => null,
                    'attivita_id' => null, 'trattativa_id' => $cambio['risorsa'],
                ];
            }

            $voce = ['id' => $cambio['id'], 'tipo' => $cambio['tipo'], 'autore_id' => $cambio['autore_id'], 'istante' => self::iso($cambio['istante']), 'campi' => $cambio['campi']];

            return $voce + ($tipo === 'trattative' ? [] : ['importazione_id' => $cambio['importazione_id']]) + ['attivita_id' => $cambio['attivita_id']];
        };
        $esito = $this->paginaCrm($richiesta, "{$lista}:{$riga['id']}", $righe, fn (array $una, array $altra) => [$altra['istante'], $altra['id']] <=> [$una['istante'], $una['id']], $voce);

        if ($tipo === 'persone') {
            $esito[1]['trattative_fuori_finestra'] = max(0, count($collegate) - self::CRM_FINESTRA_DELLE_TRATTATIVE);
        }

        return $esito;
    }

    /** Lo stato a cui passa una trattativa in una riga `modificata` della sua cronologia, o null. */
    private static function esitoCrm(array $cambio): ?string
    {
        foreach ($cambio['campi'] as $campo) {
            if ($campo['campo'] === 'stato') {
                return $campo['dopo'] ?? null;
            }
        }

        return null;
    }

    /**
     * La lettura di una riga (`mostra`): un'azienda, un contatto, una trattativa o un'attività anche archiviati, una pipeline con le fasi.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraCrm(Request $richiesta, string $tipo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm($tipo, $chi['workspace'], $id);

        return [200, ['data' => match ($tipo) {
            'organizzazioni' => $this->voceOrganizzazione($riga),
            'persone' => $this->vocePersona($riga, true),
            'trattative' => $this->voceTrattativa($riga),
            'pipeline' => $this->vocePipeline($riga),
            default => $this->voceAttivita($riga),
        }]];
    }

    // ———————————————————————————————————————— Aziende

    /** @return array<string, mixed> lo schema Organizzazione (Forme::organizzazione) */
    private function voceOrganizzazione(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'nome' => $riga['nome'],
            'sito' => $riga['sito'],
            'telefono' => $riga['telefono'],
            'indirizzo' => $riga['indirizzo'],
            'stato' => $riga['stato'],
            'responsabile_id' => $riga['responsabile_id'],
            'numero_contatti' => $riga['numero_contatti'],
            'ultima_attivita_il' => self::isoCrm($riga['ultima_attivita_il']),
            'trattative_aperte' => $riga['trattative_aperte'],
            'valore_aperto' => ['importo' => $riga['valore_aperto'], 'valuta' => $this->valutaCrm($riga['workspace'])],
            'trattative_aperte_senza_valore' => $riga['trattative_aperte_senza_valore'],
            'archiviata_il' => self::isoCrm($riga['archiviata_il']),
            'creata_il' => self::iso($riga['creata_il']),
            'aggiornata_il' => self::iso($riga['aggiornata_il']),
            'creata_da' => $riga['creata_da'],
        ];
    }

    /** @return array<string, mixed> le regole di `OrganizzazioneNuova` (Organizzazioni::regole) */
    private function regoleOrganizzazione(string $workspace, bool $nuova): array
    {
        return [
            'nome' => $nuova ? ['required', 'string', 'min:1', 'max:160', new NomeScritto] : ['filled', 'string', 'max:160', new NomeScritto],
            'sito' => ['nullable', 'string', 'max:255', 'url:http,https'],
            'telefono' => ['nullable', 'string', 'max:32'],
            'indirizzo' => ['nullable', 'array'],
            'indirizzo.via' => ['nullable', 'string', 'max:200'],
            'indirizzo.cap' => ['nullable', 'string', 'max:20'],
            'indirizzo.citta' => ['nullable', 'string', 'max:100'],
            'indirizzo.provincia' => ['nullable', 'string', 'max:100'],
            'indirizzo.paese' => ['nullable', 'string', 'regex:/^[A-Z]{2}$/'],
            'stato' => ['filled', 'string', Rule::in(self::CRM_STATI)],
            'responsabile_id' => $this->regolaDelResponsabile($workspace),
        ];
    }

    /**
     * L'indirizzo con le sue cinque chiavi, nell'ordine della risposta e con `null` dove manca; `null` se non ne porta nessuna.
     *
     * @return array<string, string|null>|null
     */
    private static function indirizzoCrm(?array $indirizzo): ?array
    {
        $completo = [];

        foreach (self::CRM_INDIRIZZO as $chiave) {
            $completo[$chiave] = $indirizzo[$chiave] ?? null;
        }

        return array_filter($completo, fn (?string $valore) => $valore !== null) === [] ? null : $completo;
    }

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function creaOrganizzazione(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.organizzazioni.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, $this->regoleOrganizzazione($chi['workspace'], true));
            $id = $this->nasceOrganizzazione($chi, $campi);

            return [201, ['data' => $this->voceOrganizzazione($this->crm['organizzazioni'][$id])], ['Location' => "/v1/crm/organizzazioni/{$id}"]];
        });
    }

    /** Fa nascere un'azienda dai campi validati, con i default di `OrganizzazioneNuova` e la riga `creata` della cronologia. */
    private function nasceOrganizzazione(array $chi, array $campi, ?string $importazione = null): string
    {
        $id = self::id();
        $ora = self::adessoCrm();
        $this->crm['organizzazioni'][$id] = [
            'id' => $id, 'workspace' => $chi['workspace'], 'nome' => $campi['nome'], 'sito' => $campi['sito'] ?? null,
            'telefono' => $campi['telefono'] ?? null, 'indirizzo' => self::indirizzoCrm($campi['indirizzo'] ?? null),
            'stato' => $campi['stato'] ?? self::CRM_STATI[0],
            'responsabile_id' => isset($campi['responsabile_id']) ? strtolower($campi['responsabile_id']) : $chi['persona'],
            'numero_contatti' => 0, 'ultima_attivita_il' => null, 'trattative_aperte' => 0, 'valore_aperto' => 0, 'trattative_aperte_senza_valore' => 0,
            'archiviata_il' => null, 'creata_il' => $ora, 'aggiornata_il' => $ora, 'creata_da' => $chi['persona'],
        ];
        $this->cambioCrm('organizzazioni', $id, $chi['persona'], 'creata', [], $importazione);

        return $id;
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaOrganizzazioneCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('organizzazioni', $chi['workspace'], $id);
        $campi = $this->modificaCrm($corpo, [...$this->regoleOrganizzazione($chi['workspace'], false), 'archiviata' => ['filled', 'boolean:strict']]);

        if (array_key_exists('indirizzo', $campi)) {
            $campi['indirizzo'] = self::indirizzoCrm($campi['indirizzo']);
        }

        $this->applicaOrganizzazione($riga['id'], $campi, $chi['persona']);

        return [200, ['data' => $this->voceOrganizzazione($this->crm['organizzazioni'][$riga['id']])]];
    }

    /** OrganizzazioniModifica::applica: i campi che cambiano davvero, l'archivio, la cronologia. */
    private function applicaOrganizzazione(string $id, array $campi, string $autore, ?string $importazione = null): void
    {
        $riga = $this->crm['organizzazioni'][$id];
        $cambi = [];
        $nuovi = [];
        $tipi = [];

        foreach (['nome', 'sito', 'telefono', 'indirizzo', 'stato', 'responsabile_id'] as $campo) {
            if (! array_key_exists($campo, $campi)) {
                continue;
            }

            $nuovo = $campo === 'responsabile_id' ? strtolower((string) $campi[$campo]) : $campi[$campo];

            if ($riga[$campo] !== $nuovo) {
                $nuovi[$campo] = $nuovo;
                $cambi[$campo] = ['prima' => $riga[$campo], 'dopo' => $nuovo];
            }
        }

        if (array_key_exists('archiviata', $campi) && $campi['archiviata'] !== ($riga['archiviata_il'] !== null)) {
            $nuovi['archiviata_il'] = $campi['archiviata'] ? self::adessoCrm() : null;
            $cambi['archiviata'] = [];
            $tipi[] = $campi['archiviata'] ? 'archiviata' : 'ripristinata';
        }

        if ($cambi === []) {
            return;
        }

        $this->salvaCrm('organizzazioni', $id, $nuovi);
        $voci = [];

        foreach (['nome', 'sito', 'telefono', 'indirizzo', 'stato', 'responsabile_id'] as $campo) {
            if (isset($cambi[$campo])) {
                $voci[] = in_array($campo, ['stato', 'responsabile_id'], true) ? ['campo' => $campo, ...$cambi[$campo]] : ['campo' => $campo];
            }
        }

        if ($voci !== []) {
            $this->cambioCrm('organizzazioni', $id, $autore, 'modificata', $voci);
        }

        foreach ($tipi as $tipo) {
            $this->cambioCrm('organizzazioni', $id, $autore, $tipo, [], $importazione);
        }
    }

    /** Organizzazioni::contaIContatti: il numero di contatti non archiviati delle aziende toccate da una scrittura di un contatto. */
    private function contaIContattiCrm(?string $prima, bool $contavaPrima, ?string $dopo, bool $contaDopo): void
    {
        $variazioni = [];

        foreach ([[$prima, $contavaPrima, -1], [$dopo, $contaDopo, 1]] as [$azienda, $conta, $passo]) {
            if ($azienda !== null && $conta) {
                $variazioni[$azienda] = ($variazioni[$azienda] ?? 0) + $passo;
            }
        }

        foreach (array_filter($variazioni) as $azienda => $variazione) {
            if (isset($this->crm['organizzazioni'][$azienda])) {
                $this->salvaCrm('organizzazioni', $azienda, ['numero_contatti' => $this->crm['organizzazioni'][$azienda]['numero_contatti'] + $variazione]);
            }
        }
    }

    // ———————————————————————————————————————— Contatti

    /**
     * Lo schema Persona (Forme::persona). La scheda intera (`mostra`, `crea`, `modifica`) porta anche `campi`, i valori dei campi
     * «tuoi»; l'elenco, la ricerca e l'esportazione hanno la forma breve, senza.
     *
     * @return array<string, mixed>
     */
    private function vocePersona(array $riga, bool $intera = false): array
    {
        $azienda = $riga['organizzazione_id'] === null ? null : ($this->crm['organizzazioni'][$riga['organizzazione_id']] ?? null);

        return [
            'id' => $riga['id'],
            'nome' => $riga['nome'],
            'cognome' => $riga['cognome'],
            'ruolo' => $riga['ruolo'],
            'organizzazione_id' => $riga['organizzazione_id'],
            'organizzazione' => $azienda === null ? null : ['id' => $azienda['id'], 'nome' => $azienda['nome'], 'archiviata_il' => self::isoCrm($azienda['archiviata_il'])],
            'email' => $riga['email'],
            'telefoni' => $riga['telefoni'],
            'stato' => $riga['stato'],
            'responsabile_id' => $riga['responsabile_id'],
            'base_giuridica' => $riga['base_giuridica'],
            'consenso_marketing' => ['dato' => $riga['consenso_marketing'], 'il' => self::isoCrm($riga['consenso_marketing_il'])],
            'fonte' => $riga['fonte'],
            'ultima_attivita_il' => self::isoCrm($riga['ultima_attivita_il']),
            'prossima_scadenza' => $riga['prossima_scadenza'],
            'trattative_aperte' => $riga['trattative_aperte'],
            'valore_aperto' => ['importo' => $riga['valore_aperto'], 'valuta' => $this->valutaCrm($riga['workspace'])],
            'trattative_aperte_senza_valore' => $riga['trattative_aperte_senza_valore'],
            'archiviato_il' => self::isoCrm($riga['archiviata_il']),
            'anonimizzata_il' => self::isoCrm($riga['anonimizzata_il']),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
            'creato_da' => $riga['creato_da'],
            ...($intera ? ['campi' => $this->valoriDiCrm($riga['workspace'], 'persona', $riga['id'])] : []),
        ];
    }

    /** @return array<string, mixed> le regole di `PersonaNuova` (Persone::regole) */
    private function regolePersona(string $workspace): array
    {
        return [
            'nome' => ['nullable', 'string', 'max:120', new NomeScritto],
            'cognome' => ['nullable', 'string', 'max:120', new NomeScritto],
            'ruolo' => ['nullable', 'string', 'max:120'],
            'organizzazione_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'organizzazioni', 'regole.organizzazione_non_esiste')],
            'email' => ['nullable', 'array', 'list', 'max:5'],
            'email.*.indirizzo' => ['required', 'string', 'email', 'max:254'],
            'email.*.etichetta' => ['required', 'string', Rule::in(self::CRM_ETICHETTE_EMAIL)],
            'telefoni' => ['nullable', 'array', 'list', 'max:5'],
            'telefoni.*.numero' => ['required', 'string', 'max:32'],
            'telefoni.*.etichetta' => ['required', 'string', Rule::in(self::CRM_ETICHETTE_TELEFONO)],
            'stato' => ['filled', 'string', Rule::in(self::CRM_STATI)],
            'responsabile_id' => $this->regolaDelResponsabile($workspace),
            'base_giuridica' => ['nullable', 'string', Rule::in(self::CRM_BASI_GIURIDICHE)],
            'consenso_marketing' => ['sometimes', 'array'],
            'consenso_marketing.dato' => ['present_with:consenso_marketing', 'boolean:strict'],
            'fonte' => ['nullable', 'string', 'max:120'],
        ];
    }

    /**
     * Gli indirizzi di una lista in minuscolo e le voci in ordine; un indirizzo che compare due volte è 422 sulla seconda voce.
     *
     * @return list<array{indirizzo: string, etichetta: string}>
     */
    private function emailCrm(array $email): array
    {
        $email = array_values($email);
        $visti = [];

        foreach ($email as $i => $voce) {
            $minuscolo = self::normalizza($voce['indirizzo']);

            if (in_array($minuscolo, $visti, true)) {
                throw $this->erroreCrm("email.{$i}.indirizzo", 'regole.email_doppia', Regola::DUPLICATO);
            }

            $visti[] = $minuscolo;
            $email[$i] = ['indirizzo' => $minuscolo, 'etichetta' => $voce['etichetta']];
        }

        return $email;
    }

    /** Il contatto del workspace che ha questo indirizzo, archiviato o no (Persone::conEmail), o null. */
    private function personaConEmailCrm(string $workspace, string $indirizzo): ?string
    {
        foreach ($this->righeCrm('persone', $workspace) as $persona) {
            if (in_array(self::normalizza($indirizzo), array_column($persona['email'], 'indirizzo'), true)) {
                return $persona['id'];
            }
        }

        return null;
    }

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function creaPersonaCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.persone.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $validati = $this->testi->validaCorpo($corpo, [...$this->regolePersona($chi['workspace']), ...$this->regoleDeiValoriCrm($chi['workspace'], 'persona', true)]);
            $campi = $this->nuovaPersonaCrm($validati, $chi['persona']);

            foreach ($campi['email'] as $voce) {
                $presente = $this->personaConEmailCrm($chi['workspace'], $voce['indirizzo']);

                if ($presente !== null) {
                    throw new Problema('email_gia_presente', estensioni: ['persona_id' => $presente]);
                }
            }

            $id = $this->nascePersonaCrm($chi, $campi);
            $this->scriviIValoriCrm('persona', $id, $this->pianoDeiValoriCrm($chi['workspace'], 'persona', $id, $validati['campi'] ?? []));

            return [201, ['data' => $this->vocePersona($this->crm['persone'][$id], true)], ['Location' => "/v1/crm/persone/{$id}"]];
        });
    }

    /** I campi di un contatto nuovo dai valori validati (Persone::nuova): le email normalizzate, i default, e almeno un nome o un'email. */
    private function nuovaPersonaCrm(array $campi, string $autore): array
    {
        $email = $this->emailCrm($campi['email'] ?? []);

        if (($campi['nome'] ?? null) === null && ($campi['cognome'] ?? null) === null && $email === []) {
            throw $this->erroreCrm('nome', 'regole.contatto_vuoto', Regola::OBBLIGATORIO);
        }

        return [
            'nome' => $campi['nome'] ?? null,
            'cognome' => $campi['cognome'] ?? null,
            'ruolo' => $campi['ruolo'] ?? null,
            'organizzazione_id' => isset($campi['organizzazione_id']) ? strtolower($campi['organizzazione_id']) : null,
            'stato' => $campi['stato'] ?? self::CRM_STATI[0],
            'responsabile_id' => isset($campi['responsabile_id']) ? strtolower($campi['responsabile_id']) : $autore,
            'base_giuridica' => $campi['base_giuridica'] ?? null,
            'consenso_marketing' => $campi['consenso_marketing']['dato'] ?? false,
            'fonte' => $campi['fonte'] ?? null,
            'email' => $email,
            'telefoni' => array_map(fn (array $voce) => ['numero' => $voce['numero'], 'etichetta' => $voce['etichetta']], array_values($campi['telefoni'] ?? [])),
        ];
    }

    /** Persone::nasce: il contatto, la riga `creata` della cronologia e il conto dei contatti della sua azienda. */
    private function nascePersonaCrm(array $chi, array $campi, ?string $importazione = null): string
    {
        $id = self::id();
        $ora = self::adessoCrm();
        $this->crm['persone'][$id] = [
            'id' => $id, 'workspace' => $chi['workspace'], ...$campi,
            'consenso_marketing_il' => $campi['consenso_marketing'] ? $ora : null,
            'ultima_attivita_il' => null, 'prossima_scadenza' => null, 'trattative_aperte' => 0, 'valore_aperto' => 0, 'trattative_aperte_senza_valore' => 0,
            'archiviata_il' => null, 'anonimizzata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora, 'creato_da' => $chi['persona'],
        ];
        $this->cambioCrm('persone', $id, $chi['persona'], 'creata', [], $importazione);
        $this->contaIContattiCrm(null, false, $campi['organizzazione_id'], true);

        return $id;
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaPersonaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('persone', $chi['workspace'], $id);
        $campi = $this->modificaCrm($corpo, [...$this->regolePersona($chi['workspace']), ...$this->regoleDeiValoriCrm($chi['workspace'], 'persona', false), 'archiviato' => ['filled', 'boolean:strict']]);

        if ($riga['anonimizzata_il'] !== null) {
            throw new Problema('persona_anonimizzata');
        }

        $this->applicaPersona($riga['id'], $campi, $chi['persona']);

        return [200, ['data' => $this->vocePersona($this->crm['persone'][$riga['id']], true)]];
    }

    /** PersoneModifica::applica: i campi che cambiano davvero, le liste sostituite, il conto dei contatti, la cronologia. */
    private function applicaPersona(string $id, array $campi, string $autore, ?string $importazione = null): void
    {
        $riga = $this->crm['persone'][$id];
        $ora = self::adessoCrm();
        $cambi = [];
        $nuovi = [];
        $tipi = [];

        foreach (['nome', 'cognome', 'ruolo', 'organizzazione_id', 'stato', 'responsabile_id', 'base_giuridica', 'fonte'] as $campo) {
            if (! array_key_exists($campo, $campi)) {
                continue;
            }

            $nuovo = in_array($campo, ['responsabile_id', 'organizzazione_id'], true) && $campi[$campo] !== null ? strtolower((string) $campi[$campo]) : $campi[$campo];

            if ($riga[$campo] !== $nuovo) {
                $nuovi[$campo] = $nuovo;
                $cambi[$campo] = ['prima' => $riga[$campo], 'dopo' => $nuovo];
            }
        }

        $consenso = $campi['consenso_marketing']['dato'] ?? null;

        if ($consenso !== null && $consenso !== $riga['consenso_marketing']) {
            $cambi['consenso_marketing'] = ['prima' => $riga['consenso_marketing'], 'dopo' => $consenso];
            $nuovi['consenso_marketing'] = $consenso;
            $nuovi['consenso_marketing_il'] = $ora;
        }

        if (array_key_exists('archiviato', $campi) && $campi['archiviato'] !== ($riga['archiviata_il'] !== null)) {
            $nuovi['archiviata_il'] = $campi['archiviato'] ? $ora : null;
            $cambi['archiviato'] = [];
            $tipi[] = $campi['archiviato'] ? 'archiviata' : 'ripristinata';
        }

        $email = array_key_exists('email', $campi) ? $this->emailCrm($campi['email'] ?? []) : null;
        $telefoni = array_key_exists('telefoni', $campi) ? array_map(fn (array $voce) => ['numero' => $voce['numero'], 'etichetta' => $voce['etichetta']], array_values($campi['telefoni'] ?? [])) : null;

        if ($email !== null && $email !== $riga['email']) {
            $cambi['email'] = [];
        }

        if ($telefoni !== null && $telefoni !== $riga['telefoni']) {
            $cambi['telefoni'] = [];
        }

        // I valori dei campi «tuoi» (#2125): il piano si fa adesso, si scrive dopo i controlli che possono rifiutare la modifica.
        $piano = $this->pianoDeiValoriCrm($riga['workspace'], 'persona', $id, $campi['campi'] ?? []);

        if ($cambi === [] && $piano === []) {
            return;
        }

        $nome = array_key_exists('nome', $nuovi) ? $nuovi['nome'] : $riga['nome'];
        $cognome = array_key_exists('cognome', $nuovi) ? $nuovi['cognome'] : $riga['cognome'];

        if ($nome === null && $cognome === null && (isset($cambi['email']) ? $email : $riga['email']) === []) {
            throw $this->erroreCrm('nome', 'regole.contatto_vuoto', Regola::OBBLIGATORIO);
        }

        if (isset($cambi['email'])) {
            foreach ($email as $voce) {
                $presente = $this->personaConEmailCrm($riga['workspace'], $voce['indirizzo']);

                if ($presente !== null && $presente !== $id) {
                    throw new Problema('email_gia_presente', estensioni: ['persona_id' => $presente]);
                }
            }

            $nuovi['email'] = $email;
        }

        if (isset($cambi['telefoni'])) {
            $nuovi['telefoni'] = $telefoni;
        }

        $this->scriviIValoriCrm('persona', $id, $piano);

        // Cambiano solo le liste o i valori: il contatto si tocca comunque (Registro::segnala), e `touch` scrive l'ora, senza il
        // millisecondo in più che AP6 dà a un salvataggio con cambi.
        if (! $this->salvaCrm('persone', $id, $nuovi)) {
            $this->crm['persone'][$id]['aggiornato_il'] = self::adessoCrm();
        }

        $dopo = $this->crm['persone'][$id];
        $this->contaIContattiCrm($riga['organizzazione_id'], $riga['archiviata_il'] === null, $dopo['organizzazione_id'], $dopo['archiviata_il'] === null);

        $valori = ['organizzazione_id' => 'organizzazione_id', 'stato' => 'stato', 'responsabile_id' => 'responsabile_id', 'base_giuridica' => 'base_giuridica', 'consenso_marketing' => 'consenso_marketing.dato'];
        $voci = [];

        foreach (['nome', 'cognome', 'ruolo', 'organizzazione_id', 'email', 'telefoni', 'stato', 'responsabile_id', 'base_giuridica', 'consenso_marketing'] as $campo) {
            if (isset($cambi[$campo])) {
                $voci[] = isset($valori[$campo]) ? ['campo' => $valori[$campo], ...$cambi[$campo]] : ['campo' => $campo];
            }
        }

        $voci = [...$voci, ...self::vociDeiValoriCrm($piano)];

        if ($voci !== []) {
            $this->cambioCrm('persone', $id, $autore, 'modificata', $voci);
        }

        foreach ($tipi as $tipo) {
            $this->cambioCrm('persone', $id, $autore, $tipo, [], $importazione);
        }
    }

    // ———————————————————————————————————————— Importazioni

    /**
     * crm.persone.importazioni.crea e crm.organizzazioni.importazioni.crea (i controller e Importazioni): fino a 500 righe, ognuna
     * validata come `crea`. Una riga nasce, salta perché la sua chiave (un'email del contatto, il nome dell'azienda senza maiuscole) è
     * già di una riga del workspace, archiviata compresa (`duplicata_archiviata`), o di una riga prima (`duplicata`), o è rifiutata coi
     * primi tre errori di `crea` sotto `#/righe/<n>` (i campi estranei in un errore solo, sulla riga). Il tetto si guarda sulle righe che
     * nascerebbero, prima di scrivere. 201 con l'id dell'importazione; con `prova: true` lo stesso lavoro senza scrivere niente, 200 e
     * senza gli id di ciò che sarebbe nato.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function importaCrm(Request $richiesta, array $corpo, string $tipo, string $operazione): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        // RateLimit-Policy e RateLimit su ogni risposta dopo il ruolo e l'app (FreniDelleImportazioni): errori, 429 e risposta ricordata
        // compresi, col residuo di adesso.
        try {
            $esito = $this->importaCrmFrenata($richiesta, $corpo, $tipo, $operazione, $chi);
        } catch (Problema $problema) {
            throw $problema->conHeader($this->intestazioniImportazioniCrm($chi));
        }

        return [$esito[0], $esito[1], $esito[2] + $this->intestazioniImportazioniCrm($chi)];
    }

    /**
     * @param  array<mixed>  $corpo
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function importaCrmFrenata(Request $richiesta, array $corpo, string $tipo, string $operazione, array $chi): array
    {
        return $this->conIdempotenza($richiesta, $operazione, $chi, $corpo, function () use ($chi, $corpo, $tipo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'prova' => ['nullable', 'boolean:strict'],
                'righe' => ['required', 'array', 'list', 'min:1', 'max:'.self::CRM_RIGHE_DI_UN_IMPORT],
                'righe.*' => ['array'],
                ...($tipo === 'persone' ? ['simili' => ['nullable', 'string', Rule::in(['crea', 'salta'])]] : []),
            ]);
            $prova = (bool) ($campi['prova'] ?? false);
            $this->frenaImportazioniCrm($chi, $prova, count($campi['righe']));
            $salta = ($campi['simili'] ?? null) === 'salta';
            $esiti = [];
            $nuove = [];
            $viste = [];
            $righeSimili = [];

            foreach (array_values($campi['righe']) as $indice => $riga) {
                try {
                    $valida = $tipo === 'persone'
                        ? $this->nuovaPersonaCrm($this->testi->validaCorpo($riga, $this->regolePersona($chi['workspace'])), $chi['persona'])
                        : $this->testi->validaCorpo($riga, $this->regoleOrganizzazione($chi['workspace'], true));
                } catch (Problema $problema) {
                    $esiti[$indice] = $problema->codice === 'dati_non_validi' ? $this->rigaRifiutataCrm($indice, $problema) : throw $problema;

                    continue;
                }

                $chiavi = $tipo === 'persone' ? array_column($valida['email'], 'indirizzo') : [mb_strtolower(trim($valida['nome']))];
                $esistente = null;

                foreach ($chiavi as $chiave) {
                    $esistente ??= $tipo === 'persone' ? $this->personaConEmailCrm($chi['workspace'], $chiave) : $this->aziendaColNomeCrm($chi['workspace'], $chiave);
                }

                $vista = array_values(array_filter(array_map(fn (string $chiave) => $viste[$chiave] ?? null, $chiavi), fn (?int $altra) => $altra !== null))[0] ?? null;

                if ($esistente !== null) {
                    $archiviata = $this->crm[$tipo][$esistente]['archiviata_il'] !== null;
                    $esiti[$indice] = ['riga' => $indice, 'esito' => 'saltata', 'motivo' => $archiviata ? 'duplicata_archiviata' : 'duplicata', 'id' => $esistente];
                } elseif ($vista !== null) {
                    $esiti[$indice] = ['riga' => $indice, 'esito' => 'saltata', 'motivo' => 'duplicata', 'uguale_a' => $vista, 'nuova' => true];
                } elseif ($tipo === 'persone' && $chiavi === []) {
                    // I simili (SomiglianzaDiNomi, #1896): senza email, lo stesso nome e cognome di un contatto vivo o di una riga prima.
                    $nome = self::nomeSimileCrm($valida['nome'], $valida['cognome']);
                    $contatto = $this->contattoSimileCrm($chi['workspace'], $nome, $valida['organizzazione_id']);
                    $prima = null;

                    foreach ($contatto === null ? $righeSimili[$nome] ?? [] : [] as [$altra, $azienda]) {
                        if ($valida['organizzazione_id'] === null || $azienda === $valida['organizzazione_id']) {
                            $prima = $altra;

                            break;
                        }
                    }

                    if (($contatto !== null || $prima !== null) && $salta) {
                        $esiti[$indice] = ['riga' => $indice, 'esito' => 'saltata', 'motivo' => 'simile']
                            + ($contatto !== null ? ['id' => $contatto] : ['uguale_a' => $prima, 'nuova' => true]);
                    } else {
                        $righeSimili[$nome][] = [$indice, $valida['organizzazione_id']];
                        $nuove[$indice] = $valida;
                        $esiti[$indice] = ['riga' => $indice, 'esito' => 'creata', 'nuova' => true]
                            + ($contatto !== null ? ['simile_a' => $contatto] : ($prima !== null ? ['simile_della_riga' => $prima] : []));
                    }
                } else {
                    $viste += array_fill_keys($chiavi, $indice);
                    $nuove[$indice] = $valida;
                    $esiti[$indice] = ['riga' => $indice, 'esito' => 'creata', 'nuova' => true];
                }
            }

            if ($nuove !== [] && count($this->righeCrm($tipo, $chi['workspace'])) + count($nuove) > self::CRM_TETTI[$tipo]) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI[$tipo]]);
            }

            $importazione = null;

            if (! $prova) {
                $importazione = self::id();
                $saltate = count(array_filter($esiti, fn (array $esito) => $esito['esito'] === 'saltata'));
                $this->crm['importazioni'][$importazione] = [
                    'id' => $importazione, 'workspace' => $chi['workspace'], 'tipo' => $tipo, 'autore_id' => $chi['persona'], 'righe' => count($campi['righe']),
                    'create' => count($nuove), 'saltate' => $saltate, 'rifiutate' => count($campi['righe']) - count($nuove) - $saltate, 'creata_il' => self::adessoCrm(),
                    'annullata_il' => null, 'annullamento' => null,
                ];

                foreach ($nuove as $indice => $valida) {
                    $esiti[$indice]['id'] = $tipo === 'persone' ? $this->nascePersonaCrm($chi, $valida, $importazione) : $this->nasceOrganizzazione($chi, $valida, $importazione);
                }
            }

            foreach ($esiti as $indice => $esito) {
                if (isset($esito['uguale_a'])) {
                    $esiti[$indice]['id'] = $esiti[$esito['uguale_a']]['id'] ?? null;
                    unset($esiti[$indice]['uguale_a']);
                }

                if (isset($esito['simile_della_riga'])) {
                    $esiti[$indice]['simile_a'] = $esiti[$esito['simile_della_riga']]['id'] ?? null;
                    $esiti[$indice]['simile_nuova'] = true;
                    unset($esiti[$indice]['simile_della_riga']);
                }
            }

            ksort($esiti);
            $righe = array_map(function (array $esito) use ($importazione) {
                $nuova = $esito['nuova'] ?? false;
                $simileNuova = $esito['simile_nuova'] ?? false;
                unset($esito['nuova'], $esito['simile_nuova']);
                // L'ordine dei campi di un esito del backoffice (Importazioni::CAMPI_DI_UN_ESITO).
                $esito = array_replace(array_intersect_key(array_flip(['riga', 'esito', 'motivo', 'id', 'simile_a', 'errors']), $esito), $esito);

                return $importazione === null ? array_diff_key($esito, ($nuova ? ['id' => true] : []) + ($simileNuova ? ['simile_a' => true] : [])) : $esito;
            }, array_values($esiti));
            $conta = fn (string $esito) => count(array_filter($righe, fn (array $riga) => $riga['esito'] === $esito));
            $totali = ['create' => $conta('creata'), 'saltate' => $conta('saltata'), 'rifiutate' => $conta('rifiutata')];

            return $importazione === null
                ? [200, ['data' => ['righe' => $righe, 'totali' => $totali]], []]
                : [201, ['data' => ['id' => $importazione, 'righe' => $righe, 'totali' => $totali]], ['Location' => "/v1/crm/importazioni/{$importazione}"]];
        });
    }

    /**
     * Importazioni::frena: la chiamata e le sue righe, in prova o in scrittura, per persona e per workspace, dopo il corpo e prima del
     * lavoro. Oltre un tetto è 429 col `freno` che ha fermato e Retry-After, e la chiamata non consuma niente.
     *
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     */
    private function frenaImportazioniCrm(array $chi, bool $prova, int $righe): void
    {
        $tipo = $prova ? 'prova' : 'scrittura';
        $contate = [];

        foreach (['chiamate_persona' => 1, "{$tipo}_persona" => $righe, "{$tipo}_workspace" => $righe] as $freno => $unita) {
            $chiave = self::chiaveImportazioniCrm($chi, $freno);

            if ($this->freni->attempts($chiave) + $unita > self::CRM_FRENI_DELLE_IMPORTAZIONI[$freno]) {
                foreach ($contate as $contata => $quante) {
                    $this->freni->decrement($contata, self::ORA, $quante);
                }

                throw new Problema('troppe_richieste', header: ['Retry-After' => (string) $this->freni->availableIn($chiave)], estensioni: ['freno' => $freno]);
            }

            $this->freni->increment($chiave, self::ORA, $unita);
            $contate[$chiave] = $unita;
        }
    }

    /**
     * Importazioni::intestazioni: una politica per freno, col tetto e l'ora, e il residuo coi secondi al ripristino (0 se la finestra non è aperta).
     *
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     * @return array{RateLimit-Policy: string, RateLimit: string}
     */
    private function intestazioniImportazioniCrm(array $chi): array
    {
        $politiche = [];
        $residui = [];

        foreach (self::CRM_FRENI_DELLE_IMPORTAZIONI as $freno => $tetto) {
            $chiave = self::chiaveImportazioniCrm($chi, $freno);
            $politiche[] = "\"{$freno}\";q={$tetto};w=".self::ORA;
            $residui[] = "\"{$freno}\";r=".max(0, $tetto - $this->freni->attempts($chiave)).';t='.$this->freni->availableIn($chiave);
        }

        return ['RateLimit-Policy' => implode(', ', $politiche), 'RateLimit' => implode(', ', $residui)];
    }

    /** @param  array{persona: string, accesso: string, workspace: string}  $chi */
    private static function chiaveImportazioniCrm(array $chi, string $freno): string
    {
        return "freni:crm.importazioni:{$freno}:".(str_ends_with($freno, '_persona') ? $chi['persona'] : $chi['workspace']);
    }

    /** La chiave di un nome e cognome (SomiglianzaDiNomi::chiave): senza spazi ai bordi, senza accenti (NFD senza segni), piegata. */
    private static function nomeSimileCrm(?string $nome, ?string $cognome): string
    {
        $piega = function (?string $testo): string {
            $testo = trim((string) $testo);
            $scomposto = \Normalizer::normalize($testo, \Normalizer::FORM_D);

            return mb_convert_case(preg_replace('/\p{Mn}+/u', '', is_string($scomposto) ? $scomposto : $testo) ?? $testo, MB_CASE_FOLD, 'UTF-8');
        };

        return $piega($nome)."\u{1F}".$piega($cognome);
    }

    /** Il primo per id dei contatti vivi (non archiviati, non anonimizzati) con quella chiave; con un'azienda, della stessa azienda. */
    private function contattoSimileCrm(string $workspace, string $nome, ?string $azienda): ?string
    {
        $simili = array_filter($this->righeCrm('persone', $workspace), fn (array $riga) => $riga['archiviata_il'] === null && $riga['anonimizzata_il'] === null
            && self::nomeSimileCrm($riga['nome'], $riga['cognome']) === $nome && ($azienda === null || $riga['organizzazione_id'] === $azienda));
        $id = array_column($simili, 'id');
        sort($id);

        return $id[0] ?? null;
    }

    /** Un'importazione del workspace (404 non_trovato per una di un altro workspace o che non c'è). @return array<string, mixed> */
    private function importazioneCrm(string $workspace, string $id): array
    {
        $importazione = $this->crm['importazioni'][strtolower($id)] ?? null;

        return $importazione !== null && $importazione['workspace'] === $workspace ? $importazione : throw new Problema('non_trovato');
    }

    /**
     * Lo schema Importazione (Forme::importazione): conti e istanti, mai un valore delle righe.
     *
     * @param  array<string, mixed>  $importazione
     * @return array<string, mixed>
     */
    private function voceImportazioneCrm(array $importazione): array
    {
        return [
            'id' => $importazione['id'], 'tipo' => $importazione['tipo'], 'autore_id' => $importazione['autore_id'], 'creata_il' => self::isoCrm($importazione['creata_il']),
            'totali' => ['righe' => $importazione['righe'], 'create' => $importazione['create'], 'saltate' => $importazione['saltate'], 'rifiutate' => $importazione['rifiutate']],
            'annullabile_fino' => self::isoCrm($importazione['creata_il']->addHours(self::CRM_ORE_PER_ANNULLARE)),
            'annullata_il' => self::isoCrm($importazione['annullata_il']), 'annullamento' => $importazione['annullamento'],
        ];
    }

    /**
     * crm.importazioni.annullamento.crea (ImportazioniController, AnnullamentoDiImportazione): entro 24 ore archivia come a mano le righe
     * nate dall'importazione e non toccate dopo, e conta le altre per motivo; una seconda volta l'esito salvato, oltre 409
     * annullamento_scaduto. Proprietario e amministratori, 30 all'ora per persona, Idempotency-Key.
     *
     * @param  array<string, mixed>  $corpo
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function annullaImportazioneCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $importazione = $this->importazioneCrm($chi['workspace'], $id);

        return $this->conIdempotenza($richiesta, 'crm.importazioni.annullamento.crea', $chi, $corpo, function () use ($chi, $importazione) {
            $this->frena('freni:crm.importazioni.annullamento.crea:'.$chi['persona'], self::CRM_ANNULLAMENTI_ALL_ORA, 3600);

            if ($importazione['annullata_il'] === null) {
                if (self::adessoCrm()->greaterThan($importazione['creata_il']->addHours(self::CRM_ORE_PER_ANNULLARE))) {
                    throw new Problema('annullamento_scaduto');
                }

                $tipo = $importazione['tipo'];
                $lasciate = ['modificate' => 0, 'collegate' => 0, 'gia_archiviate' => 0];
                $archiviate = 0;
                $nate = array_column(array_filter($this->crm['cambi'], fn (array $cambio) => $cambio['tipo_risorsa'] === $tipo && $cambio['tipo'] === 'creata' && $cambio['importazione_id'] === $importazione['id']), 'risorsa');
                sort($nate);

                foreach ($nate as $nata) {
                    $motivo = match (true) {
                        $this->crm[$tipo][$nata]['archiviata_il'] !== null || ($this->crm[$tipo][$nata]['eliminata'] ?? false) => 'gia_archiviate',
                        $this->toccataCrm($tipo, $nata) => 'modificate',
                        $this->collegataCrm($tipo, $nata) => 'collegate',
                        default => null,
                    };

                    if ($motivo !== null) {
                        $lasciate[$motivo]++;
                    } else {
                        $tipo === 'persone'
                            ? $this->applicaPersona($nata, ['archiviato' => true], $chi['persona'], $importazione['id'])
                            : $this->applicaOrganizzazione($nata, ['archiviata' => true], $chi['persona'], $importazione['id']);
                        $archiviate++;
                    }
                }

                $importazione = $this->crm['importazioni'][$importazione['id']] = [
                    ...$importazione, 'annullata_il' => self::adessoCrm(), 'annullamento' => ['archiviate' => $archiviate, 'lasciate' => $lasciate],
                ];
            }

            return [200, ['data' => $this->voceImportazioneCrm($importazione)], []];
        });
    }

    /** Se la cronologia di una riga ha una voce dopo `creata`. */
    private function toccataCrm(string $tipo, string $id): bool
    {
        return array_filter($this->crm['cambi'], fn (array $cambio) => $cambio['tipo_risorsa'] === $tipo && $cambio['risorsa'] === $id && $cambio['tipo'] !== 'creata') !== [];
    }

    /** Se una nota, un'attività, una trattativa (e per un'azienda un contatto) citano la riga, anche archiviati o eliminati. */
    private function collegataCrm(string $tipo, string $id): bool
    {
        $colonna = $tipo === 'persone' ? 'persona_id' : 'organizzazione_id';

        foreach ($tipo === 'persone' ? ['note', 'attivita', 'trattative'] : ['note', 'attivita', 'trattative', 'persone'] as $altre) {
            foreach ($this->crm[$altre] as $riga) {
                if (($riga[$colonna] ?? null) === $id) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Importazioni::rifiutata: gli errori di `crea` coi pointer sotto la riga, al più tre; i campi estranei sono un errore solo, sulla riga,
     * che non ne ripete i nomi.
     *
     * @return array<string, mixed>
     */
    private function rigaRifiutataCrm(int $indice, Problema $problema): array
    {
        $errori = array_values(array_filter($problema->errori, fn (array $errore) => $errore['regola'] !== Regola::CAMPO_ESTRANEO));

        if (count($errori) < count($problema->errori)) {
            $errori[] = ['detail' => $this->testi->testo('regole.campi_estranei_della_riga'), 'regola' => Regola::CAMPO_ESTRANEO, 'pointer' => '#'];
        }

        return [
            'riga' => $indice,
            'esito' => 'rifiutata',
            'errors' => array_map(fn (array $errore) => [...$errore, 'pointer' => '#/righe/'.$indice.substr((string) ($errore['pointer'] ?? '#'), 1)], array_slice($errori, 0, self::CRM_ERRORI_PER_RIGA)),
        ];
    }

    /** L'azienda del workspace con questo nome senza maiuscole, archiviata compresa; se sono due, la più vecchia (l'id). */
    private function aziendaColNomeCrm(string $workspace, string $chiave): ?string
    {
        $uguali = array_column(array_filter($this->righeCrm('organizzazioni', $workspace), fn (array $azienda) => mb_strtolower(trim($azienda['nome'])) === $chiave), 'id');
        sort($uguali);

        return $uguali[0] ?? null;
    }

    // ———————————————————————————————————————— Anonimizzazione

    /**
     * Anonimizzazione::della (#1655): nome, cognome, ruolo e fonte a null, email e telefoni via, il testo delle sue note e di tutte le sue
     * attività vuoto, le attività vive eliminate, le trattative col titolo fisso e senza motivo di perdita, la riga `anonimizzata` della
     * cronologia. Risponde 200 coi due numeri; una seconda volta coi due zeri, senza scrivere.
     *
     * @return array{int, array<string, mixed>}
     */
    private function anonimizzaCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $persona = $this->rigaCrm('persone', $chi['workspace'], $id);

        if ($persona['anonimizzata_il'] !== null) {
            return [200, ['data' => ['attivita_eliminate' => 0, 'opportunita_rinominate' => 0]]];
        }

        $ora = self::adessoCrm();
        $this->crm['persone'][$persona['id']] = [
            ...$persona, 'nome' => null, 'cognome' => null, 'ruolo' => null, 'fonte' => null, 'email' => [], 'telefoni' => [],
            'anonimizzata_il' => $ora, 'aggiornato_il' => $ora,
        ];
        // L'oblio svuota anche i valori dei campi «tuoi» (Anonimizzazione::valori).
        unset($this->crm['valori']['persona'][$persona['id']]);

        foreach ($this->crm['note'] as $nota => $riga) {
            if ($riga['persona_id'] === $persona['id']) {
                $this->crm['note'][$nota]['testo'] = '';
            }
        }

        $eliminate = 0;

        foreach ($this->crm['attivita'] as $attivita => $riga) {
            if ($riga['persona_id'] === $persona['id']) {
                $eliminate += ($riga['eliminata'] ?? false) ? 0 : 1;
                $this->crm['attivita'][$attivita] = [...$riga, 'eliminata' => true, 'titolo' => '', 'note' => null, 'esito' => null];
            }
        }

        // AttivitaComune::ricalcolaLaScadenza salva il contatto letto prima dell'anonimizzazione: se la scadenza cambia, l'istante
        // cresce da quello di allora (AP6).
        $scadenza = $this->prossimaScadenzaCrm($persona['id']);

        if ($eliminate > 0 && $scadenza !== $persona['prossima_scadenza']) {
            $this->crm['persone'][$persona['id']]['prossima_scadenza'] = $scadenza;
            $this->crm['persone'][$persona['id']]['aggiornato_il'] = self::dopoCrm($persona['aggiornato_il']);
        }

        $rinominate = 0;

        foreach ($this->crm['trattative'] as $trattativa => $riga) {
            if ($riga['persona_id'] === $persona['id']) {
                $rinominate += $riga['titolo'] !== self::CRM_TITOLO_ANONIMO ? 1 : 0;

                if ($riga['titolo'] !== self::CRM_TITOLO_ANONIMO || $riga['motivo_perdita'] !== null) {
                    $this->crm['trattative'][$trattativa] = [...$riga, 'titolo' => self::CRM_TITOLO_ANONIMO, 'motivo_perdita' => null, 'aggiornata_il' => $ora];
                }
            }
        }

        $this->cambioCrm('persone', $persona['id'], $chi['persona'], 'anonimizzata', []);

        return [200, ['data' => ['attivita_eliminate' => $eliminate, 'opportunita_rinominate' => $rinominate]]];
    }

    // ———————————————————————————————————————— Motivi di perdita

    /**
     * crm.motivi.crea (MotiviController::crea): in fondo all'elenco; il tetto di 50 (archiviati compresi), poi il nome unico fra i
     * non archiviati senza maiuscole né accenti (422 `duplicato` su `#/nome`).
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaMotivoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.motivi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, ['nome' => ['bail', 'required', 'string', 'max:60', new NomeScritto]]);

            if (count($this->righeCrm('motivi', $chi['workspace'])) >= self::CRM_TETTI['motivi']) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['motivi']]);
            }

            $this->motivoUnicoCrm($chi['workspace'], $campi['nome'], null, 'nome');
            $vivi = $this->motiviViviCrm($chi['workspace']);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['motivi'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'nome' => $campi['nome'],
                'posizione' => $vivi === [] ? self::CRM_PASSO : end($vivi)['posizione'] + self::CRM_PASSO,
                'archiviata_il' => null, 'creato_il' => $ora, 'aggiornato_il' => $ora,
            ];

            return [201, ['data' => $this->voceMotivoCrm($this->crm['motivi'][$id])], ['Location' => "/v1/crm/motivi/{$id}"]];
        });
    }

    /**
     * crm.motivi.modifica (MotiviController::modifica): JSON Merge Patch di `nome`, `posizione` (il posto fra i non archiviati) e
     * `archiviato`; un motivo archiviato non ha posto (422 su `#/posizione`), ripristinato torna in fondo, e ripristinarlo con un nome
     * preso è 422 `duplicato` su `#/archiviato`. Il finto rinumera i vivi a passo pieno invece di collocare a metà: l'ordine è lo stesso.
     *
     * @return array{int, array<string, mixed>}
     */
    private function modificaMotivoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm('motivi', $chi['workspace'], $id);
        $campi = $this->modificaCrm($corpo, [
            'nome' => ['bail', 'filled', 'string', 'max:60', new NomeScritto],
            'posizione' => ['filled', 'integer:strict', 'min:1'],
            'archiviato' => ['filled', 'boolean:strict'],
        ]);
        $archiviato = $campi['archiviato'] ?? ($riga['archiviata_il'] !== null);

        if (array_key_exists('posizione', $campi) && $archiviato) {
            throw $this->erroreCrm('posizione', 'regole.motivo_archiviato_senza_posto', Regola::VALORE_NON_AMMESSO);
        }

        $nome = $campi['nome'] ?? $riga['nome'];

        if (! $archiviato && ($nome !== $riga['nome'] || $riga['archiviata_il'] !== null)) {
            $this->motivoUnicoCrm($chi['workspace'], $nome, $riga['id'], array_key_exists('nome', $campi) && $nome !== $riga['nome'] ? 'nome' : 'archiviato');
        }

        $nuovi = ['nome' => $nome];

        if ($archiviato !== ($riga['archiviata_il'] !== null)) {
            $nuovi['archiviata_il'] = $archiviato ? self::adessoCrm() : null;
        }

        if (! $archiviato && (array_key_exists('posizione', $campi) || $riga['archiviata_il'] !== null)) {
            $altri = array_values(array_filter($this->motiviViviCrm($chi['workspace']), fn (array $altro) => $altro['id'] !== $riga['id']));
            $posto = min($campi['posizione'] ?? PHP_INT_MAX, count($altri) + 1);

            if ($riga['archiviata_il'] !== null || $this->postoMotivoCrm($riga) !== $posto) {
                array_splice($altri, $posto - 1, 0, [$riga]);

                foreach ($altri as $i => $motivo) {
                    if ($motivo['id'] !== $riga['id']) {
                        $this->crm['motivi'][$motivo['id']]['posizione'] = self::CRM_PASSO * ($i + 1);
                    } else {
                        $nuovi['posizione'] = self::CRM_PASSO * ($i + 1);
                    }
                }
            }
        }

        $this->salvaCrm('motivi', $riga['id'], $nuovi);

        return [200, ['data' => $this->voceMotivoCrm($this->crm['motivi'][$riga['id']])]];
    }

    /**
     * crm.motivi.elenca: per posizione, a cursore; senza `archiviati` (o con `false`) i non archiviati col loro posto, con `true` gli
     * archiviati. Un cursore degli archiviati non vale per gli altri.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaMotiviCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), ['archiviati' => ['sometimes', 'string', Rule::in(['true', 'false'])]]);
        $archiviati = ($query['archiviati'] ?? null) === 'true';
        $righe = array_values(array_filter($this->righeCrm('motivi', $chi['workspace']), fn (array $riga) => ($riga['archiviata_il'] !== null) === $archiviati));

        return $this->paginaCrm($richiesta, 'crm.motivi.elenca'.($archiviati ? ':archiviati' : ''), $righe, fn (array $una, array $altra) => [$una['posizione'], $una['id']] <=> [$altra['posizione'], $altra['id']], fn (array $riga) => $this->voceMotivoCrm($riga));
    }

    /** @return array<string, mixed> lo schema Motivo (Forme::motivo) */
    private function voceMotivoCrm(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'nome' => $riga['nome'],
            'posizione' => $riga['archiviata_il'] === null ? $this->postoMotivoCrm($riga) : null,
            'archiviato_il' => self::isoCrm($riga['archiviata_il']),
            'creato_il' => self::iso($riga['creato_il']),
            'aggiornato_il' => self::iso($riga['aggiornato_il']),
        ];
    }

    /** @return list<array<string, mixed>> i motivi non archiviati del workspace, per posizione */
    private function motiviViviCrm(string $workspace): array
    {
        $vivi = array_values(array_filter($this->righeCrm('motivi', $workspace), fn (array $riga) => $riga['archiviata_il'] === null));
        usort($vivi, fn (array $una, array $altra) => [$una['posizione'], $una['id']] <=> [$altra['posizione'], $altra['id']]);

        return $vivi;
    }

    /** Il posto di un motivo vivo fra i vivi del suo workspace (1 = in cima). */
    private function postoMotivoCrm(array $riga): int
    {
        return (int) array_search($riga['id'], array_column($this->motiviViviCrm($riga['workspace']), 'id'), true) + 1;
    }

    /** Il nome non è di un altro motivo vivo, senza maiuscole, accenti e spazi ai bordi: altrimenti 422 `duplicato` sul campo dato. */
    private function motivoUnicoCrm(string $workspace, string $nome, ?string $tranne, string $campo): void
    {
        foreach ($this->motiviViviCrm($workspace) as $altro) {
            if ($altro['id'] !== $tranne && self::nomeSimileCrm($altro['nome'], null) === self::nomeSimileCrm($nome, null)) {
                throw $this->erroreCrm($campo, 'regole.motivo_duplicato', Regola::DUPLICATO);
            }
        }
    }

    // ———————————————————————————————————————— Pipeline e fasi

    /** @return array<string, mixed> lo schema Pipeline (Forme::pipeline): le fasi nell'ordine */
    private function vocePipeline(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'nome' => $riga['nome'],
            'fasi' => array_map(fn (array $fase) => $this->voceFase($fase), $this->fasiCrm($riga['id'])),
            'archiviata_il' => self::isoCrm($riga['archiviata_il']),
            'creata_il' => self::iso($riga['creata_il']),
            'aggiornata_il' => self::iso($riga['aggiornata_il']),
            'creata_da' => $riga['creata_da'],
        ];
    }

    /** @return array<string, mixed> lo schema Fase (Forme::fase): le senza valore si contano alla lettura */
    private function voceFase(array $fase): array
    {
        $senzaValore = count(array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['fase_id'] === $fase['id']
            && $trattativa['stato'] === 'aperta' && $trattativa['archiviata_il'] === null && $trattativa['importo'] === null));

        return [
            'id' => $fase['id'],
            'nome' => $fase['nome'],
            'probabilita' => $fase['probabilita'],
            'giorni_massimi' => $fase['giorni_massimi'],
            'trattative_aperte' => $fase['trattative_aperte'],
            'valore_aperto' => ['importo' => $fase['valore_aperto'], 'valuta' => $this->valutaCrm($fase['workspace'])],
            'trattative_aperte_senza_valore' => $senzaValore,
        ];
    }

    /**
     * Le fasi vive di una pipeline, per posizione e poi per id.
     *
     * @return list<array<string, mixed>>
     */
    private function fasiCrm(string $pipeline): array
    {
        $fasi = array_values(array_filter($this->crm['fasi'], fn (array $fase) => $fase['pipeline_id'] === $pipeline && ! ($fase['eliminata'] ?? false)));
        usort($fasi, fn (array $una, array $altra) => [$una['posizione'], $una['id']] <=> [$altra['posizione'], $altra['id']]);

        return $fasi;
    }

    /** La posizione in fondo a un insieme (Posizioni::inCoda): un passo dopo l'ultima, o il primo passo. */
    private static function inCodaCrm(array $righe): int
    {
        $posizioni = array_column($righe, 'posizione');

        return $posizioni === [] ? self::CRM_PASSO : max($posizioni) + self::CRM_PASSO;
    }

    /**
     * Posizioni::collocare: la posizione della riga `$id` di `$tipo` a metà fra l'ancora `$dopo` (o l'inizio, o la fine con `$inFondo`) e
     * la riga dopo di lei, fra i `$vicini` senza la riga stessa. Senza spazio i vicini si rinumerano a passo pieno, una volta sola.
     *
     * @param  array<array<string, mixed>>  $vicini
     */
    private function collocaCrm(string $tipo, array $vicini, string $id, ?string $dopo, bool $inFondo, bool $rinumerati = false): void
    {
        $vicini = array_filter($vicini, fn (array $riga) => $riga['id'] !== $id);
        $valori = array_map(fn (array $riga) => $this->crm[$tipo][$riga['id']]['posizione'], $vicini);
        sort($valori);

        if ($dopo !== null) {
            $prima = $this->crm[$tipo][$dopo]['posizione'];
            $indice = array_search($prima, $valori, true);
            $seguente = $indice === false ? null : ($valori[$indice + 1] ?? null);
        } else {
            $prima = $inFondo && $valori !== [] ? $valori[count($valori) - 1] : null;
            $seguente = $inFondo ? null : ($valori[0] ?? null);
        }

        $nuova = match (true) {
            $prima === null && $seguente === null => self::CRM_PASSO,
            $prima === null => $seguente > 1 ? intdiv($seguente, 2) : null,
            $seguente === null => $prima + self::CRM_PASSO <= self::CRM_SOGLIA ? $prima + self::CRM_PASSO : null,
            default => $seguente - $prima > 1 ? intdiv($prima + $seguente, 2) : null,
        };

        if ($nuova === null) {
            if ($rinumerati) {
                throw new LogicException("Il finto: nessuno spazio in {$tipo} nemmeno dopo la rinumerazione.");
            }

            foreach (self::ordinePerPosizioneCrm(array_map(fn (array $riga) => $this->crm[$tipo][$riga['id']], $vicini)) as $i => $vicino) {
                $this->crm[$tipo][$vicino]['posizione'] = self::CRM_PASSO * ($i + 1);
            }

            $this->collocaCrm($tipo, $vicini, $id, $dopo, $inFondo, true);

            return;
        }

        $this->crm[$tipo][$id]['posizione'] = $nuova;
    }

    /**
     * Gli id di un insieme per posizione e poi per id.
     *
     * @param  array<array<string, mixed>>  $insieme
     * @return list<string>
     */
    private static function ordinePerPosizioneCrm(array $insieme): array
    {
        usort($insieme, fn (array $una, array $altra) => [$una['posizione'], $una['id']] <=> [$altra['posizione'], $altra['id']]);

        return array_column($insieme, 'id');
    }

    /**
     * La riga è già dove la manderebbe lo spostamento (giaLi dei controller): subito dopo l'ancora, in cima, o in fondo.
     *
     * @param  array<array<string, mixed>>  $insieme
     */
    private static function giaLiCrm(array $insieme, string $id, ?string $dopo, bool $inFondo): bool
    {
        $ordine = self::ordinePerPosizioneCrm($insieme);
        $posto = array_search($id, $ordine, true);

        return match (true) {
            $posto === false => false,
            $dopo !== null => $posto > 0 && $ordine[$posto - 1] === $dopo,
            $inFondo => $posto === count($ordine) - 1,
            default => $posto === 0,
        };
    }

    /** Una scrittura di una fase tocca la sua pipeline (Fase::$touches). */
    private function toccaPipelineCrm(string $pipeline): void
    {
        $this->crm['pipeline'][$pipeline]['aggiornata_il'] = self::dopoCrm($this->crm['pipeline'][$pipeline]['aggiornata_il']);
    }

    /** @return array<string, mixed> le regole di `PipelineNuova` (Pipelines::corpoDellaCreazione) */
    private static function regolePipeline(): array
    {
        return [
            'nome' => ['bail', 'required', 'string', 'max:60', new NomeScritto],
            'fasi' => ['bail', 'required', 'array', 'list', 'min:1', 'max:20'],
            'fasi.*' => ['array'],
            'fasi.*.nome' => ['bail', 'required', 'string', 'max:40', new NomeScritto],
            'fasi.*.probabilita' => ['required', 'integer:strict', 'between:0,100'],
            'fasi.*.giorni_massimi' => ['nullable', 'integer:strict', 'between:1,365'],
        ];
    }

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function creaPipelineCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.pipeline.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, self::regolePipeline());
            $id = $this->nascePipelineCrm($chi, $campi);

            return [201, ['data' => $this->vocePipeline($this->crm['pipeline'][$id])], ['Location' => "/v1/crm/pipeline/{$id}"]];
        });
    }

    /**
     * PipelineController::partenzaCrea: il corpo si valida sempre; un workspace senza pipeline (né attiva né archiviata) ne fa nascere
     * una come `crea` (201 con Location), uno che ne ha risponde 200 con la prima nata (per `creata_il`, poi per id) senza cambiare niente.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function partenzaPipelineCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.pipeline.partenza.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, self::regolePipeline());
            $esistenti = $this->righeCrm('pipeline', $chi['workspace']);

            if ($esistenti !== []) {
                usort($esistenti, fn (array $una, array $altra) => [$una['creata_il'], $una['id']] <=> [$altra['creata_il'], $altra['id']]);

                return [200, ['data' => $this->vocePipeline($esistenti[0])], []];
            }

            $id = $this->nascePipelineCrm($chi, $campi);

            return [201, ['data' => $this->vocePipeline($this->crm['pipeline'][$id])], ['Location' => "/v1/crm/pipeline/{$id}"]];
        });
    }

    /** Pipelines::scrivi: il tetto, la pipeline, le fasi a passo pieno, nell'istante della pipeline. */
    private function nascePipelineCrm(array $chi, array $campi): string
    {
        if (count($this->righeCrm('pipeline', $chi['workspace'])) >= self::CRM_TETTI['pipeline']) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['pipeline']]);
        }

        $id = self::id();
        $ora = self::adessoCrm();
        $this->crm['pipeline'][$id] = ['id' => $id, 'workspace' => $chi['workspace'], 'nome' => $campi['nome'], 'archiviata_il' => null, 'creata_il' => $ora, 'aggiornata_il' => $ora, 'creata_da' => $chi['persona']];

        foreach (array_values($campi['fasi']) as $i => $fase) {
            $this->nasceFaseCrm($chi['workspace'], $id, $fase, self::CRM_PASSO * ($i + 1));
        }

        return $id;
    }

    private function nasceFaseCrm(string $workspace, string $pipeline, array $campi, int $posizione): string
    {
        $id = self::id();
        $this->crm['fasi'][$id] = [
            'id' => $id, 'workspace' => $workspace, 'pipeline_id' => $pipeline, 'nome' => $campi['nome'], 'probabilita' => $campi['probabilita'],
            'giorni_massimi' => $campi['giorni_massimi'] ?? null, 'posizione' => $posizione, 'trattative_aperte' => 0, 'valore_aperto' => 0,
        ];

        return $id;
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaPipelineCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm('pipeline', $chi['workspace'], $id);
        $campi = $this->modificaCrm($corpo, [
            'nome' => ['bail', 'filled', 'string', 'max:60', new NomeScritto],
            'archiviata' => ['filled', 'boolean:strict'],
        ]);
        $nuovi = [];

        if (array_key_exists('nome', $campi) && $campi['nome'] !== $riga['nome']) {
            $nuovi['nome'] = $campi['nome'];
        }

        if (array_key_exists('archiviata', $campi) && $campi['archiviata'] !== ($riga['archiviata_il'] !== null)) {
            if ($campi['archiviata'] && array_sum(array_column($this->fasiCrm($riga['id']), 'trattative_aperte')) > 0) {
                throw new Problema('pipeline_non_vuota');
            }

            $nuovi['archiviata_il'] = $campi['archiviata'] ? self::adessoCrm() : null;
        }

        $this->salvaCrm('pipeline', $riga['id'], $nuovi);

        return [200, ['data' => $this->vocePipeline($this->crm['pipeline'][$riga['id']])]];
    }

    /**
     * crm.pipeline.elenca: per creazione (l'id), senza le fasi; senza `archiviate` (o con `false`) le non archiviate, con `true` le
     * archiviate. Un cursore delle archiviate non vale per le altre.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaPipelineCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), ['archiviate' => ['sometimes', 'string', Rule::in(['true', 'false'])]]);
        $archiviate = ($query['archiviate'] ?? null) === 'true';
        $righe = array_values(array_filter($this->righeCrm('pipeline', $chi['workspace']), fn (array $riga) => ($riga['archiviata_il'] !== null) === $archiviate));

        return $this->paginaCrm($richiesta, 'crm.pipeline.elenca'.($archiviate ? ':archiviate' : ''), $righe, fn (array $una, array $altra) => strcmp($una['id'], $altra['id']), fn (array $riga) => [
            'id' => $riga['id'], 'nome' => $riga['nome'], 'archiviata_il' => self::isoCrm($riga['archiviata_il']), 'creata_il' => self::iso($riga['creata_il']),
        ]);
    }

    /** La regola di un `pipeline_id` (Pipelines::pipelineDelWorkspace): una pipeline del workspace, archiviata o no. */
    private function regolaDellaPipeline(string $workspace): Closure
    {
        return function (string $campo, mixed $id, Closure $fail) use ($workspace) {
            if (! $this->esisteCrm('pipeline', $workspace, $id)) {
                $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, 'validation.exists', ['attribute' => $campo]);
            }
        };
    }

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function creaFaseCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.fasi.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                'pipeline_id' => ['bail', 'required', 'string', $this->regolaDellaPipeline($chi['workspace'])],
                'nome' => ['bail', 'required', 'string', 'max:40', new NomeScritto],
                'probabilita' => ['required', 'integer:strict', 'between:0,100'],
                'giorni_massimi' => ['nullable', 'integer:strict', 'between:1,365'],
            ]);
            $pipeline = strtolower($campi['pipeline_id']);
            $fasi = $this->fasiCrm($pipeline);

            if (count($fasi) >= self::CRM_TETTI['fasi_per_pipeline']) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['fasi_per_pipeline']]);
            }

            $id = $this->nasceFaseCrm($chi['workspace'], $pipeline, $campi, self::inCodaCrm($fasi));
            $this->toccaPipelineCrm($pipeline);

            return [201, ['data' => $this->voceFase($this->crm['fasi'][$id])], ['Location' => "/v1/crm/pipeline/{$pipeline}"]];
        });
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaFaseCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $fase = $this->rigaCrm('fasi', $chi['workspace'], $id);
        $campi = $this->modificaCrm($corpo, [
            'nome' => ['bail', 'filled', 'string', 'max:40', new NomeScritto],
            'probabilita' => ['filled', 'integer:strict', 'between:0,100'],
            'giorni_massimi' => ['nullable', 'integer:strict', 'between:1,365'],
        ]);

        if (array_filter($campi, fn (mixed $valore, string $campo) => $valore !== $fase[$campo], ARRAY_FILTER_USE_BOTH) !== []) {
            $this->crm['fasi'][$fase['id']] = [...$fase, ...$campi];
            $this->toccaPipelineCrm($fase['pipeline_id']);
        }

        return [200, ['data' => $this->voceFase($this->crm['fasi'][$fase['id']])]];
    }

    /**
     * FasiController::spostamentoCrea: `dopo` c'è sempre (null = in cima). Una `dopo` che non è una fase della pipeline è 422 sul
     * pointer; una eliminata, o la fase stessa, 409 posizione_cambiata. Lo stesso spostamento ripetuto non scrive niente.
     *
     * @return array{int, array<string, mixed>}
     */
    private function spostaFaseCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $fase = $this->rigaCrm('fasi', $chi['workspace'], $id);
        $campi = $this->testi->validaCorpo($corpo, ['dopo' => ['present', 'nullable', 'string']]);
        $dopo = $campi['dopo'] === null ? null : strtolower($campi['dopo']);

        if ($dopo !== null) {
            $ancora = $this->crm['fasi'][$dopo] ?? null;

            if ($ancora === null || $ancora['workspace'] !== $chi['workspace'] || $ancora['pipeline_id'] !== $fase['pipeline_id']) {
                throw $this->erroreCrm('dopo', 'validation.exists', Regola::NON_TROVATO, ['attribute' => $this->testi->nome('dopo')]);
            }

            if (($ancora['eliminata'] ?? false) || $ancora['id'] === $fase['id']) {
                throw new Problema('posizione_cambiata');
            }
        }

        $fasi = $this->fasiCrm($fase['pipeline_id']);

        if (! self::giaLiCrm($fasi, $fase['id'], $dopo, false)) {
            $this->collocaCrm('fasi', array_filter($this->crm['fasi'], fn (array $altra) => $altra['pipeline_id'] === $fase['pipeline_id']), $fase['id'], $dopo, false);
            $this->toccaPipelineCrm($fase['pipeline_id']);
        }

        return [200, ['data' => $this->voceFase($this->crm['fasi'][$fase['id']])]];
    }

    /**
     * FasiController::elimina: mai la sola fase della pipeline (409 ultima_fase); una fase con trattative, aperte o no, solo con
     * `sposta_in` nella query (#1902), altrimenti 409 fase_non_vuota. Con `sposta_in` tutte le trattative passano in coda all'altra
     * fase della pipeline, nel loro ordine, con la cronologia di uno spostamento di fase; un `sposta_in` che non va è 422 sul
     * parameter (`valore_non_ammesso` la fase stessa o di un'altra pipeline, `non_trovato` il resto). Con una fase vuota si ignora.
     * La fase passa nell'archivio di sistema e da lì è 404. Risponde 204.
     *
     * @return array{int, null}
     */
    private function eliminaFaseCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $fase = $this->rigaCrm('fasi', $chi['workspace'], $id);
        $query = self::query($richiesta);
        $spostaIn = null;

        if (array_key_exists('sposta_in', $query)) {
            if (! is_string($query['sposta_in']) || $query['sposta_in'] === '' || strlen($query['sposta_in']) > 64) {
                throw $this->erroreDiParametroCrm('regole.sposta_in', Regola::TIPO);
            }

            $spostaIn = strtolower($query['sposta_in']);
        }

        if (count($this->fasiCrm($fase['pipeline_id'])) <= 1) {
            throw new Problema('ultima_fase');
        }

        $nellaFase = array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['fase_id'] === $fase['id']);

        if ($nellaFase !== []) {
            if ($spostaIn === null) {
                throw new Problema('fase_non_vuota');
            }

            $destinazione = $this->crm['fasi'][$spostaIn] ?? null;
            $viva = $destinazione !== null && $destinazione['workspace'] === $chi['workspace'] && ! ($destinazione['eliminata'] ?? false);

            if (! $viva) {
                throw $this->erroreDiParametroCrm($spostaIn === $fase['id'] ? 'regole.sposta_in_altra_fase' : 'regole.fase_della_pipeline', $spostaIn === $fase['id'] ? Regola::VALORE_NON_AMMESSO : Regola::NON_TROVATO);
            }

            if ($spostaIn === $fase['id'] || $destinazione['pipeline_id'] !== $fase['pipeline_id']) {
                throw $this->erroreDiParametroCrm('regole.sposta_in_altra_fase', Regola::VALORE_NON_AMMESSO);
            }

            usort($nellaFase, fn (array $una, array $altra) => [$una['posizione'], $una['id']] <=> [$altra['posizione'], $altra['id']]);
            $ora = self::adessoCrm();

            foreach ($nellaFase as $riga) {
                $spostata = ['fase_id' => $spostaIn, 'in_fase_dal' => $ora, 'aggiornata_il' => self::dopoCrm($riga['aggiornata_il'])] + $riga;
                $this->crm['trattative'][$riga['id']] = $spostata;
                $this->collocaCrm('trattative', array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['fase_id'] === $spostaIn), $riga['id'], null, true);
                $this->riportaCrm(self::contributoCrm($riga), self::contributoCrm($spostata));
                $this->cambioCrm('trattative', $riga['id'], $chi['persona'], 'modificata', [['campo' => 'fase_id', 'prima' => $fase['id'], 'dopo' => $spostaIn]]);
            }
        }

        $this->crm['fasi'][$fase['id']]['eliminata'] = true;
        $this->toccaPipelineCrm($fase['pipeline_id']);

        return [204, null];
    }

    /** Un 422 dati_non_validi su un parameter della query (ErroreDiCampo::parameter del backoffice). */
    private function erroreDiParametroCrm(string $chiave, string $regola): Problema
    {
        return new Problema('dati_non_validi', [['detail' => $this->testi->testo($chiave), 'regola' => $regola, 'parameter' => 'sposta_in']]);
    }

    // ———————————————————————————————————————— Trattative

    /** @return array<string, mixed> lo schema Trattativa (Forme::trattativa) */
    private function voceTrattativa(array $riga): array
    {
        $persona = $riga['persona_id'] === null ? null : ($this->crm['persone'][$riga['persona_id']] ?? null);
        $azienda = $riga['organizzazione_id'] === null ? null : ($this->crm['organizzazioni'][$riga['organizzazione_id']] ?? null);

        return [
            'id' => $riga['id'],
            'titolo' => $riga['titolo'],
            'valore' => $riga['importo'] === null ? null : ['importo' => $riga['importo'], 'valuta' => $this->valutaCrm($riga['workspace'])],
            'pipeline_id' => $riga['pipeline_id'],
            'fase_id' => $riga['fase_id'],
            'persona' => $persona === null ? null : [
                'id' => $persona['id'], 'nome' => $persona['nome'], 'cognome' => $persona['cognome'],
                'archiviato_il' => self::isoCrm($persona['archiviata_il']), 'anonimizzata_il' => self::isoCrm($persona['anonimizzata_il']),
            ],
            'organizzazione' => $azienda === null ? null : ['id' => $azienda['id'], 'nome' => $azienda['nome'], 'archiviata_il' => self::isoCrm($azienda['archiviata_il'])],
            'probabilita' => $riga['probabilita'],
            'in_fase_dal' => self::iso($riga['in_fase_dal']),
            'stato' => $riga['stato'],
            'motivo_perdita' => $riga['motivo_perdita'],
            'motivo_perdita_id' => $riga['motivo_perdita_id'] ?? null,
            'chiusa_il' => self::isoCrm($riga['chiusa_il']),
            'chiusura_prevista' => $riga['chiusura_prevista'],
            'responsabile_id' => $riga['responsabile_id'],
            'archiviata_il' => self::isoCrm($riga['archiviata_il']),
            'creata_il' => self::iso($riga['creata_il']),
            'aggiornata_il' => self::iso($riga['aggiornata_il']),
            'creata_da' => $riga['creata_da'],
        ];
    }

    /**
     * Le regole di `TrattativaNuova` e di `TrattativaModifica` (Trattative::regole). `$pulito` è il corpo come lo legge il backoffice.
     *
     * @return array<string, mixed>
     */
    private function regoleTrattativa(string $workspace, array $pulito, bool $nuova): array
    {
        $obbligatorio = 'required_with:valore';

        return [
            'titolo' => $nuova ? ['bail', 'required', 'string', 'min:1', 'max:160', new NomeScritto] : ['bail', 'filled', 'string', 'max:160', new NomeScritto],
            'valore' => ['bail', 'nullable', 'array', function (string $campo, mixed $valore, Closure $fail) {
                if ($valore === []) {
                    $this->rifiutaCrm($fail, $campo, Regola::OBBLIGATORIO, 'validation.required', ['attribute' => $campo]);
                }
            }],
            'valore.importo' => ['bail', $obbligatorio, 'integer:strict', 'between:0,1000000000000'],
            'valore.valuta' => ['bail', $obbligatorio, 'string', function (string $campo, mixed $valuta, Closure $fail) use ($workspace) {
                if ($valuta !== $this->valutaCrm($workspace)) {
                    $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'regole.valuta_del_workspace', ['valuta' => $this->valutaCrm($workspace)]);
                }
            }],
            'pipeline_id' => [...($nuova ? ['bail', 'required', 'string'] : ['bail', 'filled', 'string']), $this->regolaDellaPipeline($workspace)],
            'fase_id' => ['bail', 'nullable', 'string', 'max:64', function (string $campo, mixed $id, Closure $fail) use ($pulito, $nuova, $workspace) {
                if (! $nuova && ! array_key_exists('pipeline_id', $pulito)) {
                    $this->rifiutaCrm($fail, $campo, Regola::CAMPO_ESTRANEO, 'regole.fase_con_la_pipeline');

                    return;
                }

                $pipeline = $pulito['pipeline_id'] ?? null;
                $fase = $this->crm['fasi'][strtolower((string) $id)] ?? null;

                if (! is_string($pipeline) || $fase === null || ($fase['eliminata'] ?? false) || $fase['workspace'] !== $workspace || $fase['pipeline_id'] !== strtolower($pipeline)) {
                    $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, 'regole.fase_della_pipeline');
                }
            }],
            'persona_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'persone', 'regole.contatto_non_esiste')],
            'organizzazione_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'organizzazioni', 'regole.azienda_non_esiste')],
            'probabilita' => ['nullable', 'integer:strict', 'between:0,100'],
            'responsabile_id' => $this->regolaDelResponsabile($workspace),
            'chiusura_prevista' => ['nullable', 'string', 'date_format:Y-m-d'],
        ] + ($nuova ? [] : [
            'stato' => ['bail', 'filled', 'string', Rule::in(self::CRM_STATI_TRATTATIVA)],
            'motivo_perdita' => ['nullable', 'string', 'max:500'],
            'motivo_perdita_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'motivi', 'regole.motivo_non_esiste')],
            'archiviata' => ['filled', 'boolean:strict'],
        ]);
    }

    /** Un `valore` mandato come testo è un tipo sbagliato (Trattative::rifiutaUnValoreTesto): si guarda il corpo come è arrivato. */
    private function valoreTestoCrm(array $corpo): void
    {
        if (is_string($corpo['valore'] ?? null)) {
            throw $this->erroreCrm('valore', 'validation.array', Regola::TIPO, ['attribute' => 'valore']);
        }
    }

    /** Un contatto da collegare: uno anonimizzato non si collega più (Note::contatto). */
    private function contattoCollegabileCrm(?string $persona): void
    {
        if ($persona !== null && ($this->crm['persone'][$persona]['anonimizzata_il'] ?? null) !== null) {
            throw new Problema('persona_anonimizzata');
        }
    }

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function creaTrattativaCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.trattative.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->valoreTestoCrm($corpo);
            $campi = $this->testi->validaCorpo($corpo, $this->regoleTrattativa($chi['workspace'], Testi::pulisci($corpo), true));
            $pipeline = $this->crm['pipeline'][strtolower($campi['pipeline_id'])];

            if ($pipeline['archiviata_il'] !== null) {
                throw $this->erroreCrm('pipeline_id', 'regole.pipeline_archiviata', Regola::VALORE_NON_AMMESSO);
            }

            $fase = isset($campi['fase_id']) ? strtolower($campi['fase_id']) : ($this->fasiCrm($pipeline['id'])[0]['id'] ?? null);

            if ($fase === null) {
                throw $this->erroreCrm('fase_id', 'regole.fase_della_pipeline', Regola::NON_TROVATO);
            }

            $persona = isset($campi['persona_id']) ? strtolower($campi['persona_id']) : null;
            $this->contattoCollegabileCrm($persona);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['trattative'][$id] = [
                'id' => $id, 'workspace' => $chi['workspace'], 'titolo' => $campi['titolo'], 'importo' => $campi['valore']['importo'] ?? null,
                'pipeline_id' => $pipeline['id'], 'fase_id' => $fase, 'persona_id' => $persona,
                'organizzazione_id' => isset($campi['organizzazione_id']) ? strtolower($campi['organizzazione_id']) : null,
                'probabilita' => $campi['probabilita'] ?? null, 'in_fase_dal' => $ora, 'stato' => 'aperta', 'motivo_perdita' => null, 'motivo_perdita_id' => null, 'chiusa_il' => null,
                'chiusura_prevista' => $campi['chiusura_prevista'] ?? null,
                'responsabile_id' => isset($campi['responsabile_id']) ? strtolower($campi['responsabile_id']) : $chi['persona'],
                'archiviata_il' => null, 'creata_il' => $ora, 'aggiornata_il' => $ora, 'creata_da' => $chi['persona'],
                'posizione' => self::inCodaCrm(array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['fase_id'] === $fase)),
            ];
            $this->cambioCrm('trattative', $id, $chi['persona'], 'creata', []);
            $this->riportaCrm(null, self::contributoCrm($this->crm['trattative'][$id]));

            return [201, ['data' => $this->voceTrattativa($this->crm['trattative'][$id])], ['Location' => "/v1/crm/trattative/{$id}"]];
        });
    }

    /**
     * Ciò che una trattativa porta nei riassunti di fase, contatto e azienda (Trattative::contributo): null se non è aperta o è archiviata.
     *
     * @return array{fase: string, persona: string|null, organizzazione: string|null, importo: int, senza_valore: int}|null
     */
    private static function contributoCrm(array $trattativa): ?array
    {
        return $trattativa['stato'] === 'aperta' && $trattativa['archiviata_il'] === null
            ? ['fase' => $trattativa['fase_id'], 'persona' => $trattativa['persona_id'], 'organizzazione' => $trattativa['organizzazione_id'], 'importo' => $trattativa['importo'] ?? 0, 'senza_valore' => $trattativa['importo'] === null ? 1 : 0]
            : null;
    }

    /** Trattative::riporta: la differenza fra ciò che la trattativa contava prima e ciò che conta dopo, su fase, contatto e azienda. */
    private function riportaCrm(?array $prima, ?array $dopo): void
    {
        foreach (['fase' => 'fasi', 'persona' => 'persone', 'organizzazione' => 'organizzazioni'] as $chiave => $tipo) {
            $variazioni = [];

            foreach ([[$prima, -1], [$dopo, 1]] as [$contributo, $segno]) {
                $id = $contributo[$chiave] ?? null;

                if ($id !== null) {
                    $variazioni[$id] = [
                        ($variazioni[$id][0] ?? 0) + $segno,
                        ($variazioni[$id][1] ?? 0) + $segno * $contributo['importo'],
                        ($variazioni[$id][2] ?? 0) + ($tipo === 'fasi' ? 0 : $segno * $contributo['senza_valore']),
                    ];
                }
            }

            foreach ($variazioni as $id => [$aperte, $valore, $senzaValore]) {
                if ([$aperte, $valore, $senzaValore] === [0, 0, 0] || ! isset($this->crm[$tipo][$id])) {
                    continue;
                }

                $riga = $this->crm[$tipo][$id];
                $campi = ['trattative_aperte' => $riga['trattative_aperte'] + $aperte, 'valore_aperto' => $riga['valore_aperto'] + $valore];

                if ($tipo === 'fasi') {
                    $this->crm['fasi'][$id] = [...$riga, ...$campi];
                    $this->toccaPipelineCrm($riga['pipeline_id']);
                } else {
                    $this->salvaCrm($tipo, $id, $campi + ['trattative_aperte_senza_valore' => $riga['trattative_aperte_senza_valore'] + $senzaValore]);
                }
            }
        }
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaTrattativaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('trattative', $chi['workspace'], $id);
        $this->valoreTestoCrm($corpo);
        $campi = $this->modificaCrm($corpo, $this->regoleTrattativa($chi['workspace'], Testi::pulisci($corpo), false));

        if (array_key_exists('fase_id', $campi) && ! array_key_exists('pipeline_id', $campi)) {
            throw $this->erroreCrm('fase_id', 'regole.fase_con_la_pipeline', Regola::CAMPO_ESTRANEO);
        }

        if (array_key_exists('valore', $campi)) {
            $campi['importo'] = $campi['valore']['importo'] ?? null;
            unset($campi['valore']);
        }

        foreach (['pipeline_id', 'fase_id', 'persona_id', 'organizzazione_id', 'responsabile_id', 'motivo_perdita_id'] as $campo) {
            if (isset($campi[$campo])) {
                $campi[$campo] = strtolower($campi[$campo]);
            }
        }

        $diventati = $this->applicaTrattativa($riga, $campi, $chi['persona']);

        return [200, ['data' => $this->voceTrattativa($this->crm['trattative'][$riga['id']]) + ['diventati_clienti' => $diventati]]];
    }

    /**
     * TrattativeModifica::applica: dove va la trattativa, i campi che cambiano, i contatori, la cronologia, e i clienti di una vinta.
     *
     * @return array{persona: bool, organizzazione: bool}
     */
    private function applicaTrattativa(array $riga, array $campi, string $autore): array
    {
        $diventati = ['persona' => false, 'organizzazione' => false];
        $destinazione = $campi['pipeline_id'] ?? $riga['pipeline_id'];
        $cambiaPipeline = $destinazione !== $riga['pipeline_id'];

        if ($cambiaPipeline && $this->crm['pipeline'][$destinazione]['archiviata_il'] !== null) {
            throw $this->erroreCrm('pipeline_id', 'regole.pipeline_archiviata', Regola::VALORE_NON_AMMESSO);
        }

        $faseId = $campi['fase_id'] ?? ($cambiaPipeline ? ($this->fasiCrm($destinazione)[0]['id'] ?? null) : $riga['fase_id']);
        $fase = $faseId === null ? null : ($this->crm['fasi'][$faseId] ?? null);

        if ($fase === null || ($fase['eliminata'] ?? false) || $fase['pipeline_id'] !== $destinazione) {
            throw $this->erroreCrm('fase_id', 'regole.fase_della_pipeline', Regola::NON_TROVATO);
        }

        if (($campi['persona_id'] ?? null) !== null && $campi['persona_id'] !== $riga['persona_id']) {
            $this->contattoCollegabileCrm($campi['persona_id']);
        }

        $ora = self::adessoCrm();
        $prima = self::contributoCrm($riga);
        $dopo = $riga;
        $cambi = [];
        $tipi = [];

        foreach (['titolo' => 'titolo', 'valore' => 'importo', 'persona_id' => 'persona_id', 'organizzazione_id' => 'organizzazione_id', 'responsabile_id' => 'responsabile_id', 'probabilita' => 'probabilita', 'chiusura_prevista' => 'chiusura_prevista'] as $campo => $attributo) {
            if (array_key_exists($attributo, $campi) && $campi[$attributo] !== $riga[$attributo]) {
                $cambi[$campo] = ['prima' => $riga[$attributo], 'dopo' => $campi[$attributo]];
                $dopo[$attributo] = $campi[$attributo];
            }
        }

        $stato = $campi['stato'] ?? $riga['stato'];

        if (array_key_exists('motivo_perdita', $campi) && $campi['motivo_perdita'] !== null && $stato !== 'persa') {
            throw $this->erroreCrm('motivo_perdita', 'regole.motivo_solo_se_persa', Regola::CAMPO_VIETATO);
        }

        if (array_key_exists('motivo_perdita_id', $campi) && $campi['motivo_perdita_id'] !== null && $stato !== 'persa') {
            throw $this->erroreCrm('motivo_perdita_id', 'regole.motivo_solo_se_persa', Regola::CAMPO_VIETATO);
        }

        if ($stato !== $riga['stato']) {
            $cambi['stato'] = ['prima' => $riga['stato'], 'dopo' => $stato];
            $dopo['stato'] = $stato;
            $dopo['chiusa_il'] = $stato === 'aperta' ? null : $ora;
        }

        $motivo = $stato === 'persa' ? (array_key_exists('motivo_perdita', $campi) ? $campi['motivo_perdita'] : $riga['motivo_perdita']) : null;

        if ($motivo !== $riga['motivo_perdita']) {
            $cambi['motivo_perdita'] = [];
            $dopo['motivo_perdita'] = $motivo;
        }

        // Il motivo scelto dall'elenco (#1904): la stessa regola della nota libera, e la cronologia ne dice prima e dopo.
        $scelto = $stato === 'persa' ? (array_key_exists('motivo_perdita_id', $campi) ? $campi['motivo_perdita_id'] : ($riga['motivo_perdita_id'] ?? null)) : null;

        if ($scelto !== ($riga['motivo_perdita_id'] ?? null)) {
            $cambi['motivo_perdita_id'] = ['prima' => $riga['motivo_perdita_id'] ?? null, 'dopo' => $scelto];
            $dopo['motivo_perdita_id'] = $scelto;
        }

        if ($destinazione !== $riga['pipeline_id']) {
            $cambi['pipeline_id'] = ['prima' => $riga['pipeline_id'], 'dopo' => $destinazione];
            $dopo['pipeline_id'] = $destinazione;
        }

        if ($fase['id'] !== $riga['fase_id']) {
            $cambi['fase_id'] = ['prima' => $riga['fase_id'], 'dopo' => $fase['id']];
            $dopo['fase_id'] = $fase['id'];
            $dopo['in_fase_dal'] = $ora;
            $dopo['posizione'] = self::inCodaCrm(array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['fase_id'] === $fase['id']));
        }

        if (array_key_exists('archiviata', $campi) && $campi['archiviata'] !== ($riga['archiviata_il'] !== null)) {
            $dopo['archiviata_il'] = $campi['archiviata'] ? $ora : null;
            $cambi['archiviata'] = [];
            $tipi[] = $campi['archiviata'] ? 'archiviata' : 'ripristinata';
        }

        if ($cambi === []) {
            return $diventati;
        }

        $dopo['aggiornata_il'] = self::dopoCrm($riga['aggiornata_il']);
        $this->crm['trattative'][$riga['id']] = $dopo;
        $this->riportaCrm($prima, self::contributoCrm($dopo));

        // Da senza valore a un valore, o il contrario: la fase cambia, e con lei la sua pipeline (TrattativeModifica, Fase::touch).
        if (self::contributoCrm($dopo) !== null && ($riga['importo'] === null) !== ($dopo['importo'] === null)) {
            $this->toccaPipelineCrm($dopo['pipeline_id']);
        }

        $valori = ['valore', 'persona_id', 'organizzazione_id', 'responsabile_id', 'probabilita', 'chiusura_prevista', 'stato', 'motivo_perdita_id', 'pipeline_id', 'fase_id'];
        $voci = [];

        foreach (['titolo', 'valore', 'persona_id', 'organizzazione_id', 'responsabile_id', 'probabilita', 'chiusura_prevista', 'stato', 'motivo_perdita', 'motivo_perdita_id', 'pipeline_id', 'fase_id'] as $campo) {
            if (isset($cambi[$campo])) {
                $voci[] = in_array($campo, $valori, true) ? ['campo' => $campo, ...$cambi[$campo]] : ['campo' => $campo];
            }
        }

        if ($voci !== []) {
            $this->cambioCrm('trattative', $riga['id'], $autore, 'modificata', $voci);
        }

        foreach ($tipi as $tipo) {
            $this->cambioCrm('trattative', $riga['id'], $autore, $tipo, []);
        }

        // Una trattativa che passa a vinta fa «cliente» il contatto e l'azienda collegati, se erano lead o qualificati (#1798).
        if (($cambi['stato']['dopo'] ?? null) === 'vinta') {
            $persona = $dopo['persona_id'] === null ? null : ($this->crm['persone'][$dopo['persona_id']] ?? null);

            if ($persona !== null && $persona['archiviata_il'] === null && $persona['anonimizzata_il'] === null && in_array($persona['stato'], ['lead', 'qualificato'], true)) {
                $this->applicaPersona($persona['id'], ['stato' => 'cliente'], $autore);
                $diventati['persona'] = true;
            }

            $azienda = $dopo['organizzazione_id'] === null ? null : ($this->crm['organizzazioni'][$dopo['organizzazione_id']] ?? null);

            if ($azienda !== null && $azienda['archiviata_il'] === null && in_array($azienda['stato'], ['lead', 'qualificato'], true)) {
                $this->applicaOrganizzazione($azienda['id'], ['stato' => 'cliente'], $autore);
                $diventati['organizzazione'] = true;
            }
        }

        return $diventati;
    }

    /**
     * TrattativeModifica::sposta: in una fase della sua pipeline, dopo la trattativa `dopo` (null = in cima, senza = in fondo). Una
     * vinta, persa o archiviata è 409 transizione_non_valida; una `dopo` che non è della fase, archiviata o la stessa, 409
     * posizione_cambiata. Lo stesso spostamento ripetuto non scrive niente; un cambio di fase va nei riassunti e nella cronologia.
     *
     * @return array{int, array<string, mixed>}
     */
    private function spostaTrattativaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('trattative', $chi['workspace'], $id);
        $campi = $this->testi->validaCorpo($corpo, [
            'fase_id' => ['bail', 'required', 'string', 'max:64', function (string $campo, mixed $fase, Closure $fail) use ($riga) {
                $trovata = $this->crm['fasi'][strtolower((string) $fase)] ?? null;

                if ($trovata === null || ($trovata['eliminata'] ?? false) || $trovata['pipeline_id'] !== $riga['pipeline_id']) {
                    $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, 'regole.fase_della_pipeline');
                }
            }],
            'dopo' => ['nullable', 'string', 'max:64'],
        ]);
        $fase = strtolower($campi['fase_id']);
        $conDopo = array_key_exists('dopo', $campi);
        $dopo = isset($campi['dopo']) ? strtolower($campi['dopo']) : null;

        if (self::contributoCrm($riga) === null) {
            throw new Problema('transizione_non_valida');
        }

        if ($dopo !== null) {
            $ancora = $this->crm['trattative'][$dopo] ?? null;

            if ($ancora === null || $ancora['workspace'] !== $chi['workspace'] || $ancora['fase_id'] !== $fase || $ancora['id'] === $riga['id'] || $ancora['archiviata_il'] !== null) {
                throw new Problema('posizione_cambiata');
            }
        }

        $cambiaFase = $fase !== $riga['fase_id'];
        $inFondo = ! $conDopo;
        $nellaFase = fn (string $quale) => array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['fase_id'] === $quale);

        if (! $cambiaFase && self::giaLiCrm($nellaFase($fase), $riga['id'], $dopo, $inFondo)) {
            return [200, ['data' => $this->voceTrattativa($riga)]];
        }

        $spostata = $riga;

        if ($cambiaFase) {
            $spostata['fase_id'] = $fase;
            $spostata['in_fase_dal'] = self::adessoCrm();
        }

        $spostata['aggiornata_il'] = self::dopoCrm($riga['aggiornata_il']);
        $this->crm['trattative'][$riga['id']] = $spostata;
        $this->collocaCrm('trattative', $nellaFase($fase), $riga['id'], $dopo, $inFondo);

        if ($cambiaFase) {
            $this->riportaCrm(self::contributoCrm($riga), self::contributoCrm($spostata));
            $this->cambioCrm('trattative', $riga['id'], $chi['persona'], 'modificata', [['campo' => 'fase_id', 'prima' => $riga['fase_id'], 'dopo' => $fase]]);
        }

        return [200, ['data' => $this->voceTrattativa($this->crm['trattative'][$riga['id']])]];
    }

    // ———————————————————————————————————————— Attività

    /** @return array<string, mixed> lo schema AttivitaDelCrm (Forme::attivitaDelCrm) */
    private function voceAttivita(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'tipo' => $riga['tipo'],
            'titolo' => $riga['titolo'],
            'note' => $riga['note'],
            'scadenza' => $riga['scadenza'],
            'ora' => $riga['ora'],
            'fuso_orario' => $riga['fuso_orario'],
            'responsabile_id' => $riga['responsabile_id'],
            'persona_id' => $riga['persona_id'],
            'organizzazione_id' => $riga['organizzazione_id'],
            'trattativa_id' => $riga['trattativa_id'],
            'fatta' => $riga['fatta'],
            'fatta_il' => self::isoCrm($riga['fatta_il']),
            'svolta_il' => self::isoCrm($riga['svolta_il']),
            'esito' => $riga['esito'],
            'durata_minuti' => $riga['durata_minuti'],
            'creata_da' => $riga['creata_da'],
            'creata_il' => self::iso($riga['creata_il']),
            'aggiornata_il' => self::iso($riga['aggiornata_il']),
        ];
    }

    /**
     * Le regole dei tre collegamenti di un'attività (AttivitaComune::regoleDeiCollegamenti): nella creazione ne serve almeno uno.
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDeiCollegamentiCrm(string $workspace, bool $obbligo): array
    {
        return [
            'persona_id' => array_merge(['bail'], $obbligo ? ['required_without_all:organizzazione_id,trattativa_id'] : [], ['nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'persone', 'regole.contatto_non_esiste')]),
            'organizzazione_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'organizzazioni', 'regole.azienda_non_esiste')],
            'trattativa_id' => ['bail', 'nullable', 'string', 'max:64', $this->regolaCheEsiste($workspace, 'trattative', 'validation.exists', true)],
        ];
    }

    /** La regola di `svolta_il` (AttivitaComune::regolaDellaSvolta): un istante ISO 8601 in UTC, non nel futuro. */
    private function regolaDellaSvolta(): Closure
    {
        return function (string $campo, mixed $valore, Closure $fail) {
            $istante = self::istanteCrm((string) $valore);

            if ($istante === null) {
                $this->rifiutaCrm($fail, $campo, Regola::FORMATO, 'validation.date', ['attribute' => $campo]);
            } elseif ($istante->greaterThan(CarbonImmutable::now())) {
                $this->rifiutaCrm($fail, $campo, Regola::FUORI_INTERVALLO, 'validation.before_or_equal', ['attribute' => $campo, 'date' => self::iso(CarbonImmutable::now())]);
            }
        };
    }

    /** Un istante ISO 8601 in UTC, al millisecondo (AttivitaComune::istante); null se non lo è. */
    private static function istanteCrm(string $testo): ?CarbonImmutable
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,3})?Z$/', $testo) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($testo)->utc()->startOfMillisecond();
        } catch (\Throwable) {
            return null;
        }
    }

    /** La regola di un campo che vale solo con `fatta: true` (`esito`, `durata_minuti`, `svolta_il`). */
    private function soloSeFattaCrm(Closure $vieta): Closure
    {
        return function (string $campo, mixed $valore, Closure $fail) use ($vieta) {
            if ($vieta()) {
                $this->rifiutaCrm($fail, $campo, Regola::CAMPO_VIETATO, 'validation.prohibited', ['attribute' => $campo]);
            }
        };
    }

    /** @return array{int, array<string, mixed>, array<string, string>} */
    private function creaAttivitaCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.attivita.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $pulito = Testi::pulisci($corpo);
            $fatta = $this->soloSeFattaCrm(fn () => ($pulito['fatta'] ?? null) !== true);
            $campi = $this->testi->validaCorpo($corpo, [
                'tipo' => ['bail', 'required', 'string', Rule::in(self::CRM_TIPI_ATTIVITA)],
                'titolo' => ['bail', 'required', 'string', 'min:1', 'max:200', new NomeScritto],
                'note' => ['bail', 'nullable', 'string', 'max:5000', new SenzaCarattereNullo],
                'scadenza' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
                'ora' => ['bail', 'nullable', 'string', 'date_format:H:i', function (string $campo, mixed $ora, Closure $fail) use ($pulito) {
                    if (($pulito['scadenza'] ?? null) === null) {
                        $this->rifiutaCrm($fail, $campo, Regola::CAMPO_VIETATO, 'validation.prohibited', ['attribute' => $campo]);
                    }
                }],
                'fuso_orario' => ['bail', 'required_with:ora', 'nullable', 'string', 'max:64', function (string $campo, mixed $fuso, Closure $fail) use ($pulito) {
                    if (($pulito['ora'] ?? null) === null) {
                        $this->rifiutaCrm($fail, $campo, Regola::CAMPO_VIETATO, 'validation.prohibited', ['attribute' => $campo]);
                    } elseif (! in_array($fuso, DateTimeZone::listIdentifiers(), true)) {
                        $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'validation.timezone', ['attribute' => $campo]);
                    }
                }],
                'responsabile_id' => $this->regolaDelResponsabile($chi['workspace']),
                ...$this->regoleDeiCollegamentiCrm($chi['workspace'], true),
                'fatta' => ['nullable', 'boolean:strict'],
                'svolta_il' => ['bail', 'nullable', 'string', $fatta, $this->regolaDellaSvolta()],
                'esito' => ['bail', 'nullable', 'string', 'max:500', new SenzaCarattereNullo, $fatta],
                'durata_minuti' => ['bail', 'nullable', 'integer:strict', 'between:1,1440', $fatta],
            ]);
            $id = $this->nasceAttivitaCrm($chi, $campi);

            return [201, ['data' => $this->voceAttivita($this->crm['attivita'][$id])], ['Location' => "/v1/crm/attivita/{$id}"]];
        });
    }

    /** AttivitaComune::scrivi: i tetti, i collegamenti, la riga, e i riassunti di contatto e azienda. */
    private function nasceAttivitaCrm(array $chi, array $campi): string
    {
        $tutte = array_filter($this->crm['attivita'], fn (array $attivita) => $attivita['workspace'] === $chi['workspace']);

        if (count($tutte) >= self::CRM_TETTI['attivita']) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['attivita']]);
        }

        $fatta = $campi['fatta'] ?? false;

        if (! $fatta && count(array_filter($tutte, fn (array $attivita) => ! $attivita['fatta'] && ! ($attivita['eliminata'] ?? false))) >= self::CRM_TETTI['attivita_non_fatte']) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['attivita_non_fatte']]);
        }

        $persona = isset($campi['persona_id']) ? strtolower($campi['persona_id']) : null;
        $this->contattoCollegabileCrm($persona);
        $id = self::id();
        $ora = self::adessoCrm();
        $svolta = isset($campi['svolta_il']) ? self::istanteCrm($campi['svolta_il']) : null;
        $this->crm['attivita'][$id] = [
            'id' => $id, 'workspace' => $chi['workspace'], 'tipo' => $campi['tipo'], 'titolo' => $campi['titolo'], 'note' => $campi['note'] ?? null,
            'scadenza' => $campi['scadenza'] ?? null, 'ora' => $campi['ora'] ?? null, 'fuso_orario' => $campi['fuso_orario'] ?? null,
            'responsabile_id' => isset($campi['responsabile_id']) ? strtolower($campi['responsabile_id']) : $chi['persona'],
            'persona_id' => $persona,
            'organizzazione_id' => isset($campi['organizzazione_id']) ? strtolower($campi['organizzazione_id']) : null,
            'trattativa_id' => isset($campi['trattativa_id']) ? strtolower($campi['trattativa_id']) : null,
            'fatta' => $fatta, 'fatta_il' => $fatta ? $ora : null, 'svolta_il' => $fatta ? ($svolta ?? $ora) : null,
            'esito' => $campi['esito'] ?? null, 'durata_minuti' => $campi['durata_minuti'] ?? null,
            'creata_da' => $chi['persona'], 'creata_il' => $ora, 'aggiornata_il' => $ora,
        ];
        $this->riassuntiAttivitaCrm(null, $this->crm['attivita'][$id]);

        return $id;
    }

    /**
     * I riassunti di un'attività scritta (AttivitaModifica::riassunti): `ultima_attivita_il` di contatto e azienda di adesso cresce fino
     * all'istante dell'attività (la svolta se è fatta, la nascita se no) e non torna indietro; la `prossima_scadenza` di ogni contatto di
     * prima e di dopo si ricalcola.
     */
    private function riassuntiAttivitaCrm(?array $prima, array $dopo): void
    {
        $istante = $dopo['fatta'] ? $dopo['svolta_il'] : $dopo['creata_il'];

        foreach (array_unique(array_filter([$prima['persona_id'] ?? null, $dopo['persona_id']])) as $id) {
            if (! isset($this->crm['persone'][$id])) {
                continue;
            }

            $campi = ['prossima_scadenza' => $this->prossimaScadenzaCrm($id)];
            $ultima = $this->crm['persone'][$id]['ultima_attivita_il'];

            if ($id === $dopo['persona_id'] && $istante !== null && ($ultima === null || $ultima->lessThan($istante))) {
                $campi['ultima_attivita_il'] = $istante;
            }

            $this->salvaCrm('persone', $id, $campi);
        }

        foreach (array_unique(array_filter([$prima['organizzazione_id'] ?? null, $dopo['organizzazione_id']])) as $id) {
            $ultima = $this->crm['organizzazioni'][$id]['ultima_attivita_il'] ?? null;

            if (isset($this->crm['organizzazioni'][$id]) && $id === $dopo['organizzazione_id'] && $istante !== null && ($ultima === null || $ultima->lessThan($istante))) {
                $this->salvaCrm('organizzazioni', $id, ['ultima_attivita_il' => $istante]);
            }
        }
    }

    /** La scadenza più vicina fra le attività non fatte e non eliminate di un contatto, o null. */
    private function prossimaScadenzaCrm(string $persona): ?string
    {
        $scadenze = array_filter(array_map(
            fn (array $attivita) => $attivita['persona_id'] === $persona && ! $attivita['fatta'] && ! ($attivita['eliminata'] ?? false) ? $attivita['scadenza'] : null,
            $this->crm['attivita'],
        ));

        return $scadenze === [] ? null : min($scadenze);
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaAttivitaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('attivita', $chi['workspace'], $id);
        $pulito = Testi::pulisci($corpo);
        $soloSeFatta = $this->soloSeFattaCrm(fn () => ($pulito['fatta'] ?? null) === false || (($pulito['fatta'] ?? null) !== true && ! $riga['fatta']));
        $campi = $this->modificaCrm($corpo, [
            'tipo' => ['bail', 'filled', 'string', Rule::in(self::CRM_TIPI_ATTIVITA)],
            'titolo' => ['bail', 'filled', 'string', 'max:200', new NomeScritto],
            'note' => ['bail', 'nullable', 'string', 'max:5000', new SenzaCarattereNullo],
            'scadenza' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            'ora' => ['bail', 'nullable', 'string', 'date_format:H:i'],
            'fuso_orario' => ['bail', 'nullable', 'string', 'max:64', function (string $campo, mixed $fuso, Closure $fail) {
                if (! in_array($fuso, DateTimeZone::listIdentifiers(), true)) {
                    $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'validation.timezone', ['attribute' => $campo]);
                }
            }],
            'responsabile_id' => $this->regolaDelResponsabile($chi['workspace']),
            ...$this->regoleDeiCollegamentiCrm($chi['workspace'], false),
            'fatta' => ['bail', 'filled', 'boolean:strict'],
            'svolta_il' => ['bail', 'filled', 'string', $soloSeFatta, $this->regolaDellaSvolta()],
            'esito' => ['bail', 'nullable', 'string', 'max:500', new SenzaCarattereNullo, $soloSeFatta],
            'durata_minuti' => ['bail', 'nullable', 'integer:strict', 'between:1,1440', $soloSeFatta],
        ]);

        foreach (['responsabile_id', 'persona_id', 'organizzazione_id', 'trattativa_id'] as $campo) {
            if (isset($campi[$campo])) {
                $campi[$campo] = strtolower($campi[$campo]);
            }
        }

        if (isset($campi['svolta_il'])) {
            $campi['svolta_il'] = self::istanteCrm($campi['svolta_il']);
        }

        if (array_key_exists('scadenza', $campi) && $campi['scadenza'] === null && ! array_key_exists('ora', $campi)) {
            $campi['ora'] = null;
        }

        if (array_key_exists('ora', $campi) && $campi['ora'] === null && ! array_key_exists('fuso_orario', $campi)) {
            $campi['fuso_orario'] = null;
        }

        $this->verificaAttivitaCrm($campi, $riga);
        $this->applicaAttivitaCrm($riga, $campi, $chi);

        return [200, ['data' => $this->voceAttivita($this->crm['attivita'][$riga['id']])]];
    }

    /** AttivitaModifica::verifica: l'orario e almeno un collegamento, sullo stato che ne esce. */
    private function verificaAttivitaCrm(array $campi, array $stato): void
    {
        $dopo = fn (string $campo): mixed => array_key_exists($campo, $campi) ? $campi[$campo] : $stato[$campo];
        $errori = [];
        $nome = fn (string $campo) => $this->testi->nome($campo);

        if ($dopo('ora') !== null && $dopo('scadenza') === null) {
            $errori[] = ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => $nome('ora')]), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => '#/ora'];
        } elseif ($dopo('ora') !== null && $dopo('fuso_orario') === null) {
            $errori[] = ['detail' => $this->testi->testo('validation.required_with', ['attribute' => $nome('fuso_orario'), 'values' => $nome('ora')]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => '#/fuso_orario'];
        } elseif ($dopo('ora') === null && $dopo('fuso_orario') !== null) {
            $errori[] = ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => $nome('fuso_orario')]), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => '#/fuso_orario'];
        }

        $legami = ['persona_id', 'organizzazione_id', 'trattativa_id'];

        if (array_filter($legami, fn (string $campo) => $dopo($campo) !== null) === []) {
            foreach ($legami as $campo) {
                if (array_key_exists($campo, $campi)) {
                    $altri = implode(', ', array_map($nome, array_values(array_diff($legami, [$campo]))));
                    $errori[] = ['detail' => $this->testi->testo('validation.required_without_all', ['attribute' => $nome($campo), 'values' => $altri]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => "#/{$campo}"];
                }
            }
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }
    }

    /** AttivitaModifica::applica: il completamento o la riapertura, i campi, i riassunti, la riga della riapertura. */
    private function applicaAttivitaCrm(array $riga, array $campi, array $chi): void
    {
        $riapre = ($campi['fatta'] ?? null) === false && $riga['fatta'];

        if ($riapre && count(array_filter($this->righeCrm('attivita', $chi['workspace']), fn (array $attivita) => ! $attivita['fatta'])) >= self::CRM_TETTI['attivita_non_fatte']) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['attivita_non_fatte']]);
        }

        if (($campi['persona_id'] ?? null) !== null && $campi['persona_id'] !== $riga['persona_id']) {
            $this->contattoCollegabileCrm($campi['persona_id']);
        }

        if ($riga['persona_id'] !== null && array_intersect(['titolo', 'note', 'esito', 'trattativa_id', 'organizzazione_id'], array_keys($campi)) !== []
            && ($this->crm['persone'][$riga['persona_id']]['anonimizzata_il'] ?? null) !== null) {
            throw new Problema('persona_anonimizzata');
        }

        $ora = self::adessoCrm();
        $dopo = $riga;

        foreach (['tipo', 'titolo', 'note', 'scadenza', 'ora', 'fuso_orario', 'responsabile_id', 'persona_id', 'organizzazione_id', 'trattativa_id', 'esito', 'durata_minuti'] as $campo) {
            if (array_key_exists($campo, $campi)) {
                $dopo[$campo] = $campi[$campo];
            }
        }

        if (($campi['fatta'] ?? null) === true && ! $riga['fatta']) {
            $dopo['fatta'] = true;
            $dopo['fatta_il'] = $ora;
            $dopo['svolta_il'] = $campi['svolta_il'] ?? $ora;
        } elseif ($riapre) {
            $dopo = [...$dopo, 'fatta' => false, 'fatta_il' => null, 'svolta_il' => null, 'esito' => null, 'durata_minuti' => null];
        } elseif (array_key_exists('svolta_il', $campi)) {
            $dopo['svolta_il'] = $campi['svolta_il'];
        }

        $cambiata = false;

        foreach ($dopo as $campo => $valore) {
            $cambiata = $cambiata || ! self::ugualeCrm($riga[$campo], $valore);
        }

        if (! $cambiata) {
            return;
        }

        $dopo['aggiornata_il'] = self::dopoCrm($riga['aggiornata_il']);
        $this->crm['attivita'][$riga['id']] = $dopo;
        $this->riassuntiAttivitaCrm($riga, $dopo);

        if ($riapre) {
            foreach (['persona_id' => 'persone', 'organizzazione_id' => 'organizzazioni', 'trattativa_id' => 'trattative'] as $campo => $tipo) {
                if ($dopo[$campo] !== null) {
                    $this->cambioCrm($tipo, $dopo[$campo], $chi['persona'], 'attivita.riaperta', [], attivita: $dopo['id']);
                }
            }
        }
    }

    /**
     * AttivitaController::elimina: l'attività passa nell'archivio di sistema e da lì è 404; la prossima scadenza del contatto si
     * ricalcola, l'ultima attività no. Risponde 204.
     *
     * @return array{int, null}
     */
    private function eliminaAttivitaCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $attivita = $this->rigaCrm('attivita', $chi['workspace'], $id);
        $this->crm['attivita'][$attivita['id']]['eliminata'] = true;

        if ($attivita['persona_id'] !== null && isset($this->crm['persone'][$attivita['persona_id']])) {
            $this->salvaCrm('persone', $attivita['persona_id'], ['prossima_scadenza' => $this->prossimaScadenzaCrm($attivita['persona_id'])]);
        }

        return [204, null];
    }

    // ———————————————————————————————————————— Note

    /** @return array<string, mixed> lo schema Nota (Forme::nota) */
    private static function voceNota(array $riga): array
    {
        return [
            'id' => $riga['id'], 'persona_id' => $riga['persona_id'], 'organizzazione_id' => $riga['organizzazione_id'], 'trattativa_id' => $riga['trattativa_id'],
            'testo' => $riga['testo'], 'creata_da' => $riga['creata_da'], 'creata_il' => self::iso($riga['creata_il']), 'aggiornata_il' => self::iso($riga['aggiornata_il']),
        ];
    }

    /**
     * Le regole del legame di una nota, nel corpo di crm.note.crea e nella query di crm.note.elenca: uno solo fra contatto, azienda e
     * trattativa, del workspace (Rule::exists del backoffice).
     *
     * @return array<string, list<mixed>>
     */
    private function regoleDelLegameCrm(string $workspace): array
    {
        $esiste = fn (string $tipo) => function (string $campo, mixed $id, Closure $fail) use ($workspace, $tipo) {
            $riga = is_string($id) ? ($this->crm[$tipo][$id] ?? null) : null;

            if ($riga === null || $riga['workspace'] !== $workspace || ($riga['eliminata'] ?? false)) {
                $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, 'validation.exists', ['attribute' => $campo]);
            }
        };

        return [
            'persona_id' => ['bail', 'required_without_all:organizzazione_id,trattativa_id', 'string', $esiste('persone')],
            'organizzazione_id' => ['bail', 'prohibits:persona_id', 'string', $esiste('organizzazioni')],
            'trattativa_id' => ['bail', 'prohibits:persona_id,organizzazione_id', 'string', $esiste('trattative')],
        ];
    }

    /** @return array{int, array<string, mixed>} */
    private function creaNotaCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        return $this->conIdempotenza($richiesta, 'crm.note.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, [
                ...$this->regoleDelLegameCrm($chi['workspace']),
                'testo' => ['bail', 'required', 'string', 'max:10000', new SenzaCarattereNullo],
            ]);

            if (count(array_filter($this->crm['note'], fn (array $nota) => $nota['workspace'] === $chi['workspace'] && ! ($nota['eliminata'] ?? false))) >= self::CRM_TETTI['note']) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_TETTI['note']]);
            }

            $legami = array_map(fn (string $campo) => isset($campi[$campo]) ? strtolower($campi[$campo]) : null, ['persona_id' => 'persona_id', 'organizzazione_id' => 'organizzazione_id', 'trattativa_id' => 'trattativa_id']);
            $this->contattoCollegabileCrm($legami['persona_id']);
            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['note'][$id] = ['id' => $id, 'workspace' => $chi['workspace'], ...$legami, 'testo' => $campi['testo'], 'creata_da' => $chi['persona'], 'creata_il' => $ora, 'aggiornata_il' => $ora];

            // Una trattativa non ha `ultima_attivita_il`: la sua nota non tocca né il contatto né l'azienda.
            if ($legami['persona_id'] !== null) {
                $this->salvaCrm('persone', $legami['persona_id'], ['ultima_attivita_il' => $ora]);
            } elseif ($legami['organizzazione_id'] !== null) {
                $this->salvaCrm('organizzazioni', $legami['organizzazione_id'], ['ultima_attivita_il' => $ora]);
            }

            return [201, ['data' => self::voceNota($this->crm['note'][$id])], []];
        });
    }

    /** @return array{int, array<string, mixed>} */
    private function modificaNotaCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $nota = $this->rigaCrm('note', $chi['workspace'], $id);

        if ($nota['creata_da'] !== $chi['persona']) {
            throw new Problema('permesso_negato');
        }

        $campi = $this->modificaCrm($corpo, ['testo' => ['bail', 'filled', 'string', 'max:10000', new SenzaCarattereNullo]]);

        if ($nota['persona_id'] !== null && ($this->crm['persone'][$nota['persona_id']]['anonimizzata_il'] ?? null) !== null) {
            throw new Problema('persona_anonimizzata');
        }

        $this->salvaCrm('note', $nota['id'], ['testo' => $campi['testo']]);

        return [200, ['data' => self::voceNota($this->crm['note'][$nota['id']])]];
    }

    /**
     * crm.note.elenca: le note di un contatto, di un'azienda o di una trattativa (uno solo, nella query), dalla più recente (l'id).
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaNoteCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), $this->regoleDelLegameCrm($chi['workspace']));
        [$campo, $id] = match (true) {
            isset($query['organizzazione_id']) => ['organizzazione_id', strtolower($query['organizzazione_id'])],
            isset($query['trattativa_id']) => ['trattativa_id', strtolower($query['trattativa_id'])],
            default => ['persona_id', strtolower((string) $query['persona_id'])],
        };
        $righe = array_values(array_filter($this->righeCrm('note', $chi['workspace']), fn (array $nota) => $nota[$campo] === $id));

        return $this->paginaCrm($richiesta, "crm.note.elenca:{$campo}:{$id}", $righe, fn (array $una, array $altra) => strcmp($altra['id'], $una['id']), self::voceNota(...));
    }

    /**
     * NoteController::elimina: solo l'autore (403 permesso_negato); la nota passa nell'archivio di sistema e da lì è 404. Risponde 204.
     *
     * @return array{int, null}
     */
    private function eliminaNotaCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $nota = $this->rigaCrm('note', $chi['workspace'], $id);

        if ($nota['creata_da'] !== $chi['persona']) {
            throw new Problema('permesso_negato');
        }

        $this->crm['note'][$nota['id']]['eliminata'] = true;

        return [204, null];
    }

    // ———————————————————————————————————————— Impostazioni e utilizzo

    /** @return array{int, array<string, mixed>} */
    private function modificaImpostazioniCrm(Request $richiesta, array $corpo, array $ruoli): array
    {
        $chi = $this->conCrm($richiesta, $ruoli);
        $campi = $this->modificaCrm($corpo, [
            'valuta' => ['bail', 'filled', 'string', Rule::in(self::CRM_VALUTE)],
            'senza_conversione' => ['filled', 'boolean:strict'],
        ]);
        $corrente = $this->valutaCrm($chi['workspace']);
        $nuova = $campi['valuta'] ?? $corrente;

        if ($nuova !== $corrente) {
            $conValori = array_filter($this->crm['trattative'], fn (array $trattativa) => $trattativa['workspace'] === $chi['workspace'] && ($trattativa['importo'] ?? 0) > 0);

            if (($campi['senza_conversione'] ?? false) !== true && $conValori !== []) {
                throw new Problema('valuta_in_uso');
            }

            $this->crm['valute'][$chi['workspace']] = $nuova;
        }

        return [200, ['data' => ['valuta' => $nuova]]];
    }

    /**
     * crm.utilizzo.mostra (UtilizzoController::mostra): un elemento per tetto, coi posti usati fermati al tetto. Le trattative aperte e
     * le fasi, una voce per pipeline non archiviata, per nome e poi per id.
     *
     * @return array{int, array<string, mixed>}
     */
    private function utilizzoCrm(Request $richiesta): array
    {
        $workspace = $this->conCrm($richiesta)['workspace'];
        $conta = fn (string $tipo, ?Closure $filtro = null, bool $eliminate = false) => count(array_filter(
            $this->crm[$tipo],
            fn (array $riga) => $riga['workspace'] === $workspace && ($eliminate || ! ($riga['eliminata'] ?? false)) && ($filtro === null || $filtro($riga)),
        ));
        $voce = fn (string $tetto, int $usati, ?string $pipeline = null) => ['tetto' => $tetto, 'limite' => self::CRM_TETTI[$tetto], 'usati' => min($usati, self::CRM_TETTI[$tetto]), 'pipeline_id' => $pipeline];
        $pipeline = array_values(array_filter($this->righeCrm('pipeline', $workspace), fn (array $riga) => $riga['archiviata_il'] === null));
        usort($pipeline, self::ordineCrm('nome'));
        $aperte = fn (string $id) => $conta('trattative', fn (array $trattativa) => $trattativa['pipeline_id'] === $id && $trattativa['stato'] === 'aperta' && $trattativa['archiviata_il'] === null);

        return [200, ['data' => [
            $voce('trattative', $conta('trattative')),
            ...array_map(fn (array $riga) => $voce('trattative_aperte_per_pipeline', $aperte($riga['id']), $riga['id']), $pipeline),
            $voce('attivita_non_fatte', $conta('attivita', fn (array $attivita) => ! $attivita['fatta'])),
            $voce('attivita', $conta('attivita', eliminate: true)),
            $voce('persone', $conta('persone')),
            $voce('organizzazioni', $conta('organizzazioni')),
            $voce('note', $conta('note')),
            $voce('pipeline', $conta('pipeline')),
            ...array_map(fn (array $riga) => $voce('fasi_per_pipeline', count($this->fasiCrm($riga['id'])), $riga['id']), $pipeline),
        ]]];
    }
}
