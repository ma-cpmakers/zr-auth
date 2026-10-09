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
 * JSON diventano BackofficeNonRisponde. Un redirect non si segue.
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

    /** @return array<mixed> */
    public function delete(string $percorso): array
    {
        return $this->chiama(fn (PendingRequest $richiesta) => $richiesta->delete($percorso), $percorso);
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
     * @param  \Closure(PendingRequest): Response  $invia
     * @return array<mixed>
     */
    private function chiama(\Closure $invia, string $percorso, bool $condizionale = false, ?string $versione = null): array
    {
        // Il gettone va solo al backoffice: un percorso che porta altrove (un indirizzo intero, `//host`) non parte. La query
        // sta in `$query`: in un GET Guzzle sostituirebbe in silenzio quella scritta nel percorso.
        if (! str_starts_with($percorso, '/v1') || str_contains($percorso, '?') || str_contains($percorso, '#')) {
            throw new InvalidArgumentException("Il percorso dev'essere un percorso di /v1 («/v1/…»), senza query: non «{$percorso}».");
        }

        $richiesta = Http::baseUrl(self::indirizzo())
            ->timeout((int) config('zr-auth.timeout'))
            ->connectTimeout((int) config('zr-auth.connessione'))
            // Un redirect non si segue (R31): un 307 rimanderebbe il corpo, una password compresa, al Location, e il tetto
            // di tempo varrebbe per ogni salto.
            ->withoutRedirecting()
            ->acceptJson()
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

        if ($risposta->status() === 401) {
            throw new GettoneRifiutato(ErroreApi::daRisposta($risposta, $corpo));
        }

        if ($risposta->failed()) {
            if ($corpo === null) {
                throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()} senza un problema JSON.");
            }

            throw ErroreApi::daRisposta($risposta, $corpo);
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
     * segreto o una richiesta del browser (un comando artisan) non si manda niente e il backoffice la conta fra gli anonimi:
     * mai un X-Forwarded-For, che un chiamante qualsiasi può scrivere.
     */
    private function firmata(PendingRequest $richiesta): PendingRequest
    {
        $cliente = config('zr-auth.cliente');
        $segreto = config('zr-auth.segreto');
        $ip = app()->bound('request') ? request()->ip() : null;

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
