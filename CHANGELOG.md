# Changelog

## 2026-11-01

- Preparate la configurazione e la procedura di conversione di Define Blocks e Core Editor in submodule dei repository ufficiali, mantenendo gli stessi commit verificati. Aggiornate le istruzioni di conversione, clonazione e inizializzazione.

- Rimosse la conversione remota dei fogli Excel, la configurazione delle relative chiavi e le istruzioni AI. L'archivio graduatorie usa CSV analizzati localmente.
- Verificata l'identità dei 298 file delle dipendenze con i commit dei repository ufficiali Define Blocks e Core Editor registrati in `dependencies.lock.json`.
- Rafforzata la `.gitignore` per credenziali, dati operativi, export JSON, backup e cartelle locali; mantenuti plugin e tema custom.
- Aggiornate documentazione, build e verifiche della demo.

## 2026-10-19

- Preparata una demo autonoma con il plugin Turni Medici, il tema custom e gli snapshot ufficiali Define Blocks 1.1.0 (`b80276c`) e Core Editor 2.1.2 (`7715a74`).
- Introdotte modalità demo predefinita, sospensione delle email e isolamento delle integrazioni esterne.
- Aggiunti seed sintetico ripetibile, verifica demo e test con fixture temporanee.
- Configurati tema, campo editoriale settimanale, dashboard coordinatore e isolamento della PWA.
- Esclusi dall'insieme pubblicabile dati reali, credenziali, WordPress core e artefatti locali.

