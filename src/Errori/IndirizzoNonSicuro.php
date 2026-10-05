<?php

namespace Zeiras\Auth\Errori;

use LogicException;

/** ZR_API_URL non è https: sulla porta 80 il server risponde 301, e un 301 trasforma un POST in un GET. */
final class IndirizzoNonSicuro extends LogicException
{
    public function __construct(string $indirizzo)
    {
        parent::__construct("ZR_API_URL dev'essere https://, non «{$indirizzo}»: il gettone non parte in chiaro.");
    }
}
