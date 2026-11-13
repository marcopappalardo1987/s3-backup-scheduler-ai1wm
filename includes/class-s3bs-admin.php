<?php
/**
 * Pagina di amministrazione "Backup S3".
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Admin {

	const SLUG  = 's3bs';
	const NONCE = 's3bs_admin';

	public static function init() {
		// Priorità 20: il menu di All-in-One WP Migration deve già esistere.
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 20 );
		add_action( 'admin_notices', array( __CLASS__, 'dependency_notice' ) );

		if ( ! S3BS_Plugin::ai1wm_ready() ) {
			return;
		}

		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'plugin_action_links_' . S3BS_BASENAME, array( __CLASS__, 'action_links' ) );

		foreach ( array( 'save_settings', 'save_schedule', 'delete_schedule', 'toggle_schedule', 'clear_history' ) as $action ) {
			add_action( 'admin_post_s3bs_' . $action, array( __CLASS__, 'post_' . $action ) );
		}

		foreach ( array( 'test', 'backup_now', 'run_schedule', 'upload_local', 'delete_local', 'delete_s3', 'protect', 'download_url', 'restore', 'resume', 'cancel', 'job_status' ) as $action ) {
			add_action( 'wp_ajax_s3bs_' . $action, array( __CLASS__, 'ajax_' . $action ) );
		}
	}

	public static function can_manage() {
		return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
	}

	public static function url( $tab = 'backups', $args = array() ) {
		return add_query_arg( array_merge( array( 'page' => self::SLUG, 'tab' => $tab ), $args ), admin_url( 'admin.php' ) );
	}

	/** @var string Hook della pagina del plugin (per caricare CSS/JS solo lì). */
	private static $hook = '';

	public static function menu() {
		if ( ! self::can_manage() ) {
			return;
		}

		$title = __( 'Backup S3', 's3-backup-scheduler-ai1wm' );

		// Dentro il menu di All-in-One WP Migration, subito dopo "Backup". Su multisite AI1WM ha il
		// menu solo nella bacheca di rete, quindi lì (e se AI1WM manca) resta un menu autonomo.
		$parent = 'ai1wm_export';
		if ( ! is_multisite() && S3BS_Plugin::ai1wm_ready() && self::menu_exists( $parent ) ) {
			$hook = add_submenu_page( $parent, $title, $title, 'manage_options', self::SLUG, array( __CLASS__, 'render' ), self::position_after( $parent, 'ai1wm_backups' ) );
		} else {
			$hook = add_menu_page( $title, $title, 'manage_options', self::SLUG, array( __CLASS__, 'render' ), 'dashicons-cloud-upload', 77 );
		}

		self::$hook = (string) $hook;
	}

	private static function menu_exists( $slug ) {
		global $menu;
		foreach ( (array) $menu as $item ) {
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				return true;
			}
		}
		return false;
	}

	private static function position_after( $parent, $slug ) {
		global $submenu;
		$position = 0;
		foreach ( isset( $submenu[ $parent ] ) ? (array) $submenu[ $parent ] : array() as $item ) {
			++$position;
			if ( isset( $item[2] ) && $item[2] === $slug ) {
				return $position;
			}
		}
		return null;
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( self::url( 'settings' ) ) . '">' . esc_html__( 'Impostazioni', 's3-backup-scheduler-ai1wm' ) . '</a>' );
		return $links;
	}

	public static function dependency_notice() {
		if ( S3BS_Plugin::ai1wm_ready() || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-error"><p>';
		if ( defined( 'AI1WM_VERSION' ) ) {
			printf(
				/* translators: 1: versione installata, 2: versione minima */
				esc_html__( 'S3 Backup Scheduler richiede una versione più recente di All-in-One WP Migration (installata: %1$s, consigliata: %2$s o successiva).', 's3-backup-scheduler-ai1wm' ),
				esc_html( AI1WM_VERSION ),
				esc_html( S3BS_MIN_AI1WM_VERSION )
			);
		} else {
			esc_html_e( 'S3 Backup Scheduler richiede il plugin All-in-One WP Migration installato e attivo.', 's3-backup-scheduler-ai1wm' );
		}
		echo '</p></div>';
	}

	public static function assets( $hook ) {
		if ( self::$hook === '' || $hook !== self::$hook ) {
			return;
		}

		wp_enqueue_style( 's3bs-admin', S3BS_URL . 'assets/admin.css', array(), S3BS_VERSION );
		wp_enqueue_script( 's3bs-admin', S3BS_URL . 'assets/admin.js', array( 'jquery' ), S3BS_VERSION, true );

		$running = S3BS_History::running();
		$row     = $running ? S3BS_History::get( $running['job'] ) : null;

		wp_localize_script(
			's3bs-admin',
			's3bs',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( self::NONCE ),
				'restUrl'   => esc_url_raw( rest_url( 'ai1wm/v1/imports/' ) ),
				'secretKey' => get_option( AI1WM_SECRET_KEY ),
				'loginUrl'  => wp_login_url( self::url() ),
				'activeJob' => $running ? array(
					'job'  => $running['job'],
					'type' => $row ? $row['type'] : $running['type'],
				) : null,
				'i18n'      => array(
					'working'         => __( 'Operazione in corso...', 's3-backup-scheduler-ai1wm' ),
					'done'            => __( 'Completato', 's3-backup-scheduler-ai1wm' ),
					'error'           => __( 'Errore', 's3-backup-scheduler-ai1wm' ),
					'close'           => __( 'Chiudi', 's3-backup-scheduler-ai1wm' ),
					'reload'          => __( 'Ricarica la pagina', 's3-backup-scheduler-ai1wm' ),
					'login'           => __( 'Accedi di nuovo', 's3-backup-scheduler-ai1wm' ),
					'proceed'         => __( 'Prosegui', 's3-backup-scheduler-ai1wm' ),
					'cancel'          => __( 'Annulla', 's3-backup-scheduler-ai1wm' ),
					'confirmDelete'   => __( 'Eliminare definitivamente questo backup da S3?', 's3-backup-scheduler-ai1wm' ),
					'confirmDelLocal' => __( 'Eliminare questo backup dalla cartella locale di All-in-One WP Migration?', 's3-backup-scheduler-ai1wm' ),
					'confirmRestore'  => __( "ATTENZIONE: il ripristino sostituirà database, file multimediali, plugin e temi di questo sito con il contenuto del backup selezionato.\n\nScrivi RIPRISTINA per confermare:", 's3-backup-scheduler-ai1wm' ),
					'restoreWord'     => __( 'RIPRISTINA', 's3-backup-scheduler-ai1wm' ),
					'passwordPrompt'  => __( 'Il backup è cifrato. Inserisci la password di decifratura:', 's3-backup-scheduler-ai1wm' ),
					'restoreDone'     => __( 'Ripristino completato. Per sicurezza le sessioni potrebbero essere state chiuse: se richiesto, accedi di nuovo con le credenziali presenti nel backup.', 's3-backup-scheduler-ai1wm' ),
					'stalled'         => __( 'Nessun avanzamento da alcuni minuti. Se persiste, verifica che il server consenta le richieste HTTP verso se stesso (loopback) e controlla il registro.', 's3-backup-scheduler-ai1wm' ),
					'restoreGuardOff' => __( 'Nota: non è stato possibile installare la protezione temporanea delle impostazioni (cartella mu-plugins non scrivibile). Dopo il ripristino potrebbe essere necessario riattivare questo plugin e reinserire le impostazioni S3.', 's3-backup-scheduler-ai1wm' ),
				),
			)
		);
	}

	// ------------------------------------------------------------------
	// Rendering
	// ------------------------------------------------------------------

	public static function render() {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Non hai i permessi per accedere a questa pagina.', 's3-backup-scheduler-ai1wm' ) );
		}

		if ( ! S3BS_Plugin::ai1wm_ready() ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Backup S3', 's3-backup-scheduler-ai1wm' ) . '</h1></div>';
			return;
		}

		$tab  = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'backups';
		$tabs = array(
			'backups'   => __( 'Backup', 's3-backup-scheduler-ai1wm' ),
			'schedules' => __( 'Pianificazioni', 's3-backup-scheduler-ai1wm' ),
			'settings'  => __( 'Impostazioni S3', 's3-backup-scheduler-ai1wm' ),
			'history'   => __( 'Registro', 's3-backup-scheduler-ai1wm' ),
		);
		if ( ! isset( $tabs[ $tab ] ) ) {
			$tab = 'backups';
		}

		echo '<div class="wrap s3bs-wrap">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Backup S3 per All-in-One WP Migration', 's3-backup-scheduler-ai1wm' ) . '</h1>';
		echo '<hr class="wp-header-end">';

		self::render_flash();

		if ( ! S3BS_Settings::is_configured() && $tab !== 'settings' ) {
			echo '<div class="notice notice-warning"><p>' . wp_kses_post(
				sprintf(
					/* translators: %s: link alle impostazioni */
					__( 'Per iniziare <a href="%s">configura la connessione al tuo bucket S3</a>.', 's3-backup-scheduler-ai1wm' ),
					esc_url( self::url( 'settings' ) )
				)
			) . '</p></div>';
		}

		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $key => $label ) {
			printf(
				'<a href="%s" class="nav-tab%s">%s</a>',
				esc_url( self::url( $key ) ),
				$key === $tab ? ' nav-tab-active' : '',
				esc_html( $label )
			);
		}
		echo '</nav>';

		echo '<div id="s3bs-job" class="s3bs-job" hidden><div class="s3bs-job-head"><span class="spinner is-active"></span><strong class="s3bs-job-title"></strong></div><div class="s3bs-job-message"></div><div class="s3bs-job-actions"></div></div>';

		call_user_func( array( __CLASS__, 'tab_' . $tab ) );

		echo '</div>';
	}

	private static function render_flash() {
		if ( empty( $_GET['s3bs_msg'] ) ) {
			return;
		}

		$messages = array(
			'settings_saved'   => array( 'success', __( 'Impostazioni salvate.', 's3-backup-scheduler-ai1wm' ) ),
			'schedule_saved'   => array( 'success', __( 'Pianificazione salvata.', 's3-backup-scheduler-ai1wm' ) ),
			'schedule_deleted' => array( 'success', __( 'Pianificazione eliminata.', 's3-backup-scheduler-ai1wm' ) ),
			'schedule_toggled' => array( 'success', __( 'Pianificazione aggiornata.', 's3-backup-scheduler-ai1wm' ) ),
			'history_cleared'  => array( 'success', __( 'Registro svuotato.', 's3-backup-scheduler-ai1wm' ) ),
		);

		$key = sanitize_key( wp_unslash( $_GET['s3bs_msg'] ) );
		if ( isset( $messages[ $key ] ) ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $messages[ $key ][0] ), esc_html( $messages[ $key ][1] ) );
		}
	}

	private static function tab_backups() {
		$configured = S3BS_Settings::is_configured();
		$settings   = S3BS_Settings::get();
		$schedules  = S3BS_Settings::get_schedules();
		$protected  = S3BS_Settings::get_protected();

		// --- Backup ora --------------------------------------------------
		?>
		<div class="s3bs-card">
			<h2><?php esc_html_e( 'Crea un backup adesso', 's3-backup-scheduler-ai1wm' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Il backup viene creato da All-in-One WP Migration e caricato subito nel bucket (cartella "manual"). Scegli cosa includere:', 's3-backup-scheduler-ai1wm' ); ?></p>
			<form id="s3bs-backup-now" class="s3bs-inline-form">
				<div class="s3bs-options-grid">
					<?php foreach ( S3BS_Settings::export_option_labels() as $name => $label ) : ?>
						<label><input type="checkbox" name="options[<?php echo esc_attr( $name ); ?>]" value="1"> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</div>
				<p>
					<label><input type="checkbox" name="keep_local" value="1"> <?php esc_html_e( 'Mantieni anche una copia locale sul server', 's3-backup-scheduler-ai1wm' ); ?></label>
				</p>
				<p>
					<label><?php esc_html_e( 'Password di cifratura (facoltativa):', 's3-backup-scheduler-ai1wm' ); ?>
						<input type="password" name="encrypt_password" autocomplete="new-password" class="regular-text"></label>
				</p>
				<p><button type="submit" class="button button-primary" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Esegui backup su S3', 's3-backup-scheduler-ai1wm' ); ?></button></p>
			</form>
		</div>
		<?php

		// --- Backup su S3 ------------------------------------------------
		echo '<h2>' . esc_html__( 'Backup nel bucket S3', 's3-backup-scheduler-ai1wm' ) . '</h2>';

		if ( ! $configured ) {
			echo '<p>' . esc_html__( 'Nessun bucket configurato.', 's3-backup-scheduler-ai1wm' ) . '</p>';
		} else {
			try {
				$objects = S3BS_S3_Client::from_settings()->list_objects( $settings['prefix'] );
				$objects = array_values(
					array_filter(
						$objects,
						function ( $o ) {
							return substr( strtolower( $o['key'] ), -7 ) === '.wpress';
						}
					)
				);
				usort(
					$objects,
					function ( $a, $b ) {
						return $b['last_modified'] - $a['last_modified'];
					}
				);

				printf(
					'<p class="description">%s</p>',
					esc_html(
						sprintf(
							/* translators: 1: bucket, 2: prefisso */
							__( 'Bucket: %1$s — prefisso: %2$s. I backup "protetti" sono esclusi dalla rotazione automatica e non vengono mai eliminati dalle pianificazioni.', 's3-backup-scheduler-ai1wm' ),
							$settings['bucket'],
							$settings['prefix'] === '' ? '/' : $settings['prefix']
						)
					)
				);

				if ( ! $objects ) {
					echo '<p>' . esc_html__( 'Nessun backup presente nel bucket.', 's3-backup-scheduler-ai1wm' ) . '</p>';
				} else {
					?>
					<table class="widefat striped s3bs-table">
						<thead><tr>
							<th><?php esc_html_e( 'Backup', 's3-backup-scheduler-ai1wm' ); ?></th>
							<th><?php esc_html_e( 'Origine', 's3-backup-scheduler-ai1wm' ); ?></th>
							<th><?php esc_html_e( 'Data', 's3-backup-scheduler-ai1wm' ); ?></th>
							<th><?php esc_html_e( 'Dimensione', 's3-backup-scheduler-ai1wm' ); ?></th>
							<th><?php esc_html_e( 'Conserva', 's3-backup-scheduler-ai1wm' ); ?></th>
							<th class="s3bs-actions-col"><?php esc_html_e( 'Azioni', 's3-backup-scheduler-ai1wm' ); ?></th>
						</tr></thead>
						<tbody>
						<?php foreach ( $objects as $object ) : ?>
							<?php $is_protected = isset( $protected[ $object['key'] ] ); ?>
							<tr data-key="<?php echo esc_attr( $object['key'] ); ?>">
								<td><strong><?php echo esc_html( basename( $object['key'] ) ); ?></strong><br><code class="s3bs-key"><?php echo esc_html( $object['key'] ); ?></code></td>
								<td><?php echo esc_html( self::folder_label( $object['key'], $settings['prefix'], $schedules ) ); ?></td>
								<td><?php echo esc_html( self::format_date( $object['last_modified'] ) ); ?></td>
								<td><?php echo esc_html( size_format( $object['size'], 1 ) ); ?></td>
								<td>
									<label class="s3bs-protect">
										<input type="checkbox" class="s3bs-protect-toggle" <?php checked( $is_protected ); ?>>
										<?php esc_html_e( 'Protetto', 's3-backup-scheduler-ai1wm' ); ?>
									</label>
								</td>
								<td class="s3bs-actions">
									<button type="button" class="button button-primary s3bs-restore"><?php esc_html_e( 'Ripristina', 's3-backup-scheduler-ai1wm' ); ?></button>
									<button type="button" class="button s3bs-download"><?php esc_html_e( 'Scarica', 's3-backup-scheduler-ai1wm' ); ?></button>
									<button type="button" class="button button-link-delete s3bs-delete-s3" <?php disabled( $is_protected ); ?>><?php esc_html_e( 'Elimina', 's3-backup-scheduler-ai1wm' ); ?></button>
								</td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
					<?php
				}
			} catch ( Exception $e ) {
				echo '<div class="notice notice-error inline"><p>' . esc_html__( 'Impossibile leggere il bucket:', 's3-backup-scheduler-ai1wm' ) . ' ' . esc_html( $e->getMessage() ) . '</p></div>';
			}
		}

		// --- Backup locali -----------------------------------------------
		$files = Ai1wm_Backups::get_files();
		echo '<h2>' . esc_html__( 'Backup locali di All-in-One WP Migration', 's3-backup-scheduler-ai1wm' ) . '</h2>';
		echo '<p class="description">' . esc_html__( 'Scegli quali backup già presenti sul server vuoi salvare nel bucket S3.', 's3-backup-scheduler-ai1wm' ) . '</p>';

		if ( ! $files ) {
			echo '<p>' . esc_html__( 'Nessun backup locale.', 's3-backup-scheduler-ai1wm' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped s3bs-table">
			<thead><tr>
				<th><?php esc_html_e( 'Backup', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th><?php esc_html_e( 'Data', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th><?php esc_html_e( 'Dimensione', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th class="s3bs-actions-col"><?php esc_html_e( 'Azioni', 's3-backup-scheduler-ai1wm' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $files as $file ) : ?>
				<tr data-archive="<?php echo esc_attr( $file['filename'] ); ?>">
					<td><strong><?php echo esc_html( $file['filename'] ); ?></strong></td>
					<td><?php echo esc_html( $file['mtime'] ? self::format_date( $file['mtime'] ) : '—' ); ?></td>
					<td><?php echo esc_html( $file['size'] !== null ? size_format( $file['size'], 1 ) : '—' ); ?></td>
					<td class="s3bs-actions">
						<button type="button" class="button button-primary s3bs-upload-local" <?php disabled( ! $configured ); ?>><?php esc_html_e( 'Salva su S3', 's3-backup-scheduler-ai1wm' ); ?></button>
						<button type="button" class="button button-link-delete s3bs-delete-local"><?php esc_html_e( 'Elimina', 's3-backup-scheduler-ai1wm' ); ?></button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function tab_schedules() {
		$schedules = S3BS_Settings::get_schedules();
		$edit_id   = isset( $_GET['edit'] ) ? sanitize_key( wp_unslash( $_GET['edit'] ) ) : '';
		$editing   = ( $edit_id && isset( $schedules[ $edit_id ] ) ) ? $schedules[ $edit_id ] : S3BS_Settings::schedule_defaults();
		$weekdays  = array( __( 'Domenica', 's3-backup-scheduler-ai1wm' ), __( 'Lunedì', 's3-backup-scheduler-ai1wm' ), __( 'Martedì', 's3-backup-scheduler-ai1wm' ), __( 'Mercoledì', 's3-backup-scheduler-ai1wm' ), __( 'Giovedì', 's3-backup-scheduler-ai1wm' ), __( 'Venerdì', 's3-backup-scheduler-ai1wm' ), __( 'Sabato', 's3-backup-scheduler-ai1wm' ) );
		$labels    = S3BS_Settings::export_option_labels();

		if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( 'WP-Cron interno è disattivato (DISABLE_WP_CRON): assicurati che un cron di sistema richiami wp-cron.php regolarmente, altrimenti i backup pianificati non partiranno.', 's3-backup-scheduler-ai1wm' ) . '</p></div>';
		} else {
			echo '<p class="description">' . esc_html__( 'Le pianificazioni usano WP-Cron, che parte quando il sito riceve visite. Per orari precisi su siti poco visitati imposta un cron di sistema su wp-cron.php.', 's3-backup-scheduler-ai1wm' ) . '</p>';
		}

		if ( $schedules ) {
			?>
			<table class="widefat striped s3bs-table">
				<thead><tr>
					<th><?php esc_html_e( 'Nome', 's3-backup-scheduler-ai1wm' ); ?></th>
					<th><?php esc_html_e( 'Frequenza', 's3-backup-scheduler-ai1wm' ); ?></th>
					<th><?php esc_html_e( 'Contenuto', 's3-backup-scheduler-ai1wm' ); ?></th>
					<th><?php esc_html_e( 'Conserva', 's3-backup-scheduler-ai1wm' ); ?></th>
					<th><?php esc_html_e( 'Prossima esecuzione', 's3-backup-scheduler-ai1wm' ); ?></th>
					<th class="s3bs-actions-col"><?php esc_html_e( 'Azioni', 's3-backup-scheduler-ai1wm' ); ?></th>
				</tr></thead>
				<tbody>
				<?php foreach ( $schedules as $id => $schedule ) : ?>
					<?php $next = S3BS_Scheduler::next_timestamp( $id ); ?>
					<tr data-schedule="<?php echo esc_attr( $id ); ?>">
						<td>
							<strong><?php echo esc_html( $schedule['name'] ); ?></strong>
							<?php if ( ! $schedule['enabled'] ) : ?><span class="s3bs-badge s3bs-badge-off"><?php esc_html_e( 'disattivata', 's3-backup-scheduler-ai1wm' ); ?></span><?php endif; ?>
							<?php if ( $schedule['encrypt'] ) : ?><span class="s3bs-badge"><?php esc_html_e( 'cifrato', 's3-backup-scheduler-ai1wm' ); ?></span><?php endif; ?>
							<br><code><?php echo esc_html( S3BS_Settings::schedule_folder( $id ) ); ?>/</code>
						</td>
						<td><?php echo esc_html( self::describe_frequency( $schedule, $weekdays ) ); ?></td>
						<td><?php echo esc_html( self::describe_options( $schedule['options'], $labels ) ); ?></td>
						<td>
							<?php
							echo esc_html(
								$schedule['retention'] > 0
									/* translators: %d: numero di backup */
									? sprintf( _n( 'ultimo %d backup', 'ultimi %d backup', $schedule['retention'], 's3-backup-scheduler-ai1wm' ), $schedule['retention'] )
									: __( 'tutti', 's3-backup-scheduler-ai1wm' )
							);
							if ( $schedule['keep_local'] ) {
								echo '<br><small>' . esc_html__( '+ copia locale', 's3-backup-scheduler-ai1wm' ) . '</small>';
							}
							?>
						</td>
						<td><?php echo esc_html( $next && $schedule['enabled'] ? self::format_date( $next ) : '—' ); ?></td>
						<td class="s3bs-actions">
							<button type="button" class="button button-primary s3bs-run-schedule"><?php esc_html_e( 'Esegui ora', 's3-backup-scheduler-ai1wm' ); ?></button>
							<a class="button" href="<?php echo esc_url( self::url( 'schedules', array( 'edit' => $id ) ) ); ?>#s3bs-schedule-form"><?php esc_html_e( 'Modifica', 's3-backup-scheduler-ai1wm' ); ?></a>
							<a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=s3bs_toggle_schedule&id=' . rawurlencode( $id ) ), 's3bs_toggle_schedule_' . $id ) ); ?>"><?php echo $schedule['enabled'] ? esc_html__( 'Disattiva', 's3-backup-scheduler-ai1wm' ) : esc_html__( 'Attiva', 's3-backup-scheduler-ai1wm' ); ?></a>
							<a class="button button-link-delete s3bs-confirm-link" data-confirm="<?php esc_attr_e( 'Eliminare questa pianificazione? I backup già presenti su S3 non verranno toccati.', 's3-backup-scheduler-ai1wm' ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=s3bs_delete_schedule&id=' . rawurlencode( $id ) ), 's3bs_delete_schedule_' . $id ) ); ?>"><?php esc_html_e( 'Elimina', 's3-backup-scheduler-ai1wm' ); ?></a>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php
		} else {
			echo '<p>' . esc_html__( 'Nessuna pianificazione. Creane una qui sotto.', 's3-backup-scheduler-ai1wm' ) . '</p>';
		}
		?>
		<div class="s3bs-card" id="s3bs-schedule-form">
			<h2><?php echo $editing['id'] ? esc_html__( 'Modifica pianificazione', 's3-backup-scheduler-ai1wm' ) : esc_html__( 'Nuova pianificazione', 's3-backup-scheduler-ai1wm' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="s3bs_save_schedule">
				<input type="hidden" name="schedule[id]" value="<?php echo esc_attr( $editing['id'] ); ?>">
				<?php wp_nonce_field( 's3bs_save_schedule' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="s3bs-name"><?php esc_html_e( 'Nome', 's3-backup-scheduler-ai1wm' ); ?></label></th>
						<td><input id="s3bs-name" type="text" class="regular-text" name="schedule[name]" value="<?php echo esc_attr( $editing['name'] ); ?>" placeholder="<?php esc_attr_e( 'Es. Backup completo notturno', 's3-backup-scheduler-ai1wm' ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Quando', 's3-backup-scheduler-ai1wm' ); ?></th>
						<td class="s3bs-when">
							<select name="schedule[frequency]" id="s3bs-frequency">
								<?php foreach ( S3BS_Settings::frequencies() as $value => $label ) : ?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $editing['frequency'], $value ); ?>><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
							<span class="s3bs-when-weekly">
								<?php esc_html_e( 'il', 's3-backup-scheduler-ai1wm' ); ?>
								<select name="schedule[weekday]">
									<?php foreach ( $weekdays as $index => $label ) : ?>
										<option value="<?php echo (int) $index; ?>" <?php selected( (int) $editing['weekday'], $index ); ?>><?php echo esc_html( $label ); ?></option>
									<?php endforeach; ?>
								</select>
							</span>
							<span class="s3bs-when-monthly">
								<?php esc_html_e( 'il giorno', 's3-backup-scheduler-ai1wm' ); ?>
								<input type="number" min="1" max="28" name="schedule[monthday]" value="<?php echo (int) $editing['monthday']; ?>" class="small-text">
							</span>
							<span class="s3bs-when-time">
								<span class="s3bs-when-time-label"><?php esc_html_e( 'alle', 's3-backup-scheduler-ai1wm' ); ?></span>
								<input type="time" name="schedule[time]" value="<?php echo esc_attr( $editing['time'] ); ?>">
							</span>
							<p class="description">
								<?php
								/* translators: %s: fuso orario */
								printf( esc_html__( 'Fuso orario del sito: %s. Per la frequenza oraria conta solo il minuto.', 's3-backup-scheduler-ai1wm' ), esc_html( wp_timezone_string() ) );
								?>
							</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Contenuto del backup', 's3-backup-scheduler-ai1wm' ); ?></th>
						<td>
							<p class="description"><?php esc_html_e( 'Per impostazione predefinita il backup è completo. Seleziona cosa escludere:', 's3-backup-scheduler-ai1wm' ); ?></p>
							<div class="s3bs-options-grid">
								<?php foreach ( $labels as $name => $label ) : ?>
									<label><input type="checkbox" name="schedule[options][<?php echo esc_attr( $name ); ?>]" value="1" <?php checked( ! empty( $editing['options'][ $name ] ) ); ?>> <?php echo esc_html( $label ); ?></label>
								<?php endforeach; ?>
							</div>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="s3bs-retention"><?php esc_html_e( 'Backup da conservare su S3', 's3-backup-scheduler-ai1wm' ); ?></label></th>
						<td>
							<input id="s3bs-retention" type="number" min="0" max="1000" class="small-text" name="schedule[retention]" value="<?php echo (int) $editing['retention']; ?>">
							<p class="description"><?php esc_html_e( 'Dopo ogni backup riuscito vengono eliminati da S3 i backup più vecchi di questa pianificazione oltre questo numero. 0 = conserva tutto. I backup segnati come "Protetto" non vengono mai eliminati.', 's3-backup-scheduler-ai1wm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Copia locale', 's3-backup-scheduler-ai1wm' ); ?></th>
						<td><label><input type="checkbox" name="schedule[keep_local]" value="1" <?php checked( $editing['keep_local'] ); ?>> <?php esc_html_e( 'Mantieni il file anche sul server dopo il caricamento (occupa spazio su disco)', 's3-backup-scheduler-ai1wm' ); ?></label></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Cifratura', 's3-backup-scheduler-ai1wm' ); ?></th>
						<td>
							<label><input type="checkbox" name="schedule[encrypt]" value="1" <?php checked( $editing['encrypt'] ); ?>> <?php esc_html_e( 'Cifra il backup con una password (AES-256)', 's3-backup-scheduler-ai1wm' ); ?></label><br>
							<input type="password" class="regular-text" name="schedule[encrypt_password]" autocomplete="new-password" placeholder="<?php echo $editing['encrypt_password'] !== '' ? esc_attr__( 'Password salvata — lascia vuoto per non cambiarla', 's3-backup-scheduler-ai1wm' ) : esc_attr__( 'Password', 's3-backup-scheduler-ai1wm' ); ?>">
							<p class="description"><?php esc_html_e( 'Conserva la password in un luogo sicuro: senza non potrai ripristinare il backup.', 's3-backup-scheduler-ai1wm' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Stato', 's3-backup-scheduler-ai1wm' ); ?></th>
						<td><label><input type="checkbox" name="schedule[enabled]" value="1" <?php checked( $editing['enabled'] ); ?>> <?php esc_html_e( 'Pianificazione attiva', 's3-backup-scheduler-ai1wm' ); ?></label></td>
					</tr>
				</table>
				<?php submit_button( $editing['id'] ? __( 'Aggiorna pianificazione', 's3-backup-scheduler-ai1wm' ) : __( 'Crea pianificazione', 's3-backup-scheduler-ai1wm' ) ); ?>
				<?php if ( $editing['id'] ) : ?>
					<a href="<?php echo esc_url( self::url( 'schedules' ) ); ?>"><?php esc_html_e( 'Annulla modifica', 's3-backup-scheduler-ai1wm' ); ?></a>
				<?php endif; ?>
			</form>
		</div>
		<?php
	}

	private static function tab_settings() {
		$settings     = S3BS_Settings::get();
		$stored       = wp_parse_args( (array) get_option( S3BS_Settings::OPTION, array() ), S3BS_Settings::defaults() );
		$key_constant = defined( 'S3BS_ACCESS_KEY' ) && S3BS_ACCESS_KEY;
		$sec_constant = defined( 'S3BS_SECRET_KEY' ) && S3BS_SECRET_KEY;
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="s3bs-settings-form" class="s3bs-card">
			<input type="hidden" name="action" value="s3bs_save_settings">
			<?php wp_nonce_field( 's3bs_save_settings' ); ?>
			<h2><?php esc_html_e( 'Connessione al bucket', 's3-backup-scheduler-ai1wm' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="s3bs-access-key">Access Key ID</label></th>
					<td>
						<?php if ( $key_constant ) : ?>
							<code><?php esc_html_e( 'definita in wp-config.php (S3BS_ACCESS_KEY)', 's3-backup-scheduler-ai1wm' ); ?></code>
						<?php else : ?>
							<input id="s3bs-access-key" type="text" class="regular-text" name="s3bs[access_key]" value="<?php echo esc_attr( $stored['access_key'] ); ?>" autocomplete="off">
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="s3bs-secret-key">Secret Access Key</label></th>
					<td>
						<?php if ( $sec_constant ) : ?>
							<code><?php esc_html_e( 'definita in wp-config.php (S3BS_SECRET_KEY)', 's3-backup-scheduler-ai1wm' ); ?></code>
						<?php else : ?>
							<input id="s3bs-secret-key" type="password" class="regular-text" name="s3bs[secret_key]" value="" autocomplete="new-password" placeholder="<?php echo $stored['secret_key'] !== '' ? esc_attr__( 'Salvata — lascia vuoto per non cambiarla', 's3-backup-scheduler-ai1wm' ) : ''; ?>">
						<?php endif; ?>
						<p class="description"><?php esc_html_e( 'Consigliato: definisci le credenziali in wp-config.php con define( \'S3BS_ACCESS_KEY\', \'...\' ); e define( \'S3BS_SECRET_KEY\', \'...\' ); così non vengono salvate nel database.', 's3-backup-scheduler-ai1wm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="s3bs-bucket"><?php esc_html_e( 'Bucket', 's3-backup-scheduler-ai1wm' ); ?></label></th>
					<td><input id="s3bs-bucket" type="text" class="regular-text" name="s3bs[bucket]" value="<?php echo esc_attr( $stored['bucket'] ); ?>"></td>
				</tr>
				<tr>
					<th scope="row"><label for="s3bs-region"><?php esc_html_e( 'Regione', 's3-backup-scheduler-ai1wm' ); ?></label></th>
					<td>
						<input id="s3bs-region" type="text" class="regular-text" name="s3bs[region]" value="<?php echo esc_attr( $stored['region'] ); ?>" list="s3bs-regions">
						<datalist id="s3bs-regions">
							<?php foreach ( array( 'eu-south-1', 'eu-central-1', 'eu-west-1', 'eu-west-2', 'eu-west-3', 'eu-north-1', 'eu-south-2', 'eu-central-2', 'us-east-1', 'us-east-2', 'us-west-1', 'us-west-2', 'auto' ) as $region ) : ?>
								<option value="<?php echo esc_attr( $region ); ?>">
							<?php endforeach; ?>
						</datalist>
						<p class="description"><?php esc_html_e( 'Es. eu-south-1 (Milano), eu-central-1 (Francoforte). Per Cloudflare R2 usa "auto".', 's3-backup-scheduler-ai1wm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="s3bs-prefix"><?php esc_html_e( 'Cartella (prefisso)', 's3-backup-scheduler-ai1wm' ); ?></label></th>
					<td>
						<input id="s3bs-prefix" type="text" class="regular-text" name="s3bs[prefix]" value="<?php echo esc_attr( $stored['prefix'] ); ?>">
						<p class="description"><?php esc_html_e( 'I backup finiscono in <prefisso>/manual/ e <prefisso>/sched-<id>/. Usa un prefisso diverso per ogni sito se condividi il bucket.', 's3-backup-scheduler-ai1wm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="s3bs-storage-class"><?php esc_html_e( 'Classe di archiviazione', 's3-backup-scheduler-ai1wm' ); ?></label></th>
					<td>
						<select id="s3bs-storage-class" name="s3bs[storage_class]">
							<?php foreach ( S3BS_Settings::storage_classes() as $value => $label ) : ?>
								<option value="<?php echo esc_attr( $value ); ?>" <?php selected( $stored['storage_class'], $value ); ?>><?php echo esc_html( $label ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'Solo Amazon S3. Con servizi compatibili lascia "Standard". Le classi Glacier Flexible/Deep Archive non sono supportate perché non permettono il ripristino immediato.', 's3-backup-scheduler-ai1wm' ); ?></p>
					</td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Servizi compatibili S3 (facoltativo)', 's3-backup-scheduler-ai1wm' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="s3bs-endpoint"><?php esc_html_e( 'Endpoint personalizzato', 's3-backup-scheduler-ai1wm' ); ?></label></th>
					<td>
						<input id="s3bs-endpoint" type="url" class="regular-text" name="s3bs[endpoint]" value="<?php echo esc_attr( $stored['endpoint'] ); ?>" placeholder="https://s3.eu-central-1.wasabisys.com">
						<p class="description"><?php esc_html_e( 'Lascia vuoto per Amazon S3. Per Wasabi, Cloudflare R2, MinIO, DigitalOcean Spaces, Backblaze B2, ecc. inserisci l\'URL dell\'endpoint, senza il nome del bucket (Cloudflare R2: https://<ID account>.r2.cloudflarestorage.com, regione "auto").', 's3-backup-scheduler-ai1wm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Indirizzamento', 's3-backup-scheduler-ai1wm' ); ?></th>
					<td><label><input type="checkbox" name="s3bs[path_style]" value="1" <?php checked( $stored['path_style'] ); ?>> <?php esc_html_e( 'Usa URL path-style (endpoint/bucket/file) — necessario per MinIO e alcuni servizi', 's3-backup-scheduler-ai1wm' ); ?></label></td>
				</tr>
			</table>

			<h2><?php esc_html_e( 'Avanzate', 's3-backup-scheduler-ai1wm' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="s3bs-part-size"><?php esc_html_e( 'Dimensione dei blocchi di upload (MB)', 's3-backup-scheduler-ai1wm' ); ?></label></th>
					<td>
						<input id="s3bs-part-size" type="number" min="5" max="512" class="small-text" name="s3bs[part_size_mb]" value="<?php echo (int) $stored['part_size_mb']; ?>">
						<p class="description"><?php esc_html_e( 'Ogni blocco viene tenuto in memoria durante l\'invio: con memory_limit basso usa valori piccoli (5–16 MB).', 's3-backup-scheduler-ai1wm' ); ?></p>
					</td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Limite di import', 's3-backup-scheduler-ai1wm' ); ?></th>
					<td>
						<label><input type="checkbox" name="s3bs[remove_upload_limit]" value="1" <?php checked( $stored['remove_upload_limit'] ); ?>> <?php esc_html_e( 'Rimuovi il limite di dimensione dei file nella pagina "Importa" di All-in-One WP Migration', 's3-backup-scheduler-ai1wm' ); ?></label>
						<p class="description">
							<?php
							/* translators: %s: limite attuale */
							printf( esc_html__( 'Limite attuale del server: %s. Il file viene comunque caricato a blocchi, quindi non serve aumentare upload_max_filesize.', 's3-backup-scheduler-ai1wm' ), esc_html( size_format( wp_max_upload_size() ) ) );
							?>
						</p>
					</td>
				</tr>
			</table>

			<p class="submit">
				<button type="submit" class="button button-primary"><?php esc_html_e( 'Salva impostazioni', 's3-backup-scheduler-ai1wm' ); ?></button>
				<button type="button" class="button" id="s3bs-test"><?php esc_html_e( 'Verifica connessione', 's3-backup-scheduler-ai1wm' ); ?></button>
				<span id="s3bs-test-result" class="s3bs-test-result" role="status"></span>
			</p>
		</form>

		<div class="s3bs-card">
			<h2><?php esc_html_e( 'Permessi IAM minimi', 's3-backup-scheduler-ai1wm' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Crea un utente IAM dedicato con questa policy (sostituisci il nome del bucket):', 's3-backup-scheduler-ai1wm' ); ?></p>
			<pre class="s3bs-pre"><?php echo esc_html( self::iam_policy( $settings['bucket'] !== '' ? $settings['bucket'] : 'NOME-BUCKET' ) ); ?></pre>
			<p class="description"><?php esc_html_e( 'Suggerimento: aggiungi al bucket una regola di lifecycle "AbortIncompleteMultipartUpload" (es. 7 giorni) per eliminare eventuali caricamenti interrotti.', 's3-backup-scheduler-ai1wm' ); ?></p>
		</div>
		<?php
	}

	private static function tab_history() {
		$rows = S3BS_History::all();
		$type_labels = array(
			'backup'  => __( 'Backup', 's3-backup-scheduler-ai1wm' ),
			'upload'  => __( 'Caricamento', 's3-backup-scheduler-ai1wm' ),
			'restore' => __( 'Ripristino', 's3-backup-scheduler-ai1wm' ),
		);
		$status_labels = array(
			'running' => __( 'In corso', 's3-backup-scheduler-ai1wm' ),
			'ok'      => __( 'Riuscito', 's3-backup-scheduler-ai1wm' ),
			'error'   => __( 'Errore', 's3-backup-scheduler-ai1wm' ),
		);

		if ( ! $rows ) {
			echo '<p>' . esc_html__( 'Il registro è vuoto.', 's3-backup-scheduler-ai1wm' ) . '</p>';
			return;
		}
		?>
		<table class="widefat striped s3bs-table">
			<thead><tr>
				<th><?php esc_html_e( 'Data', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th><?php esc_html_e( 'Operazione', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th><?php esc_html_e( 'Origine', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th><?php esc_html_e( 'Backup', 's3-backup-scheduler-ai1wm' ); ?></th>
				<th><?php esc_html_e( 'Esito', 's3-backup-scheduler-ai1wm' ); ?></th>
			</tr></thead>
			<tbody>
			<?php foreach ( $rows as $row ) : ?>
				<tr>
					<td><?php echo esc_html( self::format_date( $row['started'] ) ); ?></td>
					<td><?php echo esc_html( isset( $type_labels[ $row['type'] ] ) ? $type_labels[ $row['type'] ] : $row['type'] ); ?></td>
					<td><?php echo esc_html( $row['source'] ); ?></td>
					<td>
						<?php echo esc_html( $row['archive'] !== '' ? $row['archive'] : '—' ); ?>
						<?php if ( ! empty( $row['size'] ) ) : ?><br><small><?php echo esc_html( size_format( $row['size'], 1 ) ); ?></small><?php endif; ?>
					</td>
					<td>
						<span class="s3bs-status s3bs-status-<?php echo esc_attr( $row['status'] ); ?>"><?php echo esc_html( isset( $status_labels[ $row['status'] ] ) ? $status_labels[ $row['status'] ] : $row['status'] ); ?></span>
						<br><small><?php echo esc_html( wp_strip_all_tags( $row['message'] ) ); ?></small>
						<?php if ( ! empty( $row['warning'] ) ) : ?><br><small class="s3bs-warning"><?php echo esc_html( $row['warning'] ); ?></small><?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p>
			<a class="button s3bs-confirm-link" data-confirm="<?php esc_attr_e( 'Svuotare il registro?', 's3-backup-scheduler-ai1wm' ); ?>" href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=s3bs_clear_history' ), 's3bs_clear_history' ) ); ?>"><?php esc_html_e( 'Svuota registro', 's3-backup-scheduler-ai1wm' ); ?></a>
		</p>
		<?php
	}

	// ------------------------------------------------------------------
	// admin-post
	// ------------------------------------------------------------------

	private static function guard_post( $nonce_action ) {
		if ( ! self::can_manage() ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 's3-backup-scheduler-ai1wm' ), 403 );
		}
		check_admin_referer( $nonce_action );
	}

	public static function post_save_settings() {
		self::guard_post( 's3bs_save_settings' );
		S3BS_Settings::save( isset( $_POST['s3bs'] ) ? (array) wp_unslash( $_POST['s3bs'] ) : array() );
		wp_safe_redirect( self::url( 'settings', array( 's3bs_msg' => 'settings_saved' ) ) );
		exit;
	}

	public static function post_save_schedule() {
		self::guard_post( 's3bs_save_schedule' );
		$id = S3BS_Settings::save_schedule( isset( $_POST['schedule'] ) ? (array) wp_unslash( $_POST['schedule'] ) : array() );
		S3BS_Scheduler::reschedule( $id );
		wp_safe_redirect( self::url( 'schedules', array( 's3bs_msg' => 'schedule_saved' ) ) );
		exit;
	}

	public static function post_delete_schedule() {
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		self::guard_post( 's3bs_delete_schedule_' . $id );
		S3BS_Settings::delete_schedule( $id );
		wp_clear_scheduled_hook( S3BS_Scheduler::HOOK, array( $id ) );
		wp_safe_redirect( self::url( 'schedules', array( 's3bs_msg' => 'schedule_deleted' ) ) );
		exit;
	}

	public static function post_toggle_schedule() {
		$id = isset( $_GET['id'] ) ? sanitize_key( wp_unslash( $_GET['id'] ) ) : '';
		self::guard_post( 's3bs_toggle_schedule_' . $id );
		$schedule = S3BS_Settings::get_schedule( $id );
		if ( $schedule ) {
			S3BS_Settings::set_schedule_enabled( $id, ! $schedule['enabled'] );
			S3BS_Scheduler::reschedule( $id );
		}
		wp_safe_redirect( self::url( 'schedules', array( 's3bs_msg' => 'schedule_toggled' ) ) );
		exit;
	}

	public static function post_clear_history() {
		self::guard_post( 's3bs_clear_history' );
		S3BS_History::clear();
		wp_safe_redirect( self::url( 'history', array( 's3bs_msg' => 'history_cleared' ) ) );
		exit;
	}

	// ------------------------------------------------------------------
	// AJAX
	// ------------------------------------------------------------------

	private static function guard_ajax() {
		if ( ! self::can_manage() ) {
			wp_send_json_error( array( 'message' => __( 'Permessi insufficienti.', 's3-backup-scheduler-ai1wm' ) ), 403 );
		}
		if ( ! check_ajax_referer( self::NONCE, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Sessione scaduta: ricarica la pagina.', 's3-backup-scheduler-ai1wm' ) ), 403 );
		}
	}

	private static function post_string( $name ) {
		return isset( $_POST[ $name ] ) ? trim( (string) wp_unslash( $_POST[ $name ] ) ) : '';
	}

	/**
	 * Accetta solo chiavi .wpress sotto il prefisso configurato.
	 */
	private static function valid_key() {
		$key    = self::post_string( 'key' );
		$prefix = S3BS_Settings::get()['prefix'];

		if ( $key === '' || substr( strtolower( $key ), -7 ) !== '.wpress' || ( $prefix !== '' && strpos( $key, $prefix ) !== 0 ) || strpos( $key, '..' ) !== false ) {
			wp_send_json_error( array( 'message' => __( 'Backup non valido.', 's3-backup-scheduler-ai1wm' ) ), 400 );
		}

		return $key;
	}

	public static function ajax_test() {
		self::guard_ajax();

		$input  = isset( $_POST['s3bs'] ) ? (array) wp_unslash( $_POST['s3bs'] ) : array();
		$stored = S3BS_Settings::get();
		$config = array(
			'access_key' => ( defined( 'S3BS_ACCESS_KEY' ) && S3BS_ACCESS_KEY ) ? S3BS_ACCESS_KEY : ( isset( $input['access_key'] ) ? sanitize_text_field( $input['access_key'] ) : '' ),
			'secret_key' => ( defined( 'S3BS_SECRET_KEY' ) && S3BS_SECRET_KEY ) ? S3BS_SECRET_KEY : ( ! empty( $input['secret_key'] ) ? trim( $input['secret_key'] ) : $stored['secret_key'] ),
			'region'     => isset( $input['region'] ) ? sanitize_text_field( $input['region'] ) : '',
			'bucket'     => isset( $input['bucket'] ) ? sanitize_text_field( $input['bucket'] ) : '',
			'endpoint'   => isset( $input['endpoint'] ) ? esc_url_raw( trim( $input['endpoint'] ) ) : '',
			'path_style' => ! empty( $input['path_style'] ),
		);

		if ( $config['access_key'] === '' || $config['secret_key'] === '' || $config['bucket'] === '' ) {
			wp_send_json_error( array( 'message' => __( 'Inserisci Access Key, Secret Key e Bucket.', 's3-backup-scheduler-ai1wm' ) ) );
		}

		try {
			$client = new S3BS_S3_Client( $config );
			$client->test_connection( S3BS_Settings::normalize_prefix( isset( $input['prefix'] ) ? $input['prefix'] : '' ) );

			// Verifica anche la scrittura con un piccolo file temporaneo.
			$probe = S3BS_Settings::normalize_prefix( isset( $input['prefix'] ) ? $input['prefix'] : '' ) . '.s3bs-write-test-' . wp_generate_password( 6, false, false );
			$client->request( 'PUT', $probe, array(), array( 'content-type' => 'text/plain' ), 'ok' );
			$client->delete_object( $probe );

			wp_send_json_success( array( 'message' => __( 'Connessione riuscita: lettura e scrittura sul bucket funzionano.', 's3-backup-scheduler-ai1wm' ) ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_backup_now() {
		self::guard_ajax();

		$options = isset( $_POST['options'] ) && is_array( $_POST['options'] ) ? array_map( 'absint', wp_unslash( $_POST['options'] ) ) : array();

		try {
			$job = S3BS_Pipeline::start_backup(
				array(
					'options'          => $options,
					'folder'           => 'manual',
					'keep_local'       => ! empty( $_POST['keep_local'] ),
					'retention'        => 0,
					'source'           => __( 'Manuale', 's3-backup-scheduler-ai1wm' ),
					'encrypt_password' => self::post_string( 'encrypt_password' ),
				)
			);
			wp_send_json_success( array( 'job' => $job, 'type' => 'backup' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_run_schedule() {
		self::guard_ajax();

		$schedule = S3BS_Settings::get_schedule( sanitize_key( self::post_string( 'id' ) ) );
		if ( ! $schedule ) {
			wp_send_json_error( array( 'message' => __( 'Pianificazione non trovata.', 's3-backup-scheduler-ai1wm' ) ) );
		}

		try {
			$job = S3BS_Pipeline::start_backup(
				array(
					'options'          => $schedule['options'],
					'folder'           => S3BS_Settings::schedule_folder( $schedule['id'] ),
					'keep_local'       => $schedule['keep_local'],
					'retention'        => $schedule['retention'],
					'source'           => $schedule['name'],
					'encrypt_password' => $schedule['encrypt'] ? $schedule['encrypt_password'] : '',
				)
			);
			wp_send_json_success( array( 'job' => $job, 'type' => 'backup' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_upload_local() {
		self::guard_ajax();

		try {
			$job = S3BS_Pipeline::start_upload_local( self::post_string( 'archive' ) );
			wp_send_json_success( array( 'job' => $job, 'type' => 'upload' ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_delete_local() {
		self::guard_ajax();

		try {
			$archive = S3BS_Pipeline::find_local_backup( self::post_string( 'archive' ) );
			if ( ! Ai1wm_Backups::delete_file( $archive ) ) {
				throw new Exception( __( 'Impossibile eliminare il file.', 's3-backup-scheduler-ai1wm' ) );
			}
			if ( method_exists( 'Ai1wm_Backups', 'delete_label' ) ) {
				Ai1wm_Backups::delete_label( $archive );
			}
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_delete_s3() {
		self::guard_ajax();
		$key = self::valid_key();

		if ( S3BS_Settings::is_protected( $key ) ) {
			wp_send_json_error( array( 'message' => __( 'Il backup è protetto: togli la protezione prima di eliminarlo.', 's3-backup-scheduler-ai1wm' ) ) );
		}

		try {
			S3BS_S3_Client::from_settings()->delete_object( $key );
			S3BS_Settings::set_protected( $key, false );
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_protect() {
		self::guard_ajax();
		$key = self::valid_key();
		S3BS_Settings::set_protected( $key, ! empty( $_POST['protected'] ) && $_POST['protected'] !== 'false' );
		wp_send_json_success();
	}

	public static function ajax_download_url() {
		self::guard_ajax();
		$key = self::valid_key();

		try {
			$url = S3BS_S3_Client::from_settings()->presigned_get_url( $key, HOUR_IN_SECONDS, basename( $key ) );
			wp_send_json_success( array( 'url' => $url ) );
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_restore() {
		self::guard_ajax();

		if ( ! current_user_can( 'ai1wm_import_site' ) && ! current_user_can( 'import' ) ) {
			wp_send_json_error( array( 'message' => __( 'Non hai i permessi per importare.', 's3-backup-scheduler-ai1wm' ) ), 403 );
		}

		$key = self::valid_key();

		try {
			$result = S3BS_Pipeline::start_restore( $key, self::post_string( 'password' ) );
			wp_send_json_success(
				array(
					'job'   => $result['job'],
					'type'  => 'restore',
					'guard' => $result['guard'],
				)
			);
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_resume() {
		self::guard_ajax();

		$job = sanitize_key( self::post_string( 'job' ) );
		try {
			S3BS_Pipeline::resume_restore( $job, self::post_string( 'password' ) );
			wp_send_json_success();
		} catch ( Exception $e ) {
			wp_send_json_error( array( 'message' => $e->getMessage() ) );
		}
	}

	public static function ajax_cancel() {
		self::guard_ajax();
		S3BS_Pipeline::cancel_restore( sanitize_key( self::post_string( 'job' ) ) );
		wp_send_json_success();
	}

	public static function ajax_job_status() {
		self::guard_ajax();

		$job    = sanitize_key( self::post_string( 'job' ) );
		$status = get_option( 'ai1wm_status_' . $job, array() );
		$row    = S3BS_History::get( $job );

		// Se il lock è scaduto, running() aggiorna anche il registro.
		S3BS_History::running();
		$row = S3BS_History::get( $job ) ? S3BS_History::get( $job ) : $row;

		wp_send_json_success(
			array(
				'ai1wm'   => is_array( $status ) ? $status : array(),
				'history' => $row,
			)
		);
	}

	// ------------------------------------------------------------------
	// Helper
	// ------------------------------------------------------------------

	private static function format_date( $timestamp ) {
		return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $timestamp );
	}

	private static function folder_label( $key, $prefix, $schedules ) {
		$relative = $prefix !== '' && strpos( $key, $prefix ) === 0 ? substr( $key, strlen( $prefix ) ) : $key;
		$parts    = explode( '/', $relative );

		if ( count( $parts ) < 2 ) {
			return '—';
		}

		$folder = $parts[0];
		if ( $folder === 'manual' ) {
			return __( 'Manuale', 's3-backup-scheduler-ai1wm' );
		}
		if ( strpos( $folder, 'sched-' ) === 0 ) {
			$id = substr( $folder, 6 );
			return isset( $schedules[ $id ] )
				? $schedules[ $id ]['name']
				/* translators: %s: id pianificazione */
				: sprintf( __( 'Pianificazione eliminata (%s)', 's3-backup-scheduler-ai1wm' ), $id );
		}

		return $folder;
	}

	private static function describe_frequency( $schedule, $weekdays ) {
		switch ( $schedule['frequency'] ) {
			case 'hourly':
				/* translators: %s: minuto */
				return sprintf( __( 'Ogni ora al minuto %s', 's3-backup-scheduler-ai1wm' ), substr( $schedule['time'], 3, 2 ) );
			case 'weekly':
				/* translators: 1: giorno, 2: ora */
				return sprintf( __( 'Ogni %1$s alle %2$s', 's3-backup-scheduler-ai1wm' ), strtolower( $weekdays[ (int) $schedule['weekday'] ] ), $schedule['time'] );
			case 'monthly':
				/* translators: 1: giorno del mese, 2: ora */
				return sprintf( __( 'Il giorno %1$d di ogni mese alle %2$s', 's3-backup-scheduler-ai1wm' ), (int) $schedule['monthday'], $schedule['time'] );
			default:
				/* translators: %s: ora */
				return sprintf( __( 'Ogni giorno alle %s', 's3-backup-scheduler-ai1wm' ), $schedule['time'] );
		}
	}

	private static function describe_options( $options, $labels ) {
		$excluded = array_intersect_key( $labels, array_filter( (array) $options ) );
		if ( ! $excluded ) {
			return __( 'Sito completo', 's3-backup-scheduler-ai1wm' );
		}
		return __( 'Completo tranne:', 's3-backup-scheduler-ai1wm' ) . ' ' . implode( ', ', array_map( 'lcfirst', array_map( function ( $label ) {
			return preg_replace( '/^Escludi (i |la |le |il |lo |gli )?/u', '', $label );
		}, $excluded ) ) );
	}

	private static function iam_policy( $bucket ) {
		$policy = array(
			'Version'   => '2012-10-17',
			'Statement' => array(
				array(
					'Effect'   => 'Allow',
					'Action'   => array( 's3:ListBucket' ),
					'Resource' => 'arn:aws:s3:::' . $bucket,
				),
				array(
					'Effect'   => 'Allow',
					'Action'   => array( 's3:PutObject', 's3:GetObject', 's3:DeleteObject', 's3:AbortMultipartUpload', 's3:ListMultipartUploadParts' ),
					'Resource' => 'arn:aws:s3:::' . $bucket . '/*',
				),
			),
		);

		return wp_json_encode( $policy, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
	}
}
