<?php
/**
 * Integrazione con la pipeline di All-in-One WP Migration.
 *
 * AI1WM esegue export e import come una catena di "step" registrati sui filtri
 * `ai1wm_export` / `ai1wm_import` con priorità crescenti; ogni step viene eseguito
 * in una richiesta HTTP separata (loopback). Questo plugin aggiunge:
 *
 *  - export, priorità 260 (dopo che AI1WM ha finalizzato il file .wpress e prima della pulizia):
 *    caricamento multipart del backup su S3, a blocchi, riprendibile, + rotazione.
 *  - import, priorità 7 (prima dei controlli di compatibilità):
 *    download del backup da S3 nella cartella di lavoro di AI1WM. Da lì in poi
 *    il ripristino è eseguito interamente da All-in-One WP Migration.
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Pipeline {

	const EXPORT_PRIORITY = 260;
	const IMPORT_PRIORITY = 7;
	const UPLOAD_STATE    = 's3bs-upload.json';
	const MB              = 1048576;

	public static function init() {
		add_filter( 'ai1wm_export', array( __CLASS__, 'export_upload_step' ), self::EXPORT_PRIORITY );
		add_filter( 'ai1wm_import', array( __CLASS__, 'import_download_step' ), self::IMPORT_PRIORITY );

		add_action( 'ai1wm_status_export_error', array( __CLASS__, 'on_export_error' ), 10, 2 );
		add_action( 'ai1wm_status_import_error', array( __CLASS__, 'on_import_error' ), 10, 2 );
	}

	// ------------------------------------------------------------------
	// Avvio dei job
	// ------------------------------------------------------------------

	/**
	 * Avvia un nuovo backup completo che verrà caricato su S3.
	 *
	 * @param array $args options, folder, keep_local, retention, source, encrypt_password
	 * @return string Job id
	 * @throws Exception
	 */
	public static function start_backup( array $args ) {
		self::assert_can_start();

		$args = wp_parse_args(
			$args,
			array(
				'options'          => array(),
				'folder'           => 'manual',
				'keep_local'       => 0,
				'retention'        => 0,
				'source'           => __( 'Manuale', 's3-backup-scheduler-ai1wm' ),
				'encrypt_password' => '',
			)
		);

		$options = array();
		foreach ( array_keys( S3BS_Settings::export_option_labels() ) as $name ) {
			if ( ! empty( $args['options'][ $name ] ) ) {
				$options[ $name ] = 1;
			}
		}

		if ( $args['encrypt_password'] !== '' ) {
			if ( ! function_exists( 'ai1wm_can_encrypt' ) || ! ai1wm_can_encrypt() ) {
				throw new Exception( __( 'Questo server non supporta la cifratura dei backup (estensione OpenSSL mancante).', 's3-backup-scheduler-ai1wm' ) );
			}
			$options['encrypt_backups']  = 1;
			$options['encrypt_password'] = $args['encrypt_password'];
		}

		$job = ai1wm_storage_folder();

		$params = array(
			'secret_key'      => get_option( AI1WM_SECRET_KEY ),
			'storage'         => $job,
			'priority'        => 5,
			'options'         => $options,
			's3bs_upload'     => 1,
			's3bs_folder'     => sanitize_key( $args['folder'] ),
			's3bs_keep_local' => $args['keep_local'] ? 1 : 0,
			's3bs_retention'  => (int) $args['retention'],
		);

		S3BS_History::upsert(
			$job,
			array(
				'type'    => 'backup',
				'source'  => $args['source'],
				'status'  => 'running',
				'message' => __( 'Creazione del backup con All-in-One WP Migration...', 's3-backup-scheduler-ai1wm' ),
			)
		);
		S3BS_History::lock( $job, 'backup' );

		self::loopback( 'export', $params );

		return $job;
	}

	/**
	 * Carica su S3 un backup già presente nella cartella backup di AI1WM.
	 *
	 * @return string Job id
	 * @throws Exception
	 */
	public static function start_upload_local( $archive ) {
		self::assert_can_start();

		$archive = self::find_local_backup( $archive );
		$job     = ai1wm_storage_folder();

		$params = array(
			'secret_key'      => get_option( AI1WM_SECRET_KEY ),
			'storage'         => $job,
			'archive'         => $archive,
			'priority'        => self::EXPORT_PRIORITY,
			's3bs_upload'     => 1,
			's3bs_folder'     => 'manual',
			's3bs_keep_local' => 1,
			's3bs_retention'  => 0,
		);

		S3BS_History::upsert(
			$job,
			array(
				'type'    => 'upload',
				'source'  => __( 'Backup locale', 's3-backup-scheduler-ai1wm' ),
				'archive' => basename( $archive ),
				'status'  => 'running',
				'message' => __( 'Caricamento su S3 in coda...', 's3-backup-scheduler-ai1wm' ),
			)
		);
		S3BS_History::lock( $job, 'upload' );

		self::loopback( 'export', $params );

		return $job;
	}

	/**
	 * Ripristina un backup presente su S3: lo scarica e lo passa all'import di AI1WM.
	 *
	 * @return array job, guard (bool: protezione impostazioni installata)
	 * @throws Exception
	 */
	public static function start_restore( $key, $password = '' ) {
		self::assert_can_start();

		$client = S3BS_S3_Client::from_settings();
		$head   = $client->head_object( $key );
		if ( ! $head ) {
			throw new Exception( __( 'Il backup selezionato non esiste più nel bucket.', 's3-backup-scheduler-ai1wm' ) );
		}
		if ( $head['size'] <= 0 ) {
			throw new Exception( __( 'Il backup selezionato è vuoto.', 's3-backup-scheduler-ai1wm' ) );
		}

		// Spazio su disco: serve il file + lo spazio per estrarlo.
		$free = function_exists( 'ai1wm_disk_free_space' ) ? ai1wm_disk_free_space( AI1WM_STORAGE_PATH ) : false;
		if ( is_numeric( $free ) && $free > 0 ) {
			$needed = $head['size'] * ( defined( 'AI1WM_DISK_SPACE_FACTOR' ) ? AI1WM_DISK_SPACE_FACTOR : 2 ) + ( defined( 'AI1WM_DISK_SPACE_EXTRA' ) ? AI1WM_DISK_SPACE_EXTRA : 300 * self::MB );
			if ( $free < $needed ) {
				throw new Exception(
					sprintf(
						/* translators: 1: spazio necessario, 2: spazio libero */
						__( 'Spazio su disco insufficiente per il ripristino: servono circa %1$s, disponibili %2$s.', 's3-backup-scheduler-ai1wm' ),
						size_format( $needed ),
						size_format( $free )
					)
				);
			}
		}

		$archive = preg_replace( '/[^A-Za-z0-9._-]/', '-', basename( $key ) );
		if ( substr( strtolower( $archive ), -7 ) !== '.wpress' ) {
			$archive .= '.wpress';
		}

		$job = ai1wm_storage_folder();

		$params = array(
			'secret_key'         => get_option( AI1WM_SECRET_KEY ),
			'storage'            => $job,
			'archive'            => $archive,
			'priority'           => self::IMPORT_PRIORITY,
			'ai1wm_confirmed'    => 1,
			's3bs_restore_key'   => $key,
			's3bs_restore_size'  => (int) $head['size'],
		);
		if ( $password !== '' ) {
			$params['decryption_password'] = $password;
		}

		S3BS_History::upsert(
			$job,
			array(
				'type'    => 'restore',
				'source'  => 'S3',
				'archive' => $archive,
				'key'     => $key,
				'size'    => (int) $head['size'],
				'status'  => 'running',
				'message' => __( 'Download del backup da S3...', 's3-backup-scheduler-ai1wm' ),
			)
		);
		S3BS_History::lock( $job, 'restore' );

		// Lo snapshot va preso dopo aver scritto la riga del registro, così sopravvive all'import.
		$guard = S3BS_Guard::prepare( $job );

		self::loopback( 'import', $params );

		return array(
			'job'   => $job,
			'guard' => $guard,
		);
	}

	/**
	 * Riprende un ripristino fermo in attesa (backup cifrato o conferma).
	 *
	 * @throws Exception
	 */
	public static function resume_restore( $job, $password = '' ) {
		$status = get_option( 'ai1wm_status_' . $job, array() );
		$type   = isset( $status['type'] ) ? $status['type'] : '';

		if ( ! in_array( $type, array( 'confirm', 'disk_space_confirm', 'backup_is_encrypted' ), true ) ) {
			throw new Exception( __( 'Il ripristino non è in attesa di conferma.', 's3-backup-scheduler-ai1wm' ) );
		}

		$row     = S3BS_History::get( $job );
		$archive = ! empty( $status['archive'] ) ? $status['archive'] : ( $row ? $row['archive'] : '' );

		$params = array(
			'secret_key'      => get_option( AI1WM_SECRET_KEY ),
			'storage'         => $job,
			'archive'         => $archive,
			'ai1wm_confirmed' => 1,
			'priority'        => ( $type === 'backup_is_encrypted' ) ? 75 : 150,
		);
		if ( $password !== '' ) {
			$params['decryption_password'] = $password;
		}

		self::loopback( 'import', $params );
	}

	/**
	 * Annulla un ripristino in attesa di password/conferma.
	 */
	public static function cancel_restore( $job ) {
		update_option(
			'ai1wm_status_' . $job,
			array(
				'type'    => 'canceled',
				'message' => __( 'Ripristino annullato.', 's3-backup-scheduler-ai1wm' ),
			),
			false
		);

		try {
			Ai1wm_Directory::delete( ai1wm_storage_path( array( 'storage' => $job ) ) );
		} catch ( Exception $e ) {
			// Già rimosso.
		}

		S3BS_History::upsert( $job, array( 'status' => 'error', 'message' => __( 'Ripristino annullato dall\'utente.', 's3-backup-scheduler-ai1wm' ) ) );
		S3BS_History::unlock( $job );
		S3BS_Guard::cleanup();
	}

	// ------------------------------------------------------------------
	// Step della pipeline
	// ------------------------------------------------------------------

	/**
	 * Export, priorità 260: carica su S3 il file appena creato da AI1WM.
	 */
	public static function export_upload_step( $params ) {
		if ( empty( $params['s3bs_upload'] ) ) {
			return $params;
		}

		$job        = $params['storage'];
		$file       = ai1wm_backup_path( $params );
		$state_file = ai1wm_storage_path( $params ) . DIRECTORY_SEPARATOR . self::UPLOAD_STATE;
		$settings   = S3BS_Settings::get();
		$client     = S3BS_S3_Client::from_settings();
		$state      = self::read_json( $state_file );

		if ( ! $state ) {
			clearstatcache( true, $file );
			if ( ! is_file( $file ) ) {
				/* translators: %s: nome del file */
				throw new Exception( sprintf( __( 'File di backup non trovato: %s', 's3-backup-scheduler-ai1wm' ), basename( $file ) ) );
			}

			$size = (int) filesize( $file );
			if ( $size <= 0 ) {
				throw new Exception( __( 'Il file di backup è vuoto.', 's3-backup-scheduler-ai1wm' ) );
			}

			// Max 10.000 parti per upload: la dimensione della parte cresce con il file.
			$part_size = max( (int) $settings['part_size_mb'] * self::MB, 5 * self::MB, (int) ceil( $size / 9000 ) );
			$key       = S3BS_Settings::object_key( $params['s3bs_folder'], $params['archive'] );

			Ai1wm_Status::info( __( 'Connessione ad Amazon S3...', 's3-backup-scheduler-ai1wm' ) );

			$state = array(
				'key'       => $key,
				'size'      => $size,
				'part_size' => $part_size,
				'upload_id' => $client->create_multipart_upload( $key, $settings['storage_class'] ),
				'offset'    => 0,
				'parts'     => array(),
			);
			self::write_json( $state_file, $state );

			S3BS_History::upsert(
				$job,
				array(
					'archive' => basename( $params['archive'] ),
					'key'     => $key,
					'size'    => $size,
					'message' => __( 'Caricamento su S3...', 's3-backup-scheduler-ai1wm' ),
				)
			);
		}

		$deadline = microtime( true ) + (int) apply_filters( 's3bs_step_time_budget', 20 );
		$handle   = fopen( $file, 'rb' );
		if ( ! $handle ) {
			throw new Exception( __( 'Impossibile leggere il file di backup.', 's3-backup-scheduler-ai1wm' ) );
		}

		try {
			do {
				fseek( $handle, $state['offset'] );
				$data = self::read_bytes( $handle, $state['part_size'] );
				if ( $data === '' ) {
					break;
				}

				$number = count( $state['parts'] ) + 1;
				$etag   = self::retry(
					function () use ( $client, $state, $number, $data ) {
						return $client->upload_part( $state['key'], $state['upload_id'], $number, $data );
					}
				);

				$state['parts'][] = array(
					'PartNumber' => $number,
					'ETag'       => $etag,
				);
				$state['offset'] += strlen( $data );
				unset( $data );

				self::write_json( $state_file, $state );

				$percent = (int) floor( $state['offset'] / $state['size'] * 100 );
				Ai1wm_Status::info(
					sprintf(
						/* translators: 1: percentuale, 2: byte caricati, 3: byte totali */
						__( 'Caricamento su Amazon S3...<br />%1$d%% completato (%2$s di %3$s)', 's3-backup-scheduler-ai1wm' ),
						$percent,
						size_format( $state['offset'], 1 ),
						size_format( $state['size'], 1 )
					)
				);
				S3BS_History::upsert( $job, array( 'message' => sprintf( __( 'Caricamento su S3: %d%%', 's3-backup-scheduler-ai1wm' ), $percent ) ) );
			} while ( $state['offset'] < $state['size'] && microtime( true ) < $deadline );
		} finally {
			fclose( $handle );
		}

		if ( $state['offset'] < $state['size'] ) {
			// AI1WM richiamerà questo stesso step in una nuova richiesta.
			$params['completed'] = false;
			return $params;
		}

		Ai1wm_Status::info( __( 'Finalizzazione del caricamento su S3...', 's3-backup-scheduler-ai1wm' ) );
		$client->complete_multipart_upload( $state['key'], $state['upload_id'], $state['parts'] );
		@unlink( $state_file );

		$deleted = array();
		try {
			$deleted = self::apply_retention( $client, $params['s3bs_folder'], (int) $params['s3bs_retention'] );
		} catch ( Exception $e ) {
			S3BS_History::upsert( $job, array( 'warning' => $e->getMessage() ) );
		}

		if ( empty( $params['s3bs_keep_local'] ) && class_exists( 'Ai1wm_Backups' ) ) {
			Ai1wm_Backups::delete_file( $params['archive'] );
			if ( method_exists( 'Ai1wm_Backups', 'delete_label' ) ) {
				Ai1wm_Backups::delete_label( $params['archive'] );
			}
		}

		$message = sprintf(
			/* translators: %s: chiave S3 */
			__( 'Caricato su s3://%s', 's3-backup-scheduler-ai1wm' ),
			S3BS_Settings::get()['bucket'] . '/' . $state['key']
		);
		if ( $deleted ) {
			/* translators: %d: numero di backup rimossi */
			$message .= ' — ' . sprintf( _n( '%d backup vecchio rimosso dalla rotazione', '%d backup vecchi rimossi dalla rotazione', count( $deleted ), 's3-backup-scheduler-ai1wm' ), count( $deleted ) );
		}

		S3BS_History::upsert( $job, array( 'status' => 'ok', 'message' => $message ) );
		S3BS_History::unlock( $job );

		Ai1wm_Status::done( __( 'Backup salvato su Amazon S3', 's3-backup-scheduler-ai1wm' ), esc_html( $message ) );

		do_action( 's3bs_backup_uploaded', $state['key'], $params );

		unset( $params['completed'] );
		return $params;
	}

	/**
	 * Import, priorità 7: scarica da S3 il backup da ripristinare.
	 */
	public static function import_download_step( $params ) {
		if ( empty( $params['s3bs_restore_key'] ) || ! empty( $params['s3bs_downloaded'] ) ) {
			return $params;
		}

		$job    = $params['storage'];
		$key    = $params['s3bs_restore_key'];
		$total  = (int) $params['s3bs_restore_size'];
		$path   = ai1wm_archive_path( $params );
		$tmp    = $path . '.s3bs-part';
		$client = S3BS_S3_Client::from_settings();
		$chunk  = max( 5 * self::MB, (int) apply_filters( 's3bs_download_chunk_size', 64 * self::MB ) );

		clearstatcache( true, $path );
		$offset = is_file( $path ) ? (int) filesize( $path ) : 0;
		if ( $offset > $total ) {
			@unlink( $path );
			$offset = 0;
		}

		$deadline = microtime( true ) + (int) apply_filters( 's3bs_step_time_budget', 20 );

		while ( $offset < $total ) {
			$end = min( $offset + $chunk, $total ) - 1;

			$written = self::retry(
				function () use ( $client, $key, $offset, $end, $tmp ) {
					return $client->download_range_to_file( $key, $offset, $end, $tmp );
				}
			);

			if ( $written !== $end - $offset + 1 ) {
				@unlink( $tmp );
				throw new Exception( __( 'Download da S3 incompleto: dimensione del blocco non corrispondente.', 's3-backup-scheduler-ai1wm' ) );
			}

			self::append_file( $tmp, $path );

			clearstatcache( true, $path );
			$offset  = (int) filesize( $path );
			$percent = (int) floor( $offset / $total * 100 );

			Ai1wm_Status::info(
				sprintf(
					/* translators: 1: percentuale, 2: byte scaricati, 3: byte totali */
					__( 'Download del backup da Amazon S3...<br />%1$d%% completato (%2$s di %3$s)', 's3-backup-scheduler-ai1wm' ),
					$percent,
					size_format( $offset, 1 ),
					size_format( $total, 1 )
				)
			);
			S3BS_History::upsert( $job, array( 'message' => sprintf( __( 'Download da S3: %d%%', 's3-backup-scheduler-ai1wm' ), $percent ) ) );

			if ( microtime( true ) >= $deadline ) {
				break;
			}
		}

		if ( $offset < $total ) {
			$params['completed'] = false;
			return $params;
		}

		$params['s3bs_downloaded'] = 1;
		unset( $params['completed'] );

		Ai1wm_Status::info( __( 'Backup scaricato da S3. All-in-One WP Migration avvia il ripristino...', 's3-backup-scheduler-ai1wm' ) );
		S3BS_History::upsert( $job, array( 'message' => __( 'Download completato, ripristino in corso con All-in-One WP Migration...', 's3-backup-scheduler-ai1wm' ) ) );

		return $params;
	}

	// ------------------------------------------------------------------
	// Errori
	// ------------------------------------------------------------------

	public static function on_export_error( $params, $exception ) {
		if ( empty( $params['s3bs_upload'] ) || empty( $params['storage'] ) ) {
			return;
		}

		$job = $params['storage'];

		try {
			$state = self::read_json( ai1wm_storage_path( $params ) . DIRECTORY_SEPARATOR . self::UPLOAD_STATE );
			if ( $state && ! empty( $state['upload_id'] ) && S3BS_Settings::is_configured() ) {
				S3BS_S3_Client::from_settings()->abort_multipart_upload( $state['key'], $state['upload_id'] );
			}
		} catch ( Exception $e ) {
			// Best effort.
		}

		$message = $exception instanceof Exception ? wp_strip_all_tags( $exception->getMessage() ) : __( 'Errore sconosciuto', 's3-backup-scheduler-ai1wm' );
		if ( (int) $params['priority'] >= self::EXPORT_PRIORITY && ! empty( $params['archive'] ) ) {
			$message .= ' — ' . __( 'Il file di backup è rimasto nella cartella locale di All-in-One WP Migration.', 's3-backup-scheduler-ai1wm' );
		}

		S3BS_History::upsert( $job, array( 'status' => 'error', 'message' => $message ) );
		S3BS_History::unlock( $job );
	}

	public static function on_import_error( $params, $exception ) {
		if ( empty( $params['s3bs_restore_key'] ) && empty( $params['s3bs_downloaded'] ) ) {
			$row = ! empty( $params['storage'] ) ? S3BS_History::get( $params['storage'] ) : null;
			if ( ! $row || $row['type'] !== 'restore' ) {
				return;
			}
		}

		$job     = $params['storage'];
		$message = $exception instanceof Exception ? wp_strip_all_tags( $exception->getMessage() ) : __( 'Errore sconosciuto', 's3-backup-scheduler-ai1wm' );

		S3BS_History::upsert( $job, array( 'status' => 'error', 'message' => $message ) );
		S3BS_History::unlock( $job );

		// Prima della sostituzione del database le impostazioni sono intatte: la protezione non serve più.
		if ( (int) $params['priority'] < 300 ) {
			S3BS_Guard::cleanup();
		}
	}

	// ------------------------------------------------------------------
	// Rotazione
	// ------------------------------------------------------------------

	/**
	 * Mantiene solo gli ultimi $keep backup della cartella (quelli protetti non contano e non vengono toccati).
	 *
	 * @return string[] Chiavi eliminate
	 */
	public static function apply_retention( S3BS_S3_Client $client, $folder, $keep ) {
		if ( $keep <= 0 || $folder === '' ) {
			return array();
		}

		$prefix  = S3BS_Settings::get()['prefix'] . $folder . '/';
		$objects = array_filter(
			$client->list_objects( $prefix ),
			function ( $object ) {
				return substr( strtolower( $object['key'] ), -7 ) === '.wpress';
			}
		);

		usort(
			$objects,
			function ( $a, $b ) {
				return $b['last_modified'] - $a['last_modified'];
			}
		);

		$protected = S3BS_Settings::get_protected();
		$kept      = 0;
		$deleted   = array();

		foreach ( $objects as $object ) {
			if ( isset( $protected[ $object['key'] ] ) ) {
				continue;
			}
			$kept++;
			if ( $kept > $keep ) {
				$client->delete_object( $object['key'] );
				$deleted[] = $object['key'];
			}
		}

		return $deleted;
	}

	// ------------------------------------------------------------------
	// Utility
	// ------------------------------------------------------------------

	/**
	 * @throws Exception
	 */
	private static function assert_can_start() {
		if ( ! S3BS_Settings::is_configured() ) {
			throw new Exception( __( 'Configura prima la connessione ad Amazon S3.', 's3-backup-scheduler-ai1wm' ) );
		}

		$running = S3BS_History::running();
		if ( $running ) {
			throw new Exception( __( 'C\'è già un\'operazione in corso. Attendi che termini prima di avviarne un\'altra.', 's3-backup-scheduler-ai1wm' ) );
		}
	}

	/**
	 * @return string Nome del backup locale validato
	 * @throws Exception
	 */
	public static function find_local_backup( $archive ) {
		foreach ( Ai1wm_Backups::get_files() as $file ) {
			if ( $file['filename'] === $archive ) {
				return $file['filename'];
			}
		}

		throw new Exception( __( 'Backup locale non trovato.', 's3-backup-scheduler-ai1wm' ) );
	}

	/**
	 * Avvia uno step di AI1WM in una richiesta separata, esattamente come fa AI1WM
	 * (stessi filtri, così l'eventuale autenticazione HTTP configurata in AI1WM viene rispettata).
	 */
	public static function loopback( $type, array $params ) {
		$url = add_query_arg( array( 'ai1wm_import' => 1 ), admin_url( 'admin-ajax.php?action=ai1wm_' . $type ) );

		wp_remote_request(
			apply_filters( "ai1wm_http_{$type}_url", $url ),
			array(
				'method'    => apply_filters( "ai1wm_http_{$type}_method", 'POST' ),
				'timeout'   => apply_filters( "ai1wm_http_{$type}_timeout", 10 ),
				'blocking'  => apply_filters( "ai1wm_http_{$type}_blocking", false ),
				'sslverify' => apply_filters( "ai1wm_http_{$type}_sslverify", false ),
				'headers'   => apply_filters( "ai1wm_http_{$type}_headers", array() ),
				'body'      => apply_filters( "ai1wm_http_{$type}_body", $params ),
			)
		);
	}

	private static function retry( $callback, $attempts = 3 ) {
		$last = null;
		for ( $i = 1; $i <= $attempts; $i++ ) {
			try {
				return call_user_func( $callback );
			} catch ( S3BS_S3_Exception $e ) {
				$last = $e;
				// Gli errori di autorizzazione non si risolvono riprovando.
				if ( in_array( $e->getCode(), array( 400, 401, 403, 404 ), true ) ) {
					break;
				}
				sleep( $i * 2 );
			}
		}
		throw $last;
	}

	private static function read_bytes( $handle, $length ) {
		$data = '';
		while ( strlen( $data ) < $length && ! feof( $handle ) ) {
			$chunk = fread( $handle, $length - strlen( $data ) );
			if ( $chunk === false || $chunk === '' ) {
				break;
			}
			$data .= $chunk;
		}
		return $data;
	}

	private static function append_file( $source, $target ) {
		$in  = fopen( $source, 'rb' );
		$out = fopen( $target, 'ab' );
		if ( ! $in || ! $out ) {
			throw new Exception( __( 'Impossibile scrivere il backup scaricato nella cartella di lavoro di All-in-One WP Migration.', 's3-backup-scheduler-ai1wm' ) );
		}
		stream_copy_to_stream( $in, $out );
		fclose( $in );
		fclose( $out );
		@unlink( $source );
	}

	private static function read_json( $path ) {
		if ( ! is_file( $path ) ) {
			return null;
		}
		$data = json_decode( (string) file_get_contents( $path ), true );
		return is_array( $data ) ? $data : null;
	}

	private static function write_json( $path, $data ) {
		if ( file_put_contents( $path, wp_json_encode( $data ), LOCK_EX ) === false ) {
			throw new Exception( __( 'Impossibile salvare lo stato del caricamento.', 's3-backup-scheduler-ai1wm' ) );
		}
	}
}
