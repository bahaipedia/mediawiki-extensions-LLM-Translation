window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};

( function ( mw, $ ) {

    // --- Configuration ---
    var MAX_CONCURRENT = 5; // How many requests to send at once
    var CHUNK_SIZE = 10;    // How many strings per request

    // --- Helper: Decode Base64 UTF-8 ---
    function decodeBase64Utf8( base64 ) {
        try {
            var binaryString = atob( base64 );
            var bytes = new Uint8Array( binaryString.length );
            for ( var i = 0; i < binaryString.length; i++ ) {
                bytes[i] = binaryString.charCodeAt( i );
            }
            return new TextDecoder( 'utf-8' ).decode( bytes );
        } catch ( e ) {
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

    // --- Parallel Queue Logic ---
    var batchQueue = [];
    var activeRequests = 0;
    var targetLang = '';
    var elementMap = new Map();
    var queueStopped = false;

    function processTokens() {
        var $tokens = $( '.gemini-token' );
        if ( !$tokens.length ) return;

        targetLang = mw.config.get( 'wgGeminiTargetLang' );
        if ( !targetLang ) return;

        console.log( 'Gemini: Found ' + $tokens.length + ' tokens.' );

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

        // 1. Fill the Queue
        for ( var i = 0; i < uniqueStrings.length; i += CHUNK_SIZE ) {
            batchQueue.push( uniqueStrings.slice( i, i + CHUNK_SIZE ) );
        }

        console.log( 'Gemini: Created ' + batchQueue.length + ' batches. Starting pool of ' + MAX_CONCURRENT + ' workers.' );
        
        // 2. Start Workers (Up to MAX_CONCURRENT)
        var initialWorkers = Math.min( MAX_CONCURRENT, batchQueue.length );
        for ( var w = 0; w < initialWorkers; w++ ) {
            processNextBatch();
        }
    }

    function processNextBatch() {
        if ( queueStopped ) return;
        if ( batchQueue.length === 0 ) {
            if ( activeRequests === 0 ) console.log( 'Gemini: All done.' );
            return;
        }

        activeRequests++;
        var currentBatch = batchQueue.shift();

        $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_batch',
            contentType: 'application/json',
            data: JSON.stringify( { strings: currentBatch, targetLang: targetLang } )
        } ).done( function( data ) {
            // Success: Update UI
            applyTranslations( data.translations );
        }).fail( function( xhr ) {
            // Failure: Stop everything
            console.error( 'Gemini: Batch failed. Stopping queue.' );
            queueStopped = true;
            markAsError( currentBatch );
            // Fail remaining items immediately
            batchQueue.forEach( function( batch ) { markAsError( batch ); } );
            batchQueue = [];
        }).always( function() {
            activeRequests--;
            // Worker is free, grab next job
            processNextBatch();
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
                    $el.attr( 'title', 'Translation failed.' );
                });
            }
        });
    }

    // --- Init ---
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
