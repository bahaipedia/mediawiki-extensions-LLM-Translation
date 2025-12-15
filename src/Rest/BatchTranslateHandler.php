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
		$pageTitle = $body['pageTitle'] ?? 'Unknown Page'; 
		$request = $this->getRequest();

		// --- LOGGING ---
		try {
			// 1. Get Real IP 
			$realIp = RequestContext::getMain()->getRequest()->getIP();
			
			// 2. Identify User
			$authority = $this->getAuthority();
			$user = $authority ? $authority->getUser() : null;

			// 3. Log to file
			// Clean format: "76.133.12.56 Page/Name/en"
			$message = sprintf( "%s %s", $realIp, $pageTitle );

			LoggerFactory::getInstance( 'GeminiTranslator' )->info(
				$message,
				[
					'count' => count( $strings ),
					'user' => $user ? $user->getName() : 'Unknown',
					'user_agent' => $request->getHeader( 'User-Agent' )
				]
			);
		} catch ( \Throwable $e ) {
			error_log( 'GeminiTranslator Logger Error: ' . $e->getMessage() );
		}

		// --- PROCESSING ---

		if ( count( $strings ) > 50 ) {
			$strings = array_slice( $strings, 0, 50 );
		}

		try {
			$translations = $this->translator->translateStrings( $strings, $targetLang );
			
			return $this->getResponseFactory()->createJson( [
				'translations' => $translations
			] );

		} catch ( \RuntimeException $e ) {
			
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
