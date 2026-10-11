<?php

namespace Zeiras\Auth\Errori;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Il 403 `accettazione_richiesta` (#1699): la persona non ha accettato la versione in vigore di un documento legale, e il
 * backoffice non le apre altro che l'accettazione. È un ErroreApi come gli altri (si può catturare e decidere su `codice`, con
 * i `mancanti` fra le estensioni); se nessuno lo cattura, la pagina manda la persona alla pagina dell'accettazione di zr-home
 * (`zr-auth.accettazione`), che la rimanda indietro. Una richiesta JSON ha il 403 con lo stesso codice, una visita di Inertia il 409
 * con `X-Inertia-Location`. Non è un guasto: niente nel log.
 */
final class AccettazioneRichiesta extends ErroreApi
{
    /** Quanto può essere lungo il `ritorno`: zr-home lo rifiuta oltre, e manda la persona alla Dashboard. */
    private const RITORNO_MAX = 2000;

    public static function da(ErroreApi $errore): self
    {
        return new self($errore->stato, $errore->codice, $errore->titolo, $errore->dettaglio, $errore->errori, $errore->riprovaFra, $errore->estensioni);
    }

    public function report(): void {}

    public function render(Request $richiesta): Response
    {
        $accettazione = (string) config('zr-auth.accettazione');
        $json = new JsonResponse(['codice' => $this->codice, 'mancanti' => $this->estensioni['mancanti'] ?? []], 403);

        // La pagina dell'accettazione chiama il backoffice anche lei: se un suo metodo desse 403, rimandarla a sé stessa sarebbe un ciclo.
        if (self::eLaPaginaDellAccettazione($richiesta, $accettazione)) {
            return $json;
        }

        $inertia = $richiesta->header('X-Inertia') !== null;

        if (! $inertia && $richiesta->expectsJson()) {
            return $json;
        }

        $verso = $accettazione.(str_contains($accettazione, '?') ? '&' : '?').http_build_query(['ritorno' => self::ritorno($richiesta)], encoding_type: PHP_QUERY_RFC3986);

        return $inertia ? new Response('', 409, ['X-Inertia-Location' => $verso]) : redirect()->to($verso);
    }

    /** Da dove la persona è partita: l'indirizzo della richiesta se è una GET in https e non troppo lungo; altrimenti la radice del frontend. */
    private static function ritorno(Request $richiesta): string
    {
        $indirizzo = $richiesta->fullUrl();

        if ($richiesta->isMethod('GET') && str_starts_with($indirizzo, 'https://') && strlen($indirizzo) <= self::RITORNO_MAX) {
            return $indirizzo;
        }

        return $richiesta->root();
    }

    /** Stesso host e stesso percorso dell'accettazione, con o senza il prefisso della lingua davanti (`/<lingua>/legale/accetta`). */
    private static function eLaPaginaDellAccettazione(Request $richiesta, string $accettazione): bool
    {
        $parti = parse_url($accettazione);

        if (! isset($parti['host'], $parti['path']) || strcasecmp($parti['host'], $richiesta->getHost()) !== 0) {
            return false;
        }

        return preg_match('#^(?:/[a-z]{2}(?:-[A-Za-z]{2})?)?'.preg_quote(rtrim($parti['path'], '/'), '#').'/?$#', $richiesta->getPathInfo()) === 1;
    }
}
