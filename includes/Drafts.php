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
		$title = $skin->getTitle();

		// Only display in user space.
		if(!in_array($title->getNamespace(), [NS_USER, NS_USER_TALK])) { return; }

		$toolbox['drafts'] = [
			'text' => $skin->msg('drafts-toolbox-text'),
			'href' => \Title::newFromText('Special:Index/'.$title->getFullText().'/Brouillons')->getLocalUrl(),
			'id' => 't-drafts'
		];
	}
}
