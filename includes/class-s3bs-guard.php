<?php
/**
 * Protezione delle impostazioni durante un ripristino.
 *
 * Quando All-in-One WP Migration importa il database, la tabella wp_options viene
 * sostituita con quella del backup e tutti i plugin vengono disattivati (tranne
 * quelli di ServMask). Per non perdere credenziali S3, pianificazioni e registro,
 * prima del ripristino:
 *   1. salviamo uno snapshot delle nostre opzioni nella cartella backup di AI1WM
 *      (che l'import non tocca), protetto da un `exit` PHP;
 *   2. installiamo un mu-plugin temporaneo che, alla fine dell'import (priorità 345,
 *      prima dello step "Done" di AI1WM), ripristina le opzioni, riattiva questo
 *      plugin e poi si autoelimina.
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Guard {

	const STATE_FILE = 's3bs-restore-state.php';
	const MU_FILE    = 's3bs-restore-guard.php';
	const HEADER     = "<?php exit; ?>\n";

	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'maybe_cleanup' ) );
	}

	public static function state_path() {
		return AI1WM_BACKUPS_PATH . DIRECTORY_SEPARATOR . self::STATE_FILE;
	}

	public static function mu_path() {
		return WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . self::MU_FILE;
	}

	/**
	 * @return bool true se la protezione è installata
	 */
	public static function prepare( $job ) {
		$options = array();
		foreach ( S3BS_Settings::all_option_names() as $name ) {
			$options[ $name ] = get_option( $name, null );
		}

		$state = array(
			'job'      => $job,
			'created'  => time(),
			'basename' => S3BS_BASENAME,
			'options'  => $options,
		);

		if ( ! is_dir( AI1WM_BACKUPS_PATH ) ) {
			wp_mkdir_p( AI1WM_BACKUPS_PATH );
		}

		if ( @file_put_contents( self::state_path(), self::HEADER . wp_json_encode( $state ), LOCK_EX ) === false ) {
			return false;
		}

		if ( ! is_dir( WPMU_PLUGIN_DIR ) && ! wp_mkdir_p( WPMU_PLUGIN_DIR ) ) {
			return false;
		}

		return (bool) @copy( S3BS_DIR . 'includes/mu-guard-template.tpl', self::mu_path() );
	}

	public static function read_state() {
		$path = self::state_path();
		if ( ! is_file( $path ) ) {
			return null;
		}
		$raw  = (string) file_get_contents( $path );
		$data = json_decode( substr( $raw, strlen( self::HEADER ) ), true );
		return is_array( $data ) ? $data : null;
	}

	public static function cleanup() {
		if ( is_file( self::state_path() ) ) {
			@unlink( self::state_path() );
		}
		if ( is_file( self::mu_path() ) ) {
			@unlink( self::mu_path() );
		}
	}

	/**
	 * Rimuove i residui di ripristini falliti o interrotti. Se le nostre impostazioni
	 * sono andate perse (es. import interrotto a metà), le recupera dallo snapshot.
	 */
	public static function maybe_cleanup() {
		$state = self::read_state();

		if ( ! $state ) {
			if ( is_file( self::mu_path() ) ) {
				@unlink( self::mu_path() );
			}
			return;
		}

		// Ripristino probabilmente ancora in corso.
		if ( time() - (int) $state['created'] < 6 * HOUR_IN_SECONDS ) {
			return;
		}

		$current = get_option( S3BS_Settings::OPTION );
		if ( empty( $current ) && ! empty( $state['options'] ) ) {
			foreach ( $state['options'] as $name => $value ) {
				if ( in_array( $name, S3BS_Settings::all_option_names(), true ) && $value !== null ) {
					update_option( $name, $value, false );
				}
			}
		}

		self::cleanup();
	}
}
