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
		$req->setData( json_encode( $payload ) );
		$req->setHeader( 'Content-Type', 'application/json' );
		
		// CRITICAL FIX: Send a Referer header to satisfy Google API Key "Website Restrictions"
		// Since this runs on the server, we manually set it to a domain allowed by your key.
		// Using the base domain allows it to work across all subdomains (es., de., etc).
		$req->setHeader( 'Referer', '[https://bahaipedia.org/](https://bahaipedia.org/)' );

		$status = $req->execute();

		if ( !$status->isOK() ) {
			// Return the actual error from Google for debugging
			return StatusValue::newFatal( 'geminitranslator-ui-error', $status->getErrors() );
		}

		$result = json_decode( $req->getContent(), true );
		
		if ( isset( $result['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$rawText = $result['candidates'][0]['content']['parts'][0]['text'];
			$rawText = str_replace( [ '```json', '```' ], '', $rawText );
			$translatedBlocks = json_decode( trim( $rawText ), true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $translatedBlocks ) ) {
				return StatusValue::newGood( $translatedBlocks );
			}
		}

		return StatusValue::newFatal( 'geminitranslator-error-bad-response' );
	}
}
