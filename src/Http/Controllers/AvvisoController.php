<?php

namespace Zeiras\Auth\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Avviso;
use Zeiras\Auth\Revoca;

/**
 * L'avviso di zr-home (`POST /auth/avviso`, voce #979): un form col `logout_token`. Valido, la revoca si registra e la
 * risposta è 200; le sessioni che chiude se ne accorgono alla loro richiesta successiva. Non valido, 400, e non si chiude
 * niente. Senza sessione e senza CSRF: lo manda il server di zr-home, non un browser.
 */
final class AvvisoController
{
    public function __invoke(Request $richiesta): Response
    {
        $token = $richiesta->input('logout_token');
        $revoca = is_string($token) && $token !== '' ? Avviso::revoca($token) : null;
        if ($revoca === null) {
            return response()->json(['error' => 'invalid_request'], 400, ['Cache-Control' => 'no-store']);
        }
        Revoca::registra($revoca);

        return response('', 200, ['Cache-Control' => 'no-store']);
    }
}
