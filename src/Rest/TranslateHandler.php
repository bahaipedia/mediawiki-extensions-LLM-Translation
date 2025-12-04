<?php

namespace MediaWiki\Extension\GeminiTranslator\Rest;

use MediaWiki\Context\RequestContext;
use MediaWiki\Extension\GeminiTranslator\PageTranslator;
use MediaWiki\Rest\HttpException;
use MediaWiki\Rest\SimpleHandler;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Parser\ParserOptions;
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
		error_log( "GEMINI DEBUG: Starting execution..." );
		
		$params = $this->getValidatedParams();
		$body = $this->getValidatedBody();
		
		$revId = $params['rev_id'];
		$targetLang = $body['targetLang'];
		$sectionId = $body['section'] ?? 0;

		error_log( "GEMINI DEBUG: Params loaded. Rev: $revId, Lang: $targetLang, Section: $sectionId" );

		// 1. Load Revision
		$rev = $this->revisionLookup->getRevisionById( $revId );
		if ( !$rev ) {
			error_log( "GEMINI DEBUG: Revision not found" );
			throw new HttpException( 'Revision not found', 404 );
		}

		// 2. Get Section Content
		$content = $rev->getContent( 'main' );
		$sectionContent = null;
		
		if ( $content ) {
			error_log( "GEMINI DEBUG: Content found. Attempting to get section $sectionId" );
			$sectionBlob = $content->getSection( $sectionId );
			if ( $sectionBlob ) {
				$sectionContent = $sectionBlob;
			} else {
				if ( $sectionId !== 0 ) { 
					return $this->getResponseFactory()->createJson( [ 'html' => '' ] );
				}
				$sectionContent = $content;
			}
		}

		if ( !$sectionContent ) {
			error_log( "GEMINI DEBUG: Section content extraction failed" );
			throw new HttpException( 'Could not extract section content', 500 );
		}

		// 3. Parse Wikitext
		error_log( "GEMINI DEBUG: Parsing wikitext..." );
		$services = MediaWikiServices::getInstance();
		$parser = $services->getParser();
		
		// FIX: Use RequestContext::getMain() instead of $this->getContext()
		$popts = ParserOptions::newFromContext( RequestContext::getMain() );
		
		$output = $parser->parse( 
			$sectionContent->getText(), 
			$rev->getPageAsLinkTarget(), 
			$popts, 
			true 
		);
		
		$sourceHtml = $output->getText();
		error_log( "GEMINI DEBUG: Parsed HTML length: " . strlen($sourceHtml) );

		// 4. Translate via Gemini Block Engine
		error_log( "GEMINI DEBUG: Calling PageTranslator->translateHtml..." );
		
		$status = $this->translator->translateHtml( $sourceHtml, $targetLang );

		if ( !$status->isOK() ) {
			error_log( "GEMINI DEBUG: Translation failed: " . print_r($status->getErrors(), true) );
			// Return a clean error to the frontend
			return $this->getResponseFactory()->createJson( [ 'error' => $status->getErrors() ], 400 );
		}

		error_log( "GEMINI DEBUG: Translation success!" );

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
				ParamValidator::PARAM_DEFAULT => 0
			]
		];
	}
}
