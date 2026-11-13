<?php
/**
 * Pianificazione dei backup con WP-Cron (un evento singolo per pianificazione,
 * ricalcolato ad ogni esecuzione: così orari, giorni della settimana e giorni del mese
 * restano precisi nel fuso orario del sito).
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Scheduler {

	const HOOK          = 's3bs_run_schedule';
	const RECONCILE_KEY = 's3bs_reconciled';

	public static function init() {
		add_action( self::HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'maybe_reconcile' ), 20 );
	}

	/**
	 * Controllo periodico: ogni pianificazione attiva deve avere il suo prossimo evento
	 * (ad es. dopo un ripristino la tabella cron viene sostituita con quella del backup).
	 */
	public static function maybe_reconcile() {
		if ( get_transient( self::RECONCILE_KEY ) ) {
			return;
		}
		set_transient( self::RECONCILE_KEY, 1, 10 * MINUTE_IN_SECONDS );
		self::reconcile();
	}

	public static function reconcile() {
		$schedules = S3BS_Settings::get_schedules();

		// Rimuove eventi di pianificazioni eliminate o disattivate.
		foreach ( self::scheduled_events() as $event ) {
			$id = isset( $event['args'][0] ) ? $event['args'][0] : '';
			if ( ! isset( $schedules[ $id ] ) || ! $schedules[ $id ]['enabled'] ) {
				wp_unschedule_event( $event['timestamp'], self::HOOK, $event['args'] );
			}
		}

		foreach ( $schedules as $id => $schedule ) {
			if ( $schedule['enabled'] && ! wp_next_scheduled( self::HOOK, array( $id ) ) ) {
				wp_schedule_single_event( self::next_run( $schedule ), self::HOOK, array( $id ) );
			}
		}
	}

	/**
	 * Da chiamare dopo aver modificato una pianificazione: ricalcola subito il prossimo evento.
	 */
	public static function reschedule( $id ) {
		wp_clear_scheduled_hook( self::HOOK, array( $id ) );

		$schedule = S3BS_Settings::get_schedule( $id );
		if ( $schedule && $schedule['enabled'] ) {
			wp_schedule_single_event( self::next_run( $schedule ), self::HOOK, array( $id ) );
		}
	}

	public static function clear_all_events() {
		foreach ( self::scheduled_events() as $event ) {
			wp_unschedule_event( $event['timestamp'], self::HOOK, $event['args'] );
		}
	}

	/**
	 * Esecuzione di una pianificazione (chiamata da WP-Cron).
	 */
	public static function run( $id ) {
		$schedule = S3BS_Settings::get_schedule( $id );
		if ( ! $schedule || ! $schedule['enabled'] ) {
			return;
		}

		// Prima di tutto programma la prossima esecuzione.
		wp_schedule_single_event( self::next_run( $schedule, time() + 60 ), self::HOOK, array( $id ) );

		try {
			S3BS_Pipeline::start_backup(
				array(
					'options'          => $schedule['options'],
					'folder'           => S3BS_Settings::schedule_folder( $id ),
					'keep_local'       => $schedule['keep_local'],
					'retention'        => $schedule['retention'],
					'source'           => $schedule['name'],
					'encrypt_password' => $schedule['encrypt'] ? $schedule['encrypt_password'] : '',
				)
			);
		} catch ( Exception $e ) {
			S3BS_History::upsert(
				'skip-' . $id . '-' . time(),
				array(
					'type'    => 'backup',
					'source'  => $schedule['name'],
					'status'  => 'error',
					/* translators: %s: motivo */
					'message' => sprintf( __( 'Backup pianificato non avviato: %s', 's3-backup-scheduler-ai1wm' ), $e->getMessage() ),
				)
			);
		}
	}

	/**
	 * Calcola il prossimo orario di esecuzione (timestamp UTC) nel fuso orario del sito.
	 */
	public static function next_run( array $schedule, $from = null ) {
		$tz   = wp_timezone();
		$from = $from ? $from : time();
		$now  = ( new DateTimeImmutable( '@' . $from ) )->setTimezone( $tz );

		list( $hour, $minute ) = array_map( 'intval', explode( ':', $schedule['time'] ) );

		switch ( $schedule['frequency'] ) {
			case 'hourly':
				$next = $now->setTime( (int) $now->format( 'G' ), $minute );
				if ( $next <= $now ) {
					$next = $next->modify( '+1 hour' );
				}
				break;

			case 'weekly':
				$next = $now->setTime( $hour, $minute );
				$diff = ( (int) $schedule['weekday'] - (int) $now->format( 'w' ) + 7 ) % 7;
				$next = $next->modify( '+' . $diff . ' days' );
				if ( $next <= $now ) {
					$next = $next->modify( '+7 days' );
				}
				break;

			case 'monthly':
				$day  = max( 1, min( 28, (int) $schedule['monthday'] ) );
				$next = $now->setDate( (int) $now->format( 'Y' ), (int) $now->format( 'n' ), $day )->setTime( $hour, $minute );
				if ( $next <= $now ) {
					$first = $now->modify( 'first day of next month' );
					$next  = $first->setDate( (int) $first->format( 'Y' ), (int) $first->format( 'n' ), $day )->setTime( $hour, $minute );
				}
				break;

			case 'daily':
			default:
				$next = $now->setTime( $hour, $minute );
				if ( $next <= $now ) {
					$next = $next->modify( '+1 day' );
				}
				break;
		}

		return $next->getTimestamp();
	}

	/**
	 * @return array[] timestamp, args
	 */
	private static function scheduled_events() {
		$events = array();
		$crons  = function_exists( '_get_cron_array' ) ? _get_cron_array() : array();
		if ( ! is_array( $crons ) ) {
			return $events;
		}

		foreach ( $crons as $timestamp => $hooks ) {
			if ( empty( $hooks[ self::HOOK ] ) ) {
				continue;
			}
			foreach ( $hooks[ self::HOOK ] as $event ) {
				$events[] = array(
					'timestamp' => $timestamp,
					'args'      => isset( $event['args'] ) ? $event['args'] : array(),
				);
			}
		}

		return $events;
	}

	public static function next_timestamp( $id ) {
		return wp_next_scheduled( self::HOOK, array( $id ) );
	}
}
