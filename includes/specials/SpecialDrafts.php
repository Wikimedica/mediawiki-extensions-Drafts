<?php
/**
 * Drafts extension special page.
 *
 * @file
 * @author Antoine Mercier-Linteau
 * @license GNU
 */

namespace MediaWiki\Extension\Drafts;

use MediaWiki\MediaWikiServices;
use OOUI;
use Html;

/**
 * @inheritdoc
 * */
class SpecialDrafts extends \FormSpecialPage 
{
    /** @var Database */
    protected $db;

    /**
     * @inheritdoc
     * */
    public function __construct() 
    {
        parent::__construct('Drafts', 'edit');
        $this->db = wfGetDB( DB_REPLICA );
        $this->addHelpLink(\Title::newFromText('Création de page', NS_HELP)->getFullURL(), true);

        $this->getOutput()->setRobotPolicy('noindex,nofollow'); // Do not index that special page.
    }
    
    /**
     * @inheritdoc
     **/
    protected function getDisplayFormat() {	return 'ooui'; }
    
    /**
     * @inheritdoc
     **/
    public function preText() 
    {
        $request = $this->getRequest();
        $user = $this->getUser();
        $this->setHeaders();
        $t = '';

        if($val = $request->getVal('delete-success', false))
        {            
            $this->getOutPut()->addHTML(
				Html::successBox(
					Html::element(
						'p',
						[],
						'Le brouillon '.$val.' a été supprimé'
					),
					'mw-notify-success'
				)
			);
        }

        // Retrieve all the subpages from the database.
        $titleArray = \TitleArray::newFromResult($this->db->select(
            'page',
            [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ],
            [
                //'page_is_redirect' => 1,
                'page_namespace' => NS_USER,
                'page_title'.$this->db->buildLike($user->getUserPage()->getDBKey().'/Brouillons/', $this->db->anyString())
            ],
            __METHOD__,
            []
        ));
        
        
        // Build the HTML / Wikicode of the page.
        $t .= "== Brouillons en cours ==
<table class=\"wikitable\"><tr><th>Titre</th><th>Dernière modification</th><th>Actions</th></tr>\n";
        
        if(!$titleArray->count()) // There are no drafts.
        {
            $t .= "<tr><td colspan=\"3\"><i>Aucun brouillon</i></td></tr>";
        }
        else
        {
            foreach($titleArray as $draft) // Display information about each draft.
            {
                $t .= "<tr>";
                if($draft->isRedirect())
                {
                    $t .= '<td class="plainlinks">\'\'[{{fullurl:'.$draft->getFullText().'|redirect=no}} '.$draft->getSubpageText().'] (redirection)\'\' </td>';
                }
                else
                {
                    $t .= '<td> [['.$draft->getFullText().'|'.$draft->getSubpageText().']] </td>';
                }
                $t .= '<td>'.\DateTime::createFromFormat('U', wfTimestamp(TS_UNIX, MediaWikiServices::getInstance()->getRevisionStore()->getTimestampFromId($draft, $draft->getLatestRevID())), (new \DateTime())->getTimezone())->format('Y/m/d H:i:s').' </td>';
                
                $t .= '<td><html>';
                $form = new OOUI\FormLayout([
                    'method' => 'post', 
                    'action' => $this->getFullTitle()->getFullURL(),
                    'enctype' => "application/x-www-form-urlencoded"
                ]);
                
                $form->addItems([
                    new OOUI\HorizontalLayout(['label' => '', 'classes' => 'container', 'items' => [
                        new OOUI\HiddenInputWidget(['value' => $draft->getSubpageText(), 'name' => 'wpname']),
                        new OOUI\HiddenInputWidget(['value' => $user->getEditToken(), 'name' => 'wpEditToken']),
                        new OOUI\FieldLayout((new OOUI\ButtonInputWidget([
                            'type' => 'submit', 
                            'label' =>'Supprimer',
                            'name' => 'wpDelete',
                            'value' => 1,
                            'flags' => ['destructive']
                        ]))->addClasses(['draft-delete']))

                        /*new OOUI\FieldLayout(new OOUI\ButtonWidget([
                            'label' =>'Finaliser / Renommer', 
                            'target' => '_blank', 
                            'href' => self::getTitleFor('Movepage', $draft->getFullText())->getCanonicalURL()
                        ]))*/
                    ]])
                ]);
                
                $t .= $form."</html></td></tr>\n";
            }
        }
        
        $t .= "</table>\n";
        
        $t .= "== Nouveau brouillon ==\n__NOEDITSECTION__";
        
        // Add a JS confirm dialog to the delete buttons.
        $t .= '<html><script type="text/javascript">
window.addEventListener("load", function(){
    $(".draft-delete").on("click", function(){
        return confirm("Êtes-vous certain de vouloir supprimer cette page?");
    });
});
</script></html>'."\n";
        
        // % needs to be replaced with $ for transclusions to work with url_encoding ... no idea why.
        $t .= '{{:Special:CreateNewPage}}';

        $parser = MediaWikiServices::getInstance()->getParser();
        
        // Parse the Wikicode and return it as HTML.
        return $parser->parse($t, $this->getFullTitle(), \ParserOptions::newFromUser($this->getUser()))->getText();
    }
    
    /**
     * @inheritdoc
     **/
    public function getFormFields()
    {
        return [ // Not actually used but defined just so MediaWiki lets form fields trough and displays an edit token.
            'name' => ['type' => 'hidden'],
            'delete' => ['type' => 'hidden']
        ];
    }
    
    /**
     * @inheritdoc
     */
    protected function alterForm(\HTMLForm $form)
    {
        $form->suppressDefaultSubmit();
        return $form;
    }
    
    /**
     * @inheritdoc
     * */
    public function onSubmit($data)
    {
        if(!$data['name']) { return 'Nom de page manquant'; } // If a name was not provided.
        $draft = MediaWikiServices::getInstance()->getWikiPageFactory()->newFromTitle(\Title::newFromText($this->getUser()->getName().'/Brouillons/'.ucfirst($data['name']), NS_USER));
        
        if($data['delete'] === null) // If this is a request to delete a page.
        {
            $errors = [];
            if(!$draft->exists()) { return 'La page n\'existe pas'; }
            // Delete the page. deleteUnsafe() can be used since users are allowed to delete pages from their drafts.
            $status = MediaWikiServices::getInstance()->getDeletePAgeFactory()->newDeletePage($draft, $this->getUser())->deleteUnsafe('Suppression du brouillon');
            
            if(!$status->isGood()) {
                return $status;
            }
            
            $this->getOutput()->redirect($this->getFullTitle()->getFullURL(['delete-success' => $draft->getTitle()->getSubpageText()]));
        }
        
        return true;
    }
}
