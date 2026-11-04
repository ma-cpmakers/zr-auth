<?php

namespace Zeiras\Auth\Testing\Finto;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Client\Request;
use Illuminate\Validation\Rule;

/**
 * I modelli e i documenti firmati del CRM nel finto (CRM-10, #2923 #2924 #2927): `crm.modelli.elenca` e `.mostra`, `crm.documenti.elenca`, `.crea`,
 * `.revoca.crea` e `.pdf.mostra`, `crm.documenti.link.crea` e `.elimina`, e i due metodi senza gettone `documenti.pubblici.lettura.crea` e `.firma.crea`,
 * come ModelliController, DocumentiController, DocumentiLinkController e DocumentiPubbliciController del backoffice. I quattro modelli sono
 * `resources/crm/documenti/modelli.php`, copia byte per byte di quello del backoffice. Il testo lo compila il finto, dal modello e dal contatto; il PDF è
 * minimo (il nome del modello), con la forma vera (tipo, nome del file, niente cache). Non imita i freni (le creazioni, il PDF per persona), gli eventi
 * (`documento.firmato`, `consenso.revocato`) né la cronologia; imita l'oblio, che svuota testo, firmatario e tratto. Il token del link sta solo nella risposta
 * di `crm.documenti.link.crea`: il finto ne tiene l'SHA-256.
 */
trait CrmDocumenti
{
    /** Per quanti giorni un link si apre (LinkDelDocumento::GIORNI). */
    private const CRM_LINK_DOCUMENTI_GIORNI = 14;

    /** Sotto questa età chi firma è un genitore o un tutore (Documenti::MAGGIORE_ETA). */
    private const CRM_DOCUMENTI_MAGGIORE_ETA = 18;

    /** Quanto pesa, al più, il PNG del tratto una volta decodificato, e il suo lato più lungo (Documenti::TRATTO_BYTE e TRATTO_LATO). */
    private const CRM_DOCUMENTI_TRATTO_BYTE = 153600;

    private const CRM_DOCUMENTI_TRATTO_LATO = 2000;

    /** I legami di chi firma (Documento::LEGAMI), quelli del minorenne, e i due tratti (Documento::TRATTI). */
    private const CRM_DOCUMENTI_LEGAMI = ['se_stesso', 'genitore', 'tutore', 'altro'];

    private const CRM_DOCUMENTI_LEGAMI_DEL_MINORENNE = ['genitore', 'tutore'];

    private const CRM_DOCUMENTI_TRATTI = ['immagine', 'spunta'];

    /** @var array<string, array{revocabile: bool, nome: array<string, string>, id: string, versioni: array<int, array<string, string>>}>|null */
    private static ?array $modelliDocumentiCrm = null;

    /**
     * I modelli per chiave, nell'ordine in cui li pubblica Zeiras.
     *
     * @return array<string, array{revocabile: bool, nome: array<string, string>, id: string, versioni: array<int, array<string, string>>}>
     */
    private static function modelliCrm(): array
    {
        return self::$modelliDocumentiCrm ??= require dirname(__DIR__, 3).'/resources/crm/documenti/modelli.php';
    }

    /**
     * Un modello per chiave o per id (Modelli::trova), o null.
     *
     * @return array{id: string, chiave: string, nome: array<string, string>, revocabile: bool, versioni: array<int, array<string, string>>}|null
     */
    private static function trovaModelloCrm(string $chiaveOId): ?array
    {
        foreach (self::modelliCrm() as $chiave => $modello) {
            if ($chiave === $chiaveOId || $modello['id'] === strtolower($chiaveOId)) {
                return ['chiave' => $chiave] + $modello;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $modello */
    private static function versioneCorrenteCrm(array $modello): int
    {
        return (int) array_key_last($modello['versioni']);
    }

    /**
     * Lo schema Modello (Forme::modello).
     *
     * @param  array<string, mixed>  $modello
     * @return array<string, mixed>
     */
    private static function voceModelloCrm(array $modello, ?int $versione = null): array
    {
        return ['id' => $modello['id'], 'chiave' => $modello['chiave'], 'nome' => $modello['nome'], 'versione' => $versione ?? self::versioneCorrenteCrm($modello), 'revocabile' => $modello['revocabile']];
    }

    /**
     * Il testo di una versione in una lingua, con i segnaposto risolti per un contatto (Modelli::testo); senza contatto restano com'erano. Il giorno
     * è quello del fuso del workspace, il nome della palestra è il nome del workspace.
     *
     * @param  array<string, mixed>  $modello
     * @param  array<string, mixed>|null  $persona
     */
    private function testoDelModelloCrm(array $modello, int $versione, string $lingua, ?array $persona): string
    {
        $testo = $modello['versioni'][$versione][$lingua];

        if ($persona === null) {
            return $testo;
        }

        $valori = [
            '{{nome}}' => trim((string) $persona['nome']),
            '{{cognome}}' => trim((string) $persona['cognome']),
            '{{data}}' => self::oggiAcquistoCrm(),
            '{{palestra}}' => (string) $this->workspace[$persona['workspace']]['nome'],
        ];

        return trim((string) preg_replace('/ {2,}/', ' ', strtr($testo, $valori)));
    }

    /**
     * crm.modelli.elenca (ModelliController::elenca): i quattro modelli in ordine di id, a cursore.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaModelliCrm(Request $richiesta): array
    {
        $this->conCrm($richiesta);
        $righe = array_values(array_map(fn (string $chiave) => self::trovaModelloCrm($chiave), array_keys(self::modelliCrm())));

        return $this->paginaCrm($richiesta, 'crm.modelli.elenca', $righe, fn (array $una, array $altra) => strcmp($una['id'], $altra['id']), fn (array $modello) => self::voceModelloCrm($modello));
    }

    /**
     * crm.modelli.mostra (ModelliController::mostra): il modello in una lingua (quella della richiesta, se manca `lingua`), nella versione corrente o in
     * `versione`; con `persona_id` i segnaposto sono risolti per quel contatto. Ogni altro parametro è 422 `campo_estraneo`. Un modello, una versione o un
     * contatto che non ci sono sono 404; un contatto anonimizzato 409.
     *
     * @return array{int, array<string, mixed>}
     */
    private function mostraModelloCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $regole = [
            'lingua' => ['sometimes', 'bail', 'string', Rule::in(self::MODELLI_LINGUE_CRM)],
            'versione' => ['sometimes', 'bail', 'string', 'regex:/^[1-9][0-9]{0,8}$/D'],
            'persona_id' => ['sometimes', 'bail', 'string', 'regex:/^[0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{26}$/D'],
        ];
        $query = self::query($richiesta);

        foreach (array_diff(array_map('strval', array_keys($query)), array_keys($regole)) as $estraneo) {
            $regole[$estraneo] = [function (string $attributo, mixed $valore, Closure $fail) {
                $this->rifiutaCrm($fail, $attributo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $attributo]);
            }];
        }

        $campi = $this->testi->validaQuery($query, $regole);
        $modello = self::trovaModelloCrm($id) ?? throw new Problema('non_trovato');
        $versione = isset($campi['versione']) ? (int) $campi['versione'] : self::versioneCorrenteCrm($modello);

        if (! isset($modello['versioni'][$versione])) {
            throw new Problema('non_trovato');
        }

        $persona = null;

        if (isset($campi['persona_id'])) {
            $persona = $this->rigaCrm('persone', $chi['workspace'], $campi['persona_id']);

            if ($persona['anonimizzata_il'] !== null) {
                throw new Problema('persona_anonimizzata');
            }
        }

        $lingua = $campi['lingua'] ?? (in_array($this->testi->lingua(), self::MODELLI_LINGUE_CRM, true) ? $this->testi->lingua() : 'it');
        $testo = $this->testoDelModelloCrm($modello, $versione, $lingua, $persona);

        return [200, ['data' => self::voceModelloCrm($modello, $versione) + ['lingua' => $lingua, 'testo' => $testo, 'impronta' => hash('sha256', $testo)]]];
    }

    /** Le lingue dei modelli (Modelli::LINGUE). */
    private const MODELLI_LINGUE_CRM = ['it', 'en', 'es'];

    /**
     * Lo schema Documento di una riga (Forme::documento). Il proprietario e gli amministratori lo vedono per intero; un membro vede solo che c'è, di che
     * modello è, in che stato e quando: mai chi ha firmato, l'impronta, la lingua o la versione.
     *
     * @param  array<string, mixed>  $riga
     * @return array<string, mixed>
     */
    private function voceDocumentoCrm(array $riga, bool $intero): array
    {
        $base = [
            'id' => $riga['id'], 'modello' => $riga['modello'], 'versione' => $riga['versione'], 'lingua' => $riga['lingua'], 'stato' => $riga['stato'], 'modo' => $riga['modo'],
            'firmato_il' => self::iso($riga['firmato_il']), 'revocato_il' => $riga['revocato_il'] === null ? null : self::iso($riga['revocato_il']),
        ];

        if (! $intero) {
            return array_diff_key($base, ['versione' => 1, 'lingua' => 1]);
        }

        return $base + [
            'firmatario' => ['nome' => $riga['firmatario_nome'], 'cognome' => $riga['firmatario_cognome'], 'legame' => $riga['firmatario_legame']],
            'firmato_da' => $riga['firmato_da'],
            'impronta' => $riga['impronta'],
        ];
    }

    /**
     * crm.documenti.elenca (DocumentiController::elenca): i documenti di un contatto, dal più recente (l'id), a cursore. `persona_id` è obbligatorio; un
     * contatto che non è del workspace del gettone è 422 sul parametro, mai 404. Il cursore è legato al contatto.
     *
     * @return array{int, array<string, mixed>}
     */
    private function elencaDocumentiCrm(Request $richiesta): array
    {
        $chi = $this->conCrm($richiesta);
        $query = $this->testi->validaQuery(self::query($richiesta), ['persona_id' => ['bail', 'required', 'string', $this->contattoDelWorkspaceCrm($chi['workspace'])]]);
        $persona = strtolower($query['persona_id']);
        $righe = array_values(array_filter($this->crm['documenti'], fn (array $documento) => $documento['workspace'] === $chi['workspace'] && $documento['persona_id'] === $persona));
        $intero = $this->vedeISensibiliCrm;

        return $this->paginaCrm($richiesta, "crm.documenti.elenca:{$persona}", $righe, fn (array $una, array $altra) => strcmp($altra['id'], $una['id']), fn (array $documento) => $this->voceDocumentoCrm($documento, $intero));
    }

    /** La regola di un `persona_id` che è un contatto non eliminato del workspace (Note::contattoDelWorkspace). */
    private function contattoDelWorkspaceCrm(string $workspace): Closure
    {
        return function (string $campo, mixed $id, Closure $fail) use ($workspace) {
            $riga = is_string($id) ? ($this->crm['persone'][strtolower($id)] ?? null) : null;

            if ($riga === null || $riga['workspace'] !== $workspace || ($riga['eliminata'] ?? false)) {
                $this->rifiutaCrm($fail, $campo, Regola::NON_TROVATO, 'validation.exists', ['attribute' => $campo]);
            }
        };
    }

    /**
     * Il contatto di una scrittura (Note::contatto): che non c'è, o è di un altro workspace, è lo stesso 422 della validazione su `persona_id`; uno
     * anonimizzato è 409 `persona_anonimizzata`.
     *
     * @return array<string, mixed>
     */
    private function contattoDelDocumentoCrm(string $workspace, string $id): array
    {
        $riga = $this->crm['persone'][strtolower($id)] ?? null;

        if ($riga === null || $riga['workspace'] !== $workspace || ($riga['eliminata'] ?? false)) {
            throw $this->erroreCrm('persona_id', 'validation.exists', Regola::NON_TROVATO, ['attribute' => $this->testi->nome('persona_id')]);
        }

        if ($riga['anonimizzata_il'] !== null) {
            throw new Problema('persona_anonimizzata');
        }

        return $riga;
    }

    /**
     * Le regole del corpo di `crm.documenti.crea` (Documenti::regole). Il testo non c'è: lo compila il finto, e un `testo` del client è un campo estraneo.
     *
     * @return array<string, mixed>
     */
    private function regoleDelDocumentoCrm(): array
    {
        return [
            'persona_id' => ['bail', 'required', 'string', 'max:64'],
            'modello' => ['bail', 'required', 'string', Rule::in(array_keys(self::modelliCrm()))],
            'versione' => ['bail', 'required', 'integer:strict', 'min:1'],
            'lingua' => ['bail', 'required', 'string', Rule::in(self::MODELLI_LINGUE_CRM)],
            'firmatario' => ['bail', 'required', 'array'],
            'firmatario.nome' => ['bail', 'required', 'string', 'max:200', new NomeScritto],
            'firmatario.cognome' => ['bail', 'required', 'string', 'max:200', new NomeScritto],
            'firmatario.legame' => ['bail', 'required', 'string', Rule::in(self::CRM_DOCUMENTI_LEGAMI)],
            'tratto' => ['bail', 'required', 'array'],
            'tratto.tipo' => ['bail', 'required', 'string', Rule::in(self::CRM_DOCUMENTI_TRATTI)],
            'tratto.immagine' => ['bail', 'required_if:tratto.tipo,immagine', 'prohibited_if:tratto.tipo,spunta', 'string', function (string $campo, mixed $valore, Closure $fail) {
                $png = is_string($valore) && strlen($valore) <= (int) ceil(self::CRM_DOCUMENTI_TRATTO_BYTE / 3) * 4 ? base64_decode($valore, true) : false;
                $misure = is_string($png) && $png !== '' && strlen($png) <= self::CRM_DOCUMENTI_TRATTO_BYTE && str_starts_with($png, "\x89PNG\r\n\x1a\n") ? @getimagesizefromstring($png) : false;

                if ($misure === false || $misure[2] !== IMAGETYPE_PNG || max($misure[0], $misure[1]) > self::CRM_DOCUMENTI_TRATTO_LATO) {
                    $this->rifiutaCrm($fail, $campo, Regola::FORMATO, 'regole.documento_tratto_immagine', ['max' => (int) (self::CRM_DOCUMENTI_TRATTO_BYTE / 1024), 'lato' => self::CRM_DOCUMENTI_TRATTO_LATO]);
                }
            }],
        ];
    }

    /**
     * Se il contatto è minorenne oggi, nel fuso del workspace (Documenti::minorenne): la data di nascita è il valore del campo `data_nascita`. Senza
     * data (o con una che non si legge) è trattato da maggiorenne.
     *
     * @param  array<string, mixed>  $persona
     */
    private function minorenneCrm(array $persona): bool
    {
        $nascita = $this->crm['valori']['persona'][$persona['id']]['data_nascita'] ?? null;

        if (! is_string($nascita) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $nascita) !== 1) {
            return false;
        }

        return CarbonImmutable::parse($nascita, self::CRM_ACQUISTI_FUSO)->addYears(self::CRM_DOCUMENTI_MAGGIORE_ETA)->format('Y-m-d') > self::oggiAcquistoCrm();
    }

    /**
     * Firma un documento di `$persona` (Documenti::firma): la versione deve essere la corrente (una più vecchia è 409 `versione_non_corrente`, una che
     * non esiste 422), e il contatto minorenne si firma da un genitore o da un tutore. Il testo si compila qui.
     *
     * @param  array<string, mixed>  $persona
     * @param  array<string, mixed>  $campi  il corpo validato
     * @return array<string, mixed> la riga del documento
     */
    private function firmaDocumentoCrm(array $persona, array $campi, string $modo, ?string $firmatoDa, ?string $link = null): array
    {
        $modello = self::trovaModelloCrm((string) $campi['modello']) ?? throw new Problema('non_trovato');
        $versione = (int) $campi['versione'];
        $corrente = self::versioneCorrenteCrm($modello);

        if ($versione > $corrente) {
            throw $this->erroreCrm('versione', 'regole.documento_versione_inesistente', Regola::VALORE_NON_AMMESSO, ['corrente' => (string) $corrente]);
        }

        if ($versione < $corrente) {
            throw new Problema('versione_non_corrente');
        }

        if ($this->minorenneCrm($persona) && ! in_array($campi['firmatario']['legame'], self::CRM_DOCUMENTI_LEGAMI_DEL_MINORENNE, true)) {
            throw $this->erroreCrm('firmatario.legame', 'regole.documento_legame_minorenne', Regola::VALORE_NON_AMMESSO);
        }

        $testo = $this->testoDelModelloCrm($modello, $versione, (string) $campi['lingua'], $persona);
        $id = self::id();
        $this->crm['documenti'][$id] = [
            'id' => $id, 'workspace' => $persona['workspace'], 'persona_id' => $persona['id'], 'modello' => $modello['chiave'], 'versione' => $versione, 'lingua' => (string) $campi['lingua'],
            'stato' => 'firmato', 'modo' => $modo, 'testo' => $testo, 'impronta' => hash('sha256', $testo),
            'firmatario_nome' => (string) $campi['firmatario']['nome'], 'firmatario_cognome' => (string) $campi['firmatario']['cognome'], 'firmatario_legame' => (string) $campi['firmatario']['legame'],
            'tratto_tipo' => (string) $campi['tratto']['tipo'], 'tratto_immagine' => $campi['tratto']['immagine'] ?? null,
            'firmato_il' => self::adessoCrm(), 'firmato_da' => $firmatoDa, 'revocato_il' => null, 'revocato_da' => null, 'link_id' => $link,
        ];

        return $this->crm['documenti'][$id];
    }

    /**
     * crm.documenti.crea (DocumentiController::crea): fa firmare un documento in presenza e risponde 201 con il documento firmato (senza testo,
     * firmatario e tratto; nessuna `Location`). `Idempotency-Key` è obbligatoria (422 sull'header se manca). Il contatto che non c'è è 422 su
     * `#/persona_id`, uno anonimizzato 409.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaDocumentoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);

        if (! $richiesta->toPsrRequest()->hasHeader('Idempotency-Key')) {
            throw new Problema('dati_non_validi', [['detail' => $this->testi->testo('regole.chiave_idempotenza'), 'regola' => Regola::OBBLIGATORIO, 'header' => 'Idempotency-Key']]);
        }

        return $this->conIdempotenza($richiesta, 'crm.documenti.crea', $chi, $corpo, function () use ($chi, $corpo) {
            $campi = $this->testi->validaCorpo($corpo, $this->regoleDelDocumentoCrm());
            $persona = $this->contattoDelDocumentoCrm($chi['workspace'], (string) $campi['persona_id']);
            $documento = $this->firmaDocumentoCrm($persona, $campi, 'presenza', $chi['persona']);

            return [201, ['data' => [
                'id' => $documento['id'], 'stato' => $documento['stato'], 'firmato_il' => self::iso($documento['firmato_il']), 'firmato_da' => $documento['firmato_da'],
                'versione' => $documento['versione'], 'impronta' => $documento['impronta'],
            ]], []];
        });
    }

    /**
     * crm.documenti.revoca.crea (DocumentiController::revocaCrea): revoca il consenso di un documento e risponde 200 con lo stato; il documento resta.
     * Un modello che non si revoca è 409 `documento_non_revocabile`, un documento già revocato 409 `documento_gia_revocato`, di un contatto anonimizzato 409
     * `persona_anonimizzata`. Il corpo è vuoto.
     *
     * @return array{int, array<string, mixed>}
     */
    private function revocaDocumentoCrm(Request $richiesta, array $corpo, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $documento = $this->rigaCrm('documenti', $chi['workspace'], $id);
        $this->testi->validaCorpo($corpo, []);
        $this->contattoDelDocumentoCrm($chi['workspace'], $documento['persona_id']);

        if (! (self::trovaModelloCrm($documento['modello'])['revocabile'] ?? false)) {
            throw new Problema('documento_non_revocabile');
        }

        if ($documento['stato'] === 'revocato') {
            throw new Problema('documento_gia_revocato');
        }

        $this->crm['documenti'][$documento['id']]['stato'] = 'revocato';
        $this->crm['documenti'][$documento['id']]['revocato_il'] = self::adessoCrm();
        $this->crm['documenti'][$documento['id']]['revocato_da'] = $chi['persona'];
        $revocato = $this->crm['documenti'][$documento['id']];

        return [200, ['data' => ['id' => $revocato['id'], 'stato' => $revocato['stato'], 'revocato_il' => self::iso($revocato['revocato_il']), 'revocato_da' => $revocato['revocato_da']]]];
    }

    /**
     * crm.documenti.pdf.mostra (DocumentiController::pdfMostra): `200` `application/pdf`, solo il proprietario e gli amministratori. Il finto dà un PDF
     * minimo e valido con il nome del modello: la forma (tipo, nome del file, niente cache) è quella vera, il documento no. Un documento a cui l'oblio
     * ha tolto il testo è 404. Il freno per persona non c'è.
     *
     * @return array{int, string, array<string, string>}
     */
    private function mostraPdfDocumentoCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta, ['proprietario', 'amministratore']);
        $documento = $this->rigaCrm('documenti', $chi['workspace'], $id);

        if ($documento['testo'] === '') {
            throw new Problema('non_trovato');
        }

        $titolo = self::trovaModelloCrm($documento['modello'])['nome'][$documento['lingua']] ?? $documento['modello'];

        return [200, $this->pdfDiUnaRigaCrm($titolo), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename='.$documento['modello'].'-v'.$documento['versione'].'-'.$documento['firmato_il']->utc()->format('Y-m-d').'.pdf',
            'Cache-Control' => 'no-store, private',
            'X-Content-Type-Options' => 'nosniff',
        ]];
    }

    /**
     * crm.documenti.link.crea (DocumentiLinkController::crea): emette un link `{persona_id, modello, lingua}` e risponde 201 con `id`, `token` e
     * `scade_il` (14 giorni). Niente `Idempotency-Key`: una chiamata ripetuta ne emette uno nuovo. Il token si vede una volta sola.
     *
     * @return array{int, array<string, mixed>, array<string, string>}
     */
    private function creaLinkDocumentoCrm(Request $richiesta, array $corpo): array
    {
        $chi = $this->conCrm($richiesta);
        $campi = $this->testi->validaCorpo($corpo, [
            'persona_id' => ['bail', 'required', 'string', 'max:64'],
            'modello' => ['bail', 'required', 'string', Rule::in(array_keys(self::modelliCrm()))],
            'lingua' => ['bail', 'required', 'string', Rule::in(self::MODELLI_LINGUE_CRM)],
        ]);
        $persona = $this->contattoDelDocumentoCrm($chi['workspace'], (string) $campi['persona_id']);
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $id = self::id();
        $this->crm['link_documenti'][$id] = [
            'id' => $id, 'workspace' => $chi['workspace'], 'persona_id' => $persona['id'], 'modello' => $campi['modello'], 'lingua' => $campi['lingua'],
            'impronta' => hash('sha256', $token), 'scade_il' => self::adessoCrm()->addDays(self::CRM_LINK_DOCUMENTI_GIORNI), 'usato_il' => null, 'creato_da' => $chi['persona'],
        ];

        return [201, ['data' => ['id' => $id, 'token' => $token, 'scade_il' => self::iso($this->crm['link_documenti'][$id]['scade_il'])]], ['Cache-Control' => 'no-store, private']];
    }

    /**
     * crm.documenti.link.elimina (DocumentiLinkController::elimina): revoca un link e risponde 204; da lì il suo token è 404. Uno già revocato o di un
     * altro workspace è 404.
     *
     * @return array{int, null}
     */
    private function eliminaLinkDocumentoCrm(Request $richiesta, string $id): array
    {
        $chi = $this->conCrm($richiesta);
        $link = $this->rigaCrm('link_documenti', $chi['workspace'], $id);
        $this->crm['link_documenti'][$link['id']]['eliminata'] = true;

        return [204, null];
    }

    /**
     * Il link di un token con il suo contatto (DocumentiPubblici::apri). Sconosciuto, scaduto, usato, revocato, di un contatto archiviato dall'oblio o
     * eliminato, di un workspace con l'eliminazione programmata o con il CRM spento: sempre lo stesso 404 `non_trovato`.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function apriIlLinkDocumentoCrm(string $token): array
    {
        $impronta = hash('sha256', $token);
        $link = null;

        foreach ($this->crm['link_documenti'] as $riga) {
            if ($riga['impronta'] === $impronta && ! ($riga['eliminata'] ?? false)) {
                $link = $riga;
            }
        }

        if ($link === null || ! $this->appAttiva($link['workspace'], 'crm') || isset($this->eliminazioni[$link['workspace']])) {
            throw new Problema('non_trovato');
        }

        $persona = $this->crm['persone'][$link['persona_id']] ?? null;

        if ($link['usato_il'] !== null || $link['scade_il']->lessThanOrEqualTo(self::adessoCrm()) || $persona === null || ($persona['eliminata'] ?? false)
            || $persona['anonimizzata_il'] !== null || self::trovaModelloCrm($link['modello']) === null) {
            throw new Problema('non_trovato');
        }

        return [$link, $persona];
    }

    /**
     * documenti.pubblici.lettura.crea (DocumentiPubbliciController::lettura): il testo che il cliente firmerà, compilato per il suo contatto, con la
     * versione corrente (da rimandare firmando). Il token sta nel corpo; il freno è per token. Non scrive niente e non consuma il link.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function leggiDocumentoPubblicoCrm(array $corpo): array
    {
        $campi = $this->testi->validaStretta($corpo, ['token' => $this->regolaDelTokenDocumentoCrm()]);
        $this->frena('documenti-pubblici-lettura:'.hash('sha256', $campi['token']), self::FRENI['documenti_pubblici_letture_per_token'], self::MINUTO);
        [$link, $persona] = $this->apriIlLinkDocumentoCrm($campi['token']);
        $modello = self::trovaModelloCrm($link['modello']) ?? throw new Problema('non_trovato');
        $versione = self::versioneCorrenteCrm($modello);

        return [200, ['data' => [
            'modello' => $modello['chiave'],
            'titolo' => $modello['nome'][$link['lingua']],
            'versione' => $versione,
            'lingua' => $link['lingua'],
            'testo' => $this->testoDelModelloCrm($modello, $versione, $link['lingua'], $persona),
            'workspace' => ['nome' => $this->workspace[$link['workspace']]['nome'], 'logo' => null],
            'scade_il' => self::iso($link['scade_il']),
        ]]];
    }

    /**
     * documenti.pubblici.firma.crea (DocumentiPubbliciController::firma): `{token, versione, firmatario, tratto}`. Crea lo stesso documento di
     * `crm.documenti.crea` (stesse regole su minorenni, tratto e versione), con `modo: link` e `firmato_da: null`, e spegne il link. Risponde 201 con
     * `stato` e `firmato_il`; una seconda firma dello stesso link è 404.
     *
     * @param  array<mixed>  $corpo
     * @return array{int, array<string, mixed>}
     */
    private function firmaDocumentoPubblicoCrm(array $corpo): array
    {
        $regole = ['token' => $this->regolaDelTokenDocumentoCrm()] + array_diff_key($this->regoleDelDocumentoCrm(), array_flip(['persona_id', 'modello', 'lingua']));
        $campi = $this->testi->validaCorpo($corpo, $regole);
        $this->frena('documenti-pubblici-firma:'.hash('sha256', $campi['token']), self::FRENI['documenti_pubblici_firme_per_token'], self::MINUTO);
        [$link, $persona] = $this->apriIlLinkDocumentoCrm($campi['token']);
        unset($campi['token']);

        $documento = $this->firmaDocumentoCrm($persona, ['modello' => $link['modello'], 'lingua' => $link['lingua']] + $campi, 'link', null, $link['id']);
        $this->crm['link_documenti'][$link['id']]['usato_il'] = self::adessoCrm();

        return [201, ['data' => ['stato' => $documento['stato'], 'firmato_il' => self::iso($documento['firmato_il'])]]];
    }

    /** @return list<mixed> la forma di un token: 43 caratteri di base64url */
    private function regolaDelTokenDocumentoCrm(): array
    {
        return ['bail', 'required', 'string', 'regex:/^[A-Za-z0-9_-]{43}$/D'];
    }

    /**
     * L'oblio del contatto (Anonimizzazione::documenti): il testo compilato, il firmatario e il tratto dei suoi documenti si svuotano, revocati compresi.
     * Restano lo stato, le date, il modello, la versione e l'impronta.
     */
    private function dimenticaIDocumentiCrm(string $persona): void
    {
        foreach ($this->crm['documenti'] as $id => $riga) {
            if ($riga['persona_id'] === $persona) {
                $this->crm['documenti'][$id] = [...$riga, 'testo' => '', 'firmatario_nome' => '', 'firmatario_cognome' => '', 'tratto_immagine' => null];
            }
        }
    }
}
