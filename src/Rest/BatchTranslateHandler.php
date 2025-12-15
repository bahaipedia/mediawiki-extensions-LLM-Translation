<?php

namespace MediaWiki\Extension\GeminiTranslator\Rest;

use MediaWiki\Extension\GeminiTranslator\PageTranslator;
use MediaWiki\Rest\SimpleHandler;
use Wikimedia\ParamValidator\ParamValidator;

class BatchTranslateHandler extends SimpleHandler {

	private PageTranslator $translator;

	public function __construct( PageTranslator $translator ) {
		$this->translator = $translator;
	}

	public function execute() {
		$body = $this->getValidatedBody();
		$strings = $body['strings'] ?? [];
		$targetLang = $body['targetLang'];
		$pageTitle = $body['pageTitle'] ?? 'Unknown Page'; // New parameter
		$request = $this->getRequest();

		// --- IP EXTRACTION (REST API Workaround) ---
		// Since RequestInterface lacks getIP(), and we are behind Varnish/Nginx,
		// we check X-Forwarded-For first.
		$ip = $request->getHeader( 'X-Forwarded-For' );
		if ( !$ip ) {
			$serverParams = $request->getServerParams();
			$ip = $serverParams['REMOTE_ADDR'] ?? 'Unknown';
		}
		// If multiple IPs are in the chain, take the first one (usually the client)
		$ipParts = explode( ',', $ip );
		$clientIp = trim( $ipParts[0] );

		// --- LOGGING ---
		// Format: "REALIP <ip> <Page Title> /<lang>"
		$logMsg = sprintf( "REALIP %s %s /%s", $clientIp, $pageTitle, $targetLang );
		error_log( $logMsg );

		// --- PROCESSING ---

		// Limit batch size for safety
		if ( count( $strings ) > 50 ) {
			$strings = array_slice( $strings, 0, 50 );
		}

		try {
			$translations = $this->translator->translateStrings( $strings, $targetLang );
			
			return $this->getResponseFactory()->createJson( [
				'translations' => $translations
			] );

		} catch ( \RuntimeException $e ) {
			
			error_log( "GEMINI ERROR: " . $e->getMessage() );

			return $this->getResponseFactory()->createJson( [
				'error' => $e->getMessage()
			], 500 );
		}
	}

	public function getBodyParamSettings(): array {
		return [
			'strings' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'array',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'targetLang' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			// Add the new optional parameter for logging
			'pageTitle' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 'Unknown Page',
			]
		];
	}
}
