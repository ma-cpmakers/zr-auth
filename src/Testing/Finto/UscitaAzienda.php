<?php

namespace Zeiras\Auth\Testing\Finto;

use Illuminate\Http\Client\Request;

/**
 * L'uscita da un'azienda nel backoffice finto (#2740): `io.aziende.uscita.crea` e i due campi di `io.aziende.elenca` che dicono se la persona
 * può lasciarla (`uscita_negata`, `uscita_negata_workspace`). Una funzione sola (`ostacoliDelleAziende`, come UscitaDalWorkspace del backoffice)
 * per il 409 e per l'elenco: le liste non divergono. Il finto non modella le notifiche né gli eventi che `lascia()` scrive.
 */
trait UscitaAzienda
{
    /**
     * Per ogni azienda in cui la persona è membro di almeno un workspace vivo (senza un'eliminazione programmata): l'ostacolo (il codice del 409 o
     * null), i workspace che lo causano `[{id, nome}]` e i workspace vivi che lascerebbe. Per gravità: `proprieta_da_trasferire` (proprietaria di
     * almeno uno: quelli), `ultimo_workspace` (dopo l'uscita non le resta nessun workspace vivo in nessuna azienda: quelli che lascerebbe),
     * `unico_amministratore` (l'unica amministratrice di almeno uno, il proprietario non conta: quelli). Nell'ordine degli id, come il backoffice.
     *
     * @return array<string, array{ostacolo: ?string, workspace: list<array{id: string, nome: string}>, vivi: list<string>}>
     */
    private function ostacoliDelleAziende(string $persona): array
    {
        $vivi = array_keys(array_filter($this->membri, fn (array $ruoli, string $workspace) => isset($ruoli[$persona]) && ! isset($this->eliminazioni[$workspace]), ARRAY_FILTER_USE_BOTH));
        sort($vivi);
        $perAzienda = [];

        foreach ($vivi as $workspace) {
            $perAzienda[$this->workspace[$workspace]['azienda_id']][] = $workspace;
        }

        $voci = fn (array $elenco) => array_map(fn (string $workspace) => ['id' => $workspace, 'nome' => $this->workspace[$workspace]['nome']], $elenco);
        $esiti = [];

        foreach ($perAzienda as $azienda => $suoi) {
            $proprietari = array_values(array_filter($suoi, fn (string $workspace) => $this->membri[$workspace][$persona] === 'proprietario'));
            $unici = array_values(array_filter($suoi, fn (string $workspace) => $this->membri[$workspace][$persona] === 'amministratore'
                && array_filter($this->membri[$workspace], fn (string $ruolo, string $id) => $ruolo === 'amministratore' && $id !== $persona, ARRAY_FILTER_USE_BOTH) === []));

            [$ostacolo, $coinvolti] = match (true) {
                $proprietari !== [] => ['proprieta_da_trasferire', $proprietari],
                array_diff($vivi, $suoi) === [] => ['ultimo_workspace', $suoi],
                $unici !== [] => ['unico_amministratore', $unici],
                default => [null, []],
            };

            $esiti[$azienda] = ['ostacolo' => $ostacolo, 'workspace' => $voci($coinvolti), 'vivi' => $suoi];
        }

        return $esiti;
    }

    /**
     * io.aziende.uscita.crea (IoAziendeController::uscita): la persona lascia tutti i workspace vivi dell'azienda di cui è membro, con qualunque
     * suo gettone. Nell'ordine del backoffice: il gettone, il corpo (422, nessun campo), poi 404 se in quell'azienda non ha nessun workspace vivo
     * (o non c'è: lo stesso per tutti), il 409 col suo codice e i workspace coinvolti accanto (`workspace_da_trasferire`, `workspace_da_lasciare`,
     * `workspace_senza_amministratore`), altrimenti 204. I gettoni di quei workspace smettono di valere; quello dell'accesso resta.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, null}
     */
    private function lasciaLAzienda(Request $richiesta, array $corpo, string $azienda): array
    {
        $persona = $this->autentica($richiesta)['persona'];
        $this->testi->validaStretta($corpo, []);

        $esito = $this->ostacoliDelleAziende($persona)[$azienda] ?? throw new Problema('non_trovato');

        if ($esito['ostacolo'] !== null) {
            $chiave = match ($esito['ostacolo']) {
                'proprieta_da_trasferire' => 'workspace_da_trasferire',
                'ultimo_workspace' => 'workspace_da_lasciare',
                default => 'workspace_senza_amministratore',
            };

            throw new Problema($esito['ostacolo'], estensioni: [$chiave => $esito['workspace']]);
        }

        foreach ($esito['vivi'] as $workspace) {
            $this->togliIlMembro($workspace, $persona);
        }

        return [204, null];
    }
}
