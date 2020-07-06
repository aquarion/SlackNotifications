<?php

use \Sebbmyr\Teams\TeamsConnector;

// include("./WikiUpdateCard.php");

class TeamsNotifications
{


	/**
	 * Gets nice HTML text for article containing the link to article page
	 * and also into edit, delete and article history pages.
	 */
	static function getText(WikiPage $article, $summary)
	{
		global $wgTeamsIncludeDiffSize;

		$return = "";

		$fullArticle = $article->getTitle()->getFullText();
		$trimedArticle = substr($fullArticle, 0, strpos(wordwrap($fullArticle, 240), "\n"));

		if($summary){
			$return = $summary."\n\n";
		}
		
		$return .= $trimedArticle;

		if ($wgTeamsIncludeDiffSize) {		
			$return .= sprintf(
				" (%+d bytes)",
				$article->getRevision()->getSize() - $article->getRevision()->getPrevious()->getSize());
		}

		return $return;
	}

	static function setupCard($title, $user){
		global $wgSitename;
		global $teamsWikiUrl, $teamsWikiUrlEnding, $wgUser, $teamsWikiUrlEndingHistory, $teamsWikiUrlEndingUserPage;

		wfDebugLog( 'Teams', "Creating Card" );
		$card = new \WikiUpdateCard();

		$card->addFact("User", $user, $teamsWikiUrl.$teamsWikiUrlEnding.$teamsWikiUrlEndingUserPage.$user);
		// if($title){
		// 	$url   = $teamsWikiUrl.$teamsWikiUrlEnding.$title;
		// 	$card->addLink($title, $url);
		// 	$card->addLink("history", $url.'&'.$teamsWikiUrlEndingHistory);
		// 	$card->title = $title;
		// }

		if($title){
			
			// if(is_string($title)){
			// 	$title = Title::newFromText( $title, $defaultNamespace=NS_MAIN );
			// }
			$titleName = $title->getText();
			$url   = $title->getFullURL();
			$card->addLink($titleName, $url);
			$card->addLink("history", $url.'?action=history');
			$card->title = $titleName;
		}
		
		$card->theme_colour = '';
		$card->wiki_name = $wgSitename;

		return $card;
	}

	/**
	 * Occurs after the save page request has been processed.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/PageContentSaveComplete
	 */
	static function Teams_article_saved(WikiPage $article, $user, $content, $summary, $isMinor, $isWatch, $section, $flags, $revision, $status, $baseRevId)
	{
		global $wgTeamsNotificationEditedArticle;
		global $wgTeamsIgnoreMinorEdits, $wgTeamsIncludeDiffSize;
		if (!$wgTeamsNotificationEditedArticle) {
			 wfDebugLog( 'Teams', "[TEAMS] Not sending notification, turned off");
			return;
		}
		// Discard notifications from excluded pages
		global $wgTeamsExcludeNotificationsFrom;
		if (count($wgTeamsExcludeNotificationsFrom) > 0) {
			foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) {
					 wfDebugLog( 'Teams', "[TEAMS] Not sending notification, excluded");
					return;
				}
			}
		}

		// Discard notifications from non-included pages
		global $wgTeamsIncludeNotificationsFrom;
		if (count($wgTeamsIncludeNotificationsFrom) > 0) {
			foreach ($wgTeamsIncludeNotificationsFrom as &$currentInclude) {
				if (0 !== strpos($article->getTitle(), $currentInclude)) {
					 wfDebugLog( 'Teams', "[TEAMS] Not sending notification, not included");
					return;
				}
			}
		}

		// Skip new articles that have view count below 1. Adding new articles is already handled in article_added function and
		// calling it also here would trigger two notifications!
		$isNew = $status->value['new']; // This is 1 if article is new
		if ($isNew == 1) {
			
			 wfDebugLog( 'Teams', "[TEAMS] Not sending notification, new Article");
			return true;
		}

		// Skip minor edits if user wanted to ignore them
		if ($isMinor && $wgTeamsIgnoreMinorEdits) {
			 wfDebugLog( 'Teams', "Minor edit");
			return;
		};
		
		if ( $article->getRevision()->getPrevious() == NULL )
		{
			
			 wfDebugLog( 'Teams', "[TEAMS] Just a refresh");
			return; // Skip edits that are just refreshing the page
		}

		$card = self::setupCard($article->getTitle(), $user);

		$action = ($isMinor == true ? " made minor edit to " : " edited ");
		$card->action = $user.$action.$article->getTitle();
		$card->theme_colour = "yellow";
		$card->text = self::getText($article, $summary);		
		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Occurs after a new article has been created.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleInsertComplete
	 */
	static function Teams_article_inserted(WikiPage $article, $user, $text, $summary, $isminor, $iswatch, $section, $flags, $revision)
	{
		global $wgTeamsNotificationAddedArticle, $wgTeamsIncludeDiffSize;
		if (!$wgTeamsNotificationAddedArticle) return;

		// Discard notifications from excluded pages
		global $wgTeamsExcludeNotificationsFrom;
		if (count($wgTeamsExcludeNotificationsFrom) > 0) {
			foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) return;
			}
		}

		// Discard notifications from non-included pages
		global $wgTeamsIncludeNotificationsFrom;
		if (count($wgTeamsIncludeNotificationsFrom) > 0) {
			foreach ($wgTeamsIncludeNotificationsFrom as &$currentInclude) {
				if (0 !== strpos($article->getTitle(), $currentInclude)) return;
			}
		}

		// Do not announce newly added file uploads as articles...
		if ($article->getTitle()->getNsText() == "File") return true;
		

		
		$card = self::setupCard($article->getTitle(), $user);

		$action = " has created new page ";
		$card->action = $user.$action.$article->getTitle();
		$card->theme_colour = "green";
		$card->text = self::getText($article, $summary);

		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Occurs after the delete article request has been processed.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/ArticleDeleteComplete
	 */
	static function Teams_article_deleted(WikiPage $article, $user, $reason, $id)
	{
		global $wgTeamsNotificationRemovedArticle;
		if (!$wgTeamsNotificationRemovedArticle) return;

		// Discard notifications from excluded pages
		global $wgTeamsExcludeNotificationsFrom;
		if (count($wgTeamsExcludeNotificationsFrom) > 0) {
			foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($article->getTitle(), $currentExclude)) return;
			}
		}
		// Discard notifications from non-included pages
		global $wgTeamsIncludeNotificationsFrom;
		if (count($wgTeamsIncludeNotificationsFrom) > 0) {
			foreach ($wgTeamsIncludeNotificationsFrom as &$currentInclude) {
				if (0 !== strpos($article->getTitle(), $currentInclude)) return;
			}
		}

		
		$card = self::setupCard($article->getTitle(), $user);

		$action = " has deleted page ";
		$card->action = $user.$action.$article->getTitle();
		$card->theme_colour = "red";
		$card->text = "Reason: ".$reason;

		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Occurs after a page has been moved.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/TitleMoveComplete
	 */
	static function Teams_article_moved($title, $newtitle, $user, $oldid, $newid, $reason = null)
	{
		global $wgTeamsNotificationMovedArticle;
		if (!$wgTeamsNotificationMovedArticle) return;

		// Discard notifications from excluded pages
		global $wgTeamsExcludeNotificationsFrom;
		if (count($wgTeamsExcludeNotificationsFrom) > 0) {
			foreach ($wgTeamsExcludeNotificationsFrom as &$currentExclude) {
				if (0 === strpos($title, $currentExclude)) return;
				if (0 === strpos($newtitle, $currentExclude)) return;
			}
		}
		// Discard notifications from non-included pages
		global $wgTeamsIncludeNotificationsFrom;
		if (count($wgTeamsIncludeNotificationsFrom) > 0) {
			foreach ($wgTeamsIncludeNotificationsFrom as &$currentInclude) {
				if (0 !== strpos($title, $currentInclude)) return;
				if (0 !== strpos($newtitle, $currentInclude)) return;
			}
		}

		
		$card = self::setupCard($newTitle, $user);

		$card->action = $user." has moved '".$title."' to '".$newTitle;
		$card->theme_colour = "yellow";
		$card->text = $reason ? $reason : '';

		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Occurs after the protect article request has been processed.
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/ArticleProtectComplete
	 */
	static function Teams_article_protected($article, $user, $protect, $reason, $moveonly = false)
	{
		global $wgTeamsNotificationProtectedArticle;
		if (!$wgTeamsNotificationProtectedArticle) return;

		$card = self::setupCard($article->getTitle(), $user);

		$card->action = sprintf(
			"%s has %s article %s. Reason: %s",
			$user,
			$protect ? "changed protection of" : "removed protection of",
			$article->getTitle(),
			$reason);

		$card->theme_colour = "yellow";
		$card->text = self::getText($article);
		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Called after a user account is created.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/AddNewAccount
	 */
	static function Teams_new_user_account($user, $byEmail)
	{
		global $wgTeamsNotificationNewUser, $wgTeamsShowNewUserEmail, $wgTeamsShowNewUserFullName, $wgTeamsShowNewUserIP;
		if (!$wgTeamsNotificationNewUser) return;

		$email = "";
		$realname = "";
		$ipaddress = "";
		try { $email = $user->getEmail(); } catch (Exception $e) {}
		try { $realname = $user->getRealName(); } catch (Exception $e) {}
		try { $ipaddress = $user->getRequest()->getIP(); } catch (Exception $e) {}
		$messageExtra = "";
		if ($wgTeamsShowNewUserEmail || $wgTeamsShowNewUserFullName || $wgTeamsShowNewUserIP) {
			$messageExtra = "(";
			if ($wgTeamsShowNewUserEmail) $messageExtra .= $email . ", ";
			if ($wgTeamsShowNewUserFullName) $messageExtra .= $realname . ", ";
			if ($wgTeamsShowNewUserIP) $messageExtra .= $ipaddress . ", ";
			$messageExtra = substr($messageExtra, 0, -2); // Remove trailing , 
			$messageExtra .= ")";
		}

		$message = sprintf("New user account %s was just created", $user);

		$card = self::setupCard(false, $user);

		$card->action = sprintf("New user account %s was just created", $user);

		$card->title = "New User";

		$card->theme_colour = "green";
		$card->text = $messageExtra;
		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Called when a file upload has completed.
	 * @see http://www.mediawiki.org/wiki/Manual:Hooks/UploadComplete
	 */
	static function Teams_file_uploaded($image)
	{
		global $wgTeamsNotificationFileUpload;
		if (!$wgTeamsNotificationFileUpload) return;

		global $teamsWikiUrl, $teamsWikiUrlEnding, $wgUser;

		$card = self::setupCard($image->getLocalFile()->getTitle(), $wgUser->mName);

		$card->action = sprintf("%s has uploaded %s file %s", $wgUser->mName, $image->getLocalFile()->getMimeType(), $image->getLocalFile()->getTitle()); 

		$url = $teamsWikiUrl . $teamsWikiUrlEnding . $image->getLocalFile()->getTitle();
		$card->addLink("View File", $url);

		$card->theme_colour = "green";
		
		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Occurs after the request to block an IP or user has been processed
	 * @see http://www.mediawiki.org/wiki/Manual:MediaWiki_hooks/BlockIpComplete
	 */
	static function Teams_user_blocked(Block $block, $user)
	{
		global $wgTeamsNotificationBlockedUser;
		if (!$wgTeamsNotificationBlockedUser) return;

		global $teamsWikiUrl, $teamsWikiUrlEnding, $teamsWikiUrlEndingBlockList;
		$message = sprintf(
			"%s has blocked %s",
			$user, $block->getTarget());
		
		$text = sprintf("%s Block expiration: %s",
			$block->mReason == "" ? "" : "with reason '".$block->mReason."'.",
			$block->mExpiry);

		$card = self::setupCard(false, $user);
		$card->action = $message;
		$card->text = $text;
		$card->title = "New Block";
		$card->addLink("View Blocks", $teamsWikiUrl.$teamsWikiUrlEnding.$teamsWikiUrlEndingBlockList);
		$card->theme_colour = "red";

		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Occurs after the user groups (rights) have been changed
	 * @see https://www.mediawiki.org/wiki/Manual:Hooks/UserGroupsChanged
	 */
	static function Teams_user_groups_changed(User $user, array $added, array $removed, $performer, $reason, $oldUGMs, $newUGMs)
	{
		global $wgTeamsNotificationUserGroupsChanged;
		if (!$wgTeamsNotificationUserGroupsChanged) return;

		global $teamsWikiUrl, $teamsWikiUrlEnding, $teamsWikiUrlEndingUserRights;

		$message = sprintf(
            "%s has changed user groups for %s. New groups: %s",
			$performer,
			$user);
		$text = sprintf("New groups: %s", implode(", ", $user->getGroups()));
		
		$card = self::setupCard(false, $user);
		$card->action = $message;
		$card->text = $text;
		$card->title = "User Groups Changed";
		$card->theme_colour = "green";


		self::push_Teams_notify($card);
		return true;
	}

	/**
	 * Sends the message into Teams room.
	 * @param message Message to be sent.
	 * @param color Background color for the message. One of "green", "yellow" or "red". (default: yellow)
	 * @see https://api.Teams.com/incoming-webhooks
	 */
	static function push_Teams_notify($card)
	{
		
		 wfDebugLog( 'Teams',"Pushing Notify" );
		
		 try {
			global $wgTeamsIncomingWebhookUrl;
			$connector = new TeamsConnector($wgTeamsIncomingWebhookUrl);
		 } catch (Exception $e){
			wfDebugLog( 'Teams',"Notify Failed at init: ".$e->getMessage() );
		 }

		 wfDebugLog( 'Teams',"Connection Created" );
		//  wfDebugLog( 'Teams', var_export($connector,true) );
		 
		 try {
			wfDebugLog( 'Teams',"Connection Sending" );
			$connector->send($card);
		 } catch (Exception $e){
			wfDebugLog( 'Teams',"Notify Failed at send: ".$e->getMessage() );
			// wfDebugLog( 'Teams', print_r($e,true) );
		 }
	}
}
?>
