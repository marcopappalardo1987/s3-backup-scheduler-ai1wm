<?php
/**
 * Rimuove il limite di dimensione dell'import da file di All-in-One WP Migration.
 *
 * In AI1WM gratuito il limite è doppio:
 *  1. nel browser la pagina "Importa" rifiuta i file più grandi di `ai1wm_uploader.max_file_size`
 *     (= upload_max_filesize/post_max_size del server) e mostra l'invito all'acquisto;
 *  2. il file viene poi inviato in un'unica richiesta POST, quindi PHP lo scarta se supera
 *     post_max_size / upload_max_filesize.
 *
 * Questo modulo alza il controllo nel browser e sostituisce la singola POST con un caricamento
 * a blocchi (ogni blocco è più piccolo dei limiti PHP) verso un endpoint che ricompone il file
 * nella cartella di lavoro di AI1WM. Terminato il caricamento, l'import prosegue esattamente come
 * l'import standard di All-in-One WP Migration. Il limite della libreria media non viene toccato.
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Unlimited {

	const NONCE = 's3bs_chunk_upload';

	public static function init() {
		if ( ! self::enabled() ) {
			return;
		}

		// AI1WM registra lo script di import su admin_enqueue_scripts a priorità 5.
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ), 9999 );
		add_action( 'wp_ajax_s3bs_upload_chunk', array( __CLASS__, 'ajax_upload_chunk' ) );

		// Sostituisce il messaggio "Il tuo host limita i caricamenti a X MB" sotto l'area di import.
		add_filter( 'ai1wm_pro', array( __CLASS__, 'import_notice' ), 20 );
	}

	public static function import_notice() {
		return sprintf(
			/* translators: %s: dimensione dei blocchi */
			__( '<strong>Nessun limite di dimensione</strong>: il file viene caricato a blocchi da %s (S3 Backup Scheduler).', 's3-backup-scheduler-ai1wm' ),
			esc_html( size_format( self::chunk_size(), 1 ) )
		);
	}

	public static function enabled() {
		return (bool) apply_filters( 's3bs_remove_upload_limit', ! empty( S3BS_Settings::get()['remove_upload_limit'] ) );
	}

	public static function can_import() {
		return ( current_user_can( 'ai1wm_import_site' ) || current_user_can( 'manage_options' ) ) && ( ! is_multisite() || is_super_admin() );
	}

	/**
	 * Dimensione di ogni blocco: sotto i limiti di PHP, al massimo 5 MB.
	 */
	public static function chunk_size() {
		$limit = (int) wp_max_upload_size();
		if ( $limit <= 0 ) {
			$limit = 2 * MB_IN_BYTES;
		}
		$size = min( 5 * MB_IN_BYTES, (int) floor( $limit * 0.9 ) );
		return (int) apply_filters( 's3bs_import_chunk_size', max( 64 * KB_IN_BYTES, $size ) );
	}

	public static function enqueue() {
		if ( ! wp_script_is( 'ai1wm_import', 'registered' ) || ! self::can_import() ) {
			return;
		}

		// 1) Alza il controllo di dimensione nel browser.
		wp_add_inline_script(
			'ai1wm_import',
			'if (typeof window.ai1wm_uploader === "object" && window.ai1wm_uploader) { window.ai1wm_uploader.max_file_size = Number.MAX_SAFE_INTEGER; }',
			'before'
		);

		// 2) Caricamento a blocchi.
		wp_register_script( 's3bs-chunk-upload', S3BS_URL . 'assets/chunk-upload.js', array( 'jquery', 'ai1wm_import' ), S3BS_VERSION, false );
		wp_localize_script(
			's3bs-chunk-upload',
			's3bsChunk',
			array(
				'url'      => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( self::NONCE ),
				'chunk'    => self::chunk_size(),
				'failed'   => __( 'Caricamento del file non riuscito.', 's3-backup-scheduler-ai1wm' ),
			)
		);

		if ( wp_script_is( 'ai1wm_import', 'enqueued' ) ) {
			wp_enqueue_script( 's3bs-chunk-upload' );
		} else {
			// AI1WM accoda lo script dopo (durante il rendering della pagina): ci agganciamo lì.
			add_action(
				'admin_print_footer_scripts',
				function () {
					if ( wp_script_is( 'ai1wm_import', 'done' ) && ! wp_script_is( 's3bs-chunk-upload', 'done' ) ) {
						wp_print_scripts( 's3bs-chunk-upload' );
					}
				},
				1
			);
		}
	}

	/**
	 * Riceve un blocco del file .wpress e lo accoda nella cartella di lavoro di AI1WM.
	 */
	public static function ajax_upload_chunk() {
		if ( ! self::can_import() ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 's3-backup-scheduler-ai1wm' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta: ricarica la pagina.', 's3-backup-scheduler-ai1wm' ) ), 403 );
		}

		ai1wm_setup_environment();

		$storage = isset( $_POST['storage'] ) ? (string) wp_unslash( $_POST['storage'] ) : '';
		$archive = isset( $_POST['archive'] ) ? (string) wp_unslash( $_POST['archive'] ) : '';
		$offset  = isset( $_POST['offset'] ) ? (int) $_POST['offset'] : -1;

		if ( ! preg_match( '/^[A-Za-z0-9]{1,64}$/', $storage ) ) {
			wp_send_json_error( array( 'message' => __( 'Cartella di lavoro non valida.', 's3-backup-scheduler-ai1wm' ) ) );
		}
		if ( $archive === '' || basename( str_replace( '\\', '/', $archive ) ) !== $archive || ai1wm_validate_file( $archive ) !== 0 || ! ai1wm_is_filename_supported( $archive ) ) {
			wp_send_json_error( array( 'message' => __( 'Tipo di file non valido: è richiesto un backup .wpress.', 's3-backup-scheduler-ai1wm' ) ) );
		}
		if ( empty( $_FILES['chunk']['tmp_name'] ) || (int) $_FILES['chunk']['error'] !== UPLOAD_ERR_OK ) {
			/* translators: %d: codice errore upload PHP */
			wp_send_json_error( array( 'message' => sprintf( __( 'Blocco non ricevuto dal server (codice %d).', 's3-backup-scheduler-ai1wm' ), isset( $_FILES['chunk']['error'] ) ? (int) $_FILES['chunk']['error'] : -1 ) ) );
		}

		try {
			$path = ai1wm_archive_path(
				array(
					'storage' => $storage,
					'archive' => $archive,
				)
			);

			clearstatcache( true, $path );
			$current = is_file( $path ) ? (int) filesize( $path ) : 0;

			if ( $offset !== 0 && $offset !== $current ) {
				throw new Exception( __( 'Blocchi ricevuti fuori sequenza. Riprova l\'import.', 's3-backup-scheduler-ai1wm' ) );
			}

			$in  = fopen( $_FILES['chunk']['tmp_name'], 'rb' );
			$out = fopen( $path, $offset === 0 ? 'wb' : 'ab' );
			if ( ! $in || ! $out ) {
				throw new Exception( __( 'Impossibile scrivere nella cartella di lavoro di All-in-One WP Migration.', 's3-backup-scheduler-ai1wm' ) );
			}
			stream_copy_to_stream( $in, $out );
			fclose( $in );
			fclose( $out );
			@unlink( $_FILES['chunk']['tmp_name'] );

			// Il primo blocco deve iniziare con l'intestazione di un archivio AI1WM.
			if ( $offset === 0 && ! ai1wm_is_filedata_supported( $path ) ) {
				@unlink( $path );
				throw new Exception( __( 'Il file non è un backup valido di All-in-One WP Migration.', 's3-backup-scheduler-ai1wm' ) );
			}

			clearstatcache( true, $path );
			wp_send_json_success( array( 'size' => (int) filesize( $path ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => wp_strip_all_tags( $e->getMessage() ) ) );
		}
	}
}
