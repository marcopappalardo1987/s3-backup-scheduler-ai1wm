<?php
/**
 * Pulizia alla disinstallazione. I backup nel bucket S3 NON vengono toccati.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

foreach ( array( 's3bs_settings', 's3bs_schedules', 's3bs_protected', 's3bs_history', 's3bs_running_job' ) as $option ) {
	delete_option( $option );
}
delete_transient( 's3bs_reconciled' );
delete_site_transient( 's3bs_github_update' );

$crons = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
if ( is_array( $crons ) ) {
	foreach ( $crons as $timestamp => $hooks ) {
		if ( empty( $hooks['s3bs_run_schedule'] ) ) {
			continue;
		}
		foreach ( $hooks['s3bs_run_schedule'] as $event ) {
			wp_unschedule_event( $timestamp, 's3bs_run_schedule', isset( $event['args'] ) ? $event['args'] : array() );
		}
	}
}

$guard = WPMU_PLUGIN_DIR . DIRECTORY_SEPARATOR . 's3bs-restore-guard.php';
if ( is_file( $guard ) ) {
	@unlink( $guard );
}
