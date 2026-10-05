<?php

namespace Zeiras\Auth\Testing\Finto;

/**
 * I freni del backoffice finto, come RateLimiter di Laravel nel backoffice: una finestra fissa che parte dal primo colpo e
 * dura i suoi secondi; finita la finestra, il conto riparte. Il tempo è now(): un test lo sposta con travel().
 */
final class Freni
{
    /** @var array<string, array{colpi: int, fine: int}> */
    private array $finestre = [];

    /**
     * Conta una richiesta (Freno::conta del backoffice): oltre `$massimo` nella finestra, 429 troppe_richieste con
     * Retry-After, i secondi che mancano alla fine della finestra. La richiesta si conta anche quando è frenata.
     */
    public function conta(string $chiave, int $massimo, int $secondi): void
    {
        if ($this->colpisci($chiave, $secondi) > $massimo) {
            throw new Problema('troppe_richieste', header: ['Retry-After' => (string) $this->restano($chiave)]);
        }
    }

    /** Un colpo nella finestra della chiave (RateLimiter::hit): torna il conto. */
    public function colpisci(string $chiave, int $secondi): int
    {
        $finestra = $this->finestra($chiave) ?? ['colpi' => 0, 'fine' => now()->getTimestamp() + $secondi];
        $finestra['colpi']++;
        $this->finestre[$chiave] = $finestra;

        return $finestra['colpi'];
    }

    /** Se la finestra ha già `$massimo` colpi (RateLimiter::tooManyAttempts). */
    public function pieno(string $chiave, int $massimo): bool
    {
        return ($this->finestra($chiave)['colpi'] ?? 0) >= $massimo;
    }

    public function azzera(string $chiave): void
    {
        unset($this->finestre[$chiave]);
    }

    /** I secondi alla fine della finestra (RateLimiter::availableIn). */
    private function restano(string $chiave): int
    {
        return max(0, ($this->finestra($chiave)['fine'] ?? 0) - now()->getTimestamp());
    }

    /** @return array{colpi: int, fine: int}|null la finestra della chiave, se non è finita */
    private function finestra(string $chiave): ?array
    {
        if (isset($this->finestre[$chiave]) && now()->getTimestamp() >= $this->finestre[$chiave]['fine']) {
            unset($this->finestre[$chiave]);
        }

        return $this->finestre[$chiave] ?? null;
    }
}
