<?php

namespace MediaWiki\Extension\GeminiTranslator\Rest;

use MediaWiki\Extension\GeminiTranslator\PageTranslator;
use MediaWiki\Logger\LoggerFactory;
use MediaWiki\Context\RequestContext;
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
		$pageTitle = $body['pageTitle'] ?? 'Unknown Page'; // Received from JS
		$request = $this->getRequest();

		// --- LOGGING ---
		try {
			// 1. Get Real IP using Global Context (Handles Proxies/Varnish correctly)
			// This fixes the "172..." internal IP issue.
			$realIp = RequestContext::getMain()->getRequest()->getIP();
			
			// 2. Identify User
			$authority = $this->getAuthority();
			$user = $authority ? $authority->getUser() : null;

			// 3. Log to file defined in $wgDebugLogGroups['GeminiTranslator']
			// Format matches your request: "REALIP <ip> <Page Title> /<lang>"
			$message = sprintf( "REALIP %s %s /%s", $realIp, $pageTitle, $targetLang );

			LoggerFactory::getInstance( 'GeminiTranslator' )->info(
				$message,
				[
					'count' => count( $strings ),
					'user' => $user ? $user->getName() : 'Unknown',
					'user_agent' => $request->getHeader( 'User-Agent' )
				]
			);
		} catch ( \Throwable $e ) {
			// Fallback if something goes wrong with logging
			error_log( 'GeminiTranslator Logger Error: ' . $e->getMessage() );
		}

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
			
			// Log API failures
			LoggerFactory::getInstance( 'GeminiTranslator' )->error(
				'API Failure',
				[ 'error' => $e->getMessage() ]
			);

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
			'pageTitle' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => false,
				ParamValidator::PARAM_DEFAULT => 'Unknown Page',
			]
		];
	}
}
