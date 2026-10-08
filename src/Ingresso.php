<?php

namespace Zeiras\Auth;

use Illuminate\Http\RedirectResponse;
use InvalidArgumentException;
use LogicException;
use Zeiras\Auth\Errori\IndirizzoNonSicuro;

/**
 * La partenza dell'ingresso nei moduli (spec S01, «Ingresso nei moduli»): il modulo manda la persona a
 * `ZR_HOME_URL/ingresso` e al ritorno riceve un codice monouso (RicevitoreController). Il protocollo è un codice con PKCE:
 * il verificatore nasce qui e resta nella sessione del modulo; zr-home riceve solo la sua sfida (SHA-256, base64url), e
 * il codice senza il verificatore non vale niente.
 *
 *     Route::get('entra/{workspace}', fn (string $workspace) => Ingresso::verso($workspace))->withoutMiddleware(ConGettone::class);
 */
final class Ingresso
{
    /** La chiave della sessione sotto cui la partenza tiene `state` e verificatore fino al ritorno. */
    public const CHIAVE = 'zr-auth-ingresso';

    /** Le intestazioni di ogni risposta dell'ingresso: il codice e lo state passano per l'indirizzo, e non restano in giro. */
    public const INTESTAZIONI = ['Cache-Control' => 'no-store', 'Referrer-Policy' => 'no-referrer'];

    /**
     * Manda la persona a `ZR_HOME_URL/ingresso` per entrare nel workspace (lo slug) con l'app del modulo (`zr-auth.app`).
     * Un 302 con `app`, `workspace`, `state` e `sfida` nella query: il verificatore no, mai, né nell'indirizzo né in un
     * header. Una partenza nuova prende il posto della precedente.
     */
    public static function verso(string $workspace): RedirectResponse
    {
        $app = config('zr-auth.app');
        $home = rtrim((string) config('zr-auth.home'), '/');

        if (! is_string($app) || preg_match('/^[a-z][a-z0-9_]*$/', $app) !== 1) {
            throw new LogicException('Manca il codice dell\'app del modulo: ZR_APP (zr-auth.app), come nel catalogo delle app.');
        }

        if (! str_starts_with(strtolower($home), 'https://')) {
            throw new IndirizzoNonSicuro($home);
        }

        if ($workspace === '' || strlen($workspace) > 200) {
            throw new InvalidArgumentException('Il workspace dell\'ingresso è lo slug, non vuoto.');
        }

        Sessione::controllaIlDriver();

        $state = self::base64url(random_bytes(32));
        $verificatore = self::base64url(random_bytes(48));
        session()->put(self::CHIAVE, ['state' => $state, 'verificatore' => $verificatore]);

        $query = http_build_query([
            'app' => $app,
            'workspace' => $workspace,
            'state' => $state,
            'sfida' => self::base64url(hash('sha256', $verificatore, true)),
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away("{$home}/ingresso?{$query}")->withHeaders(self::INTESTAZIONI);
    }

    private static function base64url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
