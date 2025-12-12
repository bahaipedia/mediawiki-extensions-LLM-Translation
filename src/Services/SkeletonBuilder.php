<?php

namespace MediaWiki\Extension\GeminiTranslator\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use DOMText;
use MediaWiki\Extension\GeminiTranslator\PageTranslator;

class SkeletonBuilder {

	private PageTranslator $translator;
	
	private const IGNORE_TAGS = [ 'style', 'script', 'link', 'meta' ];
	
	// Namespaces to ignore when rewriting links (we only want to translate content pages)
	private const IGNORE_NAMESPACES = [ 
		'Special:', 'File:', 'Image:', 'Help:', 'Category:', 'MediaWiki:', 'Talk:', 'User:' 
	];

	public function __construct( PageTranslator $translator ) {
		$this->translator = $translator;
	}

	public function createSkeleton( string $html, string $targetLang ): string {
		if ( trim( $html ) === '' ) {
			return '';
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		// PHASE 0: Rewrite Links (The Navigation Loop)
		$this->rewriteLinks( $dom, $targetLang );

		// PHASE 1: Harvest all text nodes
		$textNodes = []; 
		$rawStrings = [];
		
		$this->harvestNodes( $dom->documentElement, $textNodes, $rawStrings );

		// PHASE 2: Check Cache
		$cached = $this->translator->getCachedTranslations( array_unique( $rawStrings ), $targetLang );

		// PHASE 3: Apply Cache or Tokenize
		foreach ( $textNodes as $item ) {
			/** @var DOMText $node */
			$node = $item['node'];
			$text = $item['text'];
			$lSpace = $item['lSpace'];
			$rSpace = $item['rSpace'];

			if ( isset( $cached[$text] ) ) {
				$this->replaceWithText( $dom, $node, $lSpace, $cached[$text], $rSpace );
			} else {
				$this->replaceWithToken( $dom, $node, $lSpace, $text, $rSpace );
			}
		}

		return $dom->saveHTML();
	}

	/**
	 * Scans the DOM for links and points them to the /lang version
	 */
	private function rewriteLinks( DOMDocument $dom, string $lang ): void {
		$links = $dom->getElementsByTagName( 'a' );
		
		// Convert to array to avoid modification issues during iteration
		$linkList = iterator_to_array( $links );

		foreach ( $linkList as $link ) {
			/** @var DOMElement $link */
			$href = $link->getAttribute( 'href' );
			$class = $link->getAttribute( 'class' );

			// 1. Skip Red Links (Pages that don't exist in English)
			if ( strpos( $class, 'new' ) !== false ) {
				continue;
			}

			// 2. Skip External Links or Anchors
			if ( strpos( $href, '//' ) !== false || strpos( $href, '#' ) === 0 ) {
				continue;
			}

			// 3. Only rewrite standard Wiki links (/wiki/Title or /Title depending on config)
			// We look for relative paths that don't start with query strings
			if ( strpos( $href, '/index.php?' ) !== false ) {
				continue; // Skip complicated script calls
			}

			// 4. Decode URL to check Namespaces
			$decoded = urldecode( $href );
			$isSpecial = false;
			foreach ( self::IGNORE_NAMESPACES as $ns ) {
				if ( stripos( $decoded, $ns ) !== false ) {
					$isSpecial = true;
					break;
				}
			}
			if ( $isSpecial ) {
				continue;
			}

			// 5. Append Language Code
			// Logic: If href is "/wiki/Apple", become "/wiki/Apple/es"
			// Handle trailing slash edge case
			$newHref = rtrim( $href, '/' ) . '/' . $lang;
			$link->setAttribute( 'href', $newHref );
		}
	}

	private function harvestNodes( $node, array &$textNodes, array &$rawStrings ): void {
		if ( !$node ) { return; }

		// Skip References
		if ( $node instanceof DOMElement ) {
			$class = $node->getAttribute( 'class' );
			if ( 
				( $node->nodeName === 'sup' && strpos( $class, 'reference' ) !== false ) ||
				( $node->nodeName === 'span' && strpos( $class, 'mw-editsection' ) !== false ) 
			) {
				return;
			}
			if ( in_array( strtolower( $node->nodeName ), self::IGNORE_TAGS ) ) {
				$node->parentNode->removeChild( $node );
				return;
			}
		}

		$children = iterator_to_array( $node->childNodes );
		foreach ( $children as $child ) {
			if ( $child instanceof DOMText ) {
				$raw = $child->textContent;
				if ( trim( $raw ) === '' ) { continue; }
				
				$lSpace = preg_match( '/^\s+/', $raw, $m ) ? $m[0] : '';
				$rSpace = preg_match( '/\s+$/', $raw, $m ) ? $m[0] : '';
				$cleanText = trim( $raw );

				if ( strlen( $cleanText ) > 0 ) {
					$textNodes[] = [
						'node' => $child,
						'text' => $cleanText,
						'lSpace' => $lSpace,
						'rSpace' => $rSpace
					];
					$rawStrings[] = $cleanText;
				}
				continue;
			}
			if ( $child->hasChildNodes() ) {
				$this->harvestNodes( $child, $textNodes, $rawStrings );
			}
		}
	}

	private function replaceWithText( DOMDocument $dom, DOMText $originalNode, string $lSpace, string $translatedText, string $rSpace ): void {
		$parent = $originalNode->parentNode;
		$fullText = $lSpace . $translatedText . $rSpace;
		$newNode = $dom->createTextNode( $fullText );
		$parent->replaceChild( $newNode, $originalNode );
	}

	private function replaceWithToken( DOMDocument $dom, DOMText $originalNode, string $lSpace, string $text, string $rSpace ): void {
		$parent = $originalNode->parentNode;

		if ( $lSpace !== '' ) {
			$parent->insertBefore( $dom->createTextNode( $lSpace ), $originalNode );
		}

		$span = $dom->createElement( 'span' );
		$span->setAttribute( 'class', 'gemini-token' );
		$span->setAttribute( 'data-source', base64_encode( $text ) );
		$span->textContent = $text; 

		$parent->insertBefore( $span, $originalNode );

		if ( $rSpace !== '' ) {
			$parent->insertBefore( $dom->createTextNode( $rSpace ), $originalNode );
		}

		$parent->removeChild( $originalNode );
	}
}
