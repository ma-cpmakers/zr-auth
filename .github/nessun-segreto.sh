#!/usr/bin/env bash
# Nessun segreto nel repo: il pacchetto è pubblico, e un segreto qui dentro è pubblicato. Gira nella radice del repo, sui
# file che git conosce, e lo lancia la CI; esce 1 e dice dove (il nome della variabile, mai il valore). Grezzo apposta: un
# falso allarme si corregge scrivendo diverso, un segreto pubblicato si revoca.
set -uo pipefail

trovato=0

# I file che non ci devono essere: un .env (anche .env.example), una chiave, un certificato.
if git ls-files | grep -E '(^|/)\.env($|\.)|\.key$|\.pem$'; then
    echo "file sensibile nel repo"
    trovato=1
fi

# Una chiave privata, in qualunque file.
if git grep -lE 'BEGIN [A-Z ]*PRIVATE KEY' -- .; then
    echo "chiave privata nel repo"
    trovato=1
fi

# Il nome di una variabile segreta, in inglese o in italiano, maiuscole o minuscole.
export NOMI='[A-Z0-9_]*(?:SECRET|KEY|TOKEN|PASSWORD|SEGRETO|CHIAVE)[A-Z0-9_]*'

# Un valore di riserva per una variabile segreta, nel PHP: env('…', valore), env('…') ?: valore, env('…') ?? valore, anche
# su più righe; lo stesso con getenv() e Env::get(); $_ENV['…'] e $_SERVER['…'] seguiti da ?: o ??.
if ! git ls-files -z -- '*.php' | xargs -0 -r perl -0777 -ne '
    my $nomi = $ENV{NOMI};
    while (/(?:\b(?:env|getenv|Env::get)\s*\(\s*(["\x27])($nomi)\1\s*(?:,|\)\s*\?\s*[?:])|\$_(?:ENV|SERVER)\s*\[\s*(["\x27])($nomi)\3\s*\]\s*\?\s*[?:])/gi) {
        printf "%s:%d: %s\n", $ARGV, 1 + (substr($_, 0, $-[0]) =~ tr/\n//), $2 // $4;
        $trovato = 1;
    }
    END { exit($trovato ? 1 : 0) }'; then
    echo "un segreto come valore di riserva di env()"
    trovato=1
fi

# Un valore per una variabile segreta nella configurazione di PHPUnit (phpunit.xml, phpunit.xml.dist, phpunit.dist.xml):
# <env name="…" value="…"/>, e così <server>, <var>, <const>, <ini>, con gli attributi in qualunque ordine e su più righe.
# Un valore vuoto passa.
if ! git ls-files -z -- '*phpunit*.xml*' | xargs -0 -r perl -0777 -ne '
    my $nomi = $ENV{NOMI};
    while (/<(?:env|server|var|const|ini)\b([^>]*)>/gi) {
        my ($attributi, $riga) = ($1, 1 + (substr($_, 0, $-[0]) =~ tr/\n//));
        next unless $attributi =~ /\bname\s*=\s*(["\x27])($nomi)\1/i;
        my $nome = $2;
        next unless $attributi =~ /\bvalue\s*=\s*(["\x27])(?!\1)/i;
        printf "%s:%d: %s\n", $ARGV, $riga, $nome;
        $trovato = 1;
    }
    END { exit($trovato ? 1 : 0) }'; then
    echo "un segreto nella configurazione di PHPUnit"
    trovato=1
fi

exit "$trovato"
