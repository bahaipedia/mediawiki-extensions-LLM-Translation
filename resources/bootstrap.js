window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};
console.log('Gemini: Loaded v16 bootstrap.js');
( function ( mw, $ ) {

    // --- Configuration ---
    var MAX_CONCURRENT = 5;
    var CHUNK_SIZE = 10;

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

    // --- Dialog Logic (No changes) ---
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
            // Add a specific class to track loading state
            $el.addClass('gemini-loading'); 
            
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
            // FIX: Only declare "All done" if NO workers are active
            if ( activeRequests === 0 ) {
                console.log( 'Gemini: All done.' );
                cleanupStragglers();
            }
            return;
        }

        activeRequests++;
        var currentBatch = batchQueue.shift();
        var currentTitle = mw.config.get( 'wgPageName' ).replace( /_/g, ' ' );

        $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_batch',
            contentType: 'application/json',
            data: JSON.stringify( { 
                strings: currentBatch, 
                targetLang: targetLang, 
                pageTitle: currentTitle 
            } )
        } ).done( function( data ) {
            applyTranslations( data.translations );
        }).fail( function( xhr ) {
            console.error( 'Gemini: Batch failed.' );
            // Mark JUST this batch as error, don't stop the whole queue if one fails?
            // User preference: You previously stopped everything. Keeping that logic.
            queueStopped = true;
            markAsError( currentBatch );
            batchQueue.forEach( function( batch ) { markAsError( batch ); } );
            batchQueue = [];
            cleanupStragglers(); // Clean up immediately if we crash
        }).always( function() {
            activeRequests--;
            processNextBatch();
        });
    }

    function applyTranslations( translations ) {
        $.each( translations, function( original, translated ) {
            var $elements = elementMap.get( original );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    $el.removeClass('gemini-loading'); // Remove marker
                    $el.replaceWith( document.createTextNode( translated ) );
                } );
            } else {
                console.warn('Gemini: Received translation for unknown key (hash mismatch?)');
            }
        });
    }

    function markAsError( strings ) {
        strings.forEach( function( str ) {
            var $elements = elementMap.get( str );
            if ( $elements ) {
                $elements.forEach( function( $el ) {
                    $el.removeClass('gemini-loading');
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

    // --- FIX: Cleanup Logic ---
    // If the mapping failed (Gemini returned a slightly different string key),
    // the UI elements will remain "loading" forever. This forces them to stop.
    function cleanupStragglers() {
        var $stuck = $( '.gemini-loading' );
        if ( $stuck.length > 0 ) {
            console.warn( 'Gemini: Found ' + $stuck.length + ' stuck elements. Marking as failed.' );
            $stuck.each( function() {
                $( this ).removeClass('gemini-loading')
                         .css( { 'animation': 'none', 'border': '1px dashed orange' } )
                         .attr( 'title', 'Translation missing (Mismatch error)' );
            });
        }
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
