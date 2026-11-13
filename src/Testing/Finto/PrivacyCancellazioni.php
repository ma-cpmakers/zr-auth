<?php

namespace Zeiras\Auth\Testing\Finto;

use Closure;

/**
 * La cancellazione dei dati dalla pagina pubblica del finto (#3053): `privacy.cancellazioni.crea`, `.mostra` e `.conferma.crea`, senza gettone, come
 * PrivacyCancellazioniController del backoffice. Il finto non manda email: il codice che l'email porterebbe lo dà `codiceDellaCancellazione($email)`. `.crea` risponde sempre
 * `202` con `{data: {accettato: true}}`; la trappola `sito_web` e `compilato_in_ms` sotto 3000 sono scartati con lo stesso `202` e non contano nei freni; freno per email (3 in
 * un'ora), per codice (30 al minuto) e tetto in tutto (100 al giorno), mai per IP. Un codice che non apre niente è sempre `404 non_trovato`; la conferma, una volta sola,
 * avvia la stessa eliminazione di `io.elimina`.
 */
trait PrivacyCancellazioni
{
    /** @var array<string, array{persona: string, richiesta_il: \Carbon\CarbonImmutable, confermata: bool}> per codice */
    private array $cancellazioniPrivacy = [];

    /** @var array<string, string> l'ultimo codice emesso per un'email (normalizzata), come l'avrebbe letto la persona nell'email */
    private array $codiciDiCancellazione = [];

    /** Il codice che l'email di conferma ha portato all'email data, se la richiesta ne ha fatto nascere uno (account vivo, senza proprietà da trasferire). */
    public function codiceDellaCancellazione(string $email): ?string
    {
        return $this->codiciDiCancellazione[self::normalizza($email)] ?? null;
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array{data: array{accettato: true}}}
     */
    private function chiediLaCancellazione(array $corpo): array
    {
        $regole = [
            'email' => ['bail', 'required', 'string', 'max:254', 'email:rfc,filter'],
            'sito_web' => ['bail', 'sometimes', 'nullable', 'string', 'max:1000'],
            'compilato_in_ms' => ['bail', 'required', 'integer:strict', 'min:0'],
        ];
        $campi = $this->testi->valida($corpo, $regole + $this->campiEstraneiPrivacy($corpo, $regole));

        if (($campi['sito_web'] ?? null) !== null || $campi['compilato_in_ms'] < 3000) {
            return [202, ['data' => ['accettato' => true]]];
        }

        $email = self::normalizza((string) $campi['email']);
        $this->frena('privacy-cancellazioni:email:'.hash('sha256', $email), 3, 3600);
        $this->frena('privacy-cancellazioni:totale', 100, 86400);

        $persona = $this->conEmail($email);

        if ($persona !== null && ! isset($this->eliminazioniPersona[$persona]) && $this->proprietaDaTrasferire($persona) === []) {
            $codice = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
            $this->cancellazioniPrivacy[$codice] = ['persona' => $persona, 'richiesta_il' => now()->toImmutable()->startOfMillisecond(), 'confermata' => false];
            $this->codiciDiCancellazione[$email] = $codice;
        }

        return [202, ['data' => ['accettato' => true]]];
    }

    /** @return array{int, array{data: array{stato: string, richiesta_il: string, definitiva_entro: ?string}}} */
    private function mostraLaCancellazione(string $codice): array
    {
        $this->frenaIlCodiceDellaCancellazione('mostra', $codice);

        return [200, ['data' => $this->statoDellaCancellazione($codice) ?? throw new Problema('non_trovato')]];
    }

    /**
     * @param  array<mixed>  $corpo
     * @return array{int, array{data: array{stato: string, richiesta_il: string, definitiva_entro: ?string}}}
     */
    private function confermaLaCancellazione(array $corpo, string $codice): array
    {
        $this->testi->valida($corpo, $this->campiEstraneiPrivacy($corpo, []));
        $this->frenaIlCodiceDellaCancellazione('conferma', $codice);

        $voce = $this->cancellazioniPrivacy[$codice] ?? null;

        if ($voce === null || $voce['confermata'] || $voce['richiesta_il']->addHours(24)->lte(now())) {
            throw new Problema('non_trovato');
        }

        try {
            $this->avviaLEliminazione($voce['persona']);
        } catch (Problema) {
            // La proprietà è cambiata dopo il link: la persona non si elimina, e la risposta pubblica non lo dice.
            throw new Problema('non_trovato');
        }

        $this->cancellazioniPrivacy[$codice]['confermata'] = true;

        return [200, ['data' => $this->statoDellaCancellazione($codice) ?? throw new Problema('non_trovato')]];
    }

    /** @return array{stato: string, richiesta_il: string, definitiva_entro: ?string}|null */
    private function statoDellaCancellazione(string $codice): ?array
    {
        $voce = $this->cancellazioniPrivacy[$codice] ?? null;

        if ($voce === null) {
            return null;
        }

        if (! $voce['confermata']) {
            return $voce['richiesta_il']->addHours(24)->lte(now()) ? null : ['stato' => 'in_attesa_di_conferma', 'richiesta_il' => self::iso($voce['richiesta_il']), 'definitiva_entro' => null];
        }

        $richiesta = $this->eliminazioniPersona[$voce['persona']] ?? null;

        return $richiesta === null ? null : ['stato' => 'confermata', 'richiesta_il' => self::iso($richiesta), 'definitiva_entro' => self::iso($richiesta->addDays(30))];
    }

    private function frenaIlCodiceDellaCancellazione(string $metodo, string $codice): void
    {
        $this->frena("privacy-cancellazioni-{$metodo}:codice:".hash('sha256', substr($codice, 0, 200)), 30, 60);
    }

    /**
     * Come Corpo::valida del backoffice: un campo che il contratto non dichiara è 422 `campo_estraneo` sul suo pointer.
     *
     * @param  array<mixed>  $corpo
     * @param  array<string, mixed>  $regole
     * @return array<string, list<Closure>>
     */
    private function campiEstraneiPrivacy(array $corpo, array $regole): array
    {
        $estranei = array_diff(array_map('strval', array_keys($corpo)), array_keys($regole));

        return array_fill_keys($estranei, [function (string $attributo, mixed $valore, Closure $fail) {
            $this->rifiutaCrm($fail, $attributo, Regola::CAMPO_ESTRANEO, 'validation.prohibited', ['attribute' => $attributo]);
        }]);
    }

    /**
     * I workspace di cui la persona è proprietaria con altri account (quelli che bloccano l'eliminazione).
     *
     * @return list<string>
     */
    private function proprietaDaTrasferire(string $persona): array
    {
        $ids = [];

        foreach ($this->workspace as $id => $workspace) {
            if (($this->membri[$id][$persona] ?? null) === 'proprietario' && ! isset($this->eliminazioni[$id]) && array_diff_key($this->membri[$id], [$persona => true]) !== []) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
