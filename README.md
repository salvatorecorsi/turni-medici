# Turni Medici Demo

Demo WordPress di gestione di strutture, calendari e turni con [Define Blocks](https://github.com/corsivo-suite/define-blocks) e [Core Editor](https://github.com/corsivo-suite/core-editor).

Il progetto include il plugin applicativo `turnimedici-plugin`, il tema `turnimedici-theme` e due submodule Git collegati ai repository ufficiali Define Blocks e Core Editor. I submodule sono fissati ai commit registrati in `dependencies.lock.json` e contengono anche gli asset compilati. Non occorrono altri plugin Corsivo.

## Installazione

Se stai preparando il repository da una copia locale che contiene ancora i due plugin come cartelle normali, esegui una sola volta da PowerShell:

```powershell
powershell -File .\scripts\convert-submodules.ps1
```

Lo script converte le copie locali in submodule dei repository ufficiali, fissati ai commit richiesti. Per un repository già convertito, usa invece la clonazione o l'inizializzazione descritte sotto.

Clona il progetto inizializzando anche i submodule:

```sh
git clone --recurse-submodules https://github.com/salvatorecorsi/turni-medici.git turnimedici-git
cd turnimedici-git
```

Se hai già clonato il repository, esegui dalla sua cartella:

```sh
git submodule update --init --recursive
```

Ripeti questo comando dopo un aggiornamento del progetto per allineare i plugin ai commit richiesti. Senza l'inizializzazione dei submodule le loro cartelle non contengono i plugin.

Servono PHP 8.1 o superiore, MySQL o MariaDB, WordPress e WP-CLI. La demo è stata verificata su WordPress 7.0.1 e PHP 8.4. Node.js e npm servono soltanto per ricompilare gli asset.

Esegui questi comandi dalla cartella del progetto, su un database nuovo e dedicato. Sostituisci utente MySQL e URL con quelli del tuo ambiente; le password vengono richieste interattivamente.

```sh
wp core download --version=7.0.1 --locale=it_IT --skip-content
wp config create --dbname=turnimedici_demo --dbuser=YOUR_DB_USER --dbhost=localhost --dbprefix=tmdemo_ --prompt=dbpass
wp config set WP_ENVIRONMENT_TYPE local
wp config set TM_DEMO_MODE true --raw
wp config set DISABLE_WP_CRON true --raw
wp db create
wp core install --url=http://localhost/turnimedici-git --title="Turni Medici Demo" --admin_user=demo-admin --admin_email=admin@example.invalid --skip-email --prompt=admin_password
wp plugin activate define-blocks corsivo-core-editor turnimedici-plugin
wp theme activate turnimedici-theme
wp rewrite structure '/%postname%/' --hard
wp eval-file scripts/seed-demo.php
wp rewrite flush --hard
wp eval-file scripts/verify-demo.php
```

`wp-cli.yml` abilita la generazione delle regole Apache. Su Nginx configura la riscrittura WordPress nel virtual host. WP-CLI genera salt nuovi; `wp-config.example.php` documenta la configurazione alternativa tramite variabili d'ambiente e richiede tutti gli otto salt.

Il seed crea un'AFT, due strutture, tre medici, un coordinatore, calendari e turni nel mese corrente di Europe/Rome, oltre a una guida costruita con Define Blocks. Tutti i dati sono sintetici e gli indirizzi dei profili usano `.invalid`. Il seed accetta soltanto un database appena installato oppure oggetti già creati dal seed; dopo il completamento non duplica i contenuti e non sovrascrive le modifiche manuali.

Accedi con l'amministratore scelto durante l'installazione. I profili `medico-demo-uno`, `medico-demo-due`, `medico-demo-tre` e `coordinatore-demo` ricevono password casuali. Per provare un ruolo, impostane una localmente:

```sh
wp user update medico-demo-uno --prompt=user_pass
```

Solo prima del primo seed, `TM_DEMO_PASSWORD` può impostare una password locale per i quattro account demo. Non viene salvata nei sorgenti né stampata dal seed.

## Cosa esplorare

La home autenticata mostra strutture, calendari, assegnazioni e graduatorie. Nell'area Informazioni trovi la guida demo, modificabile nell'editor WordPress. `wp-content/plugins/turnimedici-plugin/includes/blocks.php` definisce i blocchi PHP; `custom-fields/weekly-schedule/src` contiene i campi React. Core Editor legge `wp-content/themes/turnimedici-theme/corsivo-theme.json` per configurare il sistema visivo. Il seed assegna al solo amministratore installatore `manage_corsivo_developer_tools`, necessaria per vedere Impostazioni → Corsivo Core Editor.

La modalità demo è attiva per default e blocca gli invii email e l'integrazione ESTAR. Le graduatorie si importano e analizzano localmente da CSV, senza servizi AI o chiavi API. Per partire da Excel, esporta prima un CSV UTF-8 con queste colonne:

```csv
first_name,last_name,email,phone,qualification,aft,patients,role_start,ca_start,ca_end,ca_rank,ca_zone,ca_cot,district
```

Usa dati fittizi; per l'archivio CA imposta `qualification` a `ca_temporaneo`, usa date `YYYY-MM-DD` e un solo distretto per file. Google Fonts e le mappe, quando presenti, possono caricare risorse esterne.

## Sviluppo

Le build sono incluse. Per ricompilare l'app e i campi personalizzati:

```sh
npm ci --prefix wp-content/plugins/turnimedici-plugin/app
npm run build --prefix wp-content/plugins/turnimedici-plugin/app
```

Per il tema:

```sh
npm ci --prefix wp-content/themes/turnimedici-theme
npm run build --prefix wp-content/themes/turnimedici-theme
```

Define Blocks e Core Editor sono submodule dei repository ufficiali indicati sopra, ai commit bloccati in `dependencies.lock.json`. `git submodule update --init --recursive` scarica sorgenti, build, licenze e documentazione originali: le build sono già presenti nei repository dei plugin. Le loro build di sviluppo usano anche pacchetti `@corsivo-suite` su GitHub Packages: non occorre ricompilarle per installare o modificare questa demo.

## Verifiche

```sh
wp eval-file scripts/verify-demo.php
wp eval-file wp-content/plugins/turnimedici-plugin/tests/smoke.php
wp eval-file wp-content/plugins/turnimedici-plugin/tests/queue-notifications.php --skip-plugins --skip-themes
wp eval-file wp-content/plugins/turnimedici-plugin/tests/data-transfer-roundtrip.php
```

La verifica demo controlla calendari, relazioni, rendering dei blocchi, permessi REST e isolamento delle integrazioni. Gli altri test creano fixture temporanee e le eliminano al termine: eseguili sul database dimostrativo.

## File per la pubblicazione

La `.gitignore` include solo documentazione, script, configurazione di esempio, `.gitmodules`, il plugin applicativo e il tema custom necessario alla demo. Dopo la conversione, i due plugin Corsivo sono registrati nell'indice Git come submodule: il repository conserva i collegamenti ai loro commit, senza duplicarne i sorgenti. Esclude WordPress core, temi standard, `wp-config.php`, file `.env`, credenziali, cartelle locali, upload, log, database, backup, fogli di calcolo, documenti operativi, dipendenze npm e mappe sorgente. Esclude anche gli export `turnimedici-*.json`, che possono contenere dati personali.

La cartella locale può contenere un'installazione completa e `.local/access.txt` con gli accessi di collaudo: non fanno parte dei file da pubblicare. I sorgenti e le fixture non includono anagrafiche pazienti; il campo `patients` rappresenta soltanto il numero di assistiti.

Questa copia non contiene metadati o cronologia Git dell'installazione originale. Le esclusioni valgono per i nuovi file: eventuali file già tracciati in un altro repository richiedono una verifica separata.

## Licenza

Il codice applicativo e il tema sono distribuiti con licenza GPL-2.0-or-later. Le dipendenze e gli asset di terze parti conservano le proprie licenze.

