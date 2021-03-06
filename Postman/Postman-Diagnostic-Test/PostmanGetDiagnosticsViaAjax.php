<?php
/**
 *
 * @author jasonhendriks
 */
class PostmanGetDiagnosticsViaAjax {
	private $diagnostics;
	private $options;
	private $authorizationToken;
	/**
	 * Constructor
	 */
	function __construct() {
		$this->options = PostmanOptions::getInstance();
		$this->authorizationToken = PostmanOAuthToken::getInstance();
		$this->diagnostics = '';
		PostmanUtils::registerAjaxHandler( 'postman_diagnostics', $this, 'getDiagnostics' );
	}
	/**
	 * @param false|null|string $data
	 */
	private function addToDiagnostics( string $header, $data ): void {
		if ( isset( $data ) ) {
			$this->diagnostics .= sprintf( '%s: %s%s', $header, $data, PHP_EOL );
		}
	}
	/**
	 * @return string
	 */
	private function getActivePlugins() {
		// from http://stackoverflow.com/questions/20488264/how-do-i-get-activated-plugin-list-in-wordpress-plugin-development
		$apl = get_option( 'active_plugins' );
		$plugins = get_plugins();
		$pluginText = array();
		foreach ( $apl as $p ) {
			if ( isset( $plugins [ $p ] ) ) {
				$pluginText[] = $plugins [ $p ] ['Name'];
			}
		}
		return implode( ', ', $pluginText );
	}
	/**
	 * @return string
	 */
	private function getPhpDependencies() {
		$apl = PostmanPreRequisitesCheck::getState();
		$pluginText = array();
		foreach ( $apl as $p ) {
			$pluginText[] = $p ['name'] . '=' . ($p ['ready'] ? 'Yes' : 'No');
		}
		return implode( ', ', $pluginText );
	}
	private function getTransports(): string {
		$transports = '';
		foreach ( PostmanTransportRegistry::getInstance()->getTransports() as $transport ) {
			$transports .= ' : ' . $transport->getName();
		}
		return $transports;
	}

	/**
	 * Diagnostic Data test to current SMTP server
	 *
	 * @return string
	 */
	private function testConnectivity( PostmanModuleTransport $transport ) {
		$hostname = $transport->getHostname();
		$port = $transport->getPort();
		if ( ! empty( $hostname ) && ! empty( $port ) ) {
			$portTest = new PostmanPortTest( $transport->getHostname(), $transport->getPort() );
			$result = $portTest->genericConnectionTest();
			if ( $result ) {
				return 'Yes';
			} else {
				return 'No';
			}
		}
		return 'n/a';
	}

	/**
	 * 	 * Inspects the $wp_filter variable and returns the plugins attached to it
	 * 	 * From: http://stackoverflow.com/questions/5224209/wordpress-how-do-i-get-all-the-registered-functions-for-the-content-filter
	 *
	 * @return null|string
	 */
	private function getFilters( string $hook = '' ) {
		global $wp_filter;
		if ( empty( $hook ) || ! isset( $wp_filter [ $hook ] ) ) {
			return null; }
		$functionArray = array();
		foreach ( $wp_filter [ $hook ] as $functions ) {
			foreach ( $functions as $function ) {
				$thing = $function ['function'];
				if ( is_array( $thing ) ) {
					$name = get_class( $thing [0] ) . '->' . $thing [1];
					$functionArray[] = $name;
				} else {
					$functionArray[] = $thing;
				}
			}
		}
		return implode( ', ', $functionArray );
	}

	public function getDiagnostics(): void {
	    $curl = curl_version();
		$transportRegistry = PostmanTransportRegistry::getInstance();
        $this->addToDiagnostics( 'Mailer', PostmanOptions::getInstance()->getSmtpMailer() );
		$this->addToDiagnostics( 'HostName', PostmanUtils::getServerName() );
		$this->addToDiagnostics( 'cURL Version', $curl['version'] );
		$this->addToDiagnostics( 'OpenSSL Version', $curl['ssl_version'] );
		$this->addToDiagnostics( 'OS', php_uname() );
		$this->addToDiagnostics( 'PHP', PHP_OS . ' ' . PHP_VERSION . ' ' . setlocale( LC_CTYPE, "0" ) );
		$this->addToDiagnostics( 'PHP Dependencies', $this->getPhpDependencies() );
		$this->addToDiagnostics( 'WordPress', (is_multisite() ? 'Multisite ' : '') . get_bloginfo( 'version' ) . ' ' . get_locale() . ' ' . get_bloginfo( 'charset', 'display' ) );
		$this->addToDiagnostics( 'WordPress Theme', wp_get_theme() );
		$this->addToDiagnostics( 'WordPress Plugins', $this->getActivePlugins() );

        apply_filters( 'postman_wp_mail_bind_status', null );
        $wp_mail_file_name = 'n/a';
		if ( class_exists( 'ReflectionFunction' ) ) {
			$wp_mail = new ReflectionFunction( 'wp_mail' );
			$wp_mail_file_name = realpath( $wp_mail->getFileName() );
		}

		$this->addToDiagnostics( 'WordPress wp_mail Owner', $wp_mail_file_name );
		$this->addToDiagnostics( 'WordPress wp_mail Filter(s)', $this->getFilters( 'wp_mail' ) );
		$this->addToDiagnostics( 'WordPress wp_mail_from Filter(s)', $this->getFilters( 'wp_mail_from' ) );
		$this->addToDiagnostics( 'WordPress wp_mail_from_name Filter(s)', $this->getFilters( 'wp_mail_from_name' ) );
		$this->addToDiagnostics( 'WordPress wp_mail_content_type Filter(s)', $this->getFilters( 'wp_mail_content_type' ) );
		$this->addToDiagnostics( 'WordPress wp_mail_charset Filter(s)', $this->getFilters( 'wp_mail_charset' ) );
		$this->addToDiagnostics( 'WordPress phpmailer_init Action(s)', $this->getFilters( 'phpmailer_init' ) );
		$pluginData = apply_filters( 'postman_get_plugin_metadata', null );
		$this->addToDiagnostics( 'Postman', $pluginData ['version'] );
		{
			$s1 = $this->options->getEnvelopeSender();
			$s2 = $this->options->getMessageSenderEmail();
		if ( ! empty( $s1 ) || ! empty( $s2 ) ) {
			$this->addToDiagnostics( 'Postman Sender Domain (Envelope|Message)', ($hostname = substr( strrchr( $this->options->getEnvelopeSender(), '@' ), 1 )) . ' | ' . ($hostname = substr( strrchr( $this->options->getMessageSenderEmail(), '@' ), 1 )) );
		}
		}
		$this->addToDiagnostics( 'Postman Prevent Message Sender Override (Email|Name)', ($this->options->isSenderEmailOverridePrevented() ? 'Yes' : 'No') . ' | ' . ($this->options->isSenderNameOverridePrevented() ? 'Yes' : 'No') );
		{
			// status of the active transport
			$transport = $transportRegistry->getActiveTransport();
			$this->addToDiagnostics( 'Postman Active Transport', sprintf( '%s (%s)', $transport->getName(), $transportRegistry->getPublicTransportUri( $transport ) ) );
			$this->addToDiagnostics( 'Postman Active Transport Status (Ready|Connected)', ($transport->isConfiguredAndReady() ? 'Yes' : 'No') . ' | ' . ($this->testConnectivity( $transport )) );
		}
		if ( $transportRegistry->getActiveTransport() != $transportRegistry->getSelectedTransport() && $transportRegistry->getSelectedTransport() != null ) {
			// status of the selected transport
			$transport = $transportRegistry->getSelectedTransport();
			$this->addToDiagnostics( 'Postman Selected Transport', sprintf( '%s (%s)', $transport->getName(), $transportRegistry->getPublicTransportUri( $transport ) ) );
			$this->addToDiagnostics( 'Postman Selected Transport Status (Ready|Connected)', ($transport->isConfiguredAndReady() ? 'Yes' : 'No') . ' | ' . ($this->testConnectivity( $transport )) );
		}
		$this->addToDiagnostics( 'Postman Deliveries (Success|Fail)', (PostmanState::getInstance()->getSuccessfulDeliveries()) . ' | ' . (PostmanState::getInstance()->getFailedDeliveries()) );
		if ( $this->options->getConnectionTimeout() != PostmanOptions::DEFAULT_TCP_CONNECTION_TIMEOUT || $this->options->getReadTimeout() != PostmanOptions::DEFAULT_TCP_READ_TIMEOUT ) {
			$this->addToDiagnostics( 'Postman TCP Timeout (Connection|Read)', $this->options->getConnectionTimeout() . ' | ' . $this->options->getReadTimeout() );
		}
		if ( $this->options->isMailLoggingEnabled() != PostmanOptions::DEFAULT_MAIL_LOG_ENABLED || $this->options->getMailLoggingMaxEntries() != PostmanOptions::DEFAULT_MAIL_LOG_ENTRIES || $this->options->getTranscriptSize() != PostmanOptions::DEFAULT_TRANSCRIPT_SIZE ) {
			$this->addToDiagnostics( 'Postman Email Log (Enabled|Limit|Transcript Size)', ($this->options->isMailLoggingEnabled() ? 'Yes' : 'No') . ' | ' . $this->options->getMailLoggingMaxEntries() . ' | ' . $this->options->getTranscriptSize() );
		}
		$this->addToDiagnostics( 'Postman Run Mode', $this->options->getRunMode() == PostmanOptions::RUN_MODE_PRODUCTION ? null : $this->options->getRunMode() );
		$this->addToDiagnostics( 'Postman PHP LogLevel', $this->options->getLogLevel() == PostmanLogger::ERROR_INT ? null : $this->options->getLogLevel() );
		$this->addToDiagnostics( 'Postman Stealth Mode', $this->options->isStealthModeEnabled() ? 'Yes' : null );
		$this->addToDiagnostics( 'Postman File Locking (Enabled|Temp Dir)', PostmanState::getInstance()->isFileLockingEnabled() ? null : 'No' . ' | ' . $this->options->getTempDirectory() );
		$response = array(
				'message' => $this->diagnostics,
		);
		wp_send_json_success( $response );
	}
}