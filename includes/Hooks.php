<?php

// phpcs:disable MediaWiki.NamingConventions.LowerCamelFunctionsName.FunctionName

namespace MediaWiki\Extension\Thanks;

use Article;
use DatabaseLogEntry;
use DifferenceEngine;
use EnhancedChangesList;
use LogEventsList;
use LogPage;
use MediaWiki\Api\ApiModuleManager;
use MediaWiki\Api\Hook\ApiMain__moduleManagerHook;
use MediaWiki\Auth\Hook\LocalUserCreatedHook;
use MediaWiki\Block\Hook\GetAllBlockActionsHook;
use MediaWiki\Cache\GenderCache;
use MediaWiki\Config\Config;
use MediaWiki\Config\ConfigException;
use MediaWiki\Context\IContextSource;
use MediaWiki\Context\RequestContext;
use MediaWiki\Diff\Hook\DifferenceEngineViewHeaderHook;
use MediaWiki\Diff\Hook\DiffToolsHook;
use MediaWiki\Extension\Thanks\Api\ApiFlowThank;
use MediaWiki\Hook\ChangesListInitRowsHook;
use MediaWiki\Hook\ContributionsLineEndingHook;
use MediaWiki\Hook\EnhancedChangesListModifyBlockLineDataHook;
use MediaWiki\Hook\EnhancedChangesListModifyLineDataHook;
use MediaWiki\Hook\GetLogTypesOnUserHook;
use MediaWiki\Hook\HistoryToolsHook;
use MediaWiki\Hook\LogEventsListLineEndingHook;
use MediaWiki\Hook\OldChangesListRecentChangesLineHook;
use MediaWiki\Hook\PageHistoryBeforeListHook;
use MediaWiki\Hook\PageHistoryPager__doBatchLookupsHook;
use MediaWiki\Html\Html;
use MediaWiki\Linker\LinkTarget;
use MediaWiki\MediaWikiServices;
use MediaWiki\Output\Hook\BeforePageDisplayHook;
use MediaWiki\Output\OutputPage;
use MediaWiki\Page\PageIdentityValue;
use MediaWiki\Pager\ContribsPager;
use MediaWiki\Permissions\PermissionManager;
use MediaWiki\Registration\ExtensionRegistry;
use MediaWiki\Revision\MutableRevisionRecord;
use MediaWiki\Revision\RevisionLookup;
use MediaWiki\Revision\RevisionRecord;
use MediaWiki\SpecialPage\SpecialPage;
use MediaWiki\Title\Title;
use MediaWiki\User\Options\UserOptionsManager;
use MediaWiki\User\User;
use MediaWiki\User\UserFactory;
use MediaWiki\User\UserIdentity;
use MediaWiki\User\UserIdentityValue;
use RecentChange;
use Skin;
use stdClass;

/**
 * Hooks for Thanks extension
 *
 * @file
 * @ingroup Extensions
 */
class Hooks implements
	ApiMain__moduleManagerHook,
	BeforePageDisplayHook,
	DiffToolsHook,
	DifferenceEngineViewHeaderHook,
	EnhancedChangesListModifyBlockLineDataHook,
	EnhancedChangesListModifyLineDataHook,
	GetAllBlockActionsHook,
	GetLogTypesOnUserHook,
	HistoryToolsHook,
	LocalUserCreatedHook,
	LogEventsListLineEndingHook,
	PageHistoryBeforeListHook,
	PageHistoryPager__doBatchLookupsHook,
	ChangesListInitRowsHook,
	OldChangesListRecentChangesLineHook,
	ContributionsLineEndingHook
{
	private Config $config;
	private GenderCache $genderCache;
	private PermissionManager $permissionManager;
	private RevisionLookup $revisionLookup;
	private UserFactory $userFactory;
	private UserOptionsManager $userOptionsManager;

	public function __construct(
		Config $config,
		GenderCache $genderCache,
		PermissionManager $permissionManager,
		RevisionLookup $revisionLookup,
		UserFactory $userFactory,
		UserOptionsManager $userOptionsManager
	) {
		$this->config = $config;
		$this->genderCache = $genderCache;
		$this->permissionManager = $permissionManager;
		$this->revisionLookup = $revisionLookup;
		$this->userFactory = $userFactory;
		$this->userOptionsManager = $userOptionsManager;
	}

	/**
	 * Handler for the HistoryTools hook
	 *
	 * @param RevisionRecord $revisionRecord
	 * @param array &$links
	 * @param RevisionRecord|null $oldRevisionRecord
	 * @param UserIdentity $userIdentity
	 */
	public function onHistoryTools(
		$revisionRecord,
		&$links,
		$oldRevisionRecord,
		$userIdentity
	) {
		$this->insertThankLink( $revisionRecord,
			$links, $userIdentity );
		// [UGC-4257] Don't show thank links if user doesn't have specific permission
		if ( !ThanksPermissions::checkUserPermissionsForThanks( RequestContext::getMain()->getOutput() ) ) {
			return;
		}

		$this->insertThankLink( $revisionRecord,
			$links, $userIdentity );
	}

	/**
	 * Handler for the DiffTools hook
	 *
	 * @param RevisionRecord $revisionRecord
	 * @param array &$links
	 * @param RevisionRecord|null $oldRevisionRecord
	 * @param UserIdentity $userIdentity
	 */
	public function onDiffTools(
		$revisionRecord,
		&$links,
		$oldRevisionRecord,
		$userIdentity
	) {
		$out = RequestContext::getMain()->getOutput();

		// [UGC-4257] Don't show thank links if user doesn't have specific permission
		if ( !ThanksPermissions::checkUserPermissionsForThanks( $out ) ) {
			return;
		}

		// Don't allow thanking for a diff that includes multiple revisions
		// This does a query that is too expensive for history rows (T284274)
		$previous = $this->revisionLookup->getPreviousRevision( $revisionRecord );
		if ( $oldRevisionRecord && $previous &&
			$previous->getId() !== $oldRevisionRecord->getId()
		) {
			return;
		}

		$this->insertThankLink( $revisionRecord,
			$links, $userIdentity, true );
	}

	/**
	 * Insert a 'thank' link into revision interface, if the user is allowed to thank.
	 *
	 * @param RevisionRecord $revisionRecord RevisionRecord object to add the thank link for
	 * @param array &$links Links to add to the revision interface
	 * @param UserIdentity $userIdentity The user performing the thanks.
	 * @param bool $isPrimaryButton whether the link/button should be progressive
	 */
	private function insertThankLink(
		RevisionRecord $revisionRecord,
		array &$links,
		UserIdentity $userIdentity,
		bool $isPrimaryButton = false
	) {
		$recipient = $revisionRecord->getUser();
		if ( $recipient === null ) {
			// Cannot see the user
			return;
		}

		$user = $this->userFactory->newFromUserIdentity( $userIdentity );

		// Don't let users thank themselves.
		// Exclude anonymous users.
		// Exclude temp users (T345679)
		// Exclude users who are blocked.
		// Check whether bots are allowed to receive thanks.
		// Don't allow thanking for a diff that includes multiple revisions
		// Check whether we have a revision id to link to
		if ( $user->isNamed()
			&& !$userIdentity->equals( $recipient )
			&& !$this->isUserBlockedFromTitle( $user, $revisionRecord->getPageAsLinkTarget() )
			&& !self::isUserBlockedFromThanks( $user )
			&& self::canReceiveThanks( $this->config, $this->userFactory, $recipient )
			&& !$revisionRecord->isDeleted( RevisionRecord::DELETED_TEXT )
			&& $revisionRecord->getId() !== 0
		) {
			$links[] = $this->generateThankElement(
				$revisionRecord->getId(),
				$user,
				$recipient,
				'revision',
				$isPrimaryButton
			);
		}
	}

	/**
	 * Check whether the user is blocked from the title associated with the revision.
	 *
	 * This queries the replicas for a block; if 'no block' is incorrectly reported, it
	 * will be caught by ApiThank::dieOnUserBlockedFromTitle when the user attempts to thank.
	 *
	 * @param User $user
	 * @param LinkTarget $title
	 * @return bool
	 */
	private function isUserBlockedFromTitle( User $user, LinkTarget $title ) {
		return $this->permissionManager->isBlockedFrom( $user, $title, true );
	}

	/**
	 * Check whether the user is blocked from giving thanks.
	 *
	 * @param User $user
	 * @return bool
	 */
	private static function isUserBlockedFromThanks( User $user ) {
		$block = $user->getBlock();
		return $block && ( $block->isSitewide() || $block->appliesToRight( 'thanks' ) );
	}

	/**
	 * Check whether a user is allowed to receive thanks or not
	 *
	 * @param Config $config
	 * @param UserFactory $userFactory
	 * @param UserIdentity $user Recipient
	 * @return bool true if allowed, false if not
	 */
	public static function canReceiveThanks(
		Config $config,
		UserFactory $userFactory,
		UserIdentity $user
	) {
		$legacyUser = $userFactory->newFromUserIdentity( $user );
		if ( !$user->isRegistered() || $legacyUser->isSystemUser() ) {
			return false;
		}

		if ( !$config->get( 'ThanksSendToBots' ) &&
			$legacyUser->isBot()
		) {
			return false;
		}

		return true;
	}

	/**
	 * Helper for self::insertThankLink
	 * Creates either a thank link or thanked span based on users session
	 * @param int $id Revision or log ID to generate the thank element for.
	 * @param User $sender User who sends thanks notification.
	 * @param UserIdentity $recipient User who receives thanks notification.
	 * @param string $type Either 'revision' or 'log'.
	 * @param bool $isPrimaryButton whether the link/button should be progressive
	 * @return string
	 */
	protected function generateThankElement(
		$id, User $sender, UserIdentity $recipient, $type = 'revision',
		bool $isPrimaryButton = false
	) {
		$useCodex = RequestContext::getMain()->getSkin()->getSkinName() === 'minerva';
		$class = $useCodex ? 'cdx-button cdx-button--fake-button cdx-button--fake-button--enabled' : '';
		if ( $isPrimaryButton && $useCodex ) {
			$class .= ' cdx-button--weight-primary cdx-button--action-progressive';
		}
		// Check if the user has already thanked for this revision or log entry.
		/**
		 * Fandom change - start - UGC-4533 - Cache thanks data in session in a better way
		 * @author Mkostrzewski
		 */
		if ( ( new ThanksCache(
			MediaWikiServices::getInstance()->getDBLoadBalancer(),
			MediaWikiServices::getInstance()->getMainConfig()
		) )->haveThanked( RequestContext::getMain(), $sender->getActorId(), $id, $type ) ) {
			// Fandom change - end
			$class .= ' mw-thanks-thanked';
			return Html::element(
				'span',
				[ 'class' => $class ],
				wfMessage( 'thanks-thanked', $sender->getName(), $recipient->getName() )->text()
			);
		}

		// Add 'thank' link
		$tooltip = wfMessage( 'thanks-thank-tooltip' )
			->params( $sender->getName(), $recipient->getName() )
			->text();

		$class .= ' mw-thanks-thank-link';
		$subpage = ( $type === 'revision' ) ? '' : 'Log/';
		return Html::element(
			'a',
			[
				'class' => $class,
				'href' => SpecialPage::getTitleFor( 'Thanks', $subpage . $id )->getFullURL(),
				'title' => $tooltip,
				'role' => 'button',
				'data-' . $type . '-id' => $id,
				'data-recipient-gender' => $this->genderCache->getGenderOf( $recipient->getName(), __METHOD__ ),
			],
			wfMessage( 'thanks-thank', $sender->getName(), $recipient->getName() )->text()
		);
	}

	/**
	 * @param OutputPage $outputPage The OutputPage to add the module to.
	 */
	protected function addThanksModule( OutputPage $outputPage ) {
		$confirmationRequired = $this->config->get( 'ThanksConfirmationRequired' );
		$outputPage->addModules( [ 'ext.thanks.corethank' ] );
		$outputPage->addJsConfigVars( 'thanks-confirmation-required', $confirmationRequired );
	}

	/**
	 * Handler for PageHistoryBeforeList hook.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageHistoryBeforeList
	 *
	 * @param Article $page Not used
	 * @param IContextSource $context RequestContext object
	 */
	public function onPageHistoryBeforeList( $page, $context ) {
		if ( $context->getUser()->isRegistered() ) {
			$this->addThanksModule( $context->getOutput() );
		}
	}

	public function onPageHistoryPager__doBatchLookups( $pager, $result ) {
		$userNames = [];
		foreach ( $result as $row ) {
			if ( $row->user_name !== null ) {
				$userNames[] = $row->user_name;
			}
		}
		if ( $userNames ) {
			// Batch lookup for the use of GenderCache::getGenderOf in self::generateThankElement
			$this->genderCache->doQuery( $userNames, __METHOD__ );
		}
	}

	public function onChangesListInitRows( $changesList, $rows ) {
		$userNames = [];
		foreach ( $rows as $row ) {
			if ( $row->rc_user_text !== null ) {
				$userNames[] = $row->rc_user_text;
			}
		}
		if ( $userNames ) {
			// Batch lookup for the use of GenderCache::getGenderOf in self::generateThankElement
			$this->genderCache->doQuery( $userNames, __METHOD__ );
		}
	}

	/**
	 * Handler for DifferenceEngineViewHeader hook.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/DifferenceEngineViewHeader
	 * @param DifferenceEngine $diff DifferenceEngine object that's calling.
	 */
	public function onDifferenceEngineViewHeader( $diff ) {
		if ( $diff->getUser()->isRegistered() ) {
			$this->addThanksModule( $diff->getOutput() );
		}
	}

	/**
	 * Handler for LocalUserCreated hook
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/LocalUserCreated
	 * @param User $user User object that was created.
	 * @param bool $autocreated True when account was auto-created
	 */
	public function onLocalUserCreated( $user, $autocreated ) {
		// New users get echo preferences set that are not the default settings for existing users.
		// Specifically, new users are opted into email notifications for thanks.
		if ( !$user->isTemp() && !$autocreated ) {
			$this->userOptionsManager->setOption( $user, 'echo-subscriptions-email-edit-thank', true );
		}
	}

	/**
	 * Handler for GetLogTypesOnUser.
	 * So users can just type in a username for target and it'll work.
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/GetLogTypesOnUser
	 * @param string[] &$types The list of log types, to add to.
	 */
	public function onGetLogTypesOnUser( &$types ) {
		$types[] = 'thanks';
	}

	public function onGetAllBlockActions( &$actions ) {
		$actions[ 'thanks' ] = 100;
	}

	/**
	 * Handler for BeforePageDisplay.  Inserts javascript to enhance thank
	 * links from static urls to in-page dialogs along with reloading
	 * the previously thanked state.
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/BeforePageDisplay
	 * @param OutputPage $out OutputPage object
	 * @param Skin $skin The skin in use.
	 */
	public function onBeforePageDisplay( $out, $skin ): void {
		$title = $out->getTitle();
		// Add to Flow boards.
		if ( $title instanceof Title && $title->hasContentModel( 'flow-board' ) ) {
			$out->addModules( 'ext.thanks.flowthank' );
		}
		// Add to special pages where thank links appear
		if (
			$title->isSpecial( 'Log' ) ||
			$title->isSpecial( 'Contributions' ) ||
			$title->isSpecial( 'DeletedContributions' ) ||
			$title->isSpecial( 'Recentchanges' ) ||
			$title->isSpecial( 'Recentchangeslinked' ) ||
			$title->isSpecial( 'Watchlist' )
		) {
			$this->addThanksModule( $out );
		}
	}

	/**
	 * Conditionally load API module 'flowthank' depending on whether or not
	 * Flow is installed.
	 *
	 * @param ApiModuleManager $moduleManager Module manager instance
	 */
	public function onApiMain__moduleManager( $moduleManager ) {
		if ( ExtensionRegistry::getInstance()->isLoaded( 'Flow' ) ) {
			$moduleManager->addModule(
				'flowthank',
				'action',
				[
					"class" => ApiFlowThank::class,
					"services" => [
						"PermissionManager",
						"ThanksLogStore",
						"UserFactory",
					]
				]
			);
		}
	}

	/**
	 * Insert a 'thank' link into the log interface, if the user is allowed to thank.
	 *
	 * @link https://www.mediawiki.org/wiki/Manual:Hooks/LogEventsListLineEnding
	 * @param LogEventsList $page The log events list.
	 * @param string &$ret The line ending HTML, to modify.
	 * @param DatabaseLogEntry $entry The log entry.
	 * @param string[] &$classes CSS classes to add to the line.
	 * @param string[] &$attribs HTML attributes to add to the line.
	 * @throws ConfigException
	 */
	public function onLogEventsListLineEnding(
		$page, &$ret, $entry, &$classes, &$attribs
	) {
		$user = $page->getUser();

		// Don't provide thanks link if not named, blocked or if user is deleted from the log entry
		if (
			!$user->isNamed()
			|| $entry->isDeleted( LogPage::DELETED_USER )
			|| $this->isUserBlockedFromTitle( $user, $entry->getTarget() )
			|| self::isUserBlockedFromThanks( $user )
		) {
			return;
		}

		// Make sure this log type is allowed.
		$allowedLogTypes = $this->config->get( 'ThanksAllowedLogTypes' );
		if ( !in_array( $entry->getType(), $allowedLogTypes )
			&& !in_array( $entry->getType() . '/' . $entry->getSubtype(), $allowedLogTypes ) ) {
			return;
		}

		// Don't thank if no recipient,
		// or if recipient is the current user or unable to receive thanks.
		// Don't check for deleted revision (this avoids extraneous queries from Special:Log).

		$recipient = $entry->getPerformerIdentity();
		if ( $recipient->getId() === $user->getId() ||
			!self::canReceiveThanks( $this->config, $this->userFactory, $recipient )
		) {
			return;
		}

		// Create thank link either for the revision (if there is an associated revision ID)
		// or the log entry.
		$type = $entry->getAssociatedRevId() ? 'revision' : 'log';
		$id = $entry->getAssociatedRevId() ?: $entry->getId();
		$thankLink = $this->generateThankElement( $id, $user, $recipient, $type );

		// Add parentheses to match what's done with Thanks in revision lists and diff displays.
		$ret .= ' ' . wfMessage( 'parentheses' )->rawParams( $thankLink )->escaped();
	}

	/**
	 * Fandom change UGC-4012 - Add thank link to the recent changes list
	 *
	 * @link https://www.mediawiki.org/wiki/Manual:EnhancedChangesListModifyLineDataHook.php
	 * @param EnhancedChangesList $changesList
	 * @param array &$data
	 * @param RecentChange[] $block
	 * @param RecentChange $rc
	 * @param string[] &$classes
	 * @param string[] &$attribs
	 * @return void
	 */
	public function onEnhancedChangesListModifyLineData( $changesList, &$data, $block, $rc, &$classes, &$attribs ) {
		if ( !in_array( 'ext.thanks.corethank', $changesList->getOutput()->getModules() ) ) {
			$this->addThanksModule( $changesList->getOutput() );
		}

		$revision = $this->getRevisionForRecentChange( $rc );
		if ( $revision ) {
			$this->insertThankLink(
				$revision,
				$data,
				$changesList->getUser()
			);
		}
	}

	public function onOldChangesListRecentChangesLine( $changeslist, &$s, $rc, &$classes, &$attribs ) {
		if ( !in_array( 'ext.thanks.corethank', $changeslist->getOutput()->getModules() ) ) {
			$this->addThanksModule( $changeslist->getOutput() );
		}

		$revision = $this->getRevisionForRecentChange( $rc );
		if ( $revision ) {
			$holder = [];
			$this->insertThankLink(
				$revision,
				$holder,
				$changeslist->getUser()
			);

			if ( count( $holder ) ) {
				$s .= ' ' . $holder[0];
			}
		}
	}

	/**
	 * Fandom change UGC-4012 - Add thank link to the recent changes list
	 *
	 * @link https://www.mediawiki.org/wiki/Manual:EnhancedChangesListModifyBlockLineDataHook.php
	 * @param EnhancedChangesList $changesList
	 * @param array &$data
	 * @param RecentChange $rc
	 * @return void
	 */
	public function onEnhancedChangesListModifyBlockLineData( $changesList, &$data, $rc ): void {
		if ( !in_array( 'ext.thanks.corethank', $changesList->getOutput()->getModules() ) ) {
			$this->addThanksModule( $changesList->getOutput() );
		}
		$revision = $this->getRevisionForRecentChange( $rc );
		if ( $revision ) {
			$this->insertThankLink(
				$revision,
				$data,
				$changesList->getUser()
			);
		}
	}

	/**
	 * Convenience function to get the {@link RevisionRecord} corresponding to a RecentChanges entry.
	 * This is an optimization to avoid triggering a query to fetch revision data for each RecentChanges entry.
	 * Instead, the revision is constructed entirely using data from the RecentChanges entry itself (UGC-4379).
	 *
	 * @param RecentChange $recentChange The RecentChanges entry to get the revision for.
	 * @return RevisionRecord|null The {@link RevisionRecord} object corresponding to the given RecentChanges entry,
	 * or {@code null} if the entry does not correspond to an article edit.
	 */
	private function getRevisionForRecentChange( RecentChange $recentChange ): ?RevisionRecord {
		$page = $recentChange->getPage();
		if ( $page === null ) {
			return null;
		}

		$pageId = $recentChange->getAttribute( 'rc_cur_id' );
		$revId = $recentChange->getAttribute( 'rc_this_oldid' );
		if (
			!in_array( $recentChange->getAttribute( 'rc_type' ), [ RC_EDIT, RC_NEW ] ) ||
			!$pageId ||
			!$revId
		) {
			return null;
		}

		$page = PageIdentityValue::localIdentity( $pageId, $page->getNamespace(), $page->getDBkey() );

		// Initialize the author associated with this revision.
		// Note that this cannot use RecentChange::getPerformerIdentity(),
		// as it would trigger a database lookup for each entry.
		$user = new UserIdentityValue(
			(int)$recentChange->getAttribute( 'rc_user' ),
			$recentChange->getAttribute( 'rc_user_text' )
		);

		$revRecord = new MutableRevisionRecord( $page );
		$revRecord->setId( $revId );
		$revRecord->setVisibility( (int)$recentChange->getAttribute( 'rc_deleted' ) );
		$revRecord->setUser( $user );

		return $revRecord;
	}

	/**
	 * Fandom change UGC-4012 - Add thank link to Special:Contributions page
	 * @param ContribsPager $pager
	 * @param string &$line
	 * @param stdClass $row
	 * @param array &$classes
	 * @param array &$attribs
	 * @return void
	 */
	public function onContributionsLineEnding( $pager, &$line, $row, &$classes, &$attribs ): void {
		$out = RequestContext::getMain()->getOutput();

		// [UGC-4257] Don't show thank links if user doesn't have specific permission
		if ( !ThanksPermissions::checkUserPermissionsForThanks( $out ) ) {
			return;
		}

		if ( !in_array( 'ext.thanks.corethank', $out->getOutput()->getModules() ) ) {
			$this->addThanksModule( $out->getOutput() );
		}
		$revLookup = MediaWikiServices::getInstance()->getRevisionLookup();
		$links = [];
		$this->insertThankLink(
			$revLookup->getRevisionById( $row->rev_id ),
			$links,
			$out->getUser()
		);
		if ( isset( $links[0] ) ) {
			// [UGC-4257] Wrap the thank link in a span so that it can be styled
			$linkWithSpanParent = "<span class='mw-thanks-link-wrapper--contributions'>$links[0]</span>";
			$line .= $linkWithSpanParent;
		}
	}
}
