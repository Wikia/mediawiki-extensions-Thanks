<?php

namespace MediaWiki\Extension\Thanks;

use Fandom\Includes\Mobile\MobileHelper;
use MediaWiki\MediaWikiServices;
use MobileContext;

class ThanksPermissions {
	private const REQUIRE_USER_GROUPS = [ 'sysop', 'content-moderator', 'threadmoderator', 'rollback', 'staff',
	'soap', 'wiki-representative', 'wiki-specialist' ];

	private static function isMobile() {
		if ( class_exists( 'MobileContext' ) ) {
			/** @var MobileContext $mobileContext */
			$mobileContext = MediaWikiServices::getInstance()->getService( 'MobileFrontend.Context' );

			return $mobileContext->shouldDisplayMobileView();
		}

		return false;
	}

	private const REQUIRE_USER_GROUPS = [ 'sysop', 'content-moderator', 'threadmoderator', 'rollback', 'staff',
	'soap', 'wiki-representative', 'wiki-specialist' ];

	/**
	 * Check if the user is allowed to send thanks on pages:
	 * - Desktop Special:Contributions
	 * - Desktop Special:History
	 * - Mobile  Special:History
	 * - Mobile  Special:Diff
	 * @param \OutputPage $out
	 * @return bool
	 */
	public static function checkUserPermissionsForThanks( $out ) {
		$user = $out->getUser();

		if ( $user->isAnon() ) {
			return false;
		}

		$isSpecialHistory = $out->getTitle()->isSpecial( 'History' );
		$isMobileDiff = $out->getTitle()->isSpecial( 'MobileDiff' );
		$isDiff = boolval( $out->getRequest()->getVal( 'diff' ) );
		$isSpecialContributions = $out->getTitle()->isSpecial( 'Contributions' );
		$isMobile = MobileHelper::isMobile();

		if ( !( $isMobileDiff || $isDiff || $isSpecialContributions || ( $isSpecialHistory && $isMobile ) ) ) {
			return true;
		}

		$userGroupManager = MediaWikiServices::getInstance()->getUserGroupManager();
		$userGroups = $userGroupManager->getUserEffectiveGroups( $user );

		return !empty( array_intersect( $userGroups, self::REQUIRE_USER_GROUPS ) );
	}
}
