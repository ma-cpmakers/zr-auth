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
    /** I membri di un problema che hanno un posto loro nell'errore: gli altri sono le estensioni. */
    private const STANDARD = ['type', 'title', 'status', 'detail', 'codice', 'errors', 'instance'];

    /**
     * @param  list<array<string, string>>  $errori  nel 422 dati_non_validi: un elemento per valore rifiutato, con
     *                                              `detail` e uno fra `pointer`, `parameter` e `header`
     * @param  int|null  $riprovaFra  i secondi di `Retry-After` (429 troppe_richieste)
     * @param  array<string, int|float|string|bool|array<mixed>>  $estensioni  i membri estesi del problema (RFC 9457, §3.2), per
     *                                                            nome, scalari o elenchi: oggi `limite` e `direzione` del 409
     *                                                            `limite_raggiunto` di `board.schede.collegamenti.crea`, `schede` del
     *                                                            409 `attese_aperte`, `liste_consentite` del 409
     *                                                            `passaggio_non_consentito`, `limite` del 409 `lista_al_limite` e
     *                                                            `voci_aperte` del 409 `checklist_incompleta`
     */
    public function __construct(
        public readonly int $stato,
        public readonly ?string $codice,
        public readonly ?string $titolo,
        public readonly ?string $dettaglio,
        public readonly array $errori = [],
        public readonly ?int $riprovaFra = null,
        public readonly array $estensioni = [],
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
        // Ogni altro membro del problema, se è un valore semplice o un elenco: i membri riservati (i testi per le persone,
        // `errors`, `instance`) hanno un posto loro e un'estensione con quel nome non li sostituisce.
        $estensioni = array_filter(
            is_array($corpo) ? $corpo : [],
            fn (mixed $valore, mixed $nome) => is_string($nome) && ! in_array($nome, self::STANDARD, true) && (is_scalar($valore) || is_array($valore)),
            ARRAY_FILTER_USE_BOTH,
        );

        return new self(
            $risposta->status(),
            $testo('codice'),
            $testo('title'),
            $testo('detail'),
            $errori,
            ctype_digit($riprova) ? (int) $riprova : null,
            $estensioni,
        );
    }
}
