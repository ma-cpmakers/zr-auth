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
     * Il JWKS di zr-home resta in cache 10 minuti, solo se ha chiavi valide. Un `kid` che non ha lo fa rileggere al più una
     * volta al minuto (`:riletto`); zr-home che non risponde, o un JWKS che non vale, si ricordano 30 secondi (`:errore`).
     */
    private const CACHE = 'zr-auth:jwks';

    private const DURATA_CACHE = 600;

    private const RILETTURA = 60;

    private const DURATA_ERRORE = 30;

    /**
     * I claim del token se è firmato da zr-home (JWKS) per questo modulo — emittente zr-home, destinatario questo client
     * solo —, se le sue date valgono (`exp`, `iat`, `nbf`) e se la sua intestazione `typ` è `$tipo`, quando se ne chiede
     * uno. Null altrimenti, anche se zr-home non risponde.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(#[SensitiveParameter] string $token, ?string $tipo = null): ?array
    {
        $chiavi = self::chiavi(self::kid($token));
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
     * Le chiavi del JWKS di zr-home, dalla cache o da zr-home. Un `kid` che le chiavi in cache non hanno vuol dire che zr-home
     * firma con una chiave nuova: si rilegge, al più una volta al minuto. zr-home che non risponde, o una risposta senza
     * chiavi RS256 valide, non entrano in cache e si ricordano 30 secondi: nel frattempo non si richiede, e restano le chiavi
     * che c'erano.
     *
     * @return array<string, Key>|null
     */
    private static function chiavi(?string $kid): ?array
    {
        $jwks = Cache::get(self::CACHE);
        $chiavi = is_array($jwks) ? self::leggi($jwks) : null;
        if ($chiavi !== null && ($kid === null || isset($chiavi[$kid]) || ! Cache::add(self::CACHE.':riletto', true, self::RILETTURA))) {
            return $chiavi;
        }
        if (Cache::has(self::CACHE.':errore')) {
            return $chiavi;
        }

        try {
            $risposta = Http::acceptJson()->timeout(5)->get(Ingresso::zrHome().'/oauth/jwks');
        } catch (ConnectionException $errore) {
            Log::warning('zr-auth: zr-home non risponde (JWKS)', ['errore' => $errore->getMessage()]);
            Cache::put(self::CACHE.':errore', true, self::DURATA_ERRORE);

            return $chiavi;
        }
        $nuovo = $risposta->successful() ? $risposta->json() : null;
        $nuove = is_array($nuovo) ? self::leggi($nuovo) : null;
        if ($nuove === null) {
            Log::warning('zr-auth: il JWKS di zr-home non vale', ['stato' => $risposta->status()]);
            Cache::put(self::CACHE.':errore', true, self::DURATA_ERRORE);

            return $chiavi;
        }
        Cache::put(self::CACHE, $nuovo, self::DURATA_CACHE);

        return $nuove;
    }

    /** Il `kid` dell'intestazione del token, se c'è: non è verificato, serve solo a scegliere la chiave. */
    private static function kid(#[SensitiveParameter] string $token): ?string
    {
        $json = base64_decode(strtr(explode('.', $token)[0], '-_', '+/'), true);
        $intestazione = is_string($json) ? json_decode($json, true) : null;

        return is_array($intestazione) && is_string($intestazione['kid'] ?? null) ? $intestazione['kid'] : null;
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
