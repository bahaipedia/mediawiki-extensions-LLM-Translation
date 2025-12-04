window.ext = window.ext || {};
window.ext.geminiTranslator = window.ext.geminiTranslator || {};

( function ( mw, $ ) {

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
        this.panel.$element.append( 
            $( '<p>' ).text( 'Enter target language code:' ),
            this.langInput.$element 
        );
        this.$body.append( this.panel.$element );
    };

    GeminiDialog.prototype.getActionProcess = function ( action ) {
        if ( action === 'go' ) {
            var lang = this.langInput.getValue();
            var title = mw.config.get( 'wgPageName' );
            // Redirect to Title/lang
            window.location.href = mw.util.getUrl( title + '/' + lang );
            return new OO.ui.Process( function () { this.close(); }, this );
        }
        return GeminiDialog.super.prototype.getActionProcess.call( this, action );
    };

    $( function () {
        // If we are ALREADY on a translation page (e.g. Title/es), load the content immediately
        // We detect this by checking if the body has our specific class (added by PHP later)
        if ( $( 'body' ).hasClass( 'gemini-virtual-page' ) ) {
            var pathParts = mw.config.get('wgPageName').split('/');
            var targetLang = pathParts.pop();
            
            // Initiate the stream
            var translator = new GeminiTranslatorController();
            translator.startStream( targetLang );
        }

        // Setup the Menu Click Handler
        var windowManager = new OO.ui.WindowManager();
        $( 'body' ).append( windowManager.$element );
        var dialog = new GeminiDialog();
        windowManager.addWindows( [ dialog ] );

        $( 'body' ).on( 'click', '#ca-gemini-translate', function( e ) {
            e.preventDefault();
            windowManager.openWindow( dialog );
        } );
    } );

    // The Logic to Stream Content into the Virtual Page
    function GeminiTranslatorController() {
        this.revision = mw.config.get( 'wgGeminiParentRevId' ); // Passed from PHP
        this.$content = $( '#gemini-virtual-content' ); // Placeholder in PHP
    }

    GeminiTranslatorController.prototype.startStream = function ( targetLang ) {
        this.fetchSection( 0, targetLang ).done( ( data ) => {
            this.$content.html( data.html );
            this.$restOfPage = $( '<div>' ).appendTo( this.$content );
            this.fetchNextSection( 1, targetLang );
        } );
    };

    GeminiTranslatorController.prototype.fetchNextSection = function ( sectionId, targetLang ) {
        var $loader = $( '<div>' )
            .addClass( 'gemini-loader' )
            .text( '...Translating next section...' )
            .appendTo( this.$restOfPage );

        this.fetchSection( sectionId, targetLang ).done( ( data ) => {
            $loader.remove();
            if ( data.html && data.html.trim() !== '' ) {
                $( '<div>' ).html( data.html ).appendTo( this.$restOfPage );
                this.fetchNextSection( sectionId + 1, targetLang );
            }
        } ).fail( () => { $loader.remove(); } );
    };

    GeminiTranslatorController.prototype.fetchSection = function ( sectionId, targetLang ) {
        return $.ajax( {
            method: 'POST',
            url: mw.util.wikiScript( 'rest' ) + '/gemini-translator/translate_section/' + this.revision,
            contentType: 'application/json',
            data: JSON.stringify( { targetLang: targetLang, section: sectionId } )
        } );
    };

}( mediaWiki, jQuery ) );
