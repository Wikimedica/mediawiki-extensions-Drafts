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

/**
 * @inheritdoc
 * */
class SpecialDrafts extends \FormSpecialPage 
{
    protected $db;

    /**
     * @inheritdoc
     * */
    public function __construct() 
    {
        parent::__construct('Drafts', 'edit');
        $this->db = wfGetDB( DB_REPLICA );
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
        
        if($val = $request->getVal('success', false))
        {
            $t.= '<div class="success">Le brouillon [['.$user->getUserPage()->getFullText().'/Brouillons/'.$val.'|'.$val.']] a été créé avec succès.</div>'."\n";
        }
        else if($val = $request->getVal('delete-success', false))
        {
            $t.= '<div class="success">Le brouillon [['.$user->getUserPage()->getFullText().'/Brouillons/'.$val.'|'.$val.']] a été supprimé.</div>'."\n";
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
                    $t .= '<td>\'\'[['.$draft->getFullText().'|'.$draft->getSubpageText().']] (redirection)\'\' </td>';
                }
                else
                {
                    $t .= '<td> [['.$draft->getFullText().'|'.$draft->getSubpageText().']] </td>';
                }
                $t .= '<td>'.\DateTime::createFromFormat('U', wfTimestamp(TS_UNIX, \Revision::getTimestampFromId($draft, $draft->getLatestRevID())), (new \DateTime())->getTimezone())->format('Y/m/d H:i:s').' </td>';
                
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
                        ]))->addClasses(['draft-delete'])),
                        new OOUI\FieldLayout(new OOUI\ButtonWidget([
                            'label' =>'Renommer', 
                            'target' => '_blank', 
                            'href' => self::getTitleFor('Movepage', $draft->getFullText())->getCanonicalURL()
                        ]))
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
</script></html>';
        
        $parser = MediaWikiServices::getInstance()->getParser();
        
        // Parse the Wikicode and return it as HTML.
        return $parser->parse($t, $this->getFullTitle(), \ParserOptions::newFromUser($this->getUser()))->getText();
    }
    
    /**
     * @inheritdoc
     **/
    public function getFormFields()
    {
        // Retrieve all the ontology classes from the database.
        $titleArray = \TitleArray::newFromResult($this->db->select(
            'page',
            [ 'page_id', 'page_namespace', 'page_title'],
            [
                'page_is_redirect' => 0,
                'page_namespace' => NS_CLASS
            ],
            __METHOD__,
            []
        ));
        
        $classes = ['Sélectionnez une classe' => ''];
        
        // Build an array of the class names.
        foreach($titleArray as $title)
        {
            if($title->isSubpage()) { continue; } // Skip subpages.
            if(!\Title::newFromText($title->getText().'/Prototype', NS_CLASS)->exists())
            {
                continue; // Skip this class if it does not have a prototype.
            }
            
            $classes[$title->getText()] = $title->getDBkey();
        }
        
        $classes['Page vierge'] = 'empty';
        
        return [
            'class' => [
                'type' => 'select',
                'label' => 'Classe (Type)',
                'options' => $classes,
                'required' => true,
                'help' => 'Le type de page duquel vous désirez débuter. Pour une page vierge, sélectionner « Page vierge ».'
            ],
            'name' => [
                'type' => 'text',
                'label' => 'Nom de la page',
                'placeholder' => 'ex: Pneumonie',
                'required' => true,
                'help' => 'Ce nom sera utilisé comme titre de la page. La page sera ensuite disponible dans la liste de vos brouillons sous ce nom.'
            ]
        ];
    }
    
    /**
     * @inheritdoc
     * */
    public function onSubmit($data)
    {
        if(!$data['name']) { return 'Nom de page manquant'; } // If a name was not provided.
        $draft = \Article::newFromTitle(\Title::newFromText($this->getUser()->getName().'/Brouillons/'.ucfirst($data['name']), NS_USER), \RequestContext::getMain())->getPage();
        
        if($this->getRequest()->getVal('wpDelete', false)) // If this is a request to delete a page.
        {
            $errors = [];
            if(!$draft->exists()) { return 'La page n\'existe pas'; }
            if(!$draft->doDeleteArticle('Suppression du brouillon', false, null, null, $errors, $this->getUser(), true))
            {
                return 'La suppression de la page a échouée.';
            }
            
            $this->getOutput()->redirect($this->getFullTitle()->getFullURL(['delete-success' => $draft->getTitle()->getSubpageText()]));
            return true;
        }
        
        // Else this is request to create a draft.
        if($data['class'] != 'empty')
        {
            $class = \Title::newFromText($data['class'].'/Prototype', NS_CLASS);
            
            // Do some validation.
            if(!$class->exists()) { return 'Classe invalide ou ne contient pas de prototype'; }
            if(MediaWikiServices::getInstance()->getPermissionManager()->userCan('view', $this->getUser(), $class))
            {
                return 'Vous n\'êtes pas autorisés à utiliser cette classe';
            }
            
            $prototype = \Article::newFromTitle($class, \RequestContext::getMain());
            $content = $prototype->getRevision()->getContent()->getNativeData();
            $content = str_replace(['<includeonly>' , '</includeonly>'], '', $content);
        }
        else { $content = ''; } // The user wanted a blan page.
        
        // Save the new draft.
        $status = $draft->doEditContent(\ContentHandler::makeContent( $content, $draft->getTitle()), 'Création du brouillon', 0, false, $this->getUser());
        
        $this->getOutput()->redirect($this->getFullTitle()->getFullURL(['success' => $draft->getTitle()->getSubpageText()]));
    }
}