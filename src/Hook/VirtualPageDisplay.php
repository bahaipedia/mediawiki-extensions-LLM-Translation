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
		// 1. Check if page exists. If it does, let MW handle it (it's a real page).
		if ( $title->exists() ) {
			return;
		}

		// 2. Check if it's a subpage (Parent/Child)
		if ( !$title->isSubpage() ) {
			return;
		}

		// 3. Check if the "Child" part is a generic language code (2-3 chars)
		// You might want to make this stricter later
		$parts = explode( '/', $title->getText() );
		$langCode = end( $parts );
		if ( strlen( $langCode ) > 3 && $langCode !== 'zh-cn' && $langCode !== 'zh-tw' ) {
			return;
		}

		// 4. Check if Parent exists
		$parentTitle = $title->getBaseTitle();
		if ( !$parentTitle || !$parentTitle->exists() ) {
			return;
		}

		// 5. HIJACK THE DISPLAY
		// This tells MediaWiki "Stop processing, we are handling this."
		
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
		// This is the container our JS will fill
		$html = '<div class="gemini-virtual-container">';
		$html .= '<div class="mw-message-box mw-message-box-notice">';
		$html .= '<strong>Translated Content:</strong> This page is a real-time translation of <a href="' . $parent->getLinkURL() . '">' . $parent->getText() . '</a>.';
		$html .= '</div>';
		$html .= '<div id="gemini-virtual-content" style="margin-top: 20px;">';
		$html .= '<div class="gemini-loading" style="text-align:center; padding: 50px; color: #888;">';
		$html .= '<em>Generating translation for ' . htmlspecialchars( $lang ) . '...</em><br/>';
		// Simple CSS spinner
		$html .= '<div style="display:inline-block; width:20px; height:20px; border:3px solid #ccc; border-top-color:#333; border-radius:50%; animation: spin 1s linear infinite;"></div>';
		$html .= '<style>@keyframes spin {0%{transform:rotate(0deg);}100%{transform:rotate(360deg);}}</style>';
		$html .= '</div>';
		$html .= '</div>'; // End content
		$html .= '</div>'; // End container

		$output->addHTML( $html );

		// Important: Prevent MediaWiki from showing the "Create this page" form
		// We do this by effectively telling MW we are done, but we let the skin render around us.
		// However, in BeforeInitialize, the easiest way to stop the "Edit" form 
		// is often to set the action to 'view' and trick the output.
		// A cleaner way in MW architecture is actually to let this hook finish 
		// but ensure the Article object doesn't try to show the missing page form.
		// But for now, we simply add the HTML. To suppress the 404 text, we might need CSS or further logic.
		// Actually, the simplest way to suppress the "Create source" is:
		$request = $output->getRequest();
		$request->setVal( 'action', 'view' );
	}
}
