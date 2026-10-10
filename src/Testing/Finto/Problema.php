<?php

namespace Zeiras\Auth\Testing\Finto;

use RuntimeException;

/**
 * Un errore di /v1 nel backoffice finto: un codice del catalogo, i valori rifiutati se è `dati_non_validi`, gli header
 * della risposta. Lo scrive BackofficeFinto, coi testi del backoffice (Testi).
 */
final class Problema extends RuntimeException
{
    /** Lo stato HTTP dei codici che il finto usa, come nel catalogo del contratto. */
    private const STATI = [
        'gettone_assente' => 401,
        'gettone_non_valido' => 401,
        'gettone_con_workspace' => 403,
        'gettone_senza_workspace' => 403,
        'permesso_negato' => 403,
        'cliente_non_riconosciuto' => 401,
        'app_non_attiva' => 403,
        'registrazione_non_aperta' => 403,
        'non_trovato' => 404,
        'app_in_arrivo' => 409,
        'gia_membro' => 409,
        'invito_esistente' => 409,
        'collegamento_esistente' => 409,
        'scheda_archiviata' => 409,
        'limite_raggiunto' => 409,
        'proprietario_intoccabile' => 409,
        'dati_non_validi' => 422,
        'collegamento_circolare' => 422,
        'catena_troppo_lunga' => 422,
        'credenziali_non_valide' => 422,
        'chiave_idempotenza_riusata' => 422,
        'verifica_non_riuscita' => 422,
        'email_del_provider_non_verificata' => 422,
        'turnstile_non_valido' => 422,
        'troppe_richieste' => 429,
        'servizio_non_disponibile' => 503,
        'turnstile_non_disponibile' => 503,
    ];

    /**
     * @param  list<array{detail: string, pointer?: string, parameter?: string}>  $errori  per `dati_non_validi`, e solo per lui
     * @param  array<string, string>  $header  per `troppe_richieste` Retry-After, in secondi
     * @param  array<string, int|string>  $estensioni  i membri estesi del problema: `limite` e `direzione` di `limite_raggiunto`
     * @param  ?string  $verifica  per `verifica_non_riuscita`, e solo per lui: l'operazione di cui il `detail` dice il caso
     *                             (`email`, `password`, `ingresso`, `invito`, `registrazione`, `provider`)
     */
    public function __construct(
        public readonly string $codice,
        public readonly array $errori = [],
        public readonly array $header = [],
        public readonly array $estensioni = [],
        public readonly ?string $verifica = null,
    ) {
        parent::__construct($codice);
    }

    public function stato(): int
    {
        return self::STATI[$this->codice];
    }
}
