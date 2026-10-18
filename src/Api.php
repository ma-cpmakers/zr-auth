<?php

namespace Zeiras\Auth;

use Illuminate\Http\Client\HttpClientException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Psr\Http\Message\RequestInterface;
use Zeiras\Auth\Errori\AccettazioneRichiesta;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Errori\IndirizzoNonSicuro;

/**
 * Il client delle API /v1 del backoffice (spec S01, «zr-auth»): `ZR_API_URL`, il gettone nell'header, timeout corto.
 * Ogni metodo torna il JSON della risposta (`[]` per un 204). Un errore di /v1 (RFC 9457) diventa ErroreApi, anche un
 * 5xx (un 503 turnstile_non_disponibile resta leggibile: si decide su `codice`, si mostra `detail`); un 401 diventa
 * GettoneRifiutato, che chiude la sessione e rimanda all'ingresso, senza ripetere la chiamata; un backoffice che non
 * risponde, un trasporto che cade (anche dopo lo stato), un 3xx, un 5xx senza un problema JSON o una risposta senza
 * JSON diventano BackofficeNonRisponde. Un redirect non si segue. Un 403 accettazione_richiesta è un ErroreApi particolare
 * (AccettazioneRichiesta): se nessuno lo cattura, la pagina manda la persona ad accettare i documenti legali e poi indietro.
 *
 *     Api::senzaGettone()->post('/v1/accessi', ['email' => $email, 'password' => $password]);
 *     Api::workspace()->tutti('/v1/workspace/membri');
 *
 * condizionale() è la GET che il polling può ripetere a buon mercato: manda `If-None-Match` e un 304 non è un errore.
 */
final class Api
{
    /** Quante pagine scorre al più tutti(): oltre, la lista non finisce, e non è una lista. */
    public const PAGINE = 100;

    /** Gli elementi per pagina di `elenco()` quando la query non dice `limite`: il massimo di /v1. */
    public const LIMITE = 100;

    /** I freni dell'ultima risposta di questa istanza (#1911): `null` prima della prima, o se non ne diceva nessuno. */
    private ?Freni $freni = null;

    private function __construct(private readonly ?string $gettone) {}

    /** Senza gettone: i metodi che lo fanno nascere (accessi.crea) o che non lo vogliono (utenti.crea). */
    public static function senzaGettone(): self
    {
        return new self(null);
    }

    /**
     * Col gettone dell'accesso: i metodi della persona, gettoni.crea, l'uscita. Un frontend che ha solo il gettone di
     * un workspace chiama i metodi della persona con quello. Senza una sessione aperta: GettoneRifiutato.
     */
    public static function persona(): self
    {
        return new self(self::gettone('accesso') ?? self::gettone('workspace') ?? throw new GettoneRifiutato);
    }

    /** Col gettone del workspace in cui la persona è entrata (Sessione::entra): i metodi del workspace. */
    public static function workspace(): self
    {
        // Il gettone si legge una volta sola: fra due letture potrebbe scadere.
        $gettone = self::gettone('workspace');

        if ($gettone !== null) {
            return new self($gettone);
        }

        if (! Sessione::aperta()) {
            throw new GettoneRifiutato;
        }

        throw new LogicException('Nessun workspace nella sessione: prima Sessione::entra() coi dati di gettoni.crea.');
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function get(string $percorso, array $query = []): array
    {
        return $this->chiama(fn (PendingRequest $richiesta) => $richiesta->get($percorso, $query), $percorso);
    }

    /**
     * Una GET condizionale (RFC 9110 §13.1.2): con la `versione` che chi chiama ha avuto (l'`etag` della risposta
     * precedente, com'è: `"abc"` o `W/"abc"`) manda `If-None-Match`; un 304 torna `['stato' => 304, 'etag' => …, 'corpo' => null]`
     * e vuol dire «ciò che hai è ancora valido». Un 200 torna `['stato' => 200, 'etag' => ?string, 'corpo' => il JSON]`.
     * Gli errori sono quelli di get(). Una `versione` che non è un entity-tag non parte: InvalidArgumentException.
     *
     * @param  array<string, mixed>  $query
     * @return array{stato: 200|304, etag: ?string, corpo: ?array<mixed>}
     */
    public function condizionale(string $percorso, ?string $versione = null, array $query = []): array
    {
        if ($versione !== null && ! self::entityTag($versione)) {
            throw new InvalidArgumentException('La versione dev\'essere un entity-tag («"abc"» o «W/"abc"»), com\'è arrivata nell\'etag.');
        }

        $esito = $this->chiama(
            fn (PendingRequest $richiesta) => ($versione === null ? $richiesta : $richiesta->withHeaders(['If-None-Match' => $versione]))
                ->get($percorso, $query),
            $percorso,
            condizionale: true,
            versione: $versione,
        );

        /** @var array{stato: 200|304, etag: ?string, corpo: ?array<mixed>} $esito */
        return $esito;
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @param  array<string, string>  $header
     * @return array<mixed>
     */
    public function post(string $percorso, array $corpo = [], array $header = []): array
    {
        return $this->chiama(fn (PendingRequest $richiesta) => $richiesta->withHeaders($header)->post($percorso, $corpo), $percorso);
    }

    /**
     * @param  array<string, mixed>  $corpo
     * @param  array<string, string>  $header
     * @return array<mixed>
     */
    public function patch(string $percorso, array $corpo = [], array $header = []): array
    {
        return $this->chiama(fn (PendingRequest $richiesta) => $richiesta->withHeaders($header)->patch($percorso, $corpo), $percorso);
    }

    /**
     * La query (es. `sposta_in` di crm.fasi.elimina) va nei parametri della richiesta, mai nel percorso, e mai nel corpo: un DELETE di
     * /v1 non ha corpo.
     *
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     */
    public function delete(string $percorso, array $query = []): array
    {
        return $this->chiama(fn (PendingRequest $richiesta) => $richiesta->withQueryParameters($query)->delete($percorso), $percorso);
    }

    /**
     * Tutti gli elementi di una lista a cursore: chiede le pagine una dopo l'altra, col `successivo` di ognuna, fino a
     * quella che lo ha `null`. Un errore a metà lancia: mai una lista vuota o a metà.
     *
     * @param  array<string, mixed>  $query
     * @return list<mixed>
     */
    public function tutti(string $percorso, array $query = []): array
    {
        $elementi = [];
        $cursore = null;

        for ($pagina = 0; $pagina < self::PAGINE; $pagina++) {
            $risposta = $this->get($percorso, $cursore === null ? $query : [...$query, 'cursore' => $cursore]);

            if (! isset($risposta['data']) || ! is_array($risposta['data']) || ! array_is_list($risposta['data'])
                || ! array_key_exists('successivo', $risposta)) {
                throw new BackofficeNonRisponde("La risposta di GET {$percorso} non è una lista di /v1.");
            }

            array_push($elementi, ...$risposta['data']);
            $cursore = $risposta['successivo'];

            if ($cursore === null) {
                return $elementi;
            }

            if (! is_string($cursore)) {
                throw new BackofficeNonRisponde("Il successivo di GET {$percorso} non è un cursore.");
            }
        }

        throw new BackofficeNonRisponde("GET {$percorso} ha più di ".self::PAGINE.' pagine.');
    }

    /**
     * Una lista a cursore letta a pagine fino a un tetto (#2021): come `tutti()`, ma oltre `$pagine` pagine non lancia. Torna gli
     * elementi letti e il segno `tagliato`, così una pagina li mostra e dice che ce ne sono altri (mai «Zeiras non risponde» per una
     * lista lunga). `limite` è 100, il massimo di /v1, se la query non lo porta; quello della query vale. Un errore a metà, una
     * risposta che non è una lista di /v1 (senza `data` lista o senza `successivo`), un `successivo` che non è testo (o è vuoto) e un
     * cursore già dato (rileggerebbe le stesse pagine fino al tetto, con i doppioni) lanciano `BackofficeNonRisponde`; gli errori di
     * /v1 escono come da `get()` (`ErroreApi`, `GettoneRifiutato`: il 429 di un freno a pagina 37 sale a chi chiama, com'è per
     * `tutti()`). Mai una lista a metà senza il segno.
     *
     * @param  array<string, mixed>  $query
     * @param  positive-int  $pagine  il tetto di pagine; con `limite` 100 sono al più `$pagine` × 100 elementi
     */
    public function elenco(string $percorso, array $query = [], int $pagine = self::PAGINE): Elenco
    {
        if ($pagine < 1) {
            throw new InvalidArgumentException('Il tetto dev\'essere di almeno una pagina.');
        }

        $query['limite'] ??= self::LIMITE;
        $elementi = [];
        $cursore = null;
        // Il cursore da cui chi chiama riparte è già dato: se il backoffice lo ripete, si scopre alla prima risposta.
        $visti = isset($query['cursore']) && is_string($query['cursore']) ? [$query['cursore'] => true] : [];

        for ($pagina = 0; $pagina < $pagine; $pagina++) {
            $risposta = $this->get($percorso, $cursore === null ? $query : [...$query, 'cursore' => $cursore]);

            if (! isset($risposta['data']) || ! is_array($risposta['data']) || ! array_is_list($risposta['data'])
                || ! array_key_exists('successivo', $risposta)) {
                throw new BackofficeNonRisponde("La risposta di GET {$percorso} non è una lista di /v1.");
            }

            array_push($elementi, ...$risposta['data']);
            $cursore = $risposta['successivo'];

            if ($cursore === null) {
                return new Elenco($elementi, false, null);
            }

            if (! is_string($cursore) || $cursore === '') {
                throw new BackofficeNonRisponde("Il successivo di GET {$percorso} non è un cursore.");
            }

            if (isset($visti[$cursore])) {
                throw new BackofficeNonRisponde("Il successivo di GET {$percorso} ripete un cursore già dato.");
            }

            $visti[$cursore] = true;
        }

        return new Elenco($elementi, true, $cursore);
    }

    /**
     * I freni del backoffice all'ultima risposta di questa istanza, da `RateLimit-Policy` e `RateLimit` (#1911): il residuo per
     * nome di freno, anche dopo un errore (il 429 del freno compreso: si legge nel `catch`). `null` è «nessun freno noto»:
     * prima della prima chiamata, dopo una risposta senza le intestazioni, o se il backoffice non ha risposto.
     */
    public function freni(): ?Freni
    {
        return $this->freni;
    }

    /**
     * Una GET di /v1 passata a flusso, per le risposte che non sono JSON (l'esportazione CSV del CRM, `crm.persone.esportazione.mostra`
     * e `crm.organizzazioni.esportazione.mostra`, #1906): il percorso è dentro /v1 dello stesso host, senza query (la query sta in
     * `$query`), con lo stesso gettone o la stessa firma delle altre chiamate e un redirect non si segue. Gli errori sono quelli di ogni
     * chiamata: `ErroreApi` (il 429 col suo `Retry-After`), `GettoneRifiutato`, `BackofficeNonRisponde`. Il corpo non si legge qui e non
     * sta mai tutto in memoria: lo legge, a blocchi e entro `zr-auth.scarico` secondi, chi usa lo Scaricamento. Un tipo che non è fra
     * `Scaricamento::TIPI` non è un file: `BackofficeNonRisponde`.
     *
     * @param  array<string, mixed>  $query
     */
    public function scarica(string $percorso, array $query = []): Scaricamento
    {
        return $this->scaricamento($percorso, fn (PendingRequest $richiesta) => $richiesta->withOptions($this->aFlusso())->withQueryParameters($query)->get($percorso));
    }

    /**
     * Lo stesso scaricamento a flusso, ma con una POST senza corpo: per un file che il backoffice dà una volta sola (`workspace.esportazioni.scaricamento.crea`,
     * #2015), dove il metodo è una POST perché ha un effetto (consuma lo ZIP). Stesso gettone, stessi errori, stesso `Scaricamento` di `scarica`; il
     * percorso è dentro /v1 senza query, e un 403 `conferma_richiesta` arriva come `ErroreApi` prima del primo byte.
     *
     *     return Api::workspace()->scaricaConPost("/v1/workspace/esportazioni/{$id}/scaricamento")->comeRisposta();
     */
    public function scaricaConPost(string $percorso): Scaricamento
    {
        return $this->scaricamento($percorso, fn (PendingRequest $richiesta) => $richiesta->withOptions($this->aFlusso())->post($percorso));
    }

    /** @return array{stream: true, read_timeout: int<1, max>} */
    private function aFlusso(): array
    {
        return ['stream' => true, 'read_timeout' => max(1, (int) config('zr-auth.letture'))];
    }

    /** @param  \Closure(PendingRequest): Response  $invia */
    private function scaricamento(string $percorso, \Closure $invia): Scaricamento
    {
        $risposta = $this->controllata($invia, $percorso, false, null, true);

        if (! $risposta instanceof Response) {
            throw new LogicException('Una chiamata a flusso torna una risposta.');
        }

        if ($risposta->status() !== 200) {
            throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()} a uno scaricamento, non un 200.");
        }

        return Scaricamento::da($risposta);
    }

    /**
     * @param  \Closure(PendingRequest): Response  $invia
     * @return array<mixed>
     */
    private function chiama(\Closure $invia, string $percorso, bool $condizionale = false, ?string $versione = null): array
    {
        $esito = $this->controllata($invia, $percorso, $condizionale, $versione, false);

        return is_array($esito) ? $esito : throw new LogicException('Una chiamata JSON torna un array.');
    }

    /**
     * Il percorso, la spedizione e i controlli che ogni chiamata ha in comune: il freno letto, un 3xx, il gettone rifiutato (401),
     * l'errore di /v1. Con `$flusso` una risposta riuscita torna com'è, col corpo ancora da leggere (`scarica`); altrimenti il suo JSON.
     *
     * @param  \Closure(PendingRequest): Response  $invia
     * @return array<mixed>|Response
     */
    private function controllata(\Closure $invia, string $percorso, bool $condizionale, ?string $versione, bool $flusso): array|Response
    {
        // Niente resta della chiamata prima: una chiamata che non parte, o un guasto del trasporto, lascia nessun freno noto.
        $this->freni = null;

        // Il gettone va solo al backoffice: un percorso che porta altrove (un indirizzo intero, `//host`) non parte. La query
        // sta in `$query`: in un GET Guzzle sostituirebbe in silenzio quella scritta nel percorso.
        // Uno scaricamento vuole di più: `/v1` o `/v1/…` e nessun segmento `..` (`/v1/../up` resterebbe sullo stesso host, ma fuori da /v1).
        $dentro = ! $flusso || ($percorso === '/v1' || str_starts_with($percorso, '/v1/')) && ! str_contains($percorso, '/..') && ! str_contains($percorso, '\\');

        if (! str_starts_with($percorso, '/v1') || ! $dentro || str_contains($percorso, '?') || str_contains($percorso, '#')) {
            throw new InvalidArgumentException("Il percorso dev'essere un percorso di /v1 («/v1/…»), senza query: non «{$percorso}».");
        }

        $richiesta = Http::baseUrl(self::indirizzo())
            ->timeout((int) config($flusso ? 'zr-auth.scarico' : 'zr-auth.timeout'))
            ->connectTimeout((int) config('zr-auth.connessione'))
            // Un redirect non si segue (R31): un 307 rimanderebbe il corpo, una password compresa, al Location, e il tetto
            // di tempo varrebbe per ogni salto.
            ->withoutRedirecting()
            ->accept($flusso ? implode(', ', Scaricamento::TIPI) : 'application/json')
            ->withHeaders(['Accept-Language' => app()->getLocale()]);

        if ($this->gettone !== null) {
            $richiesta = $richiesta->withToken($this->gettone);
        } else {
            $richiesta = $this->firmata($richiesta);
        }

        try {
            $risposta = $invia($richiesta);
        } catch (HttpClientException $e) {
            // Ogni guasto del trasporto (R30): la connessione che non si apre o che cade, anche dopo lo stato. Con Guzzle 8 un
            // trasferimento che si rompe dopo lo stato di un 4xx o di un 5xx arriva come RequestException, non come
            // ConnectionException.
            throw new BackofficeNonRisponde('Il backoffice non risponde.', previous: $e);
        }

        $this->freni = Freni::daRisposta($risposta);

        // Il 304 di una GET condizionale non è un redirect e non è un errore: la versione di chi chiama è ancora quella buona.
        // Senza una versione mandata nessuno lo ha chiesto, e quel 304 è un backoffice che sbaglia.
        if ($condizionale && $risposta->status() === 304) {
            if ($versione === null) {
                throw new BackofficeNonRisponde('Il backoffice ha risposto 304 a una GET senza condizione.');
            }

            return ['stato' => 304, 'etag' => self::etag($risposta), 'corpo' => null];
        }

        // Un redirect non è mai un problema di /v1 (RFC 9457): è nginx o il trasporto, non il backoffice che risponde
        // nella forma giusta. Un 5xx invece può portare un problema leggibile (es. 503 turnstile_non_disponibile, con
        // `codice`, `title` e `detail`): si guarda il corpo prima di arrendersi.
        if ($risposta->redirect()) {
            throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()}.");
        }

        $corpo = self::json($risposta);

        // Un 401 è il gettone che non vale: si chiude la sessione. Non lo è `cliente_non_riconosciuto` di una rotta senza gettone:
        // quello è il frontend configurato male (nome o segreto sbagliati, orologio spostato), e deve vedersi nel log, non
        // rimandare in silenzio all'ingresso (che rimanderebbe qui, e a ogni registrazione).
        if ($risposta->status() === 401 && ($corpo['codice'] ?? null) !== 'cliente_non_riconosciuto') {
            throw new GettoneRifiutato(ErroreApi::daRisposta($risposta, $corpo));
        }

        if ($risposta->failed()) {
            if ($corpo === null) {
                throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()} senza un problema JSON.");
            }

            $errore = ErroreApi::daRisposta($risposta, $corpo);

            throw $risposta->status() === 403 && $errore->codice === 'accettazione_richiesta' ? AccettazioneRichiesta::da($errore) : $errore;
        }

        if ($flusso) {
            return $risposta;
        }

        if ($condizionale) {
            if ($risposta->status() !== 200 || $corpo === null) {
                throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()} a una GET condizionale, non un 200 con JSON.");
            }

            return ['stato' => 200, 'etag' => self::etag($risposta), 'corpo' => $corpo];
        }

        if ($risposta->status() === 204) {
            return [];
        }

        return $corpo ?? throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()} senza JSON.");
    }

    /** L'ETag della risposta, se è un entity-tag; altrimenti `null`: un valore strano non passa a chi chiama. */
    private static function etag(Response $risposta): ?string
    {
        $etag = trim($risposta->header('ETag'));

        return self::entityTag($etag) ? $etag : null;
    }

    /** RFC 9110 §8.8.3: `"` + caratteri visibili senza `"` + `"`, con `W/` davanti se è debole. Niente spazi, CR o LF. */
    private static function entityTag(string $valore): bool
    {
        return preg_match('#^(?:W/)?"[\x21\x23-\x7E]*"\z#', $valore) === 1;
    }

    /** Il corpo, se è JSON: `application/json`, e ogni `application/*+json` (application/problem+json, RFC 9457). */
    private static function json(Response $risposta): ?array
    {
        $tipo = strtolower(trim(explode(';', (string) $risposta->header('Content-Type'))[0]));

        if ($tipo !== 'application/json' && preg_match('#^application/[a-z0-9.!\#$&^_+-]+\+json$#', $tipo) !== 1) {
            return null;
        }

        $corpo = json_decode($risposta->body(), true);

        return is_array($corpo) ? $corpo : null;
    }

    /**
     * Le rotte senza gettone dichiarano l'IP vero della persona con quattro header firmati (#1447): `Zr-Cliente` (il nome di
     * questo frontend, `zr-auth.cliente`), `Zr-Ip` (l'IP che Laravel vede nella richiesta del browser), `Zr-Istante` e
     * `Zr-Firma`, l'HMAC-SHA256 di `zr1`, client, istante, metodo, percorso e IP, uno per riga, col segreto
     * (`zr-auth.segreto`, ZR_BACKOFFICE_SEGRETO). Si firma alla spedizione, col metodo e il percorso veri. Senza il nome, il
     * segreto o una richiesta del browser (un comando artisan o un job in coda: Laravel lega comunque una richiesta, con
     * 127.0.0.1) non si manda niente e il backoffice la conta fra gli anonimi: mai un X-Forwarded-For, che un chiamante
     * qualsiasi può scrivere.
     */
    private function firmata(PendingRequest $richiesta): PendingRequest
    {
        $cliente = config('zr-auth.cliente');
        $segreto = config('zr-auth.segreto');
        // Da console la richiesta legata è finta (127.0.0.1): tutti i comandi e i job dividerebbero un secchio. Nei test, che girano
        // in console, la richiesta è quella che il test ha preparato.
        $delBrowser = app()->bound('request') && (! app()->runningInConsole() || app()->runningUnitTests());
        $ip = $delBrowser ? request()->ip() : null;

        if (! is_string($cliente) || $cliente === '' || ! is_string($segreto) || $segreto === '' || ! is_string($ip) || $ip === '') {
            return $richiesta;
        }

        return $richiesta->withRequestMiddleware(function (RequestInterface $spedita) use ($cliente, $segreto, $ip): RequestInterface {
            $istante = Carbon::now()->timestamp;
            $firma = hash_hmac('sha256', "zr1\n{$cliente}\n{$istante}\n".strtoupper($spedita->getMethod())."\n".$spedita->getUri()->getPath()."\n{$ip}", $segreto);

            return $spedita
                ->withHeader('Zr-Cliente', $cliente)
                ->withHeader('Zr-Ip', $ip)
                ->withHeader('Zr-Istante', (string) $istante)
                ->withHeader('Zr-Firma', $firma);
        });
    }

    /** ZR_API_URL, senza la barra finale; un indirizzo che non è https non parte. */
    private static function indirizzo(): string
    {
        $indirizzo = rtrim((string) config('zr-auth.api'), '/');

        if (! str_starts_with(strtolower($indirizzo), 'https://')) {
            throw new IndirizzoNonSicuro($indirizzo);
        }

        return $indirizzo;
    }

    /** Il gettone della sessione, se non è scaduto: `accesso` o `workspace`. */
    private static function gettone(string $quale): ?string
    {
        if (! Sessione::aperta()) {
            return null;
        }

        $stato = session(Sessione::CHIAVE);
        $gettone = $stato['gettoni'][$quale] ?? null;

        return is_string($gettone) && Carbon::parse($stato['scade_il'])->isFuture() ? $gettone : null;
    }
}
