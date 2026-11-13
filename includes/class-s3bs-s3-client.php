<?php
/**
 * Client S3 minimale con firma AWS Signature Version 4.
 * Funziona con Amazon S3 e con i servizi compatibili (Wasabi, Cloudflare R2, MinIO,
 * DigitalOcean Spaces, Backblaze B2 S3 API, ...). Usa solo la HTTP API di WordPress.
 */

defined( 'ABSPATH' ) || exit;

class S3BS_S3_Client {

	const SERVICE = 's3';

	/** @var string */
	private $access_key;
	/** @var string */
	private $secret_key;
	/** @var string */
	private $region;
	/** @var string */
	private $bucket;
	/** @var string */
	private $endpoint;
	/** @var bool */
	private $path_style;

	/**
	 * @param array $config access_key, secret_key, region, bucket, endpoint, path_style
	 */
	public function __construct( array $config ) {
		$this->access_key = trim( (string) ( isset( $config['access_key'] ) ? $config['access_key'] : '' ) );
		$this->secret_key = trim( (string) ( isset( $config['secret_key'] ) ? $config['secret_key'] : '' ) );
		$this->region     = trim( (string) ( isset( $config['region'] ) && $config['region'] !== '' ? $config['region'] : 'us-east-1' ) );
		$this->bucket     = trim( (string) ( isset( $config['bucket'] ) ? $config['bucket'] : '' ) );
		$this->endpoint   = rtrim( trim( (string) ( isset( $config['endpoint'] ) ? $config['endpoint'] : '' ) ), '/' );
		$this->path_style = ! empty( $config['path_style'] );

		// I bucket con punti non funzionano in virtual-host su HTTPS (certificato wildcard).
		if ( strpos( $this->bucket, '.' ) !== false ) {
			$this->path_style = true;
		}

		if ( $this->endpoint !== '' && ! preg_match( '#^https?://#i', $this->endpoint ) ) {
			$this->endpoint = 'https://' . $this->endpoint;
		}

		// Errore comune: incollare l'URL "API S3" della dashboard (es. Cloudflare R2), che termina
		// con /<bucket>. Il bucket verrebbe ripetuto nel percorso e S3 risponderebbe NoSuchKey.
		if ( $this->endpoint !== '' && $this->bucket !== '' ) {
			$suffix = '/' . $this->bucket;
			if ( strcasecmp( substr( $this->endpoint, -strlen( $suffix ) ), $suffix ) === 0 && preg_match( '#^https?://[^/]+/#i', $this->endpoint ) ) {
				$this->endpoint = substr( $this->endpoint, 0, -strlen( $suffix ) );
			}
		}
	}

	/**
	 * @return S3BS_S3_Client
	 * @throws S3BS_S3_Exception
	 */
	public static function from_settings() {
		$settings = S3BS_Settings::get();

		if ( $settings['access_key'] === '' || $settings['secret_key'] === '' || $settings['bucket'] === '' ) {
			throw new S3BS_S3_Exception( __( 'Configurazione S3 incompleta: inserisci Access Key, Secret Key e Bucket nelle impostazioni.', 's3-backup-scheduler-ai1wm' ) );
		}

		return new self( $settings );
	}

	// ------------------------------------------------------------------
	// Operazioni di alto livello
	// ------------------------------------------------------------------

	/**
	 * Verifica credenziali e accesso al bucket.
	 *
	 * @throws S3BS_S3_Exception
	 */
	public function test_connection( $prefix = '' ) {
		$this->request( 'GET', '', array( 'list-type' => '2', 'max-keys' => '1', 'prefix' => $prefix ) );
		return true;
	}

	/**
	 * Elenca gli oggetti con un certo prefisso (gestisce la paginazione).
	 *
	 * @return array[] key, size, last_modified (timestamp), etag, storage_class
	 * @throws S3BS_S3_Exception
	 */
	public function list_objects( $prefix = '', $limit = 10000 ) {
		$objects = array();
		$token   = null;

		do {
			$query = array(
				'list-type' => '2',
				'max-keys'  => '1000',
				'prefix'    => $prefix,
			);
			if ( $token ) {
				$query['continuation-token'] = $token;
			}

			$response = $this->request( 'GET', '', $query );
			$xml      = $this->parse_xml( $response['body'] );

			if ( isset( $xml->Contents ) ) {
				foreach ( $xml->Contents as $item ) {
					$objects[] = array(
						'key'           => (string) $item->Key,
						'size'          => (int) $item->Size,
						'last_modified' => strtotime( (string) $item->LastModified ),
						'etag'          => trim( (string) $item->ETag, '"' ),
						'storage_class' => (string) $item->StorageClass,
					);
				}
			}

			$token = ( isset( $xml->IsTruncated ) && (string) $xml->IsTruncated === 'true' && isset( $xml->NextContinuationToken ) )
				? (string) $xml->NextContinuationToken
				: null;
		} while ( $token && count( $objects ) < $limit );

		return $objects;
	}

	/**
	 * @return array|null size, last_modified, etag (null se l'oggetto non esiste)
	 * @throws S3BS_S3_Exception
	 */
	public function head_object( $key ) {
		try {
			$response = $this->request( 'HEAD', $key );
		} catch ( S3BS_S3_Exception $e ) {
			if ( $e->getCode() === 404 ) {
				return null;
			}
			throw $e;
		}

		return array(
			'size'          => (int) wp_remote_retrieve_header( $response['raw'], 'content-length' ),
			'last_modified' => strtotime( (string) wp_remote_retrieve_header( $response['raw'], 'last-modified' ) ),
			'etag'          => trim( (string) wp_remote_retrieve_header( $response['raw'], 'etag' ), '"' ),
		);
	}

	/**
	 * @throws S3BS_S3_Exception
	 */
	public function delete_object( $key ) {
		$this->request( 'DELETE', $key );
		return true;
	}

	/**
	 * @return string UploadId
	 * @throws S3BS_S3_Exception
	 */
	public function create_multipart_upload( $key, $storage_class = '' ) {
		$headers = array( 'content-type' => 'application/octet-stream' );
		if ( $storage_class !== '' && $storage_class !== 'STANDARD' ) {
			$headers['x-amz-storage-class'] = $storage_class;
		}

		$response = $this->request( 'POST', $key, array( 'uploads' => '' ), $headers );
		$xml      = $this->parse_xml( $response['body'] );

		if ( empty( $xml->UploadId ) ) {
			throw new S3BS_S3_Exception( __( 'S3 non ha restituito un UploadId per il caricamento multipart.', 's3-backup-scheduler-ai1wm' ) );
		}

		return (string) $xml->UploadId;
	}

	/**
	 * @return string ETag della parte
	 * @throws S3BS_S3_Exception
	 */
	public function upload_part( $key, $upload_id, $part_number, $data ) {
		$response = $this->request(
			'PUT',
			$key,
			array(
				'partNumber' => (string) (int) $part_number,
				'uploadId'   => $upload_id,
			),
			array( 'content-type' => 'application/octet-stream' ),
			$data,
			array( 'timeout' => 600 )
		);

		$etag = trim( (string) wp_remote_retrieve_header( $response['raw'], 'etag' ) );
		if ( $etag === '' ) {
			throw new S3BS_S3_Exception( __( 'S3 non ha restituito l\'ETag della parte caricata.', 's3-backup-scheduler-ai1wm' ) );
		}

		return $etag;
	}

	/**
	 * @param array $parts [ [ 'PartNumber' => int, 'ETag' => string ], ... ]
	 * @throws S3BS_S3_Exception
	 */
	public function complete_multipart_upload( $key, $upload_id, array $parts ) {
		usort(
			$parts,
			function ( $a, $b ) {
				return (int) $a['PartNumber'] - (int) $b['PartNumber'];
			}
		);

		$xml = '<CompleteMultipartUpload>';
		foreach ( $parts as $part ) {
			$xml .= '<Part><PartNumber>' . (int) $part['PartNumber'] . '</PartNumber><ETag>' . esc_html( $part['ETag'] ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';

		$response = $this->request(
			'POST',
			$key,
			array( 'uploadId' => $upload_id ),
			array( 'content-type' => 'application/xml' ),
			$xml,
			array( 'timeout' => 600 )
		);

		// S3 può rispondere 200 con un <Error> nel corpo.
		if ( strpos( $response['body'], '<Error>' ) !== false ) {
			$this->throw_from_body( $response['body'], 200 );
		}

		return true;
	}

	public function abort_multipart_upload( $key, $upload_id ) {
		try {
			$this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ) );
		} catch ( Exception $e ) {
			// Best effort.
		}
	}

	/**
	 * Scarica un intervallo di byte di un oggetto direttamente su file (senza caricarlo in memoria).
	 *
	 * @return int Byte scritti
	 * @throws S3BS_S3_Exception
	 */
	public function download_range_to_file( $key, $start, $end, $filename ) {
		$response = $this->request(
			'GET',
			$key,
			array(),
			array( 'range' => 'bytes=' . (int) $start . '-' . (int) $end ),
			'',
			array(
				'timeout'  => 900,
				'stream'   => true,
				'filename' => $filename,
			)
		);

		$code = (int) wp_remote_retrieve_response_code( $response['raw'] );
		if ( $code === 200 && (int) $start !== 0 ) {
			throw new S3BS_S3_Exception( __( 'Il server S3 non supporta i download parziali (Range).', 's3-backup-scheduler-ai1wm' ), $code );
		}

		clearstatcache( true, $filename );
		return is_file( $filename ) ? (int) filesize( $filename ) : 0;
	}

	/**
	 * URL pre-firmato per scaricare un oggetto dal browser.
	 */
	public function presigned_get_url( $key, $expires = 3600, $download_name = '' ) {
		$target    = $this->target( $key );
		$timestamp = time();
		$amz_date  = gmdate( 'Ymd\THis\Z', $timestamp );
		$date      = gmdate( 'Ymd', $timestamp );
		$scope     = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';

		$query = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $this->access_key . '/' . $scope,
			'X-Amz-Date'          => $amz_date,
			'X-Amz-Expires'       => (string) (int) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);
		if ( $download_name !== '' ) {
			$query['response-content-disposition'] = 'attachment; filename="' . str_replace( '"', '', $download_name ) . '"';
		}

		$canonical_query = $this->canonical_query( $query );
		$canonical       = "GET\n" . $target['canonical_uri'] . "\n" . $canonical_query . "\nhost:" . $target['host'] . "\n\nhost\nUNSIGNED-PAYLOAD";
		$string_to_sign  = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical );
		$signature       = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date ) );

		return $target['url'] . '?' . $canonical_query . '&X-Amz-Signature=' . $signature;
	}

	// ------------------------------------------------------------------
	// Firma SigV4 e trasporto
	// ------------------------------------------------------------------

	/**
	 * @return array [ 'body' => string, 'raw' => array ]
	 * @throws S3BS_S3_Exception
	 */
	public function request( $method, $key = '', array $query = array(), array $headers = array(), $body = '', array $options = array() ) {
		$target       = $this->target( $key );
		$timestamp    = time();
		$amz_date     = gmdate( 'Ymd\THis\Z', $timestamp );
		$date         = gmdate( 'Ymd', $timestamp );
		$payload_hash = hash( 'sha256', (string) $body );

		$headers                         = array_change_key_case( $headers, CASE_LOWER );
		$headers['host']                 = $target['host'];
		$headers['x-amz-date']           = $amz_date;
		$headers['x-amz-content-sha256'] = $payload_hash;
		ksort( $headers );

		$canonical_headers = '';
		foreach ( $headers as $name => $value ) {
			$canonical_headers .= $name . ':' . trim( preg_replace( '/\s+/', ' ', (string) $value ) ) . "\n";
		}
		$signed_headers  = implode( ';', array_keys( $headers ) );
		$canonical_query = $this->canonical_query( $query );

		$canonical_request = implode(
			"\n",
			array(
				$method,
				$target['canonical_uri'],
				$canonical_query,
				$canonical_headers,
				$signed_headers,
				$payload_hash,
			)
		);

		$scope          = $date . '/' . $this->region . '/' . self::SERVICE . '/aws4_request';
		$string_to_sign = "AWS4-HMAC-SHA256\n" . $amz_date . "\n" . $scope . "\n" . hash( 'sha256', $canonical_request );
		$signature      = hash_hmac( 'sha256', $string_to_sign, $this->signing_key( $date ) );

		$headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->access_key . '/' . $scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

		// L'header Host lo imposta il trasporto HTTP.
		unset( $headers['host'] );

		$url = $target['url'] . ( $canonical_query !== '' ? '?' . $canonical_query : '' );

		$args = array(
			'method'      => $method,
			'headers'     => $headers,
			'timeout'     => isset( $options['timeout'] ) ? (int) $options['timeout'] : 60,
			'redirection' => 0,
			'sslverify'   => apply_filters( 's3bs_sslverify', true ),
			'user-agent'  => 'S3BackupScheduler/' . S3BS_VERSION . '; WordPress',
		);
		if ( $body !== '' ) {
			$args['body'] = $body;
		}
		if ( ! empty( $options['stream'] ) ) {
			$args['stream']   = true;
			$args['filename'] = $options['filename'];
		}

		$raw = wp_remote_request( $url, $args );

		if ( is_wp_error( $raw ) ) {
			throw new S3BS_S3_Exception(
				/* translators: %s: messaggio di errore HTTP */
				sprintf( __( 'Errore di connessione a S3: %s', 's3-backup-scheduler-ai1wm' ), $raw->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $raw );
		if ( ! empty( $options['stream'] ) ) {
			$response_body = '';
			if ( $code >= 300 && is_file( $options['filename'] ) && filesize( $options['filename'] ) < 65536 ) {
				$response_body = (string) file_get_contents( $options['filename'] );
			}
		} else {
			$response_body = (string) wp_remote_retrieve_body( $raw );
		}

		if ( $code < 200 || $code >= 300 ) {
			if ( ! empty( $options['stream'] ) && is_file( $options['filename'] ) ) {
				@unlink( $options['filename'] );
			}
			$this->throw_from_body( $response_body, $code );
		}

		return array(
			'body' => $response_body,
			'raw'  => $raw,
		);
	}

	/**
	 * Calcola URL, host e URI canonica per una chiave.
	 */
	private function target( $key ) {
		$key          = ltrim( (string) $key, '/' );
		$encoded_key  = implode( '/', array_map( 'rawurlencode', explode( '/', $key ) ) );

		if ( $this->endpoint !== '' ) {
			$parts  = wp_parse_url( $this->endpoint );
			$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
			$host   = $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
			$base   = isset( $parts['path'] ) ? rtrim( $parts['path'], '/' ) : '';
		} else {
			$scheme = 'https';
			$host   = 's3.' . $this->region . '.amazonaws.com';
			$base   = '';
		}

		if ( $this->path_style ) {
			$uri = $base . '/' . rawurlencode( $this->bucket ) . '/' . $encoded_key;
		} else {
			$host = $this->bucket . '.' . $host;
			$uri  = $base . '/' . $encoded_key;
		}

		return array(
			'url'           => $scheme . '://' . $host . $uri,
			'host'          => $host,
			'canonical_uri' => $uri,
		);
	}

	private function canonical_query( array $query ) {
		if ( empty( $query ) ) {
			return '';
		}

		$pairs = array();
		foreach ( $query as $name => $value ) {
			$pairs[ rawurlencode( (string) $name ) ] = rawurlencode( (string) $value );
		}
		ksort( $pairs, SORT_STRING );

		$out = array();
		foreach ( $pairs as $name => $value ) {
			$out[] = $name . '=' . $value;
		}

		return implode( '&', $out );
	}

	private function signing_key( $date ) {
		$k_date    = hash_hmac( 'sha256', $date, 'AWS4' . $this->secret_key, true );
		$k_region  = hash_hmac( 'sha256', $this->region, $k_date, true );
		$k_service = hash_hmac( 'sha256', self::SERVICE, $k_region, true );
		return hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	}

	/**
	 * @return SimpleXMLElement
	 * @throws S3BS_S3_Exception
	 */
	private function parse_xml( $body ) {
		if ( trim( $body ) === '' ) {
			throw new S3BS_S3_Exception( __( 'Risposta vuota da S3.', 's3-backup-scheduler-ai1wm' ) );
		}

		$previous = libxml_use_internal_errors( true );
		$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
		libxml_use_internal_errors( $previous );

		if ( $xml === false ) {
			throw new S3BS_S3_Exception( __( 'Risposta XML di S3 non valida.', 's3-backup-scheduler-ai1wm' ) );
		}

		return $xml;
	}

	/**
	 * @throws S3BS_S3_Exception
	 */
	private function throw_from_body( $body, $code ) {
		$s3_code = '';
		$message = '';

		if ( $body !== '' ) {
			$previous = libxml_use_internal_errors( true );
			$xml      = simplexml_load_string( $body, 'SimpleXMLElement', LIBXML_NONET );
			libxml_use_internal_errors( $previous );
			if ( $xml !== false ) {
				$s3_code = isset( $xml->Code ) ? (string) $xml->Code : '';
				$message = isset( $xml->Message ) ? (string) $xml->Message : '';
			}
		}

		$hints = array(
			'InvalidAccessKeyId'    => __( 'Access Key non valida.', 's3-backup-scheduler-ai1wm' ),
			'SignatureDoesNotMatch' => __( 'Secret Key errata o regione non corretta.', 's3-backup-scheduler-ai1wm' ),
			'NoSuchBucket'          => __( 'Il bucket indicato non esiste.', 's3-backup-scheduler-ai1wm' ),
			'NoSuchKey'             => __( 'Il file non esiste nel bucket. Se compare con "Verifica connessione", controlla che l\'endpoint sia solo l\'indirizzo del servizio (es. https://<account>.r2.cloudflarestorage.com) senza il nome del bucket in fondo.', 's3-backup-scheduler-ai1wm' ),
			'AccessDenied'          => __( 'Accesso negato: verifica i permessi IAM dell\'utente sul bucket.', 's3-backup-scheduler-ai1wm' ),
			'AuthorizationHeaderMalformed' => __( 'Regione del bucket non corretta.', 's3-backup-scheduler-ai1wm' ),
			'PermanentRedirect'     => __( 'Il bucket si trova in un\'altra regione: correggi la regione nelle impostazioni.', 's3-backup-scheduler-ai1wm' ),
		);

		$text = $message !== '' ? $message : sprintf( 'HTTP %d', $code );
		if ( $s3_code !== '' ) {
			$text = $s3_code . ': ' . $text;
			if ( isset( $hints[ $s3_code ] ) ) {
				$text .= ' — ' . $hints[ $s3_code ];
			}
		} elseif ( $code === 404 ) {
			$text = __( 'Oggetto o bucket non trovato (HTTP 404).', 's3-backup-scheduler-ai1wm' );
		} elseif ( $code === 403 ) {
			$text = __( 'Accesso negato (HTTP 403): verifica credenziali e permessi.', 's3-backup-scheduler-ai1wm' );
		} elseif ( $code === 301 ) {
			$text = __( 'Il bucket si trova in un\'altra regione (HTTP 301): correggi la regione nelle impostazioni.', 's3-backup-scheduler-ai1wm' );
		}

		throw new S3BS_S3_Exception( $text, $code, $s3_code );
	}
}
