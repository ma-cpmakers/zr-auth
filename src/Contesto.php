<?php

namespace Zeiras\Auth;

/**
 * Chi e dove, nella richiesta: la persona, il workspace della sessione e il suo ruolo lì. Lo apre Sessione; senza (console,
 * coda, le rotte senza sessione) è vuoto, e un modello DelWorkspace non trova niente. Un job che lavora per un workspace lo
 * apre da sé.
 */
final class Contesto
{
    private ?int $persona = null;

    private ?int $workspace = null;

    private ?string $nome = null;

    private ?string $ruolo = null;

    public function apri(int $persona, int $workspace, string $nome, string $ruolo): void
    {
        $this->persona = $persona;
        $this->workspace = $workspace;
        $this->nome = $nome;
        $this->ruolo = $ruolo;
    }

    public function chiudi(): void
    {
        $this->persona = null;
        $this->workspace = null;
        $this->nome = null;
        $this->ruolo = null;
    }

    public function personaId(): ?int
    {
        return $this->persona;
    }

    public function persona(): ?Persona
    {
        return $this->persona === null ? null : Persona::query()->find($this->persona);
    }

    public function workspaceId(): ?int
    {
        return $this->workspace;
    }

    public function workspaceNome(): ?string
    {
        return $this->nome;
    }

    /** Il ruolo della persona nel workspace, come lo dice zr-home: `owner`, `admin` o `member`. */
    public function ruolo(): ?string
    {
        return $this->ruolo;
    }
}
