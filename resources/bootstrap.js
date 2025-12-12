window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};
console.log('Gemini: Loaded v10 bootstrap.js');
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
        if ( !$tokens.length ) return;

        var targetLang = mw.config.get( 'wgGeminiTargetLang' );
        if ( !targetLang ) return;

        console.log( 'Gemini: Found ' + $tokens.length + ' tokens. Preparing batch...' );

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

        // Use small chunks to allow progressive loading
        var chunkSize = 5;
        for ( var i = 0; i < batch.length; i += chunkSize ) {
            var chunk = batch.slice( i, i + chunkSize );
            // Stagger initial requests slightly to avoid hitting rate limits instantly
            (function(c, delay) {
                setTimeout(function() {
                    fetchBatchWithRetry( c, targetLang, elementMap, 0 );
                }, delay);
            })(chunk, i * 100); 
        }
    }

    /**
     * Fetches a batch with retry logic
     * @param {Array} strings 
     * @param {string} targetLang 
     * @param {Map} elementMap 
     * @param {number} attempt (0-indexed)
     */
    function fetchBatchWithRetry( strings, targetLang, elementMap, attempt ) {
        $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_batch',
            contentType: 'application/json',
            data: JSON.stringify( { strings: strings, targetLang: targetLang } )
        } ).done( function( data ) {
            applyTranslations( data.translations, elementMap );
        }).fail( function( xhr ) {
            console.warn( 'Gemini: Batch failed (Attempt ' + (attempt+1) + '). Status: ' + xhr.status );

            // Retry up to 3 times (total 4 attempts)
            if ( attempt < 3 ) {
                // Exponential backoff: 2s, 4s, 8s
                var delay = Math.pow( 2, attempt + 1 ) * 1000;
                setTimeout( function() {
                    fetchBatchWithRetry( strings, targetLang, elementMap, attempt + 1 );
                }, delay );
            } else {
                // Final Failure: Show error state
                markAsError( strings, elementMap );
            }
        });
    }

    function applyTranslations( translations, elementMap ) {
        $.each( translations, function( original, translated ) {
            var $elements = elementMap.get( original );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    $el.replaceWith( document.createTextNode( translated ) );
                });
            }
        });
    }

    function markAsError( strings, elementMap ) {
        strings.forEach( function( str ) {
            var $elements = elementMap.get( str );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    // Stop animation and turn red to indicate failure
                    $el.css( {
                        'animation': 'none',
                        'background': '#ffdddd', // Light red background
                        'border': '1px solid red',
                        'cursor': 'help',
                        'color': 'red' // Make text red? Or keep transparent?
                    } );
                    $el.attr( 'title', 'Translation failed. Refresh to retry.' );
                });
            }
        });
    }

    // --- Initialization ---
    $( function () {
        var windowManager = new OO.ui.WindowManager();
        $( 'body' ).append( windowManager.$element );
        var dialog = new GeminiDialog();
        windowManager.addWindows( [ dialog ] );
        $( 'body' ).on( 'click', '#ca-gemini-translate', function( e ) {
            e.preventDefault();
            windowManager.openWindow( dialog );
        } );

        if ( $( 'body' ).hasClass( 'gemini-virtual-page' ) ) {
            $( '.noarticletext' ).hide();
            processTokens();
        }
    } );

}( mediaWiki, jQuery ) );
