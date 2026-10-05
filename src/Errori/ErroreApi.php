<?php

namespace Zeiras\Auth\Errori;

use Illuminate\Http\Client\Response;
use RuntimeException;

/**
 * Un errore di /v1: un problem details di RFC 9457 (`application/problem+json`). Si decide su `codice`, mai su `titolo`
 * o `dettaglio`, che sono testi per le persone, nella lingua della risposta: il `dettaglio` si mostra.
 */
class ErroreApi extends RuntimeException
{
    /**
     * @param  list<array<string, string>>  $errori  nel 422 dati_non_validi: un elemento per valore rifiutato, con
     *                                              `detail` e uno fra `pointer`, `parameter` e `header`
     * @param  int|null  $riprovaFra  i secondi di `Retry-After` (429 troppe_richieste)
     */
    public function __construct(
        public readonly int $stato,
        public readonly ?string $codice,
        public readonly ?string $titolo,
        public readonly ?string $dettaglio,
        public readonly array $errori = [],
        public readonly ?int $riprovaFra = null,
    ) {
        // Nel messaggio stato e codice soltanto: va nei log del frontend, e i testi per le persone restano fuori.
        parent::__construct("Il backoffice ha risposto {$stato}".($codice !== null ? " {$codice}" : '').'.');
    }

    /** @param  array<mixed>|null  $corpo  il problema già letto, o null se la risposta non è JSON */
    public static function daRisposta(Response $risposta, ?array $corpo): self
    {
        $testo = fn (string $campo): ?string => isset($corpo[$campo]) && is_string($corpo[$campo]) ? $corpo[$campo] : null;
        $errori = isset($corpo['errors']) && is_array($corpo['errors']) ? array_values(array_filter($corpo['errors'], 'is_array')) : [];
        $riprova = $risposta->header('Retry-After');

        return new self(
            $risposta->status(),
            $testo('codice'),
            $testo('title'),
            $testo('detail'),
            $errori,
            ctype_digit($riprova) ? (int) $riprova : null,
        );
    }
}
