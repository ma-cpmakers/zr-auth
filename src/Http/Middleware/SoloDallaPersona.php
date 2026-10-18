<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Zeiras\Auth\Persona;
use Zeiras\Auth\Sessione;

/**
 * «Resta poi Salva» (#2136): una scrittura vale solo dalla pagina della persona che è nella sessione. Il cookie di sessione è
 * uno per browser: se A lascia una scheda aperta e B entra da un'altra, la scrittura della scheda di A partirebbe col gettone di
 * B. Ogni scrittura porta allora, in `X-Zr-Persona`, l'impronta della persona della pagina (Persona::impronta()), e qui si
 * confronta con quella della sessione, prima della rotta e senza chiamare il backoffice. Diversa, vuota o assente (la scheda
 * aperta prima del deploy è una scheda ferma anche lei) → 409 `persona_diversa`, e nient'altro: la scheda ferma non legge né chi
 * è entrato né la sua impronta. Se l'intestazione è ripetuta valgono tutte.
 *
 * Un modulo HTML nativo (`<form method="post">`, per uno scaricamento che il browser salva man mano che arriva) non porta intestazioni: se
 * l'intestazione manca del tutto, l'impronta si legge dal campo `_zr_persona` del corpo (Persona::CAMPO), solo se è una stringa, e si
 * confronta nello stesso modo. Con l'intestazione presente, anche vuota, vale lei e il campo si ignora; mai la query: l'impronta non
 * sta in un indirizzo. Il CSRF resta quello di Laravel: l'impronta è un controllo di scheda ferma, non un segreto.
 *
 * Gira dopo ConGettone, e salvo `zr-auth.persona.controllo` spento (ZR_AUTH_PERSONA_CONTROLLO=false): un modulo che ha la sua
 * copia la spegne finché non passa a questa. GET, HEAD e OPTIONS passano (il metodo vero della richiesta: un POST con `_method=OPTIONS` non è un OPTIONS). Controlla
 * le rotte con la guardia: una pagina pubblica (`withoutMiddleware(ConGettone::class)`, l'accesso, la registrazione) è la strada
 * di chi entra, e un'altra persona deve poter entrare. Una rotta fuori dalla guardia che è un'azione della persona nella sessione
 * (il consenso, l'invito) lo vuole con `->middleware(SoloDallaPersona::class.':sempre')`. Una rotta che non deve averlo («Esci»:
 * chiudere la sessione è il lato sicuro) se lo toglie con `withoutMiddleware(SoloDallaPersona::class)`. Senza sessione passa: la
 * strada è quella della guardia. Con la sessione aperta e l'impronta che manca (`utente.id` assente, vuoto o non stringa) è 409: non c'è
 * una persona da confrontare, e la scrittura userebbe il gettone di chi c'è.
 */
final class SoloDallaPersona
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $richiesta, Closure $next, ?string $quando = null): Response
    {
        // `1`, `on` e `yes` accendono come `true`: un valore che non si legge non lascia la guardia spenta senza dirlo.
        if (! (filter_var(config('zr-auth.persona.controllo', true), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true) || in_array($richiesta->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($richiesta);
        }
        if ($quando !== 'sempre' && ! $this->conLaGuardia($richiesta)) {
            return $next($richiesta);
        }

        $attesa = Persona::impronta();
        if ($attesa === null) {
            // Senza sessione la strada è quella della guardia. Con la sessione aperta e l'impronta che manca (`utente.id` che non è
            // una stringa non vuota) la scrittura userebbe il gettone di chi c'è senza il controllo: 409, come la persona diversa (#2201).
            return Sessione::aperta() ? new JsonResponse(['errore' => 'persona_diversa'], Response::HTTP_CONFLICT) : $next($richiesta);
        }

        $dette = [];
        foreach ($richiesta->headers->all(Persona::INTESTAZIONE) as $valore) {
            // Due intestazioni dello stesso nome arrivano unite da una virgola: ognuna deve essere quella giusta.
            foreach (explode(',', (string) $valore) as $detta) {
                $dette[] = trim($detta);
            }
        }

        // Un modulo nativo non ha intestazioni: senza l'intestazione, il campo del corpo (una stringa sola, mai un elenco).
        if (! $richiesta->headers->has(Persona::INTESTAZIONE)) {
            $campo = $richiesta->request->all()[Persona::CAMPO] ?? null;

            if (is_string($campo)) {
                $dette[] = trim($campo);
            }
        }

        $valgono = $dette !== [];
        foreach ($dette as $detta) {
            $valgono = hash_equals($attesa, $detta) && $valgono;
        }

        return $valgono ? $next($richiesta) : new JsonResponse(['errore' => 'persona_diversa'], Response::HTTP_CONFLICT);
    }

    /** La rotta ha la guardia di zr-auth: una pagina pubblica se la toglie. */
    private function conLaGuardia(Request $richiesta): bool
    {
        $rotta = $richiesta->route();

        return $rotta !== null && in_array(ConGettone::class, app(Router::class)->gatherRouteMiddleware($rotta), true);
    }
}
