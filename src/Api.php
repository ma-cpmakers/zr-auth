<?php

namespace Zeiras\Auth;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use LogicException;
use Zeiras\Auth\Errori\BackofficeNonRisponde;
use Zeiras\Auth\Errori\ErroreApi;
use Zeiras\Auth\Errori\GettoneRifiutato;
use Zeiras\Auth\Errori\IndirizzoNonSicuro;

/**
 * Il client delle API /v1 del backoffice (spec S01, «zr-auth»): `ZR_API_URL`, il gettone nell'header, timeout corto.
 * Ogni metodo torna il JSON della risposta (`[]` per un 204). Un errore di /v1 (RFC 9457) diventa ErroreApi; un 401 diventa
 * GettoneRifiutato, che chiude la sessione e rimanda all'ingresso, senza ripetere la chiamata; un backoffice che non
 * risponde, un 5xx o una risposta senza JSON diventano BackofficeNonRisponde.
 *
 *     Api::senzaGettone()->post('/v1/accessi', ['email' => $email, 'password' => $password]);
 *     Api::workspace()->tutti('/v1/workspace/membri');
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
        if (! Sessione::aperta()) {
            throw new GettoneRifiutato;
        }

        return new self(self::gettone('workspace')
            ?? throw new LogicException('Nessun workspace nella sessione: prima Sessione::entra() coi dati di gettoni.crea.'));
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
    private function chiama(\Closure $invia, string $percorso): array
    {
        // Il gettone va solo al backoffice: un percorso che porta altrove (un indirizzo intero, `//host`) non parte.
        if (! str_starts_with($percorso, '/v1') || str_starts_with($percorso, '//')) {
            throw new InvalidArgumentException("Il percorso dev'essere un percorso di /v1 («/v1/…»), non «{$percorso}».");
        }

        $richiesta = Http::baseUrl(self::indirizzo())
            ->timeout((int) config('zr-auth.timeout'))
            ->connectTimeout((int) config('zr-auth.connessione'))
            ->acceptJson()
            ->withHeaders(['Accept-Language' => app()->getLocale()]);

        if ($this->gettone !== null) {
            $richiesta = $richiesta->withToken($this->gettone);
        }

        try {
            $risposta = $invia($richiesta);
        } catch (ConnectionException $e) {
            throw new BackofficeNonRisponde('Il backoffice non risponde.', previous: $e);
        }

        if ($risposta->serverError()) {
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

        if ($risposta->status() === 204) {
            return [];
        }

        return $corpo ?? throw new BackofficeNonRisponde("Il backoffice ha risposto {$risposta->status()} senza JSON.");
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
