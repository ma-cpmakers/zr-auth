<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * Il catalogo del CRM nel backoffice finto (CRM-05, #2349): `crm.catalogo.elenca`, `categorie.elenca`, `mostra`, `crea`, `modifica` e
 * `cronologia.elenca`, con le regole, i pointer e l'ordine di `CatalogoController` del backoffice. Una voce ha un tipo (`servizio`,
 * `prodotto`, `abbonamento`, `pacchetto`) e i campi di quel tipo: un campo che il tipo non ha è 422 `campo_vietato`, uno che manca
 * `obbligatorio`. Si archivia e si ripristina con `stato`, mai si elimina; il prezzo è un intero in unità minori nella valuta del CRM; la
 * modifica vuole l'`If-Match` se c'è, e la cronologia ricorda i valori vecchi e nuovi (di nome, descrizione, codice e categoria il solo nome
 * del campo). Scrivono il proprietario e l'amministratore, leggono i tre ruoli. L'`ETag` non è lo stesso valore del backoffice: conta la forma.
 */
trait CrmCatalogo
{
    /** Le voci per workspace, archiviate comprese (CatalogoController::TETTO). */
    private const CRM_CATALOGO_TETTO = 2000;

    private const CRM_CATALOGO_TIPI = ['servizio', 'prodotto', 'abbonamento', 'pacchetto'];

    private const CRM_CATALOGO_STATI = ['bozza', 'attiva', 'archiviata'];

    private const CRM_CATALOGO_RINNOVI = ['mensile', 'trimestrale', 'semestrale', 'annuale'];

    /** Gli ordini di `ordina` (CatalogoElencaRequest::ORDINI). */
    private const CRM_CATALOGO_ORDINI = ['nome', '-nome', 'aggiornata_il', '-aggiornata_il', 'prezzo', '-prezzo'];

    /** I campi di una voce nell'ordine del contratto, come stanno nella cronologia (CatalogoController::CAMPI). */
    private const CRM_CATALOGO_CAMPI = ['tipo', 'nome', 'descrizione', 'codice', 'categoria', 'prezzo', 'aliquota_iva', 'iva_inclusa', 'durata_minuti', 'unita_incluse', 'etichetta_unita', 'validita_mesi', 'rinnovo', 'stato'];

    /** I campi che la cronologia ricorda col solo nome (CatalogoController::SENZA_VALORI). */
    private const CRM_CATALOGO_SENZA_VALORI = ['nome', 'descrizione', 'codice', 'categoria'];

    /**
     * crm.catalogo.crea (CatalogoController::crea): 201 con la voce, `Location` ed `ETag`. Nasce `attiva` se non si dice altro. Prima il
     * corpo e i campi del tipo, poi il tetto di 2.000 voci per workspace (409 `limite_raggiunto`).
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaVoceCatalogoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);

        return $this->conIdempotenza($richiesta, 'crm.catalogo.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, $this->regoleDellaVoceCrm($chi['workspace'], true));
            $this->coerenzaDellaVoceCrm($this->completaLaVoceCrm($campi, null));

            if (count($this->righeCrm('catalogo', $chi['workspace'])) >= self::CRM_CATALOGO_TETTO) {
                throw new Problema('limite_raggiunto', estensioni: ['limite' => self::CRM_CATALOGO_TETTO]);
            }

            $id = self::id();
            $ora = self::adessoCrm();
            $this->crm['catalogo'][$id] = $this->colonneDellaVoceCrm($campi) + [
                'id' => $id, 'workspace' => $chi['workspace'], 'stato' => $campi['stato'] ?? 'attiva', 'iva_inclusa' => true, 'archiviata_il' => null,
                'creata_il' => $ora, 'aggiornata_il' => $ora, 'creata_da' => $chi['persona'],
            ] + $this->colonneDellaVoceCrm(array_fill_keys(self::CRM_CATALOGO_CAMPI, null));
            $this->cambioCrm('catalogo', $id, $chi['persona'], 'creata', []);
            $voce = $this->voceCatalogoCrm($this->crm['catalogo'][$id]);

            return [201, ['data' => $voce], ['Location' => "/v1/crm/catalogo/{$id}", 'ETag' => self::etagCrm($voce)]];
        });
    }

    /**
     * crm.catalogo.modifica (CatalogoController::modifica): JSON Merge Patch, con `If-Match` letto prima del corpo (412 sulla versione
     * vecchia). Cambiare `tipo` rivalida i campi del tipo nuovo; `stato: archiviata` archivia, `bozza` o `attiva` ripristina. Una modifica
     * che non cambia niente non scrive e non lascia righe.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function modificaVoceCatalogoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $riga = $this->rigaCrm('catalogo', $chi['workspace'], $id);
        $this->pretendiLaVersioneCrm($richiesta, $this->voceCatalogoCrm($riga));
        $campi = $this->modificaCrm($corpo, $this->regoleDellaVoceCrm($chi['workspace'], false));
        $prima = $this->valoriDellaVoceCrm($riga);
        $dopo = $this->completaLaVoceCrm($campi, $riga);
        $this->coerenzaDellaVoceCrm($dopo);
        // Il prezzo cambia se cambia l'importo: la valuta è sempre quella del workspace e l'ordine delle chiavi del corpo non conta.
        $cambiati = array_values(array_filter(self::CRM_CATALOGO_CAMPI, fn (string $campo) => $campo === 'prezzo'
            ? ($prima['prezzo']['importo'] ?? null) !== ($dopo['prezzo']['importo'] ?? null)
            : $prima[$campo] !== $dopo[$campo]));

        if ($cambiati !== []) {
            $nuovi = $this->colonneDellaVoceCrm($campi);

            if (in_array('stato', $cambiati, true)) {
                $nuovi['stato'] = $dopo['stato'];
                $nuovi['archiviata_il'] = $dopo['stato'] === 'archiviata' ? self::adessoCrm() : null;
            }

            $this->salvaCrm('catalogo', $riga['id'], $nuovi);
            $archivio = in_array('stato', $cambiati, true) && ($prima['stato'] === 'archiviata' || $dopo['stato'] === 'archiviata');
            $voci = [];

            foreach ($cambiati as $campo) {
                if ($campo === 'stato' && $archivio) {
                    continue;
                }

                $voci[] = in_array($campo, self::CRM_CATALOGO_SENZA_VALORI, true) ? ['campo' => $campo] : ['campo' => $campo, 'prima' => $prima[$campo], 'dopo' => $dopo[$campo]];
            }

            if ($voci !== []) {
                $this->cambioCrm('catalogo', $riga['id'], $chi['persona'], 'modificata', $voci);
            }

            if ($archivio) {
                $this->cambioCrm('catalogo', $riga['id'], $chi['persona'], $dopo['stato'] === 'archiviata' ? 'archiviata' : 'ripristinata', []);
            }
        }

        $voce = $this->voceCatalogoCrm($this->crm['catalogo'][$riga['id']]);

        return [200, ['data' => $voce], ['ETag' => self::etagCrm($voce)]];
    }

    /**
     * crm.catalogo.elenca (CatalogoController::elenca): le voci a cursore. `tipo` e `stato` a virgole (di partenza `bozza,attiva`), `categoria`
     * uguale senza maiuscole né accenti, `ordina` (`nome`, `aggiornata_il` o `prezzo`, con `-`; di partenza `nome`), `totale=true`. A parità
     * decide l'id nello stesso verso; le voci senza prezzo vanno in fondo in tutti e due i versi.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCatalogoCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), [
            'tipo' => $this->regolaDelleVociCrm('tipo', self::CRM_CATALOGO_TIPI),
            'stato' => $this->regolaDelleVociCrm('stato', self::CRM_CATALOGO_STATI),
            'categoria' => ['bail', 'sometimes', 'nullable', 'string', 'max:60'],
            'ordina' => ['bail', 'sometimes', 'string', Rule::in(self::CRM_CATALOGO_ORDINI)],
            'totale' => ['sometimes', 'string', Rule::in(['true', 'false'])],
        ]);
        $voci = fn (string $parametro) => array_values(array_unique(array_filter(array_map('trim', explode(',', (string) ($query[$parametro] ?? ''))), fn (string $voce) => $voce !== '')));
        $tipi = $voci('tipo');
        $stati = $voci('stato') ?: ['bozza', 'attiva'];
        sort($tipi);
        sort($stati);
        $categoria = isset($query['categoria']) && trim((string) $query['categoria']) !== '' ? self::chiaveCrm($query['categoria']) : null;
        $ordina = (string) ($query['ordina'] ?? 'nome');
        $righe = array_values(array_filter(
            $this->righeCrm('catalogo', $chi['workspace']),
            fn (array $riga) => in_array($riga['stato'], $stati, true) && ($tipi === [] || in_array($riga['tipo'], $tipi, true))
                && ($categoria === null || ($riga['categoria'] !== null && self::chiaveCrm($riga['categoria']) === $categoria)),
        ));
        $lista = 'crm.catalogo.elenca:'.substr(hash('sha256', json_encode([$ordina, $tipi, $stati, $categoria], JSON_THROW_ON_ERROR)), 0, 16);
        $campo = ['nome' => 'nome', 'aggiornata_il' => 'aggiornata_il', 'prezzo' => 'prezzo_importo'][ltrim($ordina, '-')];
        $discendente = str_starts_with($ordina, '-');
        $ordine = function (array $una, array $altra) use ($campo, $discendente): int {
            $a = self::chiaveCrm($una[$campo] ?? null);
            $b = self::chiaveCrm($altra[$campo] ?? null);

            if (($a === null) !== ($b === null)) {
                return $a === null ? 1 : -1;
            }

            $verso = $discendente ? -1 : 1;
            $ordine = $a === null ? 0 : $verso * self::confrontaCrm($a, $b);

            return $ordine !== 0 ? $ordine : $verso * (strcmp($una['id'], $altra['id']) <=> 0);
        };
        $risposta = $this->paginaCrm($richiesta, $lista, $righe, $ordine, fn (array $riga) => $this->voceCatalogoCrm($riga));

        if (($query['totale'] ?? null) === 'true') {
            $risposta[1]['totale'] = count($righe);
        }

        return $risposta;
    }

    /**
     * crm.catalogo.categorie.elenca: le categorie in uso nelle voci non archiviate, per nome (senza maiuscole né accenti), con quante voci
     * ognuna ha; il nome è il più basso fra quelli scritti. Un elenco solo, senza cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaCategorieCatalogoCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $gruppi = [];

        foreach ($this->righeCrm('catalogo', $chi['workspace']) as $riga) {
            if ($riga['stato'] === 'archiviata' || $riga['categoria'] === null) {
                continue;
            }

            $chiave = (string) self::chiaveCrm($riga['categoria']);
            $gruppi[$chiave]['nome'] = isset($gruppi[$chiave]) ? min($gruppi[$chiave]['nome'], $riga['categoria']) : $riga['categoria'];
            $gruppi[$chiave]['voci'] = ($gruppi[$chiave]['voci'] ?? 0) + 1;
        }

        ksort($gruppi);

        return [200, ['data' => array_values($gruppi)]];
    }

    /**
     * crm.catalogo.cronologia.elenca: la cronologia di una voce, anche archiviata, dalla più recente, a cursore. Le righe portano i nomi dei
     * campi cambiati e, salvo nome, descrizione, codice e categoria, il valore di prima e di dopo.
     *
     * @return array{int, array<string, mixed>}
     */
    private function cronologiaCatalogoCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $riga = $this->rigaCrm('catalogo', $chi['workspace'], $id);
        $righe = array_values(array_filter($this->crm['cambi'], fn (array $cambio) => $cambio['tipo_risorsa'] === 'catalogo' && $cambio['risorsa'] === $riga['id']));

        return $this->paginaCrm(
            $richiesta,
            "crm.catalogo.cronologia.elenca:{$riga['id']}",
            $righe,
            fn (array $una, array $altra) => [$altra['istante'], $altra['id']] <=> [$una['istante'], $una['id']],
            fn (array $cambio) => ['id' => $cambio['id'], 'tipo' => $cambio['tipo'], 'autore_id' => $cambio['autore_id'], 'istante' => self::iso($cambio['istante']), 'campi' => $cambio['campi']],
        );
    }

    /**
     * Lo schema VoceDelCatalogo (Forme::voceDelCatalogo): il prezzo è `{importo, valuta}` con la valuta del CRM di adesso, o null.
     *
     * @return array<string, mixed>
     */
    private function voceCatalogoCrm(array $riga): array
    {
        return [
            'id' => $riga['id'],
            'tipo' => $riga['tipo'],
            'nome' => $riga['nome'],
            'descrizione' => $riga['descrizione'],
            'codice' => $riga['codice'],
            'categoria' => $riga['categoria'],
            'prezzo' => $riga['prezzo_importo'] === null ? null : ['importo' => $riga['prezzo_importo'], 'valuta' => $this->valutaCrm($riga['workspace'])],
            'aliquota_iva' => $riga['aliquota_iva'],
            'iva_inclusa' => $riga['iva_inclusa'],
            'durata_minuti' => $riga['durata_minuti'],
            'unita_incluse' => $riga['unita_incluse'],
            'etichetta_unita' => $riga['etichetta_unita'],
            'validita_mesi' => $riga['validita_mesi'],
            'rinnovo' => $riga['rinnovo'],
            'stato' => $riga['stato'],
            'archiviata_il' => self::isoCrm($riga['archiviata_il']),
            'creata_il' => self::iso($riga['creata_il']),
            'aggiornata_il' => self::iso($riga['aggiornata_il']),
            'creata_da' => $riga['creata_da'],
        ];
    }

    /**
     * Le regole dei campi di una voce (CatalogoController::regole): tutti obbligatori o facoltativi alla creazione, `filled` o `nullable` in
     * una modifica. I campi che dipendono dal tipo si controllano insieme, dopo, in `coerenzaDellaVoceCrm`.
     *
     * @return array<string, mixed>
     */
    private function regoleDellaVoceCrm(string $workspace, bool $nuova): array
    {
        $presenza = $nuova ? 'required' : 'filled';

        return [
            'tipo' => ['bail', $presenza, 'string', Rule::in(self::CRM_CATALOGO_TIPI)],
            'nome' => ['bail', $presenza, 'string', 'max:160', new NomeScritto],
            'descrizione' => ['bail', 'sometimes', 'nullable', 'string', 'max:2000'],
            'codice' => ['bail', 'sometimes', 'nullable', 'string', 'max:40'],
            'categoria' => ['bail', 'sometimes', 'nullable', 'string', 'max:60'],
            'prezzo' => ['bail', 'sometimes', 'nullable', 'array', function (string $campo, mixed $prezzo, Closure $fail) {
                if ($prezzo === []) {
                    $this->rifiutaCrm($fail, $campo, Regola::OBBLIGATORIO, 'validation.required', ['attribute' => $campo]);
                }
            }],
            'prezzo.importo' => ['bail', 'required_with:prezzo', 'integer:strict', 'between:0,1000000000000'],
            'prezzo.valuta' => ['bail', 'required_with:prezzo', 'string', function (string $campo, mixed $valuta, Closure $fail) use ($workspace) {
                if ($valuta !== $this->valutaCrm($workspace)) {
                    $this->rifiutaCrm($fail, $campo, Regola::VALORE_NON_AMMESSO, 'regole.valuta_del_workspace', ['valuta' => $this->valutaCrm($workspace)]);
                }
            }],
            'aliquota_iva' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:0,10000'],
            'iva_inclusa' => ['bail', 'sometimes', 'boolean:strict'],
            'durata_minuti' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:1,1440'],
            'unita_incluse' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:1,100000'],
            'etichetta_unita' => ['bail', 'sometimes', 'nullable', 'string', 'max:30'],
            'validita_mesi' => ['bail', 'sometimes', 'nullable', 'integer:strict', 'between:1,36'],
            'rinnovo' => ['bail', 'sometimes', 'nullable', 'string', Rule::in(self::CRM_CATALOGO_RINNOVI)],
            'stato' => ['bail', $nuova ? 'sometimes' : 'filled', 'string', Rule::in($nuova ? ['bozza', 'attiva'] : self::CRM_CATALOGO_STATI)],
        ];
    }

    /**
     * La voce com'è dopo la scrittura: i campi del corpo sopra quelli della voce di prima (null per una che nasce, `attiva`).
     *
     * @param  array<string, mixed>  $campi
     * @return array<string, mixed>
     */
    private function completaLaVoceCrm(array $campi, ?array $riga): array
    {
        $valori = $riga === null ? array_fill_keys(self::CRM_CATALOGO_CAMPI, null) : $this->valoriDellaVoceCrm($riga);

        if ($riga === null) {
            $valori['stato'] = 'attiva';
            $valori['iva_inclusa'] = true;
        }

        foreach (self::CRM_CATALOGO_CAMPI as $campo) {
            if (array_key_exists($campo, $campi)) {
                $valori[$campo] = $campi[$campo];
            }
        }

        return $valori;
    }

    /**
     * I valori di una voce nei nomi del contratto; il prezzo è `{importo, valuta}` o null.
     *
     * @return array<string, mixed>
     */
    private function valoriDellaVoceCrm(array $riga): array
    {
        $valori = [];

        foreach (self::CRM_CATALOGO_CAMPI as $campo) {
            $valori[$campo] = $campo === 'prezzo'
                ? ($riga['prezzo_importo'] === null ? null : ['importo' => $riga['prezzo_importo'], 'valuta' => $this->valutaCrm($riga['workspace'])])
                : $riga[$campo];
        }

        return $valori;
    }

    /**
     * Le colonne da scrivere per i campi del corpo (il prezzo si spezza nel solo importo: la valuta è quella del workspace).
     *
     * @param  array<string, mixed>  $campi
     * @return array<string, mixed>
     */
    private function colonneDellaVoceCrm(array $campi): array
    {
        $colonne = [];

        foreach (self::CRM_CATALOGO_CAMPI as $campo) {
            if ($campo !== 'prezzo' && $campo !== 'stato' && array_key_exists($campo, $campi)) {
                $colonne[$campo] = $campi[$campo];
            }
        }

        if (array_key_exists('prezzo', $campi)) {
            $colonne['prezzo_importo'] = $campi['prezzo']['importo'] ?? null;
        }

        return $colonne;
    }

    /**
     * I campi del tipo (CatalogoController::coerenza): il prodotto non ha la durata; le unità incluse e la loro etichetta sono del solo
     * pacchetto, e le unità lì vogliono un valore; l'abbonamento non ha la scadenza e vuole il rinnovo, che gli altri tipi non hanno. Un campo
     * che il tipo non ha è 422 `campo_vietato`, uno che manca `obbligatorio`, sul pointer del campo; tutti gli errori insieme.
     *
     * @param  array<string, mixed>  $voce  la voce com'è dopo la scrittura
     */
    private function coerenzaDellaVoceCrm(array $voce): void
    {
        $tipo = (string) $voce['tipo'];
        $errori = [];
        $vieta = function (string $campo) use (&$errori, $voce, $tipo) {
            if ($voce[$campo] !== null) {
                $errori[] = ['detail' => $this->testi->testo('regole.catalogo_campo_del_tipo', ['campo' => $campo, 'tipo' => $tipo]), 'regola' => Regola::CAMPO_VIETATO, 'pointer' => "#/{$campo}"];
            }
        };
        $manca = function (string $campo) use (&$errori, $tipo) {
            $errori[] = ['detail' => $this->testi->testo('regole.catalogo_campo_richiesto', ['campo' => $campo, 'tipo' => $tipo]), 'regola' => Regola::OBBLIGATORIO, 'pointer' => "#/{$campo}"];
        };

        if ($tipo === 'prodotto') {
            $vieta('durata_minuti');
        }

        if ($tipo !== 'pacchetto') {
            $vieta('unita_incluse');
            $vieta('etichetta_unita');
        } elseif ($voce['unita_incluse'] === null) {
            $manca('unita_incluse');
        }

        if ($tipo === 'abbonamento') {
            $vieta('validita_mesi');

            if ($voce['rinnovo'] === null) {
                $manca('rinnovo');
            }
        } else {
            $vieta('rinnovo');
        }

        if ($errori !== []) {
            throw new Problema('dati_non_validi', $errori);
        }
    }
}
