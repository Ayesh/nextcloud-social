<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Social\Tools;

interface IDeserializable {
	/**
	 * @param array $data
	 *
	 * @return self
	 */
	public function import(array $data): self;
}
