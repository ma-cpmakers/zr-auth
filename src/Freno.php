<?php

namespace Zeiras\Auth;

/**
 * Un freno del backoffice com'era all'ultima risposta (#1911): il nome della politica, e i suoi parametri nel draft IETF
 * httpapi-ratelimit-headers. Da `RateLimit-Policy` la quota `q` e la finestra `w` in secondi; da `RateLimit` il residuo `r` e i
 * secondi `t` al ripristino. Un parametro che la risposta non dice è `null`, mai zero.
 */
final class Freno
{
    public function __construct(
        public readonly string $nome,
        public readonly int $q,
        public readonly ?int $w = null,
        public readonly ?int $r = null,
        public readonly ?int $t = null,
    ) {}
}
