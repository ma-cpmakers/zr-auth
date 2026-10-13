<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use DateTimeZone;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Il CRM del backoffice finto (#1856, G67): aziende (`organizzazioni`), contatti (`persone`), trattative, pipeline e fasi, attività,
 * note, impostazioni e utilizzo, in memoria e per workspace, con le forme del contratto, i codici del catalogo e le regole dei corpi
 * del backoffice (`app/Http/Controllers/Crm`). Un metodo `crm.*` vuole l'app `crm` attiva nel workspace del gettone (attivaApp()):
 * prima il ruolo (403 permesso_negato), poi l'app (403 app_non_attiva), poi la risorsa del percorso (404 non_trovato, anche per
 * quella di un altro workspace), poi il corpo (422). Ciò che il finto non imita è nel README.
 */
trait Crm
{
    /** I metodi del CRM: verbo, percorso, operationId. I percorsi fissi (`ricerca`, `importazioni`, `partenza`) prima di quelli con un id. */
    private const METODI_CRM = [
        ['GET', '#^/v1/crm/persone$#', 'crm.persone.elenca'],
        ['POST', '#^/v1/crm/persone$#', 'crm.persone.crea'],
        ['GET', '#^/v1/crm/persone/([^/]+)$#', 'crm.persone.mostra'],
        ['PATCH', '#^/v1/crm/persone/([^/]+)$#', 'crm.persone.modifica'],
        ['GET', '#^/v1/crm/organizzazioni$#', 'crm.organizzazioni.elenca'],
        ['POST', '#^/v1/crm/organizzazioni$#', 'crm.organizzazioni.crea'],
        ['GET', '#^/v1/crm/organizzazioni/([^/]+)$#', 'crm.organizzazioni.mostra'],
        ['PATCH', '#^/v1/crm/organizzazioni/([^/]+)$#', 'crm.organizzazioni.modifica'],
        ['GET', '#^/v1/crm/pipeline$#', 'crm.pipeline.elenca'],
        ['POST', '#^/v1/crm/pipeline$#', 'crm.pipeline.crea'],
        ['GET', '#^/v1/crm/pipeline/([^/]+)$#', 'crm.pipeline.mostra'],
        ['PATCH', '#^/v1/crm/pipeline/([^/]+)$#', 'crm.pipeline.modifica'],
        ['POST', '#^/v1/crm/fasi$#', 'crm.fasi.crea'],
        ['PATCH', '#^/v1/crm/fasi/([^/]+)$#', 'crm.fasi.modifica'],
        ['GET', '#^/v1/crm/trattative$#', 'crm.trattative.elenca'],
        ['POST', '#^/v1/crm/trattative$#', 'crm.trattative.crea'],
        ['GET', '#^/v1/crm/trattative/([^/]+)$#', 'crm.trattative.mostra'],
        ['PATCH', '#^/v1/crm/trattative/([^/]+)$#', 'crm.trattative.modifica'],
        ['GET', '#^/v1/crm/attivita$#', 'crm.attivita.elenca'],
        ['POST', '#^/v1/crm/attivita$#', 'crm.attivita.crea'],
        ['GET', '#^/v1/crm/attivita/([^/]+)$#', 'crm.attivita.mostra'],
        ['PATCH', '#^/v1/crm/attivita/([^/]+)$#', 'crm.attivita.modifica'],
        ['GET', '#^/v1/crm/note$#', 'crm.note.elenca'],
        ['POST', '#^/v1/crm/note$#', 'crm.note.crea'],
        ['PATCH', '#^/v1/crm/note/([^/]+)$#', 'crm.note.modifica'],
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

    /** I tetti di crm.utilizzo.mostra (config zeiras.crm e le costanti dei controller), nell'ordine della risposta. */
    private const CRM_TETTI = [
        'trattative' => 25000, 'trattative_aperte_per_pipeline' => 5000, 'attivita_non_fatte' => 10000, 'attivita' => 50000,
        'persone' => 25000, 'organizzazioni' => 10000, 'note' => 50000, 'pipeline' => 10, 'fasi_per_pipeline' => 20,
    ];

    /** Il passo fra due posizioni vicine (Posizioni::PASSO). */
    private const CRM_PASSO = 1024;

    /**
     * Lo stato del CRM, per tipo e per id: ogni riga porta il suo `workspace`. Le righe eliminate (fasi, attività, note) restano con
     * `eliminata`, come nell'archivio di sistema; le `cambi` sono le righe delle cronologie di contatti, aziende e trattative.
     *
     * @var array{organizzazioni: array<string, array<string, mixed>>, persone: array<string, array<string, mixed>>, trattative: array<string, array<string, mixed>>, pipeline: array<string, array<string, mixed>>, fasi: array<string, array<string, mixed>>, attivita: array<string, array<string, mixed>>, note: array<string, array<string, mixed>>, cambi: list<array<string, mixed>>, valute: array<string, string>}
     */
    private array $crm = ['organizzazioni' => [], 'persone' => [], 'trattative' => [], 'pipeline' => [], 'fasi' => [], 'attivita' => [], 'note' => [], 'cambi' => [], 'valute' => []];

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
            'crm.persone.crea' => $this->creaPersonaCrm($richiesta, $corpo),
            'crm.persone.mostra' => $this->mostraCrm($richiesta, 'persone', $parametri[0]),
            'crm.persone.modifica' => $this->modificaPersonaCrm($richiesta, $corpo, $parametri[0]),
            'crm.persone.elenca' => $this->elencaCrm($richiesta, 'persone', $operazione),
            'crm.pipeline.crea' => $this->creaPipelineCrm($richiesta, $corpo),
            'crm.pipeline.mostra' => $this->mostraCrm($richiesta, 'pipeline', $parametri[0]),
            'crm.pipeline.modifica' => $this->modificaPipelineCrm($richiesta, $corpo, $parametri[0]),
            'crm.pipeline.elenca' => $this->elencaPipelineCrm($richiesta),
            'crm.fasi.crea' => $this->creaFaseCrm($richiesta, $corpo),
            'crm.fasi.modifica' => $this->modificaFaseCrm($richiesta, $corpo, $parametri[0]),
            'crm.trattative.crea' => $this->creaTrattativaCrm($richiesta, $corpo),
            'crm.trattative.mostra' => $this->mostraCrm($richiesta, 'trattative', $parametri[0]),
            'crm.trattative.modifica' => $this->modificaTrattativaCrm($richiesta, $corpo, $parametri[0]),
            'crm.trattative.elenca' => $this->elencaCrm($richiesta, 'trattative', $operazione),
            'crm.attivita.crea' => $this->creaAttivitaCrm($richiesta, $corpo),
            'crm.attivita.mostra' => $this->mostraCrm($richiesta, 'attivita', $parametri[0]),
            'crm.attivita.modifica' => $this->modificaAttivitaCrm($richiesta, $corpo, $parametri[0]),
            'crm.attivita.elenca' => $this->elencaCrm($richiesta, 'attivita', $operazione),
            'crm.note.crea' => $this->creaNotaCrm($richiesta, $corpo),
            'crm.note.modifica' => $this->modificaNotaCrm($richiesta, $corpo, $parametri[0]),
            'crm.note.elenca' => $this->elencaNoteCrm($richiesta),
            'crm.impostazioni.mostra' => [200, ['data' => ['valuta' => $this->valutaCrm($this->conCrm($richiesta)['workspace'])]]],
            'crm.impostazioni.modifica' => $this->modificaImpostazioniCrm($richiesta, $corpo, $amministra),
            'crm.utilizzo.mostra' => $this->utilizzoCrm($richiesta),
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
            $riga[$tipo === 'persone' ? 'aggiornato_il' : 'aggiornata_il'] = self::adessoCrm();
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

            $ordine = $a === null ? 0 : ($discendente ? -1 : 1) * ($a <=> $b);

            return $ordine !== 0 ? $ordine : strcmp($una['id'], $altra['id']);
        };
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
     * Un elenco di aziende, contatti, trattative o attività, nell'ordine predefinito del backoffice: le aziende e i contatti per nome,
     * le trattative per posizione, le attività per scadenza; le archiviate e le eliminate no.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCrm(Request $richiesta, string $tipo, string $lista): array
    {
        $chi = $this->conCrm($richiesta);
        $righe = array_values(array_filter($this->righeCrm($tipo, $chi['workspace']), fn (array $riga) => ($riga['archiviata_il'] ?? null) === null));
        [$campo, $voce] = match ($tipo) {
            'organizzazioni' => ['nome', fn (array $riga) => $this->voceOrganizzazione($riga)],
            'persone' => ['nome', fn (array $riga) => $this->vocePersona($riga)],
            'trattative' => ['posizione', fn (array $riga) => $this->voceTrattativa($riga)],
            default => ['scadenza', fn (array $riga) => $this->voceAttivita($riga)],
        };

        return $this->paginaCrm($richiesta, $lista, $righe, self::ordineCrm($campo), $voce);
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
            'persone' => $this->vocePersona($riga),
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
    private function applicaOrganizzazione(string $id, array $campi, string $autore): void
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
            $this->cambioCrm('organizzazioni', $id, $autore, $tipo, []);
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

    /** @return array<string, mixed> lo schema Persona (Forme::persona) */
    private function vocePersona(array $riga): array
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
            $campi = $this->nuovaPersonaCrm($this->testi->validaCorpo($corpo, $this->regolePersona($chi['workspace'])), $chi['persona']);

            foreach ($campi['email'] as $voce) {
                $presente = $this->personaConEmailCrm($chi['workspace'], $voce['indirizzo']);

                if ($presente !== null) {
                    throw new Problema('email_gia_presente', estensioni: ['persona_id' => $presente]);
                }
            }

            $id = $this->nascePersonaCrm($chi, $campi);

            return [201, ['data' => $this->vocePersona($this->crm['persone'][$id])], ['Location' => "/v1/crm/persone/{$id}"]];
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
        $campi = $this->modificaCrm($corpo, [...$this->regolePersona($chi['workspace']), 'archiviato' => ['filled', 'boolean:strict']]);

        if ($riga['anonimizzata_il'] !== null) {
            throw new Problema('persona_anonimizzata');
        }

        $this->applicaPersona($riga['id'], $campi, $chi['persona']);

        return [200, ['data' => $this->vocePersona($this->crm['persone'][$riga['id']])]];
    }

    /** PersoneModifica::applica: i campi che cambiano davvero, le liste sostituite, il conto dei contatti, la cronologia. */
    private function applicaPersona(string $id, array $campi, string $autore): void
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

        if ($cambi === []) {
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

        // Cambiano solo le liste: il contatto si tocca comunque (Registro::segnala).
        if (! $this->salvaCrm('persone', $id, $nuovi)) {
            $this->crm['persone'][$id]['aggiornato_il'] = $ora;
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

        if ($voci !== []) {
            $this->cambioCrm('persone', $id, $autore, 'modificata', $voci);
        }

        foreach ($tipi as $tipo) {
            $this->cambioCrm('persone', $id, $autore, $tipo, []);
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

    /** Una scrittura di una fase tocca la sua pipeline (Fase::$touches). */
    private function toccaPipelineCrm(string $pipeline): void
    {
        $this->crm['pipeline'][$pipeline]['aggiornata_il'] = self::adessoCrm();
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
                'probabilita' => $campi['probabilita'] ?? null, 'in_fase_dal' => $ora, 'stato' => 'aperta', 'motivo_perdita' => null, 'chiusa_il' => null,
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

        foreach (['pipeline_id', 'fase_id', 'persona_id', 'organizzazione_id', 'responsabile_id'] as $campo) {
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

        $dopo['aggiornata_il'] = $ora;
        $this->crm['trattative'][$riga['id']] = $dopo;
        $this->riportaCrm($prima, self::contributoCrm($dopo));

        // Da senza valore a un valore, o il contrario: la fase cambia, e con lei la sua pipeline (TrattativeModifica, Fase::touch).
        if (self::contributoCrm($dopo) !== null && ($riga['importo'] === null) !== ($dopo['importo'] === null)) {
            $this->toccaPipelineCrm($dopo['pipeline_id']);
        }

        $valori = ['valore', 'persona_id', 'organizzazione_id', 'responsabile_id', 'probabilita', 'chiusura_prevista', 'stato', 'pipeline_id', 'fase_id'];
        $voci = [];

        foreach (['titolo', 'valore', 'persona_id', 'organizzazione_id', 'responsabile_id', 'probabilita', 'chiusura_prevista', 'stato', 'motivo_perdita', 'pipeline_id', 'fase_id'] as $campo) {
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

        $dopo['aggiornata_il'] = $ora;
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
