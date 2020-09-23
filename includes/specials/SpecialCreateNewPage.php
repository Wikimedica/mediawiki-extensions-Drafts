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
use Wikimedia\Rdbms\Database;

/**
 * @inheritdoc
 * */
class SpecialCreateNewPage extends \FormSpecialPage 
{
    /** @var Database */
    protected $db;
    
    /** @var boolean if the drafts should be created or preloaded. */
    protected $create = false;

    
    /** @var string a prefix to be prepended to all pages created. */
    protected $prefix = '';
    
    /** @var string the name of the page to return to. */
    protected $return = '';
    
    /** @var string a name for the page to be created. */
    protected $name = '';
    
    /**
     * @inheritdoc
     * */
    public function __construct() 
    {
        parent::__construct('CreateNewPage', 'edit');
        $this->db = wfGetDB( DB_REPLICA );
        $this->mIncludable = true;
    }
    
    /**
     * @inheritdoc
     * */
    public function execute($par)
    {
        // Format the parameters passed to this special page.
        $p = explode('/', $par);
        for($i = 0; $i < count($p); $i++)
        {
            if($p[$i] == 'create') { $this->create = true; }
            else if($p[$i] == 'prefix' && isset($p[$i + 1]))
            {
                /* prefix must be followed by an argument. This argument is urlencoded
                 * and then % needs to be replaced with $ for it to go through. No idea
                 * why. */
                $this->prefix = urldecode(str_replace('$', '%', $p[$i + 1]));
                $i++;
            }
            else if($p[$i] == 'return' && isset($p[$i + 1]))
            {
                /* return must be followed by an argument. This argument is urlencoded
                 * and then % needs to be replaced with $ for it to go through. No idea
                 * why. */
                $this->return = urldecode(str_replace('$', '%', $p[$i + 1]));
                $i++;
            }
            else if($p[$i] == 'name' && isset($p[$i + 1]))
            {
                /* name must be followed by an argument. This argument is urlencoded
                 * and then % needs to be replaced with $ for it to go through. No idea
                 * why. */
                $this->name = urldecode(str_replace('$', '%', $p[$i + 1]));
                $i++;
            }
        }

        parent::execute($par);
    }
    
    /**
     * @inheritdoc
     **/
    protected function getDisplayFormat() {	return 'ooui'; }
    
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
        
        $form =  [
            'class' => [
                'type' => 'select',
                'label' => 'Gabarit (classe)',
                'options' => $classes,
                'required' => true,
                'help' => 'Le type de page duquel vous désirez débuter. Pour une page vierge, sélectionner « Page vierge ».'
            ],
        ];
        
        if(!$this->name) // If the user needs to specify a name.
        {
            $form['name'] = [
                'type' => 'text',
                'label' => 'Nom de la page',
                'placeholder' => 'ex: Pneumonie',
                'required' => true,
                'help' => 'Ce nom sera utilisé comme titre de la page.'
            ];
        }
        
        if($this->create)
        {
            // The special page is being called within the drafts page.
            $form['create'] = [
                'type' => 'submit',
                'buttonlabel' => 'Créer', 
            ];
        }
        else
        {            
            $form['createAsDraft'] = [
                'type' => 'submit',
                'buttonlabel' => 'Créer dans mes brouillons',
            ];
            
            $form['createInPlace'] = [
                'type' => 'submit',
                'buttonlabel' => 'Créer',
                'flags' => ['normal']
            ];

            // This does not work (no idea why), set it in Common.css instead.
            $this->getOutput()->addInlineStyle('            
            .mw-htmlform-field-HTMLSubmitField
            {
                display: inline-block;
                margin-right: 1em;
            }');
        }
        return $form;
    }
    
    /**
     * @inheritdoc
     * */
    public function onSubmit($data)
    {           
        if(!isset($data['class'])) { return 'Nom de classe manquant'; } // If a class was not provided.

        if($data['class'] != 'empty')
        {
            $class = \Title::newFromText($data['class'].'/Prototype', NS_CLASS);
            
            // Do some validation.
            if(!$class->exists()) { return 'Classe invalide ou ne contient pas de prototype'; }
            if(MediaWikiServices::getInstance()->getPermissionManager()->userCan('view', $this->getUser(), $class))
            {
                return 'Vous n\'êtes pas autorisés à utiliser cette classe';
            }
            
            if($this->create) // The user wants to create a new blank page with preloaded content.
            {
                $prototype = \Article::newFromTitle($class, \RequestContext::getMain());
                $content = $prototype->getRevision()->getContent()->getNativeData();
                $content = str_replace(['<includeonly>' , '</includeonly>'], '', $content);
            }
        }
        else { $content = ''; } // The user wanted a blank page.
        
        if(!$this->create) // If the user just wanted a redirection to a page with preloaded content.
        {
            // Preload a page within the user's drafts.
            if(isset($data['createAsDraft']) && $data['createAsDraft'] === true)
            {
                $title = \Title::newFromText($this->getUser()->getName().'/Brouillons/'.ucfirst($this->name), NS_USER);
            }
            // Preload the page at the required title.
            else // if(isset($data['createInPlace']) && $data['createInplace'] === true)
            {
                $title = \Title::newFromText(ucfirst($this->name));
            }

            $this->getOutput()->redirect($title->getFullURL([
                'veaction' => 'edit',
                'preload' => $data['class'] == 'empty' ? '': $class->getFullText()
            ]));

            return;
        }
        
        if(!isset($data['name'])) { return 'Nom de page manquant'; } // If a name was not provided.
        $draft = \Article::newFromTitle(\Title::newFromText($this->prefix.'/'.ucfirst($data['name'])), \RequestContext::getMain())->getPage();
        
        /* Save the new draft. Allows overwriting an existing page with preloaded content, something 
         * MediaWiki does not allow. */
        $status = $draft->doEditContent(\ContentHandler::makeContent( $content, $draft->getTitle()), 'Création du brouillon', 0, false, $this->getUser());
        
        if($this->return) // Return to a specific URL.
        {
            $this->getOutput()->redirect(\Title::newFromText($this->return)->getFullURL(['success' => $draft->getTitle()->getSubpageText()]));
            return;
        }
        
        return 'La page a été crée avec succès.';
    }

    /**
     * @inheritdoc
     * */
    public function alterForm( $form )
    {
        global $wgOut;
        
        $form->suppressDefaultSubmit();

        return $form;
    }	

}