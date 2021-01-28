<?php
/**
 * Drafts extension main class.
 * 
 * @file
 * @author Antoine Mercier-Linteau
 * @license GNU
 */

namespace MediaWiki\Extension;

class Drafts 
{
	/**
	 * PersonalUrls hook handler.
	 *
	 * @param array &$personalUrls
	 * @param Title &$title (unused)
	 * @param Skin $skin
	 * @return bool true
	 */
	public static function onPersonalUrls(&$personalUrls, &$title, $skin) 
	{
	    // Do not show for anonymous users.
		if($skin->getUser()->isAnon()) { return true; }

		$newPersonalUrls = [];
        
		$link = [
		    'id' => 'pt-drafts',
		    'text' => $skin->msg( 'drafts-link-label' )->text(),
		    'title' => $skin->msg( 'drafts-link-title' )->text(),
		    'href' => \SpecialPage::getSafeTitleFor('Drafts')->getLocalURL(['user' => $skin->getUser()->getName()]),
		    'exists' => true,
		];
		
		// Insert our link before the link to user preferences.
		// If the link to preferences is missing, insert at the end.
		foreach($personalUrls as $key => $value) 
		{
			if($key === 'preferences') { $newPersonalUrls['drafts'] = $link; }
			$newPersonalUrls[$key] = $value;
		}
		
		if (!array_key_exists('drafts', $newPersonalUrls)) 
		{
			$newPersonalUrls['drafts'] = $link;
		}

		$personalUrls = $newPersonalUrls;
		
		return true;
	}

	/**
	 * Add links to the sidebar toolbox.
	 * @param BaseTemplate $baseTemplate
	 * @param array $toolbox
	 */
	public static function onBaseTemplateToolbox($baseTemplate, &$toolbox )
	{
		$skin = $baseTemplate->getSkin();
		$title = $skin->getTitle()->getRootTitle();

		// Only display in user space.
		if(!in_array($title->getNamespace(), [NS_USER, NS_USER_TALK])) { return; }

		$title = \MediaWiki\MediaWikiServices::getInstance()->getNamespaceInfo()->getSubjectPage($title); // Make sure we are getting a subject page.

		$toolbox['drafts'] = [
			'text' => $skin->msg('drafts-toolbox-text'),
			'href' => \Title::newFromText('Special:Index/Utilisateur:'.$title->getText().'/Brouillons')->getLocalUrl(),
			'id' => 't-drafts'
		];
	}

	/**
	 * Add Drafts category.
	 *
	 * @param Parser $parser
	 * @param string $text The html output
	 * @param StripState $stripState
	 */
	public static function ParserAfterParse($parser, $text, $stripState) 
	{
		if($parser->getTitle()->getNamespace() == NS_USER 
			&& $parser->getTitle()->isSubPage() 
			&& strpos($parser->getTitle()->getFullText(), '/Brouillons/') !== false
		)
		{
			$parser->getOutput()->setCategoryLinks(['Brouillons' => 0]);
		}
	}
}