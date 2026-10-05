<?php

namespace Zeiras\Auth\Testing\Finto;

use LogicException;

/**
 * Una chiamata di /v1 che il backoffice finto non conosce: un metodo che non fa ancora, o un verbo che il percorso non
 * ha. Il finto non inventa una risposta che il backoffice non darebbe: il test del frontend si ferma qui.
 */
final class RichiestaSconosciuta extends LogicException
{
    public function __construct(string $metodo, string $percorso)
    {
        parent::__construct("Il backoffice finto non conosce {$metodo} {$percorso}: i metodi che fa sono nel README di zr-auth.");
    }
}
