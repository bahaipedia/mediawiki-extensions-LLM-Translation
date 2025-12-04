<?php

namespace MediaWiki\Extension\GeminiTranslator;

use DOMDocument;
use DOMElement;
use DOMXPath;
use MediaWiki\Config\Config;
use MediaWiki\Extension\GeminiTranslator\Services\GeminiClient;
use Wikimedia\Rdbms\ILoadBalancer;
use StatusValue;

class PageTranslator {

	private GeminiClient $client;
	private ILoadBalancer $lb;
	private Config $config;

	// Tags we consider "translateable blocks"
	private const BLOCK_TAGS = [ 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'li', 'caption', 'th', 'td' ];

	public function __construct( GeminiClient $client, ILoadBalancer $lb, Config $config ) {
		$this->client = $client;
		$this->lb = $lb;
		$this->config = $config;
	}

	/**
	 * Main entry point: Takes raw HTML, translates text nodes, preserves structure.
	 * * @param string $html The HTML fragment or page content
	 * @param string $targetLang
	 * @return StatusValue Contains the translated HTML string
	 */
	public function translateHtml( string $html, string $targetLang ): StatusValue {
		if ( trim( $html ) === '' ) {
			return StatusValue::newGood( '' );
		}

		// 1. Parse HTML
		// We use libxml_use_internal_errors to suppress warnings about partial HTML fragments
		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		// Hack to force UTF-8 processing
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		$xpath = new DOMXPath( $dom );
		$nodesToTranslate = [];
		$hashes = [];
		$contentMap = [];

		// 2. Identify Blocks
		// We query for specific block tags that have text content
		$query = '//' . implode( ' | //', self::BLOCK_TAGS );
		foreach ( $xpath->query( $query ) as $index => $node ) {
			/** @var DOMElement $node */
			// Skip empty nodes or nodes usually ignored (like empty spacing)
			$content = $this->getInnerHtml( $node );
			if ( trim( strip_tags( $content ) ) === '' ) {
				continue;
			}

			// Create a hash based on the source English content
			// We strip whitespace to avoid hashing issues with indentation
			$cleanContent = trim( $content );
			$hash = hash( 'sha256', $cleanContent );

			$nodesToTranslate[$index] = $node;
			$hashes[$index] = $hash;
			$contentMap[$hash] = $cleanContent;
		}

		if ( empty( $hashes ) ) {
			return StatusValue::newGood( $html );
		}

		// 3. Check Database (Cache Hit)
		$cachedTranslations = $this->fetchFromDb( array_unique( $hashes ), $targetLang );

		// 4. Identify Missing Blocks (Cache Miss)
		$missingBlocks = [];
		foreach ( $hashes as $index => $hash ) {
			if ( !isset( $cachedTranslations[$hash] ) ) {
				// We need to send this to Gemini
				// Key by Hash to avoid duplicates in the API call
				$missingBlocks[$hash] = $contentMap[$hash];
			}
		}

		// 5. Fetch from Gemini (if necessary)
		if ( !empty( $missingBlocks ) ) {
			// Convert to indexed array for the API
			$blocksToSend = array_values( $missingBlocks );
			$hashKeys = array_keys( $missingBlocks ); // Keep track of which hash belongs to which index
			
			$apiStatus = $this->client->translateBlocks( $blocksToSend, $targetLang );
			
			if ( !$apiStatus->isOK() ) {
				return $apiStatus; // Fail gracefully
			}

			$apiResults = $apiStatus->getValue();
			$newTranslations = [];

			// Map API results back to Hashes
			foreach ( $apiResults as $i => $translatedText ) {
				if ( isset( $hashKeys[$i] ) ) {
					$h = $hashKeys[$i];
					$newTranslations[$h] = $translatedText;
					$cachedTranslations[$h] = $translatedText; // Add to current working set
				}
			}

			// 6. Save new translations to DB
			$this->saveToDb( $newTranslations, $targetLang );
		}

		// 7. Reconstruct HTML
		foreach ( $nodesToTranslate as $index => $node ) {
			$h = $hashes[$index];
			if ( isset( $cachedTranslations[$h] ) ) {
				$this->setInnerHtml( $node, $cachedTranslations[$h] );
			}
		}

		return StatusValue::newGood( $dom->saveHTML() );
	}

	/**
	 * Helper to get inner HTML of a DOMNode
	 */
	private function getInnerHtml( DOMElement $element ): string {
		$innerHTML = "";
		foreach ( $element->childNodes as $child ) {
			$innerHTML .= $element->ownerDocument->saveHTML( $child );
		}
		return $innerHTML;
	}

	/**
	 * Helper to replace inner HTML of a DOMNode
	 * This is tricky because loading a fragment can create new <html><body> tags we don't want
	 */
	private function setInnerHtml( DOMElement $element, string $html ): void {
		// Clear existing children
		while ( $element->hasChildNodes() ) {
			$element->removeChild( $element->firstChild );
		}

		// Create a temporary doc to parse the new fragment
		$tempDoc = new DOMDocument();
		// Hack for UTF-8 again
		@$tempDoc->loadHTML( '<?xml encoding="utf-8" ?><div>' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		
		// Import nodes to main document
		foreach ( $tempDoc->getElementsByTagName('div')->item(0)->childNodes as $node ) {
			$importedNode = $element->owner
