<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Ritorno;
use Zeiras\Auth\Sessione;

/**
 * La guardia di zr-auth: ogni rotta dei gruppi `web` e `api` vuole una sessione con un gettone che non è scaduto
 * (ZrAuthServiceProvider la mette nei gruppi da sé). Senza, una pagina rimanda all'ingresso e ricorda dove voleva andare
 * (un GET: la sua pagina, con la query se è corta; una scrittura: la pagina da cui è partita, dal Referer, Ritorno::dalReferer); una visita di Inertia
 * riceve 409 con `X-Inertia-Location`; una richiesta JSON 401.
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

        // La pagina chiesta si ricorda da un GET (l'indirizzo della richiesta) e da una scrittura (POST, PUT, PATCH, DELETE) solo dal
        // Referer, a condizioni strette (Ritorno::dalReferer, #2043). Mai redirect()->guest(): ricorderebbe il Referer di
        // qualunque metodo, la pagina di un altro sito, dove la persona tornerebbe dopo l'accesso. HEAD non ricorda niente. Il
        // ritorno lo legge Sessione::ritorno(), con un GET: la scrittura non si ripete.
        if ($richiesta->hasSession()) {
            if ($richiesta->isMethod('GET')) {
                // La pagina con la query se è corta, altrimenti il solo percorso (Ritorno::dallaRichiesta): la sessione di chi non è entrato resta piccola.
                $dovEri = Ritorno::dallaRichiesta($richiesta);
                $dovEri !== null ? $richiesta->session()->put('url.intended', $dovEri) : $richiesta->session()->forget('url.intended');
            } elseif (in_array($richiesta->getRealMethod(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $dovEri = Ritorno::dalReferer($richiesta, $richiesta->route('workspace'));
                // Un ritorno ricordato prima non vale per questa scrittura.
                $dovEri !== null ? $richiesta->session()->put('url.intended', $dovEri) : $richiesta->session()->forget('url.intended');
            }
        }

        return $inertia ? new Response('', 409, ['X-Inertia-Location' => $ingresso]) : redirect()->to($ingresso);
    }
}
