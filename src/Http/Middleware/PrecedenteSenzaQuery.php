<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Sessione;

/**
 * Per chi non è entrato, `_previous.url` senza la query. StartSession di Laravel scrive `_previous.url = fullUrl()` a ogni GET,
 * dopo la risposta: un GET anonimo con una query di 8 KB lasciava in Redis una sessione di 25 KB per due ore, e Redis è uno per
 * tutta Zeiras. ZrAuthServiceProvider la mette nel gruppo `web`, dopo StartSession: il suo codice dopo `$next` gira prima della
 * scrittura di StartSession, e la query della richiesta (già servita) non entra più in `fullUrl()`. Chi è entrato non cambia:
 * le sue sessioni sono poche e `back()` le serve.
 */
final class PrecedenteSenzaQuery
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        $risposta = $next($richiesta);

        if ($richiesta->isMethod('GET') && $richiesta->hasSession() && ! Sessione::aperta()) {
            $richiesta->server->set('QUERY_STRING', '');
        }

        return $risposta;
    }
}
