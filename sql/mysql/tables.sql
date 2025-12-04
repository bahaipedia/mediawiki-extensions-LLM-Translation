-- Database definition for GeminiTranslator

CREATE TABLE /*_*/gemini_translation_blocks (
  -- SHA256 Hash of the source HTML block
  gtb_source_hash VARBINARY(64) NOT NULL,
  
  -- The target language code (e.g., 'es', 'fr')
  gtb_lang VARBINARY(20) NOT NULL,
  
  -- The translated HTML content
  gtb_content MEDIUMBLOB NOT NULL,
  
  -- For cache eviction policies later
  gtb_last_touched BINARY(14) NOT NULL,

  PRIMARY KEY (gtb_source_hash, gtb_lang)
) /*$wgDBTableOptions*/;
