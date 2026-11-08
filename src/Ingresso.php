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
        if ($workspace === '' || strlen($workspace) > 200) {
            throw new InvalidArgumentException('Il workspace dell\'ingresso è lo slug, non vuoto.');
        }

        return self::parti('/ingresso', ['workspace' => $workspace], null);
    }

    /**
     * Manda la persona a `ZR_HOME_URL/ingresso/cliente` per entrare nel modulo come cliente, senza workspace: chi prenota su Bookings
     * non è membro di nessun workspace. Un 302 con `app`, `state` e `sfida` nella query, e niente altro: il verificatore no, mai, né
     * nell'indirizzo né in un header, e la scelta che il modulo ha davanti (la sala, la pagina) non passa di qui. Il ritorno è lo stesso
     * ricevitore dell'ingresso nel workspace: scambia il codice di `ingressi.clienti.crea` e apre la sessione del cliente
     * (`Sessione::entraCliente()`), con la persona e il gettone ristretto, senza workspace né ruolo. Una partenza nuova prende il posto della precedente.
     *
     *     Route::get('prenota/entra', fn () => Ingresso::versoCliente())->withoutMiddleware(ConGettone::class);
     */
    public static function versoCliente(): RedirectResponse
    {
        return self::parti('/ingresso/cliente', [], 'cliente');
    }

    /**
     * Manda la persona a `ZR_HOME_URL/ingresso/staff` per entrare nel frontend dello staff (`admin`, zr-superadmin) con il login normale di Zeiras (#3445): niente password né
     * secondo fattore propri, la lista è chiusa e la tiene il backoffice. Un 302 con `app` (sempre `admin`: `zr-auth.app` dev'essere quello), `state` e `sfida` nella query, e niente altro.
     * Il ritorno è lo stesso ricevitore degli altri ingressi: scambia il codice di `ingressi.staff.crea` col verificatore e apre la sessione dello staff
     * (`Sessione::entraStaff()`), col gettone dello staff e senza persona né workspace. Una partenza nuova prende il posto della precedente.
     *
     *     Route::get('entra', fn () => Ingresso::versoStaff())->withoutMiddleware(ConGettone::class);
     */
    public static function versoStaff(): RedirectResponse
    {
        if (config('zr-auth.app') !== 'admin') {
            throw new LogicException('L\'ingresso dello staff è del frontend `admin`: ZR_APP (zr-auth.app) dev\'essere admin.');
        }

        return self::parti('/ingresso/staff', [], 'staff');
    }

    /**
     * La partenza di ogni ingresso: `state` e verificatore nella sessione (col segno del tipo, `cliente` o `staff`, che il ricevitore vuole uguale
     * al gettone che torna), la sfida nell'indirizzo.
     *
     * @param  array<string, string>  $campi  i campi in più della query, dopo `app`
     * @param  'cliente'|'staff'|null  $tipo  null per l'ingresso in un workspace
     */
    private static function parti(string $percorso, array $campi, ?string $tipo): RedirectResponse
    {
        $app = config('zr-auth.app');
        $home = rtrim((string) config('zr-auth.home'), '/');

        if (! is_string($app) || preg_match('/^[a-z][a-z0-9_]*$/', $app) !== 1) {
            throw new LogicException('Manca il codice dell\'app del modulo: ZR_APP (zr-auth.app), come nel catalogo delle app.');
        }

        if (! str_starts_with(strtolower($home), 'https://')) {
            throw new IndirizzoNonSicuro($home);
        }

        Sessione::controllaIlDriver();

        $state = self::base64url(random_bytes(32));
        $verificatore = self::base64url(random_bytes(48));
        session()->put(self::CHIAVE, ['state' => $state, 'verificatore' => $verificatore] + ($tipo !== null ? [$tipo => true] : []));

        $query = http_build_query([
            'app' => $app,
            ...$campi,
            'state' => $state,
            'sfida' => self::base64url(hash('sha256', $verificatore, true)),
        ], '', '&', PHP_QUERY_RFC3986);

        return redirect()->away("{$home}{$percorso}?{$query}")->withHeaders(self::INTESTAZIONI);
    }

    private static function base64url(string $binario): string
    {
        return rtrim(strtr(base64_encode($binario), '+/', '-_'), '=');
    }
}
