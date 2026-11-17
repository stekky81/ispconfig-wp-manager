<?php
// SPDX-License-Identifier: BSD-3-Clause

declare(strict_types=1);

namespace WPStation;

/**
 * Any node can open a transfer request, and a node that was broken into could ask for any site of another
 * node. The source node sends a site only for a clone that the panel created: the panel writes every new
 * record into sys_datalog, where the nodes can only change the status. That record must name this source,
 * this target and the node of the target.
 */
final class TransferAuthorization
{
    /**
     * Why the transfer is refused, or '' when the panel asked for this clone.
     *
     * @param string|null $datalog the data column of the sys_datalog insert of the target record
     */
    public static function refusal(?string $datalog, int $sourceSiteId, int $targetSiteId, int $targetServerId): string
    {
        if ($datalog === null || $datalog === '') {
            return 'the panel did not ask for this clone';
        }
        // Written by the panel; still, no object of any class is created from it.
        $data = @unserialize($datalog, ['allowed_classes' => false]);
        $new = is_array($data) && is_array($data['new'] ?? null) ? $data['new'] : null;
        if ($new === null) {
            return 'the request of the panel cannot be read';
        }
        if ((int)($new['wpstation_site_id'] ?? 0) !== $targetSiteId
            || (int)($new['clone_of'] ?? 0) !== $sourceSiteId
            || (int)($new['server_id'] ?? 0) !== $targetServerId) {
            return 'the panel asked for another clone';
        }
        return '';
    }
}
