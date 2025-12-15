<?php

namespace MediaWiki\Extension\GeminiTranslator\Rest;

use MediaWiki\Extension\GeminiTranslator\PageTranslator;
use MediaWiki\Logger\LoggerFactory;
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

		// --- 1. Calculate Usage Metrics ---
		$totalChars = 0;
		foreach ( $strings as $str ) {
			$totalChars += mb_strlen( $str );
		}
		
		$request = $this->getRequest();
		$ip = $request->getIP();
		$userAgent = $request->getHeader( 'User-Agent' );

		// --- 2. Log the Request ---
		// This logs to the 'GeminiTranslator' channel.
		LoggerFactory::getInstance( 'GeminiTranslator' )->info(
			'Translation request processing',
			[
				'ip' => $ip,
				'target_lang' => $targetLang,
				'string_count' => count( $strings ),
				'total_chars' => $totalChars,
				'user_agent' => $userAgent,
				'user_id' => $this->getAuthority()->getUser()->getId(), // 0 if anonymous
				'user_name' => $this->getAuthority()->getUser()->getName(),
			]
		);

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
			// Log the error specifically
			LoggerFactory::getInstance( 'GeminiTranslator' )->error(
				'Translation failed',
				[
					'ip' => $ip,
					'error' => $e->getMessage()
				]
			);

			// Return a 500 error so the JS .fail() block triggers
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
			]
		];
	}
}
