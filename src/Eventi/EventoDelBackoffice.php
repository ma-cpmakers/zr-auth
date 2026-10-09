<?php

namespace Zeiras\Auth\Eventi;

/**
 * Un evento del backoffice, firmato e verificato dal ricevitore (`zr-auth.eventi.percorso`), come evento di Laravel: il
 * modulo lo ascolta con un listener. Il pacchetto non guarda il tipo né se l'id esiste: un tipo nuovo passa invariato.
 */
final class EventoDelBackoffice
{
    /**
     * @param  array<mixed>  $data  il `data` dell'evento, com'è
     * @param  array<mixed>  $corpo  tutto il JSON ricevuto, decodificato: un campo che il pacchetto non conosce (`autore`, `causa`) sta qui
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly ?string $subject,
        public readonly ?string $sequence,
        public readonly array $data,
        public readonly ?string $time,
        public readonly array $corpo,
    ) {}
}
