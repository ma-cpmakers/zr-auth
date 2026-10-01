<?php

namespace Zeiras\Auth\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Zeiras\Auth\Ingresso;

/**
 * Il ritorno da zr-home (`GET /auth/callback`). Lo `state` dev'essere un ingresso che aspetta in questa sessione, e vale
 * una volta. Col codice si apre la sessione del modulo e si torna alla pagina chiesta; se l'ingresso in silenzio trova
 * zr-home che vuole la persona davanti — senza sessione, o con una pagina da mostrarle — l'ingresso riparte con l'accesso.
 * Tutto il resto è 403, senza sessione.
 */
final class RitornoController
{
    /** Gli errori di `prompt=none` in OpenID Connect: zr-home vuole la persona davanti. */
    private const CON_LA_PERSONA = ['login_required', 'interaction_required', 'consent_required', 'account_selection_required'];

    public function __invoke(Request $richiesta, Ingresso $ingresso): RedirectResponse
    {
        $state = $richiesta->query('state');
        $atteso = is_string($state) && $state !== '' ? $ingresso->riprendi($richiesta, $state) : null;
        abort_if($atteso === null, 403);

        if ($atteso['silenzioso'] && in_array($richiesta->query('error'), self::CON_LA_PERSONA, true)) {
            return redirect()->away($ingresso->comincia($richiesta, $atteso['workspace'], silenzioso: false, ritorno: $atteso['ritorno']));
        }

        $codice = $richiesta->query('code');
        abort_unless(is_string($codice) && $codice !== '' && $ingresso->apri($richiesta, $atteso, $codice), 403);

        return redirect()->to($atteso['ritorno']);
    }
}
