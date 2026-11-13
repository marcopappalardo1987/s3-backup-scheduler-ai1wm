<?php
/**
 * Plugin Name:       S3 Backup Scheduler for All-in-One WP Migration
 * Plugin URI:        https://github.com/marcopappalardo1987/s3-backup-scheduler-ai1wm
 * Description:       Pianifica backup di All-in-One WP Migration che finiscono direttamente nel tuo bucket Amazon S3 (o compatibile), scegli quali backup conservare e ripristinali dal bucket usando il motore di import di All-in-One WP Migration. Rimuove inoltre il limite di dimensione dell'import da file.
 * Version:           1.2.0
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Requires Plugins:  all-in-one-wp-migration
 * Author:            Marco Pappalardo
 * Author URI:        https://marcopappalardo.it
 * License:           GPLv3 or later
 * License URI:       https://www.gnu.org/licenses/gpl-3.0.html
 * Text Domain:       s3-backup-scheduler-ai1wm
 * Update URI:        https://github.com/marcopappalardo1987/s3-backup-scheduler-ai1wm
 */

defined( 'ABSPATH' ) || exit;

// Unica fonte della versione: l'intestazione "Version:" qui sopra.
define( 'S3BS_VERSION', get_file_data( __FILE__, array( 'Version' => 'Version' ) )['Version'] );
define( 'S3BS_FILE', __FILE__ );
define( 'S3BS_DIR', plugin_dir_path( __FILE__ ) );
define( 'S3BS_URL', plugin_dir_url( __FILE__ ) );
define( 'S3BS_BASENAME', plugin_basename( __FILE__ ) );

/** Versione minima di All-in-One WP Migration testata (serve lo stato per-job). */
define( 'S3BS_MIN_AI1WM_VERSION', '7.100' );

require_once S3BS_DIR . 'includes/class-s3bs-s3-exception.php';
require_once S3BS_DIR . 'includes/class-s3bs-s3-client.php';
require_once S3BS_DIR . 'includes/class-s3bs-settings.php';
require_once S3BS_DIR . 'includes/class-s3bs-history.php';
require_once S3BS_DIR . 'includes/class-s3bs-guard.php';
require_once S3BS_DIR . 'includes/class-s3bs-pipeline.php';
require_once S3BS_DIR . 'includes/class-s3bs-scheduler.php';
require_once S3BS_DIR . 'includes/class-s3bs-unlimited.php';
require_once S3BS_DIR . 'includes/class-s3bs-admin.php';
require_once S3BS_DIR . 'includes/class-s3bs-updater.php';

// Gli aggiornamenti devono funzionare anche se All-in-One WP Migration non è attivo.
S3BS_Updater::init();

final class S3BS_Plugin {

	/**
	 * Avvia il plugin dopo che tutti i plugin (incluso All-in-One WP Migration) sono caricati.
	 */
	public static function boot() {
		load_plugin_textdomain( 's3-backup-scheduler-ai1wm', false, dirname( S3BS_BASENAME ) . '/languages' );

		S3BS_Admin::init();

		if ( ! self::ai1wm_ready() ) {
			return;
		}

		S3BS_Unlimited::init();
		S3BS_Pipeline::init();
		S3BS_Scheduler::init();
		S3BS_Guard::init();
	}

	/**
	 * All-in-One WP Migration è attivo e abbastanza recente?
	 *
	 * @return bool
	 */
	public static function ai1wm_ready() {
		return defined( 'AI1WM_PLUGIN_NAME' )
			&& defined( 'AI1WM_VERSION' )
			&& class_exists( 'Ai1wm_Status' )
			&& property_exists( 'Ai1wm_Status', 'job_id' )
			&& function_exists( 'ai1wm_storage_folder' );
	}

	public static function activate() {
		if ( ! get_option( S3BS_Settings::OPTION ) ) {
			add_option( S3BS_Settings::OPTION, S3BS_Settings::defaults(), '', false );
		}
		if ( false === get_option( S3BS_Settings::SCHEDULES_OPTION ) ) {
			add_option( S3BS_Settings::SCHEDULES_OPTION, array(), '', false );
		}
	}

	public static function deactivate() {
		wp_clear_scheduled_hook( S3BS_Scheduler::HOOK );
		S3BS_Scheduler::clear_all_events();
	}
}

register_activation_hook( __FILE__, array( 'S3BS_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'S3BS_Plugin', 'deactivate' ) );

add_action( 'plugins_loaded', array( 'S3BS_Plugin', 'boot' ), 20 );
