<?php

namespace MediaWiki\Extension\GeminiTranslator\Services;

use DOMDocument;
use DOMElement;
use DOMXPath;
use DOMText;

class SkeletonBuilder {

	private const IGNORE_TAGS = [ 'style', 'script', 'link', 'meta' ];
	
	// We will traverse these but not tokenize the tags themselves
	private const BLOCK_TAGS = [ 'div', 'p', 'table', 'tbody', 'tr', 'td', 'ul', 'ol', 'li', 'blockquote', 'section' ];

	public function createSkeleton( string $html ): string {
		if ( trim( $html ) === '' ) {
			return '';
		}

		libxml_use_internal_errors( true );
		$dom = new DOMDocument();
		$dom->loadHTML( '<?xml encoding="utf-8" ?>' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
		libxml_clear_errors();

		// We removed the code that deletes references.
		// Now we just traverse the tree.
		$this->processNode( $dom, $dom->documentElement );

		return $dom->saveHTML();
	}

	private function processNode( DOMDocument $dom, $node ): void {
		if ( !$node ) { return; }

		// SKIP REFERENCES: If this is a reference tag, leave it alone (don't tokenize children)
		if ( $node instanceof DOMElement ) {
			$class = $node->getAttribute( 'class' );
			if ( 
				( $node->nodeName === 'sup' && strpos( $class, 'reference' ) !== false ) ||
				( $node->nodeName === 'span' && strpos( $class, 'mw-editsection' ) !== false ) 
			) {
				return; // Stop traversing this branch. The node remains in the DOM, untranslated.
			}
			
			// Remove garbage tags completely
			if ( in_array( strtolower( $node->nodeName ), self::IGNORE_TAGS ) ) {
				$node->parentNode->removeChild( $node );
				return;
			}
		}

		$children = iterator_to_array( $node->childNodes );
		foreach ( $children as $child ) {
			// Handle Text Nodes
			if ( $child instanceof DOMText ) {
				$raw = $child->textContent;
				if ( trim( $raw ) === '' ) { continue; }
				
				// Identify whitespace to preserve
				$lSpace = preg_match( '/^\s+/', $raw, $m ) ? $m[0] : '';
				$rSpace = preg_match( '/\s+$/', $raw, $m ) ? $m[0] : '';
				$cleanText = trim( $raw );

				if ( strlen( $cleanText ) > 0 ) {
					$this->wrapTextNode( $dom, $child, $lSpace, $cleanText, $rSpace );
				}
				continue;
			}

			// Recurse
			if ( $child->hasChildNodes() ) {
				$this->processNode( $dom, $child );
			}
		}
	}

	private function wrapTextNode( DOMDocument $dom, DOMText $originalNode, string $lSpace, string $text, string $rSpace ): void {
		$parent = $originalNode->parentNode;

		if ( $lSpace !== '' ) {
			$parent->insertBefore( $dom->createTextNode( $lSpace ), $originalNode );
		}

		$span = $dom->createElement( 'span' );
		$span->setAttribute( 'class', 'gemini-token' );
		$span->setAttribute( 'data-source', base64_encode( $text ) );
		// Visual style for skeleton
		$span->setAttribute( 'style', 'background-color: #f8f9fa; color: transparent; border-bottom: 2px solid #eaecf0; transition: all 0.5s ease;' );
		$span->textContent = $text; 

		$parent->insertBefore( $span, $originalNode );

		if ( $rSpace !== '' ) {
			$parent->insertBefore( $dom->createTextNode( $rSpace ), $originalNode );
		}

		$parent->removeChild( $originalNode );
	}
}
