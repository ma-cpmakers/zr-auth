<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Sessione;

/**
 * La guardia di zr-auth: ogni rotta dei gruppi `web` e `api` vuole una sessione con un gettone che non è scaduto
 * (ZrAuthServiceProvider la mette nei gruppi da sé). Senza, una pagina rimanda all'ingresso e, se è un GET, ricorda dove
 * voleva andare; una visita di Inertia riceve 409 con `X-Inertia-Location`; una richiesta JSON 401.
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
        $inertia = $richiesta->header('X-Inertia') !== null;

        if (! $inertia && $richiesta->expectsJson()) {
            return new JsonResponse(['message' => 'Unauthenticated.'], 401);
        }

        // La pagina chiesta si ricorda solo da un GET, ed è l'indirizzo della richiesta. Mai redirect()->guest(): per ogni
        // altro metodo, HEAD compreso, ricorderebbe il Referer, la pagina di un altro sito, dove la persona tornerebbe dopo
        // l'accesso. Il ritorno lo legge Sessione::ritorno().
        if ($richiesta->hasSession() && $richiesta->isMethod('GET')) {
            $richiesta->session()->put('url.intended', $richiesta->fullUrl());
        }

        return $inertia ? new Response('', 409, ['X-Inertia-Location' => $ingresso]) : redirect()->to($ingresso);
    }
}
