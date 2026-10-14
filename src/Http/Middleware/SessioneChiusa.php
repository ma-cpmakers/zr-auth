<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Sessione;

/**
 * Una risposta tardiva non fa rivivere una sessione chiusa (#2044). Una richiesta lenta partita prima che un'altra richiesta dello
 * stesso browser facesse entrare un'altra persona (o cambiare workspace, o uscire) ha in mano l'id di prima: a fine corsa Laravel
 * riscrive quella sessione e rimette quel cookie nel browser. Qui, a risposta pronta, se l'id della richiesta è segnato chiuso
 * (Sessione::eChiusa) la sessione appena riscritta si distrugge e dalla risposta escono il cookie di sessione e quello del gettone
 * CSRF: al browser resta quello di chi è entrato dopo. Sta fuori da StartSession (ZrAuthServiceProvider lo mette per primo nel
 * gruppo `web`) e non chiede nulla alle rotte: vale per ogni pagina, non solo per quelle con `->bloccaSessione()`.
 */
final class SessioneChiusa
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        $risposta = $next($richiesta);

        if (! $richiesta->hasSession() || ! Sessione::eChiusa($richiesta->session()->getId())) {
            return $risposta;
        }

        $richiesta->session()->getHandler()->destroy($richiesta->session()->getId());

        $percorso = (string) config('session.path', '/');
        $dominio = config('session.domain');
        foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $nome) {
            $risposta->headers->removeCookie((string) $nome, $percorso, is_string($dominio) ? $dominio : null);
        }

        // Né l'id né il percorso con i valori: solo che è successo e dove (il disegno della rotta).
        Log::info('zr-auth: scartata la risposta tardiva di una sessione chiusa', [
            'metodo' => $richiesta->method(),
            'rotta' => $richiesta->route()?->uri(),
        ]);

        return $risposta;
    }
}
