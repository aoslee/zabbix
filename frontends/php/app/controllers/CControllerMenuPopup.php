<?php
/*
** Zabbix
** Copyright (C) 2001-2019 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


class CControllerMenuPopup extends CController {

	protected function checkInput() {
		$fields = [
			'type' => 'required|in history,host,item,item_prototype,trigger,triggerMacro',
			'data' => 'array'
		];

		$ret = $this->validateInput($fields);

		if (!$ret) {
			$output = [];
			if (($messages = getMessages()) !== null) {
				$output['errors'] = $messages->toString();
			}

			$this->setResponse(new CControllerResponseData(['main_block' => CJs::encodeJson($output)]));
		}

		return $ret;
	}

	protected function checkPermissions() {
		return true;
	}

	protected function doAction() {
		$data = $this->hasInput('data') ? $this->getInput('data') : [];

		$output = [];

		switch ($this->getInput('type')) {
			case 'history':
				$items = API::Item()->get([
					'output' => ['value_type'],
					'itemids' => $data['itemid'],
					'webitems' => true
				]);

				if ($items) {
					$value_type = $items[0]['value_type'];

					$output['data'] = [
						'type' => 'history',
						'itemid' => $data['itemid'],
						'hasLatestGraphs' => in_array($value_type, [ITEM_VALUE_TYPE_UINT64, ITEM_VALUE_TYPE_FLOAT])
					];
				}
				else {
					error(_('No permissions to referred object or it does not exist!'));
				}
				break;

			case 'host':
				$has_goto = !array_key_exists('has_goto', $data) || $data['has_goto'];

				$hosts = $has_goto
					? API::Host()->get([
						'output' => ['hostid', 'status'],
						'selectGraphs' => API_OUTPUT_COUNT,
						'selectScreens' => API_OUTPUT_COUNT,
						'hostids' => $data['hostid']
					])
					: API::Host()->get([
						'output' => ['hostid'],
						'hostids' => $data['hostid']
					]);

				if ($hosts) {
					$scripts = API::Script()->getScriptsByHosts([$data['hostid']])[$data['hostid']];

					$output['data'] = CMenuPopupHelper::getHost($hosts[0], $scripts, (bool) $has_goto);
				}
				else {
					error(_('No permissions to referred object or it does not exist!'));
				}
				break;

			case 'item':
				$items = API::Item()->get([
					'output' => ['hostid', 'name', 'value_type'],
					'itemids' => $data['itemid'],
					'webitems' => true
				]);

				if ($items) {
					$item = $items[0];
					$triggers = [];

					if (in_array($item['value_type'],
							[ITEM_VALUE_TYPE_LOG, ITEM_VALUE_TYPE_STR, ITEM_VALUE_TYPE_TEXT])) {
						$db_triggers = API::Trigger()->get([
							'output' => ['triggerid', 'description', 'recovery_mode'],
							'selectFunctions' => API_OUTPUT_EXTEND,
							'itemids' => $data['itemid']
						]);

						foreach ($db_triggers as $db_trigger) {
							if ($db_trigger['recovery_mode'] == ZBX_RECOVERY_MODE_RECOVERY_EXPRESSION) {
								continue;
							}

							foreach ($db_trigger['functions'] as $function) {
								if (!str_in_array($function['function'], ['regexp', 'iregexp'])) {
									continue 2;
								}
							}

							$triggers[] = [
								'triggerid' => $db_trigger['triggerid'],
								'name' => $db_trigger['description']
							];
						}
					}

					$output['data'] = [
						'type' => 'item',
						'itemid' => $data['itemid'],
						'hostid' => $item['hostid'],
						'name' => $item['name'],
						'triggers' => $triggers
					];
				}
				else {
					error(_('No permissions to referred object or it does not exist!'));
				}
				break;

			case 'item_prototype':
				$item_prototypes = API::ItemPrototype()->get([
					'output' => ['name'],
					'selectDiscoveryRule' => ['itemid'],
					'itemids' => $data['itemid']
				]);

				if ($item_prototypes) {
					$item_prototype = $item_prototypes[0];

					$output['data'] = [
						'type' => 'item_prototype',
						'itemid' => $data['itemid'],
						'name' => $item_prototype['name'],
						'parent_discoveryid' => $item_prototype['discoveryRule']['itemid']
					];
				}
				else {
					error(_('No permissions to referred object or it does not exist!'));
				}
				break;

			case 'trigger':
				$triggers = API::Trigger()->get([
					'output' => ['triggerid', 'expression', 'url', 'flags', 'comments'],
					'selectHosts' => ['hostid', 'name', 'status'],
					'selectItems' => ['itemid', 'hostid', 'name', 'key_', 'value_type'],
					'triggerids' => $data['triggerid']
				]);

				if ($triggers) {
					$triggers = CMacrosResolverHelper::resolveTriggerUrls($triggers);

					$trigger = $triggers[0];

					$options = [
						'show_description' => !array_key_exists('show_description', $data) || $data['show_description']
					];

					if ($options['show_description']) {
						$rw_triggers = API::Trigger()->get([
							'output' => [],
							'triggerids' => $trigger['triggerid'],
							'editable' => true
						]);

						$editable = (bool) $rw_triggers;
						$options['description_enabled'] = ($trigger['comments'] !== ''
							|| ($editable && $trigger['flags'] == ZBX_FLAG_DISCOVERY_NORMAL));
					}

					$acknowledge = array_key_exists('acknowledge', $data) ? $data['acknowledge'] : [];

					$output['data'] = CMenuPopupHelper::getTrigger($trigger, $acknowledge, $options);
				}
				else {
					error(_('No permissions to referred object or it does not exist!'));
				}
				break;

			case 'triggerMacro':
				$output['data'] = ['type' => 'triggerMacro'];
				break;
		}

		if (($messages = getMessages()) !== null) {
			$output['errors'] = $messages->toString();
		}

		$this->setResponse(new CControllerResponseData(['main_block' => CJs::encodeJson($output)]));
	}
}
