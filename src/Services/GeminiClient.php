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

		// 3. Prepare Payload (STRICTER PROMPT)
		$promptParts = [];
		$promptParts[] = "You are a strict translation engine. Translate the text content of the following array into language code '{$targetLang}'.";
		$promptParts[] = "STRICT RULES:";
		$promptParts[] = "1. PRESERVE HTML: Do not remove, move, or modify HTML tags (like <a href...>, <b>, <i>). Keep them exactly around the equivalent words.";
		$promptParts[] = "2. NO HALLUCINATIONS: Do not add any new sentences, explanations, or context. Translate ONLY what is provided.";
		$promptParts[] = "3. NO EXPANSION: Do not expand the scope of links. If a link covers one word in the source, it must cover only that word in the translation.";
		$promptParts[] = "4. OUTPUT FORMAT: Return ONLY a valid raw JSON array of strings. No Markdown formatting (no ```json).";
		$promptParts[] = "5. INTEGRITY: The output array must have exactly the same number of elements as the input.";
		$promptParts[] = "Input JSON:";
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
			// Cleanup Markdown if Gemini ignores the rule
			$rawText = str_replace( [ '```json', '```' ], '', $rawText );
			$translatedBlocks = json_decode( trim( $rawText ), true );

			if ( json_last_error() === JSON_ERROR_NONE && is_array( $translatedBlocks ) ) {
				return StatusValue::newGood( $translatedBlocks );
			} else {
				error_log( "GEMINI CLIENT ERROR: Bad JSON. Raw: " . substr( $rawText, 0, 100 ) );
			}
		}

		return StatusValue::newFatal( 'geminitranslator-error-bad-response' );
	}
}
