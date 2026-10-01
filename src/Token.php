<?php

namespace Zeiras\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Illuminate\Support\Facades\Http;
use stdClass;
use Throwable;

/**
 * Un token di zr-home — l'id_token di un ingresso, il logout_token di un avviso. Firma, algoritmo e date li verifica
 * firebase/php-jwt col JWKS di zr-home (G18); chi lo usa aggiunge i controlli sui claim, non li sostituisce.
 */
final class Token
{
    /** Un minuto di tolleranza fra l'orologio di zr-home e quello del modulo. */
    private const TOLLERANZA = 60;

    /**
     * I claim del token se è firmato da zr-home (JWKS) e le sue date valgono (`exp`, `iat`, `nbf`), e se la sua
     * intestazione `typ` è `$tipo`, quando se ne chiede uno. Null altrimenti, anche se zr-home non dà il JWKS.
     *
     * @return array<string, mixed>|null
     */
    public static function claims(string $token, ?string $tipo = null): ?array
    {
        $jwks = Http::acceptJson()->timeout(10)->get(Ingresso::zrHome().'/oauth/jwks');
        if (! $jwks->successful() || ! is_array($jwks->json())) {
            return null;
        }

        // L'orologio è quello dell'app, come per la durata della sessione.
        $tolleranza = JWT::$leeway;
        $adesso = JWT::$timestamp;
        $intestazione = new stdClass;
        try {
            JWT::$leeway = self::TOLLERANZA;
            JWT::$timestamp = now()->getTimestamp();
            $claims = json_decode((string) json_encode(JWT::decode($token, JWK::parseKeySet($jwks->json(), 'RS256'), $intestazione)), true);
        } catch (Throwable) {
            return null;
        } finally {
            JWT::$leeway = $tolleranza;
            JWT::$timestamp = $adesso;
        }

        return is_array($claims) && ($tipo === null || ($intestazione->typ ?? null) === $tipo) ? $claims : null;
    }
}
