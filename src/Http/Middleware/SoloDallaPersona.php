<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Persona;

/**
 * «Resta poi Salva» (#2136): una scrittura vale solo dalla pagina della persona che è nella sessione. Il cookie di sessione è
 * uno per browser: se A lascia una scheda aperta e B entra da un'altra, la scrittura della scheda di A partirebbe col gettone di
 * B. Ogni scrittura porta allora, in `X-Zr-Persona`, l'impronta della persona della pagina (Persona::impronta()), e qui si
 * confronta con quella della sessione, prima della rotta e senza chiamare il backoffice. Diversa, vuota o assente (la scheda
 * aperta prima del deploy è una scheda ferma anche lei) → 409 `persona_diversa`, e nient'altro: la scheda ferma non legge né chi
 * è entrato né la sua impronta. Se l'intestazione è ripetuta valgono tutte.
 *
 * Gira dopo ConGettone, e solo con `zr-auth.persona.controllo` acceso: un modulo che ha la sua copia la tiene finché non passa
 * a questa. GET, HEAD e OPTIONS passano. Una rotta che non deve averlo («Esci»: chiudere la sessione è il lato sicuro) se lo
 * toglie con `withoutMiddleware(SoloDallaPersona::class)`. Senza sessione passa: la strada è quella della guardia.
 */
final class SoloDallaPersona
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next): Response
    {
        if (config('zr-auth.persona.controllo') !== true || in_array($richiesta->getMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($richiesta);
        }

        $attesa = Persona::impronta();
        if ($attesa === null) {
            return $next($richiesta);
        }

        $dette = [];
        foreach ($richiesta->headers->all(Persona::INTESTAZIONE) as $valore) {
            // Due intestazioni dello stesso nome arrivano unite da una virgola: ognuna deve essere quella giusta.
            foreach (explode(',', (string) $valore) as $detta) {
                $dette[] = trim($detta);
            }
        }

        $valgono = $dette !== [];
        foreach ($dette as $detta) {
            $valgono = hash_equals($attesa, $detta) && $valgono;
        }

        return $valgono ? $next($richiesta) : new JsonResponse(['errore' => 'persona_diversa'], Response::HTTP_CONFLICT);
    }
}
