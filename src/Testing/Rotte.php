<?php

namespace Zeiras\Auth\Testing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Zeiras\Auth\Http\Middleware\Sessione;

/**
 * Il test delle rotte che il modulo eredita (voce #978): `expect(Rotte::senzaSessione())->toBe([])` manda la CI in rosso
 * su ogni rotta che risponde senza la sessione di zr-auth.
 */
final class Rotte
{
    /** Le rotte senza sessione apposta: il ritorno da zr-home, l'avviso di zr-home, il controllo di salute. */
    public const ECCEZIONI = ['GET auth/callback', 'POST auth/avviso', 'GET up'];

    /**
     * Le rotte del modulo che rispondono senza sessione, fuori dalle eccezioni, come «METODO uri» (HEAD va con GET): quelle
     * fuori dai gruppi `web` e `api`, e quelle che si tolgono Sessione con withoutMiddleware.
     *
     * @return list<string>
     */
    public static function senzaSessione(): array
    {
        // Gruppi e priorità arrivano al router quando nasce il kernel HTTP: prima di una richiesta non ci sono ancora.
        app(Kernel::class);
        $router = app(Router::class);

        $scoperte = [];
        foreach ($router->getRoutes()->getRoutes() as $rotta) {
            $eseguiti = array_map(
                fn (mixed $middleware) => is_string($middleware) ? Str::before($middleware, ':') : '',
                $router->gatherRouteMiddleware($rotta),
            );
            if (in_array(Sessione::class, $eseguiti, true)) {
                continue;
            }
            foreach (array_diff($rotta->methods(), ['HEAD']) as $metodo) {
                $voce = $metodo.' '.$rotta->uri();
                if (! in_array($voce, self::ECCEZIONI, true)) {
                    $scoperte[] = $voce;
                }
            }
        }
        sort($scoperte);

        return $scoperte;
    }
}
