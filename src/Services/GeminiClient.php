<?php

namespace MediaWiki\Extension\GeminiTranslator\Services;

use MediaWiki\Http\HttpRequestFactory;
use StatusValue;

class GeminiClient {
	private string $apiKey;
	private string $model;
	private HttpRequestFactory $httpFactory;

	public function __construct( string $apiKey, string $model, HttpRequestFactory $httpFactory ) {
		$this->apiKey = $apiKey;
		$this->model = $model;
		$this->httpFactory = $httpFactory;
	}

	/**
	 * Translates an array of text blocks.
	 * @param array $blocks Array of strings (HTML/Text) to translate
	 * @param string $targetLang
	 * @return StatusValue Returns [ 'original_index' => 'translated_text' ]
	 */
	public function translateBlocks( array $blocks, string $targetLang ): StatusValue {
		if ( empty( $this->apiKey ) ) {
			return StatusValue::newFatal( 'geminitranslator-error-no-api-key' );
		}

		if ( empty( $blocks ) ) {
			return StatusValue::newGood( [] );
		}

		// Prepare the Prompt
		// We use a structured prompt to ensure Gemini returns a JSON array of translations
		// that maps 1:1 to the input blocks.
		$promptParts = [];
		$promptParts[] = "You are a professional translator for an encyclopedia. Translate the following HTML blocks into language code '{$targetLang}'.";
		$promptParts[] = "Maintain all HTML tags, classes, and structure exactly. Only translate the human-readable text content.";
		$promptParts[] = "Return the response strictly as a JSON array of strings. Do not include markdown formatting (like ```json).";
		$promptParts[] = "Input Blocks:";
		$promptParts[] = json_encode( $blocks );

		$url = "[https://generativelanguage.googleapis.com/v1beta/models/](https://generativelanguage.googleapis.com/v1beta/models/){$this->model}:generateContent?key={$this->apiKey}";
		
		$payload = [
			'contents' => [
				[
					'parts' => [
						[ 'text' => implode( "\n", $promptParts ) ]
					]
				]
			]
		];

		$req = $this->httpFactory->create( $url, [ 'method' => 'POST' ], __METHOD__ );
		$req->setBody( json_encode( $payload ) );
		$req->setHeader( 'Content-Type', 'application/json' );

		$status = $req->execute();

		if ( !$status->isOK() ) {
			return $status;
		}

		$result = json_decode( $req->getContent(), true );
		
		// Parsing Gemini Response
		// Note: Robust error handling needed here for real production (checking candidates, safety ratings)
		if ( isset( $result['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$rawText = $result['candidates'][0]['content']['parts'][0]['text'];
			// Strip possible markdown code blocks if Gemini ignores our system instruction
			$rawText = str_replace( [ '```json', '```' ], '', $rawText );
			$translatedBlocks = json_decode( trim( $rawText ), true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $translatedBlocks ) ) {
				// Map back to original keys if necessary, strictly assuming order is preserved
				return StatusValue::newGood( $translatedBlocks );
			}
		}

		return StatusValue::newFatal( 'geminitranslator-error-bad-response' );
	}
}
