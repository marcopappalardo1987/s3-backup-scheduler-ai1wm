=== S3 Backup Scheduler for All-in-One WP Migration ===
Contributors: Marco Pappalardo
Plugin URI: https://github.com/marcopappalardo1987/s3-backup-scheduler-ai1wm
Author URI: https://marcopappalardo.it
Requires at least: 5.8
Requires PHP: 7.2
Requires Plugins: all-in-one-wp-migration
Stable tag: 1.1.0
License: GPLv3 or later

Backup pianificati di All-in-One WP Migration direttamente su Amazon S3 (o compatibili) e ripristino dal bucket.

== Funzioni ==

* Pianificazioni multiple (ogni ora / giorno / settimana / mese, all'orario scelto nel fuso del sito).
* Per ogni pianificazione scegli cosa includere (database, media, plugin, temi, ...), quanti backup conservare
  su S3 (rotazione), se tenere anche la copia locale e se cifrare il backup con password.
* "Backup adesso" manuale e "Salva su S3" per i backup locali già presenti in All-in-One WP Migration.
* Elenco dei backup nel bucket con: Ripristina, Scarica (link temporaneo firmato), Elimina, Protetto
  (i backup protetti non vengono mai cancellati dalla rotazione).
* Ripristino: il file viene scaricato da S3 a blocchi e poi importato dal motore di All-in-One WP Migration
  (stesso processo dell'import manuale). Supporta backup cifrati (chiede la password).
* Le impostazioni del plugin (credenziali, pianificazioni, registro) vengono preservate durante il ripristino
  e il plugin viene riattivato automaticamente al termine.
* Rimuove il limite di dimensione dell'import da file nella pagina "Importa" di All-in-One WP Migration.
* Nessun SDK esterno: client S3 con firma SigV4 integrato. Funziona con Amazon S3, Wasabi, Cloudflare R2,
  MinIO, DigitalOcean Spaces, Backblaze B2 (API S3), ecc.

== Installazione ==

1. Carica la cartella `s3-backup-scheduler-ai1wm` in `wp-content/plugins/` (o installa lo zip).
2. Attiva All-in-One WP Migration e poi questo plugin.
3. Menu "Backup S3" > "Impostazioni S3": inserisci credenziali, bucket, regione e premi "Verifica connessione".
4. Crea una pianificazione nella scheda "Pianificazioni".

Credenziali consigliate in wp-config.php:

    define( 'S3BS_ACCESS_KEY', 'AKIA...' );
    define( 'S3BS_SECRET_KEY', '...' );

== Note ==

* Le pianificazioni usano WP-Cron: su siti con poco traffico configura un cron di sistema che richiami
  wp-cron.php ogni 5-15 minuti.
* Come All-in-One WP Migration, il plugin lavora a step tramite richieste HTTP del server verso se stesso
  (loopback): se il server le blocca, backup e ripristini non avanzano.
* Struttura nel bucket: <prefisso>/manual/ per i backup manuali, <prefisso>/sched-<id>/ per ogni pianificazione.
* Consigliata una regola di lifecycle "AbortIncompleteMultipartUpload" sul bucket.
* Filtri disponibili: s3bs_step_time_budget (secondi per step, default 20), s3bs_download_chunk_size,
  s3bs_remove_upload_limit, s3bs_sslverify.

== Aggiornamenti ==

Il plugin si aggiorna da GitHub (https://github.com/marcopappalardo1987/s3-backup-scheduler-ai1wm),
branch main: avviso nella pagina Plugin, "Aggiorna ora" e aggiornamenti automatici di WordPress.
Il controllo avviene ogni 6 ore; per forzarlo usa "Controlla aggiornamenti" sotto il nome del plugin.

Per pubblicare una nuova versione: alza "Version:" in s3-backup-scheduler-ai1wm.php (e "Stable tag"
qui), aggiungi una voce al Changelog e fai push su main.

== Changelog ==

= 1.1.0 =
* Aggiornamenti automatici da GitHub (branch main).
* Endpoint S3: se termina con il nome del bucket (URL "API S3" di Cloudflare R2) viene corretto da solo.
* Pagina Importa di All-in-One WP Migration: messaggio "Nessun limite di dimensione" al posto dell'avviso sul limite dell'host.

= 1.0.0 =
* Prima versione: backup pianificati su S3, rotazione, backup protetti, ripristino dal bucket, import senza limiti.
