<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Sessione;

/**
 * La guardia di zr-auth: ogni rotta dei gruppi `web` e `api` vuole una sessione con un gettone che non è scaduto
 * (ZrAuthServiceProvider la mette nei gruppi da sé). Senza, una pagina rimanda all'ingresso e ricorda dove voleva
 * andare; una visita di Inertia riceve 409 con `X-Inertia-Location`; una richiesta JSON 401.
 */
final class ConGettone
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        if (! Sessione::aperta()) {
            if ($richiesta->hasSession() && $richiesta->session()->has(Sessione::CHIAVE)) {
                // Un gettone scaduto: esce dalla sessione, come all'uscita.
                Sessione::chiudi();
            }

            return self::versoLIngresso($richiesta);
        }

        return $next($richiesta);
    }

    /** La risposta che manda all'ingresso (`zr-auth.ingresso`): la usa anche GettoneRifiutato. */
    public static function versoLIngresso(Request $richiesta): Response
    {
        $ingresso = (string) config('zr-auth.ingresso');

        if ($richiesta->header('X-Inertia') !== null) {
            if ($richiesta->hasSession() && $richiesta->isMethod('GET')) {
                $richiesta->session()->put('url.intended', $richiesta->fullUrl());
            }

            return new Response('', 409, ['X-Inertia-Location' => $ingresso]);
        }

        if ($richiesta->expectsJson()) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->guest($ingresso);
    }
}
