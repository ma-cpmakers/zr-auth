<?php

namespace Zeiras\Auth\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Session\Middleware\StartSession;
use Zeiras\Auth\Ritorno;
use Zeiras\Auth\Sessione;

/**
 * StartSession di Laravel, con `_previous.url` che per chi non è entrato è il solo percorso. Laravel scrive `_previous.url =
 * fullUrl()` a ogni GET, dopo la risposta: un GET anonimo con una query di 8 KB, o con un percorso di 8 KB, lasciava in Redis
 * una sessione di 25 KB per due ore, e Redis è uno per tutta Zeiras (256 MB, noeviction). Per chi non ha una sessione aperta si
 * scrive Ritorno::dallaRichiesta (percorso senza query, tetto 300 byte), o niente se non vale. Anche per il ricevitore
 * dell'ingresso: il suo `?codice=&state=` è già consumato e non deve restare nella sessione di chi è appena entrato. Per chi è
 * entrato non cambia niente: le sue sessioni sono poche e `back()` le serve.
 *
 * ZrAuthServiceProvider mette questa classe nel gruppo `web` al posto di StartSession: il suo posto nell'ordine dei middleware
 * resta quello (la lista delle priorità riconosce la classe madre).
 */
class AvviaLaSessioneSenzaQuery extends StartSession
{
    /** @param  \Illuminate\Contracts\Session\Session  $session */
    protected function storeCurrentUrl(Request $request, $session)
    {
        $rotta = $request->route();
        $ricevitore = $rotta instanceof Route && $rotta->getName() === 'zr-auth.ricevitore';

        if (! $ricevitore && Sessione::aperta()) {
            parent::storeCurrentUrl($request, $session);

            return;
        }

        if ($request->isMethod('GET') && $rotta instanceof Route && ! $request->ajax() && ! $request->prefetch() && ! $request->isPrecognitive()) {
            $percorso = Ritorno::dallaRichiesta($request);
            if ($percorso !== null) {
                $session->setPreviousUrl($percorso);
            }
        }
    }
}
