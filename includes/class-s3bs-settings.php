<?php
/**
 * Impostazioni del plugin e delle pianificazioni.
 *
 * Le credenziali possono anche essere definite in wp-config.php, così non finiscono nel database:
 *   define( 'S3BS_ACCESS_KEY', '...' );
 *   define( 'S3BS_SECRET_KEY', '...' );
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Settings {

	const OPTION           = 's3bs_settings';
	const SCHEDULES_OPTION = 's3bs_schedules';
	const PROTECTED_OPTION = 's3bs_protected';

	/** Opzioni di export di All-in-One WP Migration che l'utente può scegliere. */
	public static function export_option_labels() {
		return array(
			'no_database'         => __( 'Escludi il database', 's3-backup-scheduler-ai1wm' ),
			'no_media'            => __( 'Escludi la libreria media (uploads)', 's3-backup-scheduler-ai1wm' ),
			'no_plugins'          => __( 'Escludi i plugin', 's3-backup-scheduler-ai1wm' ),
			'no_inactive_plugins' => __( 'Escludi i plugin non attivi', 's3-backup-scheduler-ai1wm' ),
			'no_themes'           => __( 'Escludi i temi', 's3-backup-scheduler-ai1wm' ),
			'no_inactive_themes'  => __( 'Escludi i temi non attivi', 's3-backup-scheduler-ai1wm' ),
			'no_muplugins'        => __( 'Escludi i must-use plugin', 's3-backup-scheduler-ai1wm' ),
			'no_cache'            => __( 'Escludi i file di cache', 's3-backup-scheduler-ai1wm' ),
			'no_spam_comments'    => __( 'Escludi i commenti spam', 's3-backup-scheduler-ai1wm' ),
			'no_post_revisions'   => __( 'Escludi le revisioni dei post', 's3-backup-scheduler-ai1wm' ),
			'no_email_replace'    => __( 'Non sostituire i domini nelle email', 's3-backup-scheduler-ai1wm' ),
		);
	}

	public static function storage_classes() {
		return array(
			'STANDARD'            => 'Standard',
			'STANDARD_IA'         => 'Standard-IA',
			'ONEZONE_IA'          => 'One Zone-IA',
			'INTELLIGENT_TIERING' => 'Intelligent-Tiering',
			'GLACIER_IR'          => 'Glacier Instant Retrieval',
		);
	}

	public static function defaults() {
		return array(
			'access_key'          => '',
			'secret_key'          => '',
			'region'              => 'eu-south-1',
			'bucket'              => '',
			'prefix'              => 'wp-backups/',
			'endpoint'            => '',
			'path_style'          => 0,
			'storage_class'       => 'STANDARD',
			'part_size_mb'        => 16,
			'remove_upload_limit' => 1,
		);
	}

	/**
	 * Impostazioni effettive (i valori di wp-config.php hanno la precedenza).
	 */
	public static function get() {
		$settings = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		if ( defined( 'S3BS_ACCESS_KEY' ) && S3BS_ACCESS_KEY ) {
			$settings['access_key'] = S3BS_ACCESS_KEY;
		}
		if ( defined( 'S3BS_SECRET_KEY' ) && S3BS_SECRET_KEY ) {
			$settings['secret_key'] = S3BS_SECRET_KEY;
		}

		$settings['prefix'] = self::normalize_prefix( $settings['prefix'] );

		return $settings;
	}

	public static function is_configured() {
		$s = self::get();
		return $s['access_key'] !== '' && $s['secret_key'] !== '' && $s['bucket'] !== '';
	}

	public static function save( array $input ) {
		$current = wp_parse_args( (array) get_option( self::OPTION, array() ), self::defaults() );

		$new = array(
			'access_key'          => sanitize_text_field( isset( $input['access_key'] ) ? $input['access_key'] : '' ),
			'region'              => sanitize_text_field( isset( $input['region'] ) ? $input['region'] : '' ),
			'bucket'              => sanitize_text_field( isset( $input['bucket'] ) ? $input['bucket'] : '' ),
			'prefix'              => self::normalize_prefix( isset( $input['prefix'] ) ? $input['prefix'] : '' ),
			'endpoint'            => esc_url_raw( isset( $input['endpoint'] ) ? trim( $input['endpoint'] ) : '' ),
			'path_style'          => empty( $input['path_style'] ) ? 0 : 1,
			'storage_class'       => ( isset( $input['storage_class'] ) && array_key_exists( $input['storage_class'], self::storage_classes() ) ) ? $input['storage_class'] : 'STANDARD',
			'part_size_mb'        => max( 5, min( 512, (int) ( isset( $input['part_size_mb'] ) ? $input['part_size_mb'] : 16 ) ) ),
			'remove_upload_limit' => empty( $input['remove_upload_limit'] ) ? 0 : 1,
		);

		// La secret key non viene mai rimandata al browser: se il campo è vuoto si mantiene quella salvata.
		$secret            = isset( $input['secret_key'] ) ? trim( (string) $input['secret_key'] ) : '';
		$new['secret_key'] = $secret !== '' ? $secret : $current['secret_key'];

		update_option( self::OPTION, $new, false );
	}

	public static function normalize_prefix( $prefix ) {
		$prefix = trim( str_replace( '\\', '/', (string) $prefix ) );
		$prefix = preg_replace( '#/+#', '/', $prefix );
		$prefix = trim( $prefix, '/' );
		$prefix = preg_replace( '#[^A-Za-z0-9!_.*\'()/-]#', '-', $prefix );

		return $prefix === '' ? '' : $prefix . '/';
	}

	/**
	 * Chiave S3 di un backup: <prefisso><cartella>/<file>.wpress
	 */
	public static function object_key( $folder, $archive ) {
		$folder = sanitize_key( $folder );
		return self::get()['prefix'] . ( $folder !== '' ? $folder . '/' : '' ) . basename( str_replace( '\\', '/', $archive ) );
	}

	/**
	 * Cartella di destinazione su S3 per una pianificazione.
	 */
	public static function schedule_folder( $schedule_id ) {
		return 'sched-' . sanitize_key( $schedule_id );
	}

	// ------------------------------------------------------------------
	// Pianificazioni
	// ------------------------------------------------------------------

	public static function schedule_defaults() {
		return array(
			'id'             => '',
			'name'           => '',
			'enabled'        => 1,
			'frequency'      => 'daily',
			'time'           => '03:00',
			'weekday'        => 1,
			'monthday'       => 1,
			'options'        => array(),
			'retention'      => 7,
			'keep_local'     => 0,
			'encrypt'        => 0,
			'encrypt_password' => '',
		);
	}

	public static function get_schedules() {
		$schedules = get_option( self::SCHEDULES_OPTION, array() );
		if ( ! is_array( $schedules ) ) {
			return array();
		}

		$out = array();
		foreach ( $schedules as $id => $schedule ) {
			$out[ $id ] = wp_parse_args( (array) $schedule, self::schedule_defaults() );
		}

		return $out;
	}

	public static function get_schedule( $id ) {
		$schedules = self::get_schedules();
		return isset( $schedules[ $id ] ) ? $schedules[ $id ] : null;
	}

	public static function frequencies() {
		return array(
			'hourly'  => __( 'Ogni ora', 's3-backup-scheduler-ai1wm' ),
			'daily'   => __( 'Ogni giorno', 's3-backup-scheduler-ai1wm' ),
			'weekly'  => __( 'Ogni settimana', 's3-backup-scheduler-ai1wm' ),
			'monthly' => __( 'Ogni mese', 's3-backup-scheduler-ai1wm' ),
		);
	}

	/**
	 * Valida e salva una pianificazione. Restituisce l'id.
	 */
	public static function save_schedule( array $input ) {
		$schedules = self::get_schedules();

		$id = isset( $input['id'] ) ? sanitize_key( $input['id'] ) : '';
		if ( $id === '' || ! isset( $schedules[ $id ] ) ) {
			$id = strtolower( wp_generate_password( 8, false, false ) );
		}

		$previous = isset( $schedules[ $id ] ) ? $schedules[ $id ] : self::schedule_defaults();

		$time = isset( $input['time'] ) ? (string) $input['time'] : '03:00';
		if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $time ) ) {
			$time = '03:00';
		}

		$options = array();
		$allowed = array_keys( self::export_option_labels() );
		if ( isset( $input['options'] ) && is_array( $input['options'] ) ) {
			foreach ( $input['options'] as $key => $value ) {
				if ( in_array( $key, $allowed, true ) && $value ) {
					$options[ $key ] = 1;
				}
			}
		}

		$frequency = ( isset( $input['frequency'] ) && array_key_exists( $input['frequency'], self::frequencies() ) ) ? $input['frequency'] : 'daily';

		$password = isset( $input['encrypt_password'] ) ? (string) $input['encrypt_password'] : '';
		if ( $password === '' ) {
			$password = $previous['encrypt_password'];
		}

		$schedules[ $id ] = array(
			'id'               => $id,
			'name'             => sanitize_text_field( isset( $input['name'] ) && $input['name'] !== '' ? $input['name'] : __( 'Backup pianificato', 's3-backup-scheduler-ai1wm' ) ),
			'enabled'          => empty( $input['enabled'] ) ? 0 : 1,
			'frequency'        => $frequency,
			'time'             => $time,
			'weekday'          => max( 0, min( 6, (int) ( isset( $input['weekday'] ) ? $input['weekday'] : 1 ) ) ),
			'monthday'         => max( 1, min( 28, (int) ( isset( $input['monthday'] ) ? $input['monthday'] : 1 ) ) ),
			'options'          => $options,
			'retention'        => max( 0, min( 1000, (int) ( isset( $input['retention'] ) ? $input['retention'] : 7 ) ) ),
			'keep_local'       => empty( $input['keep_local'] ) ? 0 : 1,
			'encrypt'          => empty( $input['encrypt'] ) ? 0 : 1,
			'encrypt_password' => $password,
		);

		if ( $schedules[ $id ]['encrypt'] && $schedules[ $id ]['encrypt_password'] === '' ) {
			$schedules[ $id ]['encrypt'] = 0;
		}

		update_option( self::SCHEDULES_OPTION, $schedules, false );

		return $id;
	}

	public static function delete_schedule( $id ) {
		$schedules = self::get_schedules();
		unset( $schedules[ $id ] );
		update_option( self::SCHEDULES_OPTION, $schedules, false );
	}

	public static function set_schedule_enabled( $id, $enabled ) {
		$schedules = self::get_schedules();
		if ( isset( $schedules[ $id ] ) ) {
			$schedules[ $id ]['enabled'] = $enabled ? 1 : 0;
			update_option( self::SCHEDULES_OPTION, $schedules, false );
		}
	}

	// ------------------------------------------------------------------
	// Backup protetti (esclusi dalla rotazione automatica)
	// ------------------------------------------------------------------

	public static function get_protected() {
		$list = get_option( self::PROTECTED_OPTION, array() );
		return is_array( $list ) ? $list : array();
	}

	public static function is_protected( $key ) {
		return array_key_exists( $key, self::get_protected() );
	}

	public static function set_protected( $key, $protected ) {
		$list = self::get_protected();
		if ( $protected ) {
			$list[ $key ] = time();
		} else {
			unset( $list[ $key ] );
		}
		update_option( self::PROTECTED_OPTION, $list, false );
	}

	/**
	 * Tutte le opzioni del plugin (usate per lo snapshot prima di un ripristino).
	 */
	public static function all_option_names() {
		return array( self::OPTION, self::SCHEDULES_OPTION, self::PROTECTED_OPTION, S3BS_History::OPTION );
	}
}
