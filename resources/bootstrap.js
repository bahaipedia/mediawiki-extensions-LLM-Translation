window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};
console.log('Gemini: Loaded v5 bootstrap.js');
( function ( mw, $ ) {

    // --- Dialog Logic (Redirects to /lang) ---
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
        if ( !$tokens.length ) return;

        var targetLang = mw.config.get( 'wgGeminiTargetLang' );
        if ( !targetLang ) return;

        console.log( 'Gemini: Found ' + $tokens.length + ' tokens. Processing...' );

        // 1. Collect strings and map them to DOM elements
        var batch = [];
        var elementMap = new Map(); // string -> [jquery_elements]

        $tokens.each( function() {
            var $el = $( this );
            // Decode the base64 source
            try {
                var raw = atob( $el.data( 'source' ) );
                if ( !elementMap.has( raw ) ) {
                    elementMap.set( raw, [] );
                    batch.push( raw );
                }
                elementMap.get( raw ).push( $el );
            } catch (e) {
                console.error('Gemini: Bad token', e);
            }
        });

        // 2. Chunk into groups of 25 to respect API limits
        var chunkSize = 25;
        for ( var i = 0; i < batch.length; i += chunkSize ) {
            var chunk = batch.slice( i, i + chunkSize );
            fetchBatch( chunk, targetLang, elementMap );
        }
    }

    function fetchBatch( strings, targetLang, elementMap ) {
        $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_batch',
            contentType: 'application/json',
            data: JSON.stringify( { strings: strings, targetLang: targetLang } )
        } ).done( function( data ) {
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
                    // Fade out spinner, swap text, fade in
                    $el.css( 'opacity', 0 ).animate( { opacity: 1 }, 300, function() {
                        // Replace the SPAN with a pure TextNode to clean up the DOM
                        $el.replaceWith( document.createTextNode( translated ) );
                    });
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
            // Hide the "No article text" box via JS as well just in case
            $( '.noarticletext' ).hide();
            
            // Start processing tokens immediately
            processTokens();
        }
    } );

}( mediaWiki, jQuery ) );
