<?php
/************************************************************************
 * This file is part of EspoCRM.
 *
 * EspoCRM - Open Source CRM application.
 * Copyright (C) 2014-2022 Yurii Kuznietsov, Taras Machyshyn, Oleksii Avramenko
 * Website: https://www.espocrm.com
 *
 * EspoCRM is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * EspoCRM is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with EspoCRM. If not, see http://www.gnu.org/licenses/.
 *
 * The interactive user interfaces in modified source and object code versions
 * of this program must display Appropriate Legal Notices, as required under
 * Section 5 of the GNU General Public License version 3.
 *
 * In accordance with Section 7(b) of the GNU General Public License version 3,
 * these Appropriate Legal Notices must retain the display of the "EspoCRM" word.
 ************************************************************************/

namespace Espo\Core\Rebuild\Actions;

use Espo\Core\Rebuild\RebuildAction;

use Espo\Core\Utils\Metadata;
use Espo\Entities\ScheduledJob;
use Espo\ORM\EntityManager;

/**
 * Rebuilds scheduled jobs. Creates system jobs.
 */
class ScheduledJobs implements RebuildAction
{
    private Metadata $metadata;
    private EntityManager $entityManager;

    public function __construct(Metadata $metadata, EntityManager $entityManager)
    {
        $this->metadata = $metadata;
        $this->entityManager = $entityManager;
    }

    public function process(): void
    {
        $jobDefs = array_merge(
            $this->metadata->get(['entityDefs', 'ScheduledJob', 'jobs'], []), // for bc
            $this->metadata->get(['app', 'scheduledJobs'], [])
        );

        $systemJobNameList = [];

        foreach ($jobDefs as $jobName => $defs) {
            if (!$jobName) {
                continue;
            }

            if (empty($defs['isSystem']) || empty($defs['scheduling'])) {
                continue;
            }

            $systemJobNameList[] = $jobName;

            $sj = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where([
                    'job' => $jobName,
                    'status' => ScheduledJob::STATUS_ACTIVE,
                    'scheduling' => $defs['scheduling'],
                ])
                ->findOne();

            if ($sj) {
                continue;
            }

            $existingJob = $this->entityManager
                ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                ->where([
                    'job' => $jobName,
                ])
                ->findOne();

            if ($existingJob) {
                $this->entityManager->removeEntity($existingJob);
            }

            $name = $jobName;

            if (!empty($defs['name'])) {
                $name = $defs['name'];
            }

            $this->entityManager->createEntity(ScheduledJob::ENTITY_TYPE, [
                'job' => $jobName,
                'status' => ScheduledJob::STATUS_ACTIVE,
                'scheduling' => $defs['scheduling'],
                'isInternal' => true,
                'name' => $name,
            ]);
        }

        $internalScheduledJobList = $this->entityManager
            ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
            ->where([
                'isInternal' => true,
            ])
            ->find();

        foreach ($internalScheduledJobList as $scheduledJob) {
            $jobName = $scheduledJob->get('job');

            if (!in_array($jobName, $systemJobNameList)) {
                $this->entityManager
                    ->getRDBRepository(ScheduledJob::ENTITY_TYPE)
                    ->deleteFromDb($scheduledJob->getId());
            }
        }
    }
}
