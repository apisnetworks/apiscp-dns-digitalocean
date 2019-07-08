<?php declare(strict_types=1);
	/**
	 * Copyright (C) Apis Networks, Inc - All Rights Reserved.
	 *
	 * Unauthorized copying of this file, via any medium, is
	 * strictly prohibited without consent. Any dissemination of
	 * material herein is prohibited.
	 *
	 * For licensing inquiries email <licensing@apisnetworks.com>
	 *
	 * Written by Matt Saladna <matt@apisnetworks.com>, March 2019
	 */

	namespace Opcenter\Dns\Providers\Digitalocean;

	class Record extends \Opcenter\Dns\Record
	{
		protected $ttl = Module::DNS_TTL;

		protected function formatCaa() {
			return $this->appendDot();
		}

		protected function formatCname() {
			return $this->appendDot();
		}

		protected function formatNs() {
			return $this->appendDot();
		}

		protected function formatMx() {
			return $this->appendDot();
		}

		protected function formatSrv() {
			return $this->appendDot();
		}

		private function appendDot() {
			if (null !== ($data = $this->getMeta('data'))) {
				$this->setMeta('data', rtrim($data, '.') . '.');
			}
			$this->parameter = rtrim($this->parameter, '.') . '.';
		}

	}