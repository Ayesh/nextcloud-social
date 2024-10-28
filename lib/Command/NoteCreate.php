<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2018 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Command;

use Exception;
use OC\Core\Command\Base;
use OCA\Social\Model\Post;
use OCA\Social\Service\AccountService;
use OCA\Social\Service\ActivityService;
use OCA\Social\Service\ConfigService;
use OCA\Social\Service\CurlService;
use OCA\Social\Service\MiscService;
use OCA\Social\Service\PostService;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Class NoteCreate
 *
 * @package OCA\Social\Command
 */
class NoteCreate extends Base {
	private ConfigService $configService;

	private ActivityService $activityService;

	private AccountService $accountService;

	private PostService $postService;

	private CurlService $curlService;

	private MiscService $miscService;


	/**
	 * NoteCreate constructor.
	 *
	 * @param ActivityService $activityService
	 * @param AccountService $accountService
	 * @param PostService $postService
	 * @param CurlService $curlService
	 * @param ConfigService $configService
	 * @param MiscService $miscService
	 */
	public function __construct(
		ActivityService $activityService, AccountService $accountService, PostService $postService,
		CurlService $curlService, ConfigService $configService, MiscService $miscService,
	) {
		parent::__construct();

		$this->activityService = $activityService;
		$this->accountService = $accountService;
		$this->postService = $postService;
		$this->curlService = $curlService;
		$this->configService = $configService;
		$this->miscService = $miscService;
	}


	/**
	 *
	 */
	protected function configure() {
		parent::configure();
		$this->setName('social:note:create')
			->addOption(
				'replyTo', 'r', InputOption::VALUE_OPTIONAL, 'in reply to an existing thread'
			)
			->addOption(
				'to', 't', InputOption::VALUE_OPTIONAL, 'mentioning people'
			)
			->addOption(
				'type', 'y', InputOption::VALUE_OPTIONAL,
				'type: public (default), followers, unlisted, direct'
			)
			->addOption(
				'hashtag', 'g', InputOption::VALUE_OPTIONAL,
				'hashtag, without the leading #'
			)
			->addArgument('user_id', InputArgument::REQUIRED, 'userId of the author')
			->addArgument('content', InputArgument::REQUIRED, 'content of the post')
			->setDescription('Create a new note');
	}


	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 *
	 * @throws Exception
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument('userid');
		$content = $input->getArgument('content');
		$to = $input->getOption('to');
		$hashtag = $input->getOption('hashtag');
		$replyTo = $input->getOption('replyTo');
		$type = $input->getOption('type');

		$actor = $this->accountService->getActorFromUserId($userId);
		$post = new Post($actor);
		$post->setContent($content);
		$post->setType(($type === null) ? '' : $type);
		$post->setReplyTo(($replyTo === null) ? '' : $replyTo);
		$post->addTo(($to === null) ? '' : $to);
		$post->setHashtags(($hashtag === null) ? [] : [$hashtag]);

		$token = '';
		$activity = $this->postService->createPost($post, $token);

		echo 'object: ' . json_encode($activity, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
		echo 'token: ' . $token . "\n";

		return 0;
	}
}
