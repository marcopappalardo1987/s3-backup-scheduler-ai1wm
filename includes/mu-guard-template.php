<?php
/**
 * Plugin Name: S3 Backup Scheduler - Protezione ripristino (temporaneo)
 * Description: Installato automaticamente durante un ripristino da S3. Ripristina le impostazioni di S3 Backup Scheduler e riattiva il plugin alla fine dell'import, poi si elimina da solo.
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 's3bs_guard_state_path' ) ) {

	function s3bs_guard_state_path() {
		$dir = defined( 'AI1WM_BACKUPS_PATH' ) ? AI1WM_BACKUPS_PATH : WP_CONTENT_DIR . DIRECTORY_SEPARATOR . 'ai1wm-backups';
		return $dir . DIRECTORY_SEPARATOR . 's3bs-restore-state.php';
	}

	function s3bs_guard_read_state() {
		$path = s3bs_guard_state_path();
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw    = (string) file_get_contents( $path );
		$header = "<?php exit; ?>\n";
		$data   = json_decode( substr( $raw, strlen( $header ) ), true );
		return is_array( $data ) ? $data : null;
	}

	function s3bs_guard_remove() {
		@unlink( s3bs_guard_state_path() );
		@unlink( __FILE__ );
	}

	/**
	 * Step di import di AI1WM a priorità 345 (prima di "Done", dopo database, utenti e opzioni).
	 */
	function s3bs_guard_finalize( $params ) {
		$state = s3bs_guard_read_state();
		if ( ! $state || empty( $params['storage'] ) || $params['storage'] !== $state['job'] ) {
			return $params;
		}

		$allowed = array( 's3bs_settings', 's3bs_schedules', 's3bs_protected', 's3bs_history' );

		if ( ! empty( $state['options'] ) && is_array( $state['options'] ) ) {
			foreach ( $state['options'] as $name => $value ) {
				if ( ! in_array( $name, $allowed, true ) ) {
					continue;
				}
				if ( $value === null ) {
					delete_option( $name );
				} else {
					update_option( $name, $value, false );
				}
			}
		}

		// Segna il ripristino come completato nel registro.
		$history = get_option( 's3bs_history', array() );
		if ( is_array( $history ) && isset( $history[ $state['job'] ] ) ) {
			$history[ $state['job'] ]['status']  = 'ok';
			$history[ $state['job'] ]['message'] = 'Ripristino completato da All-in-One WP Migration.';
			$history[ $state['job'] ]['updated'] = time();
			update_option( 's3bs_history', $history, false );
		}
		delete_option( 's3bs_running_job' );

		// Riattiva S3 Backup Scheduler (AI1WM lo ha disattivato durante l'import del database).
		if ( ! empty( $state['basename'] ) && function_exists( 'ai1wm_activate_plugins' ) ) {
			ai1wm_activate_plugins( array( $state['basename'] ) );
		}

		s3bs_guard_remove();

		return $params;
	}

	add_filter( 'ai1wm_import', 's3bs_guard_finalize', 345 );

	// Autopulizia: se lo snapshot non esiste più o è vecchio di oltre 2 giorni.
	add_action(
		'init',
		function () {
			$state = s3bs_guard_read_state();
			if ( ! $state || time() - (int) $state['created'] > 2 * DAY_IN_SECONDS ) {
				s3bs_guard_remove();
			}
		}
	);
}
