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
            $t.= '<div class="success">Le brouillon [['.$user->getUserPage()->getText().'/'.$val.'|'.$val.']] a été créé avec succès</div>';
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
                $form = new OOUI\FormLayout();
                
                $form->addItems([
                    new OOUI\HorizontalLayout(['label' => '', 'classes' => 'container', 'items' => [
                        new OOUI\FieldLayout(new OOUI\ButtonInputWidget(['type' => 'submit', 'label' =>'Supprimer', 'flags' => ['destructive']])),
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
        $class = \Title::newFromText($data['class'].'/Prototype', NS_CLASS);
        
        // Do some validation.
        if(!$class->exists()) { return 'Classe invalide ou ne contient pas de prototype'; }
        if(MediaWikiServices::getInstance()->getPermissionManager()->userCan('view', $this->getUser(), $class))
        {
            return 'Vous n\'êtes pas autorisés à utiliser cette classe';
        }
        
        $prototype = \Article::newFromTitle($class, \RequestContext::getMain());
        $draft = \Article::newFromTitle(\Title::newFromText($this->getUser()->getName().'/Brouillons/'.ucfirst($data['name']), NS_USER), \RequestContext::getMain())->getPage();
        
        // Save the new draft.
        $content = \ContentHandler::makeContent( $prototype->getRevision()->getContent()->getNativeData(), $draft->getTitle());
        $content = str_replace(['<noinclude>' , '</noinclude>'], '', $content);
        $status = $draft->doEditContent($content, 'Création du brouillon', 0, false, $this->getUser());
        
        $this->getOutput()->redirect($this->getURL(['success' => $draft->getTitle()->getText()]));
    }
    
    /**
     * @inheritdoc
     * */
    /*public function execute( $params ) 
    {
        $request = $this->getRequest();
        $output = $this->getOutput();
        $output->enableOOUI();
        $user = $this->getUser();
        $db = wfGetDB( DB_REPLICA ); //\MediaWiki\MediaWikiServices::getInstance()->getDBLoadBalancer()->getConnection(DB_REPLICA);
        $this->setHeaders();
        
        // Retrieve all the subpages from the database.
        $titleArray = \TitleArray::newFromResult($db->select( 
            'page',
            [ 'page_id', 'page_namespace', 'page_title', 'page_is_redirect' ],
            [
                'page_is_redirect' => 1, 
                'page_namespace' => NS_USER,
                'page_title'.$db->buildLike($user->getName().'/', $db->anyString())
            ],
            __METHOD__,
            []
            )
        );
        
        // Build the HTML / Wikicode of the page.
        
        $t = "== Brouillons en cours ==
{| class=\"wikitable\"
|-
! Titre !! Dernière modification !! Actions
|-      \n";
        
        if(!$titleArray->count()) // There are no drafts.
        {
            $t .= "|colspan=\"3\"|''Aucun brouillon''\n|}";
        }
        else
        {
        }
        
        $t .= "\n== Nouveau brouillon ==";
        $output->addWikiTextAsContent($t);
        
        $form = new OOUI\FormLayout();
        
        $classes = new OOUI\DropdownInputWidget();
        $classes->setTitle('Sélectionnez une classe')->setOptions([[
                'data' => 'a',
                'label' => 'Maladie'
            ]
        ]);
        
        $form->addItems([
            new OOUI\FieldsetLayout(['label' => '', 'classes' => 'container', 'items' => [
                new OOUI\FieldLayout($classes, [
                    'label' => 'Classe de la page', 
                    'align' => 'top']),
                new OOUI\FieldLayout(new OOUI\TextInputWidget([
                    'name' => 'titre', 
                    'placeholder' => 'ex: Pneumonie', 
                    'maxLength' => 30, 
                    'required' => true,
                ]), [
                    'label' => 'Nom de la page', 
                    'align' => 'top',
                    'help' => 'Ce nom sera utilisé comme titre de la page. La page sera ensuite disponible dans la liste de vos brouillons sous ce nom.'
                ]),
                new OOUI\FieldLayout(new OOUI\ButtonInputWidget(['type' => 'submit', 'label' =>'Créer']))
            ]])   
        ]);
        $output->addHTML($form);
    }*/
}