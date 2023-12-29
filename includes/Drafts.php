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
	 *Modify the personnal URLs
	 * SkinTemplate $skinTemplate
	 * array &$links
	 */
	public static function onSkinTemplateNavigation_Universal( $skinTemplate, &$links ) {
	    // Do not show for anonymous users.
		if($skinTemplate->getUser()->isAnon()) { return true; }
        
		$link = [
		    'id' => 'pt-drafts',
		    'text' => $skinTemplate->msg( 'drafts-link-label' )->text(),
		    'title' => $skinTemplate->msg( 'drafts-link-title' )->text(),
		    'href' => \SpecialPage::getSafeTitleFor('Drafts')->getLocalURL(['user' => $skinTemplate->getUser()->getName()]),
		    'exists' => true,
			'icon' => 'edit'
		];
		
		$newPersonalUrls = [];

		// Insert our link before the link to user preferences.
		// If the link to preferences is missing, insert at the end.
		foreach($links['user-menu'] as $key => $value) 
		{
			if($key === 'preferences') { $newPersonalUrls['drafts'] = $link; }
			$newPersonalUrls[$key] = $value;
		}
		
		if (!array_key_exists('drafts', $newPersonalUrls)) 
		{
			$newPersonalUrls['drafts'] = $link;
		}

		$links['user-menu'] = $newPersonalUrls;
		
		return true;
	}

	/**
	 * Add links to the sidebar toolbox.
	 * @param Skin $skin
	 * @param array $bar
	 */
	public static function onSidebarBeforeOutput( $skin, &$bar )
	{
		$title = $skin->getTitle()->getRootTitle();

		// Only display in user space.
		if(!in_array($title->getNamespace(), [NS_USER, NS_USER_TALK])) { return; }

		$title = \MediaWiki\MediaWikiServices::getInstance()->getNamespaceInfo()->getSubjectPage($title); // Make sure we are getting a subject page.

		$bar['TOOLBOX'][] = [
			'text' => $skin->msg('drafts-toolbox-text'),
			'href' => \Title::newFromText('Special:Index/Utilisateur:'.$title->getText().'/Brouillons')->getLocalUrl(),
			'id' => 't-drafts'
		];
	}

	/**
	 * Add Drafts category and prevent draft pages from getting indexed.
	 *
	 * @param Parser $parser
	 * @param string $text The html output
	 * @param StripState $stripState
	 */
	public static function ParserAfterParse($parser, $text, $stripState) 
	{
		if($parser->getTitle()->getNamespace() == NS_USER 
			&& $parser->getTitle()->isSubPage() 
			&& strpos($parser->getTitle()->getText(), '/Brouillons/') !== false
		)
		{
			// Category => Sort key
			$parser->getOutput()->setCategoryLinks(['Brouillons' => $parser->getTitle()->getSubpageText()]);
			
			// Prevent draft pages from getting indexed.
			global $wgOut;
			$wgOut->setRobotPolicy('noindex,nofollow');
		}
	}
}