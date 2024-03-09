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
use RequestContext;

/**
 * @inheritdoc
 * */
class SpecialCreateNewPage extends \FormSpecialPage 
{
    /** @var Database */
    protected $db;
    
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
            if($p[$i] == 'name' && isset($p[$i + 1]))
            {
                /* name must be followed by an argument. This argument is urlencoded
                 * and then % needs to be replaced with $ for it to go through. No idea
                 * why. */
                $this->name = urldecode(str_replace('$', '%', $p[$i + 1]));
                $i++;

                break;
            }
        }

		$form = $this->getForm();
        $form->show();

		// GET forms can be set as includable
		if ( !$this->including() ) {
			//$result = $this->getShowAlways() ? $form->showAlways() : $form->show();
		} else {
			$result = $form->prepareForm()->tryAuthorizedSubmit();
		}

		if ( $result === true || ( $result instanceof Status && $result->isGood() ) ) {
			$this->onSuccess();
		}
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
        
        $classes = [];
        
        // Build an array of the class names.
        foreach($titleArray as $title)
        {
            if($title->isSubpage()) { continue; } // Skip subpages.
            if(!\Title::newFromText($title->getText().'/Prototype', NS_CLASS)->exists())
            {
                continue; // Skip this class if it does not have a prototype.
            }
            
            $classes[ $title->getDBkey() ] = $title->getText(); 
        }
        
        natcasesort($classes);
        $classes = array_flip($classes); // Needed because the Form object expects the items inverted (label => value).

        $classes = ['Sélectionnez une classe' => ''] + $classes;
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
        
        $title = \Title::newFromText($_REQUEST['title']); // Title of the current page being requested (not necessarily this special page, which might be an inclusion).

        if($title == null) {
            // This was not meant for us. Probably a page deletion in Special:Drafts. Just return the form normally.
            
            $form['createAsDraft'] = [
                'type' => 'submit',
                'buttonlabel' => 'Créer', 
            ];
            
            return $form;
        }

        if($title->getNamespace() == NS_SPECIAL && $title->getText() == 'Drafts')
        {
            // The special page is being called within the drafts page.
            $form['createAsDraft'] = [
                'type' => 'submit',
                'buttonlabel' => 'Créer', 
            ];
        }
        else
        {            
            if($title->getNamespace() != NS_USER && !strpos($title->getText(), 'Brouillons')) {
                // Don't display that button if we are already in the user's Draft space.
                $form['createAsDraft'] = [
                    'type' => 'submit',
                    'buttonlabel' => 'Créer dans mes brouillons',
                ];
            }
            
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
        
        if(!$this->name) {
            if(!isset($data['name'])) { return 'Nom de la page manquant'; } // If a class was not provided.
            $this->name = $data['name'];
        }

        if($data['class'] != 'empty')
        {
            $class = \Title::newFromText($data['class'].'/Prototype', NS_CLASS);
            
            // Do some validation.
            if(!$class->exists()) { return 'Classe invalide ou ne contient pas de prototype'; }
            if(MediaWikiServices::getInstance()->getPermissionManager()->userCan('view', $this->getUser(), $class))
            {
                return 'Vous n\'êtes pas autorisés à utiliser cette classe';
            }
        }
        else { $content = ''; } // The user wanted a blank page.

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

    /**
     * @inheritdoc
     * */
    public function alterForm( $form )
    {
        $form->suppressDefaultSubmit();

        return $form;
    }	

}