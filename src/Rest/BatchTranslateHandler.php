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

		// Limit batch size for safety
		if ( count( $strings ) > 50 ) {
			$strings = array_slice( $strings, 0, 50 );
		}

		$translations = $this->translator->translateStrings( $strings, $targetLang );

		return $this->getResponseFactory()->createJson( [
			'translations' => $translations
		] );
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
