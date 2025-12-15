<?php

namespace MediaWiki\Extension\GeminiTranslator\Hook;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Title\Title;
use MediaWiki\Html\Html;

class AddContentAction implements BeforePageDisplayHook {

	/**
	 * Adds the Javascript module and the Page Indicator
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		$user = $skin->getUser();

		// Validation: Don't show on special pages, non-existent pages, or edit mode
		if ( !$title->exists() || $title->isSpecialPage() || $out->getActionName() === 'edit' ) {
			return;
		}

		$msgText = $skin->msg( 'geminitranslator-ca-translate' )->text();

		// 1. Configure the Link HTML
		if ( $user->isAnon() ) {
			// Anon: Link to Help page
			$helpTitle = Title::newFromText( 'Help:GeminiTranslator' );
			$url = $helpTitle ? $helpTitle->getLinkURL() : '#';
			
			// We create a standard link
			$html = Html::element( 'a', [
				'href' => $url,
				'class' => 'mw-indicator-gemini-translate',
				'title' => 'Learn about translation'
			], $msgText );

		} else {
			// Logged In: JS Trigger
			// We MUST use the ID 'ca-gemini-translate' because bootstrap.js is looking for it
			$html = Html::element( 'a', [
				'href' => '#',
				'id' => 'ca-gemini-translate',
				'class' => 'mw-indicator-gemini-translate',
				'style' => 'cursor: pointer; font-weight: bold;'
			], $msgText );

			// Load the JS module
			$out->addModules( [ 'ext.geminitranslator.bootstrap' ] );
		}

		// 2. Add to Page Indicators
		// The key 'gemini-status' is arbitrary but useful for sorting if needed
		$out->setIndicators( [ 'gemini-status' => $html ] );
	}
}
