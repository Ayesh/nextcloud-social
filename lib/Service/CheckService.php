<?php
/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Service;

use Exception;
use GuzzleHttp\Exception\ClientException;
use OC\User\NoUserException;
use OCA\Social\Db\CacheActorsRequest;
use OCA\Social\Db\FollowsRequest;
use OCA\Social\Db\StreamDestRequest;
use OCA\Social\Db\StreamRequest;
use OCA\Social\Exceptions\AccountAlreadyExistsException;
use OCA\Social\Exceptions\ActorDoesNotExistException;
use OCA\Social\Exceptions\CacheActorDoesNotExistException;
use OCA\Social\Exceptions\ItemAlreadyExistsException;
use OCA\Social\Exceptions\SocialAppConfigException;
use OCA\Social\Exceptions\UrlCloudException;
use OCA\Social\Model\ActivityPub\Object\Follow;
use OCA\Social\Model\ActivityPub\Object\Note;
use OCA\Social\Tools\Traits\TArrayTools;
use OCA\Social\Tools\Traits\TStringTools;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\ICache;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;

/**
 * Class CheckService
 *
 * @package OCA\Social\Service
 */
class CheckService {
	use TArrayTools;
	use TStringTools;


	public const CACHE_PREFIX = 'social_check_';

	private IUserManager $userManager;
	private ICache $cache;
	private IConfig $config;
	private IClientService $clientService;
	private IRequest $request;
	private IURLGenerator $urlGenerator;
	private FollowsRequest $followRequest;
	private CacheActorsRequest $cacheActorsRequest;
	private StreamDestRequest $streamDestRequest;
	private StreamRequest $streamRequest;
	private AccountService $accountService;
	private ConfigService $configService;
	private MiscService $miscService;
	private ?string $userId = null;

	public function __construct(
		IUserManager $userManager, ?string $userId, ICache $cache, IConfig $config, IClientService $clientService,
		IRequest $request, IURLGenerator $urlGenerator, FollowsRequest $followRequest,
		CacheActorsRequest $cacheActorsRequest, StreamDestRequest $streamDestRequest,
		StreamRequest $streamRequest, AccountService $accountService, ConfigService $configService,
		MiscService $miscService,
	) {
		$this->userManager = $userManager;
		$this->cache = $cache;
		$this->config = $config;
		$this->clientService = $clientService;
		$this->request = $request;
		$this->urlGenerator = $urlGenerator;
		$this->followRequest = $followRequest;
		$this->cacheActorsRequest = $cacheActorsRequest;
		$this->streamDestRequest = $streamDestRequest;
		$this->streamRequest = $streamRequest;
		$this->accountService = $accountService;
		$this->configService = $configService;
		$this->miscService = $miscService;
		$this->userId = $userId;
	}


	/**
	 * @return array
	 */
	public function checkDefault(): array {
		$checks = [];
		$checks['wellknown'] = $this->checkWellKnown();

		$success = true;
		foreach ($checks as $check) {
			if (!$check) {
				$success = false;
			}
		}

		return [
			'success' => $success,
			'checks' => $checks
		];
	}


	/**
	 * @return bool
	 */
	public function checkWellKnown(): bool {
		$state = (bool)($this->cache->get(self::CACHE_PREFIX . 'wellknown') === 'true');
		if ($state === true) {
			return true;
		}

		$address = $this->config->getAppValue('social', 'address', '');

		if ($address !== '' && $this->requestWellKnown($address)) {
			return true;
		}

		if ($this->requestWellKnown(
			$this->request->getServerProtocol() . '://' . $this->request->getServerHost()
		)) {
			return true;
		}

		if ($this->requestWellKnown($this->urlGenerator->getBaseUrl())) {
			return true;
		}

		return false;
	}


	/**
	 * @param bool $light
	 *
	 * @return array
	 */
	public function checkInstallationStatus(bool $light = false): array {
		$result = [];
		if (!$light) {
			$result = [
				'invalidFollows' => $this->removeInvalidFollows(),
				'invalidNotes' => $this->removeInvalidNotes()
			];
		}

		//		$this->checkStatusTableFollows();
		//		$this->checkStatusTableStreamDest();
		try {
			$this->checkLocalAccountFollowingItself();
		} catch (Exception $e) {
		}

		return $result;
	}


	/**
	 * create a fake follow entry. Mandatory to have Home Stream working.
	 */
	public function checkStatusTableFollows() {
		if ($this->followRequest->countFollows() > 0) {
			return;
		}

		$follow = new Follow();
		$follow->setId($this->uuid());
		$follow->setType('Unknown');
		$follow->setActorId($this->uuid());
		$follow->setObjectId($this->uuid());
		$follow->setFollowId($this->uuid());

		$this->followRequest->save($follow);
	}


	/**
	 * create entries in follows so that user follows itself.
	 *
	 * @throws AccountAlreadyExistsException
	 * @throws NoUserException
	 * @throws SocialAppConfigException
	 * @throws UrlCloudException
	 * @throws ItemAlreadyExistsException
	 */
	public function checkLocalAccountFollowingItself() {
		$users = $this->userManager->search('');

		foreach ($users as $user) {
			try {
				$actor = $this->accountService->getActorFromUserId($user->getUID());
			} catch (ActorDoesNotExistException $e) {
				continue;
			}

			$this->followRequest->generateLoopbackAccount($actor);
		}
	}


	/**
	 * @return int
	 */
	public function removeInvalidFollows(): int {
		$count = 0;
		$follows = $this->followRequest->getAll();
		foreach ($follows as $follow) {
			try {
				$this->cacheActorsRequest->getFromId($follow->getActorId());
				$this->cacheActorsRequest->getFromId($follow->getObjectId());
			} catch (CacheActorDoesNotExistException $e) {
				$this->followRequest->deleteById($follow->getId());
				$count++;
			}
		}

		$this->miscService->log('removeInvalidFollows removed ' . $count . ' entries', 1);

		return $count;
	}


	/**
	 * @return int
	 */
	public function removeInvalidNotes(): int {
		$count = 0;
		$streams = $this->streamRequest->getAll(Note::TYPE);
		foreach ($streams as $stream) {
			try {
				// Check if it's enough for Note, Announce, ...
				$this->cacheActorsRequest->getFromId($stream->getAttributedTo());
			} catch (CacheActorDoesNotExistException $e) {
				$this->streamRequest->deleteById($stream->getId(), Note::TYPE);
				$count++;
			}
		}

		$this->miscService->log('removeInvalidNotes removed ' . $count . ' entries', 1);

		return $count;
	}

	private function requestWellKnown(string $base): bool {
		try {
			$url = $base . '/.well-known/webfinger?resource=acct:' . $this->userId . '@' . parse_url($base, PHP_URL_HOST);
			$options['nextcloud']['allow_local_address'] = true;
			$options['verify'] = $this->config->getSystemValue('social.checkssl', true);

			$response = $this->clientService->newClient()
				->get($url, $options);
			if ($response->getStatusCode() === Http::STATUS_OK) {
				$this->cache->set(self::CACHE_PREFIX . 'wellknown', 'true', 3600);

				return true;
			}
		} catch (ClientException $e) {
		} catch (Exception $e) {
		}

		return false;
	}
}
