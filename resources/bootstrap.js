window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};
console.log('Gemini: Loaded v9 bootstrap.js');
( function ( mw, $ ) {

    // --- Helper: Correctly decode UTF-8 from Base64 ---
    function decodeBase64Utf8( base64 ) {
        try {
            var binaryString = atob( base64 );
            var bytes = new Uint8Array( binaryString.length );
            for ( var i = 0; i < binaryString.length; i++ ) {
                bytes[i] = binaryString.charCodeAt( i );
            }
            return new TextDecoder( 'utf-8' ).decode( bytes );
        } catch ( e ) {
            console.error( 'Gemini: Failed to decode base64 token', e );
            return '';
        }
    }

    // --- Dialog Logic ---
    function GeminiDialog( config ) {
        GeminiDialog.super.call( this, config );
    }
    OO.inheritClass( GeminiDialog, OO.ui.ProcessDialog );
    GeminiDialog.static.name = 'geminiDialog';
    GeminiDialog.static.title = 'Translate Page';
    GeminiDialog.static.actions = [
        { action: 'go', label: 'Go', flags: 'primary' },
        { action: 'cancel', label: 'Cancel', flags: 'safe' }
    ];
    GeminiDialog.prototype.initialize = function () {
        GeminiDialog.super.prototype.initialize.call( this );
        this.panel = new OO.ui.PanelLayout( { padded: true, expanded: false } );
        this.langInput = new OO.ui.TextInputWidget( { placeholder: 'es', value: 'es' } );
        this.panel.$element.append( $( '<p>' ).text( 'Enter target language code:' ), this.langInput.$element );
        this.$body.append( this.panel.$element );
    };
    GeminiDialog.prototype.getActionProcess = function ( action ) {
        if ( action === 'go' ) {
            var lang = this.langInput.getValue();
            var title = mw.config.get( 'wgPageName' );
            window.location.href = mw.util.getUrl( title + '/' + lang );
            return new OO.ui.Process( function () { this.close(); }, this );
        }
        return GeminiDialog.super.prototype.getActionProcess.call( this, action );
    };

    // --- Token Processor Logic ---
    function processTokens() {
        var $tokens = $( '.gemini-token' );
        if ( !$tokens.length ) {
            console.log( 'Gemini: No tokens found on page.' );
            return;
        }

        var targetLang = mw.config.get( 'wgGeminiTargetLang' );
        if ( !targetLang ) {
            console.warn( 'Gemini: Tokens found but no target language defined.' );
            return;
        }

        console.log( 'Gemini: Found ' + $tokens.length + ' tokens. Preparing batch...' );

        // 1. Collect strings and map them to DOM elements
        var batch = [];
        var elementMap = new Map();

        $tokens.each( function() {
            var $el = $( this );
            var raw = decodeBase64Utf8( $el.data( 'source' ) );
            
            if ( raw ) {
                if ( !elementMap.has( raw ) ) {
                    elementMap.set( raw, [] );
                    batch.push( raw );
                }
                elementMap.get( raw ).push( $el );
            }
        });

        console.log( 'Gemini: Unique strings to translate: ' + batch.length );

        // 2. Chunk into groups of 25
        var chunkSize = 25;
        for ( var i = 0; i < batch.length; i += chunkSize ) {
            var chunk = batch.slice( i, i + chunkSize );
            console.log( 'Gemini: Queueing batch ' + (i/chunkSize + 1) + ' (' + chunk.length + ' items)' );
            fetchBatch( chunk, targetLang, elementMap );
        }
    }

    function fetchBatch( strings, targetLang, elementMap ) {
        console.log( 'Gemini: Sending batch request...' );
        
        $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_batch',
            contentType: 'application/json',
            data: JSON.stringify( { strings: strings, targetLang: targetLang } )
        } ).done( function( data ) {
            console.log( 'Gemini: Received response for ' + Object.keys(data.translations).length + ' strings.' );
            applyTranslations( data.translations, elementMap );
        }).fail( function( err ) {
            console.error( 'Gemini: Batch failed', err );
        });
    }

    function applyTranslations( translations, elementMap ) {
        $.each( translations, function( original, translated ) {
            var $elements = elementMap.get( original );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    // Replace immediately
                    $el.replaceWith( document.createTextNode( translated ) );
                });
            }
        });
    }

    // --- Initialization ---
    $( function () {
        // 1. Menu Handler
        var windowManager = new OO.ui.WindowManager();
        $( 'body' ).append( windowManager.$element );
        var dialog = new GeminiDialog();
        windowManager.addWindows( [ dialog ] );
        $( 'body' ).on( 'click', '#ca-gemini-translate', function( e ) {
            e.preventDefault();
            windowManager.openWindow( dialog );
        } );

        // 2. Virtual Page Handler
        if ( $( 'body' ).hasClass( 'gemini-virtual-page' ) ) {
            $( '.noarticletext' ).hide();
            processTokens();
        }
    } );

}( mediaWiki, jQuery ) );
