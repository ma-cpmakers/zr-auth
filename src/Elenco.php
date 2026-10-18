<?php

namespace Zeiras\Auth;

/**
 * Una lista a cursore letta a pagine fino a un tetto (`Api::elenco`, #2021): gli elementi letti, e se la lista continuava oltre il
 * tetto. `tagliato` è il segno che una pagina mostra («Mostrati i primi 10.000 elementi.») invece di dire che Zeiras non risponde;
 * `successivo` è il cursore da cui si riprenderebbe (null se la lista è finita).
 */
final class Elenco
{
    /**
     * @param  list<mixed>  $elementi
     */
    public function __construct(
        public readonly array $elementi,
        public readonly bool $tagliato,
        public readonly ?string $successivo,
    ) {}
}
