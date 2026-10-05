<?php

namespace Zeiras\Auth\Testing;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\Str;
use Zeiras\Auth\Http\Middleware\ConGettone;

/**
 * Il test delle rotte che il frontend eredita (spec S01, prova 11): una rotta senza la guardia di zr-auth manda la CI in
 * rosso.
 *
 *     expect(Rotte::senzaGuardia(['GET accedi', 'POST accedi']))->toBe([]);
 */
final class Rotte
{
    /** Le rotte senza guardia per ogni frontend: il controllo di salute. */
    public const ECCEZIONI = ['GET up'];

    /**
     * Le rotte del frontend che rispondono senza la guardia, come «METODO uri» (HEAD va con GET): quelle fuori dai gruppi
     * `web` e `api`, e quelle che se la tolgono con withoutMiddleware. Fuori dall'elenco il controllo di salute e le
     * pagine pubbliche che il frontend nomina (l'accesso, la registrazione).
     *
     * @param  list<string>  $pubbliche  le rotte senza guardia apposta, come «METODO uri»
     * @return list<string>
     */
    public static function senzaGuardia(array $pubbliche = []): array
    {
        // Gruppi e priorità arrivano al router quando nasce il kernel HTTP: prima di una richiesta non ci sono ancora.
        app(Kernel::class);
        $router = app(Router::class);
        $eccezioni = [...self::ECCEZIONI, ...$pubbliche];

        $scoperte = [];
        foreach ($router->getRoutes()->getRoutes() as $rotta) {
            $eseguiti = array_map(
                fn (mixed $middleware) => is_string($middleware) ? Str::before($middleware, ':') : '',
                $router->gatherRouteMiddleware($rotta),
            );
            if (in_array(ConGettone::class, $eseguiti, true)) {
                continue;
            }
            foreach (array_diff($rotta->methods(), ['HEAD']) as $metodo) {
                $voce = $metodo.' '.$rotta->uri();
                if (! in_array($voce, $eccezioni, true)) {
                    $scoperte[] = $voce;
                }
            }
        }
        sort($scoperte);

        return $scoperte;
    }
}
