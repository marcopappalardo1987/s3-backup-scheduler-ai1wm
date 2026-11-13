# S3 Backup Scheduler for All-in-One WP Migration

Plugin WordPress di [Marco Pappalardo](https://marcopappalardo.it) che estende **All-in-One WP Migration** (7.100+):

- **Backup pianificati su S3**: ogni ora / giorno / settimana / mese, con scelta del contenuto, rotazione, copia locale opzionale e cifratura.
- **Scelta dei backup da conservare**: backup protetti esclusi dalla rotazione, elimina/scarica dal bucket.
- **Ripristino dal bucket**: scegli il backup dall'elenco; viene scaricato a blocchi e importato dal motore di All-in-One WP Migration.
- **Import senza limiti di dimensione** nella pagina "Importa" di All-in-One WP Migration (caricamento a blocchi).
- Compatibile con Amazon S3, Cloudflare R2, Wasabi, MinIO, DigitalOcean Spaces, Backblaze B2 (API S3). Nessun SDK esterno.

## Installazione

1. Scarica lo zip: [main.zip](https://github.com/marcopappalardo1987/s3-backup-scheduler-ai1wm/archive/refs/heads/main.zip).
2. WordPress → Plugin → Aggiungi nuovo → Carica plugin → seleziona lo zip → Attiva.
3. Menu **Backup S3** → **Impostazioni S3**: credenziali, bucket, regione/endpoint → **Verifica connessione**.

### Cloudflare R2

| Campo | Valore |
|---|---|
| Regione | `auto` |
| Endpoint | `https://<ID account>.r2.cloudflarestorage.com` (senza il nome del bucket) |
| Path-style | attivo |

Credenziali consigliate in `wp-config.php`:

```php
define( 'S3BS_ACCESS_KEY', '...' );
define( 'S3BS_SECRET_KEY', '...' );
```

## Aggiornamenti

Il plugin si aggiorna da questo repository, **branch `main`**: WordPress mostra l'avviso nella pagina Plugin e supporta "Aggiorna ora" e gli aggiornamenti automatici.

Per pubblicare una nuova versione:

1. alza `Version:` in `s3-backup-scheduler-ai1wm.php` (e `Stable tag` in `readme.txt`);
2. aggiungi la voce nel `== Changelog ==` di `readme.txt`;
3. push su `main`.

I siti controllano ogni 6 ore; "Controlla aggiornamenti" sotto il nome del plugin forza il controllo.

## Licenza

GPLv3 o successiva.
