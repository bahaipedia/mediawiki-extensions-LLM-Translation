window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};

( function ( mw, $ ) {

    function GeminiTranslator( $trigger ) {
        this.$trigger = $trigger;
        this.revision = mw.config.get( 'wgRevisionId' );
        this.$content = $( '#mw-content-text > .mw-parser-output' );
        
        // Setup UI components
        this.setupDialog();
        
        this.$trigger.on( 'click', this.openDialog.bind( this ) );
    }

    GeminiTranslator.prototype.setupDialog = function () {
        // Create a simple Prompt dialog for language code
        // In a future version, this should be a dropdown of supported languages
        this.langInput = new OO.ui.TextInputWidget( { 
            placeholder: 'es', 
            value: 'es' 
        } );

        this.dialog = new OO.ui.ProcessDialog( {
            size: 'medium'
        } );
        
        // Hacky way to set title/actions on a generic ProcessDialog instance
        this.dialog.title.setLabel( 'Translate Page' );
        this.dialog.actions.set( [
            { action: 'translate', label: 'Translate', flags: 'primary' },
            { action: 'cancel', label: 'Cancel', flags: 'safe' }
        ] );

        this.dialog.initialize();
        this.dialog.$body.append( 
            $( '<p>' ).text( 'Enter target language code (e.g. es, fr, de):' ),
            this.langInput.$element 
        );

        // Handle actions
        this.dialog.getProcess = ( action ) => {
            if ( action === 'translate' ) {
                this.startTranslation( this.langInput.getValue() );
                return new OO.ui.Process( () => { this.dialog.close(); } );
            }
            return new OO.ui.Process( () => { this.dialog.close(); } );
        };

        var windowManager = new OO.ui.WindowManager();
        $( 'body' ).append( windowManager.$element );
        windowManager.addWindows( [ this.dialog ] );
        this.windowManager = windowManager;
    };

    GeminiTranslator.prototype.openDialog = function ( e ) {
        e.preventDefault();
        this.windowManager.openWindow( this.dialog );
    };

    GeminiTranslator.prototype.startTranslation = function ( targetLang ) {
        // Visual feedback
        this.$content.css( 'opacity', '0.5' );
        mw.notify( 'Starting translation...' );

        // 1. Translate Lead (Section 0)
        this.fetchSection( 0, targetLang ).done( ( data ) => {
            
            // Clear existing content and replace with Lead
            this.$content.html( data.html );
            this.$content.css( 'opacity', '1' );
            
            // Add a container for subsequent sections
            this.$restOfPage = $( '<div>' ).attr('id', 'gemini-translated-body').appendTo( this.$content );

            // 2. Determine how many sections exist
            // We can infer this from the TOC if it exists, or just try requesting until we get empty
            // For robustness, let's just try fetching sections 1..10 sequentially
            this.fetchNextSection( 1, targetLang );

        } ).fail( ( err ) => {
            console.error( err );
            mw.notify( 'Translation failed.', { type: 'error' } );
            this.$content.css( 'opacity', '1' );
        } );
    };

    GeminiTranslator.prototype.fetchNextSection = function ( sectionId, targetLang ) {
        // Visual indicator at bottom
        var $loader = $( '<div>' ).text( 'Loading section ' + sectionId + '...' ).appendTo( this.$restOfPage );

        this.fetchSection( sectionId, targetLang ).done( ( data ) => {
            $loader.remove();
            
            if ( data.html && data.html.trim() !== '' ) {
                // Append the new section
                $( '<div>' ).html( data.html ).appendTo( this.$restOfPage );
                
                // Fetch the next one
                this.fetchNextSection( sectionId + 1, targetLang );
            } else {
                // No more content
                mw.notify( 'Translation complete!' );
            }
        } ).fail( () => {
            $loader.remove();
            // Assume failure means end of sections or error
        } );
    };

    GeminiTranslator.prototype.fetchSection = function ( sectionId, targetLang ) {
        return $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_section/' + this.revision,
            contentType: 'application/json',
            data: JSON.stringify( {
                targetLang: targetLang,
                section: sectionId
            } )
        } );
    };

    // Initialize
    $( function () {
        var $btn = $( '#ca-gemini-translate' );
        if ( $btn.length ) {
            new GeminiTranslator( $btn );
        }
    } );

}( mediaWiki, jQuery ) );
