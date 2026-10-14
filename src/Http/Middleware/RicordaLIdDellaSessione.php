<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ricorda l'id con cui la sessione è partita (#2044): SessioneChiusa lo confronta con i segni delle sessioni chiuse a fine corsa,
 * anche se la richiesta ha cambiato id da sé (`Sessione::entra`, o un `session()->regenerate()` del modulo). Sta nel gruppo `web`
 * dopo StartSession (ZrAuthServiceProvider lo mette da sé).
 */
final class RicordaLIdDellaSessione
{
    public const ATTRIBUTO = 'zr-auth.id-iniziale';

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        if ($richiesta->hasSession()) {
            $richiesta->attributes->set(self::ATTRIBUTO, $richiesta->session()->getId());
        }

        return $next($richiesta);
    }
}
