<?php
/**
 * Aggiornamenti del plugin da GitHub (branch main).
 *
 * Il plugin dichiara "Update URI: https://github.com/<repo>", quindi WordPress non lo cerca su
 * wordpress.org e chiede a questo modulo (filtro update_plugins_github.com) se esiste una versione
 * più recente. La versione disponibile è quella scritta nell'intestazione del file principale sul
 * branch main: per pubblicare un aggiornamento basta alzare "Version:" e fare push su main.
 * Funzionano l'avviso nella pagina Plugin, "Aggiorna ora" e gli aggiornamenti automatici.
 */

defined( 'ABSPATH' ) || exit;

class S3BS_Updater {

	const REPO      = 'marcopappalardo1987/s3-backup-scheduler-ai1wm';
	const BRANCH    = 'main';
	const SLUG      = 's3-backup-scheduler-ai1wm';
	const TRANSIENT = 's3bs_github_update';
	const TTL       = 6 * HOUR_IN_SECONDS;

	public static function init() {
		add_filter( 'update_plugins_github.com', array( __CLASS__, 'check' ), 10, 3 );
		add_filter( 'plugins_api', array( __CLASS__, 'details' ), 10, 3 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder' ), 10, 4 );
		add_filter( 'plugin_row_meta', array( __CLASS__, 'row_meta' ), 10, 2 );
		add_action( 'admin_post_s3bs_check_update', array( __CLASS__, 'force_check' ) );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'flush' ), 10, 0 );
	}

	public static function repo_url() {
		return 'https://github.com/' . self::REPO;
	}

	private static function raw_url( $file ) {
		return 'https://raw.githubusercontent.com/' . self::REPO . '/' . self::BRANCH . '/' . $file;
	}

	public static function package_url() {
		return self::repo_url() . '/archive/refs/heads/' . self::BRANCH . '.zip';
	}

	/**
	 * Legge dal branch main la versione disponibile (con cache).
	 *
	 * @return array|null version, requires, requires_php, tested
	 */
	public static function remote( $force = false ) {
		$cached = get_site_transient( self::TRANSIENT );
		if ( ! $force && is_array( $cached ) ) {
			return empty( $cached['version'] ) ? null : $cached;
		}

		$data     = array();
		$response = wp_remote_get(
			self::raw_url( self::SLUG . '.php' ),
			array(
				'timeout' => 10,
				'headers' => self::headers(),
			)
		);

		if ( ! is_wp_error( $response ) && (int) wp_remote_retrieve_response_code( $response ) === 200 ) {
			$headers = self::parse_headers(
				wp_remote_retrieve_body( $response ),
				array(
					'version'      => 'Version',
					'requires'     => 'Requires at least',
					'requires_php' => 'Requires PHP',
					'tested'       => 'Tested up to',
				)
			);
			if ( preg_match( '/^\d+(\.\d+)*([.-][0-9A-Za-z.-]+)?$/', $headers['version'] ) ) {
				$data = $headers;
			}
		}

		// In caso di errore riprova tra un'ora invece che a ogni caricamento di pagina.
		set_site_transient( self::TRANSIENT, $data, empty( $data ) ? HOUR_IN_SECONDS : self::TTL );

		return empty( $data ) ? null : $data;
	}

	private static function headers() {
		$headers = array( 'User-Agent' => 'WordPress-S3BS-Updater' );
		if ( defined( 'S3BS_GITHUB_TOKEN' ) && S3BS_GITHUB_TOKEN ) {
			$headers['Authorization'] = 'token ' . S3BS_GITHUB_TOKEN;
		}
		return $headers;
	}

	private static function parse_headers( $body, array $fields ) {
		$out  = array();
		$head = substr( (string) $body, 0, 8192 );
		foreach ( $fields as $key => $label ) {
			$out[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . ':(.*)$/mi', $head, $m ) ? trim( $m[1] ) : '';
		}
		return $out;
	}

	/**
	 * Filtro update_plugins_github.com: comunica a WordPress la versione disponibile.
	 */
	public static function check( $update, $plugin_data, $plugin_file ) {
		if ( $plugin_file !== S3BS_BASENAME ) {
			return $update;
		}

		$remote = self::remote( self::is_force_check() );
		if ( ! $remote ) {
			return $update;
		}

		return array(
			'slug'         => self::SLUG,
			'version'      => $remote['version'],
			'url'          => self::repo_url(),
			'package'      => self::package_url(),
			'requires'     => $remote['requires'],
			'requires_php' => $remote['requires_php'],
			'tested'       => $remote['tested'],
		);
	}

	private static function is_force_check() {
		// "Controlla di nuovo" in Bacheca > Aggiornamenti.
		return is_admin() && isset( $_GET['force-check'] ) && current_user_can( 'update_plugins' ); // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Finestra "Visualizza dettagli" della pagina Plugin.
	 */
	public static function details( $result, $action, $args ) {
		if ( $action !== 'plugin_information' || ! isset( $args->slug ) || $args->slug !== self::SLUG ) {
			return $result;
		}

		$remote  = self::remote();
		$local   = get_plugin_data( S3BS_FILE, false, false );
		$readme  = '';
		$request = wp_remote_get( self::raw_url( 'readme.txt' ), array( 'timeout' => 10, 'headers' => self::headers() ) );
		if ( ! is_wp_error( $request ) && (int) wp_remote_retrieve_response_code( $request ) === 200 ) {
			$readme = wp_remote_retrieve_body( $request );
		}

		$sections = array(
			'description' => '<p>' . esc_html( $local['Description'] ) . '</p>',
		);
		foreach ( array( 'Installazione' => 'installation', 'Changelog' => 'changelog' ) as $title => $key ) {
			$text = self::readme_section( $readme, $title );
			if ( $text !== '' ) {
				$sections[ $key ] = self::readme_to_html( $text );
			}
		}

		return (object) array(
			'name'          => $local['Name'],
			'slug'          => self::SLUG,
			'version'       => $remote ? $remote['version'] : $local['Version'],
			'author'        => '<a href="https://marcopappalardo.it">Marco Pappalardo</a>',
			'homepage'      => self::repo_url(),
			'requires'      => $remote ? $remote['requires'] : '',
			'requires_php'  => $remote ? $remote['requires_php'] : '',
			'tested'        => $remote ? $remote['tested'] : '',
			'download_link' => self::package_url(),
			'sections'      => $sections,
		);
	}

	private static function readme_section( $readme, $title ) {
		if ( ! preg_match( '/^==\s*' . preg_quote( $title, '/' ) . '\s*==\s*$(.*?)(?=^==[^=]|\z)/msi', (string) $readme, $m ) ) {
			return '';
		}
		return trim( $m[1] );
	}

	private static function readme_to_html( $text ) {
		$html = '';
		$list = false;
		foreach ( preg_split( '/\R/', $text ) as $line ) {
			$line = trim( $line );
			if ( preg_match( '/^=\s*(.+?)\s*=$/', $line, $m ) ) {
				$html .= ( $list ? '</ul>' : '' ) . '<h4>' . esc_html( $m[1] ) . '</h4>';
				$list  = false;
			} elseif ( preg_match( '/^[*-]\s+(.+)$/', $line, $m ) ) {
				$html .= ( $list ? '' : '<ul>' ) . '<li>' . esc_html( $m[1] ) . '</li>';
				$list  = true;
			} elseif ( $line !== '' ) {
				$html .= ( $list ? '</ul>' : '' ) . '<p>' . esc_html( $line ) . '</p>';
				$list  = false;
			}
		}
		return $html . ( $list ? '</ul>' : '' );
	}

	/**
	 * Lo zip di GitHub contiene la cartella "<repo>-main": la rinomina con il nome della cartella
	 * del plugin, altrimenti WordPress lo installerebbe in una cartella diversa e lo disattiverebbe.
	 */
	public static function fix_folder( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		global $wp_filesystem;

		if ( is_wp_error( $source ) || ! $wp_filesystem ) {
			return $source;
		}

		$is_update = isset( $hook_extra['plugin'] ) && $hook_extra['plugin'] === S3BS_BASENAME;
		$is_ours   = $wp_filesystem->exists( trailingslashit( $source ) . self::SLUG . '.php' );
		if ( ! $is_update && ! ( empty( $hook_extra['plugin'] ) && $is_ours ) ) {
			return $source;
		}
		if ( ! $is_ours ) {
			return new WP_Error( 's3bs_bad_package', __( 'Il pacchetto scaricato da GitHub non contiene il plugin.', 's3-backup-scheduler-ai1wm' ) );
		}

		$folder = $is_update ? dirname( S3BS_BASENAME ) : self::SLUG;
		$target = trailingslashit( dirname( untrailingslashit( $source ) ) ) . $folder;
		if ( untrailingslashit( $source ) === $target ) {
			return $source;
		}
		if ( $wp_filesystem->exists( $target ) ) {
			$wp_filesystem->delete( $target, true );
		}
		if ( ! $wp_filesystem->move( untrailingslashit( $source ), $target ) ) {
			return new WP_Error( 's3bs_rename_failed', __( 'Impossibile preparare la cartella del plugin aggiornato.', 's3-backup-scheduler-ai1wm' ) );
		}

		return trailingslashit( $target );
	}

	public static function row_meta( $links, $file ) {
		if ( $file !== S3BS_BASENAME || ! current_user_can( 'update_plugins' ) ) {
			return $links;
		}
		$links[] = '<a href="' . esc_url( self::repo_url() ) . '" target="_blank" rel="noopener">GitHub</a>';
		$links[] = '<a href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=s3bs_check_update' ), 's3bs_check_update' ) ) . '">' . esc_html__( 'Controlla aggiornamenti', 's3-backup-scheduler-ai1wm' ) . '</a>';
		return $links;
	}

	public static function force_check() {
		if ( ! current_user_can( 'update_plugins' ) ) {
			wp_die( esc_html__( 'Permessi insufficienti.', 's3-backup-scheduler-ai1wm' ) );
		}
		check_admin_referer( 's3bs_check_update' );

		self::remote( true );
		delete_site_transient( 'update_plugins' );
		wp_update_plugins();

		wp_safe_redirect( is_network_admin() || is_multisite() ? network_admin_url( 'plugins.php' ) : admin_url( 'plugins.php' ) );
		exit;
	}

	public static function flush() {
		delete_site_transient( self::TRANSIENT );
	}
}
