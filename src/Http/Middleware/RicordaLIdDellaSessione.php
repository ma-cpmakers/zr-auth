<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;
use Zeiras\Auth\Sessione;

/**
 * Ricorda l'id con cui la sessione è partita (#2044): SessioneChiusa lo confronta con i segni delle sessioni chiuse a fine corsa,
 * anche se la richiesta ha cambiato id da sé (`Sessione::entra`, o un `session()->regenerate()` del modulo). Sta nel gruppo `web`
 * dopo StartSession (ZrAuthServiceProvider lo mette da sé). Ricorda anche, a inizio richiesta, se la persona della sessione è
 * un'identità di prova (#2157): a fine corsa la richiesta può aver chiuso la sessione o cambiato persona, e la riga di log di
 * SessioneChiusa parla della persona della sessione che si chiude.
 */
final class RicordaLIdDellaSessione
{
    public const ATTRIBUTO = 'zr-auth.id-iniziale';

    public const PROVA = 'zr-auth.persona-di-prova';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        if ($richiesta->hasSession()) {
            $richiesta->attributes->set(self::ATTRIBUTO, $richiesta->session()->getId());
            $richiesta->attributes->set(self::PROVA, self::eUnaProva());
        }

        return $next($richiesta);
    }

    /** Vero se l'email della persona della sessione comincia per uno dei prefissi di prova (`zr-auth.prova.email_prefissi`). */
    private static function eUnaProva(): bool
    {
        try {
            $email = Sessione::utente()['email'] ?? null;
        } catch (Throwable) {
            return false;
        }

        if (! is_string($email)) {
            return false;
        }

        $email = strtolower($email);
        foreach ((array) config('zr-auth.prova.email_prefissi', ['uat+', 'uat-']) as $prefisso) {
            if (is_string($prefisso) && $prefisso !== '' && str_starts_with($email, strtolower($prefisso))) {
                return true;
            }
        }

        return false;
    }
}
