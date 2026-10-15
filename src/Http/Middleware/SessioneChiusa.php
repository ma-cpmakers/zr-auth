<?php

namespace Zeiras\Auth\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;
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

        if (! $richiesta->hasSession()) {
            return $risposta;
        }

        // L'id con cui la richiesta è partita e quello che ha a fine corsa (diverso se la richiesta ha cambiato sessione da sé).
        $iniziale = $richiesta->attributes->get(RicordaLIdDellaSessione::ATTRIBUTO);
        $ids = array_values(array_unique([is_string($iniziale) ? $iniziale : $richiesta->session()->getId(), $richiesta->session()->getId()]));

        try {
            // Tardiva: lo dice la richiesta stessa (ha trovato la sua sessione già chiusa quando ha provato a cambiarla), o uno degli id
            // è chiuso da un'altra richiesta. Un id che ha chiuso lei, con un ingresso suo, non conta.
            $mie = (array) $richiesta->attributes->get(Sessione::MIE, []);
            $chiusa = $richiesta->attributes->get(Sessione::TARDIVA) === true
                || array_filter($ids, fn (string $id) => ! in_array($id, $mie, true) && Sessione::eChiusa($id)) !== [];
        } catch (Throwable $errore) {
            // La cache non risponde: non si sa se la sessione è chiusa, e il lato prudente è rifiutare. Nessun cookie esce (al browser
            // resta quello che ha), la risposta è 503 e chi chiama riprova tra un istante. La sessione non si distrugge: un guasto
            // della cache non deve far uscire tutti.
            report($errore);

            return self::senzaCookie(response('Riprova tra un istante.', 503, ['Retry-After' => '1', 'Cache-Control' => 'no-store']));
        }

        if (! $chiusa) {
            return $risposta;
        }

        // La persona si legge prima di distruggere la sessione (#2157).
        $prova = self::eUnaProva();

        // Anche la sessione nuova di una richiesta tardiva che ha cambiato id da sé: nessun cookie la porta al browser.
        foreach ($ids as $id) {
            $richiesta->session()->getHandler()->destroy($id);
        }

        self::senzaCookie($risposta);

        // Né l'id né il percorso con i valori: solo che è successo e dove (il disegno della rotta), e se la persona è un'identità di prova
        // (un booleano: nessuna email, nessun id).
        Log::info('zr-auth: scartata la risposta tardiva di una sessione chiusa', [
            'metodo' => $richiesta->method(),
            'rotta' => $richiesta->route()?->uri(),
            'prova' => $prova,
        ]);

        return $risposta;
    }

    /** Vero se l'email della persona della sessione comincia per uno dei prefissi di prova (`zr-auth.prova.email_prefissi`). */
    private static function eUnaProva(): bool
    {
        try {
            $email = Sessione::utente()['email'] ?? null;
        } catch (Throwable) {
            return false;
        }

        if (! is_string($email)) {
            return false;
        }

        $email = strtolower($email);
        foreach ((array) config('zr-auth.prova.email_prefissi', ['uat+', 'uat-']) as $prefisso) {
            if (is_string($prefisso) && $prefisso !== '' && str_starts_with($email, strtolower($prefisso))) {
                return true;
            }
        }

        return false;
    }

    /** Toglie dalla risposta il cookie di sessione e quello del gettone CSRF: al browser resta quello che ha. */
    private static function senzaCookie(Response $risposta): Response
    {
        $percorso = config('session.path') ?: '/';
        $dominio = config('session.domain');

        foreach ([config('session.cookie'), 'XSRF-TOKEN'] as $nome) {
            $risposta->headers->removeCookie((string) $nome, (string) $percorso, is_string($dominio) ? $dominio : null);
        }

        return $risposta;
    }
}
