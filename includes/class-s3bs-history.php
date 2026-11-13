<?php
/**
 * Registro delle operazioni (backup, caricamenti, ripristini) e lock anti-sovrapposizione.
 */

defined( 'ABSPATH' ) || exit;

class S3BS_History {

	const OPTION   = 's3bs_history';
	const LOCK     = 's3bs_running_job';
	const MAX_ROWS = 100;

	public static function all() {
		$rows = get_option( self::OPTION, array() );
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Crea o aggiorna la riga del job.
	 */
	public static function upsert( $job_id, array $data ) {
		$rows = self::all();

		if ( isset( $rows[ $job_id ] ) ) {
			$rows[ $job_id ] = array_merge( $rows[ $job_id ], $data, array( 'updated' => time() ) );
		} else {
			$rows[ $job_id ] = array_merge(
				array(
					'job'     => $job_id,
					'type'    => 'backup',
					'source'  => '',
					'archive' => '',
					'key'     => '',
					'size'    => 0,
					'status'  => 'running',
					'message' => '',
					'started' => time(),
					'updated' => time(),
				),
				$data
			);
		}

		uasort(
			$rows,
			function ( $a, $b ) {
				return (int) $b['started'] - (int) $a['started'];
			}
		);
		$rows = array_slice( $rows, 0, self::MAX_ROWS, true );

		update_option( self::OPTION, $rows, false );
	}

	public static function get( $job_id ) {
		$rows = self::all();
		return isset( $rows[ $job_id ] ) ? $rows[ $job_id ] : null;
	}

	public static function clear() {
		update_option( self::OPTION, array(), false );
	}

	// ------------------------------------------------------------------
	// Lock: un solo backup/caricamento verso S3 alla volta
	// ------------------------------------------------------------------

	/**
	 * @return array|null job, type, since
	 */
	public static function running() {
		self::close_orphans();

		$lock = get_option( self::LOCK );
		if ( ! is_array( $lock ) || empty( $lock['job'] ) ) {
			return null;
		}

		// Lock scaduto (processo morto): dopo 12 ore senza aggiornamenti lo consideriamo libero.
		$row     = self::get( $lock['job'] );
		$touched = $row ? (int) $row['updated'] : (int) $lock['since'];
		if ( time() - $touched > 12 * HOUR_IN_SECONDS ) {
			if ( $row && $row['status'] === 'running' ) {
				self::upsert( $lock['job'], array( 'status' => 'error', 'message' => __( 'Il processo si è interrotto senza completare (timeout).', 's3-backup-scheduler-ai1wm' ) ) );
			}
			delete_option( self::LOCK );
			return null;
		}

		if ( $row && $row['status'] !== 'running' ) {
			delete_option( self::LOCK );
			return null;
		}

		// Ogni job attivo ha la sua cartella di lavoro in AI1WM. Se non esiste (oltre i primi
		// secondi di avvio) il lock è un residuo: ad es. ripristinando un backup il database
		// riporta il lock che era attivo nel momento in cui quel backup è stato creato.
		if ( defined( 'AI1WM_STORAGE_PATH' ) && time() - (int) $lock['since'] > 2 * MINUTE_IN_SECONDS
			&& ! is_dir( AI1WM_STORAGE_PATH . DIRECTORY_SEPARATOR . basename( $lock['job'] ) ) ) {
			if ( $row && $row['status'] === 'running' ) {
				self::upsert( $lock['job'], array( 'status' => 'error', 'message' => __( 'Operazione non più in esecuzione (stato non aggiornato, ad es. dopo un ripristino).', 's3-backup-scheduler-ai1wm' ) ) );
			}
			delete_option( self::LOCK );
			return null;
		}

		return $lock;
	}

	/**
	 * Chiude le righe "in corso" che non hanno più un processo attivo (cartella di lavoro assente).
	 */
	private static function close_orphans() {
		if ( ! defined( 'AI1WM_STORAGE_PATH' ) ) {
			return;
		}

		$lock = get_option( self::LOCK );
		foreach ( self::all() as $job_id => $row ) {
			if ( $row['status'] !== 'running' || ( is_array( $lock ) && isset( $lock['job'] ) && $lock['job'] === $job_id ) ) {
				continue;
			}
			if ( time() - (int) $row['updated'] > 2 * MINUTE_IN_SECONDS && ! is_dir( AI1WM_STORAGE_PATH . DIRECTORY_SEPARATOR . basename( $job_id ) ) ) {
				self::upsert( $job_id, array( 'status' => 'error', 'message' => __( 'Operazione non più in esecuzione (stato non aggiornato, ad es. dopo un ripristino).', 's3-backup-scheduler-ai1wm' ) ) );
			}
		}
	}

	public static function lock( $job_id, $type ) {
		update_option(
			self::LOCK,
			array(
				'job'   => $job_id,
				'type'  => $type,
				'since' => time(),
			),
			false
		);
	}

	public static function unlock( $job_id = null ) {
		$lock = get_option( self::LOCK );
		if ( $job_id === null || ( is_array( $lock ) && isset( $lock['job'] ) && $lock['job'] === $job_id ) ) {
			delete_option( self::LOCK );
		}
	}
}
