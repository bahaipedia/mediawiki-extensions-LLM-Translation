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
		$request = $this->getRequest();
		$body = $this->getValidatedBody();
		$strings = $body['strings'] ?? [];
		$targetLang = $body['targetLang'];

		// --- LOGGING ---
		// Using wfDebugLog to match $wgDebugLogGroups['GeminiTranslator']
		$logData = [
			'event' => 'batch_start',
			'ip' => $request->getIP(),
			'target_lang' => $targetLang,
			'count' => count( $strings ),
			'user_agent' => $request->getHeader( 'User-Agent' )
		];
		wfDebugLog( 'GeminiTranslator', json_encode( $logData ) );

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
			
			wfDebugLog( 'GeminiTranslator', 'ERROR: ' . $e->getMessage() );

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
