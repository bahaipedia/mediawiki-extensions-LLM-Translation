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
		// 1. Only run in Main Namespace (0)
		if ( $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		// 2. If the page actually exists, let MediaWiki handle it.
		if ( $title->exists() ) {
			return;
		}

		// 3. Manual Subpage Detection (Fixes the issue if wgNamespacesWithSubpages is off)
		$text = $title->getText();
		$lastSlash = strrpos( $text, '/' );
		
		if ( $lastSlash === false ) {
			return; // No slash found
		}

		// Extract parts based on string position
		$baseText = substr( $text, 0, $lastSlash );
		$langCode = substr( $text, $lastSlash + 1 );

		// 4. Validate Language Code (2-3 chars, or specific variants)
		// We accept 2 chars (en, es), 3 chars (ast), or specific dashed codes (pt-br)
		$len = strlen( $langCode );
		$isValidLang = ( $len >= 2 && $len <= 3 ) || in_array( strtolower($langCode), [ 'zh-cn', 'zh-tw', 'pt-br' ] );
		
		if ( !$isValidLang ) {
			return;
		}

		// 5. Check if Parent exists
		// We create a Title object for the text "before the slash"
		$parentTitle = Title::newFromText( $baseText );
		
		if ( !$parentTitle || !$parentTitle->exists() ) {
			return;
		}

		// 6. Hijack the Display
		$this->renderVirtualPage( $output, $parentTitle, $langCode, $title );
	}

	private function renderVirtualPage( OutputPage $output, Title $parent, string $lang, Title $fullTitle ): void {
		$output->setPageTitle( $fullTitle->getText() );
		$output->setArticleFlag( false ); // Suppress "missing article" error
		$output->addBodyClasses( 'gemini-virtual-page' );
		$output->addModules( [ 'ext.geminitranslator.bootstrap' ] );

		// 1. Get Parent Revision
		$rev = $this->revisionLookup->getRevisionByTitle( $parent );
		if ( !$rev ) { return; }

		// 2. Parse Lead Section (Section 0)
		$content = $rev->getContent( 'main' );
		// Defensive check: getContent might return null if something is corrupt
		$section0 = $content ? $content->getSection( 0 ) : null;
		
		$skeletonHtml = '';
		if ( $section0 ) {
			$services = MediaWikiServices::getInstance();
			$parser = $services->getParser();
			// Use global context options
			$popts = ParserOptions::newFromContext( RequestContext::getMain() );
			
			$parseOut = $parser->parse( $section0->getText(), $parent, $popts, true );
			
			// Transform to Skeleton
			$builder = $services->getService( 'GeminiTranslator.SkeletonBuilder' );
			$skeletonHtml = $builder->createSkeleton( $parseOut->getText() );
		}

		// 3. Output HTML
		$html = '<div class="gemini-virtual-container">';
		$html .= '<div class="mw-message-box mw-message-box-notice">';
		$html .= '<strong>Translated Content:</strong> This page is a real-time translation of <a href="' . $parent->getLinkURL() . '">' . $parent->getText() . '</a>.';
		$html .= '</div>';
		
		$html .= '<div id="gemini-virtual-content" style="margin-top: 20px;">';
		
		// If skeleton is empty (e.g. empty page), show default loader
		if ( empty( $skeletonHtml ) ) {
			$html .= '<div class="gemini-loading">Loading...</div>';
		} else {
			$html .= $skeletonHtml;
		}
		
		$html .= '</div>'; // End content
		$html .= '</div>'; // End container

		$output->addHTML( $html );
		
		// Pass vars to JS for lazy loading the rest
		$output->addJsConfigVars( [
			'wgGeminiParentRevId' => $rev->getId(),
			'wgGeminiTargetLang' => $lang
		] );

		// Force the action to 'view' to prevent "Create Page" editor
		$request = $output->getRequest();
		$request->setVal( 'action', 'view' );
	}
}
