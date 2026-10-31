<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * L'unione dei contatti del CRM nel backoffice finto (CRM-01 F8, #2752): le coppie di possibili doppioni (`crm.persone.doppioni.elenca`),
 * l'unione (`crm.persone.unioni.crea`), la sua lettura (`.mostra`) e il suo annullamento (`.annullamento.crea`), con le regole, i pointer e l'ordine
 * dei controlli di `PersoneDoppioniController`, `PersoneUnioniController` e `App\Crm\Unioni` del backoffice. Chi resta prende note, attività e
 * opportunità di chi si unisce e, dove i suoi sono vuoti o dove `scelte` lo dice, i suoi campi; chi si unisce si archivia (`unito_in` nel suo
 * `mostra`). Si annulla entro 7 giorni. Il freno è per workspace, 60 all'ora e 300 al giorno, contato prima del lavoro, e il lavoro è in una
 * scrittura sola: un errore non lascia dati a metà. Il finto non emette gli eventi, e non ha le parti di Bookings (`link_prenotazione`,
 * `prenotazioni`) fra i `blocchi`; l'`ETag` dei contatti non è lo stesso valore del backoffice: conta la forma.
 */
trait CrmUnioni
{
    /** I giorni in cui un'unione si annulla (Unione::GIORNI), e il suo freno per workspace (config zeiras.freni.unioni_workspace_*). */
    private const CRM_UNIONI_GIORNI = 7;

    private const CRM_UNIONI_ALL_ORA = 60;

    private const CRM_UNIONI_AL_GIORNO = 300;

    /** Le voci di una lista finale di email o di telefoni (Persone::ELEMENTI), e i campi estranei al più in un errore (Corpo::ESTRANEI). */
    private const CRM_UNIONI_ELEMENTI = 5;

    private const CRM_UNIONI_ESTRANEI = 10;

    /** I campi di testo che `scelte` può decidere (Unioni::SCELTE). */
    private const CRM_UNIONI_SCELTE = ['nome', 'cognome', 'ruolo', 'professione', 'organizzazione_id', 'stato', 'responsabile_id', 'fonte'];

    /** Dal più restrittivo al meno: vince il primo (Unioni::BASI_GIURIDICHE). */
    private const CRM_UNIONI_BASI = ['consenso', 'interesse_legittimo', 'contratto', 'obbligo_legale'];

    /** Di un gruppo più grande di così danno coppie i contatti con l'id più basso (Doppioni::GRUPPO_MASSIMO), e le coppie al più per pagina. */
    private const CRM_DOPPIONI_GRUPPO = 10;

    private const CRM_DOPPIONI_LIMITE = 50;

    /**
     * crm.persone.doppioni.elenca (PersoneDoppioniController::elenca): le coppie di contatti vivi con uno stesso numero (le sole cifre, col `+` in
     * testa se c'è) o lo stesso nome e cognome (senza maiuscole né accenti) e la stessa azienda o nessuna; il primo motivo vince, i telefoni prima
     * dei nomi. In ordine di id, a cursore, `limite` da 1 a 50. Proprietario e amministratore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaDoppioniCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $this->testi->validaQuery(self::query($richiesta), ['limite' => ['sometimes', 'integer', 'between:1,'.self::CRM_DOPPIONI_LIMITE], 'cursore' => ['sometimes', 'string']]);
        $righe = array_map(fn (array $coppia) => ['id' => $coppia[0].'|'.$coppia[1], 'persone' => [$coppia[0], $coppia[1]], 'motivo' => $coppia[2]], $this->coppieDeiDoppioniCrm($chi['workspace']));

        return $this->paginaCrm(
            $richiesta,
            'crm.persone.doppioni.elenca',
            $righe,
            fn (array $una, array $altra) => strcmp($una['id'], $altra['id']),
            fn (array $riga) => ['persone' => $riga['persone'], 'motivo' => $riga['motivo']],
        );
    }

    /** @return list<array{0: string, 1: string, 2: string}> le coppie in ordine di id, ognuna col suo motivo */
    private function coppieDeiDoppioniCrm(string $workspace): array
    {
        $vive = array_filter($this->righeCrm('persone', $workspace), fn (array $persona) => $persona['archiviata_il'] === null && $persona['anonimizzata_il'] === null);
        usort($vive, fn (array $una, array $altra) => strcmp($una['id'], $altra['id']));
        $perTelefono = [];
        $perNome = [];

        foreach ($vive as $persona) {
            foreach ($persona['telefoni'] as $voce) {
                $cifre = preg_replace('/\D+/', '', $voce['numero']) ?? '';

                if ($cifre !== '') {
                    $perTelefono[(str_starts_with(ltrim($voce['numero']), '+') ? '+' : '').$cifre][] = $persona['id'];
                }
            }

            $nome = self::chiaveCrm(trim((string) $persona['nome']));
            $cognome = self::chiaveCrm(trim((string) $persona['cognome']));

            if ($nome !== '' || $cognome !== '') {
                $perNome[$nome."\u{1E}".$cognome."\u{1E}".($persona['organizzazione_id'] ?? '')][] = $persona['id'];
            }
        }

        $coppie = [];

        foreach ([[$perTelefono, 'telefono'], [$perNome, 'nome']] as [$gruppi, $motivo]) {
            foreach ($gruppi as $gruppo) {
                $gruppo = array_slice(array_values(array_unique($gruppo)), 0, self::CRM_DOPPIONI_GRUPPO);
                sort($gruppo, SORT_STRING);

                foreach ($gruppo as $i => $a) {
                    foreach (array_slice($gruppo, $i + 1) as $b) {
                        $coppie["{$a}|{$b}"] ??= [$a, $b, $motivo];
                    }
                }
            }
        }

        ksort($coppie, SORT_STRING);

        return array_values($coppie);
    }

    /**
     * crm.persone.unioni.crea (PersoneUnioniController::crea, Unioni::crea): 201 con l'unione, i conti di ciò che ha spostato e la `Location`.
     * L'ordine: il ruolo e l'app, il freno, la forma del corpo (422), due contatti uguali (409 `unione_non_valida`), le versioni e le scelte
     * (422), poi i due contatti (422 se uno non c'è, 409 `contatto_non_unibile`, 412 se uno è cambiato dopo la lettura), le scelte dei campi in
     * conflitto e le liste finali (422). Accetta Idempotency-Key.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaUnioneCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.persone.unioni.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $this->contaLeUnioniCrm('crm.persone.unioni.crea', $chi['workspace']);
            $campi = $this->testi->validaCorpo($corpo, [
                'resta_id' => ['bail', 'required', 'string', 'max:64', 'lowercase'],
                'unito_id' => ['bail', 'required', 'string', 'max:64', 'lowercase'],
                'versioni' => ['required', 'array'],
                'scelte' => ['sometimes', 'array'],
                'email' => ['nullable', 'array', 'list', 'max:'.self::CRM_UNIONI_ELEMENTI],
                'email.*.indirizzo' => ['required', 'string', 'email', 'max:254'],
                'email.*.etichetta' => ['required', 'string', Rule::in(self::CRM_ETICHETTE_EMAIL)],
                'telefoni' => ['nullable', 'array', 'list', 'max:'.self::CRM_UNIONI_ELEMENTI],
                'telefoni.*.numero' => ['required', 'string', 'max:32'],
                'telefoni.*.etichetta' => ['required', 'string', Rule::in(self::CRM_ETICHETTE_TELEFONO)],
            ]);

            if ($campi['resta_id'] === $campi['unito_id']) {
                throw new Problema('unione_non_valida');
            }

            $versioni = $this->versioniDellUnioneCrm($campi['versioni'], [$campi['resta_id'], $campi['unito_id']]);
            $scelte = $this->scelteDellUnioneCrm($campi['scelte'] ?? []);
            $prima = $this->crm;

            try {
                $unione = $this->unisciCrm($chi, $campi, $versioni, $scelte);
            } catch (Throwable $errore) {
                $this->crm = $prima;

                throw $errore;
            }

            return [201, ['data' => $this->voceUnioneCrm($unione) + ['spostati' => [
                'note' => count($unione['parti']['note']), 'attivita' => count($unione['parti']['attivita']), 'trattative' => count($unione['parti']['trattative']),
            ]]], ['Location' => "/v1/crm/persone/unioni/{$unione['id']}"]];
        });
    }

    /**
     * Il lavoro dell'unione (Unioni::crea), dentro la scrittura.
     *
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     * @param  array<string, mixed>  $campi
     * @param  array<string, string>  $versioni
     * @param  array<string, string>  $scelte
     * @return array<string, mixed>
     */
    private function unisciCrm(array $chi, array $campi, array $versioni, array $scelte): array
    {
        $workspace = $chi['workspace'];

        foreach (['resta_id', 'unito_id'] as $campo) {
            $riga = $this->crm['persone'][$campi[$campo]] ?? null;

            if ($riga === null || $riga['workspace'] !== $workspace) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.persona_non_esiste'), 'regola' => Regola::NON_TROVATO, 'pointer' => self::puntatoreCrm([$campo])]]);
            }
        }

        $resta = $this->crm['persone'][$campi['resta_id']];
        $unito = $this->crm['persone'][$campi['unito_id']];

        foreach ([$resta, $unito] as $contatto) {
            if ($contatto['archiviata_il'] !== null || $contatto['anonimizzata_il'] !== null || $this->inUnioneApertaCrm($contatto['id'])) {
                throw new Problema('contatto_non_unibile');
            }
        }

        $blocchi = $this->blocchiDelContattoCrm($unito['id']);

        if ($blocchi !== []) {
            throw new Problema('contatto_non_unibile', estensioni: ['blocchi' => $blocchi]);
        }

        foreach ([$resta, $unito] as $contatto) {
            if (self::etagCrm($this->voceMostrataCrm('persone', $contatto)) !== $versioni[$contatto['id']]) {
                throw new Problema('precondizione_fallita');
            }
        }

        $daScrivere = $this->campiDaUnireCrm($workspace, $resta, $unito, $scelte);
        [$email, $telefoni] = $this->listeDellUnioneCrm($resta, $unito, $campi['email'] ?? null, $campi['telefoni'] ?? null);
        $ora = self::adessoCrm();
        $id = self::id();
        $valoriPrima = $this->crm['valori']['persona'][$resta['id']] ?? [];

        // Prima chi si unisce: si archivia finché ha ancora le sue email, poi le sue liste si svuotano, così gli indirizzi che passano a chi
        // resta non sono più suoi (409 `email_gia_presente`).
        $this->applicaPersona($unito['id'], ['archiviato' => true], $chi['persona']);
        $this->crm['persone'][$unito['id']]['email'] = [];
        $this->crm['persone'][$unito['id']]['telefoni'] = [];
        $this->applicaPersona($resta['id'], $daScrivere + ['email' => $email, 'telefoni' => $telefoni], $chi['persona']);

        $dopo = $this->crm['persone'][$resta['id']];
        $cambiati = [];

        foreach ([...self::CRM_UNIONI_SCELTE, 'base_giuridica', 'consenso_marketing'] as $campo) {
            if ($resta[$campo] !== $dopo[$campo]) {
                $cambiati[$campo] = $resta[$campo];
            }
        }

        $valori = [];
        $valoriDopo = $this->crm['valori']['persona'][$resta['id']] ?? [];

        foreach (array_keys($this->campiTuoiCrm($workspace, 'persona')) as $chiave) {
            if (($valoriPrima[$chiave] ?? null) !== ($valoriDopo[$chiave] ?? null)) {
                $valori[$chiave] = $valoriPrima[$chiave] ?? null;
            }
        }

        $parti = ['note' => [], 'attivita' => [], 'trattative' => []];

        foreach (['note' => 'note', 'attivita' => 'attivita', 'trattative' => 'trattative'] as $nome => $tipo) {
            foreach ($this->crm[$tipo] as $riga => $voce) {
                if ($voce['workspace'] === $workspace && $voce['persona_id'] === $unito['id'] && ! ($voce['eliminata'] ?? false) && ($voce['archiviata_il'] ?? null) === null) {
                    $this->crm[$tipo][$riga] = [...$voce, 'persona_id' => $resta['id'], 'aggiornata_il' => $ora];
                    $parti[$nome][] = $riga;
                }
            }
        }

        $this->riassuntiDelleUnioniCrm($resta['id'], $unito['id']);

        foreach ([[$resta['id'], $unito['id']], [$unito['id'], $resta['id']]] as [$contatto, $altro]) {
            $this->cambioCrm('persone', $contatto, $chi['persona'], 'unita', [], altra: $altro, unione: $id);
        }

        return $this->crm['unioni'][$id] = [
            'id' => $id, 'workspace' => $workspace, 'resta_id' => $resta['id'], 'unito_id' => $unito['id'], 'creata_da' => $chi['persona'],
            'creata_il' => $ora, 'annullabile_fino' => $ora->addDays(self::CRM_UNIONI_GIORNI), 'annullata_il' => null, 'annullata_da' => null,
            'unito_email' => $unito['email'], 'unito_telefoni' => $unito['telefoni'], 'parti' => $parti,
            'resta_prima' => ['campi' => $cambiati, 'valori' => $valori, 'email' => $resta['email'], 'telefoni' => $resta['telefoni']],
            'resta_dopo' => ['email' => $dopo['email'], 'telefoni' => $dopo['telefoni']],
        ];
    }

    /**
     * crm.persone.unioni.crea, `versioni`: per ognuno dei due contatti l'ETag letto con `crm.persone.mostra`, fra virgolette, e nient'altro. Una
     * versione mancante o scritta male è 422 sul suo id, un id che non è dei due contatti è un campo estraneo.
     *
     * @param  array<array-key, mixed>  $versioni
     * @param  list<string>  $contatti
     * @return array<string, string>
     */
    private function versioniDellUnioneCrm(array $versioni, array $contatti): array
    {
        $errori = [];

        foreach ($contatti as $id) {
            $versione = $versioni[$id] ?? null;

            if ($versione === null) {
                $errori[] = ['detail' => $this->testi->testo('validation.required', ['attribute' => $this->testi->nome('versioni')]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => self::puntatoreCrm(['versioni', $id])];
            } elseif (! is_string($versione) || preg_match('/^"[0-9a-f]{32}"$/', $versione) !== 1) {
                $errori[] = ['detail' => $this->testi->testo('validation.regex', ['attribute' => $this->testi->nome('versioni')]), 'regola' => Regola::FORMATO, 'pointer' => self::puntatoreCrm(['versioni', $id])];
            }
        }

        foreach (array_slice(array_diff(array_map('strval', array_keys($versioni)), $contatti), 0, self::CRM_UNIONI_ESTRANEI) as $id) {
            $errori[] = ['detail' => $this->testi->testo('validation.prohibited', ['attribute' => $this->testi->nome('versioni')]), 'regola' => Regola::CAMPO_ESTRANEO, 'pointer' => self::puntatoreCrm(['versioni', $id])];
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }

        return array_map('strval', array_intersect_key($versioni, array_flip($contatti)));
    }

    /**
     * `scelte`: campo → `resta` o `unito`. Quali campi si scelgono lo dice l'unione, che conosce i due contatti: qui solo la forma del valore.
     *
     * @param  array<array-key, mixed>  $scelte
     * @return array<string, string>
     */
    private function scelteDellUnioneCrm(array $scelte): array
    {
        $errori = [];

        foreach ($scelte as $campo => $valore) {
            if (! in_array($valore, ['resta', 'unito'], true)) {
                $errori[] = ['detail' => $this->testi->testo('validation.in', ['attribute' => $this->testi->nome('scelte')]), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => self::puntatoreCrm(['scelte', (string) $campo])];
            }
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', array_slice($errori, 0, self::CRM_UNIONI_ESTRANEI));
        }

        return array_map('strval', $scelte);
    }

    /**
     * I campi di chi resta che l'unione cambia, nella forma di una modifica: dove chi resta ha il campo vuoto prende quello dell'unito; dove
     * tutti e due lo hanno e sono diversi decide `scelte` (422 sul pointer `#/scelte/<campo>` se manca, e anche se la scelta non serve); i
     * consensi non si scelgono, e vince il più restrittivo.
     *
     * @param  array<string, mixed>  $resta
     * @param  array<string, mixed>  $unito
     * @param  array<string, string>  $scelte
     * @return array<string, mixed>
     */
    private function campiDaUnireCrm(string $workspace, array $resta, array $unito, array $scelte): array
    {
        $campi = [];
        $decisi = [];

        foreach (self::CRM_UNIONI_SCELTE as $campo) {
            if ($unito[$campo] === null || $resta[$campo] === $unito[$campo]) {
                continue;
            }

            if ($resta[$campo] === null) {
                $campi[$campo] = $unito[$campo];

                continue;
            }

            $decisi[$campo] = true;

            if ($this->sceltaDellUnioneCrm($scelte, $campo) === 'unito') {
                $campi[$campo] = $unito[$campo];
            }
        }

        $valoriResta = $this->crm['valori']['persona'][$resta['id']] ?? [];
        $valoriUnito = $this->crm['valori']['persona'][$unito['id']] ?? [];

        foreach ($this->campiTuoiCrm($workspace, 'persona') as $chiave => $campo) {
            $dellUnito = $valoriUnito[$chiave] ?? null;
            $di = $valoriResta[$chiave] ?? null;

            if ($dellUnito === null || json_encode($di) === json_encode($dellUnito)) {
                continue;
            }

            $valore = $this->valoreInUscitaCrm($workspace, (string) $campo['tipo'], $dellUnito);

            if ($di === null) {
                $campi['campi'][$chiave] = $valore;

                continue;
            }

            $decisi["campi.{$chiave}"] = true;

            if ($this->sceltaDellUnioneCrm($scelte, "campi.{$chiave}") === 'unito') {
                $campi['campi'][$chiave] = $valore;
            }
        }

        foreach (array_keys($scelte) as $chiave) {
            if (! isset($decisi[$chiave])) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.unione_scelta_inutile'), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => self::puntatoreCrm(['scelte', (string) $chiave])]]);
            }
        }

        if ($resta['consenso_marketing'] && ! $unito['consenso_marketing']) {
            $campi['consenso_marketing'] = ['dato' => false];
        }

        $base = $unito['base_giuridica'];

        if ($base !== null && ($resta['base_giuridica'] === null || array_search($base, self::CRM_UNIONI_BASI, true) < array_search($resta['base_giuridica'], self::CRM_UNIONI_BASI, true))) {
            $campi['base_giuridica'] = $base;
        }

        return $campi;
    }

    /** @param  array<string, string>  $scelte */
    private function sceltaDellUnioneCrm(array $scelte, string $campo): string
    {
        return $scelte[$campo] ?? throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.unione_scelta_mancante'), 'regola' => Regola::OBBLIGATORIO, 'pointer' => self::puntatoreCrm(['scelte', $campo])]]);
    }

    /**
     * Le liste finali di email e telefoni. Mandate, ogni voce viene da uno dei due contatti (422 sul suo pointer se no, e se è ripetuta); non
     * mandate, sono quelle di chi resta e poi quelle dell'unito che chi resta non ha. Oltre 5 voci è 422.
     *
     * @param  array<string, mixed>  $resta
     * @param  array<string, mixed>  $unito
     * @param  list<array<string, string>>|null  $email
     * @param  list<array<string, string>>|null  $telefoni
     * @return array{list<array<string, string>>, list<array<string, string>>}
     */
    private function listeDellUnioneCrm(array $resta, array $unito, ?array $email, ?array $telefoni): array
    {
        $liste = [];

        foreach ([['email', 'indirizzo', $email], ['telefoni', 'numero', $telefoni]] as [$nome, $colonna, $mandata]) {
            $voci = fn (array $righe) => array_map(fn (array $riga) => [$colonna => $colonna === 'indirizzo' ? self::normalizza($riga[$colonna]) : $riga[$colonna], 'etichetta' => $riga['etichetta']], $righe);
            $dei = [...$voci($resta[$nome]), ...$voci($unito[$nome])];
            $ammesse = array_values(array_unique(array_map(fn (array $voce) => $voce[$colonna].'|'.$voce['etichetta'], $dei)));
            $finale = [];

            foreach ($mandata !== null ? array_values($mandata) : $dei as $i => $voce) {
                $chiave = ($colonna === 'indirizzo' ? self::normalizza($voce[$colonna]) : $voce[$colonna]).'|'.$voce['etichetta'];

                if (! in_array($chiave, $ammesse, true)) {
                    throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.unione_elemento_estraneo'), 'regola' => Regola::VALORE_NON_AMMESSO, 'pointer' => self::puntatoreCrm([$nome, (string) $i])]]);
                }

                if (isset($finale[$chiave])) {
                    if ($mandata !== null) {
                        throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.unione_elemento_doppio'), 'regola' => Regola::DUPLICATO, 'pointer' => self::puntatoreCrm([$nome, (string) $i])]]);
                    }

                    continue;
                }

                $finale[$chiave] = [$colonna => $colonna === 'indirizzo' ? self::normalizza($voce[$colonna]) : $voce[$colonna], 'etichetta' => $voce['etichetta']];
            }

            if (count($finale) > self::CRM_UNIONI_ELEMENTI) {
                throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.unione_troppi_elementi', ['massimo' => self::CRM_UNIONI_ELEMENTI]), 'regola' => Regola::TROPPO_LUNGO, 'pointer' => self::puntatoreCrm([$nome])]]);
            }

            $liste[] = array_values($finale);
        }

        return $liste;
    }

    /**
     * Le parti, diverse da note, attività e opportunità, che un contatto ha e che l'unione non sa spostare: i nomi di `blocchi`, nell'ordine in
     * cui si apriranno. Il finto non ha le parti di Bookings (`link_prenotazione`, `prenotazioni`). Un profilo o un indirizzo archiviato (`archiviata_il`) non blocca.
     *
     * @return list<string>
     */
    private function blocchiDelContattoCrm(string $contatto): array
    {
        $vive = fn (string $tipo) => array_filter($this->crm[$tipo], fn (array $riga) => ($riga['persona_id'] ?? null) === $contatto && ! ($riga['eliminata'] ?? false));
        $viva = fn (string $tipo) => array_filter($this->crm[$tipo], fn (array $riga) => $riga['persona_id'] === $contatto && $riga['archiviata_il'] === null) !== [];
        $acquisti = array_keys(array_filter($this->crm['acquisti'], fn (array $riga) => $riga['persona_id'] === $contatto));
        $nomi = [];

        foreach (['preventivi' => $vive('preventivi') !== [], 'scadenze' => $vive('scadenze') !== [], 'acquisti' => $acquisti !== [],
            'rate' => array_filter($this->crm['rate'], fn (array $rata) => in_array($rata['acquisto_id'], $acquisti, true) && ! ($rata['eliminata'] ?? false)) !== [],
            'profili_fatturazione' => $viva('profili'),
            'indirizzi_spedizione' => $viva('indirizzi'),
            'schede_collegate' => array_filter($this->schedeCrm, fn (array $collegamento) => $collegamento['tipo'] === 'crm.persona' && $collegamento['record'] === $contatto) !== []] as $nome => $presente) {
            if ($presente) {
                $nomi[] = $nome;
            }
        }

        return $nomi;
    }

    /** Un'unione non annullata di cui questo contatto è l'unito e che si può ancora annullare (Unioni::inUnioneAperta). */
    private function inUnioneApertaCrm(string $contatto): bool
    {
        $adesso = self::adessoCrm();

        return array_filter($this->crm['unioni'], fn (array $unione) => $unione['unito_id'] === $contatto && $unione['annullata_il'] === null && $unione['annullabile_fino']->greaterThan($adesso)) !== [];
    }

    /**
     * Il contatto in cui questo si è unito (Forme::unitoIn): `{id, nome, cognome}` finché l'unione non si annulla, altrimenti null.
     *
     * @return array{id: string, nome: string|null, cognome: string|null}|null
     */
    private function unitoInCrm(string $contatto): ?array
    {
        foreach ($this->crm['unioni'] as $unione) {
            if ($unione['unito_id'] === $contatto && $unione['annullata_il'] === null) {
                $resta = $this->crm['persone'][$unione['resta_id']];

                return ['id' => $resta['id'], 'nome' => $resta['nome'], 'cognome' => $resta['cognome']];
            }
        }

        return null;
    }

    /** Il freno per workspace, contato prima del lavoro: 60 all'ora e 300 al giorno (Unioni::conta). */
    private function contaLeUnioniCrm(string $metodo, string $workspace): void
    {
        $this->frena("freni:{$metodo}:ora:{$workspace}", self::CRM_UNIONI_ALL_ORA, 3600);
        $this->frena("freni:{$metodo}:giorno:{$workspace}", self::CRM_UNIONI_AL_GIORNO, 86400);
    }

    /**
     * Un'unione del workspace: una di un altro workspace o che non c'è è 404 non_trovato, con lo stesso corpo.
     *
     * @return array<string, mixed>
     */
    private function unioneCrm(string $workspace, string $id): array
    {
        $unione = $this->crm['unioni'][strtolower($id)] ?? null;

        return $unione !== null && $unione['workspace'] === $workspace ? $unione : throw new Problema('non_trovato');
    }

    /**
     * @param  array<string, mixed>  $unione
     * @return array<string, mixed>
     */
    private function voceUnioneCrm(array $unione): array
    {
        return [
            'id' => $unione['id'], 'resta_id' => $unione['resta_id'], 'unito_id' => $unione['unito_id'], 'creata_il' => self::iso($unione['creata_il']),
            'annullabile_fino' => self::iso($unione['annullabile_fino']), 'annullata_il' => self::isoCrm($unione['annullata_il']),
        ];
    }

    /**
     * crm.persone.unioni.mostra (PersoneUnioniController::mostra): l'unione, con `dopo`, ciò che è stato scritto su chi è rimasto dopo
     * l'unione e che l'annullamento lascerebbe com'è.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraUnioneCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $unione = $this->unioneCrm($chi['workspace'], $id);

        return [200, ['data' => $this->voceUnioneCrm($unione) + ['dopo' => $this->dopoDellUnioneCrm($unione)['restati']]]];
    }

    /**
     * Ciò che è stato scritto su chi è rimasto dopo l'unione e resta con l'annullamento (`restati`), ciò che l'annullamento rimetterebbe
     * (`rimessi`, i nomi dei campi) e i valori da riscrivere (`da_rimettere`, nella forma di una modifica). Su un'unione annullata è tutto vuoto
     * (Unioni::dopo).
     *
     * @param  array<string, mixed>  $unione
     * @return array{restati: array{note: int, attivita: int, trattative: int, campi: list<string>}, rimessi: list<string>, da_rimettere: array<string, mixed>}
     */
    private function dopoDellUnioneCrm(array $unione): array
    {
        $restati = ['note' => 0, 'attivita' => 0, 'trattative' => 0, 'campi' => []];
        $rimessi = [];
        $daRimettere = [];

        if ($unione['annullata_il'] !== null) {
            return ['restati' => $restati, 'rimessi' => $rimessi, 'da_rimettere' => $daRimettere];
        }

        $resta = $this->crm['persone'][$unione['resta_id']];
        $unito = $this->crm['persone'][$unione['unito_id']];

        foreach (['note', 'attivita', 'trattative'] as $tipo) {
            $restati[$tipo] = count(array_filter($this->crm[$tipo], fn (array $riga) => $riga['persona_id'] === $resta['id'] && ! ($riga['eliminata'] ?? false)
                && ($riga['archiviata_il'] ?? null) === null && $riga['creata_il']->greaterThan($unione['creata_il'])));
        }

        $prima = $unione['resta_prima']['campi'];

        foreach ([...self::CRM_UNIONI_SCELTE, 'base_giuridica'] as $campo) {
            if (! array_key_exists($campo, $prima)) {
                continue;
            }

            if (self::taleCrm($resta[$campo]) === self::taleCrm($unito[$campo])) {
                $daRimettere[$campo] = $prima[$campo];
                $rimessi[] = $campo;
            } else {
                $restati['campi'][] = $campo;
            }
        }

        if (array_key_exists('consenso_marketing', $prima)) {
            if (self::taleCrm($resta['consenso_marketing']) === self::taleCrm($unito['consenso_marketing'])) {
                $daRimettere['consenso_marketing'] = ['dato' => (bool) $prima['consenso_marketing']];
                $rimessi[] = 'consenso_marketing';
            } else {
                $restati['campi'][] = 'consenso_marketing';
            }
        }

        // I campi «tuoi»: una riga nata con l'unione torna a niente, una cambiata torna al valore di prima, se il suo valore è ancora quello dell'unito.
        $tuoi = $this->campiTuoiCrm($resta['workspace'], 'persona');

        foreach ($unione['resta_prima']['valori'] as $chiave => $valorePrima) {
            if (! isset($tuoi[$chiave])) {
                continue;
            }

            $dellUnito = $this->crm['valori']['persona'][$unito['id']][$chiave] ?? null;
            $diResta = $this->crm['valori']['persona'][$resta['id']][$chiave] ?? null;

            if ($dellUnito === null || json_encode($diResta) !== json_encode($dellUnito)) {
                $restati['campi'][] = "campi.{$chiave}";

                continue;
            }

            $daRimettere['campi'][$chiave] = $valorePrima === null ? null : $this->valoreInUscitaCrm($resta['workspace'], (string) $tuoi[$chiave]['tipo'], $valorePrima);
            $rimessi[] = "campi.{$chiave}";
        }

        return ['restati' => $restati, 'rimessi' => $rimessi, 'da_rimettere' => $daRimettere];
    }

    /**
     * crm.persone.unioni.annullamento.crea (PersoneUnioniController::annullamentoCrea, Unioni::annulla): entro i 7 giorni (409
     * `annullamento_scaduto` oltre), una volta sola (409 `gia_annullata`). Senza corpo, o con un oggetto vuoto. Chi si era unito torna attivo con
     * le sue email (quelle che nessun altro ha preso nel frattempo, le altre sono in `non_rimesse`) e i suoi telefoni; note, attività e
     * opportunità spostate e ancora di chi resta tornano a lui; i campi di chi resta presi dall'unito tornano al valore di prima, salvo quelli
     * cambiati dopo l'unione, e le sue liste solo se nessuno le ha toccate. Ciò che è nato dopo resta dov'è.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function annullaUnioneCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $unione = $this->unioneCrm($chi['workspace'], $id);
        $this->contaLeUnioniCrm('crm.persone.unioni.annullamento.crea', $chi['workspace']);
        $this->testi->validaCorpo($corpo, []);

        if ($unione['annullata_il'] !== null) {
            throw new Problema('gia_annullata');
        }

        if (self::adessoCrm()->greaterThan($unione['annullabile_fino'])) {
            throw new Problema('annullamento_scaduto');
        }

        if ($this->crm['persone'][$unione['resta_id']]['anonimizzata_il'] !== null || $this->crm['persone'][$unione['unito_id']]['anonimizzata_il'] !== null) {
            throw new Problema('contatto_non_unibile');
        }

        $prima = $this->crm;

        try {
            $esito = $this->disfaiCrm($chi, $unione);
        } catch (Throwable $errore) {
            $this->crm = $prima;

            throw $errore;
        }

        return [200, ['data' => ['id' => $unione['id'], 'annullata_il' => self::iso($this->crm['unioni'][$unione['id']]['annullata_il'])] + $esito]];
    }

    /**
     * Il lavoro dell'annullamento (Unioni::annulla), dentro la scrittura.
     *
     * @param  array{persona: string, accesso: string, workspace: string}  $chi
     * @param  array<string, mixed>  $unione
     * @return array{rimessi: array<string, mixed>, restati: array<string, mixed>, non_rimesse: list<array{indirizzo: string, persona_id: string}>}
     */
    private function disfaiCrm(array $chi, array $unione): array
    {
        $ora = self::adessoCrm();
        $resta = $this->crm['persone'][$unione['resta_id']];
        $dopo = $this->dopoDellUnioneCrm($unione);
        $campi = $dopo['da_rimettere'];
        $liste = [];

        // Le liste di chi resta tornano quelle di prima solo se nessuno le ha toccate dopo: sono ancora quelle che l'unione ha scritto.
        foreach (['email', 'telefoni'] as $lista) {
            if ($unione['resta_dopo'][$lista] !== $unione['resta_prima'][$lista] && $resta[$lista] === $unione['resta_dopo'][$lista]) {
                $campi[$lista] = $unione['resta_prima'][$lista];
                $liste[] = $lista;
            }
        }

        // Prima chi resta (libera gli indirizzi che le sue righe di ora tenevano), poi l'unito, che riprende solo ciò che è libero.
        if ($campi !== []) {
            $this->applicaPersona($resta['id'], $campi, $chi['persona']);
        }

        $nonRimesse = [];
        $email = [];

        foreach ($unione['unito_email'] as $voce) {
            $presente = $this->personaConEmailCrm($chi['workspace'], $voce['indirizzo']);

            if ($presente !== null) {
                $nonRimesse[] = ['indirizzo' => $voce['indirizzo'], 'persona_id' => $presente];

                continue;
            }

            $email[] = $voce;
        }

        $this->applicaPersona($unione['unito_id'], ['archiviato' => false, 'email' => $email, 'telefoni' => $unione['unito_telefoni']], $chi['persona']);

        $rimessi = ['note' => 0, 'attivita' => 0, 'trattative' => 0];

        foreach ($unione['parti'] as $nome => $righe) {
            foreach ($righe as $riga) {
                if (($this->crm[$nome][$riga]['persona_id'] ?? null) === $resta['id']) {
                    $this->crm[$nome][$riga] = [...$this->crm[$nome][$riga], 'persona_id' => $unione['unito_id'], 'aggiornata_il' => $ora];
                    $rimessi[$nome]++;
                }
            }
        }

        $this->riassuntiDelleUnioniCrm($resta['id'], $unione['unito_id']);

        foreach ([[$resta['id'], $unione['unito_id']], [$unione['unito_id'], $resta['id']]] as [$contatto, $altro]) {
            $this->cambioCrm('persone', $contatto, $chi['persona'], 'unione_annullata', [], altra: $altro, unione: $unione['id']);
        }

        $this->crm['unioni'][$unione['id']] = [...$unione, 'annullata_il' => $ora, 'annullata_da' => $chi['persona']];

        return ['rimessi' => $rimessi + ['campi' => [...$dopo['rimessi'], ...$liste]], 'restati' => $dopo['restati'], 'non_rimesse' => $nonRimesse];
    }

    /**
     * I riassunti dei due contatti, ricalcolati dopo uno spostamento: le opportunità aperte, il loro valore e quelle senza valore, la
     * prossima scadenza e, per chi resta, l'ultima attività (cresce e non torna mai indietro).
     */
    private function riassuntiDelleUnioniCrm(string $resta, string $unito): void
    {
        $ultima = null;

        foreach ([$resta, $unito] as $contatto) {
            $aperte = array_filter($this->crm['trattative'], fn (array $riga) => $riga['persona_id'] === $contatto && $riga['stato'] === 'aperta' && $riga['archiviata_il'] === null);
            $this->salvaCrm('persone', $contatto, [
                'trattative_aperte' => count($aperte),
                'valore_aperto' => array_sum(array_map(fn (array $riga) => $riga['importo'] ?? 0, $aperte)),
                'trattative_aperte_senza_valore' => count(array_filter($aperte, fn (array $riga) => $riga['importo'] === null)),
                'prossima_scadenza' => $this->prossimaScadenzaCrm($contatto),
            ]);
            $viva = $this->crm['persone'][$contatto]['ultima_attivita_il'];

            if ($viva instanceof CarbonImmutable && ($ultima === null || $viva->greaterThan($ultima))) {
                $ultima = $viva;
            }
        }

        if ($ultima !== null) {
            $this->salvaCrm('persone', $resta, ['ultima_attivita_il' => $ultima]);
        }
    }

    /** Un valore nella forma con cui si confronta: una stringa, e un booleano come 0 o 1; null resta tale (Unioni::tale). */
    private static function taleCrm(mixed $valore): ?string
    {
        return match (true) {
            $valore === null => null,
            is_bool($valore) => $valore ? '1' : '0',
            is_array($valore) => (string) json_encode($valore),
            default => (string) $valore,
        };
    }

    /**
     * Il pointer di un valore del corpo (ErroreDiCampo::pointer): ogni segmento come in RFC 6901, poi codificato per un frammento di URI.
     *
     * @param  list<string>  $segmenti
     */
    private static function puntatoreCrm(array $segmenti): string
    {
        return '#/'.implode('/', array_map(fn (string $segmento) => rawurlencode(str_replace(['~', '/'], ['~0', '~1'], $segmento)), $segmenti));
    }
}
