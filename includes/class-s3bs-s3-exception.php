<?php
defined( 'ABSPATH' ) || exit;

class S3BS_S3_Exception extends Exception {

	/** @var string */
	protected $s3_code = '';

	public function __construct( $message, $http_code = 0, $s3_code = '' ) {
		parent::__construct( $message, (int) $http_code );
		$this->s3_code = (string) $s3_code;
	}

	public function get_s3_code() {
		return $this->s3_code;
	}
}
