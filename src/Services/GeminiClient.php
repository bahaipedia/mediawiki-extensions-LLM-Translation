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
			return StatusValue::newFatal( 'geminitranslator-error-no-api-key' );
		}

		if ( empty( $blocks ) ) {
			return StatusValue::newGood( [] );
		}

		// 1. Prepare Referer
		$referer = $this->serverUrl;
		if ( strpos( $referer, '//' ) === 0 ) {
			$referer = 'https:' . $referer;
		}
		$referer = rtrim( $referer, '/' ) . '/';

		// 2. Logging
		error_log( sprintf( "GEMINI CLIENT: Requesting %d blocks for %s. Referer: %s", count($blocks), $targetLang, $referer ) );

		// 3. Prepare Payload
		$promptParts = [];
		$promptParts[] = "You are a professional translator. Translate the following array of text strings into language code '{$targetLang}'.";
		$promptParts[] = "Do not translate proper nouns or technical terms if inappropriate.";
		$promptParts[] = "Return ONLY a JSON array of strings. No markdown formatting.";
		$promptParts[] = "Input:";
		$promptParts[] = json_encode( $blocks, JSON_UNESCAPED_UNICODE );

		$url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key={$this->apiKey}";
		
		$payloadData = [
			'contents' => [
				[ 'parts' => [ [ 'text' => implode( "\n", $promptParts ) ] ] ]
			]
		];

		$jsonBody = json_encode( $payloadData, JSON_UNESCAPED_UNICODE );

		$req = $this->httpFactory->create( $url, [ 
			'method' => 'POST', 
			'postData' => $jsonBody,
			'timeout' => 120
		], __METHOD__ );
		
		$req->setHeader( 'Content-Type', 'application/json' );
		$req->setHeader( 'Referer', $referer );

		$status = $req->execute();

		if ( !$status->isOK() ) {
			$msg = $status->getErrors()[0]['message'] ?? 'Unknown';
			error_log( "GEMINI CLIENT ERROR: HTTP $msg" );
			return StatusValue::newFatal( 'geminitranslator-ui-error', $status->getErrors() );
		}

		$result = json_decode( $req->getContent(), true );
		
		if ( isset( $result['error'] ) ) {
			error_log( "GEMINI CLIENT ERROR: API " . print_r( $result['error'], true ) );
			return StatusValue::newFatal( 'geminitranslator-ui-error' );
		}
		
		if ( isset( $result['candidates'][0]['content']['parts'][0]['text'] ) ) {
			$rawText = $result['candidates'][0]['content']['parts'][0]['text'];
			$rawText = str_replace( [ '```json', '```' ], '', $rawText );
			$translatedBlocks = json_decode( trim( $rawText ), true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $translatedBlocks ) ) {
				return StatusValue::newGood( $translatedBlocks );
			} else {
				error_log( "GEMINI CLIENT ERROR: Bad JSON. Raw: " . substr( $rawText, 0, 50 ) );
			}
		}

		return StatusValue::newFatal( 'geminitranslator-error-bad-response' );
	}
}
