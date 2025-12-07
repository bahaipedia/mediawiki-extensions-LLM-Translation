<?php

namespace MediaWiki\Extension\GeminiTranslator\Hook;

use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Output\OutputPage;
use MediaWiki\MediaWikiServices;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Context\RequestContext;

class VirtualPageDisplay implements BeforeInitializeHook {

	private RevisionLookup $revisionLookup;
	private TitleFactory $titleFactory;

	public function __construct( RevisionLookup $revisionLookup, TitleFactory $titleFactory ) {
		$this->revisionLookup = $revisionLookup;
		$this->titleFactory = $titleFactory;
	}

	public function onBeforeInitialize( $title, $article, $output, $user, $request, $mediaWiki ): void {
		$text = $title->getText();
		error_log( "GEMINI HOOK: Checking page '$text'" );

		// 1. Only run in Main Namespace (0)
		if ( $title->getNamespace() !== NS_MAIN ) {
			error_log( "GEMINI HOOK: Skipping - Not in Main Namespace (" . $title->getNamespace() . ")" );
			return;
		}

		// 2. If the page actually exists, let MediaWiki handle it.
		if ( $title->exists() ) {
			error_log( "GEMINI HOOK: Skipping - Page actually exists" );
			return;
		}

		// 3. Manual Subpage Detection
		$lastSlash = strrpos( $text, '/' );
		if ( $lastSlash === false ) {
			error_log( "GEMINI HOOK: Skipping - No slash found in title" );
			return; 
		}

		// Extract parts
		$baseText = substr( $text, 0, $lastSlash );
		$langCode = substr( $text, $lastSlash + 1 );
		error_log( "GEMINI HOOK: Base: '$baseText', Lang: '$langCode'" );

		// 4. Validate Language Code
		$len = strlen( $langCode );
		$isValidLang = ( $len >= 2 && $len <= 3 ) || in_array( strtolower($langCode), [ 'zh-cn', 'zh-tw', 'pt-br' ] );
		
		if ( !$isValidLang ) {
			error_log( "GEMINI HOOK: Skipping - Invalid lang code" );
			return;
		}

		// 5. Check if Parent exists
		$parentTitle = Title::newFromText( $baseText );
		
		if ( !$parentTitle || !$parentTitle->exists() ) {
			error_log( "GEMINI HOOK: Skipping - Parent '$baseText' does not exist" );
			return;
		}

		error_log( "GEMINI HOOK: MATCH! Hijacking display..." );

		// 6. Hijack the Display
		$this->renderVirtualPage( $output, $parentTitle, $langCode, $title );
	}

	private function renderVirtualPage( OutputPage $output, Title $parent, string $lang, Title $fullTitle ): void {
		$output->setPageTitle( $fullTitle->getText() );
		$output->setArticleFlag( false ); 
		$output->addBodyClasses( 'gemini-virtual-page' );
		$output->addModules( [ 'ext.geminitranslator.bootstrap' ] );
		$output->addInlineStyle( '.noarticletext { display: none !important; }' );

		// 1. Get Parent Revision
		$rev = $this->revisionLookup->getRevisionByTitle( $parent );
		if ( !$rev ) { 
			error_log( "GEMINI HOOK: Parent has no revision?" );
			return; 
		}
		error_log( "GEMINI HOOK: Parent Rev ID: " . $rev->getId() );

		// 2. Parse Full Content
		// We use 'main' slot which contains the whole page wikitext
		$content = $rev->getContent( 'main' );
		
		$skeletonHtml = '';
		if ( $content ) {
			$services = MediaWikiServices::getInstance();
			$parser = $services->getParser();
			$popts = ParserOptions::newFromContext( RequestContext::getMain() );
			
			// PARSE FULL PAGE
			$parseOut = $parser->parse( $content->getText(), $parent, $popts, true );
			
			// Transform to Skeleton (passing language code for cache lookup)
			$builder = $services->getService( 'GeminiTranslator.SkeletonBuilder' );
			$skeletonHtml = $builder->createSkeleton( $parseOut->getText(), $lang );
		}

		// 3. Output HTML
		$html = '<div class="gemini-virtual-container">';
		$html .= '<div class="mw-message-box mw-message-box-notice">';
		$html .= '<strong>Translated Content:</strong> This page is a real-time translation of <a href="' . $parent->getLinkURL() . '">' . $parent->getText() . '</a>.';
		$html .= '</div>';
		$html .= '<div id="gemini-virtual-content" style="margin-top: 20px;">';
		
		if ( empty( $skeletonHtml ) ) {
			$html .= '<div class="gemini-loading">Loading...</div>';
		} else {
			$html .= $skeletonHtml;
		}
		
		$html .= '</div></div>';

		$output->addHTML( $html );
		
		$output->addJsConfigVars( [
			'wgGeminiParentRevId' => $rev->getId(),
			'wgGeminiTargetLang' => $lang
		] );

		$request = $output->getRequest();
		$request->setVal( 'action', 'view' );
		error_log( "GEMINI HOOK: Output injected." );
	}
}
