<?php

namespace MediaWiki\Extension\GeminiTranslator\Hook;

use MediaWiki\Hook\BeforeInitializeHook;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Title\Title;
use MediaWiki\Title\TitleFactory;
use MediaWiki\Output\OutputPage;

class VirtualPageDisplay implements BeforeInitializeHook {

	private RevisionLookup $revisionLookup;
	private TitleFactory $titleFactory;

	public function __construct( RevisionLookup $revisionLookup, TitleFactory $titleFactory ) {
		$this->revisionLookup = $revisionLookup;
		$this->titleFactory = $titleFactory;
	}

	public function onBeforeInitialize( $title, $article, $output, $user, $request, $mediaWiki ): void {
		// 1. CRITICAL: Only run in Main Namespace (0)
		// We do not want to interfere with Template:/fr, User:/es, etc.
		if ( $title->getNamespace() !== NS_MAIN ) {
			return;
		}

		// 2. Check if page exists. If it does, let MW handle it (it's a real page).
		if ( $title->exists() ) {
			return;
		}

		// 3. Check if it's a subpage (Parent/Child)
		if ( !$title->isSubpage() ) {
			return;
		}

		// 4. Check if the "Child" part is a generic language code
		// We allow 2-3 char codes, plus specifically zh-cn/zh-tw
		$parts = explode( '/', $title->getText() );
		$langCode = end( $parts );
		$len = strlen( $langCode );
		
		$isValidLang = ( $len >= 2 && $len <= 3 ) || in_array( $langCode, [ 'zh-cn', 'zh-tw', 'pt-br' ] );
		
		if ( !$isValidLang ) {
			return;
		}

		// 5. Check if Parent exists
		$parentTitle = $title->getBaseTitle();
		if ( !$parentTitle || !$parentTitle->exists() ) {
			return;
		}

		// 6. HIJACK THE DISPLAY
		$this->renderVirtualPage( $output, $parentTitle, $langCode, $title );
	}

	private function renderVirtualPage( OutputPage $output, Title $parent, string $lang, Title $fullTitle ): void {
		$output->setPageTitle( $fullTitle->getText() );
		$output->setArticleFlag( false ); // Hide "No article text found"
		
		// Get latest revision of parent to pass to JS
		$rev = $this->revisionLookup->getRevisionByTitle( $parent );
		if ( !$rev ) {
			return;
		}

		// Pass variables to JS
		$output->addJsConfigVars( 'wgGeminiParentRevId', $rev->getId() );
		
		// Add body class so JS knows to start automatically
		$output->addBodyClasses( 'gemini-virtual-page' );

		// Load our JS
		$output->addModules( [ 'ext.geminitranslator.bootstrap' ] );

		// Output the Shell HTML
		$html = '<div class="gemini-virtual-container">';
		$html .= '<div class="mw-message-box mw-message-box-notice">';
		$html .= '<strong>Translated Content:</strong> This page is a real-time translation of <a href="' . $parent->getLinkURL() . '">' . $parent->getText() . '</a>.';
		$html .= '</div>';
		$html .= '<div id="gemini-virtual-content" style="margin-top: 20px;">';
		$html .= '<div class="gemini-loading" style="text-align:center; padding: 50px; color: #888;">';
		$html .= '<em>Generating translation for ' . htmlspecialchars( $lang ) . '...</em><br/>';
		// Simple CSS spinner
		$html .= '<div style="display:inline-block; width:20px; height:20px; margin-top:10px; border:3px solid #ccc; border-top-color:#333; border-radius:50%; animation: spin 1s linear infinite;"></div>';
		$html .= '<style>@keyframes spin {0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}</style>';
		$html .= '</div>';
		$html .= '</div>'; // End content
		$html .= '</div>'; // End container

		$output->addHTML( $html );

		// Suppress the "Create this page" form by forcing view mode
		$request = $output->getRequest();
		$request->setVal( 'action', 'view' );
	}
}
