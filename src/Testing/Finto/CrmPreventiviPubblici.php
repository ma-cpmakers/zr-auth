<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;

/**
 * Il link pubblico di un preventivo del CRM nel finto (#2391, CRM-06): `preventivi.pubblici.lettura.crea`, `.accettazione.crea` e
 * `.richieste.crea`, senza gettone, come PreventiviPubbliciController del backoffice. Il token sta nel corpo; sconosciuto, di una bozza o di
 * un preventivo eliminato è lo stesso 404; il freno è per token. Il link lo emette chi manda l'email (`crm.preventivi.invio.crea` con
 * `modo: email`, di #2390): fino ad allora un test lo chiede al finto con `emettiLinkDelPreventivo()`. Non modella la notifica
 * `crm.preventivo.richiesta` (il finto non ha le notifiche del CRM).
 */
trait CrmPreventiviPubblici
{
    /** Per quanti giorni un link si apre ancora dopo che il preventivo è accettato, rifiutato o scaduto (PreventiviPubblici::GIORNI_DOPO_LA_CHIUSURA, #2436). */
    private const CRM_LINK_GIORNI_DOPO_LA_CHIUSURA = 90;

    /** Quante richieste al più per preventivo (RichiestaDelPreventivo::TETTO del backoffice). */
    private const CRM_RICHIESTE_PREVENTIVO_TETTO = 50;

    /**
     * Emette un link per un preventivo e dà il token (43 caratteri di base64url): il finto tiene solo il suo SHA-256, come il backoffice
     * tiene l'impronta. Ne può emettere quanti ne vuole per lo stesso preventivo.
     */
    public function emettiLinkDelPreventivo(string $preventivo): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $this->crm['link_preventivi'][hash('sha256', $token)] = strtolower($preventivo);

        return $token;
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function leggiPreventivoPubblico(array $corpo): array
    {
        $token = $this->tokenDelPreventivoPubblico($corpo, [], 'lettura');
        $riga = $this->apriIlPreventivoPubblico($token);

        if ($riga['stato'] === 'inviato' && ($riga['visto_il'] ?? null) === null && ! $this->contattoDimenticatoDelPreventivoPubblico($riga)) {
            $ora = self::dopoCrm($riga['aggiornato_il']);
            $this->crm['preventivi'][$riga['id']]['stato'] = 'visto';
            $this->crm['preventivi'][$riga['id']]['visto_il'] = $ora;
            $this->crm['preventivi'][$riga['id']]['aggiornato_il'] = $ora;
            $riga = $this->crm['preventivi'][$riga['id']];
        }

        return [200, ['data' => $this->documentoDelPreventivoPubblico($riga)]];
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function accettaPreventivoPubblico(array $corpo): array
    {
        $campi = $this->tokenDelPreventivoPubblico($corpo, [
            'nome' => ['bail', 'required', 'string', 'max:200', new NomeScritto],
            'accetto' => ['bail', 'required', 'boolean:strict', 'accepted'],
        ], 'accettazione', true);
        $riga = $this->apriIlPreventivoPubblico($campi['token']);

        if ($riga['stato'] === 'sostituito') {
            throw new Problema('preventivo_sostituito');
        }

        // Gli stati prima del contatto (PreventiviPubblici::accetta): un preventivo di sola azienda, o il cui contatto è archiviato o non c'è più, non
        // si accetta dal link e al cliente non si dice perché. Accettato e scaduto si dicono per primi: li dice accettaIlPreventivoCrm.
        // Un contatto che ha chiesto l'oblio non si dice al cliente: stesso 409 di un contatto archiviato, in ogni stato.
        if ($this->contattoDimenticatoDelPreventivoPubblico($riga)) {
            throw new Problema('preventivo_stato_non_ammesso');
        }

        if (in_array(self::statoDelPreventivoCrm($riga), ['inviato', 'visto'], true)) {
            $contatto = $riga['persona_id'] === null ? null : ($this->crm['persone'][$riga['persona_id']] ?? null);

            if ($contatto === null || ($contatto['eliminata'] ?? false) || $contatto['archiviata_il'] !== null) {
                throw new Problema('preventivo_stato_non_ammesso');
            }
        }

        $this->accettaIlPreventivoCrm($riga['workspace'], null, ['nome' => $campi['nome']], $riga['id'], 'online');

        return [201, ['data' => $this->documentoDelPreventivoPubblico($this->crm['preventivi'][$riga['id']])]];
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function chiediModificaPreventivoPubblico(array $corpo): array
    {
        $campi = $this->tokenDelPreventivoPubblico($corpo, ['messaggio' => ['bail', 'required', 'string', 'max:2000', new SenzaCarattereNullo]], 'richieste', true);
        $riga = $this->apriIlPreventivoPubblico($campi['token']);

        if ($riga['stato'] === 'sostituito') {
            throw new Problema('preventivo_sostituito');
        }

        if ($this->contattoDimenticatoDelPreventivoPubblico($riga)) {
            throw new Problema('preventivo_stato_non_ammesso');
        }

        if ($riga['stato'] === 'accettato') {
            throw new Problema('gia_accettato');
        }

        if ($riga['stato'] === 'rifiutato') {
            throw new Problema('preventivo_stato_non_ammesso');
        }

        // Al più 50 richieste per preventivo (RichiestaDelPreventivo::TETTO): oltre, 409 limite_raggiunto.
        if (count(array_filter($this->crm['richieste_preventivo'] ?? [], fn (array $richiesta) => $richiesta['preventivo_id'] === $riga['id'])) >= self::CRM_RICHIESTE_PREVENTIVO_TETTO) {
            throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_RICHIESTE_PREVENTIVO_TETTO]);
        }

        $ora = self::adessoCrm();
        $this->crm['richieste_preventivo'][] = ['id' => self::id(), 'preventivo_id' => $riga['id'], 'messaggio' => $campi['messaggio'], 'creata_il' => $ora];
        $trattativa = $riga['trattativa_id'] === null ? null : ($this->crm['trattative'][$riga['trattativa_id']] ?? null);

        if ($trattativa !== null && ! ($trattativa['eliminata'] ?? false)) {
            $this->cambioCrm('trattative', $trattativa['id'], null, 'preventivo.richiesta', [['campo' => 'preventivo_id', 'dopo' => $riga['id']]]);
        }

        return [201, ['data' => ['creata_il' => self::iso($ora)]]];
    }

    /**
     * Il contatto del preventivo ha chiesto l'oblio (`anonimizzata_il`): dal link non si legge più come «visto», non si accetta e non si chiede,
     * e al cliente non si dice perché (PreventiviPubblici del backoffice).
     *
     * @param  array<string, mixed>  $riga
     */
    private function contattoDimenticatoDelPreventivoPubblico(array $riga): bool
    {
        return $riga['persona_id'] !== null && ($this->crm['persone'][$riga['persona_id']]['anonimizzata_il'] ?? null) !== null;
    }

    /**
     * Il corpo validato con il token (43 caratteri di base64url) e, prima di guardare il preventivo, il freno di quel token. Senza altre regole
     * dà il solo token; con le regole, tutti i campi validati.
     *
     * @param  array<mixed>  $corpo
     * @param  array<string, mixed>  $altre
     * @return ($tutti is true ? array<string, mixed> : string)
     */
    private function tokenDelPreventivoPubblico(array $corpo, array $altre, string $metodo, bool $tutti = false): array|string
    {
        $campi = $this->testi->validaStretta($corpo, ['token' => ['bail', 'required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/']] + $altre);
        $token = (string) $campi['token'];
        $massimo = self::FRENI['preventivi_pubblici_'.['lettura' => 'letture', 'accettazione' => 'accettazioni', 'richieste' => 'richieste'][$metodo].'_per_token'];
        $this->frena("preventivi-pubblici-{$metodo}:".hash('sha256', $token), $massimo, self::MINUTO);

        return $tutti ? $campi : $token;
    }

    /**
     * Il preventivo di un token (PreventiviPubblici::apri): sconosciuto, di una bozza o eliminato è 404 `non_trovato`; un sostituito vale
     * finché sarebbe stato valido, poi è 404.
     *
     * @return array<string, mixed>
     */
    private function apriIlPreventivoPubblico(string $token): array
    {
        $id = $this->crm['link_preventivi'][hash('sha256', $token)] ?? null;
        $riga = $id === null ? null : ($this->crm['preventivi'][$id] ?? null);

        if ($riga === null || ($riga['eliminata'] ?? false) || $riga['stato'] === 'bozza') {
            throw new Problema('non_trovato');
        }

        if ($riga['stato'] === 'sostituito' && $riga['valido_fino_al'] < self::oggiAcquistoCrm()) {
            throw new Problema('non_trovato');
        }

        // Accettato, rifiutato o scaduto da novanta giorni: il link si è spento (il giorno 89 si apre, il giorno 90 no).
        $chiusoDal = match (self::statoDelPreventivoCrm($riga)) {
            'accettato' => $riga['accettato_il'],
            'rifiutato' => CarbonImmutable::instance($riga['rifiutato_il'])->setTimezone(self::CRM_ACQUISTI_FUSO)->format('Y-m-d'),
            'scaduto' => CarbonImmutable::parse($riga['valido_fino_al'], self::CRM_ACQUISTI_FUSO)->addDay()->format('Y-m-d'),
            default => null,
        };

        if ($chiusoDal !== null && CarbonImmutable::parse($chiusoDal, self::CRM_ACQUISTI_FUSO)->addDays(self::CRM_LINK_GIORNI_DOPO_LA_CHIUSURA)->format('Y-m-d') <= self::oggiAcquistoCrm()) {
            throw new Problema('non_trovato');
        }

        return $riga;
    }

    /**
     * Il documento del cliente (Forme::preventivoDelCliente): solo ciò che serve a leggerlo e a decidere, senza nessun id. Di un sostituito
     * lo stato, il workspace e `rispondi_a`, l'email di chi l'ha creato.
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function documentoDelPreventivoPubblico(array $riga): array
    {
        $workspace = ['nome' => $this->workspace[$riga['workspace']]['nome'], 'logo' => null];

        if ($riga['stato'] === 'sostituito') {
            return ['stato' => 'sostituito', 'workspace' => $workspace, 'rispondi_a' => $this->persone[$riga['creato_da'] ?? ''] ['email'] ?? null];
        }

        $voce = $this->vocePreventivoCrm($riga);

        return [
            'numero' => $voce['numero'],
            'stato' => $voce['stato'],
            'workspace' => $workspace,
            'valido_fino_al' => $voce['valido_fino_al'],
            'valuta' => $voce['valuta'],
            'righe' => array_map(fn (array $uno) => array_intersect_key($uno, array_flip(['posizione', 'descrizione', 'quantita', 'prezzo_unitario', 'sconto_percento', 'aliquota_iva', 'iva_inclusa', 'importo'])), $voce['righe']),
            'piano' => $voce['piano'],
            'introduzione' => $voce['introduzione'],
            'condizioni' => $voce['condizioni'],
            'totali' => $voce['totali'],
            'visto_il' => $voce['visto_il'],
            'accettato_il' => $voce['accettato_il'],
        ];
    }
}
