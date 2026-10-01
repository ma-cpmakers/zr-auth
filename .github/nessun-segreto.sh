#!/usr/bin/env bash
# Nessun segreto nel repo: il pacchetto è pubblico, e un segreto qui dentro è pubblicato. Gira nella radice del repo, sui
# file che git conosce, e lo lancia la CI; esce 1 e dice dove (il nome della variabile, mai il valore). Grezzo apposta: un
# falso allarme si corregge scrivendo diverso, un segreto pubblicato si revoca.
set -uo pipefail

trovato=0

# L'uscita di un controllo: 1 = trovato, e lo si dice; ogni altra uscita diversa da 0 vuol dire che il controllo non ha
# letto tutto (un file che non si apre, perl o git che falliscono): la guardia è rossa anche così, e lo dice.
esito() {
    case "$1" in
        0) ;;
        1) echo "$2"; trovato=1 ;;
        *) echo "$2: il controllo non ha letto tutto (uscita $1)"; trovato=1 ;;
    esac
}

# I file che non ci devono essere: un .env (anche .env.example), una chiave, un certificato, un archivio di chiavi (.p12,
# .pfx), le credenziali di Composer (auth.json).
if git ls-files | grep -E '(^|/)\.env($|\.)|\.key$|\.pem$|\.p12$|\.pfx$|(^|/)auth\.json$'; then
    echo "file sensibile nel repo"
    trovato=1
fi

# Una chiave privata, in qualunque file.
if git grep -lE 'BEGIN [A-Z ]*PRIVATE KEY' -- .; then
    echo "chiave privata nel repo"
    trovato=1
fi

# Il nome di una variabile segreta, in inglese o in italiano.
export NOMI='[A-Z0-9_]*(?:SECRET|KEY|TOKEN|PASSWORD|PASSWD|PWD|SEGRETO|SEGRETI|CHIAVE|CHIAVI)[A-Z0-9_]*'

# I controlli in perl ricevono da git i nomi dei file, separati da NUL, e li aprono con l'open a tre argomenti: un nome con
# spazi ai lati o con < > | resta il nome di un file (con l'open a due argomenti, un nome che finisce con | è un comando).
# Un file che non si apre fa uscire 2.

# Un valore di riserva per una variabile segreta, nel PHP (maiuscole o minuscole): env('…', valore), env('…') ?: valore,
# env('…') ?? valore, anche su più righe; lo stesso con getenv() e Env::get(); $_ENV['…'] e $_SERVER['…'] seguiti da ?: o ??.
git ls-files -z -- '*.php' | perl -e '
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; $rotto = 1; next };
        my $testo = do { local $/; <$fh> };
        close $fh;
        while ($testo =~ /(?:\b(?:env|getenv|Env::get)\s*\(\s*(["\x27])($nomi)\1\s*(?:,|\)\s*\?\s*[?:])|\$_(?:ENV|SERVER)\s*\[\s*(["\x27])($nomi)\3\s*\]\s*\?\s*[?:])/gi) {
            printf "%s:%d: %s\n", $file, 1 + (substr($testo, 0, $-[0]) =~ tr/\n//), $2 // $4;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)'
esito $? "un segreto come valore di riserva di env()"

# Un valore per una variabile segreta nella configurazione di PHPUnit (phpunit.xml, phpunit.xml.dist, phpunit.dist.xml):
# <env name="…" value="…"/>, e così <server>, <var>, <const>, <ini>, con gli attributi in qualunque ordine e su più righe.
# Un valore vuoto passa.
git ls-files -z -- '*phpunit*.xml*' | perl -e '
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; $rotto = 1; next };
        my $testo = do { local $/; <$fh> };
        close $fh;
        while ($testo =~ /<(?:env|server|var|const|ini)\b([^>]*)>/gi) {
            my ($attributi, $riga) = ($1, 1 + (substr($testo, 0, $-[0]) =~ tr/\n//));
            next unless $attributi =~ /\bname\s*=\s*(["\x27])($nomi)\1/i;
            my $nome = $2;
            next unless $attributi =~ /\bvalue\s*=\s*(["\x27])(?!\1)/i;
            printf "%s:%d: %s\n", $file, $riga, $nome;
            $trovato = 1;
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)'
esito $? "un segreto nella configurazione di PHPUnit"

# Un valore per una variabile segreta scritto come in un .env — NOME=valore, senza spazi intorno all'uguale — in qualunque
# file di testo (un README, un esempio, uno script), e come in un file YAML, NOME: valore a inizio riga. Nomi in maiuscolo,
# come nell'ambiente. Passano il valore vuoto, due virgolette vuote, i segnaposto (<…>, $VAR, ${VAR}, ${{ secrets.… }},
# {{ … }}, …, ...), null, ~, un commento, e la fine di un codice in linea (`NOME=`). I file binari (con un NUL) no.
git ls-files -z | perl -e '
    my ($trovato, $rotto, $nomi) = (0, 0, $ENV{NOMI});
    my $vuoto = qr/(?!["\x27]{2}|["\x27]?(?:[<\$\{`]|…|\.\.\.)|(?i:null)\b|~|\s*$|\s*#)/;
    local $/ = "\0";
    while (my $file = <STDIN>) {
        chomp $file;
        open(my $fh, "<", $file) or do { warn "non si apre: $file\n"; $rotto = 1; next };
        my $testo = do { local $/; <$fh> };
        close $fh;
        next if $testo =~ /\0/;
        my ($yaml, $riga) = ($file =~ /\.ya?ml$/, 0);
        for my $linea (split /\n/, $testo) {
            $riga++;
            if ($linea =~ /\b($nomi)=$vuoto/ || ($yaml && $linea =~ /^\s*-?\s*($nomi):\s*$vuoto\S/)) {
                printf "%s:%d: %s\n", $file, $riga, $1;
                $trovato = 1;
            }
        }
    }
    exit($rotto ? 2 : $trovato ? 1 : 0)'
esito $? "un segreto scritto come in un .env o in un YAML"

exit "$trovato"
