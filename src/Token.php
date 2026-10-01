<?php

namespace Zeiras\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * Un token di zr-home — l'id_token di un ingresso, il logout_token di un avviso. Firma, algoritmo e date li verifica
 * firebase/php-jwt col JWKS di zr-home (G18), emittente e destinatario si controllano qui; chi lo usa aggiunge i controlli
 * sui suoi claim, non li sostituisce.
 */
final class Token
{
    /** Un minuto di tolleranza fra l'orologio di zr-home e quello del modulo. */
    private const TOLLERANZA = 60;

    /**
     * Il JWKS di zr-home resta in cache 10 minuti, solo se ha chiavi valide: una chiave nuova di zr-home va pubblicata nel
     * JWKS almeno 10 minuti prima di firmare con quella.
     */
    private const CACHE = 'zr-auth:jwks';

    private const DURATA_CACHE = 600;

    /**
     * I claim del token se è firmato da zr-home (JWKS) per questo modulo — emittente zr-home, destinatario questo client
     * solo —, se le sue date valgono (`exp`, `iat`, `nbf`) e se la sua intestazione `typ` è `$tipo`, quando se ne chiede
     * uno. Null altrimenti, anche se zr-home non risponde.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(#[SensitiveParameter] string $token, ?string $tipo = null): ?array
    {
        $chiavi = self::chiavi();
        if ($chiavi === null) {
            return null;
        }

        // L'orologio è quello dell'app, come per la durata della sessione.
        $tolleranza = JWT::$leeway;
        $adesso = JWT::$timestamp;
        $intestazione = new stdClass;
        try {
            JWT::$leeway = self::TOLLERANZA;
            JWT::$timestamp = now()->getTimestamp();
            $claims = json_decode((string) json_encode(JWT::decode($token, $chiavi, $intestazione)), true);
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$leeway = $tolleranza;
            JWT::$timestamp = $adesso;
        }

        $valido = is_array($claims)
            && ($claims['iss'] ?? null) === Ingresso::zrHome()
            && (array) ($claims['aud'] ?? []) === [config('zr-auth.client_id')]
            && ($tipo === null || ($intestazione->typ ?? null) === $tipo);

        return $valido ? $claims : null;
    }

    /**
     * Un id di zr-home — una persona, un workspace — scritto in un claim o in un indirizzo: cifre senza zeri davanti, che
     * stanno in un intero. Null per tutto il resto.
     */
    public static function id(mixed $valore): ?int
    {
        return is_string($valore) && preg_match('/^[1-9][0-9]{0,17}$/', $valore) === 1 ? (int) $valore : null;
    }

    /**
     * Le chiavi del JWKS di zr-home, dalla cache o da zr-home. Una risposta senza chiavi RS256 valide, o zr-home che non
     * risponde, non restano in cache: la volta dopo si richiede.
     *
     * @return array<string, Key>|null
     */
    private static function chiavi(): ?array
    {
        $jwks = Cache::get(self::CACHE);
        if (is_array($jwks)) {
            return self::leggi($jwks);
        }

        try {
            $risposta = Http::acceptJson()->timeout(10)->get(Ingresso::zrHome().'/oauth/jwks');
        } catch (ConnectionException $errore) {
            Log::warning('zr-auth: zr-home non risponde (JWKS)', ['errore' => $errore->getMessage()]);

            return null;
        }
        $jwks = $risposta->successful() ? $risposta->json() : null;
        $chiavi = is_array($jwks) ? self::leggi($jwks) : null;
        if ($chiavi === null) {
            Log::warning('zr-auth: il JWKS di zr-home non vale', ['stato' => $risposta->status()]);

            return null;
        }
        Cache::put(self::CACHE, $jwks, self::DURATA_CACHE);

        return $chiavi;
    }

    /**
     * @param  array<mixed>  $jwks
     * @return array<string, Key>|null
     */
    private static function leggi(array $jwks): ?array
    {
        try {
            return JWK::parseKeySet($jwks, 'RS256');
        } catch (Throwable) {
            return null;
        }
    }
}
