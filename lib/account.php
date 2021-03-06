<?php
/**
 * Copyright (c) 2012 Bart Visscher <bartv@thisnet.nl>
 * Copyright (c) 2014 Thomas Müller <deepdiver@owncloud.com>
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 */

namespace OCA\Mail;

use Horde_Imap_Client_Mailbox;
use Horde_Imap_Client_Socket;
use Horde_Imap_Client;
use OCA\Mail\Db\MailAccount;

class Account {

	/**
	 * @var MailAccount
	 */
	private $account;

	/**
	 *  @var Mailbox[]
	 */
	private $mailboxes;

	/**
	 * @var Horde_Imap_Client_Socket
	 */
	private $client;

	/**
	 * @param MailAccount $info
	 */
	function __construct(MailAccount $account) {
		$this->account = $account;
		$this->mailboxes = null;
	}

	/**
	 * @return int
	 */
	public function getId() {
		return $this->account->getId();
	}

	/**
	 * @return string
	 */
	public function getName() {
		return $this->account->getName();
	}

	/**
	 * @return string
	 */
	public function getEMailAddress() {
		return $this->account->getEmail();
	}

	/**
	 * @return Horde_Imap_Client_Socket
	 */
	public function getImapConnection() {
		if (is_null($this->client)) {
			$host = $this->account->getInboundHost();
			$user = $this->account->getInboundUser();
			$password = $this->account->getInboundPassword();
			$port = $this->account->getInboundPort();
			$ssl_mode = $this->account->getInboundSslMode();

			$this->client = new \Horde_Imap_Client_Socket(
				array(
					'username' => $user,
					'password' => $password,
					'hostspec' => $host,
					'port' => $port,
					'secure' => $ssl_mode,
					'timeout' => 20,
				));
			$this->client->login();
		}
		return $this->client;
	}

	/**
	 * Lists mailboxes (folders) for this account.
	 *
	 * Lists mailboxes and also queries the server for their 'special use',
	 * eg. inbox, sent, trash, etc
	 *
	 * @param string $pattern Pattern to match mailboxes against. All by default.
	 * @return Mailbox[]
	 */
	protected function listMailboxes($pattern='*') {
		// open the imap connection
		$conn = $this->getImapConnection();

		// if successful -> get all folders of that account
		$mboxes = $conn->listMailboxes($pattern, Horde_Imap_Client::MBOX_ALL, array(
			'attributes' => true,
			'special_use' => true,
			'sort' => true
		));

		$mailboxes = array();
		foreach ($mboxes as $mailbox) {
			$mailboxes[] = new Mailbox($conn, $mailbox['mailbox'], $mailbox['attributes'], $mailbox['delimiter']);
		}
		return $mailboxes;
	}

	/**
	 * @param string $folderId
	 * @return \OCA\Mail\Mailbox
	 */
	public function getMailbox($folderId) {
		$conn = $this->getImapConnection();
		$mailbox = new Horde_Imap_Client_Mailbox($folderId, true);
		return new Mailbox($conn, $mailbox, array());
	}

	/**
	 * Get a list of all mailboxes in this account
	 *
	 * @return Mailbox[]
	 */
	protected function getMailboxes() {
		if ($this->mailboxes === null) {
			$this->mailboxes = $this->listMailboxes();
			$this->sortMailboxes();
			$this->localizeSpecialMailboxes();
		}

		return $this->mailboxes;
	}

	/**
	 * @return array
	 */
	public function getListArray() {

		$folders = array();
		foreach ($this->getMailboxes() as $mailbox) {
			$folders[] = $mailbox->getListArray($this->getId());
		}
		return array(
			'id'             => $this->getId(),
			'email'          => $this->getEMailAddress(),
			'folders'        => array_values($folders),
			'specialFolders' => $this->getSpecialFoldersIds()
		);
	}


	/**
	 * @return \Horde_Mail_Transport_Smtphorde
	 */
	public function createTransport() {
		$host = $this->account->getOutboundHost();
		$params = array(
			'host' => $host,
			'password' => $this->account->getOutboundPassword(),
			'port' => $this->account->getOutboundPort(),
			'username' => $this->account->getOutboundUser(),
			'secure' => $this->account->getOutboundSslMode(),
			'timeout' => 2
		);
		return new \Horde_Mail_Transport_Smtphorde($params);
	}
	
	/**
	 * Lists special use folders for this account.
	 *
	 * The special uses returned are the "best" one for each special role,
	 * picked amongst the ones returned by the server, as well
	 * as the one guessed by our code.
	 * 
	 * @return array In the form array(<special use>=><folder id>, ...)
	 */
	public function getSpecialFoldersIds($base64_encode=true) {
		$folderRoles = array('inbox', 'sent', 'drafts', 'trash', 'archive', 'junk', 'flagged', 'all');
		$specialFoldersIds = array();
		
		foreach ($folderRoles as $role) {
			$folder = $this->getSpecialFolder($role, true);
			$specialFoldersIds[$role] = empty($folder) ? null : $folder->getFolderId();
			if ($specialFoldersIds[$role] !== null && $base64_encode === true) {
				$specialFoldersIds[$role] = base64_encode($specialFoldersIds[$role]);
			}
		}
		return $specialFoldersIds;
	}

	/**
	 * Get the "sent mail" mailbox
	 *
	 * @return Mailbox The best candidate for the "sent mail" inbox
	 */
	public function getSentFolder() {
		return $this->getSpecialFolder('sent', true);
	}
	
	/**
	 * @param string $sourceFolderId
	 * @param int $messageId
	 */
	public function deleteMessage($sourceFolderId, $messageId) {
		
		// by default we will create a 'Trash' folder if no trash is found
		$trashId = "Trash";
		$createTrash = true;

		$trashFolder = $this->getSpecialFolder('trash', true);

		if (empty($trashFolder) === false) {
			$trashId = $trashFolder->getFolderId();
			$createTrash = false;
		} else {
			// no trash -> guess
			$trashes = array_filter($this->getMailboxes(), function($box) {
				/**
				 * @var Mailbox $box
				 */
				return (stripos($box->getDisplayName(), 'trash') !== FALSE);
			});
			if (!empty($trashes)) {
				$trashId = array_values($trashes);
				$trashId = $trashId[0]->getFolderId();
				$createTrash = false;
			}
		}

		$hordeMessageIds = new \Horde_Imap_Client_Ids($messageId);
		$hordeSourceMailBox = new Horde_Imap_Client_Mailbox($sourceFolderId, true);
		$hordeTrashMailBox = new Horde_Imap_Client_Mailbox($trashId, true);

		$result = $this->getImapConnection()->copy($hordeSourceMailBox, $hordeTrashMailBox,
			array('create' => $createTrash, 'move' => true, 'ids' => $hordeMessageIds));

		\OC::$server->getLogger()->info("Message moved to trash: {result}", array('result' => $result));
	}
	
	/*
	 * Get mailbox(es) that have the given special use role
	 *
	 * With this method we can get a list of all mailboxes tht have been
	 * determined to have a specific special use role. It can also return
	 * the best candidate for this role, for situations where we want
	 * one single folder. Right now the best candidate is the one with
	 * the most messages in it.
	 *
	 * @param string $role Special role of the folder we want to get ('sent', 'inbox', etc.)
	 * @param bool $guessBest If set to true, return only the folder with the most messages in it
	 *
	 * @return Mailbox[] if $guessBest is false, or Mailbox if $guessBest is true. Empty array() if no match.
	 */ 
	protected function getSpecialFolder($role, $guessBest=true) {
		
		$specialFolders = array();
		foreach ($this->getMailboxes() as $mailbox) {
			if ($role === $mailbox->getSpecialRole()) {
				$specialFolders[] = $mailbox;
			}
		}

		if ($guessBest === true && count($specialFolders) > 0) {
			$maxMessages = 0;
			$maxFolder = reset($specialFolders);
			foreach ($specialFolders as $folder) {
				/** @var Mailbox $folder */
				if ($folder->getTotalMessages() > $maxMessages) {
					$maxMessages = $folder->getTotalMessages();
					$maxFolder = $folder;
				}
			}
			return $maxFolder;
		} else {
			return $specialFolders;
		}
	}

	/**
	 *  Localizes the name of the special use folders
	 *
	 *  The display name of the best candidate folder for each special use
	 *  is localized to the user's language
	 */
	protected function localizeSpecialMailboxes() {

		$l = new \OC_L10N('mail');
		$map = array(
			// TRANSLATORS: translated mail box name
			'inbox'   => $l->t('Inbox'),
			// TRANSLATORS: translated mail box name
			'sent'    => $l->t('Sent'),
			// TRANSLATORS: translated mail box name
			'drafts'  => $l->t('Drafts'),
			// TRANSLATORS: translated mail box name
			'archive' => $l->t('Archive'),
			// TRANSLATORS: translated mail box name
			'trash'   => $l->t('Trash'),
			// TRANSLATORS: translated mail box name
			'junk'    => $l->t('Junk'),
			// TRANSLATORS: translated mail box name
			'all'     => $l->t('All'),
			// TRANSLATORS: translated mail box name
			'flagged' => $l->t('Starred'),
		);
		$mailboxes = $this->getMailboxes();
		$specialIds = $this->getSpecialFoldersIds(false);
		foreach ($mailboxes as $i => $mailbox) {
			if (in_array($mailbox->getFolderId(), $specialIds) === true) {
				if (isset($map[$mailbox->getSpecialRole()])) {
					$translatedDisplayName = $map[$mailbox->getSpecialRole()];
					$mailboxes[$i]->setDisplayName((string)$translatedDisplayName);
				}
			}
		}
	}

	/**
	 * Sort mailboxes
	 *
	 * Sort the array of mailboxes with 
	 *  - special use folders coming first in this order: all, inbox, flagged, drafts, sent, archive, junk, trash 
	 *  - 'normal' folders coming after that, sorted alphabetically
	 */
	protected function sortMailboxes() {

		$mailboxes = $this->getMailboxes();
		usort($mailboxes, function($a, $b) {
			/**
			 * @var Mailbox $a
			 * @var Mailbox $b
			 */
			$roleA = $a->getSpecialRole();
			$roleB = $b->getSpecialRole();
			$specialRolesOrder = array(
				'all'     => 0,
				'inbox'   => 1,
				'flagged' => 2,
				'drafts'  => 3,
				'sent'    => 4,
				'archive' => 5,
				'junk'    => 6,
				'trash'   => 7,
			);
			// if there is a flag unknown to us, we ignore it for sorting :
			// the folder will be sorted by name like any other 'normal' folder
			if (array_key_exists($roleA, $specialRolesOrder) === false) {
				$roleA = null;
			}
			if (array_key_exists($roleB, $specialRolesOrder) === false) {
				$roleB = null;
			}

			if ($roleA === null && $roleB !== null) {
				return 1;
			} elseif ($roleA !== null && $roleB === null){
				return -1;
			} elseif ($roleA !== null && $roleB !== null) {
				if ($roleA === $roleB) {
					return strcasecmp($a->getdisplayName(), $b->getDisplayName());
				} else {
					return $specialRolesOrder[$roleA] - $specialRolesOrder[$roleB];
				}
			} 
			// we get here if $roleA === null && $roleB === null
			return strcasecmp($a->getDisplayName(), $b->getDisplayName());
		});

		$this->mailboxes = $mailboxes;
	}
}

