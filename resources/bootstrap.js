window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};

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

    // --- Serial Queue Logic ---
    var batchQueue = [];
    var isProcessing = false;
    var targetLang = '';
    var elementMap = new Map();

    function processTokens() {
        var $tokens = $( '.gemini-token' );
        if ( !$tokens.length ) return;

        targetLang = mw.config.get( 'wgGeminiTargetLang' );
        if ( !targetLang ) return;

        console.log( 'Gemini: Found ' + $tokens.length + ' tokens. Preparing queue...' );

        var uniqueStrings = [];
        
        $tokens.each( function() {
            var $el = $( this );
            var raw = decodeBase64Utf8( $el.data( 'source' ) );
            if ( raw ) {
                if ( !elementMap.has( raw ) ) {
                    elementMap.set( raw, [] );
                    uniqueStrings.push( raw );
                }
                elementMap.get( raw ).push( $el );
            }
        });

        // Split into batches of 5 strings
        var chunkSize = 5;
        for ( var i = 0; i < uniqueStrings.length; i += chunkSize ) {
            batchQueue.push( uniqueStrings.slice( i, i + chunkSize ) );
        }

        console.log( 'Gemini: Queue length is ' + batchQueue.length );
        
        // Start the queue
        processNextBatch();
    }

    function processNextBatch() {
        if ( batchQueue.length === 0 ) {
            console.log( 'Gemini: Translation complete.' );
            return;
        }

        var currentBatch = batchQueue.shift(); // Get first item
        var remaining = batchQueue.length;

        $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_batch',
            contentType: 'application/json',
            data: JSON.stringify( { strings: currentBatch, targetLang: targetLang } )
        } ).done( function( data ) {
            // 1. Success! Update UI
            applyTranslations( data.translations );
            
            // 2. Wait a small delay (500ms) to be nice to the API, then process next
            if ( remaining > 0 ) {
                setTimeout( processNextBatch, 500 );
            }
        }).fail( function( xhr ) {
            // 3. Failure! Stop the entire queue.
            console.error( 'Gemini: Batch failed. Stopping queue to save quota.' );
            
            // Mark the failed batch as error
            markAsError( currentBatch );
            
            // Mark all remaining queued items as error (since we aren't sending them)
            batchQueue.forEach( function( batch ) {
                markAsError( batch );
            });
            batchQueue = []; // Clear queue
        });
    }

    function applyTranslations( translations ) {
        $.each( translations, function( original, translated ) {
            var $elements = elementMap.get( original );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    $el.replaceWith( document.createTextNode( translated ) );
                });
            }
        });
    }

    function markAsError( strings ) {
        strings.forEach( function( str ) {
            var $elements = elementMap.get( str );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    $el.css( {
                        'animation': 'none',
                        'background': '#ffdddd', 
                        'border': '1px solid red',
                        'cursor': 'help'
                    } );
                    $el.attr( 'title', 'Translation failed or aborted.' );
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
