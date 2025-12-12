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
		if ( $title->getNamespace() !== NS_MAIN ) { return; }
		if ( $title->exists() ) { return; }

		$text = $title->getText();
		$lastSlash = strrpos( $text, '/' );
		if ( $lastSlash === false ) { return; }

		$baseText = substr( $text, 0, $lastSlash );
		$langCode = substr( $text, $lastSlash + 1 );
		
		// Validate Lang Code (2-3 chars or pt-br/zh-cn)
		$len = strlen( $langCode );
		$isValidLang = ( $len >= 2 && $len <= 3 ) || in_array( strtolower($langCode), [ 'zh-cn', 'zh-tw', 'pt-br' ] );
		if ( !$isValidLang ) { return; }

		$parentTitle = Title::newFromText( $baseText );
		if ( !$parentTitle || !$parentTitle->exists() ) { return; }

		// --- HIJACK DISPLAY ---
		$this->renderVirtualPage( $output, $parentTitle, $langCode, $title, $user );
	}

	private function renderVirtualPage( OutputPage $output, Title $parent, string $lang, Title $fullTitle, $user ): void {
		$output->setPageTitle( $fullTitle->getText() );
		$output->setArticleFlag( false ); 
		$output->addBodyClasses( 'gemini-virtual-page' );
		
		// 1. STRICT ANONYMOUS CHECK
		if ( !$user->isNamed() ) {
			// Show "Please log in" message using i18n
			$output->addWikiMsg( 'geminitranslator-login-required' );
			
			// Force view mode to hide "Create Page" editor
			$request = $output->getRequest();
			$request->setVal( 'action', 'view' );
			return; 
		}

		// 2. Load Resources for Logged-In Users
		$output->addModules( [ 'ext.geminitranslator.bootstrap' ] );
		$output->addInlineStyle( '.noarticletext { display: none !important; }' );

		// 3. Get Parent Content
		$rev = $this->revisionLookup->getRevisionByTitle( $parent );
		if ( !$rev ) { return; }

		$content = $rev->getContent( 'main' );
		$skeletonHtml = '';
		
		if ( $content ) {
			$services = MediaWikiServices::getInstance();
			$parser = $services->getParser();
			$popts = ParserOptions::newFromContext( RequestContext::getMain() );
			
			$parseOut = $parser->parse( $content->getText(), $parent, $popts, true );
			
			$builder = $services->getService( 'GeminiTranslator.SkeletonBuilder' );
			// No need for $isReadOnly flag anymore since we gate earlier
			$skeletonHtml = $builder->createSkeleton( $parseOut->getText(), $lang );
		}

		// 4. Output HTML Structure
		$html = '<div class="gemini-virtual-container">';
		
		// Notice Banner (using i18n via wfMessage, parsed as HTML)
		$noticeMsg = \wfMessage( 'geminitranslator-viewing-live', $parent->getPrefixedText(), $parent->getText() )->parse();
		$html .= '<div class="mw-message-box mw-message-box-notice">' . $noticeMsg . '</div>';
		
		$html .= '<div id="gemini-virtual-content" style="margin-top: 20px;">';
		$html .= $skeletonHtml;
		$html .= '</div></div>';

		$output->addHTML( $html );
		
		// Pass Config to JS
		$output->addJsConfigVars( [
			'wgGeminiTargetLang' => $lang
		] );

		$request = $output->getRequest();
		$request->setVal( 'action', 'view' );
	}
}
