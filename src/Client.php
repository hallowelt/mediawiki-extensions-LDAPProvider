<?php

namespace MediaWiki\Extension\LDAPProvider;

use MediaWiki\Logger\LoggerFactory;

class Client {

	/**
	 *
	 * @var resource
	 */
	protected $connection = null;

	/**
	 *
	 * @var \$config
	 */
	protected $config = null;

	/**
	 *
	 * @var \Psr\Log\LoggerInterface
	 */
	protected $logger = null;

	/**
	 *
	 * @param \Config $config
	 */
	public function __construct( $config ) {
		$this->config = $config;
		$this->logger = LoggerFactory::getInstance( __CLASS__ );
	}

	protected function init() {
		//Already initialized?
		if( $this->connection !== null ) {
			return;
		}

		$this->initConnection();
		$this->setConnectionOptions();
		$this->maybeStartTLS();
		$this->establishBinding();
	}

	protected function initConnection() {
		MediaWiki\suppressWarnings();
		$this->connection = ldap_connect(
			$this->config->get( ClientConfig::SERVER ),
			$this->config->get( ClientConfig::PORT )
		);
		MediaWiki\restoreWarnings();
	}

	protected function setConnectionOptions() {
		ldap_set_option( $this->connection, LDAP_OPT_PROTOCOL_VERSION, 3 );
		ldap_set_option( $this->connection, LDAP_OPT_REFERRALS, 0 );

		if( $this->config->has( ClientConfig::OPTIONS ) ) {
			$options = $this->config->get( ClientConfig::OPTIONS );
			foreach ( $options  as $key => $value ) {
				$ret = ldap_set_option( $this->connection, constant( $key ), $value );
				if ( $ret === false ) {
					$message = 'Can\'t set option to LDAP connection!';
					$this->logger->debug( $message, [ $key, $value ] );
				}
			}
		}
	}

	protected function establishBinding() {
		$username = null;
		if( $this->config->has( ClientConfig::USER ) ) {
			$username = $this->config->get( ClientConfig::USER );
		}
		$password = null;
		if( $this->config->has( ClientConfig::PASSWORD) ) {
			$pssword = $this->config->get( ClientConfig::PASSWORD );
		}

		$ret = ldap_bind( $this->connection, $password, $username );
		if( $ret === false ) {
			$error = ldap_error( $this->connection );
			$errno = ldap_errno( $this->connection );
			throw new \MWException( "Could not bind to LDAP: ($errno) $error" );
		}
	}

	protected function maybeStartTLS() {
		if( $this->config->has( ClientConfig::ENC_TYPE ) ) {
			$encType = $this->config->get( ClientConfig::ENC_TYPE );
			if( $encType === EncType::TLS ) {
				$ret = ldap_start_tls( $this->connection );
				if ( $ret === false ) {
					throw new \MWException( 'Could not start TLS!' );
				}
			}
		}
	}

	/**
	 * Perform an LDAP search
	 * @param string $match desired in ldap search format
	 * @param array $attrs list of attributes to get, default to '*'
	 * @return array
	 */
	public function search( $match, $attrs = [ "*" ] ) {
		$this->init();

		wfProfileIn( __METHOD__ );
		$runTime = -microtime( true );

		$res = ldap_search(
			$this->connection,
			$this->config->get( ClientConfig::BASE_DN ),
			$match,
			$attrs
		);

		if ( !$res ) {
			wfProfileOut( __METHOD__ );
			throw new MWException(
				"Error in LDAP search: " . ldap_error( $this->connection )
			);
		}

		$entry = ldap_get_entries( $this->connection, $res );

		$runTime += microtime( true );
		wfProfileOut( __METHOD__ );
		$this->logger->debug( "Ran LDAP search for '$match' in $runTime seconds.\n" );

		return $entry;
	}
}