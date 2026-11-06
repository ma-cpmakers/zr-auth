<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;
use InvalidArgumentException;

/**
 * Le aziende di cui una persona è cliente, nel backoffice finto (#3321, zr-core #3319): `io.fornitori.nascosti.elenca`, `.crea` e `.elimina`, con le regole,
 * i pointer e l'ordine di `IoFornitoriNascostiController` del backoffice. Il finto non ha contatti del CRM per questo: la relazione da cliente si dichiara
 * con cliente() (un contatto vivo con l'email principale della persona e lo spazio del cliente acceso, nel backoffice vero). Vale se la persona ha
 * l'email verificata e il workspace non è in eliminazione. Le aziende si ordinano per nome (confronto byte per byte, non per ordine alfabetico del
 * database) e poi per id. Il finto non emette gli eventi.
 */
trait Fornitori
{
    /** @var array<string, array<string, bool>> workspace => persona => lo spazio del cliente è acceso */
    private array $clienti = [];

    /** @var array<string, array<string, true>> persona => workspace => nascosto */
    private array $fornitoriNascosti = [];

    /**
     * Dichiara che la persona è cliente del workspace, come se il workspace avesse un suo contatto vivo con l'email principale della persona.
     *
     * @param  array<string, mixed>  $workspace  un workspace di workspace()
     * @param  array<string, mixed>  $persona  una persona di persona()
     * @param  bool  $spazioCliente  lo spazio del cliente del contatto (`spazio_cliente`): spento, il workspace non compare
     */
    public function cliente(array $workspace, array $persona, bool $spazioCliente = true): self
    {
        $idWorkspace = $workspace['id'] ?? null;
        $idPersona = $persona['id'] ?? null;

        if (! is_string($idWorkspace) || ! isset($this->workspace[$idWorkspace])) {
            throw new InvalidArgumentException('Il workspace non è del finto: nasce con workspace().');
        }

        if (! is_string($idPersona) || ! isset($this->persone[$idPersona])) {
            throw new InvalidArgumentException('La persona non è del finto: nasce con persona().');
        }

        $this->clienti[$idWorkspace][$idPersona] = $spazioCliente;

        return $this;
    }

    /**
     * io.fornitori.nascosti.elenca (IoFornitoriNascostiController::elenca): i nascosti della persona del gettone di cui è ancora cliente, per nome e poi id.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaFornitoriNascosti(Request $richiesta): array
    {
        $chi = $this->autentica($richiesta);
        $voci = array_values(array_filter($this->fornitoriDellaPersona($chi['persona']), fn (array $voce) => isset($this->fornitoriNascosti[$chi['persona']][$voce['id']])));

        return $this->pagina(
            $richiesta,
            'io.fornitori.nascosti.elenca',
            'id',
            $voci,
            fn (array $voce) => [$voce['nome'], $voce['id']],
            fn (string $id) => isset($this->workspace[$id]) ? [$this->workspace[$id]['nome'], $id] : null,
        );
    }

    /**
     * io.fornitori.nascosti.crea (IoFornitoriNascostiController::crea): nasconde l'azienda, 201 la prima volta e 200 con la stessa forma dopo. Un workspace di cui la
     * persona non è cliente, o che non c'è, è 404.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function nascondiFornitore(Request $richiesta, array $corpo): array
    {
        $chi = $this->autentica($richiesta);
        $this->frena('io-fornitori-nascosti:'.$chi['persona'], self::FRENI['io_fornitori_nascosti_per_persona'], self::ORA);
        $campi = $this->testi->validaStretta($corpo, ['workspace_id' => ['bail', 'required', 'string', 'ulid']]);
        $voce = $this->fornitoriDellaPersona($chi['persona'])[strtolower($campi['workspace_id'])] ?? throw new Problema('non_trovato');

        $nuovo = ! isset($this->fornitoriNascosti[$chi['persona']][$voce['id']]);
        $this->fornitoriNascosti[$chi['persona']][$voce['id']] = true;

        return [$nuovo ? 201 : 200, ['data' => $voce]];
    }

    /**
     * io.fornitori.nascosti.elimina (IoFornitoriNascostiController::elimina): rimette l'azienda, 204 anche se non era nascosta. Un workspace di cui la persona
     * non è cliente, che non c'è o con un id fuori forma è 404.
     *
     * @return array{int, null}
     */
    private function rimettiFornitore(Request $richiesta, string $workspace): array
    {
        $chi = $this->autentica($richiesta);
        $this->frena('io-fornitori-nascosti:'.$chi['persona'], self::FRENI['io_fornitori_nascosti_per_persona'], self::ORA);

        if (preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/i', $workspace) !== 1 || ! isset($this->fornitoriDellaPersona($chi['persona'])[strtolower($workspace)])) {
            throw new Problema('non_trovato');
        }

        unset($this->fornitoriNascosti[$chi['persona']][strtolower($workspace)]);

        return [204, null];
    }

    /**
     * Le aziende di cui la persona è cliente, per id: il workspace col suo id, nome e colore. Senza l'email verificata non ce n'è nessuna, e un workspace in
     * eliminazione non conta.
     *
     * @return array<string, array{id: string, nome: string, colore: string}>
     */
    private function fornitoriDellaPersona(string $persona): array
    {
        if (($this->persone[$persona]['email_verificata_il'] ?? null) === null) {
            return [];
        }

        $voci = [];

        foreach ($this->clienti as $workspace => $persone) {
            if (($persone[$persona] ?? false) && isset($this->workspace[$workspace]) && ! isset($this->eliminazioni[$workspace])) {
                $voci[$workspace] = ['id' => $workspace, 'nome' => $this->workspace[$workspace]['nome'], 'colore' => $this->workspace[$workspace]['colore'] ?? 'neutral'];
            }
        }

        return $voci;
    }
}
