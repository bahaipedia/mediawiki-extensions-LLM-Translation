<?php

namespace MediaWiki\Extension\GeminiTranslator\Hook;

use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Html\Html;

class AddContentAction implements BeforePageDisplayHook {

	/**
	 * Adds the Javascript module and the Page Indicator
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();

		// Validation: Don't show on special pages, non-existent pages, or edit mode
		if ( !$title->exists() || $title->isSpecialPage() || $out->getActionName() === 'edit' ) {
			return;
		}

		$msgText = $skin->msg( 'geminitranslator-ca-translate' )->text();

		// --- Unified Logic ---
		// We allow both Anon and Logged-in users to access the feature.
		// We use the ID 'ca-gemini-translate' so bootstrap.js can attach the click event.
		$html = Html::element( 'a', [
			'href' => '#',
			'id' => 'ca-gemini-translate',
			'class' => 'mw-indicator-gemini-translate',
			'style' => 'cursor: pointer; font-weight: bold;',
			'title' => 'Translate this page using Gemini'
		], $msgText );

		// Load the JS module for everyone
		$out->addModules( [ 'ext.geminitranslator.bootstrap' ] );

		// Add to Page Indicators
		$out->setIndicators( [ 'gemini-status' => $html ] );
	}
}
