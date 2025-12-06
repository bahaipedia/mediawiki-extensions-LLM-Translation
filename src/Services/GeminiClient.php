<?php

namespace MediaWiki\Extension\GeminiTranslator\Services;

use MediaWiki\Http\HttpRequestFactory;
use StatusValue;

class GeminiClient {
	private string $apiKey;
	private string $model;
	private HttpRequestFactory $httpFactory;
	private string $serverUrl;

	public function __construct( string $apiKey, string $model, HttpRequestFactory $httpFactory, string $serverUrl ) {
		$this->apiKey = $apiKey;
		$this->model = $model;
		$this->httpFactory = $httpFactory;
		$this->serverUrl = $serverUrl;
	}

	public function translateBlocks( array $blocks, string $targetLang ): StatusValue {
		if ( empty( $this->apiKey ) ) {
			error_log("GEMINI CLIENT: No API Key configured.");
			return StatusValue::newFatal( 'geminitranslator-error-no-api-key' );
		}

		if ( empty( $blocks ) ) {
			return StatusValue::newGood( [] );
		}

		error_log("GEMINI CLIENT: Sending " . count($blocks) . " blocks to translate into '$targetLang'.");

		$promptParts = [];
		$promptParts[] = "You are a professional translator. Translate the following array of text strings into language code '{$targetLang}'.";
		$promptParts[] = "Do not translate proper nouns or technical terms if inappropriate.";
		$promptParts[] = "Return ONLY a JSON array of strings. No markdown formatting.";
		$promptParts[] = "Input:";
		$promptParts[] = json_encode( $blocks );

		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
		
		$payload = [
			'contents' => [
				[ 'parts' => [ [ 'text' => implode( "\n", $promptParts ) ] ] ]
			]
		];

		$req = $this->httpFactory->create( $url, [ 'method' => 'POST', 'postData' => json_encode( $payload ) ], __METHOD__ );
		
		$req->setHeader( 'Content-Type', 'application/json' );
		
		// DYNAMIC REFERER: Uses the actual wiki URL (e.g., https://vi.bahaipedia.org)
		// This ensures it matches the *.bahaipedia.org restriction.
		$req->setHeader( 'Referer', $this->serverUrl . '/' );

		$status = $req->execute();

		if ( !$status->isOK() ) {
			error_log("GEMINI CLIENT: HTTP Error: " . print_r($status->getErrors(), true));
			return StatusValue::newFatal( 'geminitranslator-ui-error', $status->getErrors() );
		}

		$result = json_decode( $req->getContent(), true );
		
		if ( isset( $result['error'] ) ) {
			error_log("GEMINI CLIENT: API Error: " . print_r($result['error'], true));
			return StatusValue::newFatal( 'geminitranslator-ui-error' );
		}
		
		if ( isset( $result['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$rawText = $result['candidates'][0]['content']['parts'][0]['text'];
			$rawText = str_replace( [ '```json', '```' ], '', $rawText );
			$translatedBlocks = json_decode( trim( $rawText ), true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $translatedBlocks ) ) {
				error_log("GEMINI CLIENT: Success! Received " . count($translatedBlocks) . " strings.");
				return StatusValue::newGood( $translatedBlocks );
			} else {
				error_log("GEMINI CLIENT: JSON Decode Error: " . substr($rawText, 0, 100) . "...");
			}
		} else {
			error_log("GEMINI CLIENT: Unexpected response structure.");
		}

		return StatusValue::newFatal( 'geminitranslator-error-bad-response' );
	}
}
