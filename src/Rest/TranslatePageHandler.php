<?php

namespace MediaWiki\Extension\GeminiTranslator\Rest;

use MediaWiki\Extension\GeminiTranslator\PageTranslator;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Parser\Parser;
use MediaWiki\Parser\ParserOptions;
use MediaWiki\Parser\ParserOutput;
use MediaWiki\MediaWikiServices;
use Wikimedia\ParamValidator\ParamValidator;

class TranslateHandler extends SimpleHandler {

	private RevisionLookup $revisionLookup;
	private PageTranslator $translator;

	public function __construct(
		RevisionLookup $revisionLookup,
		PageTranslator $translator
	) {
		$this->revisionLookup = $revisionLookup;
		$this->translator = $translator;
	}

	public function execute() {
		$params = $this->getValidatedParams();
		$body = $this->getValidatedBody();
		
		$revId = $params['rev_id'];
		$targetLang = $body['targetLang'];
		// Default to section 0 (Lead) if not provided
		$sectionId = $body['section'] ?? 0;

		// 1. Load Revision
		$rev = $this->revisionLookup->getRevisionById( $revId );
		if ( !$rev ) {
			throw new HttpException( 'Revision not found', 404 );
		}

		// 2. Get Section Content (Wikitext)
		// We use the content handler to slice the wikitext by section index
		$content = $rev->getContent( 'main' );
		$sectionContent = null;
		
		if ( $content ) {
			// This is a bit of a workaround to get section-specific HTML reliably
			// We fetch the wikitext section, then parse it.
			// Note: This might miss context (like references defined elsewhere), 
			// but it supports the Lazy Load architecture best.
			$sectionBlob = $content->getSection( $sectionId );
			if ( $sectionBlob ) {
				$sectionContent = $sectionBlob;
			} else {
				// Fallback: if section extraction fails or is 0 (whole page sometimes), try getting full content
				// For now, if section is missing, we assume end of content
				if ( $sectionId !== 0 ) { 
					return $this->getResponseFactory()->createJson( [ 'html' => '' ] );
				}
				$sectionContent = $content;
			}
		}

		if ( !$sectionContent ) {
			throw new HttpException( 'Could not extract section content', 500 );
		}

		// 3. Parse Wikitext to HTML
		// We need a ParserOptions object configured for the user/wiki
		$services = MediaWikiServices::getInstance();
		$parser = $services->getParser();
		$popts = ParserOptions::newFromContext( $this->getContext() );
		
		// Render the section
		$output = $parser->parse( 
			$sectionContent->getText(), 
			$rev->getPageAsLinkTarget(), 
			$popts, 
			true 
		);
		
		$sourceHtml = $output->getText();

		// 4. Translate via Gemini Block Engine
		$status = $this->translator->translateHtml( $sourceHtml, $targetLang );

		if ( !$status->isOK() ) {
			return $this->getResponseFactory()->createJson( [ 'error' => $status->getErrors() ], 400 );
		}

		return $this->getResponseFactory()->createJson( [
			'html' => $status->getValue(),
			'section' => $sectionId
		] );
	}

	public function getParamSettings() {
		return [
			'rev_id' => [
				self::PARAM_SOURCE => 'path',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => true
			],
		];
	}

	public function getBodyParamSettings(): array {
		return [
			'targetLang' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'string',
				ParamValidator::PARAM_REQUIRED => true,
			],
			'section' => [
				self::PARAM_SOURCE => 'body',
				ParamValidator::PARAM_TYPE => 'integer',
				ParamValidator::PARAM_REQUIRED => false,
				self::PARAM_DEFAULT => 0
			]
		];
	}
}
